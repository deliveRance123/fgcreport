import os
from fastapi import Request, HTTPException, status
from fastapi.responses import RedirectResponse
import bcrypt
from sqlalchemy.orm import Session
from app.models import Church, Zone, User

def verify_password(plain_password: str, hashed_password: str) -> bool:
    """
    Verifies password. Supports PHP's $2y$ BCrypt prefix by converting it
    to standard $2b$ for Python compatibility.
    """
    if not hashed_password:
        return False
    if hashed_password.startswith("$2y$"):
        hashed_password = hashed_password.replace("$2y$", "$2b$", 1)
    try:
        return bcrypt.checkpw(plain_password.encode("utf-8"), hashed_password.encode("utf-8"))
    except Exception:
        return False

def get_password_hash(password: str) -> str:
    """
    Generates BCrypt password hash.
    """
    salt = bcrypt.gensalt()
    return bcrypt.hashpw(password.encode("utf-8"), salt).decode("utf-8")

def is_logged_in(request: Request) -> bool:
    """
    Checks if a user is logged in.
    """
    return "user_id" in request.session

def current_user_id(request: Request) -> int | None:
    """
    Returns logged in user ID or None.
    """
    return request.session.get("user_id")

def current_role(request: Request) -> str | None:
    """
    Returns logged in user's role or None.
    """
    return request.session.get("role")

def current_church_id(request: Request, db: Session) -> int | None:
    """
    Gets the church ID associated with the current user, resolving fallback values
    if the session is missing the key (matching PHP auth.php).
    """
    if "church_id" in request.session and request.session["church_id"] is not None:
        return int(request.session["church_id"])
        
    uid = current_user_id(request)
    if uid:
        try:
            # 1. Resolve church created by this user
            church = db.query(Church).filter(Church.created_by == uid).first()
            if church:
                request.session["church_id"] = church.id
                return church.id
            
            # 2. Fallback: select first registered church
            first_church = db.query(Church).order_by(Church.id.asc()).first()
            if first_church:
                request.session["church_id"] = first_church.id
                return first_church.id
        except Exception:
            pass
    return None

def current_zone_id(request: Request, db: Session) -> int | None:
    """
    Gets the zone ID associated with the current user, resolving fallback values
    if the session is missing the key (matching PHP auth.php).
    """
    if "zone_id" in request.session and request.session["zone_id"] is not None:
        return int(request.session["zone_id"])
        
    uid = current_user_id(request)
    if uid:
        try:
            # 1. Resolve zone created by this user
            zone = db.query(Zone).filter(Zone.created_by == uid).first()
            if zone:
                request.session["zone_id"] = zone.id
                return zone.id
            
            # 2. Fallback: select first registered zone
            first_zone = db.query(Zone).order_by(Zone.id.asc()).first()
            if first_zone:
                request.session["zone_id"] = first_zone.id
                return first_zone.id
        except Exception:
            pass
    return None

def login_user(request: Request, user: User, db: Session) -> None:
    """
    Initializes user session variables upon login.
    """
    request.session["user_id"] = user.id
    request.session["role"] = user.role
    request.session["full_name"] = user.full_name
    request.session["email"] = user.email

    # Pre-resolve entity IDs based on role
    if user.role == "church_admin":
        current_church_id(request, db)
    elif user.role == "zonal_admin":
        current_zone_id(request, db)

def logout_user(request: Request) -> None:
    """
    Destroys user session.
    """
    request.session.clear()

def require_login(request: Request):
    """
    Dependency or check to enforce authentication.
    Returns RedirectResponse if not logged in.
    """
    if not is_logged_in(request):
        raise HTTPException(
            status_code=status.HTTP_307_TEMPORARY_REDIRECT,
            headers={"Location": "/login"}
        )

def require_role(*roles: str):
    """
    Dependency to enforce specific user roles.
    """
    def dependency(request: Request):
        require_login(request)
        role = current_role(request)
        if role not in roles:
            raise HTTPException(
                status_code=status.HTTP_307_TEMPORARY_REDIRECT,
                headers={"Location": "/login"}
            )
    return dependency

def redirect_to_dashboard(role: str) -> RedirectResponse:
    """
    Maps role to correct dashboard page URL matching PHP.
    """
    mapping = {
        "church_admin": "/church-dashboard",
        "zonal_admin": "/zone-dashboard",
        "super_admin": "/admin-dashboard"
    }
    target = mapping.get(role, "/login")
    return RedirectResponse(url=target, status_code=status.HTTP_303_SEE_OTHER)
