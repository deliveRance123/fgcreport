@echo off
set DATABASE_URL=postgresql://postgres:2004@localhost:5432/foursquare_reports
python -m uvicorn app.main:app --host 127.0.0.1 --port 8000
