<?php
// AI Assistant floating widget — included via admin-sidebar.php
// Provides streaming chat on every admin page without a full-page reload
$ai_widget_page = basename($_SERVER['PHP_SELF'] ?? 'Admin Panel');
$ai_widget_page_label = pathinfo($ai_widget_page, PATHINFO_FILENAME);
$ai_widget_page_label = ucwords(str_replace(['-','admin','_'], [' ','',''], $ai_widget_page_label));
$_aip_uid  = intval($_SESSION['user_id'] ?? 0);
$_aip_name = htmlspecialchars($_SESSION['user_name'] ?? 'Staff', ENT_QUOTES);
?>
<style>
/* ── AI Float Button ─── */
#ai-float-btn {
  position:fixed; bottom:24px; right:24px; z-index:9000;
  width:52px; height:52px; border-radius:50%;
  background:linear-gradient(135deg,#3b82f6,#8b5cf6);
  border:none; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 4px 20px rgba(59,130,246,.5);
  transition:transform .2s, box-shadow .2s;
  font-size:22px; color:#fff;
}
#ai-float-btn:hover { transform:scale(1.08); box-shadow:0 6px 26px rgba(59,130,246,.65); }
#ai-float-btn.active { transform:scale(0.95); }
.ai-float-badge {
  position:absolute; top:4px; right:4px;
  width:11px; height:11px; border-radius:50%;
  background:#10b981; border:2px solid #fff;
  display:none;
}
.ai-float-badge.visible { display:block; }

/* ── AI Panel ─── */
#ai-side-panel {
  position:fixed; right:0; top:0; bottom:0; z-index:8999;
  width:390px; max-width:100vw;
  background:#0f172a; display:flex; flex-direction:column;
  box-shadow:-4px 0 30px rgba(0,0,0,.4);
  transform:translateX(110%); transition:transform .3s cubic-bezier(.4,0,.2,1);
  font-family:Inter,system-ui,sans-serif;
}
#ai-side-panel.open { transform:translateX(0); }

/* Panel header */
.aip-hd {
  padding:14px 16px; background:#0d1b3e;
  border-bottom:1px solid #1e293b;
  display:flex; align-items:center; gap:10px; flex-shrink:0;
}
.aip-hd-icon {
  width:34px; height:34px; border-radius:9px; flex-shrink:0;
  background:linear-gradient(135deg,#3b82f6,#8b5cf6);
  display:flex; align-items:center; justify-content:center; font-size:16px;
}
.aip-hd-title { color:#f1f5f9; font-size:13.5px; font-weight:600; flex:1; }
.aip-hd-sub { color:#64748b; font-size:11px; }
.aip-hd-actions { display:flex; gap:6px; }
.aip-btn-icon {
  background:none; border:none; color:#64748b; cursor:pointer;
  width:28px; height:28px; border-radius:6px;
  display:flex; align-items:center; justify-content:center;
  font-size:13px; transition:all .15s;
}
.aip-btn-icon:hover { background:#1e293b; color:#94a3b8; }

/* Context bar */
.aip-ctx {
  padding:7px 14px; background:#1e293b;
  font-size:11px; color:#64748b;
  display:flex; align-items:center; gap:6px; flex-shrink:0;
  border-bottom:1px solid #0f172a;
}
.aip-ctx-page { color:#94a3b8; font-weight:500; }

/* Messages area */
.aip-msgs {
  flex:1; overflow-y:auto; padding:14px;
  display:flex; flex-direction:column; gap:10px;
}
.aip-msgs::-webkit-scrollbar { width:4px; }
.aip-msgs::-webkit-scrollbar-thumb { background:#1e293b; border-radius:2px; }

/* Welcome in panel */
.aip-welcome {
  text-align:center; padding:20px 10px; color:#475569;
  font-size:12.5px; line-height:1.7;
}
.aip-welcome strong { color:#94a3b8; display:block; margin-bottom:6px; font-size:13px; }

/* Quick prompts */
.aip-chips { display:flex; flex-wrap:wrap; gap:5px; justify-content:center; margin-top:12px; }
.aip-chip {
  background:#1e293b; border:1px solid #334155; border-radius:20px;
  padding:4px 11px; font-size:11.5px; color:#94a3b8;
  cursor:pointer; transition:all .15s;
}
.aip-chip:hover { background:#273549; color:#cbd5e1; border-color:#475569; }

/* Message bubbles */
.aip-msg { display:flex; gap:7px; }
.aip-msg.user { flex-direction:row-reverse; }
.aip-msg-av {
  width:26px; height:26px; border-radius:7px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:12px;
}
.aip-msg.user .aip-msg-av { background:#3b82f6; color:#fff; }
.aip-msg.ai .aip-msg-av { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; }
.aip-bub {
  background:#1e293b; border:1px solid #334155; border-radius:10px;
  padding:8px 12px; font-size:12.5px; line-height:1.6; color:#cbd5e1;
  max-width:280px; word-break:break-word;
}
.aip-msg.user .aip-bub { background:#1d4ed8; border-color:#2563eb; color:#fff; }
.aip-bub-err { background:#450a0a !important; border-color:#7f1d1d !important; color:#fca5a5 !important; }
.aip-bub pre { background:#0f172a; border:1px solid #334155; border-radius:6px; padding:7px; font-size:11px; overflow-x:auto; margin:5px 0; font-family:monospace; white-space:pre-wrap; }
.aip-bub code { background:#0f172a; padding:1px 4px; border-radius:3px; font-size:11px; font-family:monospace; }
.aip-bub strong { font-weight:600; color:#f1f5f9; }
.aip-bub ul, .aip-bub ol { margin:4px 0; padding-left:15px; }
.aip-bub li { margin-bottom:2px; }
.aip-tdot-wrap { display:flex; gap:4px; padding:5px 0; }
.aip-tdot { width:6px; height:6px; background:#475569; border-radius:50%; animation:aip-bounce 1.2s infinite; }
.aip-tdot:nth-child(2) { animation-delay:.2s; }
.aip-tdot:nth-child(3) { animation-delay:.4s; }
@keyframes aip-bounce { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-5px)} }

/* Input area */
.aip-input-wrap { padding:10px 12px; background:#0d1b3e; border-top:1px solid #1e293b; flex-shrink:0; }
.aip-input-row {
  display:flex; gap:7px; align-items:flex-end;
  background:#1e293b; border:1.5px solid #334155; border-radius:11px; padding:7px 9px;
  transition:border-color .15s;
}
.aip-input-row:focus-within { border-color:#3b82f6; }
#aip-input {
  flex:1; border:none; background:transparent; outline:none; resize:none;
  font-size:12.5px; color:#f1f5f9; font-family:inherit; min-height:20px; max-height:100px;
  line-height:1.5; caret-color:#3b82f6;
}
#aip-input::placeholder { color:#475569; }
.aip-send {
  background:#3b82f6; color:#fff; border:none; border-radius:7px;
  width:30px; height:30px; cursor:pointer; display:flex; align-items:center;
  justify-content:center; font-size:13px; transition:background .15s; flex-shrink:0;
}
.aip-send:hover { background:#2563eb; }
.aip-send:disabled { background:#334155; color:#64748b; cursor:not-allowed; }
.aip-footer {
  display:flex; align-items:center; justify-content:space-between;
  margin-top:7px; padding:0 2px;
}
.aip-footer-hint { font-size:10.5px; color:#475569; }
.aip-open-full {
  font-size:11px; color:#3b82f6; text-decoration:none;
  display:flex; align-items:center; gap:4px;
  transition:color .15s;
}
.aip-open-full:hover { color:#60a5fa; }

/* Backdrop (mobile) */
#ai-backdrop {
  display:none; position:fixed; inset:0; background:rgba(0,0,0,.4);
  z-index:8998; backdrop-filter:blur(1px);
}
#ai-backdrop.visible { display:block; }

@media (max-width:500px) {
  #ai-side-panel { width:100vw; }
  #ai-float-btn { bottom:16px; right:16px; }
}
</style>

<!-- Floating AI Button -->
<button id="ai-float-btn" onclick="aipToggle()" title="Ask AI Assistant" data-testid="button-ai-float">
  🤖
  <span class="ai-float-badge" id="ai-float-badge"></span>
</button>

<!-- Backdrop (closes panel on outside click) -->
<div id="ai-backdrop" onclick="aipClose()"></div>

<!-- Side Panel -->
<div id="ai-side-panel">
  <div class="aip-hd">
    <div class="aip-hd-icon">🤖</div>
    <div style="flex:1">
      <div class="aip-hd-title">AI Assistant</div>
      <div class="aip-hd-sub" id="aip-model-badge">Blue Mogul · Ollama</div>
    </div>
    <div class="aip-hd-actions">
      <button class="aip-btn-icon" onclick="aipNewChat()" title="New chat"><i class="fas fa-plus"></i></button>
      <button class="aip-btn-icon" onclick="aipClose()" title="Close"><i class="fas fa-times"></i></button>
    </div>
  </div>

  <div class="aip-ctx">
    <i class="fas fa-map-marker-alt" style="font-size:10px"></i>
    Context:
    <span class="aip-ctx-page"><?= htmlspecialchars($ai_widget_page_label) ?></span>
  </div>

  <div class="aip-msgs" id="aip-msgs">
    <div class="aip-welcome" id="aip-welcome">
      <strong>Hi! I'm your AI assistant.</strong>
      I'm here to help with anything on this page or across the portal. Ask me anything.
      <div class="aip-chips">
        <button class="aip-chip" onclick="aipChip(this)">Summarize my tasks</button>
        <button class="aip-chip" onclick="aipChip(this)">Draft a client reply</button>
        <button class="aip-chip" onclick="aipChip(this)">Explain this data</button>
        <button class="aip-chip" onclick="aipChip(this)">Write ticket response</button>
        <button class="aip-chip" onclick="aipChip(this)">MSP best practice?</button>
      </div>
    </div>
  </div>

  <div class="aip-input-wrap">
    <div class="aip-input-row">
      <textarea id="aip-input" rows="1" placeholder="Ask anything…"
        oninput="aipResize(this)" onkeydown="aipKey(event)"
        data-testid="input-ai-widget-message"></textarea>
      <button class="aip-send" id="aip-send" onclick="aipSend()" data-testid="button-ai-widget-send">
        <i class="fas fa-paper-plane"></i>
      </button>
    </div>
    <div class="aip-footer">
      <span class="aip-footer-hint">🔒 Runs locally · data stays on your server</span>
      <a href="admin-ai-assistant.php" class="aip-open-full" target="_self">
        Full chat <i class="fas fa-external-link-alt" style="font-size:9px"></i>
      </a>
    </div>
  </div>
</div>

<script>
(function() {
  let aipOpen = false;
  let aipMessages = [];
  let aipSettings = {};
  let aipAbort = null;
  const PAGE_CONTEXT = <?= json_encode($ai_widget_page_label) ?>;
  const STAFF_USER_ID   = <?= intval($_aip_uid) ?>;
  const STAFF_USER_NAME = <?= json_encode($_aip_name) ?>;

  // ── Boot ──────────────────────────────────────────────────────────────
  fetch('/api/ollama/settings').then(r=>r.json()).then(d=>{
    aipSettings = d;
    const badge = document.getElementById('aip-model-badge');
    if (badge) badge.textContent = (d.model || 'llama3') + ' · Ollama';
  }).catch(()=>{});

  fetch('/api/ollama/models').then(r=>{ if(r.ok) showBadge(); }).catch(()=>{});

  // ── Staff presence heartbeat — marks this admin as "online" ────────────
  function pingPresence() {
    if (!STAFF_USER_ID) return;
    fetch('/api/support/presence', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ user_id: STAFF_USER_ID, user_name: STAFF_USER_NAME }),
    }).catch(()=>{});
  }
  pingPresence(); // immediate on page load
  setInterval(pingPresence, 60000); // every 60 seconds

  function showBadge() {
    const b = document.getElementById('ai-float-badge');
    if (b) b.classList.add('visible');
  }

  // ── Panel open/close ───────────────────────────────────────────────────
  window.aipToggle = function() {
    aipOpen ? aipClose() : openPanel();
  };
  function openPanel() {
    aipOpen = true;
    document.getElementById('ai-side-panel').classList.add('open');
    document.getElementById('ai-backdrop').classList.add('visible');
    document.getElementById('ai-float-btn').classList.add('active');
    setTimeout(()=>document.getElementById('aip-input')?.focus(), 320);
  }
  window.aipClose = function() {
    aipOpen = false;
    document.getElementById('ai-side-panel').classList.remove('open');
    document.getElementById('ai-backdrop').classList.remove('visible');
    document.getElementById('ai-float-btn').classList.remove('active');
  };

  // ── New chat ───────────────────────────────────────────────────────────
  window.aipNewChat = function() {
    if (aipAbort) { aipAbort.abort(); aipAbort = null; }
    aipMessages = [];
    const c = document.getElementById('aip-msgs');
    c.innerHTML = `<div class="aip-welcome" id="aip-welcome">
      <strong>New conversation started.</strong>
      Ask me anything about ${aipEsc(PAGE_CONTEXT)} or the admin portal.
      <div class="aip-chips">
        <button class="aip-chip" onclick="aipChip(this)">Summarize my tasks</button>
        <button class="aip-chip" onclick="aipChip(this)">Draft a client reply</button>
        <button class="aip-chip" onclick="aipChip(this)">Explain this data</button>
        <button class="aip-chip" onclick="aipChip(this)">Write ticket response</button>
        <button class="aip-chip" onclick="aipChip(this)">MSP best practice?</button>
      </div></div>`;
    document.getElementById('aip-send').disabled = false;
    document.getElementById('aip-input').focus();
  };

  window.aipChip = function(btn) {
    document.getElementById('aip-input').value = btn.textContent;
    aipSend();
  };

  // ── Send message ───────────────────────────────────────────────────────
  window.aipSend = async function() {
    const inp = document.getElementById('aip-input');
    const text = inp.value.trim();
    if (!text) return;
    inp.value = ''; inp.style.height = 'auto';

    const welcome = document.getElementById('aip-welcome');
    if (welcome) welcome.remove();

    const c = document.getElementById('aip-msgs');

    // Context injection on first message
    const msgToSend = aipMessages.length === 0
      ? `[Context: I'm on the ${PAGE_CONTEXT} page of the Blue Mogul admin portal]\n\n${text}`
      : text;

    aipMessages.push({ role:'user', content:msgToSend });

    c.insertAdjacentHTML('beforeend',
      `<div class="aip-msg user">
        <div class="aip-msg-av"><i class="fas fa-user"></i></div>
        <div class="aip-bub">${aipEsc(text).replace(/\n/g,'<br>')}</div>
      </div>`);

    const tid = 'aip_' + Date.now();
    c.insertAdjacentHTML('beforeend',
      `<div class="aip-msg ai" id="${tid}">
        <div class="aip-msg-av"><i class="fas fa-robot"></i></div>
        <div class="aip-bub" id="${tid}_b">
          <div class="aip-tdot-wrap"><div class="aip-tdot"></div><div class="aip-tdot"></div><div class="aip-tdot"></div></div>
        </div>
      </div>`);
    aipScroll();

    const sb = document.getElementById('aip-send');
    sb.disabled = true;

    aipAbort = new AbortController();
    let fullReply = '';
    let firstToken = true;
    let lineBuffer = '';

    try {
      const resp = await fetch('/api/ollama/chat', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ messages: aipMessages.slice(-10), title: text.substring(0,60) }),
        signal: aipAbort.signal,
      });

      if (!resp.ok) {
        const errData = await resp.json().catch(()=>({error:'Server error'}));
        aipBubErr(tid, errData.error || 'Server error');
        return;
      }

      const reader = resp.body.getReader();
      const decoder = new TextDecoder();

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        lineBuffer += decoder.decode(value, { stream:true });
        const lines = lineBuffer.split('\n');
        lineBuffer = lines.pop() ?? '';

        for (const line of lines) {
          if (!line.startsWith('data: ')) continue;
          const raw = line.slice(6).trim();
          if (!raw) continue;
          let evt;
          try { evt = JSON.parse(raw); } catch { continue; }

          if (evt.error) { aipBubErr(tid, evt.error); return; }

          if (evt.token) {
            fullReply += evt.token;
            const bub = document.getElementById(tid+'_b');
            if (bub) {
              if (firstToken) { bub.innerHTML = ''; firstToken = false; }
              bub.innerHTML = aipFmt(fullReply);
            }
            aipScroll();
          }

          if (evt.done) {
            aipMessages.push({ role:'assistant', content:fullReply });
          }
        }
      }
    } catch(e) {
      if (e.name !== 'AbortError') aipBubErr(tid, 'Network error: ' + e.message);
    } finally {
      aipAbort = null;
      sb.disabled = false;
      inp.focus();
    }
  };

  function aipBubErr(tid, msg) {
    const bub = document.getElementById(tid+'_b');
    if (bub) { bub.className = 'aip-bub aip-bub-err'; bub.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + aipEsc(msg); }
    document.getElementById('aip-send').disabled = false;
  }

  function aipScroll() {
    const c = document.getElementById('aip-msgs');
    if (c) c.scrollTop = c.scrollHeight;
  }

  function aipEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function aipFmt(t) {
    return aipEsc(t)
      .replace(/```([\s\S]*?)```/g,'<pre>$1</pre>')
      .replace(/`([^`]+)`/g,'<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>')
      .replace(/\*([^*]+)\*/g,'<em>$1</em>')
      .replace(/^\- (.+)$/gm,'<li>$1</li>')
      .replace(/(<li>.*<\/li>(\n)?)+/g,'<ul>$&</ul>')
      .replace(/\n\n/g,'</p><p style="margin:5px 0">')
      .replace(/\n/g,'<br>');
  }

  window.aipKey = function(e) {
    if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); aipSend(); }
  };

  window.aipResize = function(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 100) + 'px';
  };

  // Close on Escape
  document.addEventListener('keydown', e => { if (e.key==='Escape' && aipOpen) aipClose(); });

  // Abort on page leave
  window.addEventListener('beforeunload', () => { if (aipAbort) aipAbort.abort(); });
})();
</script>
