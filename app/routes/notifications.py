from datetime import datetime
from fastapi import APIRouter, Request, Depends, HTTPException
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session
from sqlalchemy import or_, and_, desc

from app.database import get_db
from app.models import Notification, User
from app.auth import is_logged_in, current_user_id, current_role

router = APIRouter(prefix="/api/notifications", tags=["notifications"])

def format_time_ago(dt: datetime) -> str:
    if not dt:
        return "recently"
    now = datetime.utcnow()
    diff = now - dt
    secs = int(diff.total_seconds())
    if secs < 60:
        return "just now"
    mins = secs // 60
    if mins < 60:
        return f"{mins}m ago"
    hours = mins // 60
    if hours < 24:
        return f"{hours}h ago"
    days = hours // 24
    if days < 7:
        return f"{days}d ago"
    return dt.strftime("%b %d")

@router.get("")
def get_notifications(request: Request, db: Session = Depends(get_db)):
    if not is_logged_in(request):
        return JSONResponse({"success": True, "unread_count": 0, "notifications": []})

    uid = current_user_id(request)
    role = current_role(request)

    # Fetch notifications strictly tailored to this user or role broadcast
    query = db.query(Notification).filter(
        or_(
            Notification.user_id == uid,
            and_(
                Notification.user_id == None,
                Notification.role_target == role
            )
        )
    ).order_by(desc(Notification.created_at)).limit(25)

    items = query.all()

    # If no notifications exist yet, create a customized real-world welcome notification
    if not items and uid:
        if role == "church_admin":
            title = "⛪ Welcome to Church Reporting Portal"
            msg = "Your church account is ready. You can create monthly financial and spiritual reports, track national & district dues, and print official returns."
            link = "/church-dashboard"
        elif role == "zonal_admin":
            title = "🏛️ Welcome to Zonal Oversight Portal"
            msg = "Your zonal dashboard is ready. Monitor branch church returns, review consolidated remittances, and generate zonal monthly reports."
            link = "/zone-dashboard"
        else:
            title = "🛡️ Welcome Super Administrator"
            msg = "National control centre ready. Manage national due rates, view all church & zonal submissions, and oversee system configurations."
            link = "/admin-dashboard"

        welcome_notif = Notification(
            user_id=uid,
            role_target=role,
            title=title,
            message=msg,
            link=link,
            category="info",
            is_read=False
        )
        db.add(welcome_notif)
        db.commit()
        items = [welcome_notif]


    unread_count = sum(1 for n in items if not n.is_read)

    result = []
    for n in items:
        result.append({
            "id": n.id,
            "title": n.title,
            "message": n.message,
            "link": n.link or "#",
            "category": n.category or "info",
            "is_read": n.is_read,
            "created_at": n.created_at.strftime("%Y-%m-%d %H:%M:%S") if n.created_at else "",
            "time_ago": format_time_ago(n.created_at)
        })

    return JSONResponse({
        "success": True,
        "unread_count": unread_count,
        "notifications": result
    })

@router.post("/{notif_id}/read")
def mark_notification_read(notif_id: int, request: Request, db: Session = Depends(get_db)):
    if not is_logged_in(request):
        return JSONResponse({"success": False, "error": "Unauthorized"}, status_code=401)

    notif = db.query(Notification).filter(Notification.id == notif_id).first()
    if notif:
        db.delete(notif)
        db.commit()
        return JSONResponse({"success": True, "deleted": True})
    return JSONResponse({"success": False, "error": "Notification not found"}, status_code=404)

@router.post("/read-all")
def mark_all_notifications_read(request: Request, db: Session = Depends(get_db)):
    if not is_logged_in(request):
        return JSONResponse({"success": False, "error": "Unauthorized"}, status_code=401)

    uid = current_user_id(request)
    role = current_role(request)

    db.query(Notification).filter(
        or_(
            Notification.user_id == uid,
            and_(
                Notification.user_id == None,
                Notification.role_target == role
            )
        )
    ).delete(synchronize_session=False)
    db.commit()

    return JSONResponse({"success": True, "deleted_all": True})