import os
from sqlalchemy import create_engine
from sqlalchemy.orm import declarative_base, sessionmaker

# Retrieve database URL from environment variable, fallback to default local Postgres URI
DATABASE_URL = os.getenv("DATABASE_URL", "postgresql://postgres:2004@localhost:5432/foursquare_reports")

# Render uses `postgres://` which is deprecated in SQLAlchemy. Correct it to `postgresql://`.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

# Create database engine
engine = create_engine(
    DATABASE_URL,
    pool_pre_ping=True,  # Test connections before executing queries to prevent stale connections
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
