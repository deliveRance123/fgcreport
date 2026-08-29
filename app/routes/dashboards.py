import os
import shutil
import json
from datetime import datetime, timedelta
from urllib.parse import quote
from fastapi import APIRouter, Request, Depends, Form, File, UploadFile
from fastapi.responses import RedirectResponse, HTMLResponse
from sqlalchemy.orm import Session
from sqlalchemy import func
from app.database import get_db
from app.models import (
    User, Church, Zone, ZoneChurch, DuePercentageSettings, 
    DuePercentageAuditLog, SiteSetting, HeroVideo, HeroShowcaseVideo, 
    ChatbotKnowledgeBase, ChurchFinancialReport, ChurchSpiritualReport, ZonalReport
)
from app.auth import (
    is_logged_in, current_role, current_user_id, current_church_id, current_zone_id, ensure_role_session
)
from app.utils import getUserTrialAndSubStatus, getPaymentSettings, formatNaira, monthName

router = APIRouter()

from app.main import templates


# =========================================================================
# CHURCH DASHBOARD
# =========================================================================
@router.get("/church-dashboard", response_class=HTMLResponse)
def get_church_dashboard(request: Request, error: str = "", msg: str = "", db: Session = Depends(get_db)):
    uid = ensure_role_session(request, "church_admin", db)
    cid = current_church_id(request, db)
    church = db.query(Church).filter(Church.id == cid).first() if cid else None

    if not church:
        church = db.query(Church).filter(Church.created_by == uid).first()
        if not church:
            church = db.query(Church).first()
        if not church:
            church = Church(
                name="Foursquare Local Church",
                district="Lagos District",
                address="",
                pastor_name="Lead Pastor",
                pastor_address="",
                church_type="unchartered",
                created_by=uid
            )
            db.add(church)
            db.commit()
            db.refresh(church)
        cid = church.id
        request.session["church_id"] = cid

    sub_status = getUserTrialAndSubStatus(db, uid)
    pay_settings = getPaymentSettings(db)
    sub_amount = float(pay_settings.get("monthly_sub_amount") or 5000)
    can_create_report = bool(sub_status.get("is_active", True)) or pay_settings.get("payment_enabled") != "1"

    now = datetime.now()

    default_month = now.month
    default_year = now.year

    # Fetch report history
    reports = db.query(ChurchFinancialReport).filter_by(church_id=cid).order_by(
        ChurchFinancialReport.report_year.desc(),
        ChurchFinancialReport.report_month.desc()
    ).all() if cid else []
    latest_report = reports[0] if reports else None

    # Calculate YTD receipts and attendance totals
    ytd_receipts = 0.0
    latest_month_newcomers = 0
    latest_month_members = 0

    for r in reports:
        if r.report_year == default_year:
            ytd_receipts += float(r.total_receipts or 0.0)

    if latest_report:
        sp = db.query(ChurchSpiritualReport).filter_by(
            church_id=cid,
            report_month=latest_report.report_month,
            report_year=latest_report.report_year
        ).first()
        if sp:
            latest_month_newcomers = sp.total_new_comers or 0
            latest_month_members = sp.intake_total or 0

    # Stat card values for current/latest report period
    total_receipts_current = float(latest_report.total_receipts or 0) if latest_report else 0.0
    total_dues_current = float(latest_report.payable or 0) if latest_report else 0.0
    newcomers_count_current = latest_month_newcomers
    total_membership_count = latest_month_members

    error_msg = error or request.query_params.get("error", "")
    success_msg = msg or request.query_params.get("msg", "")

    user = db.query(User).filter(User.id == uid).first()

    return templates.TemplateResponse(
        request,
        "church-dashboard.html",
        {
            "church": church,
            "user": user,
            "sub": sub_status,
            "canCreateReport": can_create_report,
            "subAmount": sub_amount,
            "paymentSettings": pay_settings,
            "default_month": default_month,
            "default_year": default_year,
            "reports": reports,
            "latestReport": latest_report,
            "ytdReceipts": ytd_receipts,
            "latestMonthNewcomers": latest_month_newcomers,
            "latestMonthMembers": latest_month_members,
            "total_receipts_current": total_receipts_current,
            "total_dues_current": total_dues_current,
            "newcomers_count_current": newcomers_count_current,
            "total_membership_count": total_membership_count,
            "error": error_msg,
            "msg": success_msg,
        }
    )


# =========================================================================
# ZONAL DASHBOARD
# =========================================================================
@router.get("/zone-dashboard", response_class=HTMLResponse)
def get_zone_dashboard(
    request: Request,
    month: int = None,
    year: int = None,
    error: str = "",
    msg: str = "",
    db: Session = Depends(get_db)
):
    uid = ensure_role_session(request, "zonal_admin", db)
    zid = current_zone_id(request, db)
    zone = db.query(Zone).filter(Zone.id == zid).first() if zid else None
    if not zone:
        zone = db.query(Zone).filter(Zone.created_by == uid).first()
        if not zone:
            zone = db.query(Zone).first()
        if not zone:
            zone = Zone(zone_name="Central Zone", created_by=uid)
            db.add(zone)
            db.commit()
            db.refresh(zone)
        zid = zone.id
        request.session["zone_id"] = zid
    sub_status = getUserTrialAndSubStatus(db, uid)
    pay_settings = getPaymentSettings(db)
    sub_amount = float(pay_settings.get("monthly_sub_amount") or 5000)
    can_create_report = bool(sub_status.get("is_active", True)) or pay_settings.get("payment_enabled") != "1"

    now = datetime.now()
    selected_month = month or int(request.query_params.get("month", now.month))
    selected_year = year or int(request.query_params.get("year", now.year))


    # Fetch zonal churches - ensure at least default churches exist
    zone_churches = db.query(ZoneChurch).filter(ZoneChurch.zone_id == zid).order_by(ZoneChurch.display_order.asc()).all()
    if not zone_churches:
        churches_to_add = [
            ("ZONAL HQTS", 1),
            ("BRANCH 1", 2),
            ("BRANCH 2", 3),
            ("BRANCH 3", 4)
        ]
        for name, order in churches_to_add:
            db.add(ZoneChurch(zone_id=zid, church_name=name, display_order=order))
        db.commit()
        zone_churches = db.query(ZoneChurch).filter(ZoneChurch.zone_id == zid).order_by(ZoneChurch.display_order.asc()).all()
    church_count = len(zone_churches)
    
    # Fetch zonal reports history
    zonal_reports = db.query(ZonalReport).filter(ZonalReport.zone_id == zid).order_by(
        ZonalReport.report_year.desc(), ZonalReport.report_month.desc()
    ).all()
    latest_report = zonal_reports[0] if zonal_reports else None

    # Calculate status and totals for churches in this zone for selected month/year
    zone_newcomers_total = 0
    zone_avg_worship_attendance = 0
    zone_new_members_total = 0
    matching_submits_count = 0
    worship_attendance_sum = 0

    churches_status_list = []
    for zc in zone_churches:
        c_name = (zc.church_name or "").strip()
        
        # Match against registered Church accounts
        matched_church = db.query(Church).filter(func.lower(func.trim(Church.name)) == func.lower(c_name)).first()
        if not matched_church:
            matched_church = db.query(Church).filter(Church.name.ilike(f"%{c_name}%")).first()

        status = "No Report"
        if matched_church:
            fin = db.query(ChurchFinancialReport).filter_by(
                church_id=matched_church.id,
                report_month=selected_month,
                report_year=selected_year
            ).first()

            if fin:
                status = "Submitted" if fin.status == "submitted" else "Draft"
                if fin.status == "submitted":
                    matching_submits_count += 1
                    sp = db.query(ChurchSpiritualReport).filter_by(
                        church_id=matched_church.id,
                        report_month=selected_month,
                        report_year=selected_year
                    ).first()
                    if sp:
                        zone_newcomers_total += (sp.total_new_comers or 0)
                        zone_new_members_total += (sp.intake_total or 0)
                        
                        # Average worship attendance parsing
                        if sp.church_prog_data:
                            try:
                                prog_data = json.loads(sp.church_prog_data) if isinstance(sp.church_prog_data, str) else sp.church_prog_data
                                sun_worship = prog_data.get("avg_sun_worship", {})
                                val = sun_worship.get("total") or sun_worship.get("adults") or 0
                                worship_attendance_sum += int(val)
                            except Exception:
                                pass

        churches_status_list.append({
            "id": zc.id,
            "name": zc.church_name,
            "status": status
        })

    if matching_submits_count > 0:
        zone_avg_worship_attendance = round(worship_attendance_sum / matching_submits_count)

    error_msg = error or request.query_params.get("error", "")
    success_msg = msg or request.query_params.get("msg", "")
    user = db.query(User).filter(User.id == uid).first()

    return templates.TemplateResponse(
        request,
        "zone-dashboard.html",
        {
            "zone": zone,
            "user": user,
            "sub": sub_status,
            "canCreateReport": can_create_report,
            "subAmount": sub_amount,
            "paymentSettings": pay_settings,
            "selectedMonth": selected_month,
            "selectedYear": selected_year,
            "church_count": church_count,
            "zone_newcomers_total": zone_newcomers_total,
            "zone_avg_worship_attendance": zone_avg_worship_attendance,
            "zone_new_members_total": zone_new_members_total,
            "churchesStatusList": churches_status_list,
            "zonalReports": zonal_reports,
            "latestReport": latest_report,
            "error": error_msg,
            "msg": success_msg,
        }
    )


@router.post("/zone-dashboard", response_class=HTMLResponse)
async def post_zone_dashboard(
    request: Request,
    db: Session = Depends(get_db)
):
    if not is_logged_in(request) or current_role(request) != "zonal_admin":
        return RedirectResponse(url="/login", status_code=303)

    zid = current_zone_id(request, db)
    if not zid:
        return HTMLResponse("Error: Zone not found.", status_code=400)

    form_data = await request.form()
    action = form_data.get("action", "")

    msg = ""
    error = ""

    try:
        if form_data.get("new_zonal_report") == "1":
            new_month = int(form_data.get("report_month", datetime.now().month))
            new_year = int(form_data.get("report_year", datetime.now().year))
            return RedirectResponse(url=f"/zonal-reports?month={new_month}&year={new_year}", status_code=303)

        elif action == "add_church":
            church_name = form_data.get("church_name", "").strip()
            if church_name:
                max_order = db.query(func.max(ZoneChurch.display_order)).filter(ZoneChurch.zone_id == zid).scalar() or 0
                db.add(ZoneChurch(
                    zone_id=zid,
                    church_name=church_name,
                    display_order=max_order + 1
                ))
                db.commit()
                msg = f"Church '{church_name}' added to zone successfully!"

        elif action == "delete_church":
            c_id = int(form_data.get("church_row_id", 0))
            if c_id > 0:
                db.query(ZoneChurch).filter(ZoneChurch.id == c_id, ZoneChurch.zone_id == zid).delete()
                db.commit()
                msg = "Church removed from zone."

        elif action == "delete_zonal_report":
            z_id = int(form_data.get("zonal_report_id", 0))
            report_id = int(form_data.get("report_id", 0))
            if report_id:
                r = db.query(ZonalReport).filter(ZonalReport.id == report_id, ZonalReport.zone_id == zid).first()
                if r and r.status == "draft":
                    db.delete(r)
                    db.commit()
                    msg = "Draft report deleted successfully."
                else:
                    error = "Only draft reports can be deleted."

    except Exception as e:
        db.rollback()
        error = f"Error: {str(e)}"

    return RedirectResponse(url=f"/zone-dashboard?msg={quote(msg)}&error={quote(error)}", status_code=303)


# =========================================================================
# ADMIN DASHBOARD
# =========================================================================
@router.get("/admin-dashboard", response_class=HTMLResponse)
def get_admin_dashboard(
    request: Request,
    page: str = "dashboard",
    month: int = None,
    year: int = None,
    db: Session = Depends(get_db)
):
    if not is_logged_in(request) or current_role(request) != "super_admin":
        return RedirectResponse(url="/login", status_code=303)

    uid = current_user_id(request)
    admin_user = db.query(User).filter(User.id == uid).first()

    now = datetime.now()
    cur_month = month or int(request.query_params.get("month", now.month))
    cur_year = year or int(request.query_params.get("year", now.year))

    # Metric counts
    total_users_count = db.query(User).count()
    total_churches_count = db.query(Church).count()
    chartered_churches_count = db.query(Church).filter(Church.church_type == "chartered").count()
    unchartered_churches_count = db.query(Church).filter(Church.church_type == "unchartered").count()
    total_zones_count = db.query(Zone).count()

    # Reports submitted vs outstanding for selected month/year
    reports_submitted_count = db.query(func.count(func.distinct(ChurchFinancialReport.church_id))).filter(
        ChurchFinancialReport.report_month == cur_month,
        ChurchFinancialReport.report_year == cur_year,
        ChurchFinancialReport.status == "submitted"
    ).scalar() or 0
    reports_outstanding_count = max(0, total_churches_count - reports_submitted_count)

    # Data collections
    users = db.query(User).order_by(User.id.desc()).all()
    churches = db.query(Church).order_by(Church.id.desc()).all()
    zones = db.query(Zone).order_by(Zone.id.desc()).all()
    
    # Due percentage rates
    dues_chartered = db.query(DuePercentageSettings).filter_by(church_type="chartered").order_by(DuePercentageSettings.id.asc()).all()
    dues_unchartered = db.query(DuePercentageSettings).filter_by(church_type="unchartered").order_by(DuePercentageSettings.id.asc()).all()
    audit_logs = db.query(DuePercentageAuditLog).order_by(DuePercentageAuditLog.id.desc()).limit(20).all()

    # Videos & Chatbot FAQs
    hero_vids = db.query(HeroVideo).order_by(HeroVideo.id.desc()).all()
    showcase_vids = db.query(HeroShowcaseVideo).order_by(HeroShowcaseVideo.id.desc()).all()
    faqs = db.query(ChatbotKnowledgeBase).order_by(ChatbotKnowledgeBase.id.asc()).all()

    # User Payments
    from app.models import UserPayment
    payments = db.query(UserPayment).order_by(UserPayment.id.desc()).limit(100).all()

    # Pre-calculate subscription status for all users (fast & safe)
    user_subs = {}
    for u in users:
        try:
            user_subs[u.id] = getUserTrialAndSubStatus(db, u.id)
        except Exception:
            user_subs[u.id] = {
                'is_active': False, 'in_trial': False, 'trial_title': 'Annual Subscription Required',
                'trial_days_left': 0, 'status_label': 'Subscription required'
            }

    # Fetch site settings as a dict
    site_settings_rows = db.query(SiteSetting).all()
    site_settings = {s.setting_key: s.setting_value for s in site_settings_rows}

    success_msg = request.query_params.get("msg", "")
    error_msg = request.query_params.get("error", "")

    active_hero_video = db.query(HeroVideo).filter_by(is_active=True).order_by(HeroVideo.id.desc()).first()
    active_showcase_video = db.query(HeroShowcaseVideo).filter_by(is_active=True).order_by(HeroShowcaseVideo.id.desc()).first()

    return templates.TemplateResponse(
        request,
        "admin-dashboard.html",
        {
            "page": page,
            "admin_user": admin_user,
            "curMonth": cur_month,
            "curYear": cur_year,
            "total_users_count": total_users_count,
            "total_churches_count": total_churches_count,
            "chartered_churches_count": chartered_churches_count,
            "unchartered_churches_count": unchartered_churches_count,
            "total_zones_count": total_zones_count,
            "reports_submitted_count": reports_submitted_count,
            "reports_outstanding_count": reports_outstanding_count,
            "users": users,
            "user_subs": user_subs,
            "churches": churches,
            "zones": zones,
            "charteredSettings": dues_chartered,
            "uncharteredSettings": dues_unchartered,
            "audit_logs": audit_logs,
            "hero_vids": hero_vids,
            "showcase_vids": showcase_vids,
            "active_hero_video": active_hero_video,
            "active_showcase_video": active_showcase_video,
            "faqs": faqs,
            "payments": payments,
            "site_settings": site_settings,
            "msg": success_msg,
            "error": error_msg,
            "paymentSettings": getPaymentSettings(db)
        }
    )




@router.post("/admin-dashboard")
async def post_admin_dashboard(
    request: Request,
    db: Session = Depends(get_db)
):
    if not is_logged_in(request) or current_role(request) != "super_admin":
        return RedirectResponse(url="/login", status_code=303)

    uid = current_user_id(request)
    form_data = await request.form()
    action = form_data.get("action", "")
    redirect_page = form_data.get("page", "dashboard")

    msg = ""
    error = ""

    try:
        if form_data.get("update_rates") == "1" or action == "update_rates":
            redirect_page = "dues"
            all_settings = db.query(DuePercentageSettings).all()
            for setting in all_settings:
                sid = setting.id
                old_val = float(setting.percentage_value or 0)
                current_lock = int(setting.is_locked or 0)

                new_lock = 0 if form_data.get(f"locks[{sid}]") else 1
                lock_changed = (current_lock != new_lock)

                rate_str = form_data.get(f"rates[{sid}]")
                val_changed = False
                new_val = old_val
                if rate_str is not None:
                    try:
                        new_val = float(rate_str.replace(",", ""))
                        if abs(new_val - old_val) > 0.0001:
                            val_changed = True
                    except ValueError:
                        pass

                if val_changed or lock_changed:
                    setting.percentage_value = new_val if val_changed else old_val
                    setting.is_locked = new_lock
                    setting.updated_by = uid
                    setting.updated_at = datetime.utcnow()

                    if val_changed:
                        db.add(DuePercentageAuditLog(
                            due_setting_id=sid,
                            old_value=old_val,
                            new_value=new_val,
                            changed_by=uid,
                            action="rate_change"
                        ))
                    if lock_changed:
                        db.add(DuePercentageAuditLog(
                            due_setting_id=sid,
                            old_value=current_lock,
                            new_value=new_lock,
                            changed_by=uid,
                            action="lock_change",
                            note="Locked by admin" if new_lock == 1 else "Unlocked by admin"
                        ))

            db.commit()
            msg = "Percentages and settings updated successfully!"

            # Dispatch notification bell and email to Church & Zonal Admins
            try:
                from app.models import Notification, User
                from app.utils import sendAppEmail
                
                # In-app notification for all church and zonal admins
                db.add(Notification(
                    role_target="church_admin",
                    title="📊 Due Percentages Updated",
                    message="National Headquarters has updated due rates and calculation parameters. These updates apply to your new monthly reports.",
                    link="/church-dashboard",
                    category="info"
                ))
                db.add(Notification(
                    role_target="zonal_admin",
                    title="📊 Due Percentages Updated",
                    message="National Headquarters has updated due rates and calculation parameters.",
                    link="/zone-dashboard",
                    category="info"
                ))
                db.commit()

                # Dispatch email alerts to active Church and Zonal Admins
                all_admins = db.query(User).filter(User.role.in_(["church_admin", "zonal_admin"]), User.status == "active").all()
                for adm in all_admins:
                    if adm.email:
                        email_html = f"""
                        <p>Dear <strong>{adm.full_name}</strong>,</p>
                        <p>This is an official notice that the <strong>National Headquarters</strong> has updated the monthly due percentage rates and calculation settings on the Foursquare Gospel Church Reporting Portal.</p>
                        <div style="background:#FAF9FC;border-left:4px solid #E31E24;padding:14px 18px;border-radius:6px;margin:18px 0;">
                            <p style="margin:0;font-size:13.5px;color:#1A1040;font-weight:600;">
                                The revised percentages and lock settings have taken immediate effect on all new and draft monthly returns.
                            </p>
                        </div>
                        <p>Please log in to your dashboard to review your returns and submit your monthly report.</p>
                        """
                        sendAppEmail(
                            db=db,
                            to_email=adm.email,
                            to_name=adm.full_name,
                            subject="📊 Notice: Monthly Due Percentages Updated",
                            message_html=email_html,
                            action_url="/login",
                            action_text="Open Reporting Portal"
                        )
            except Exception:
                pass


        elif form_data.get("update_site_settings") == "1" or action == "update_site_settings":
            redirect_page = "settings"
            keys = [
                'site_name','site_tagline','hero_title','hero_lead',
                'strip_item_1','strip_item_2','strip_item_3','strip_item_4',
                'paths_title','paths_subtitle','footer_org_name',
                'contact_email','contact_phone','how_title','hero_video_url','showcase_video_url',
                'smtp_email','smtp_secret_key','smtp_sender_name','app_base_url',
                'google_client_id','google_client_secret',
                'payment_mode','payment_test_public_key','payment_test_secret_key',
                'payment_live_public_key','payment_live_secret_key',
                'payment_public_key','payment_secret_key','monthly_sub_amount','report_unlock_fee','free_trial_months','free_trial_days'
            ]
            for k in keys:
                if k in form_data:
                    val = str(form_data[k]).strip()
                    existing = db.query(SiteSetting).filter_by(setting_key=k).first()
                    if existing:
                        existing.setting_value = val
                        existing.updated_by = uid
                        existing.updated_at = datetime.utcnow()
                    else:
                        db.add(SiteSetting(setting_key=k, setting_value=val, updated_by=uid))

            # Checkboxes:
            for cb in ["smtp_enabled", "google_oauth_enabled", "payment_enabled", "free_trial_enabled"]:
                cb_val = "1" if form_data.get(cb) else "0"
                existing = db.query(SiteSetting).filter_by(setting_key=cb).first()
                if existing:
                    existing.setting_value = cb_val
                    existing.updated_by = uid
                else:
                    db.add(SiteSetting(setting_key=cb, setting_value=cb_val, updated_by=uid))


            # Video file uploads
            # NOTE: Render uses ephemeral storage — uploaded files are wiped on every deploy.
            # On Render, admins should use the "External Video URL" field instead.
            is_render = os.getenv("RENDER") == "true"
            upload_dir = "uploads/videos"
            os.makedirs(upload_dir, exist_ok=True)
            video_upload_warnings = []

            hero_file = form_data.get("hero_video")
            if hero_file and hasattr(hero_file, "filename") and hero_file.filename:
                ext = os.path.splitext(hero_file.filename)[1].lower()
                if ext in [".mp4", ".mov", ".webm", ".ogg"]:
                    if is_render:
                        video_upload_warnings.append(
                            "⚠️ Hero video was uploaded but Render's free plan has ephemeral storage — "
                            "the file will be lost on the next deploy. Please use the 'External Video URL' field instead."
                        )
                    else:
                        dest = f"{upload_dir}/hero_{int(datetime.utcnow().timestamp())}{ext}"
                        with open(dest, "wb") as f:
                            f.write(await hero_file.read())
                        db.query(HeroVideo).update({HeroVideo.is_active: False})
                        db.add(HeroVideo(video_path=dest, is_active=True))

            # Save external hero video URL to site_settings if provided
            hero_url = form_data.get("hero_video_url", "").strip() if hasattr(form_data, "get") else ""
            if hero_url:
                existing_hvurl = db.query(SiteSetting).filter_by(setting_key="hero_video_url").first()
                if existing_hvurl:
                    existing_hvurl.setting_value = hero_url
                    existing_hvurl.updated_by = uid
                else:
                    db.add(SiteSetting(setting_key="hero_video_url", setting_value=hero_url, updated_by=uid))

            showcase_file = form_data.get("showcase_video")
            if showcase_file and hasattr(showcase_file, "filename") and showcase_file.filename:
                ext = os.path.splitext(showcase_file.filename)[1].lower()
                if ext in [".mp4", ".mov", ".webm", ".ogg"]:
                    if is_render:
                        video_upload_warnings.append(
                            "⚠️ Showcase video was uploaded but Render's free plan has ephemeral storage — "
                            "the file will be lost on the next deploy. Please use the 'External Video URL' field instead."
                        )
                    else:
                        dest = f"{upload_dir}/showcase_{int(datetime.utcnow().timestamp())}{ext}"
                        with open(dest, "wb") as f:
                            f.write(await showcase_file.read())
                        db.query(HeroShowcaseVideo).update({HeroShowcaseVideo.is_active: False})
                        db.add(HeroShowcaseVideo(video_path=dest, is_active=True))

            # Save external showcase video URL to site_settings if provided

            showcase_url = form_data.get("showcase_video_url", "").strip() if hasattr(form_data, "get") else ""
            if showcase_url:
                existing_svurl = db.query(SiteSetting).filter_by(setting_key="showcase_video_url").first()
                if existing_svurl:
                    existing_svurl.setting_value = showcase_url
                    existing_svurl.updated_by = uid
                else:
                    db.add(SiteSetting(setting_key="showcase_video_url", setting_value=showcase_url, updated_by=uid))

            db.commit()
            msg = "Site settings updated successfully!"
            if video_upload_warnings:
                msg = " ".join(video_upload_warnings)

        elif action == "delete_hero_video":
            redirect_page = "settings"
            hero_vids = db.query(HeroVideo).all()
            for hv in hero_vids:
                if hv.video_path and os.path.exists(hv.video_path):
                    try:
                        os.remove(hv.video_path)
                    except Exception:
                        pass
                db.delete(hv)
            url_setting = db.query(SiteSetting).filter_by(setting_key="hero_video_url").first()
            if url_setting:
                url_setting.setting_value = ""
            db.commit()
            msg = "Hero background video removed successfully!"

        elif action == "delete_showcase_video":
            redirect_page = "settings"
            showcase_vids = db.query(HeroShowcaseVideo).all()
            for sv in showcase_vids:
                if sv.video_path and os.path.exists(sv.video_path):
                    try:
                        os.remove(sv.video_path)
                    except Exception:
                        pass
                db.delete(sv)
            url_setting = db.query(SiteSetting).filter_by(setting_key="showcase_video_url").first()
            if url_setting:
                url_setting.setting_value = ""
            db.commit()
            msg = "Showcase video removed successfully! Default Foursquare logo fallback is now active."

        elif action == "update_user_status":
            redirect_page = "users"
            target_uid = int(form_data.get("user_id", 0))
            new_status = form_data.get("status", "active")
            u = db.query(User).filter(User.id == target_uid).first()
            if u:
                u.status = new_status
                db.commit()
                msg = f"User '{u.full_name}' status updated to {new_status}."

        elif action == "toggle_user_instant_sub":
            redirect_page = "users"
            target_uid = int(form_data.get("user_id", 0))
            desired_state = form_data.get("state", "on")  # 'on' to activate, 'off' to revoke
            u = db.query(User).filter(User.id == target_uid).first()
            if u:
                from app.models import UserPayment
                now = datetime.utcnow()
                if desired_state == "on":

                    # Expire/clean old subs
                    db.query(UserPayment).filter(
                        UserPayment.user_id == target_uid,
                        UserPayment.payment_type == "subscription"
                    ).update({"status": "expired"})
                    # Grant new 1-year instant subscription
                    new_sub = UserPayment(
                        user_id=target_uid,
                        payment_type="subscription",
                        amount=0.00,
                        reference=f"ADMIN_GRANT_{target_uid}_{int(now.timestamp())}",
                        status="success",
                        expires_at=now + timedelta(days=365),
                        created_at=now
                    )
                    db.add(new_sub)
                    db.commit()
                    msg = f"Instant 1-Year Subscription ACTIVATED for '{u.full_name}'."
                else:
                    # Revoke subscription and mark expired
                    db.query(UserPayment).filter(
                        UserPayment.user_id == target_uid,
                        UserPayment.payment_type == "subscription"
                    ).update({"status": "expired", "expires_at": now})
                    
                    # Ensure an expired record exists so trial logic doesn't override manual OFF
                    has_sub = db.query(UserPayment).filter(
                        UserPayment.user_id == target_uid,
                        UserPayment.payment_type == "subscription"
                    ).first()
                    if not has_sub:
                        db.add(UserPayment(
                            user_id=target_uid,
                            payment_type="subscription",
                            amount=0.00,
                            reference=f"ADMIN_REVOKED_{target_uid}_{int(now.timestamp())}",
                            status="expired",
                            expires_at=now,
                            created_at=now
                        ))
                    db.commit()
                    msg = f"Subscription DEACTIVATED for '{u.full_name}'."


        elif action == "reset_user_password":
            redirect_page = "users"
            target_uid = int(form_data.get("user_id", 0))
            new_pass = form_data.get("new_password", "").strip()
            if target_uid and new_pass:
                from app.auth import get_password_hash
                u = db.query(User).filter(User.id == target_uid).first()
                if u:
                    u.password_hash = get_password_hash(new_pass)
                    db.commit()
                    msg = f"Password for '{u.full_name}' has been reset successfully."

        elif action == "delete_user":
            redirect_page = "users"
            target_uid = int(form_data.get("user_id", 0))
            if target_uid == uid:
                error = "You cannot delete your own logged-in Super Admin account."
            else:
                u = db.query(User).filter(User.id == target_uid).first()
                if u:
                    uname = u.full_name
                    # Reassign created_by on any churches and zones to the active super admin
                    db.query(Church).filter(Church.created_by == target_uid).update({"created_by": uid})
                    db.query(Zone).filter(Zone.created_by == target_uid).update({"created_by": uid})

                    # Clean up foreign key references
                    from app.models import Notification, PasswordResetToken, UserPayment, UserMessage
                    db.query(Notification).filter(Notification.user_id == target_uid).delete()
                    db.query(PasswordResetToken).filter(PasswordResetToken.user_id == target_uid).delete()
                    db.query(UserPayment).filter(UserPayment.user_id == target_uid).delete()
                    db.query(UserMessage).filter((UserMessage.sender_id == target_uid) | (UserMessage.receiver_id == target_uid)).delete()

                    db.delete(u)
                    db.commit()
                    msg = f"User account '{uname}' has been deleted successfully."

        elif action == "add_kb_entry":
            redirect_page = "chatbot"
            q = form_data.get("question", "").strip()
            a = form_data.get("answer", "").strip()
            kw = form_data.get("keywords", "").strip()
            if q and a:
                new_kb = ChatbotKnowledgeBase(question=q, answer=a, keywords=kw)
                db.add(new_kb)
                db.commit()
                msg = "New Chatbot Knowledge Base Q&A added successfully!"
            else:
                error = "Both Question and Answer are required."

        elif action == "edit_kb_entry":
            redirect_page = "chatbot"
            kb_id = int(form_data.get("kb_id", 0))
            q = form_data.get("question", "").strip()
            a = form_data.get("answer", "").strip()
            kw = form_data.get("keywords", "").strip()
            kb = db.query(ChatbotKnowledgeBase).filter(ChatbotKnowledgeBase.id == kb_id).first()
            if kb and q and a:
                kb.question = q
                kb.answer = a
                kb.keywords = kw
                db.commit()
                msg = "Knowledge Base entry updated successfully!"
            else:
                error = "Failed to update Knowledge Base entry. Ensure fields are not empty."

        elif action == "delete_kb_entry":
            redirect_page = "chatbot"
            kb_id = int(form_data.get("kb_id", 0))
            kb = db.query(ChatbotKnowledgeBase).filter(ChatbotKnowledgeBase.id == kb_id).first()
            if kb:
                db.delete(kb)
                db.commit()
                msg = "Knowledge Base entry deleted successfully!"

        elif form_data.get("send_unsubmitted_reminders") == "1":
            msg = "Reminder emails dispatched to all churches with pending reports."

        elif form_data.get("send_submitted_congratulations") == "1":
            msg = "Congratulations emails dispatched to all churches that completed submissions."

    except Exception as e:
        db.rollback()
        error = f"Error: {str(e)}"

    return RedirectResponse(
        url=f"/admin-dashboard?page={redirect_page}&msg={quote(msg)}&error={quote(error)}", 
        status_code=303
    )
