import re
import hashlib
from datetime import datetime, timedelta
from fastapi import APIRouter, Request, Depends, HTTPException
from fastapi.responses import JSONResponse, HTMLResponse
from sqlalchemy.orm import Session
from sqlalchemy import or_, and_, func

from app.database import get_db
from app.models import User, UserMessage, ChatbotKnowledgeBase
from app.auth import is_logged_in, current_user_id

router = APIRouter()

from app.main import templates

# Stop words list for chatbot queries
STOP_WORDS = {
    'the','and','are','for','that','this','how','can','does',
    'did','what','when','who','why','will','you','your','with',
    'have','has','been','from','not','its','but','about','into','i'
}

# Synonyms dictionary for chatbot recall matching
SYNONYMS = {
    'report': ['create', 'submit', 'financial', 'spiritual', 'fill', 'entry', 'monthly', 'file', 'form', 'make', 'start', 'draft'],
    'dues': ['due', 'percentage', 'calc', 'calculate', 'national', 'district', 'regional', 'zonal', 'rate', 'tithes', 'offerings'],
    'unlock': ['edit', 'modify', 'change', 'submitted', 'locked', 'correction', 'resubmit'],
    'admin': ['contact', 'super', 'zonal', 'support', 'help', 'leader', 'pastor', 'user', 'superintendent'],
    'register': ['signup', 'new', 'church', 'zone', 'create', 'account', 'setup'],
    'spiritual': ['attendance', 'convert', 'baptism', 'dedication', 'wedding', 'funeral', 'membership', 'growth', 'cell', 'outreach'],
    'pdf': ['download', 'print', 'export', 'paper', 'copy'],
    'chartered': ['unchartered', 'type', 'status', 'established'],
}

@router.api_route("/chat-api", methods=["GET", "POST"])
async def chat_api(request: Request, db: Session = Depends(get_db)):
    # 1. Parse action
    action = request.query_params.get("action", "")
    form_data = {}
    if request.method == "POST":
        try:
            form_data = await request.form()
            action = action or form_data.get("action", "")
        except Exception:
            pass

    # 2. KB Query (No login required)
    if action == "kb_query":
        query_val = request.query_params.get("query", "") or form_data.get("query", "")
        query_val = str(query_val).strip()
        
        if not query_val:
            return JSONResponse({
                "success": False,
                "error": "Empty query string provided.",
                "answer": "Please enter a question to search the knowledge base."
            })

        raw_query = query_val.lower()

        # Check greetings (regular expressions matching PHP)
        greetings_regex = r"^(hi|hello|hey|greetings|wassup|what\'?s\s*up|yo|hiya|good\s*(morning|afternoon|evening)|help|support|sup|howdy|ola|hi\s*there)$"
        if re.match(greetings_regex, raw_query, re.IGNORECASE):
            return JSONResponse({
                "success": True,
                "matched_question": "Greeting",
                "answer": "Hello! 👋 How may I assist you today? You can ask me about creating monthly reports, calculating church dues, the difference between chartered and unchartered churches, downloading PDFs, how to register a church or zone, or anything else about using this portal."
            })

        try:
            # Fetch all KB rows
            all_kb = db.query(ChatbotKnowledgeBase).all()
            if not all_kb:
                return JSONResponse({
                    "success": True,
                    "matched_question": None,
                    "answer": "Hello! 👋 I am here to help. For immediate assistance, please switch to the **Live Chat** tab to message an Admin directly."
                })

            # Tokenize user query
            words = [w for w in re.split(r"[\s\.,;:?!\-\/\(\)]+", raw_query) if len(w) >= 2]
            content_words = [w for w in words if w not in STOP_WORDS]
            if not content_words:
                content_words = words

            # Expand with synonyms
            expanded_words = list(content_words)
            for cw in content_words:
                for key, syn_list in SYNONYMS.items():
                    if cw == key or cw in syn_list:
                        expanded_words.append(key)
                        expanded_words.extend(syn_list)
            
            expanded_words = list(set(expanded_words))

            best_match = None
            highest_score = 0

            # Match KB questions
            for kb in all_kb:
                score = 0
                q_text = kb.question.lower()
                a_text = kb.answer.lower()
                k_text = (kb.keywords or "").lower()
                k_list = [k.strip() for k in k_text.split(",") if k.strip()]

                # Phrase matches
                if raw_query in q_text:
                    score += 100
                if raw_query in a_text:
                    score += 40

                # Keyword score
                for word in expanded_words:
                    if re.search(r'\b' + re.escape(word) + r'\b', q_text):
                        score += 25
                    elif word in q_text:
                        score += 10

                    for kw in k_list:
                        if kw == word or kw in word or word in kw:
                            score += 20

                    if re.search(r'\b' + re.escape(word) + r'\b', a_text):
                        score += 5

                if score > highest_score:
                    highest_score = score
                    best_match = kb

            if best_match and highest_score > 0:
                return JSONResponse({
                    "success": True,
                    "matched_question": best_match.question,
                    "answer": best_match.answer
                })
            else:
                # Return standard formatted fallback matching PHP
                topics = [f"• {kb.question}" for kb in all_kb[:5]]
                topics_list = "\n".join(topics)
                fallback = f"I'm sorry, I don't have specific details on **\"{query_val}\"** right now.\n\nHere are some common topics I can help you with:\n\n{topics_list}\n\nFor direct assistance, feel free to switch to the **Live Chat** tab to message an Admin."
                return JSONResponse({
                    "success": True,
                    "matched_question": None,
                    "answer": fallback
                })
                
        except Exception as e:
            return JSONResponse({
                "success": False,
                "error": f"Database query exception: {str(e)}",
                "answer": f"Database Query Error: {str(e)}"
            })

    # 3. Logged-in check for live chat messaging
    if not is_logged_in(request):
        return JSONResponse({
            "success": False,
            "error": "Authentication Required: Session user_id is empty.",
            "message": "Please log in to your account to load Live Chat contacts."
        })

    uid = current_user_id(request)

    # 4. Update presence heartbeat
    try:
        user = db.query(User).filter(User.id == uid).first()
        if user:
            user.last_active = datetime.utcnow()
            db.commit()
    except Exception:
        db.rollback()

    if action == "heartbeat":
        import time
        return JSONResponse({"success": True, "ts": int(time.time())})

    # 5. Fetch Contacts List
    elif action == "fetch_users":
        try:
            # Check online limits (last active within 2 minutes)
            time_limit = datetime.utcnow() - timedelta(minutes=2)
            
            # Fetch users
            users = db.query(User).filter(User.id != uid).all()
            
            # Calculate unread counts
            user_list = []
            for u in users:
                is_online = u.last_active is not None and u.last_active >= time_limit
                unread_count = db.query(UserMessage).filter_by(
                    sender_id=u.id, receiver_id=uid, is_read=False
                ).count()
                
                # Check profile photo file exists
                has_photo = False
                if u.profile_photo:
                    has_photo = os.path.exists(u.profile_photo)
                
                user_list.append({
                    "id": u.id,
                    "full_name": u.full_name,
                    "email": u.email,
                    "phone": u.phone,
                    "profile_photo": u.profile_photo or "",
                    "is_online": is_online,
                    "unread_count": unread_count,
                    "has_photo": has_photo,
                    "role_label": u.role.replace("_", " ").title()
                })

            # Sort online first, then unread counts desc, then name asc
            user_list.sort(key=lambda x: (-int(x["is_online"]), -x["unread_count"], x["full_name"]))

            return JSONResponse({"success": True, "users": user_list})
        except Exception as e:
            return JSONResponse({"success": False, "message": str(e)})

    # 6. Send Direct Message
    elif action == "send_message":
        receiver_id = int(form_data.get("receiver_id", 0) or request.query_params.get("receiver_id", 0))
        msg_text = str(form_data.get("message", "") or request.query_params.get("message", "")).strip()

        if receiver_id <= 0 or not msg_text:
            return JSONResponse({"success": False, "message": "Recipient and message cannot be empty."})

        try:
            msg = UserMessage(
                sender_id=uid,
                receiver_id=receiver_id,
                message=msg_text,
                is_read=False,
                created_at=datetime.utcnow()
            )
            db.add(msg)
            db.commit()
            return JSONResponse({"success": True, "message_id": msg.id})
        except Exception as e:
            db.rollback()
            return JSONResponse({"success": False, "message": f"Failed to send message: {str(e)}"})

    # 7. Fetch Conversation History
    elif action == "fetch_messages":
        partner_id = int(request.query_params.get("partner_id", 0) or form_data.get("partner_id", 0))
        if partner_id <= 0:
            return JSONResponse({"success": True, "messages": []})

        try:
            # Mark messages from partner as read
            db.query(UserMessage).filter_by(
                sender_id=partner_id, receiver_id=uid, is_read=False
            ).update({"is_read": True})
            db.commit()

            # Query all messages
            msgs = db.query(UserMessage).filter(
                or_(
                    and_(UserMessage.sender_id == uid, UserMessage.receiver_id == partner_id),
                    and_(UserMessage.sender_id == partner_id, UserMessage.receiver_id == uid)
                )
            ).order_by(UserMessage.created_at.asc()).all()

            messages_list = []
            for m in msgs:
                # Format timestamp
                time_fmt = m.created_at.strftime('%I:%M %p')
                date_fmt = m.created_at.strftime('%b %d, %Y')
                
                messages_list.append({
                    "id": m.id,
                    "sender_id": m.sender_id,
                    "receiver_id": m.receiver_id,
                    "message": m.message,
                    "is_read": int(m.is_read),
                    "is_mine": m.sender_id == uid,
                    "time_formatted": time_fmt,
                    "date_formatted": date_fmt
                })

            return JSONResponse({"success": True, "messages": messages_list})
        except Exception as e:
            db.rollback()
            return JSONResponse({"success": False, "message": str(e)})

    # 8. Unread badge count
    elif action == "fetch_unread_count":
        try:
            unread_count = db.query(UserMessage).filter_by(receiver_id=uid, is_read=False).count()
            return JSONResponse({"success": True, "unread_count": unread_count})
        except Exception:
            return JSONResponse({"success": True, "unread_count": 0})

    return JSONResponse({"success": False, "message": "Invalid action."})


@router.get("/debug-chat", response_class=HTMLResponse)
def get_debug_chat(request: Request, db: Session = Depends(get_db)):
    if not is_logged_in(request):
        return RedirectResponse(url="/login", status_code=303)
    
    uid = current_user_id(request)
    user = db.query(User).filter(User.id == uid).first()
    
    # Fetch some history messages for debug
    recent = db.query(UserMessage).filter(
        or_(UserMessage.sender_id == uid, UserMessage.receiver_id == uid)
    ).order_by(UserMessage.created_at.desc()).limit(20).all()

    return templates.TemplateResponse(request, "debug_chat.html", {"user": user,
        "recent": recent})
