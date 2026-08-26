@echo off
echo Starting FGC Report Server with auto-reload...
echo Open your browser at: http://127.0.0.1:8000
echo.
echo The server will automatically reload when you save any .py or .html file.
echo Press Ctrl+C to stop the server.
echo.
python -m uvicorn app.main:app --host 127.0.0.1 --port 8000 --reload --reload-include *.html
pause
