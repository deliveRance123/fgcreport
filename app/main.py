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

# Session Secret Key config (Render uses environment variable, fallback to consistent default)
SECRET_KEY = os.getenv("SESSION_SECRET", "super-secret-fgc-key-999-permanent-2026")
app.add_middleware(
    SessionMiddleware,
    secret_key=SECRET_KEY,
    session_cookie="fgc_session",
    max_age=14 * 86400,
    same_site="lax",
    https_only=False
)

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
    if not (path.startswith("/assets") or path.startswith("/uploads")):
        content_type = response.headers.get("content-type", "")
        if "text/html" in content_type:
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

# Session helpers — safely handle empty / missing request sessions
def _is_logged_in(request):
    try:
        return "user_id" in request.session
    except Exception:
        return False

def _current_role(request):
    try:
        return request.session.get("role")
    except Exception:
        return None

def _current_user_name(request):
    try:
        return request.session.get("full_name")
    except Exception:
        return None

def _current_user_id(request):
    try:
        return request.session.get("user_id")
    except Exception:
        return None

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
        from app.database import SessionLocal as _SL
        Base.metadata.create_all(bind=engine)
        print("[Startup] Database tables verified successfully.")
        # Run safe schema migrations
        try:
            import app.init_schema as _schema
            _schema.run_migrations(engine)
        except Exception:
            pass
    except Exception as e:
        print(f"[Startup] Database setup warning: {e}")

# Import routes
from app.routes import auth, dashboards, reports, chat, payments, stats_api, google_oauth

app.include_router(auth.router)
app.include_router(dashboards.router)
app.include_router(reports.router)
app.include_router(chat.router)
app.include_router(payments.router)
app.include_router(stats_api.router)
app.include_router(google_oauth.router)

# ── Global Exception Handlers ─────────────────────────────────────────────────

from fastapi.exceptions import HTTPException as FastAPIHTTPException
from starlette.exceptions import HTTPException as StarletteHTTPException

@app.exception_handler(FastAPIHTTPException)
@app.exception_handler(StarletteHTTPException)
async def http_exception_handler(request: Request, exc: StarletteHTTPException):
    """Catch 404, 403, 303 redirects, and other HTTP errors — never show blank pages."""
    # Pass redirects through cleanly
    if exc.status_code in (301, 302, 303, 307, 308):
        headers = getattr(exc, "headers", None) or {}
        location = headers.get("Location") or headers.get("location") or "/login"
        return RedirectResponse(url=location, status_code=exc.status_code)

    # If unauthenticated user hits 401 or 403, redirect to login
    if exc.status_code in (401, 403):
        return RedirectResponse(url="/login?error=Please+log+in+to+continue.", status_code=303)

    # 404 — Page not found
    if exc.status_code == 404:
        return templates.TemplateResponse(
            request, "error.html",
            {
                "status_code": 404,
                "title": "Page Not Found",
                "message": "The page you're looking for doesn't exist or may have moved. Please use the button below to go back."
            },
            status_code=404
        )

    # All other HTTP errors
    return templates.TemplateResponse(
        request, "error.html",
        {
            "status_code": exc.status_code,
            "title": "System Notice",
            "message": str(exc.detail) if exc.detail else "Something went wrong. Please return to your dashboard."
        },
        status_code=exc.status_code
    )

@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    """Catch all unhandled Python exceptions — prevents blank white page on crash."""
    import traceback
    print(f"[UNHANDLED ERROR on {request.url.path}]: {traceback.format_exc()}")
    return templates.TemplateResponse(
        request, "error.html",
        {
            "status_code": 500,
            "title": "System Recovery",
            "message": "The server encountered a temporary error while loading this page. Please click below to return to your dashboard."
        },
        status_code=500
    )

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
