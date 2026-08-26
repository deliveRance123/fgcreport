import os
import time
from sqlalchemy import create_engine, text
from sqlalchemy.orm import declarative_base, sessionmaker

# Retrieve database URL from environment variable
DATABASE_URL = os.getenv("DATABASE_URL", "").strip()

# Render uses `postgres://` which is deprecated in SQLAlchemy — fix it.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

# Detect if running in cloud container (Render / Linux container) or local Windows XAMPP dev
is_cloud_container = os.getenv("RENDER") == "true" or not os.path.exists("c:\\xampp")

if not DATABASE_URL:
    if is_cloud_container:
        # In cloud without explicit DATABASE_URL, use SQLite (prevents crashing on container localhost)
        DATABASE_URL = "sqlite:///./foursquare_reports.db"
    else:
        # Local Windows dev default
        DATABASE_URL = "postgresql://postgres:2004@localhost:5432/foursquare_reports"

IS_PRODUCTION = os.getenv("RENDER") == "true" or "render.com" in DATABASE_URL or is_cloud_container

def _make_pg_engine(url: str):
    """Create a PostgreSQL engine with safe SSL handling."""
    connect_args = {}
    if IS_PRODUCTION and "localhost" not in url and "127.0.0.1" not in url:
        if "sslmode" not in url:
            connect_args["sslmode"] = "prefer"
    return create_engine(
        url,
        connect_args=connect_args,
        pool_pre_ping=True,       # Automatically tests & reconnects stale/cold connections
        pool_size=5,
        max_overflow=10,
        pool_recycle=300,        # Recycle connections every 5 min (avoids stale TCP)
    )

def _make_sqlite_engine():
    """SQLite fallback for reliable local / container persistence."""
    return create_engine(
        "sqlite:///./foursquare_reports.db",
        connect_args={"check_same_thread": False},
        pool_pre_ping=True,
    )

# --- Engine initialization with connection verification ---
engine = None
if DATABASE_URL.startswith("postgresql://") or DATABASE_URL.startswith("postgres://"):
    try:
        engine = _make_pg_engine(DATABASE_URL)
        # Test the connection immediately
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        print("[DB] PostgreSQL connection successfully established and verified.")
    except Exception as e:
        print(f"[DB] PostgreSQL connection failed ({e}). Automatically switching to SQLite.")
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
