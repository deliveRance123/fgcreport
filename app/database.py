import os
import time
from sqlalchemy import create_engine, text
from sqlalchemy.orm import declarative_base, sessionmaker

# Automatically load .env file if present in the project root
env_file_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env")
if os.path.exists(env_file_path):
    with open(env_file_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                os.environ.setdefault(k.strip(), v.strip().strip("'\""))

# Retrieve database URL from environment variable
DATABASE_URL = os.getenv("DATABASE_URL", "").strip()

# Render / cloud providers use `postgres://` which is deprecated in SQLAlchemy — fix it.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

# Detect if running in cloud container or local dev
is_cloud_container = os.getenv("RENDER") == "true" or not os.path.exists("c:\\xampp")

if not DATABASE_URL:
    if is_cloud_container:
        # In cloud without explicit DATABASE_URL, use SQLite
        DATABASE_URL = "sqlite:///./foursquare_reports.db"
    else:
        # Local Windows dev default
        DATABASE_URL = "postgresql://postgres:2004@localhost:5432/foursquare_reports"

IS_PRODUCTION = os.getenv("RENDER") == "true" or "render.com" in DATABASE_URL or "neon.tech" in DATABASE_URL or "supabase.co" in DATABASE_URL or is_cloud_container


def _make_pg_engine(url: str):
    """Create a PostgreSQL engine with safe SSL handling."""
    # Strip unsupported libpq parameters like channel_binding that cause psycopg2 errors on Linux
    if "channel_binding=" in url:
        import re
        url = re.sub(r'[?&]channel_binding=[^&]+', '', url)
        if "?" not in url and "&" in url:
            url = url.replace("&", "?", 1)

    connect_args = {}
    if IS_PRODUCTION and "localhost" not in url and "127.0.0.1" not in url:
        if "sslmode" not in url:
            connect_args["sslmode"] = "require"

    return create_engine(
        url,
        connect_args=connect_args,
        pool_pre_ping=True,       # Automatically tests & reconnects stale/cold connections
        pool_size=3,              # Safe for Render / Neon free tier
        max_overflow=5,           # Allow burst connections
        pool_recycle=300,         # Recycle connections every 5 min
        pool_timeout=30,          # Don't wait more than 30s
    )

def _make_sqlite_engine():
    """SQLite fallback for reliable local / container persistence."""
    return create_engine(
        "sqlite:///./foursquare_reports.db",
        connect_args={"check_same_thread": False},
        pool_pre_ping=True,
    )

# --- Engine initialization with connection verification (with retry for Neon/Cloud) ---
engine = None
if DATABASE_URL.startswith("postgresql://") or DATABASE_URL.startswith("postgres://"):
    max_retries = 5
    for attempt in range(1, max_retries + 1):
        try:
            engine = _make_pg_engine(DATABASE_URL)
            with engine.connect() as conn:
                conn.execute(text("SELECT 1"))
            print("[DB] PostgreSQL connection successfully established and verified.")
            break
        except Exception as e:
            if attempt < max_retries:
                print(f"[DB] PostgreSQL connection attempt {attempt}/{max_retries} failed ({e}). Retrying in 2 seconds...")
                time.sleep(2)
            else:
                print(f"[DB] PostgreSQL connection failed after {max_retries} attempts ({e}). Automatically switching to SQLite.")
                DATABASE_URL = "sqlite:///./foursquare_reports.db"
                engine = _make_sqlite_engine()
else:
    engine = _make_sqlite_engine()
    print("[DB] SQLite database initialized.")


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
