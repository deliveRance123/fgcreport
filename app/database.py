import os
import time
from sqlalchemy import create_engine, text
from sqlalchemy.orm import declarative_base, sessionmaker

# Retrieve database URL from environment variable, fallback to local Postgres URI for dev
DATABASE_URL = os.getenv("DATABASE_URL", "postgresql://postgres:2004@localhost:5432/foursquare_reports")

# Render uses `postgres://` which is deprecated in SQLAlchemy. Correct it to `postgresql://`.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

IS_PRODUCTION = os.getenv("RENDER") == "true" or os.getenv("DATABASE_URL", "").startswith("postgres")

def _make_pg_engine(url: str):
    """Create a PostgreSQL engine. Add SSL args if running on Render/cloud."""
    connect_args = {}
    if IS_PRODUCTION:
        # Render PostgreSQL requires SSL — without this, connection is rejected (red flag)
        connect_args["sslmode"] = "require"
    return create_engine(
        url,
        connect_args=connect_args,
        pool_pre_ping=True,        # Validates connections before use
        pool_size=5,               # Keep 5 persistent connections
        max_overflow=10,           # Allow 10 extra connections under load
        pool_timeout=30,           # Wait up to 30s for a free connection
        pool_recycle=1800,         # Recycle connections every 30 min to avoid stale TCP
    )

def _make_sqlite_engine():
    """Fallback SQLite engine for local dev without PostgreSQL."""
    return create_engine(
        "sqlite:///./foursquare_reports.db",
        connect_args={"check_same_thread": False},
        pool_pre_ping=True,
    )

# On Render (production), we MUST use PostgreSQL — no silent SQLite fallback.
# On local dev, fall back to SQLite if Postgres isn't running.
engine = None

if IS_PRODUCTION:
    # Retry up to 5 times (30s intervals) — Render DB sometimes takes a moment to be ready
    last_error = None
    for attempt in range(1, 6):
        try:
            print(f"[DB] Connecting to PostgreSQL (attempt {attempt}/5)...")
            engine = _make_pg_engine(DATABASE_URL)
            with engine.connect() as conn:
                conn.execute(text("SELECT 1"))
            print("[DB] PostgreSQL connection successful.")
            break
        except Exception as e:
            last_error = e
            print(f"[DB] Connection attempt {attempt} failed: {e}")
            if attempt < 5:
                time.sleep(5)
    if engine is None:
        raise RuntimeError(
            f"[DB] FATAL: Could not connect to PostgreSQL after 5 attempts.\n"
            f"Last error: {last_error}\n"
            f"DATABASE_URL hint: {DATABASE_URL[:40]}..."
        )
else:
    # Local development — try PostgreSQL first, then fall back to SQLite
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
