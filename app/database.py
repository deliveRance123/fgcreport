import os
import time
from sqlalchemy import create_engine, text
from sqlalchemy.orm import declarative_base, sessionmaker

# Retrieve database URL from environment variable, fallback to local Postgres URI for dev
DATABASE_URL = os.getenv("DATABASE_URL", "postgresql://postgres:2004@localhost:5432/foursquare_reports")

# Render uses `postgres://` which is deprecated in SQLAlchemy — fix it.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

# IS_PRODUCTION is ONLY true when running on Render (RENDER env var is set in render.yaml).
# Do NOT detect by DATABASE_URL prefix — that would break local dev.
IS_PRODUCTION = os.getenv("RENDER") == "true"


def _make_pg_engine(url: str):
    """Create a PostgreSQL engine with the right SSL args for the environment."""
    connect_args = {}
    if IS_PRODUCTION:
        # Render PostgreSQL requires SSL — without this the connection is refused
        connect_args["sslmode"] = "require"
    return create_engine(
        url,
        connect_args=connect_args,
        pool_pre_ping=True,       # Validate connections before use
        pool_size=5,
        max_overflow=10,
        pool_timeout=30,
        pool_recycle=1800,        # Recycle connections every 30 min (avoids stale TCP)
    )


def _make_sqlite_engine():
    """SQLite fallback for local dev when PostgreSQL isn't running."""
    return create_engine(
        "sqlite:///./foursquare_reports.db",
        connect_args={"check_same_thread": False},
        pool_pre_ping=True,
    )


def _connect_with_retry(url: str, retries: int = 8, wait: int = 10):
    """
    Try to create + test a PostgreSQL engine, retrying on failure.
    Returns the engine on success, or None if all attempts fail.
    """
    for attempt in range(1, retries + 1):
        try:
            print(f"[DB] Connecting to PostgreSQL (attempt {attempt}/{retries})...")
            eng = _make_pg_engine(url)
            with eng.connect() as conn:
                conn.execute(text("SELECT 1"))
            print("[DB] PostgreSQL connection successful.")
            return eng
        except Exception as e:
            print(f"[DB] Attempt {attempt} failed: {e}")
            if attempt < retries:
                print(f"[DB] Retrying in {wait}s...")
                time.sleep(wait)
    return None


# --- Engine selection ---
engine = None

if IS_PRODUCTION:
    # On Render: must use PostgreSQL. Retry patiently — DB may still be provisioning.
    engine = _connect_with_retry(DATABASE_URL, retries=8, wait=10)
    if engine is None:
        # Log the failure clearly but don't raise — uvicorn will still start.
        # Individual requests will fail with a DB error until the DB is ready,
        # at which point Render will restart the service automatically.
        print("[DB] WARNING: Could not connect to PostgreSQL. "
              "The server will start but DB operations will fail until the DB is ready.")
        # Use a deferred engine — requests that need DB will fail gracefully
        engine = _make_pg_engine(DATABASE_URL)
else:
    # Local dev: try PostgreSQL, fall back to SQLite
    try:
        engine = _make_pg_engine(DATABASE_URL)
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        print("[DB] Local PostgreSQL connection successful.")
    except Exception as e:
        print(f"[DB] Local Postgres unavailable ({e}). Falling back to SQLite.")
        engine = _make_sqlite_engine()

# Session factory for individual requests
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)

# Declarative Base for models
Base = declarative_base()


def get_db():
    """
    FastAPI dependency that yields a database session.
    Automatically closes the session at the end of the request.
    """
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
