import os, subprocess, sys

env = os.environ.copy()
env["DATABASE_URL"] = "postgresql://postgres:2004@localhost:5432/foursquare_reports"

proc = subprocess.Popen(
    [sys.executable, "-m", "uvicorn", "app.main:app", "--host", "127.0.0.1", "--port", "8000", "--reload"],
    env=env,
    cwd=r"c:\xampp\htdocs\fgc_report_web"
)
print(f"Server started with PID {proc.pid}")
proc.wait()
