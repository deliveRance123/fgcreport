import os
from sqlalchemy import create_engine
from sqlalchemy.orm import declarative_base, sessionmaker

# Retrieve database URL from environment variable, fallback to default local Postgres URI
DATABASE_URL = os.getenv("DATABASE_URL", "postgresql://postgres:2004@localhost:5432/foursquare_reports")

# Render uses `postgres://` which is deprecated in SQLAlchemy. Correct it to `postgresql://`.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

try:
    engine = create_engine(
        DATABASE_URL,
        pool_pre_ping=True,  # Test connections before executing queries to prevent stale connections
    )
    # Test connection
    with engine.connect() as conn:
        pass
except Exception as e:
    print(f"Warning: Primary DB connection failed ({e}). Using SQLite fallback.")
    DATABASE_URL = "sqlite:///./foursquare_reports.db"
    engine = create_engine(
        DATABASE_URL,
        connect_args={"check_same_thread": False},
        pool_pre_ping=True,
    )

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
