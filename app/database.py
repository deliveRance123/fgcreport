import os
import time
from sqlalchemy import create_engine, text
from sqlalchemy.orm import declarative_base, sessionmaker

# Retrieve database URL from environment variable, fallback to local Postgres URI for dev
DATABASE_URL = os.getenv("DATABASE_URL", "postgresql://postgres:2004@localhost:5432/foursquare_reports")

# Render uses `postgres://` which is deprecated in SQLAlchemy — fix it.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

# IS_PRODUCTION is true when running on Render or cloud container
IS_PRODUCTION = os.getenv("RENDER") == "true" or "render.com" in DATABASE_URL

def _make_pg_engine(url: str):
    """Create a PostgreSQL engine with the right SSL args for the environment."""
    connect_args = {}
    if IS_PRODUCTION:
        # Render PostgreSQL connections: use require for external or prefer for flexible routing
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
    """SQLite fallback for local dev when PostgreSQL isn't running."""
    return create_engine(
        "sqlite:///./foursquare_reports.db",
        connect_args={"check_same_thread": False},
        pool_pre_ping=True,
    )

# --- Engine initialization ---
try:
    if DATABASE_URL.startswith("postgresql://") or DATABASE_URL.startswith("postgres://"):
        engine = _make_pg_engine(DATABASE_URL)
        print("[DB] PostgreSQL engine initialized.")
    else:
        engine = _make_sqlite_engine()
        print("[DB] SQLite engine initialized.")
except Exception as e:
    print(f"[DB] Primary engine initialization fallback ({e}). Using SQLite.")
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
