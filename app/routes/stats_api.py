# app/routes/stats_api.py
# Real-time stats JSON API - used by dashboard polling scripts.
import datetime
import json
from fastapi import APIRouter, Request, Depends
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session
from sqlalchemy import func
from app.database import get_db
from app.models import (
    User, Church, Zone, ZoneChurch,
    ChurchFinancialReport, ChurchSpiritualReport
)
from app.auth import (
    is_logged_in, current_role, current_user_id,
    current_church_id, current_zone_id
)

router = APIRouter(prefix="/api/stats")


def _json_error(msg, status=403):
    return JSONResponse({"error": msg}, status_code=status)


@router.get("/church")
def church_stats(request: Request, db: Session = Depends(get_db)):
    if not is_logged_in(request) or current_role(request) != "church_admin":
        return _json_error("Unauthorized")
    cid = current_church_id(request, db)
    if not cid:
        return _json_error("Church not found", 404)
    latest = (
        db.query(ChurchFinancialReport)
        .filter_by(church_id=cid)
        .order_by(ChurchFinancialReport.report_year.desc(),
                  ChurchFinancialReport.report_month.desc())
        .first()
    )
    total_receipts = float(latest.total_receipts or 0) if latest else 0.0
    total_dues = float(latest.payable or 0) if latest else 0.0
    latest_status = latest.status if latest else None
    latest_month = latest.report_month if latest else None
    latest_year = latest.report_year if latest else None
    newcomers = 0
    total_members = 0
    if latest:
        sp = db.query(ChurchSpiritualReport).filter_by(
            church_id=cid,
            report_month=latest.report_month,
            report_year=latest.report_year
        ).first()
        if sp:
            newcomers = sp.total_new_comers or 0
            total_members = sp.intake_total or 0
    return JSONResponse({
        "total_receipts": total_receipts,
        "total_dues": total_dues,
        "newcomers": newcomers,
        "total_members": total_members,
        "latest_status": latest_status,
        "latest_month": latest_month,
        "latest_year": latest_year,
    })


@router.get("/zone")
def zone_stats(request: Request, month: int = None, year: int = None, db: Session = Depends(get_db)):
    if not is_logged_in(request) or current_role(request) != "zonal_admin":
        return _json_error("Unauthorized")
    zid = current_zone_id(request, db)
    if not zid:
        return _json_error("Zone not found", 404)
    now = datetime.datetime.now()
    sel_month = month or now.month
    sel_year = year or now.year
    zone_churches = db.query(ZoneChurch).filter(ZoneChurch.zone_id == zid).all()
    church_count = len(zone_churches)
    zone_newcomers = 0
    zone_new_members = 0
    worship_sum = 0
    submitted_count = 0
    for zc in zone_churches:
        c_name = (zc.church_name or "").strip()
        matched = db.query(Church).filter(func.lower(func.trim(Church.name)) == func.lower(c_name)).first()
        if not matched:
            matched = db.query(Church).filter(Church.name.ilike("%" + c_name + "%")).first()
        if not matched:
            continue
        fin = db.query(ChurchFinancialReport).filter_by(
            church_id=matched.id, report_month=sel_month, report_year=sel_year, status="submitted"
        ).first()
        if fin:
            submitted_count += 1
            sp = db.query(ChurchSpiritualReport).filter_by(
                church_id=matched.id, report_month=sel_month, report_year=sel_year
            ).first()
            if sp:
                zone_newcomers += sp.total_new_comers or 0
                zone_new_members += sp.intake_total or 0
                if sp.church_prog_data:
                    try:
                        prog = json.loads(sp.church_prog_data) if isinstance(sp.church_prog_data, str) else sp.church_prog_data
                        sun = prog.get("avg_sun_worship", {})
                        worship_sum += int(sun.get("total") or sun.get("adults") or 0)
                    except Exception:
                        pass
    avg_worship = round(worship_sum / submitted_count) if submitted_count > 0 else 0
    return JSONResponse({
        "church_count": church_count,
        "submitted_count": submitted_count,
        "zone_newcomers": zone_newcomers,
        "zone_new_members": zone_new_members,
        "avg_worship_attendance": avg_worship,
        "selected_month": sel_month,
        "selected_year": sel_year,
    })


@router.get("/admin")
def admin_stats(request: Request, month: int = None, year: int = None, db: Session = Depends(get_db)):
    if not is_logged_in(request) or current_role(request) != "super_admin":
        return _json_error("Unauthorized")
    now = datetime.datetime.now()
    cur_month = month or now.month
    cur_year = year or now.year
    total_users = db.query(User).count()
    total_churches = db.query(Church).count()
    total_zones = db.query(Zone).count()
    submitted = (
        db.query(func.count(func.distinct(ChurchFinancialReport.church_id)))
        .filter(
            ChurchFinancialReport.report_month == cur_month,
            ChurchFinancialReport.report_year == cur_year,
            ChurchFinancialReport.status == "submitted"
        ).scalar() or 0
    )
    outstanding = max(0, total_churches - submitted)
    return JSONResponse({
        "total_users": total_users,
        "total_churches": total_churches,
        "total_zones": total_zones,
        "reports_submitted": submitted,
        "reports_outstanding": outstanding,
        "month": cur_month,
        "year": cur_year,
    })
