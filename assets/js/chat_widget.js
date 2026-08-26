"use strict";
/**
 * assets/ts/chat_widget.ts — Client-side live messaging and AI assistant chatbot controller.
 */
const fgcApiBase = window.FGC_API_BASE || 'chat-api';
let activeFgcPartnerId = 0;
let fgcPollingTimer = null;
let fgcHeartbeatTimer = null;
let fgcPartnerIsOnline = false;
// Helper to safely parse JSON response, ignoring ProFreeHost HTML bypass injections
function safeParseFgcJson(text) {
    if (!text || typeof text !== 'string')
        return null;
    // 1. Direct parse attempt
    try {
        const parsed = JSON.parse(text);
        if (parsed && typeof parsed === 'object')
            return parsed;
    }
    catch (e) { }
    // 2. Strip HTML challenge scripts
    const clean = text.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
        .replace(/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/gi, '')
        .replace(/<[^>]+>/g, '')
        .trim();
    try {
        const parsed = JSON.parse(clean);
        if (parsed && typeof parsed === 'object')
            return parsed;
    }
    catch (e) { }
    // 3. Locate first valid JSON boundaries
    const firstBrace = clean.indexOf('{');
    const lastBrace = clean.lastIndexOf('}');
    if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
        const extracted = clean.substring(firstBrace, lastBrace + 1);
        try {
            const parsed = JSON.parse(extracted);
            if (parsed && typeof parsed === 'object')
                return parsed;
        }
        catch (e) { }
    }
    return null;
}
function fgcFetch(url, options = {}) {
    if (!options.headers)
        options.headers = {};
    // Bypass free hosting challenges
    options.headers['X-Requested-With'] = 'XMLHttpRequest';
    options.credentials = 'same-origin';
    return fetch(url, options);
}
// Send heartbeat to mark user as online
function sendFgcHeartbeat() {
    fgcFetch(fgcApiBase + '?action=heartbeat').catch(() => { });
}
// Initialize online presence heartbeat if logged in
if (window.FGC_IS_LOGGED_IN) {
    sendFgcHeartbeat();
    fgcHeartbeatTimer = setInterval(sendFgcHeartbeat, 120000); // 2 minutes
}
function toggleFgcChatWidget() {
    const win = document.getElementById('fgcChatWindow');
    const openIcon = document.getElementById('chatIconOpen');
    const closeIcon = document.getElementById('chatIconClose');
    if (!win || !openIcon || !closeIcon)
        return;
    if (win.style.display === 'none' || win.style.display === '') {
        win.style.display = 'flex';
        openIcon.style.display = 'none';
        closeIcon.style.display = 'block';
        fetchFgcUnreadBadge();
    }
    else {
        win.style.display = 'none';
        openIcon.style.display = 'block';
        closeIcon.style.display = 'none';
        // Stop active thread polling when widget is closed
        if (fgcPollingTimer) {
            clearInterval(fgcPollingTimer);
            fgcPollingTimer = null;
        }
    }
}
window.toggleFgcChatWidget = toggleFgcChatWidget;
let fgcVoiceEnabled = localStorage.getItem('fgc_chat_voice_enabled') !== 'false';
let fgcSpeechRecognizer = null;
let fgcIsListening = false;
function toggleFgcVoice() {
    fgcVoiceEnabled = !fgcVoiceEnabled;
    localStorage.setItem('fgc_chat_voice_enabled', fgcVoiceEnabled ? 'true' : 'false');
    updateFgcVoiceUI();
    if (!fgcVoiceEnabled && 'speechSynthesis' in window) {
        window.speechSynthesis.cancel();
    }
}
window.toggleFgcVoice = toggleFgcVoice;
function updateFgcVoiceUI() {
    const icon = document.getElementById('fgcVoiceIcon');
    const label = document.getElementById('fgcVoiceLabel');
    const btn = document.getElementById('fgcVoiceToggleBtn');
    if (icon && label && btn) {
        if (fgcVoiceEnabled) {
            icon.textContent = '🔊';
            label.textContent = 'Voice ON';
            btn.style.background = 'rgba(16, 185, 129, 0.25)';
            btn.style.borderColor = 'rgba(16, 185, 129, 0.5)';
            btn.title = 'Voice reading enabled. Click to mute.';
        }
        else {
            icon.textContent = '🔇';
            label.textContent = 'Voice OFF';
            btn.style.background = 'rgba(255, 255, 255, 0.12)';
            btn.style.borderColor = 'rgba(255, 255, 255, 0.2)';
            btn.title = 'Voice reading muted. Click to enable.';
        }
    }
}
function speakFgcText(text) {
    if (!fgcVoiceEnabled || !('speechSynthesis' in window))
        return;
    try {
        window.speechSynthesis.cancel(); // cancel previous utterance
        // Strip markdown, asterisks, brackets, HTML tags
        const cleanText = text
            .replace(/<[^>]+>/g, '')
            .replace(/\*\*(.*?)\*\*/g, '$1')
            .replace(/\[(.*?)\]\(.*?\)/g, '$1')
            .replace(/[#*_~`]/g, '')
            .trim();
        if (!cleanText)
            return;
        const utterance = new SpeechSynthesisUtterance(cleanText);
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.lang = 'en-US';
        // Find best English voice
        const voices = window.speechSynthesis.getVoices();
        const preferredVoice = voices.find(v => (v.lang.startsWith('en') && (v.name.includes('Natural') || v.name.includes('Google') || v.name.includes('Samantha') || v.name.includes('Female'))));
        if (preferredVoice) {
            utterance.voice = preferredVoice;
        }
        window.speechSynthesis.speak(utterance);
    }
    catch (e) {
        console.warn('Speech synthesis error:', e);
    }
}
window.speakFgcText = speakFgcText;
function toggleFgcSpeechRecognition() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const micBtn = document.getElementById('fgcAiMicBtn');
    const input = document.getElementById('fgcAiInput');
    if (!SpeechRecognition) {
        alert('Voice speech recognition is not supported in this browser. Please use Chrome, Edge, or Safari.');
        return;
    }
    if (fgcIsListening && fgcSpeechRecognizer) {
        fgcSpeechRecognizer.stop();
        fgcIsListening = false;
        if (micBtn) {
            micBtn.style.background = '#F3F4F6';
            micBtn.style.color = '#374151';
            micBtn.style.borderColor = '#D1D5DB';
        }
        if (input)
            input.placeholder = 'Type or click mic to speak...';
        return;
    }
    try {
        fgcSpeechRecognizer = new SpeechRecognition();
        fgcSpeechRecognizer.lang = 'en-US';
        fgcSpeechRecognizer.interimResults = false;
        fgcSpeechRecognizer.maxAlternatives = 1;
        fgcSpeechRecognizer.onstart = () => {
            fgcIsListening = true;
            if (micBtn) {
                micBtn.style.background = '#EF4444';
                micBtn.style.color = '#FFFFFF';
                micBtn.style.borderColor = '#DC2626';
            }
            if (input)
                input.placeholder = '🎙️ Listening... speak now!';
        };
        fgcSpeechRecognizer.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            if (input && transcript) {
                input.value = transcript;
                submitAiChatQuery(new Event('submit'));
            }
        };
        fgcSpeechRecognizer.onerror = (event) => {
            console.warn('Speech recognition error:', event.error);
            fgcIsListening = false;
            if (micBtn) {
                micBtn.style.background = '#F3F4F6';
                micBtn.style.color = '#374151';
                micBtn.style.borderColor = '#D1D5DB';
            }
            if (input)
                input.placeholder = 'Type or click mic to speak...';
        };
        fgcSpeechRecognizer.onend = () => {
            fgcIsListening = false;
            if (micBtn) {
                micBtn.style.background = '#F3F4F6';
                micBtn.style.color = '#374151';
                micBtn.style.borderColor = '#D1D5DB';
            }
            if (input && !input.value)
                input.placeholder = 'Type or click mic to speak...';
        };
        fgcSpeechRecognizer.start();
    }
    catch (e) {
        console.error('Speech recognition start failed:', e);
    }
}
window.toggleFgcSpeechRecognition = toggleFgcSpeechRecognition;
// Initialize voice UI state
setTimeout(updateFgcVoiceUI, 300);
function switchFgcChatTab(tab) {
    const tabAi = document.getElementById('fgcTabAiContent');
    const tabLive = document.getElementById('fgcTabLiveContent');
    const btnAi = document.getElementById('tabBtnAi');
    const btnLive = document.getElementById('tabBtnLive');
    if (!tabAi || !tabLive || !btnAi || !btnLive)
        return;
    if (tab === 'ai') {
        tabAi.style.display = 'flex';
        tabLive.style.display = 'none';
        btnAi.style.background = '#fff';
        btnAi.style.color = '#1A1040';
        btnAi.style.borderBottom = '2px solid #E31E24';
        btnLive.style.background = 'transparent';
        btnLive.style.color = '#6B7280';
        btnLive.style.borderBottom = '2px solid transparent';
    }
    else {
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
window.switchFgcChatTab = switchFgcChatTab;
function submitAiChatQuery(e) {
    if (e && e.preventDefault)
        e.preventDefault();
    const input = document.getElementById('fgcAiInput');
    if (!input)
        return;
    const query = input.value.trim();
    if (!query)
        return;
    appendAiChatMessage('user', query);
    input.value = '';
    const params = new URLSearchParams();
    params.append('action', 'kb_query');
    params.append('query', query);
    const requestUrl = fgcApiBase + '?action=kb_query&query=' + encodeURIComponent(query);
    fgcFetch(requestUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
        .then(r => r.text())
        .then(text => {
        const data = safeParseFgcJson(text);
        if (data) {
            if (data.error) {
                appendAiChatMessage('bot', '⚠️ **Server Note:** ' + data.error);
            }
            else {
                appendAiChatMessage('bot', data.answer || 'Thank you for asking!');
            }
        }
        else {
            console.error('Raw non-JSON response from server:', text);
            appendAiChatMessage('bot', '👋 I am initializing connection to support server. Please ask your question again in a moment.');
        }
    })
        .catch((err) => {
        console.error('Chatbot API fetch error:', err);
        appendAiChatMessage('bot', '❌ **Connection Error:** Unable to reach support server.');
    });
}
window.submitAiChatQuery = submitAiChatQuery;
function sendQuickAiQuery(q) {
    const input = document.getElementById('fgcAiInput');
    if (input) {
        input.value = q;
        submitAiChatQuery(new Event('submit'));
    }
}
window.sendQuickAiQuery = sendQuickAiQuery;
function appendAiChatMessage(sender, text) {
    const container = document.getElementById('fgcAiMessages');
    if (!container)
        return;
    const msgDiv = document.createElement('div');
    if (sender === 'user') {
        msgDiv.style.cssText = 'align-self:flex-end; max-width:85%; background:#1A1040; color:#fff; border-radius:12px 12px 2px 12px; padding:10px 14px; font-size:13px; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.08);';
        const formattedText = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
        msgDiv.innerHTML = formattedText;
    }
    else {
        msgDiv.style.cssText = 'align-self:flex-start; max-width:85%; background:#FFFFFF; border:1px solid #E5E7EB; border-radius:12px 12px 12px 2px; padding:10px 14px; font-size:13px; color:#1F2937; line-height:1.45; box-shadow:0 1px 2px rgba(0,0,0,0.04); position:relative;';
        const formattedText = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
        // Escape for onclick attribute
        const escapedText = text.replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, ' ');
        const audioBtn = `<button type="button" onclick="speakFgcText('${escapedText}')" title="Replay voice audio" style="position:absolute; bottom:6px; right:8px; background:#F3F4F6; border:1px solid #E5E7EB; border-radius:6px; font-size:11px; padding:2px 6px; cursor:pointer; color:#4B5563;">🔊</button>`;
        msgDiv.innerHTML = formattedText + `<div style="margin-top:4px; height:18px;"></div>` + audioBtn;
        // Auto speak bot reply
        speakFgcText(text);
    }
    container.appendChild(msgDiv);
    container.scrollTop = container.scrollHeight;
}
function loadFgcUserList() {
    fgcFetch(fgcApiBase + '?action=fetch_users')
        .then(r => r.text())
        .then(text => {
        const container = document.getElementById('fgcUserListContainer');
        if (!container)
            return;
        const data = safeParseFgcJson(text);
        if (data && data.success && Array.isArray(data.users)) {
            const online = data.users.filter((u) => u.is_online);
            const offline = data.users.filter((u) => !u.is_online);
            if (data.users.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:24px 16px; font-size:13px; color:#9CA3AF;">No other users found in database.</div>';
                return;
            }
            let html = '';
            if (online.length > 0) {
                html += `<div style="font-size:10px; font-weight:700; color:#059669; text-transform:uppercase; letter-spacing:0.06em; padding:6px 4px 3px;">🟢 Online — ${online.length}</div>`;
                online.forEach((u) => { html += buildUserCard(u); });
            }
            if (offline.length > 0) {
                html += `<div style="font-size:10px; font-weight:700; color:#9CA3AF; text-transform:uppercase; letter-spacing:0.06em; padding:10px 4px 3px;">⚫ Offline — ${offline.length}</div>`;
                offline.forEach((u) => { html += buildUserCard(u); });
            }
            container.innerHTML = html;
        }
        else if (data && (data.error || data.message)) {
            const errDetail = data.error || data.message;
            container.innerHTML = `<div style="text-align:center; padding:20px 16px; font-size:12.5px; color:#DC2626; background:#FEF2F2; border-radius:8px; border:1px solid #FEE2E2;"><strong>Live Chat Note:</strong><br>${escapeHtml(errDetail)}</div>`;
        }
        else {
            container.innerHTML = `<div style="text-align:center; padding:20px 16px; font-size:12.5px; color:#4B5563; background:#F3F4F6; border-radius:8px;">Connecting to Live Chat server... Please wait a moment.</div>`;
        }
    })
        .catch((err) => {
        const container = document.getElementById('fgcUserListContainer');
        if (container) {
            container.innerHTML = `<div style="text-align:center; padding:20px 16px; font-size:12px; color:#DC2626; background:#FEF2F2; border-radius:8px; border:1px solid #FEE2E2;"><strong>Network Error:</strong><br>${escapeHtml(err.message || 'Unable to connect to server.')}</div>`;
        }
    });
}
function buildUserCard(u) {
    const badge = u.unread_count > 0 ? `<span style="background:#EF4444; color:#fff; font-size:11px; font-weight:700; border-radius:99px; min-width:20px; text-align:center; padding:2px 7px;">${u.unread_count}</span>` : '';
    const avatarHtml = u.has_photo ?
        `<div style="position:relative; flex-shrink:0;">
            <img src="${escapeHtml(u.profile_photo)}" style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid ${u.is_online ? '#22C55E' : '#D1D5DB'};">
            <span style="position:absolute; bottom:0; right:0; width:11px; height:11px; background:${u.is_online ? '#22C55E' : '#9CA3AF'}; border-radius:50%; border:2px solid #fff;"></span>
         </div>` :
        `<div style="position:relative; flex-shrink:0;">
            <div style="width:40px; height:40px; border-radius:50%; background:${u.is_online ? '#2E1B6A' : '#9CA3AF'}; color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; border:2px solid ${u.is_online ? '#A78BFA' : '#D1D5DB'};">${u.full_name.charAt(0).toUpperCase()}</div>
            <span style="position:absolute; bottom:0; right:0; width:11px; height:11px; background:${u.is_online ? '#22C55E' : '#9CA3AF'}; border-radius:50%; border:2px solid #fff;"></span>
         </div>`;
    const phoneHtml = u.phone ? `<div style="font-size:10.5px; color:#059669; font-weight:600; margin-top:1px;">📞 ${escapeHtml(u.phone)}</div>` : '';
    const statusLabel = u.is_online ? `<span style="font-size:10px; color:#059669; font-weight:700;">● Online</span>` : `<span style="font-size:10px; color:#9CA3AF;">● Offline</span>`;
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
    const nameEl = document.getElementById('threadPartnerName');
    if (nameEl)
        nameEl.innerText = name;
    // Role + phone + online indicator in thread header
    const roleText = role + (phone ? ' · 📞 ' + phone : '');
    const roleEl = document.getElementById('threadPartnerRole');
    if (roleEl)
        roleEl.innerText = roleText;
    // Online dot indicator next to name
    const dotEl = document.getElementById('threadOnlineDot');
    if (dotEl) {
        dotEl.style.background = isOnline ? '#22C55E' : '#9CA3AF';
        dotEl.title = isOnline ? 'Online' : 'Offline';
    }
    const avatarContainer = document.getElementById('threadAvatarContainer');
    if (avatarContainer) {
        if (hasPhoto && photo) {
            avatarContainer.innerHTML = `<img src="${escapeHtml(photo)}" style="width:100%; height:100%; object-fit:cover;">`;
        }
        else {
            avatarContainer.innerHTML = name.charAt(0).toUpperCase();
        }
    }
    const userListEl = document.getElementById('fgcLiveUserList');
    const liveThreadEl = document.getElementById('fgcLiveThread');
    if (userListEl)
        userListEl.style.display = 'none';
    if (liveThreadEl)
        liveThreadEl.style.display = 'flex';
    fetchFgcThreadMessages();
    if (fgcPollingTimer)
        clearInterval(fgcPollingTimer);
    fgcPollingTimer = setInterval(fetchFgcThreadMessages, 10000); // Poll thread every 10s
}
window.openFgcLiveThread = openFgcLiveThread;
function backToFgcUserList() {
    activeFgcPartnerId = 0;
    if (fgcPollingTimer) {
        clearInterval(fgcPollingTimer);
        fgcPollingTimer = null;
    }
    const liveThreadEl = document.getElementById('fgcLiveThread');
    const userListEl = document.getElementById('fgcLiveUserList');
    if (liveThreadEl)
        liveThreadEl.style.display = 'none';
    if (userListEl)
        userListEl.style.display = 'flex';
    loadFgcUserList();
}
window.backToFgcUserList = backToFgcUserList;
function fetchFgcThreadMessages() {
    if (!activeFgcPartnerId)
        return;
    fgcFetch(fgcApiBase + '?action=fetch_messages&partner_id=' + activeFgcPartnerId)
        .then(r => r.text())
        .then(text => {
        const data = safeParseFgcJson(text);
        if (data && data.success) {
            const container = document.getElementById('fgcLiveMessages');
            if (!container)
                return;
            const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 60;
            let html = '';
            let lastDate = '';
            data.messages.forEach((m) => {
                const isMine = m.is_mine;
                const bg = isMine ? '#DCF8C6' : '#FFFFFF';
                const align = isMine ? 'flex-end' : 'flex-start';
                const rad = isMine ? '10px 10px 0 10px' : '10px 10px 10px 0';
                // Date separator
                if (m.date_formatted !== lastDate) {
                    html += `<div style="align-self:center; background:rgba(0,0,0,0.12); color:#fff; font-size:10px; font-weight:700; padding:3px 10px; border-radius:99px; margin:6px 0;">${escapeHtml(m.date_formatted)}</div>`;
                    lastDate = m.date_formatted;
                }
                // Tick indicator: ✓ sent, ✓✓ read
                let tickHtml = '';
                if (isMine) {
                    if (m.is_read == 1) {
                        tickHtml = `<span style="color:#53BDEB; font-size:12px; margin-left:3px;">✓✓</span>`;
                    }
                    else {
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
            if (wasAtBottom)
                container.scrollTop = container.scrollHeight;
        }
    }).catch(() => { });
}
function submitLiveDirectMessage(e) {
    if (e && e.preventDefault)
        e.preventDefault();
    const input = document.getElementById('fgcLiveInput');
    if (!input || !activeFgcPartnerId)
        return;
    const text = input.value.trim();
    if (!text)
        return;
    input.value = '';
    const params = new URLSearchParams();
    params.append('action', 'send_message');
    params.append('receiver_id', activeFgcPartnerId.toString());
    params.append('message', text);
    fgcFetch(fgcApiBase + '?action=send_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
        .then(r => r.text())
        .then(text => {
        const data = safeParseFgcJson(text);
        if (data && data.success) {
            fetchFgcThreadMessages();
        }
    }).catch(() => { });
}
window.submitLiveDirectMessage = submitLiveDirectMessage;
function fetchFgcUnreadBadge() {
    fgcFetch(fgcApiBase + '?action=fetch_unread_count')
        .then(r => r.text())
        .then(text => {
        const data = safeParseFgcJson(text);
        if (data && data.success) {
            const badge = document.getElementById('chatUnreadBadge');
            const pill = document.getElementById('tabUnreadPill');
            if (!badge || !pill)
                return;
            if (data.unread_count > 0) {
                badge.innerText = data.unread_count;
                badge.style.display = 'block';
                pill.innerText = data.unread_count;
                pill.style.display = 'inline-block';
            }
            else {
                badge.style.display = 'none';
                pill.style.display = 'none';
            }
        }
    }).catch(() => { });
}
function escapeHtml(str) {
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
function escapeJsString(str) {
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}
// Initial unread badge check
fetchFgcUnreadBadge();
// Refresh unread badge every 60s
setInterval(fetchFgcUnreadBadge, 60000);
