<?php
// Client-facing AI assistant widget with live chat escalation
// Included via client-sidebar.php — appears on every client portal page
$_cw_uid   = intval($_SESSION['user_id'] ?? 0);
$_cw_name  = htmlspecialchars($_SESSION['user_name'] ?? 'Client', ENT_QUOTES);
$_cw_email = htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES);
?>
<style>
/* ── Client AI Float Button ─────── */
#cw-float-btn {
  position:fixed; bottom:24px; right:24px; z-index:9000;
  width:56px; height:56px; border-radius:50%;
  background:linear-gradient(135deg,#0ea5e9,#2563eb);
  border:none; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 20px rgba(14,165,233,.45);
  transition:transform .2s, box-shadow .2s;
  font-size:24px; color:#fff;
}
#cw-float-btn:hover { transform:scale(1.08); box-shadow:0 6px 28px rgba(14,165,233,.65); }
#cw-float-btn.open  { transform:scale(0.94); }
.cw-pulse {
  position:absolute; top:3px; right:3px;
  width:13px; height:13px; border-radius:50%;
  background:#10b981; border:2.5px solid #fff;
  display:none;
}
.cw-pulse.show { display:block; }
.cw-badge-num {
  position:absolute; top:-4px; left:-4px;
  min-width:18px; height:18px; border-radius:9px;
  background:#ef4444; border:2px solid #fff;
  font-size:10px; font-weight:700; color:#fff;
  display:none; align-items:center; justify-content:center; padding:0 3px;
}

/* ── Side Panel ─────────────────── */
#cw-panel {
  position:fixed; right:0; top:0; bottom:0; z-index:8999;
  width:400px; max-width:100vw;
  background:#fff; display:flex; flex-direction:column;
  box-shadow:-6px 0 40px rgba(0,0,0,.12);
  transform:translateX(110%); transition:transform .3s cubic-bezier(.4,0,.2,1);
  font-family:Inter,system-ui,sans-serif;
}
#cw-panel.open { transform:translateX(0); }

/* Header */
.cw-hd {
  padding:14px 16px; background:linear-gradient(135deg,#0ea5e9,#2563eb);
  display:flex; align-items:center; gap:11px; flex-shrink:0;
}
.cw-hd-av {
  width:38px; height:38px; border-radius:11px;
  background:rgba(255,255,255,.2); border:2px solid rgba(255,255,255,.35);
  display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;
}
.cw-hd-title { color:#fff; font-size:14px; font-weight:700; }
.cw-hd-sub   { color:rgba(255,255,255,.75); font-size:11.5px; }
.cw-hd-close {
  margin-left:auto; background:rgba(255,255,255,.18); border:none; color:#fff;
  width:30px; height:30px; border-radius:8px; cursor:pointer;
  display:flex; align-items:center; justify-content:center; font-size:14px;
  transition:background .15s;
}
.cw-hd-close:hover { background:rgba(255,255,255,.3); }

/* Availability pill */
.cw-avail-bar {
  padding:8px 16px; border-bottom:1px solid #f1f5f9;
  display:flex; align-items:center; gap:7px; font-size:12px; flex-shrink:0;
  background:#f8fafc;
}
.cw-avail-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.cw-avail-dot.online  { background:#10b981; }
.cw-avail-dot.offline { background:#94a3b8; }
.cw-avail-dot.checking{ background:#f59e0b; animation:cw-pulse-anim 1s infinite; }
@keyframes cw-pulse-anim { 0%,100%{opacity:1}50%{opacity:.4} }
.cw-avail-text { color:#64748b; }

/* Messages */
.cw-msgs {
  flex:1; overflow-y:auto; padding:16px;
  display:flex; flex-direction:column; gap:12px;
}
.cw-msgs::-webkit-scrollbar { width:4px; }
.cw-msgs::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:2px; }

/* Welcome */
.cw-welcome { text-align:center; padding:20px 12px; }
.cw-welcome-icon {
  width:56px; height:56px; margin:0 auto 12px;
  background:linear-gradient(135deg,#0ea5e9,#2563eb);
  border-radius:16px; display:flex; align-items:center;
  justify-content:center; font-size:28px;
}
.cw-welcome h3 { font-size:15px; font-weight:700; color:#0f172a; margin:0 0 7px; }
.cw-welcome p  { font-size:12.5px; color:#64748b; line-height:1.65; margin:0 0 14px; }
.cw-chips { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; }
.cw-chip {
  background:#eff6ff; border:1px solid #bfdbfe; border-radius:20px;
  padding:4px 12px; font-size:12px; color:#1d4ed8; cursor:pointer;
  transition:all .15s;
}
.cw-chip:hover { background:#dbeafe; border-color:#93c5fd; }

/* Bubbles */
.cw-msg { display:flex; gap:8px; }
.cw-msg.user { flex-direction:row-reverse; }
.cw-msg-av {
  width:28px; height:28px; border-radius:8px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:13px;
}
.cw-msg.user .cw-msg-av { background:#2563eb; color:#fff; }
.cw-msg.ai   .cw-msg-av { background:linear-gradient(135deg,#0ea5e9,#2563eb); color:#fff; }
.cw-bub {
  background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;
  padding:9px 13px; font-size:13px; line-height:1.65; color:#1e293b;
  max-width:290px; word-break:break-word;
}
.cw-msg.user .cw-bub { background:#2563eb; border-color:#1d4ed8; color:#fff; }
.cw-bub strong { font-weight:600; }
.cw-bub pre  { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:7px; padding:8px; font-size:11.5px; overflow-x:auto; margin:6px 0; font-family:monospace; white-space:pre-wrap; }
.cw-bub code { background:#f1f5f9; padding:1px 4px; border-radius:3px; font-size:11.5px; font-family:monospace; }
.cw-bub ul, .cw-bub ol { margin:4px 0; padding-left:15px; }
.cw-bub li { margin-bottom:2px; }
.cw-bub-err { background:#fef2f2 !important; border-color:#fca5a5 !important; color:#991b1b !important; }

/* Typing dots */
.cw-tdot-wrap { display:flex; gap:4px; padding:4px 0; }
.cw-tdot { width:7px; height:7px; background:#94a3b8; border-radius:50%; animation:cw-bounce 1.2s infinite; }
.cw-tdot:nth-child(2) { animation-delay:.2s; }
.cw-tdot:nth-child(3) { animation-delay:.4s; }
@keyframes cw-bounce { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-6px)} }

/* Escalation strip — appears after each AI message */
.cw-esc-strip {
  margin-top:6px; padding:7px 10px;
  background:#f0f9ff; border:1px solid #bae6fd; border-radius:9px;
  display:flex; align-items:center; gap:8px; font-size:12px; color:#0369a1;
}
.cw-esc-strip button {
  margin-left:auto; background:#0ea5e9; color:#fff; border:none;
  padding:4px 11px; border-radius:6px; cursor:pointer; font-size:11.5px;
  font-weight:600; white-space:nowrap; transition:background .15s;
}
.cw-esc-strip button:hover { background:#0284c7; }

/* Escalation panel (availability result) */
.cw-esc-panel {
  margin:4px 0; padding:13px 14px;
  border-radius:12px; border:1px solid #e2e8f0; background:#fff;
  font-size:12.5px;
}
.cw-esc-panel.online  { border-color:#a7f3d0; background:#f0fdf4; }
.cw-esc-panel.offline { border-color:#e2e8f0; background:#f8fafc; }
.cw-esc-panel h4 { margin:0 0 6px; font-size:13.5px; font-weight:700; }
.cw-esc-panel p  { margin:0 0 10px; color:#64748b; font-size:12px; line-height:1.5; }
.cw-btn-livechat {
  display:flex; align-items:center; gap:7px;
  background:#059669; color:#fff; border:none; border-radius:9px;
  padding:9px 15px; font-size:13px; font-weight:600; cursor:pointer;
  text-decoration:none; transition:background .15s; width:100%;
  justify-content:center;
}
.cw-btn-livechat:hover { background:#047857; }

/* Ticket form */
.cw-ticket-form { display:flex; flex-direction:column; gap:9px; }
.cw-ticket-form label { font-size:11.5px; font-weight:600; color:#374151; display:block; margin-bottom:3px; }
.cw-ticket-form input, .cw-ticket-form textarea, .cw-ticket-form select {
  width:100%; padding:7px 10px; border:1.5px solid #d1d5db; border-radius:7px;
  font-size:12.5px; font-family:inherit; outline:none; box-sizing:border-box;
}
.cw-ticket-form input:focus, .cw-ticket-form textarea:focus, .cw-ticket-form select:focus {
  border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1);
}
.cw-ticket-form textarea { min-height:64px; resize:vertical; }
.cw-btn-ticket {
  background:#2563eb; color:#fff; border:none; border-radius:9px;
  padding:9px 15px; font-size:13px; font-weight:600; cursor:pointer;
  transition:background .15s; margin-top:2px;
}
.cw-btn-ticket:hover { background:#1d4ed8; }
.cw-btn-ticket:disabled { background:#94a3b8; cursor:not-allowed; }

/* Input bar */
.cw-input-wrap { padding:10px 13px; background:#fff; border-top:1px solid #f1f5f9; flex-shrink:0; }
.cw-input-row {
  display:flex; gap:7px; align-items:flex-end;
  background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; padding:8px 10px;
  transition:border-color .15s;
}
.cw-input-row:focus-within { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
#cw-input {
  flex:1; border:none; background:transparent; outline:none; resize:none;
  font-size:13px; color:#0f172a; font-family:inherit; min-height:20px; max-height:100px; line-height:1.5;
}
#cw-input::placeholder { color:#94a3b8; }
.cw-send {
  background:#2563eb; color:#fff; border:none; border-radius:8px;
  width:32px; height:32px; cursor:pointer; display:flex; align-items:center;
  justify-content:center; font-size:13px; transition:background .15s; flex-shrink:0;
}
.cw-send:hover { background:#1d4ed8; }
.cw-send:disabled { background:#cbd5e1; cursor:not-allowed; }
.cw-input-footer {
  display:flex; align-items:center; justify-content:space-between; margin-top:6px;
}
.cw-powered { font-size:10.5px; color:#94a3b8; }

/* Backdrop */
#cw-backdrop {
  display:none; position:fixed; inset:0; background:rgba(0,0,0,.25);
  z-index:8998; backdrop-filter:blur(1px);
}
#cw-backdrop.show { display:block; }

@media(max-width:480px) {
  #cw-panel { width:100vw; }
  #cw-float-btn { bottom:16px; right:16px; }
}
</style>

<!-- Float button -->
<button id="cw-float-btn" onclick="cwToggle()" title="Chat with Blue Mogul Support" data-testid="button-support-chat-float">
  💬
  <span class="cw-pulse" id="cw-pulse"></span>
</button>

<!-- Backdrop -->
<div id="cw-backdrop" onclick="cwClose()"></div>

<!-- Side panel -->
<div id="cw-panel">
  <div class="cw-hd">
    <div class="cw-hd-av">💬</div>
    <div>
      <div class="cw-hd-title">Blue Mogul Support</div>
      <div class="cw-hd-sub">AI-powered · escalate to live agent anytime</div>
    </div>
    <button class="cw-hd-close" onclick="cwClose()"><i class="fas fa-times"></i></button>
  </div>

  <div class="cw-avail-bar">
    <span class="cw-avail-dot checking" id="cw-avail-dot"></span>
    <span class="cw-avail-text" id="cw-avail-text">Checking agent availability…</span>
    <button onclick="cwCheckAvail()" style="margin-left:auto;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:11px;" title="Refresh"><i class="fas fa-sync-alt"></i></button>
  </div>

  <div class="cw-msgs" id="cw-msgs">
    <div class="cw-welcome" id="cw-welcome">
      <div class="cw-welcome-icon">💬</div>
      <h3>Hi, <?= htmlspecialchars($_cw_name, ENT_QUOTES) ?>!</h3>
      <p>Ask me anything — billing, services, technical questions, or anything about your account. I can also connect you to a live agent.</p>
      <div class="cw-chips">
        <button class="cw-chip" onclick="cwChip(this)">Check my invoice</button>
        <button class="cw-chip" onclick="cwChip(this)">My internet is down</button>
        <button class="cw-chip" onclick="cwChip(this)">Update my services</button>
        <button class="cw-chip" onclick="cwChip(this)">Billing question</button>
        <button class="cw-chip" onclick="cwChip(this)">Talk to a person</button>
      </div>
    </div>
  </div>

  <div class="cw-input-wrap">
    <div class="cw-input-row">
      <textarea id="cw-input" rows="1" placeholder="Type your question…"
        oninput="cwResize(this)" onkeydown="cwKey(event)"
        data-testid="input-support-chat-message"></textarea>
      <button class="cw-send" id="cw-send" onclick="cwSend()" data-testid="button-support-chat-send">
        <i class="fas fa-paper-plane"></i>
      </button>
    </div>
    <div class="cw-input-footer">
      <span class="cw-powered">🤖 AI · 🔒 Secure</span>
      <a href="client-chat.php" style="font-size:11px;color:#2563eb;text-decoration:none;" data-testid="link-go-live-chat">
        Live support <i class="fas fa-external-link-alt" style="font-size:9px"></i>
      </a>
    </div>
  </div>
</div>

<script>
(function() {
  const CW_USER_ID    = <?= intval($_cw_uid) ?>;
  const CW_USER_NAME  = <?= json_encode($_cw_name) ?>;
  const CW_USER_EMAIL = <?= json_encode($_cw_email) ?>;

  let cwOpen = false;
  let cwMessages = [];
  let cwAbort = null;
  let cwAvailData = null; // last availability result

  // ── Availability check ───────────────────────────────────────────────
  window.cwCheckAvail = async function() {
    const dot  = document.getElementById('cw-avail-dot');
    const text = document.getElementById('cw-avail-text');
    dot.className = 'cw-avail-dot checking'; text.textContent = 'Checking…';
    try {
      const r = await fetch('/api/support/availability');
      cwAvailData = await r.json();
      if (cwAvailData.available) {
        dot.className = 'cw-avail-dot online';
        const agents = cwAvailData.agents?.slice(0,2).join(', ') || 'Support';
        text.textContent = `🟢 ${agents} is online — live chat available`;
        document.getElementById('cw-pulse').classList.add('show');
      } else {
        dot.className = 'cw-avail-dot offline';
        text.textContent = '⚫ Support offline — leave us a ticket';
        document.getElementById('cw-pulse').classList.remove('show');
      }
    } catch {
      dot.className = 'cw-avail-dot offline';
      text.textContent = 'Unable to check availability';
    }
  };
  cwCheckAvail();
  setInterval(cwCheckAvail, 90000); // refresh every 90 seconds

  // ── Panel toggle ─────────────────────────────────────────────────────
  window.cwToggle = function() { cwOpen ? cwClose() : cwOpenPanel(); };
  function cwOpenPanel() {
    cwOpen = true;
    document.getElementById('cw-panel').classList.add('open');
    document.getElementById('cw-backdrop').classList.add('show');
    document.getElementById('cw-float-btn').classList.add('open');
    setTimeout(() => document.getElementById('cw-input')?.focus(), 320);
  }
  window.cwClose = function() {
    cwOpen = false;
    document.getElementById('cw-panel').classList.remove('open');
    document.getElementById('cw-backdrop').classList.remove('show');
    document.getElementById('cw-float-btn').classList.remove('open');
  };

  document.addEventListener('keydown', e => { if (e.key === 'Escape' && cwOpen) cwClose(); });
  window.addEventListener('beforeunload', () => { if (cwAbort) cwAbort.abort(); });

  // ── Quick suggestion chips ───────────────────────────────────────────
  window.cwChip = function(btn) {
    const text = btn.textContent.trim();
    if (text === 'Talk to a person') { cwEscalate(); return; }
    document.getElementById('cw-input').value = text;
    cwSend();
  };

  // ── Send message (AI streaming) ──────────────────────────────────────
  window.cwSend = async function() {
    const inp  = document.getElementById('cw-input');
    const text = inp.value.trim();
    if (!text) return;
    inp.value = ''; inp.style.height = 'auto';

    const welcome = document.getElementById('cw-welcome');
    if (welcome) welcome.remove();

    const c = document.getElementById('cw-msgs');

    // System context on first message
    const msgToSend = cwMessages.length === 0
      ? `[Client: ${CW_USER_NAME}] ${text}`
      : text;

    cwMessages.push({ role: 'user', content: msgToSend });

    // User bubble
    c.insertAdjacentHTML('beforeend', `
      <div class="cw-msg user">
        <div class="cw-msg-av"><i class="fas fa-user"></i></div>
        <div class="cw-bub">${cwEsc(text).replace(/\n/g,'<br>')}</div>
      </div>`);

    // AI typing bubble
    const tid = 'cw_' + Date.now();
    c.insertAdjacentHTML('beforeend', `
      <div class="cw-msg ai" id="${tid}">
        <div class="cw-msg-av"><i class="fas fa-robot"></i></div>
        <div>
          <div class="cw-bub" id="${tid}_b">
            <div class="cw-tdot-wrap"><div class="cw-tdot"></div><div class="cw-tdot"></div><div class="cw-tdot"></div></div>
          </div>
        </div>
      </div>`);
    cwScroll();

    const sb = document.getElementById('cw-send');
    sb.disabled = true;

    cwAbort = new AbortController();
    let fullReply = '';
    let firstToken = true;
    let lineBuffer = '';

    try {
      const resp = await fetch('/api/ollama/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          messages: cwMessages.slice(-8),
          title: text.substring(0, 60),
        }),
        signal: cwAbort.signal,
      });

      if (!resp.ok) {
        const err = await resp.json().catch(() => ({ error: 'Server error' }));
        cwBubErr(tid, err.error || 'Server error'); return;
      }

      const reader = resp.body.getReader();
      const decoder = new TextDecoder();

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        lineBuffer += decoder.decode(value, { stream: true });
        const lines = lineBuffer.split('\n');
        lineBuffer = lines.pop() ?? '';

        for (const line of lines) {
          if (!line.startsWith('data: ')) continue;
          const raw = line.slice(6).trim();
          if (!raw) continue;
          let evt;
          try { evt = JSON.parse(raw); } catch { continue; }

          if (evt.error) { cwBubErr(tid, evt.error); return; }

          if (evt.token) {
            fullReply += evt.token;
            const bub = document.getElementById(tid + '_b');
            if (bub) {
              if (firstToken) { bub.innerHTML = ''; firstToken = false; }
              bub.innerHTML = cwFmt(fullReply);
            }
            cwScroll();
          }

          if (evt.done) {
            cwMessages.push({ role: 'assistant', content: fullReply });
            // Show escalation strip after AI response
            const wrap = document.querySelector('#' + tid + ' > div');
            if (wrap) wrap.insertAdjacentHTML('beforeend', `
              <div class="cw-esc-strip">
                <span>Need more help?</span>
                <button onclick="cwEscalate(this)" data-context="${cwEsc(fullReply.substring(0,100))}">
                  Talk to a person
                </button>
              </div>`);
            cwScroll();
          }
        }
      }
    } catch(e) {
      if (e.name !== 'AbortError') cwBubErr(tid, 'Connection error: ' + e.message);
    } finally {
      cwAbort = null;
      sb.disabled = false;
      inp.focus();
    }
  };

  // ── Escalation flow ─────────────────────────────────────────────────
  window.cwEscalate = async function(btn) {
    if (btn) btn.disabled = true;

    const c = document.getElementById('cw-msgs');
    const escId = 'esc_' + Date.now();

    // Show loading placeholder
    c.insertAdjacentHTML('beforeend', `
      <div id="${escId}" class="cw-esc-panel" style="text-align:center;color:#64748b;font-size:12.5px;padding:16px;">
        <i class="fas fa-spinner fa-spin"></i> Checking agent availability…
      </div>`);
    cwScroll();

    await cwCheckAvail();
    const el = document.getElementById(escId);
    if (!el) return;

    if (cwAvailData?.available) {
      // ── Live agent available ──────────────────────────────────────────
      const agentName = cwAvailData.agents?.[0] || 'Support';
      el.className = 'cw-esc-panel online';
      el.innerHTML = `
        <h4 style="color:#065f46">🟢 Agent available!</h4>
        <p><strong>${cwEsc(agentName)}</strong> is online and ready to help you.</p>
        <a href="client-chat.php" class="cw-btn-livechat" data-testid="link-start-live-chat">
          <i class="fas fa-headset"></i> Start Live Chat
        </a>
        <button onclick="this.closest('.cw-esc-panel').remove()" style="width:100%;margin-top:8px;background:none;border:none;color:#94a3b8;font-size:11.5px;cursor:pointer;padding:4px;">
          Dismiss
        </button>`;
    } else {
      // ── Offline — show quick ticket form ─────────────────────────────
      const lastUserMsg = [...cwMessages].reverse().find(m => m.role === 'user')?.content || '';
      const transcript  = cwMessages.slice(-6).map(m =>
        (m.role === 'user' ? 'Client' : 'AI') + ': ' + m.content
      ).join('\n\n');
      const defaultSubject = lastUserMsg.replace(/^\[Client:[^\]]+\]\s*/, '').substring(0, 60);

      el.className = 'cw-esc-panel offline';
      el.innerHTML = `
        <h4 style="color:#0f172a">⚫ Support is offline</h4>
        <p>No agents are available right now. Submit a ticket and we'll get back to you shortly.</p>
        <div class="cw-ticket-form" id="${escId}_form">
          <div>
            <label>Subject</label>
            <input type="text" id="${escId}_subject" value="${cwEsc(defaultSubject)}" placeholder="What do you need help with?" maxlength="200">
          </div>
          <div>
            <label>Details</label>
            <textarea id="${escId}_desc" rows="3" placeholder="Describe your issue…">${cwEsc(transcript ? 'Chat summary:\n\n' + transcript : '')}</textarea>
          </div>
          <div>
            <label>Priority</label>
            <select id="${escId}_priority">
              <option value="low">Low — general question</option>
              <option value="medium" selected>Medium — needs attention</option>
              <option value="high">High — affecting my service</option>
              <option value="urgent">Urgent — critical outage</option>
            </select>
          </div>
          <button class="cw-btn-ticket" onclick="cwSubmitTicket('${escId}')" data-testid="button-submit-ticket-widget">
            <i class="fas fa-ticket-alt"></i> Submit Ticket
          </button>
          <button onclick="this.closest('.cw-esc-panel').remove()" style="background:none;border:none;color:#94a3b8;font-size:11.5px;cursor:pointer;padding:4px;width:100%;">
            Dismiss
          </button>
        </div>`;
    }
    cwScroll();
  };

  // ── Submit ticket from widget ────────────────────────────────────────
  window.cwSubmitTicket = async function(escId) {
    const subject  = document.getElementById(escId + '_subject')?.value.trim();
    const desc     = document.getElementById(escId + '_desc')?.value.trim();
    const priority = document.getElementById(escId + '_priority')?.value || 'medium';
    const btn      = document.querySelector(`#${escId} .cw-btn-ticket`);

    if (!subject) { alert('Please enter a subject.'); return; }
    if (btn) btn.disabled = true;

    try {
      const r = await fetch('/api/support/ticket', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: CW_USER_ID, subject, description: desc, priority }),
      });
      const d = await r.json();
      if (d.success) {
        const el = document.getElementById(escId);
        if (el) el.innerHTML = `
          <div style="text-align:center;padding:12px 0">
            <div style="font-size:28px;margin-bottom:8px">✅</div>
            <h4 style="color:#065f46;margin:0 0 5px">Ticket #${d.ticket_id} created!</h4>
            <p style="color:#64748b;font-size:12px;margin:0">We'll email you as soon as an agent responds.</p>
            <a href="tickets.php" style="display:inline-block;margin-top:10px;font-size:12px;color:#2563eb;">View my tickets →</a>
          </div>`;
      } else {
        alert('Failed to submit ticket: ' + (d.error || 'Unknown error'));
        if (btn) btn.disabled = false;
      }
    } catch(e) {
      alert('Network error: ' + e.message);
      if (btn) btn.disabled = false;
    }
  };

  // ── Helpers ──────────────────────────────────────────────────────────
  function cwBubErr(tid, msg) {
    const bub = document.getElementById(tid + '_b');
    if (bub) { bub.className = 'cw-bub cw-bub-err'; bub.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + cwEsc(msg); }
    document.getElementById('cw-send').disabled = false;
  }

  function cwScroll() {
    const c = document.getElementById('cw-msgs');
    if (c) c.scrollTop = c.scrollHeight;
  }

  function cwEsc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function cwFmt(t) {
    return cwEsc(t)
      .replace(/```([\s\S]*?)```/g,'<pre>$1</pre>')
      .replace(/`([^`]+)`/g,'<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>')
      .replace(/\*([^*]+)\*/g,'<em>$1</em>')
      .replace(/^\- (.+)$/gm,'<li>$1</li>')
      .replace(/(<li>.*<\/li>(\n)?)+/g,'<ul>$&</ul>')
      .replace(/\n\n/g,'</p><p style="margin:5px 0">')
      .replace(/\n/g,'<br>');
  }

  window.cwKey    = e => { if (e.key==='Enter'&&!e.shiftKey){e.preventDefault();cwSend();} };
  window.cwResize = el => { el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,100)+'px'; };
})();
</script>
