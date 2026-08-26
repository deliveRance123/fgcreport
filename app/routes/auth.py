import os
from fastapi import APIRouter, Request, Depends, Form, File, UploadFile
from fastapi.responses import RedirectResponse, HTMLResponse
from sqlalchemy.orm import Session
from app.database import get_db, SessionLocal
from app.models import User, Church, Zone, ZoneChurch, ChurchExpenseItem
from app.auth import (
    verify_password, get_password_hash, login_user, logout_user, 
    is_logged_in, current_role, redirect_to_dashboard, current_user_id
)
from app.utils import defaultExpenseItems

router = APIRouter()

# Setup templates path from app/main.py configuration
from app.main import templates

@router.get("/login", response_class=HTMLResponse)
def get_login(request: Request, preview: str = None, db: Session = Depends(get_db)):
    # 1. Handle Preview Bypass
    if preview:
        try:
            # We use a transaction context
            
            if preview in ["church_admin", "church"]:
                # Find existing church admin
                user = db.query(User).filter(User.role == "church_admin").first()
                if not user:
                    # Create preview user
                    pwd = get_password_hash("password")
                    user = User(
                        full_name="Preview Church Admin",
                        email="preview_church@fgc-report.org",
                        phone="123",
                        password_hash=pwd,
                        role="church_admin",
                        status="active"
                    )
                    db.add(user)
                    db.commit()
                    db.refresh(user)

                    # Create preview church
                    church = Church(
                        name="Preview Local Church",
                        district="Lagos District",
                        address="123 Church Rd",
                        pastor_name="Pastor John Doe",
                        pastor_address="Pastor House",
                        church_type="unchartered",
                        created_by=user.id
                    )
                    db.add(church)
                    db.commit()
                    db.refresh(church)

                    # Seed 29 Default Expense Items
                    defaults = defaultExpenseItems()
                    for item in defaults:
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
                else:
                    church = db.query(Church).filter(Church.created_by == user.id).first()
                
                # Log user in
                login_user(request, user, db)
                if church:
                    request.session["church_id"] = church.id
                db.commit()
                return RedirectResponse(url="/church-dashboard", status_code=303)

            elif preview in ["zonal_admin", "zone_admin", "zone"]:
                # Find existing zonal admin
                user = db.query(User).filter(User.role == "zonal_admin").first()
                if not user:
                    pwd = get_password_hash("password")
                    user = User(
                        full_name="Preview Zonal Admin",
                        email="preview_zone@fgc-report.org",
                        phone="123",
                        password_hash=pwd,
                        role="zonal_admin",
                        status="active"
                    )
                    db.add(user)
                    db.commit()
                    db.refresh(user)

                    # Create preview zone
                    zone = Zone(
                        zone_name="Preview Zone (Isara Zone)",
                        created_by=user.id
                    )
                    db.add(zone)
                    db.commit()
                    db.refresh(zone)

                    # Add churches to zone
                    churches_to_add = [
                        ("ZONAL HQTS", 1),
                        ("ISARA II", 2),
                        ("IPARA", 3),
                        ("ODE INTAKE", 4)
                    ]
                    for name, order in churches_to_add:
                        db.add(ZoneChurch(
                            zone_id=zone.id,
                            church_name=name,
                            display_order=order
                        ))
                    db.commit()
                else:
                    zone = db.query(Zone).filter(Zone.created_by == user.id).first()
                    if not zone:
                        zone = Zone(
                            zone_name="Preview Zone (Isara Zone)",
                            created_by=user.id
                        )
                        db.add(zone)
                        db.commit()
                        db.refresh(zone)

                # Ensure default churches exist under the zone
                existing_churches = db.query(ZoneChurch).filter_by(zone_id=zone.id).all()
                if not existing_churches:
                    churches_to_add = [
                        ("ZONAL HQTS", 1),
                        ("ISARA II", 2),
                        ("IPARA", 3),
                        ("ODE INTAKE", 4)
                    ]
                    for name, order in churches_to_add:
                        db.add(ZoneChurch(
                            zone_id=zone.id,
                            church_name=name,
                            display_order=order
                        ))
                    db.commit()

                login_user(request, user, db)
                if zone:
                    request.session["zone_id"] = zone.id
                db.commit()
                return RedirectResponse(url="/zone-dashboard", status_code=303)

            elif preview in ["super_admin", "admin"]:
                # Find super admin
                user = db.query(User).filter(User.role == "super_admin").first()
                if not user:
                    pwd = get_password_hash("password")
                    user = User(
                        full_name="Preview Super Admin",
                        email="preview_admin@report.org",
                        phone="123",
                        password_hash=pwd,
                        role="super_admin",
                        status="active"
                    )
                    db.add(user)
                    db.commit()
                    db.refresh(user)

                login_user(request, user, db)
                db.commit()
                return RedirectResponse(url="/admin-dashboard", status_code=303)

        except Exception as e:
            import traceback
            print(f"[AUTH PREVIEW ERROR]: {traceback.format_exc()}")
            db.rollback()
            return templates.TemplateResponse(request, "login.html", {"error": f"Error setting up preview session: {str(e)}"})

    # 2. Redirect if already logged in
    if is_logged_in(request):
        return redirect_to_dashboard(current_role(request))

    return templates.TemplateResponse(request, "login.html", {"error": ""})


@router.post("/login", response_class=HTMLResponse)
def post_login(
    request: Request,
    access_user: str = Form(None),
    access_pass: str = Form(None),
    email: str = Form(None),      # fallback
    password: str = Form(None),   # fallback
    db: Session = Depends(get_db)
):
    usr = access_user or email
    pwd = access_pass or password

    if not usr or not pwd:
        return templates.TemplateResponse(request, "login.html", {"error": "Email and password are required.",
            "access_user": usr or ""})

    user = db.query(User).filter(User.email == usr.strip()).first()
    if user and verify_password(pwd, user.password_hash):
        if user.status != "active":
            return templates.TemplateResponse(request, "login.html", {"error": "Your account is pending or suspended. Please contact the administrator.",
                "access_user": usr})
        
        # Log in
        login_user(request, user, db)
        return redirect_to_dashboard(user.role)
    else:
        return templates.TemplateResponse(request, "login.html", {"error": "Invalid email or password.",
            "access_user": usr})


@router.get("/logout")
def get_logout(request: Request):
    logout_user(request)
    return RedirectResponse(url="/login", status_code=303)


@router.get("/profile", response_class=HTMLResponse)
def get_profile(request: Request, db: Session = Depends(get_db)):
    if not is_logged_in(request):
        return RedirectResponse(url="/login", status_code=303)
        
    uid = current_user_id(request)
    user = db.query(User).filter(User.id == uid).first()
    
    # Calculate initials
    names = user.full_name.strip().split()
    initials = ""
    if names:
        initials += names[0][0].upper()
        if len(names) > 1:
            initials += names[-1][0].upper()

    return templates.TemplateResponse(request, "profile.html", {"user": user,
        "initials": initials,
        "successMsg": "",
        "errorMsg": ""})


@router.post("/profile", response_class=HTMLResponse)
async def post_profile(
    request: Request,
    action: str = Form("update_profile"),
    full_name: str = Form(None),
    email: str = Form(None),
    phone: str = Form(None),
    bio: str = Form(None),
    profile_photo: UploadFile = File(None),
    current_password: str = Form(None),
    new_password: str = Form(None),
    confirm_password: str = Form(None),
    db: Session = Depends(get_db)
):
    if not is_logged_in(request):
        return RedirectResponse(url="/login", status_code=303)
        
    uid = current_user_id(request)
    user = db.query(User).filter(User.id == uid).first()
    successMsg = ""
    errorMsg = ""

    if action == "update_profile":
        if not full_name or not email:
            errorMsg = "Full name and email are required."
        else:
            # Check uniqueness
            existing = db.query(User).filter(User.email == email.strip(), User.id != uid).first()
            if existing:
                errorMsg = "That email address is already in use by another account."
            else:
                try:
                    # Handle profile photo upload
                    photo_path = user.profile_photo
                    if profile_photo and profile_photo.filename:
                        # Validate extension
                        ext = os.path.splitext(profile_photo.filename)[1].lower()
                        if ext in [".jpg", ".jpeg", ".png", ".webp", ".gif"]:
                            import time
                            filename = f"user_{uid}_{int(time.time())}{ext}"
                            save_dir = "uploads/profiles"
                            os.makedirs(save_dir, exist_ok=True)
                            filepath = os.path.join(save_dir, filename)
                            
                            with open(filepath, "wb") as f:
                                f.write(await profile_photo.read())
                            photo_path = f"uploads/profiles/{filename}"

                    user.full_name = full_name.strip()
                    user.email = email.strip()
                    user.phone = phone.strip() if phone else ""
                    user.bio = bio.strip() if bio else ""
                    user.profile_photo = photo_path
                    
                    db.commit()
                    request.session["full_name"] = user.full_name
                    successMsg = "Profile updated successfully!"
                except Exception as e:
                    db.rollback()
                    errorMsg = f"Error updating profile: {str(e)}"

    elif action == "change_password":
        if not current_password or not new_password or not confirm_password:
            errorMsg = "All password fields are required."
        elif not verify_password(current_password, user.password_hash):
            errorMsg = "Current password is incorrect."
        elif new_password != confirm_password:
            errorMsg = "New passwords do not match."
        elif len(new_password) < 8:
            errorMsg = "New password must be at least 8 characters."
        else:
            try:
                user.password_hash = get_password_hash(new_password)
                db.commit()
                successMsg = "Password changed successfully!"
            except Exception as e:
                db.rollback()
                errorMsg = f"Error changing password: {str(e)}"

    # Re-calculate initials
    names = user.full_name.strip().split()
    initials = ""
    if names:
        initials += names[0][0].upper()
        if len(names) > 1:
            initials += names[-1][0].upper()

    return templates.TemplateResponse(request, "profile.html", {"user": user,
        "initials": initials,
        "successMsg": successMsg,
        "errorMsg": errorMsg})


@router.get("/register-church", response_class=HTMLResponse)
def get_register_church(request: Request):
    if is_logged_in(request):
        return redirect_to_dashboard(current_role(request))
    return templates.TemplateResponse(request, "register_church.html", {"error": "", "success": "", "form_data": {}})


@router.post("/register-church", response_class=HTMLResponse)
def post_register_church(
    request: Request,
    church_name: str = Form(None),
    district: str = Form(""),
    address: str = Form(""),
    pastor_name: str = Form(""),
    pastor_address: str = Form(""),
    church_type: str = Form(None),
    full_name: str = Form(None),
    email: str = Form(None),
    phone: str = Form(""),
    password: str = Form(None),
    confirm_password: str = Form(None),
    db: Session = Depends(get_db)
):
    form_data = {
        "church_name": church_name or "",
        "district": district or "",
        "address": address or "",
        "pastor_name": pastor_name or "",
        "pastor_address": pastor_address or "",
        "church_type": church_type or "",
        "full_name": full_name or "",
        "email": email or "",
        "phone": phone or ""
    }

    if not church_name or not church_type or not full_name or not email or not password:
        return templates.TemplateResponse(request, "register_church.html", {"error": "All required fields must be filled.", "success": "", "form_data": form_data})
    if password != confirm_password:
        return templates.TemplateResponse(request, "register_church.html", {"error": "Passwords do not match.", "success": "", "form_data": form_data})
    if church_type not in ["chartered", "unchartered"]:
        return templates.TemplateResponse(request, "register_church.html", {"error": "Invalid church type selected.", "success": "", "form_data": form_data})

    # Check if email exists
    existing = db.query(User).filter(User.email == email.strip()).first()
    if existing:
        return templates.TemplateResponse(request, "register_church.html", {"error": "Email address is already registered.", "success": "", "form_data": form_data})

    try:
        db.begin_nested()
        
        # 1. Create User
        user = User(
            full_name=full_name.strip(),
            email=email.strip(),
            phone=phone.strip(),
            password_hash=get_password_hash(password),
            role="church_admin",
            status="active"
        )
        db.add(user)
        db.commit()
        db.refresh(user)

        # 2. Create Church
        church = Church(
            name=church_name.strip(),
            district=district.strip(),
            address=address.strip(),
            pastor_name=pastor_name.strip(),
            pastor_address=pastor_address.strip(),
            church_type=church_type,
            created_by=user.id
        )
        db.add(church)
        db.commit()
        db.refresh(church)

        # 3. Seed 29 Default Expense Items for this church
        defaults = defaultExpenseItems()
        for item in defaults:
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
        return templates.TemplateResponse(request, "register_church.html", {"error": "", "success": "Church and admin account registered successfully! You can now log in.", "form_data": {}})
    except Exception as e:
        db.rollback()
        return templates.TemplateResponse(request, "register_church.html", {"error": f"Registration error: {str(e)}", "success": "", "form_data": form_data})


@router.get("/register-zone", response_class=HTMLResponse)
def get_register_zone(request: Request):
    if is_logged_in(request):
        return redirect_to_dashboard(current_role(request))
    return templates.TemplateResponse(request, "register_zone.html", {"error": "", "success": "", "form_data": {}})


@router.post("/register-zone", response_class=HTMLResponse)
def post_register_zone(
    request: Request,
    zone_name: str = Form(None),
    church_list: str = Form(""),
    full_name: str = Form(None),
    email: str = Form(None),
    phone: str = Form(""),
    password: str = Form(None),
    confirm_password: str = Form(None),
    db: Session = Depends(get_db)
):
    form_data = {
        "zone_name": zone_name or "",
        "church_list": church_list or "",
        "full_name": full_name or "",
        "email": email or "",
        "phone": phone or ""
    }

    if not zone_name or not full_name or not email or not password:
        return templates.TemplateResponse(request, "register_zone.html", {"error": "All required fields must be filled.", "success": "", "form_data": form_data})
    if password != confirm_password:
        return templates.TemplateResponse(request, "register_zone.html", {"error": "Passwords do not match.", "success": "", "form_data": form_data})

    # Check if email exists
    existing = db.query(User).filter(User.email == email.strip()).first()
    if existing:
        return templates.TemplateResponse(request, "register_zone.html", {"error": "Email address is already registered.", "success": "", "form_data": form_data})

    try:
        db.begin_nested()
        
        # 1. Create User
        user = User(
            full_name=full_name.strip(),
            email=email.strip(),
            phone=phone.strip(),
            password_hash=get_password_hash(password),
            role="zonal_admin",
            status="active"
        )
        db.add(user)
        db.commit()
        db.refresh(user)

        # 2. Create Zone
        zone = Zone(
            zone_name=zone_name.strip(),
            created_by=user.id
        )
        db.add(zone)
        db.commit()
        db.refresh(zone)

        # 3. Add churches to zone
        churches = [c.strip() for c in church_list.split("\n") if c.strip()]
        if not churches:
            churches = ["ZONAL HQTS", "ISARA II", "IPARA", "ODE INTAKE"]  # Fallbacks
        
        for i, cname in enumerate(churches):
            db.add(ZoneChurch(
                zone_id=zone.id,
                church_name=cname,
                display_order=i+1
            ))
        
        db.commit()
        return templates.TemplateResponse(request, "register_zone.html", {"error": "", "success": "Zone and admin account registered successfully! You can now log in.", "form_data": {}})
    except Exception as e:
        db.rollback()
        return templates.TemplateResponse(request, "register_zone.html", {"error": f"Registration error: {str(e)}", "success": "", "form_data": form_data})


# Super admin account initialization trigger matching admin/setup.php
@router.get("/admin/setup", response_class=HTMLResponse)
def get_admin_setup(request: Request, db: Session = Depends(get_db)):
    super_admin_exists = db.query(User).filter(User.role == "super_admin").count() > 0
    return templates.TemplateResponse(request, "setup.html", {"superAdminExists": super_admin_exists,
        "error": "",
        "success": "",
        "form_data": {}})

@router.post("/admin/setup", response_class=HTMLResponse)
def post_admin_setup(
    request: Request,
    full_name: str = Form(None),
    email: str = Form(None),
    phone: str = Form(""),
    password: str = Form(None),
    confirm_password: str = Form(None),
    db: Session = Depends(get_db)
):
    super_admin_exists = db.query(User).filter(User.role == "super_admin").count() > 0
    form_data = {
        "full_name": full_name or "",
        "email": email or "",
        "phone": phone or ""
    }

    if super_admin_exists:
        return templates.TemplateResponse(request, "setup.html", {"superAdminExists": True,
            "error": "Super Admin account already exists. For security, this page is locked.",
            "success": "",
            "form_data": {}})

    if not full_name or not email or not password or not confirm_password:
        return templates.TemplateResponse(request, "setup.html", {"superAdminExists": False,
            "error": "Full Name, Email, and Password are required.",
            "success": "",
            "form_data": form_data})

    if password != confirm_password:
        return templates.TemplateResponse(request, "setup.html", {"superAdminExists": False,
            "error": "Passwords do not match.",
            "success": "",
            "form_data": form_data})

    try:
        user = User(
            full_name=full_name.strip(),
            email=email.strip(),
            phone=phone.strip(),
            password_hash=get_password_hash(password),
            role="super_admin",
            status="active"
        )
        db.add(user)
        db.commit()
        return templates.TemplateResponse(request, "setup.html", {"superAdminExists": True,
            "error": "",
            "success": "Super Admin account created successfully! You can now log in.",
            "form_data": {}})
    except Exception as e:
        db.rollback()
        return templates.TemplateResponse(request, "setup.html", {"superAdminExists": False,
            "error": f"Database error: {str(e)}",
            "success": "",
            "form_data": form_data})
