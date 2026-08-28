import os
import json
import secrets
from urllib.parse import urlencode, quote

import httpx
from fastapi import APIRouter, Request, Depends, Form
from fastapi.responses import RedirectResponse, HTMLResponse
from sqlalchemy.orm import Session

from app.database import get_db, SessionLocal
from app.models import User, Church, Zone, ZoneChurch, ChurchExpenseItem, SiteSetting
from app.auth import get_password_hash, login_user, is_logged_in
from app.main import templates
from app.utils import defaultExpenseItems

router = APIRouter(prefix="/auth/google", tags=["google_oauth"])

def get_google_credentials(db: Session = None):
    """Retrieve Google OAuth Client ID and Secret from ENV or SiteSettings table."""
    client_id = os.getenv("GOOGLE_CLIENT_ID", "").strip()
    client_secret = os.getenv("GOOGLE_CLIENT_SECRET", "").strip()

    if not client_id or not client_secret:
        close_db = False
        if not db:
            db = SessionLocal()
            close_db = True
        try:
            if not client_id:
                row = db.query(SiteSetting).filter_by(setting_key="google_client_id").first()
                if row and row.setting_value:
                    client_id = row.setting_value.strip()
            if not client_secret:
                row = db.query(SiteSetting).filter_by(setting_key="google_client_secret").first()
                if row and row.setting_value:
                    client_secret = row.setting_value.strip()
        finally:
            if close_db:
                db.close()

    return client_id, client_secret

GOOGLE_SCOPES = "openid email profile"

def _get_redirect_uri(request: Request) -> str:
    proto = request.headers.get("x-forwarded-proto", request.url.scheme)
    host = request.headers.get("x-forwarded-host", request.url.netloc)
    return f"{proto}://{host}/auth/google/callback"

def _google_auth_url(redirect_uri: str, state: str, client_id: str) -> str:
    params = {
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "response_type": "code",
        "scope": GOOGLE_SCOPES,
        "state": state,
        "access_type": "online",
        "prompt": "select_account",
    }
    return "https://accounts.google.com/o/oauth2/v2/auth?" + urlencode(params)


@router.get("/login")
def google_login(request: Request, signup_role: str = "", db: Session = Depends(get_db)):
    client_id, _ = get_google_credentials(db)
    if not client_id:
        return RedirectResponse(
            url=f"/login?error={quote('Google sign-in is not configured yet. Please use email/password.')}",
            status_code=303
        )
    state = secrets.token_urlsafe(16)
    request.session["oauth_state"] = state
    request.session["oauth_signup_role"] = signup_role or "login"
    redirect_uri = _get_redirect_uri(request)
    return RedirectResponse(url=_google_auth_url(redirect_uri, state, client_id))


@router.get("/callback")
async def google_callback(
    request: Request,
    code: str = None,
    state: str = None,
    error: str = None,
    db: Session = Depends(get_db)
):
    if error or not code:
        err_text = error or "Google sign-in was cancelled. Please try again."
        return RedirectResponse(url=f"/login?error={quote(err_text)}", status_code=303)

    expected_state = request.session.pop("oauth_state", None)
    signup_role = request.session.pop("oauth_signup_role", "login")
    if not expected_state or state != expected_state:
        return RedirectResponse(url="/login?error=Invalid+OAuth+state.+Please+try+again.", status_code=303)

    client_id, client_secret = get_google_credentials(db)
    if not client_id or not client_secret:
        return RedirectResponse(url="/login?error=Google+OAuth+credentials+missing.", status_code=303)

    redirect_uri = _get_redirect_uri(request)

    try:
        async with httpx.AsyncClient(timeout=15.0) as client:
            token_resp = await client.post(
                "https://oauth2.googleapis.com/token",
                data={
                    "code": code,
                    "client_id": client_id,
                    "client_secret": client_secret,
                    "redirect_uri": redirect_uri,
                    "grant_type": "authorization_code",
                }
            )
            token_data = token_resp.json()
            access_token = token_data.get("access_token")
            if not access_token:
                raise ValueError(f"No access token: {token_data}")

            user_resp = await client.get(
                "https://www.googleapis.com/oauth2/v3/userinfo",
                headers={"Authorization": f"Bearer {access_token}"}
            )
            ginfo = user_resp.json()
    except Exception as e:
        print(f"[Google OAuth Error]: {e}")
        return RedirectResponse(url=f"/login?error={quote('Google authentication failed. Please try again.')}", status_code=303)

    google_id = ginfo.get("sub")
    email = ginfo.get("email", "").strip().lower()
    full_name = ginfo.get("name", "").strip()
    picture = ginfo.get("picture", "")

    if not google_id or not email:
        return RedirectResponse(url="/login?error=Could+not+retrieve+your+Google+account+info.", status_code=303)

    user = db.query(User).filter(User.google_id == google_id).first()
    if not user:
        user = db.query(User).filter(User.email == email).first()

    if user:
        if user.status != "active":
            return RedirectResponse(url="/login?error=Your+account+is+pending+or+suspended.+Please+contact+admin.", status_code=303)
        changed = False
        if not user.google_id:
            user.google_id = google_id
            changed = True
        if not user.profile_photo and picture:
            user.google_avatar = picture
            user.profile_photo = picture
            changed = True
        if changed:
            db.commit()

        login_user(request, user, db)
        from app.auth import redirect_to_dashboard
        return redirect_to_dashboard(user.role)

    # New user: store details in session and redirect to Setup page to complete church/zone profile
    request.session["google_pending"] = json.dumps({
        "google_id": google_id,
        "email": email,
        "full_name": full_name or "Google User",
        "picture": picture,
        "signup_role": signup_role,
    })
    return RedirectResponse(url="/auth/google/setup", status_code=303)


@router.get("/setup", response_class=HTMLResponse)
def google_setup_get(request: Request):
    pending_raw = request.session.get("google_pending")
    if not pending_raw:
        return RedirectResponse(url="/login", status_code=303)
    pending = json.loads(pending_raw)
    signup_role = pending.get("signup_role", "login")
    prefill_role = "church_admin" if signup_role == "church" else ("zonal_admin" if signup_role == "zone" else "church_admin")
    return templates.TemplateResponse(request, "google_setup.html", {
        "pending": pending,
        "prefill_role": prefill_role,
        "error": "",
    })


@router.post("/setup", response_class=HTMLResponse)
async def google_setup_post(request: Request, db: Session = Depends(get_db)):
    pending_raw = request.session.get("google_pending")
    if not pending_raw:
        return RedirectResponse(url="/login", status_code=303)
    pending = json.loads(pending_raw)

    form = await request.form()
    role = form.get("role", "").strip()
    full_name = form.get("full_name", pending.get("full_name", "")).strip()
    phone = form.get("phone", "").strip()

    # Church fields
    church_name = form.get("church_name", "").strip()
    district = form.get("district", "").strip()
    church_type = form.get("church_type", "unchartered").strip()
    church_address = form.get("church_address", "").strip()
    pastor_name = form.get("pastor_name", "").strip()
    pastor_address = form.get("pastor_address", "").strip()

    # Zone fields
    zone_name = form.get("zone_name", "").strip()
    zone_churches_raw = form.get("zone_churches", "").strip()

    def bad(msg):
        return templates.TemplateResponse(request, "google_setup.html", {
            "pending": pending,
            "prefill_role": role,
            "error": msg,
        })

    if role not in ("church_admin", "zonal_admin"):
        return bad("Please select whether you represent a Local Church or a Zone.")

    if role == "church_admin":
        if not church_name or not district:
            return bad("Church Name and District are required to generate your monthly reports.")
        if church_type not in ("chartered", "unchartered"):
            church_type = "unchartered"

    if role == "zonal_admin":
        if not zone_name:
            return bad("Zone Name is required to set up your zonal portal.")

    existing = db.query(User).filter(User.email == pending["email"]).first()
    if existing:
        request.session.pop("google_pending", None)
        return RedirectResponse(url=f"/login?msg={quote('Account already registered! Please log in.')}", status_code=303)

    try:
        user = User(
            full_name=full_name or pending["full_name"],
            email=pending["email"],
            phone=phone,
            password_hash="",
            role=role,
            status="active",
            google_id=pending["google_id"],
            google_avatar=pending.get("picture"),
            profile_photo=pending.get("picture"),
        )
        db.add(user)
        db.commit()
        db.refresh(user)

        if role == "church_admin":
            church = Church(
                name=church_name,
                district=district,
                address=church_address,
                pastor_name=pastor_name or full_name,
                pastor_address=pastor_address,
                church_type=church_type,
                created_by=user.id
            )
            db.add(church)
            db.commit()
            db.refresh(church)

            for item in defaultExpenseItems():
                db.add(ChurchExpenseItem(
                    church_id=church.id,
                    report_id=None,
                    item_key=item["item_key"],
                    label=item["label"],
                    amount=0.00,
                    is_custom=False,
                    display_order=item["display_order"]
                ))
            db.commit()
            request.session["church_id"] = church.id

        elif role == "zonal_admin":
            zone = Zone(zone_name=zone_name, created_by=user.id)
            db.add(zone)
            db.commit()
            db.refresh(zone)

            churches_list = [c.strip() for c in zone_churches_raw.splitlines() if c.strip()] or ["ZONAL HQTS", "BRANCH 1", "BRANCH 2", "BRANCH 3"]
            for i, cname in enumerate(churches_list):
                db.add(ZoneChurch(zone_id=zone.id, church_name=cname, display_order=i + 1))
            db.commit()
            request.session["zone_id"] = zone.id

        request.session.pop("google_pending", None)
        login_user(request, user, db)
        from app.auth import redirect_to_dashboard
        return redirect_to_dashboard(user.role)

    except Exception as e:
        db.rollback()
        return bad(f"Setup error: {str(e)}")