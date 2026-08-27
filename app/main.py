import os
import re
from datetime import datetime
from fastapi import FastAPI, Request, Depends
from fastapi.responses import RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware
from sqlalchemy.orm import Session

from app.database import engine, Base, SessionLocal, get_db
from app.models import SiteSetting
from app.utils import formatNaira, formatPctDiff, monthName, getSiteSettings
from app.auth import is_logged_in

# Initialize FastAPI app
app = FastAPI(title="Foursquare Gospel Church Reporting System")

# Session Secret Key config (Render uses environment variable, fallback to default)
SECRET_KEY = os.getenv("SESSION_SECRET", "super-secret-fgc-key-999")
# On Render (HTTPS), cookies must be sent with Secure flag; locally use HTTP
_https_only = os.getenv("RENDER") == "true"
app.add_middleware(SessionMiddleware, secret_key=SECRET_KEY, same_site="lax", https_only=_https_only)

@app.middleware("http")
async def no_cache_html(request: Request, call_next):
    """
    Force browsers to NEVER cache HTML pages.
    This means every page visit fetches the latest version from the server,
    so changes always show immediately without a hard refresh.
    Static files (assets/, uploads/) are excluded and can still be cached.
    """
    response = await call_next(request)
    path = request.url.path
    # Apply no-cache only to HTML page responses (not to static assets)
    is_static = path.startswith("/assets") or path.startswith("/uploads")
    content_type = response.headers.get("content-type", "")
    if not is_static and "text/html" in content_type:
        response.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
        response.headers["Pragma"] = "no-cache"
        response.headers["Expires"] = "0"
    return response


@app.middleware("http")
async def redirect_php_requests(request: Request, call_next):
    path = request.url.path
    if path.endswith(".php"):
        new_path = path[:-4]
        if new_path == "/register_church":
            new_path = "/register-church"
        elif new_path == "/register_zone":
            new_path = "/register-zone"
        elif new_path == "/church_report":
            new_path = "/church-report"
        elif new_path == "/zonal_reports":
            new_path = "/zonal-reports"
        elif new_path == "/chat_api":
            new_path = "/chat-api"
        elif new_path == "/process_payment":
            new_path = "/process-payment"
        elif new_path == "/debug_chat":
            new_path = "/debug-chat"
        elif new_path == "/index":
            new_path = "/"
            
        query_string = request.url.query
        if query_string:
            new_path += f"?{query_string}"
            
        return RedirectResponse(url=new_path, status_code=307)
    return await call_next(request)

# Mount static folders
app.mount("/assets", StaticFiles(directory="assets"), name="assets")

# Ensure uploads directories exist
os.makedirs("uploads/profiles", exist_ok=True)
os.makedirs("uploads/videos", exist_ok=True)
app.mount("/uploads", StaticFiles(directory="uploads"), name="uploads")

# Set up templates
templates = Jinja2Templates(directory="templates")

# Register custom filters & context processors in Jinja2 env
import hashlib

def md5_helper(val):
    return hashlib.md5(str(val).encode('utf-8')).hexdigest()

templates.env.filters["format_naira"] = formatNaira
templates.env.filters["format_pct_diff"] = formatPctDiff
templates.env.filters["month_name"] = monthName
templates.env.filters["md5"] = md5_helper
templates.env.globals["md5"] = md5_helper

def get_ss(key: str, default: str = "") -> str:
    """Helper to fetch site settings from database (cached or direct)."""
    db = SessionLocal()
    try:
        setting = db.query(SiteSetting).filter_by(setting_key=key).first()
        return setting.setting_value if setting else default
    except Exception:
        return default
    finally:
        db.close()

def hero_title_formatter(key: str, default: str) -> str:
    """Formats [em]text[/em] into <em>text</em> for HTML titles."""
    raw = get_ss(key, default)
    # Escape tags but preserve our custom formatting tags [em] and [/em]
    import html
    escaped = html.escape(raw)
    formatted = re.sub(r'\[em\](.*?)\[/em\]', r'<em>\1</em>', escaped, flags=re.IGNORECASE)
    return formatted

# Session helpers — named functions (not lambdas) to avoid closure issues
def _is_logged_in(request): return "user_id" in request.session
def _current_role(request): return request.session.get("role")
def _current_user_name(request): return request.session.get("full_name")
def _current_user_id(request): return request.session.get("user_id")

def _current_year(): return datetime.utcnow().year

# Disable Jinja2 bytecode cache to prevent Python 3.14 TypeError with unhashable globals
templates.env.cache = None  # type: ignore[assignment]

# Register global helpers
templates.env.globals.update({
    "is_logged_in": _is_logged_in,
    "current_role": _current_role,
    "current_user_name": _current_user_name,
    "current_user_id": _current_user_id,
    "ss": get_ss,
    "ss_raw": get_ss,
    "hero_title": hero_title_formatter,
    "current_year": _current_year,
    "csrf_token": "fixed-csrf-token-for-python",
})

# Database setup on startup (checks & creates tables if missing)
@app.on_event("startup")
def startup_event():
    try:
        Base.metadata.create_all(bind=engine)
        print("[Startup] Database tables verified successfully.")
    except Exception as e:
        print(f"[Startup] Database setup warning: {e}")

# Import routes (we will create these routers in the next steps)
from app.routes import auth, dashboards, reports, chat, payments, stats_api

app.include_router(auth.router)
app.include_router(dashboards.router)
app.include_router(reports.router)
app.include_router(chat.router)
app.include_router(payments.router)
app.include_router(stats_api.router)

# Root landing page route
@app.get("/")
@app.get("/index.php")
def landing_page(request: Request, db: Session = Depends(get_db)):
    # Fetch active video paths
    from app.models import HeroVideo, HeroShowcaseVideo
    
    hero_video = None
    showcase_video = None
    try:
        h_vid = db.query(HeroVideo).filter_by(is_active=True).order_by(HeroVideo.id.desc()).first()
        if h_vid:
            hero_video = h_vid.video_path
            
        s_vid = db.query(HeroShowcaseVideo).filter_by(is_active=True).order_by(HeroShowcaseVideo.id.desc()).first()
        if s_vid:
            showcase_video = s_vid.video_path
    except Exception:
        pass
        
    return templates.TemplateResponse(request, "index.html", {"hero_video": hero_video,
        "showcase_video": showcase_video})
