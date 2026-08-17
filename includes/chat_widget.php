<?php
/**
 * includes/chat_widget.php — Universal Floating AI Chatbot & Live WhatsApp Messaging Widget
 */
$isUserLoggedIn = isLoggedIn();
$currentUserId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$userName = $_SESSION['full_name'] ?? 'User';

// Relative path to chat_api.php — 100% host, protocol, and domain agnostic
$_chatApiUrl = 'chat_api.php';
?>

<style>
/* Chatbot Mobile Responsiveness (320px+) */
#fgcChatWindow {
  word-break: break-word;
  overflow-wrap: break-word;
}
@media (max-width: 560px) {
  #fgcChatBubble {
    bottom: 16px !important;
    right: 16px !important;
    width: 50px !important;
    height: 50px !important;
  }
  #fgcChatWindow {
    position: fixed !important;
    top: auto !important;
    left: auto !important;
    right: 16px !important;
    bottom: 76px !important;
    width: calc(100vw - 32px) !important;
    max-width: 380px !important;
    height: 500px !important;
    max-height: calc(100vh - 95px) !important;
    border-radius: 16px !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25) !important;
  }
  #fgcAiMessages, #fgcLiveMessages {
    flex: 1 !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch;
  }
  #fgcTabAiContent form, #fgcLiveThread form {
    position: sticky !important;
    bottom: 0 !important;
    background: #ffffff !important;
    z-index: 10 !important;
    flex-shrink: 0 !important;
  }
}
</style>

<!-- FLOATING CHAT TRIGGER BUTTON -->
<div id="fgcChatBubble" onclick="toggleFgcChatWidget()" title="Need help or Live Chat?" style="position:fixed; bottom:24px; right:24px; z-index:99999; cursor:pointer; background:linear-gradient(135deg, #1A1040 0%, #2E1B6A 100%); color:#fff; width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 8px 24px rgba(26,16,64,0.35); border:2px solid rgba(255,255,255,0.2); transition:transform 0.2s ease;">
  <svg id="chatIconOpen" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
  <svg id="chatIconClose" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  <span id="chatUnreadBadge" style="position:absolute; top:-2px; right:-2px; background:#EF4444; color:#fff; font-size:11px; font-weight:700; border-radius:99px; padding:2px 6px; border:2px solid #fff; display:none;">0</span>
</div>

<!-- CHAT WIDGET MODAL WINDOW -->
<div id="fgcChatWindow" style="position:fixed; bottom:90px; right:24px; z-index:99998; width:380px; max-width:calc(100vw - 32px); height:540px; max-height:calc(100vh - 120px); background:#FFFFFF; border-radius:16px; box-shadow:0 12px 36px rgba(0,0,0,0.25); border:1px solid #E5E7EB; display:none; flex-direction:column; overflow:hidden; font-family:'Inter', sans-serif;">

  <!-- WIDGET HEADER -->
  <div style="background:#1A1040; color:#fff; padding:14px 18px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
    <div style="display:flex; align-items:center; gap:10px;">
      <div style="width:32px; height:32px; border-radius:50%; background:#E31E24; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px; color:#fff;">F</div>
      <div>
        <h6 style="margin:0; font-size:14px; font-weight:700; color:#fff; font-family:'Outfit',sans-serif;">Foursquare Support &amp; Chat</h6>
        <span style="font-size:11px; opacity:0.75; display:block;">AI Assistant &amp; Live Messaging</span>
      </div>
    </div>
    <button type="button" onclick="toggleFgcChatWidget()" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer; opacity:0.75; line-height:1;">&times;</button>
  </div>

  <!-- WIDGET NAVIGATION TABS -->
  <div style="display:flex; background:#F3F4F6; border-bottom:1px solid #E5E7EB; flex-shrink:0;">
    <button type="button" id="tabBtnAi" onclick="switchFgcChatTab('ai')" style="flex:1; padding:10px; font-size:12.5px; font-weight:700; border:none; background:#fff; color:#1A1040; border-bottom:2px solid #E31E24; cursor:pointer;">🤖 AI Assistant</button>
    <button type="button" id="tabBtnLive" onclick="switchFgcChatTab('live')" style="flex:1; padding:10px; font-size:12.5px; font-weight:700; border:none; background:transparent; color:#6B7280; border-bottom:2px solid transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px;">
      💬 Live Chat <span id="tabUnreadPill" style="background:#EF4444; color:#fff; font-size:10px; padding:1px 5px; border-radius:99px; display:none;">0</span>
    </button>
  </div>

  <!-- TAB 1: AI ASSISTANT & KNOWLEDGE BASE -->
  <div id="fgcTabAiContent" style="display:flex; flex-direction:column; flex:1; min-height:0; background:#FAF9F6;">
    <!-- CHAT MESSAGES BODY -->
    <div id="fgcAiMessages" style="flex:1; padding:14px; overflow-y:auto; display:flex; flex-direction:column; gap:10px;">
      <div style="align-self:flex-start; max-width:85%; background:#FFFFFF; border:1px solid #E5E7EB; border-radius:12px 12px 12px 2px; padding:10px 14px; font-size:13px; color:#1F2937; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
        👋 Hello <?= h($userName) ?>! I am your <strong>Foursquare Monthly Reports AI Assistant</strong>. Ask me anything about how to create reports, calculate dues, pay subscriptions, or unlock submitted reports!
      </div>
      <!-- SUGGESTED CHIPS -->
      <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;">
        <button type="button" onclick="sendQuickAiQuery('How do I create a report?')" style="background:#EFF6FF; color:#2563EB; border:1px solid #BFDBFE; border-radius:99px; padding:4px 10px; font-size:11px; font-weight:600; cursor:pointer;">How do I create a report?</button>
        <button type="button" onclick="sendQuickAiQuery('How are dues calculated?')" style="background:#EFF6FF; color:#2563EB; border:1px solid #BFDBFE; border-radius:99px; padding:4px 10px; font-size:11px; font-weight:600; cursor:pointer;">How are dues calculated?</button>
        <button type="button" onclick="sendQuickAiQuery('Can I edit a submitted report?')" style="background:#EFF6FF; color:#2563EB; border:1px solid #BFDBFE; border-radius:99px; padding:4px 10px; font-size:11px; font-weight:600; cursor:pointer;">Edit submitted report?</button>
        <button type="button" onclick="sendQuickAiQuery('How does the free trial work?')" style="background:#EFF6FF; color:#2563EB; border:1px solid #BFDBFE; border-radius:99px; padding:4px 10px; font-size:11px; font-weight:600; cursor:pointer;">Do i need to pay monthly?</button>
      </div>
    </div>
    <!-- AI CHAT INPUT FORM -->
    <form onsubmit="submitAiChatQuery(event)" style="padding:10px 12px; background:#fff; border-top:1px solid #E5E7EB; display:flex; gap:8px; align-items:center; flex-shrink:0;">
      <input type="text" id="fgcAiInput" placeholder="Ask a question about the platform..." style="flex:1; padding:9px 12px; border:1.5px solid #D1D5DB; border-radius:8px; font-size:13px; outline:none; font-family:inherit;">
      <button type="submit" style="background:#1A1040; color:#fff; border:none; border-radius:8px; padding:9px 14px; font-size:13px; font-weight:600; cursor:pointer;">Send</button>
    </form>
  </div>

  <!-- TAB 2: LIVE WHATSAPP-STYLE DIRECT CHAT -->
  <div id="fgcTabLiveContent" style="display:none; flex-direction:column; flex:1; min-height:0; background:#E5DDD5;">
    <?php if ($isUserLoggedIn): ?>
      <!-- USER SELECTION SCREEN -->
      <div id="fgcLiveUserList" style="flex:1; overflow-y:auto; padding:10px; background:#fff; display:flex; flex-direction:column; gap:6px;">
        <div style="font-size:12px; font-weight:700; color:#6B7280; text-transform:uppercase; letter-spacing:0.04em; padding:4px 8px;">Chat with users live</div>
        <div id="fgcUserListContainer" style="display:flex; flex-direction:column; gap:4px;">
          <div style="text-align:center; padding:20px; font-size:13px; color:#9CA3AF;">Loading contacts...</div>
        </div>
      </div>

      <!-- ACTIVE THREAD SCREEN -->
      <div id="fgcLiveThread" style="display:none; flex-direction:column; flex:1; min-height:0;">
        <div style="background:#075E54; color:#fff; padding:8px 12px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
          <div style="display:flex; align-items:center; gap:8px; min-width:0;">
            <button type="button" onclick="backToFgcUserList()" style="background:none; border:none; color:#fff; font-size:16px; cursor:pointer; padding:0 4px;">&larr;</button>
            <div id="threadAvatarContainer" style="width:34px; height:34px; border-radius:50%; overflow:hidden; flex-shrink:0; background:#128C7E; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; font-size:13px;"></div>
            <div style="min-width:0;">
              <div style="display:flex; align-items:center; gap:5px;">
                <h7 id="threadPartnerName" style="margin:0; font-size:13px; font-weight:700; color:#fff; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">User</h7>
                <span id="threadOnlineDot" style="width:8px; height:8px; border-radius:50%; background:#9CA3AF; flex-shrink:0; display:inline-block;" title="Offline"></span>
              </div>
              <span id="threadPartnerRole" style="font-size:10px; opacity:0.85; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Online Support</span>
            </div>
          </div>
        </div>

        <div id="fgcLiveMessages" style="flex:1; padding:12px; overflow-y:auto; display:flex; flex-direction:column; gap:8px; background:#E5DDD5; background-image: repeating-linear-gradient(0deg,transparent,transparent 29px,rgba(0,0,0,0.03) 29px,rgba(0,0,0,0.03) 30px),repeating-linear-gradient(90deg,transparent,transparent 29px,rgba(0,0,0,0.03) 29px,rgba(0,0,0,0.03) 30px);">
          <!-- Live messages populate here dynamically -->
        </div>

        <form onsubmit="submitLiveDirectMessage(event)" style="padding:8px 10px; background:#F0F0F0; border-top:1px solid #DDD; display:flex; gap:8px; align-items:center; flex-shrink:0;">
          <input type="text" id="fgcLiveInput" placeholder="Type a message..." style="flex:1; padding:9px 12px; border:1px solid #CCC; border-radius:20px; font-size:13px; outline:none; background:#FFF; font-family:inherit;">
          <button type="submit" style="background:#128C7E; color:#fff; border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </form>
      </div>
    <?php else: ?>
      <div style="padding:30px 20px; text-align:center; background:#fff; flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.8" style="margin-bottom:12px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <h6 style="font-size:14px; font-weight:700; color:#111827; margin-bottom:6px;">Log in for Live Messaging</h6>
        <p style="font-size:12px; color:#6B7280; margin-bottom:16px;">Please log in to chat live with users, Zonal Superintendents, and other church leaders.</p>
        <a href="login.php" style="background:#1A1040; color:#fff; padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none;">Log in Now</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- CHATBOT & LIVE MESSAGING JAVASCRIPT LOGIC -->
<script>
const fgcApiBase = <?= json_encode($_chatApiUrl) ?>;
let activeFgcPartnerId = 0;
let fgcPollingTimer = null;
let fgcHeartbeatTimer = null;
let fgcPartnerIsOnline = false;

// ProFreeHost / ByetHost / InfinityFree Anti-Bot Bypass Helper Functions
function safeParseFgcJson(text) {
    if (!text || typeof text !== 'string') return null;
    
    // 1. Direct parse attempt
    try {
        let parsed = JSON.parse(text);
        if (parsed && typeof parsed === 'object') return parsed;
    } catch (e) {}

    // 2. Strip HTML <script> and <style> blocks (strips ProFreeHost aes.js challenge scripts)
    let clean = text.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
                    .replace(/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/gi, '')
                    .replace(/<[^>]+>/g, '')
                    .trim();

    try {
        let parsed = JSON.parse(clean);
        if (parsed && typeof parsed === 'object') return parsed;
    } catch (e) {}

    // 3. Locate first valid JSON object boundaries
    let firstBrace = clean.indexOf('{');
    let lastBrace = clean.lastIndexOf('}');
    if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
        let extracted = clean.substring(firstBrace, lastBrace + 1);
        try {
            let parsed = JSON.parse(extracted);
            if (parsed && typeof parsed === 'object') return parsed;
        } catch (e) {}
    }

    return null;
}

function fgcFetch(url, options = {}) {
    options.credentials = 'same-origin';
    if (!options.headers) options.headers = {};
    // ProFreeHost requires X-Requested-With header to bypass aes.js HTML injection on API endpoints
    options.headers['X-Requested-With'] = 'XMLHttpRequest';
    return fetch(url, options);
}

// Send heartbeat to mark user as online (relaxed to every 120s to prevent free host rate-limiting)
function sendFgcHeartbeat() {
    fgcFetch(fgcApiBase + '?action=heartbeat').catch(()=>{});
}
<?php if ($isUserLoggedIn): ?>
sendFgcHeartbeat();
fgcHeartbeatTimer = setInterval(sendFgcHeartbeat, 120000); // 2 minutes
<?php endif; ?>

function toggleFgcChatWidget() {
    let win = document.getElementById('fgcChatWindow');
    let openIcon = document.getElementById('chatIconOpen');
    let closeIcon = document.getElementById('chatIconClose');

    if (win.style.display === 'none' || win.style.display === '') {
        win.style.display = 'flex';
        openIcon.style.display = 'none';
        closeIcon.style.display = 'block';
        fetchFgcUnreadBadge();
    } else {
        win.style.display = 'none';
        openIcon.style.display = 'block';
        closeIcon.style.display = 'none';
        // Stop active thread polling when widget is closed to save host resources
        if (fgcPollingTimer) {
            clearInterval(fgcPollingTimer);
            fgcPollingTimer = null;
        }
    }
}

function switchFgcChatTab(tab) {
    let tabAi = document.getElementById('fgcTabAiContent');
    let tabLive = document.getElementById('fgcTabLiveContent');
    let btnAi = document.getElementById('tabBtnAi');
    let btnLive = document.getElementById('tabBtnLive');

    if (tab === 'ai') {
        tabAi.style.display = 'flex';
        tabLive.style.display = 'none';
        btnAi.style.background = '#fff';
        btnAi.style.color = '#1A1040';
        btnAi.style.borderBottom = '2px solid #E31E24';
        btnLive.style.background = 'transparent';
        btnLive.style.color = '#6B7280';
        btnLive.style.borderBottom = '2px solid transparent';
    } else {
        tabAi.style.display = 'none';
        tabLive.style.display = 'flex';
        btnLive.style.background = '#fff';
        btnLive.style.color = '#1A1040';
        btnLive.style.borderBottom = '2px solid #E31E24';
        btnAi.style.background = 'transparent';
        btnAi.style.color = '#6B7280';
        btnAi.style.borderBottom = '2px solid transparent';

        loadFgcUserList();
    }
}

function submitAiChatQuery(e) {
    if (e && e.preventDefault) e.preventDefault();
    let input = document.getElementById('fgcAiInput');
    let query = input.value.trim();
    if (!query) return;

    appendAiChatMessage('user', query);
    input.value = '';

    // Use URLSearchParams for application/x-www-form-urlencoded (100% free-host safe)
    let params = new URLSearchParams();
    params.append('action', 'kb_query');
    params.append('query', query);

    let requestUrl = fgcApiBase + '?action=kb_query&query=' + encodeURIComponent(query);

    fgcFetch(requestUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.text())
    .then(text => {
        let data = safeParseFgcJson(text);
        if (data) {
            if (data.error) {
                appendAiChatMessage('bot', '⚠️ **Server Note:** ' + data.error);
            } else {
                appendAiChatMessage('bot', data.answer || 'Thank you for asking!');
            }
        } else {
            console.error('Raw non-JSON response from server:', text);
            appendAiChatMessage('bot', '👋 I am initializing connection to support server. Please ask your question again in a moment.');
        }
    })
    .catch((err) => {
        console.error('Chatbot API fetch error:', err);
        appendAiChatMessage('bot', '❌ **Connection Error:** Unable to reach support server.');
    });
}

function sendQuickAiQuery(q) {
    document.getElementById('fgcAiInput').value = q;
    submitAiChatQuery(new Event('submit'));
}

function appendAiChatMessage(sender, text) {
    let container = document.getElementById('fgcAiMessages');
    let msgDiv = document.createElement('div');
    if (sender === 'user') {
        msgDiv.style.cssText = 'align-self:flex-end; max-width:85%; background:#1A1040; color:#fff; border-radius:12px 12px 2px 12px; padding:10px 14px; font-size:13px; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.08);';
    } else {
        msgDiv.style.cssText = 'align-self:flex-start; max-width:85%; background:#FFFFFF; border:1px solid #E5E7EB; border-radius:12px 12px 12px 2px; padding:10px 14px; font-size:13px; color:#1F2937; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.04);';
    }

    // Format markdown bold & linebreaks
    let formattedText = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
    msgDiv.innerHTML = formattedText;
    container.appendChild(msgDiv);
    container.scrollTop = container.scrollHeight;
}

// ─── LIVE MESSAGING LOGIC ───────────────────────────────────────────────────
function loadFgcUserList() {
    fgcFetch(fgcApiBase + '?action=fetch_users')
    .then(r => r.text())
    .then(text => {
        let container = document.getElementById('fgcUserListContainer');
        if (!container) return;

        let data = safeParseFgcJson(text);
        if (data && data.success && Array.isArray(data.users)) {
            const online = data.users.filter(u => u.is_online);
            const offline = data.users.filter(u => !u.is_online);

            if (data.users.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:24px 16px; font-size:13px; color:#9CA3AF;">No other users found in database.</div>';
                return;
            }

            let html = '';

            if (online.length > 0) {
                html += `<div style="font-size:10px; font-weight:700; color:#059669; text-transform:uppercase; letter-spacing:0.06em; padding:6px 4px 3px;">🟢 Online — ${online.length}</div>`;
                online.forEach(u => { html += buildUserCard(u); });
            }

            if (offline.length > 0) {
                html += `<div style="font-size:10px; font-weight:700; color:#9CA3AF; text-transform:uppercase; letter-spacing:0.06em; padding:10px 4px 3px;">⚫ Offline — ${offline.length}</div>`;
                offline.forEach(u => { html += buildUserCard(u); });
            }

            container.innerHTML = html;
        } else if (data && (data.error || data.message)) {
            let errDetail = data.error || data.message;
            container.innerHTML = `<div style="text-align:center; padding:20px 16px; font-size:12.5px; color:#DC2626; background:#FEF2F2; border-radius:8px; border:1px solid #FEE2E2;"><strong>Live Chat Note:</strong><br>${escapeHtml(errDetail)}</div>`;
        } else {
            container.innerHTML = `<div style="text-align:center; padding:20px 16px; font-size:12.5px; color:#4B5563; background:#F3F4F6; border-radius:8px;">Connecting to Live Chat server... Please wait a moment.</div>`;
        }
    })
    .catch((err) => {
        let container = document.getElementById('fgcUserListContainer');
        if (container) {
            container.innerHTML = `<div style="text-align:center; padding:20px 16px; font-size:12px; color:#DC2626; background:#FEF2F2; border-radius:8px; border:1px solid #FEE2E2;"><strong>Network Error:</strong><br>${escapeHtml(err.message || 'Unable to connect to server.')}</div>`;
        }
    });
}

function buildUserCard(u) {
    let badge = u.unread_count > 0 ? `<span style="background:#EF4444; color:#fff; font-size:11px; font-weight:700; border-radius:99px; min-width:20px; text-align:center; padding:2px 7px;">${u.unread_count}</span>` : '';
    let avatarHtml = u.has_photo ?
        `<div style="position:relative; flex-shrink:0;">
            <img src="${escapeHtml(u.profile_photo)}" style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid ${u.is_online ? '#22C55E' : '#D1D5DB'};">
            <span style="position:absolute; bottom:0; right:0; width:11px; height:11px; background:${u.is_online ? '#22C55E' : '#9CA3AF'}; border-radius:50%; border:2px solid #fff;"></span>
         </div>` :
        `<div style="position:relative; flex-shrink:0;">
            <div style="width:40px; height:40px; border-radius:50%; background:${u.is_online ? '#2E1B6A' : '#9CA3AF'}; color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; border:2px solid ${u.is_online ? '#A78BFA' : '#D1D5DB'};">${u.full_name.charAt(0).toUpperCase()}</div>
            <span style="position:absolute; bottom:0; right:0; width:11px; height:11px; background:${u.is_online ? '#22C55E' : '#9CA3AF'}; border-radius:50%; border:2px solid #fff;"></span>
         </div>`;
    let phoneHtml = u.phone ? `<div style="font-size:10.5px; color:#059669; font-weight:600; margin-top:1px;">📞 ${escapeHtml(u.phone)}</div>` : '';
    let statusLabel = u.is_online ? `<span style="font-size:10px; color:#059669; font-weight:700;">● Online</span>` : `<span style="font-size:10px; color:#9CA3AF;">● Offline</span>`;

    return `<div onclick="openFgcLiveThread(${u.id}, '${escapeJsString(u.full_name)}', '${escapeJsString(u.role_label)}', '${escapeJsString(u.phone || '')}', '${escapeJsString(u.profile_photo || '')}', ${u.has_photo}, ${u.is_online})" style="display:flex; align-items:center; justify-content:space-between; padding:10px 10px; border-radius:10px; background:#F9FAFB; border:1px solid #E5E7EB; cursor:pointer; transition:background 0.15s; gap:8px;" onmouseover="this.style.background='#F0FDF4'" onmouseout="this.style.background='#F9FAFB'">
        <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
            ${avatarHtml}
            <div style="min-width:0; flex:1;">
                <div style="font-size:13px; font-weight:700; color:${u.is_online ? '#111827' : '#6B7280'}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(u.full_name)}</div>
                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                    <span style="font-size:10.5px; color:#6B7280;">${escapeHtml(u.role_label)}</span>
                    ${statusLabel}
                </div>
                ${phoneHtml}
            </div>
        </div>
        ${badge}
    </div>`;
}


function openFgcLiveThread(partnerId, name, role, phone, photo, hasPhoto, isOnline) {
    activeFgcPartnerId = partnerId;
    fgcPartnerIsOnline = isOnline;
    document.getElementById('threadPartnerName').innerText = name;

    // Role + phone + online indicator in thread header
    let roleText = role + (phone ? ' · 📞 ' + phone : '');
    document.getElementById('threadPartnerRole').innerText = roleText;

    // Online dot indicator next to name
    let dotEl = document.getElementById('threadOnlineDot');
    if (dotEl) {
        dotEl.style.background = isOnline ? '#22C55E' : '#9CA3AF';
        dotEl.title = isOnline ? 'Online' : 'Offline';
    }

    let avatarContainer = document.getElementById('threadAvatarContainer');
    if (hasPhoto && photo) {
        avatarContainer.innerHTML = `<img src="${escapeHtml(photo)}" style="width:100%; height:100%; object-fit:cover;">`;
    } else {
        avatarContainer.innerHTML = name.charAt(0).toUpperCase();
    }

    document.getElementById('fgcLiveUserList').style.display = 'none';
    document.getElementById('fgcLiveThread').style.display = 'flex';

    fetchFgcThreadMessages();
    if (fgcPollingTimer) clearInterval(fgcPollingTimer);
    fgcPollingTimer = setInterval(fetchFgcThreadMessages, 10000); // Poll thread every 10s (free-host safe)
}

function backToFgcUserList() {
    activeFgcPartnerId = 0;
    if (fgcPollingTimer) {
        clearInterval(fgcPollingTimer);
        fgcPollingTimer = null;
    }
    document.getElementById('fgcLiveThread').style.display = 'none';
    document.getElementById('fgcLiveUserList').style.display = 'flex';
    loadFgcUserList();
}

function fetchFgcThreadMessages() {
    if (!activeFgcPartnerId) return;
    fgcFetch(fgcApiBase + '?action=fetch_messages&partner_id=' + activeFgcPartnerId)
    .then(r => r.text())
    .then(text => {
        let data = safeParseFgcJson(text);
        if (data && data.success) {
            let container = document.getElementById('fgcLiveMessages');
            let wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 60;
            let html = '';
            let lastDate = '';
            data.messages.forEach(m => {
                let isMine = m.is_mine;
                let bg = isMine ? '#DCF8C6' : '#FFFFFF';
                let align = isMine ? 'flex-end' : 'flex-start';
                let rad = isMine ? '10px 10px 0 10px' : '10px 10px 10px 0';

                // Date separator
                if (m.date_formatted !== lastDate) {
                    html += `<div style="align-self:center; background:rgba(0,0,0,0.12); color:#fff; font-size:10px; font-weight:700; padding:3px 10px; border-radius:99px; margin:6px 0;">${escapeHtml(m.date_formatted)}</div>`;
                    lastDate = m.date_formatted;
                }

                // Tick indicator: ✓ sent, ✓✓ grey=delivered, ✓✓ blue=read
                let tickHtml = '';
                if (isMine) {
                    if (m.is_read == 1) {
                        tickHtml = `<span style="color:#53BDEB; font-size:12px; margin-left:3px;">✓✓</span>`;
                    } else {
                        tickHtml = `<span style="color:#999; font-size:12px; margin-left:3px;">✓</span>`;
                    }
                }

                html += `
                <div style="align-self:${align}; max-width:82%; background:${bg}; border-radius:${rad}; padding:7px 12px 6px; font-size:13px; color:#111; box-shadow:0 1px 1px rgba(0,0,0,0.1); word-break:break-word;">
                    <div style="line-height:1.4;">${escapeHtml(m.message)}</div>
                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:2px; margin-top:3px;">
                        <span style="font-size:10px; color:#888;">${m.time_formatted}</span>
                        ${tickHtml}
                    </div>
                </div>`;
            });
            container.innerHTML = html;
            if (wasAtBottom) container.scrollTop = container.scrollHeight;
        }
    }).catch(() => {});
}

function submitLiveDirectMessage(e) {
    if (e && e.preventDefault) e.preventDefault();
    let input = document.getElementById('fgcLiveInput');
    let text = input.value.trim();
    if (!text || !activeFgcPartnerId) return;

    input.value = '';
    let params = new URLSearchParams();
    params.append('action', 'send_message');
    params.append('receiver_id', activeFgcPartnerId);
    params.append('message', text);

    fgcFetch(fgcApiBase + '?action=send_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.text())
    .then(text => {
        let data = safeParseFgcJson(text);
        if (data && data.success) {
            fetchFgcThreadMessages();
        }
    }).catch(() => {});
}

function fetchFgcUnreadBadge() {
    fgcFetch(fgcApiBase + '?action=fetch_unread_count')
    .then(r => r.text())
    .then(text => {
        let data = safeParseFgcJson(text);
        if (data && data.success) {
            let badge = document.getElementById('chatUnreadBadge');
            let pill = document.getElementById('tabUnreadPill');
            if (data.unread_count > 0) {
                badge.innerText = data.unread_count;
                badge.style.display = 'block';
                pill.innerText = data.unread_count;
                pill.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
                pill.style.display = 'none';
            }
        }
    }).catch(()=>{});
}

function escapeHtml(str) {
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
function escapeJsString(str) {
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

// Initial unread badge check on page load
fetchFgcUnreadBadge();
// Refresh unread badge gently every 60s (free-host friendly)
setInterval(fetchFgcUnreadBadge, 60000);
</script>
