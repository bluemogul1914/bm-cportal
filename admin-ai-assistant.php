<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Assistant – Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  body { font-family: Inter, system-ui, sans-serif; background: #f8fafc; }

  /* ── AI Layout ───────────────────── */
  .ai-outer { display:flex; height:calc(100vh - 0px); overflow:hidden; }
  .ai-panel  { display:flex; height:100vh; overflow:hidden; flex:1; }

  /* ── Conversation Sidebar ─────────── */
  .ai-sidebar {
    width:268px; min-width:240px; background:#0f172a; display:flex; flex-direction:column;
    border-right:1px solid #1e293b; flex-shrink:0;
  }
  .ai-sidebar-hd {
    padding:14px 16px; border-bottom:1px solid #1e293b; display:flex; align-items:center; gap:10px;
  }
  .ai-sidebar-hd h3 { margin:0; color:#f1f5f9; font-size:14px; font-weight:600; flex:1; }
  .btn-new {
    background:#3b82f6; color:#fff; border:none; border-radius:8px;
    padding:6px 12px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap;
    display:flex; align-items:center; gap:4px; transition:background .15s;
  }
  .btn-new:hover { background:#2563eb; }
  .convo-list { flex:1; overflow-y:auto; padding:8px 6px; }
  .convo-item {
    padding:9px 10px; border-radius:8px; cursor:pointer; margin-bottom:2px;
    display:flex; align-items:center; gap:8px; transition:background .1s; position:relative;
  }
  .convo-item:hover { background:#1e293b; }
  .convo-item.active { background:#1e3a5f; border-left:3px solid #3b82f6; padding-left:7px; }
  .convo-title {
    color:#cbd5e1; font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1;
  }
  .convo-meta { color:#475569; font-size:11px; }
  .convo-del {
    color:#475569; background:none; border:none; cursor:pointer; padding:2px 5px;
    border-radius:4px; font-size:13px; opacity:0; transition:opacity .1s; flex-shrink:0;
  }
  .convo-item:hover .convo-del { opacity:1; }
  .convo-del:hover { color:#ef4444; }
  .ai-sidebar-ft { padding:10px 14px; border-top:1px solid #1e293b; }
  .btn-settings-bar {
    width:100%; padding:8px 10px; border-radius:8px; border:1px solid #1e293b; background:transparent;
    color:#94a3b8; font-size:12px; cursor:pointer; display:flex; align-items:center;
    justify-content:center; gap:6px; transition:all .15s;
  }
  .btn-settings-bar:hover { background:#1e293b; color:#f1f5f9; }
  .status-dot { width:8px; height:8px; border-radius:50%; margin-left:auto; }
  .status-dot.online { background:#10b981; }
  .status-dot.offline { background:#ef4444; }
  .status-dot.checking { background:#f59e0b; animation:pulse 1s infinite; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
  .empty-convos { color:#64748b; font-size:12px; text-align:center; padding:24px 12px; }

  /* ── Chat Main ───────────────────── */
  .chat-main { flex:1; display:flex; flex-direction:column; overflow:hidden; }
  .chat-hd {
    padding:12px 20px; background:#fff; border-bottom:1px solid #e2e8f0;
    display:flex; align-items:center; gap:12px; flex-shrink:0;
  }
  .chat-hd-icon {
    width:36px; height:36px; background:linear-gradient(135deg,#3b82f6,#8b5cf6);
    border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:17px;
  }
  .chat-hd h4 { margin:0; font-size:15px; font-weight:600; color:#0f172a; }
  .chat-hd span { font-size:12px; color:#64748b; }
  .model-badge {
    margin-left:auto; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:20px;
    padding:3px 11px; font-size:11px; color:#475569; font-weight:500;
  }
  .chat-messages { flex:1; overflow-y:auto; padding:20px 24px; display:flex; flex-direction:column; gap:14px; }

  /* Welcome screen */
  .welcome-wrap { text-align:center; margin:auto; max-width:500px; padding:32px 20px; }
  .welcome-icon {
    width:64px; height:64px; background:linear-gradient(135deg,#3b82f6,#8b5cf6);
    border-radius:18px; display:flex; align-items:center; justify-content:center;
    margin:0 auto 18px; font-size:32px;
  }
  .welcome-wrap h3 { color:#0f172a; font-size:20px; margin:0 0 10px; }
  .welcome-wrap p { color:#64748b; font-size:13px; line-height:1.6; margin:0 0 22px; }
  .suggestions { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; }
  .suggestion {
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
    padding:7px 13px; font-size:12.5px; color:#3b82f6; cursor:pointer;
    transition:all .15s;
  }
  .suggestion:hover { background:#eff6ff; border-color:#bfdbfe; }

  /* Messages */
  .msg { display:flex; gap:10px; max-width:780px; }
  .msg.user { align-self:flex-end; flex-direction:row-reverse; max-width:680px; }
  .msg-av {
    width:32px; height:32px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:15px;
  }
  .msg.user .msg-av { background:#3b82f6; color:#fff; }
  .msg.assistant .msg-av { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; }
  .msg-bub {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:11px 15px; font-size:13.5px; line-height:1.65; color:#1e293b;
    box-shadow:0 1px 3px rgba(0,0,0,.04); max-width:100%;
  }
  .msg.user .msg-bub { background:#3b82f6; color:#fff; border-color:#2563eb; }
  .msg-bub pre {
    background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px;
    padding:10px; overflow-x:auto; font-size:12px; margin:8px 0; white-space:pre-wrap;
    font-family:monospace;
  }
  .msg.user .msg-bub pre { background:rgba(255,255,255,.18); border-color:rgba(255,255,255,.3); color:#fff; }
  .msg-bub code { background:#f1f5f9; padding:1px 4px; border-radius:4px; font-family:monospace; font-size:12px; }
  .msg.user .msg-bub code { background:rgba(255,255,255,.25); }
  .msg-bub strong { font-weight:600; }
  .msg-bub ul, .msg-bub ol { margin:5px 0; padding-left:18px; }
  .msg-bub li { margin-bottom:3px; }
  .msg-err { background:#fef2f2 !important; border-color:#fca5a5 !important; color:#991b1b !important; }
  .typing-ind { display:flex; align-items:center; gap:5px; padding:11px 15px; }
  .tdot {
    width:7px; height:7px; background:#94a3b8; border-radius:50%;
    animation:bounce 1.2s infinite;
  }
  .tdot:nth-child(2) { animation-delay:.2s; }
  .tdot:nth-child(3) { animation-delay:.4s; }
  @keyframes bounce { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-7px)} }

  /* Input */
  .chat-input-wrap { padding:14px 20px; background:#fff; border-top:1px solid #e2e8f0; flex-shrink:0; }
  .offline-bar {
    background:#fef2f2; border:1px solid #fca5a5; border-radius:8px;
    padding:10px 14px; font-size:12.5px; color:#991b1b; margin-bottom:10px;
    display:flex; align-items:center; gap:8px;
  }
  .offline-bar.hidden { display:none; }
  .input-row {
    display:flex; gap:8px; align-items:flex-end; background:#f8fafc;
    border:1.5px solid #e2e8f0; border-radius:13px; padding:9px 11px;
    transition:border-color .15s;
  }
  .input-row:focus-within { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
  #chatInput {
    flex:1; border:none; background:transparent; outline:none; resize:none;
    font-size:13.5px; color:#0f172a; font-family:inherit; min-height:22px; max-height:130px;
    line-height:1.5;
  }
  .btn-send {
    background:#3b82f6; color:#fff; border:none; border-radius:9px;
    width:36px; height:36px; cursor:pointer; display:flex; align-items:center;
    justify-content:center; font-size:16px; transition:background .15s; flex-shrink:0;
  }
  .btn-send:hover { background:#2563eb; }
  .btn-send:disabled { background:#94a3b8; cursor:not-allowed; }
  .input-hint { font-size:11px; color:#94a3b8; margin-top:7px; text-align:center; }

  /* Settings Modal */
  .modal-backdrop {
    position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000;
    display:flex; align-items:center; justify-content:center;
  }
  .modal-backdrop.hidden { display:none; }
  .modal-card {
    background:#fff; border-radius:16px; padding:26px; width:520px; max-width:95vw;
    max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25);
  }
  .modal-card h3 { margin:0 0 18px; color:#0f172a; font-size:17px; display:flex; align-items:center; gap:8px; }
  .fr { margin-bottom:15px; }
  .fr label { display:block; font-size:12.5px; font-weight:600; color:#374151; margin-bottom:5px; }
  .fr input, .fr select, .fr textarea {
    width:100%; padding:8px 11px; border:1.5px solid #d1d5db; border-radius:8px;
    font-size:13.5px; outline:none; font-family:inherit; box-sizing:border-box;
  }
  .fr input:focus, .fr select:focus, .fr textarea:focus {
    border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1);
  }
  .fr textarea { min-height:72px; resize:vertical; }
  .fr .hint { font-size:11.5px; color:#6b7280; margin-top:3px; }
  .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
  .btn-save {
    background:#3b82f6; color:#fff; border:none; padding:8px 18px; border-radius:8px;
    font-weight:600; cursor:pointer; font-size:13.5px; transition:background .15s;
  }
  .btn-save:hover { background:#2563eb; }
  .btn-cancel {
    background:#f3f4f6; color:#374151; border:1px solid #d1d5db; padding:8px 18px;
    border-radius:8px; font-weight:500; cursor:pointer; font-size:13.5px; transition:all .15s;
  }
  .btn-cancel:hover { background:#e5e7eb; }
  .model-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:7px; }
  .mchip {
    background:#eff6ff; border:1px solid #bfdbfe; border-radius:20px;
    padding:3px 11px; font-size:12px; color:#1d4ed8; cursor:pointer; transition:all .15s;
  }
  .mchip:hover { background:#dbeafe; }
  .mchip.sel { background:#3b82f6; color:#fff; border-color:#3b82f6; }
  .toggle-row { display:flex; align-items:center; gap:10px; margin-bottom:15px; }
  .tgl-lbl { font-size:12.5px; font-weight:600; color:#374151; }
  .tgl { position:relative; width:40px; height:22px; }
  .tgl input { opacity:0; width:0; height:0; }
  .tgl-sl {
    position:absolute; inset:0; background:#d1d5db; border-radius:22px; cursor:pointer; transition:.3s;
  }
  .tgl-sl:before {
    position:absolute; content:""; height:16px; width:16px; left:3px; bottom:3px;
    background:#fff; border-radius:50%; transition:.3s;
  }
  input:checked + .tgl-sl { background:#3b82f6; }
  input:checked + .tgl-sl:before { transform:translateX(18px); }
  .notice {
    position:fixed; top:20px; right:20px; z-index:9999; padding:11px 17px; border-radius:10px;
    font-size:13.5px; font-weight:500; box-shadow:0 4px 12px rgba(0,0,0,.15); transition:opacity .3s;
    pointer-events:none;
  }
</style>
</head>
<body class="flex h-screen overflow-hidden bg-gray-50">

<?php require_once 'includes/admin-sidebar.php'; ?>

<!-- AI Panel -->
<div class="ai-panel">

  <!-- Conversation Sidebar -->
  <div class="ai-sidebar">
    <div class="ai-sidebar-hd">
      <h3>&#129302; Chats</h3>
      <button class="btn-new" onclick="newChat()"><i class="fas fa-plus"></i> New</button>
    </div>
    <div class="convo-list" id="convoList">
      <div class="empty-convos">No conversations yet.<br>Start a new chat!</div>
    </div>
    <div class="ai-sidebar-ft">
      <button class="btn-settings-bar" onclick="openSettings()">
        <i class="fas fa-cog"></i> Settings
        <span class="status-dot checking" id="statusDot" title="Checking Ollama…"></span>
      </button>
    </div>
  </div>

  <!-- Chat Main -->
  <div class="chat-main">
    <div class="chat-hd">
      <div class="chat-hd-icon">&#129302;</div>
      <div>
        <h4 id="chatTitle">AI Assistant</h4>
        <span id="chatSubtitle">Powered by Ollama &mdash; runs locally, stays private</span>
      </div>
      <div class="model-badge" id="modelBadge">llama3</div>
    </div>

    <div class="chat-messages" id="messages">
      <div class="welcome-wrap" id="welcomeScreen">
        <div class="welcome-icon">&#129302;</div>
        <h3>Blue Mogul AI Assistant</h3>
        <p>Ask me anything about clients, tickets, invoices, or MSP operations.<br>Runs locally via Ollama &mdash; your data never leaves your server.</p>
        <div class="suggestions">
          <button class="suggestion" onclick="useSuggestion(this)">Summarize open tickets</button>
          <button class="suggestion" onclick="useSuggestion(this)">How do I onboard a new client?</button>
          <button class="suggestion" onclick="useSuggestion(this)">Draft an overdue invoice follow-up</button>
          <button class="suggestion" onclick="useSuggestion(this)">Best practices for MSP helpdesk</button>
          <button class="suggestion" onclick="useSuggestion(this)">Explain fiber vs copper tradeoffs</button>
          <button class="suggestion" onclick="useSuggestion(this)">Write a ticket reply for slow internet</button>
        </div>
      </div>
    </div>

    <div class="chat-input-wrap">
      <div class="offline-bar hidden" id="offlineBar">
        <i class="fas fa-exclamation-triangle"></i>
        Ollama is not reachable. Configure your Ollama URL in settings.
        <button onclick="openSettings()" style="margin-left:auto;background:#991b1b;color:#fff;border:none;padding:3px 9px;border-radius:6px;cursor:pointer;font-size:12px;">Settings</button>
      </div>
      <div class="input-row">
        <textarea id="chatInput" placeholder="Ask anything… (Shift+Enter for new line)" rows="1"
          oninput="autoResize(this)" onkeydown="handleKey(event)" data-testid="input-ai-message"></textarea>
        <button class="btn-send" id="sendBtn" onclick="sendMessage()" data-testid="button-send-message">
          <i class="fas fa-paper-plane"></i>
        </button>
      </div>
      <div class="input-hint">&#128274; Runs locally via Ollama &middot; Your data stays on your server</div>
    </div>
  </div>
</div>

<!-- Settings Modal -->
<div class="modal-backdrop hidden" id="settingsModal" onclick="if(event.target===this)closeSettings()">
  <div class="modal-card">
    <h3><i class="fas fa-cog text-blue-500"></i> AI Assistant Settings</h3>
    <div class="toggle-row">
      <span class="tgl-lbl">Enable AI Assistant</span>
      <label class="tgl">
        <input type="checkbox" id="settingEnabled" checked>
        <span class="tgl-sl"></span>
      </label>
    </div>
    <div class="fr">
      <label>Ollama Server URL</label>
      <input type="url" id="settingUrl" placeholder="http://localhost:11434" data-testid="input-ollama-url">
      <div class="hint">URL of your Ollama instance. Use a public hostname if Ollama runs on a different machine or server.</div>
    </div>
    <div class="fr">
      <label>Model Name</label>
      <input type="text" id="settingModel" placeholder="llama3" data-testid="input-ollama-model">
      <div class="hint">Must be pulled in Ollama first. e.g., <code>ollama pull llama3</code></div>
    </div>
    <div class="fr">
      <label>
        Available Models
        <button onclick="loadModels()" style="margin-left:8px;background:none;border:1px solid #d1d5db;padding:2px 8px;border-radius:6px;cursor:pointer;font-size:11px;color:#374151;">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </label>
      <div class="model-chips" id="modelChips">
        <span style="color:#94a3b8;font-size:12px;">Click Refresh to load available models</span>
      </div>
    </div>
    <div class="fr">
      <label>System Prompt</label>
      <textarea id="settingSystemPrompt" rows="3" placeholder="You are a helpful MSP support assistant for Blue Mogul. Be concise and professional."></textarea>
      <div class="hint">Instructions that shape the AI's behaviour in every conversation.</div>
    </div>
    <div class="fr">
      <label>Connection Test</label>
      <div id="testResult" style="font-size:12.5px;color:#6b7280;margin-bottom:8px;">Click Test to check your Ollama connection.</div>
      <button onclick="testConn()" style="background:#f3f4f6;border:1px solid #d1d5db;padding:7px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;">
        <i class="fas fa-plug"></i> Test Connection
      </button>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeSettings()">Cancel</button>
      <button class="btn-save" onclick="saveSettings()" data-testid="button-save-settings">Save Settings</button>
    </div>
  </div>
</div>

<script>
let currentConvId = null;
let messages = [];
let aiSettings = {};
let conversations = [];

async function init() {
  await loadSettings();
  await loadConversations();
  checkStatus();
}

async function loadSettings() {
  try {
    const r = await fetch('/api/ollama/settings');
    aiSettings = await r.json();
    document.getElementById('modelBadge').textContent = aiSettings.model || 'llama3';
    document.getElementById('settingUrl').value = aiSettings.url || '';
    document.getElementById('settingModel').value = aiSettings.model || '';
    document.getElementById('settingEnabled').checked = aiSettings.enabled !== false;
    document.getElementById('settingSystemPrompt').value = aiSettings.system_prompt || '';
  } catch(e) { console.error('Settings load failed:', e); }
}

async function checkStatus() {
  const dot = document.getElementById('statusDot');
  const bar = document.getElementById('offlineBar');
  dot.className = 'status-dot checking';
  try {
    const r = await fetch('/api/ollama/models');
    if (r.ok) {
      dot.className = 'status-dot online'; dot.title = 'Ollama connected ✓';
      bar.classList.add('hidden');
    } else throw new Error();
  } catch {
    dot.className = 'status-dot offline'; dot.title = 'Ollama offline';
    bar.classList.remove('hidden');
  }
}

async function loadConversations() {
  try {
    const r = await fetch('/api/ollama/conversations');
    conversations = await r.json();
    renderConvoList();
  } catch(e) { console.error('Convo load failed:', e); }
}

function renderConvoList() {
  const el = document.getElementById('convoList');
  if (!Array.isArray(conversations) || !conversations.length) {
    el.innerHTML = '<div class="empty-convos">No conversations yet.<br>Start a new chat!</div>';
    return;
  }
  el.innerHTML = conversations.map(c => `
    <div class="convo-item ${c.id === currentConvId ? 'active' : ''}" onclick="loadConvo(${c.id})">
      <div style="flex:1;overflow:hidden;">
        <div class="convo-title">${esc(c.title)}</div>
        <div class="convo-meta">${c.message_count||0} msgs &middot; ${relTime(c.updated_at)}</div>
      </div>
      <button class="convo-del" onclick="delConvo(event,${c.id})" title="Delete"><i class="fas fa-trash-alt"></i></button>
    </div>`).join('');
}

async function loadConvo(id) {
  try {
    const r = await fetch(`/api/ollama/conversations/${id}`);
    const data = await r.json();
    currentConvId = id;
    messages = data.messages || [];
    document.getElementById('chatTitle').textContent = data.title || 'Chat';
    document.getElementById('chatSubtitle').textContent = `${messages.length} messages · model: ${data.model || aiSettings.model}`;
    renderMsgs();
    renderConvoList();
  } catch { notify('Failed to load conversation', 'err'); }
}

async function delConvo(e, id) {
  e.stopPropagation();
  if (!confirm('Delete this conversation?')) return;
  await fetch(`/api/ollama/conversations/${id}`, { method:'DELETE' });
  if (currentConvId === id) newChat();
  await loadConversations();
}

function newChat() {
  currentConvId = null; messages = [];
  document.getElementById('chatTitle').textContent = 'AI Assistant';
  document.getElementById('chatSubtitle').textContent = 'Powered by Ollama — runs locally, stays private';
  document.getElementById('messages').innerHTML = `
    <div class="welcome-wrap" id="welcomeScreen">
      <div class="welcome-icon">&#129302;</div>
      <h3>Blue Mogul AI Assistant</h3>
      <p>Ask me anything about clients, tickets, invoices, or MSP operations.<br>Runs locally via Ollama — your data never leaves your server.</p>
      <div class="suggestions">
        <button class="suggestion" onclick="useSuggestion(this)">Summarize open tickets</button>
        <button class="suggestion" onclick="useSuggestion(this)">How do I onboard a new client?</button>
        <button class="suggestion" onclick="useSuggestion(this)">Draft an overdue invoice follow-up</button>
        <button class="suggestion" onclick="useSuggestion(this)">Best practices for MSP helpdesk</button>
        <button class="suggestion" onclick="useSuggestion(this)">Explain fiber vs copper tradeoffs</button>
        <button class="suggestion" onclick="useSuggestion(this)">Write a ticket reply for slow internet</button>
      </div>
    </div>`;
  renderConvoList();
  document.getElementById('chatInput').focus();
}

function useSuggestion(btn) {
  document.getElementById('chatInput').value = btn.textContent;
  sendMessage();
}

function renderMsgs() {
  const c = document.getElementById('messages');
  if (!messages.length) { newChat(); return; }
  c.innerHTML = messages.map(m => msgHtml(m)).join('');
  scrollBottom();
}

function msgHtml(m) {
  const av = m.role === 'user' ? '<i class="fas fa-user"></i>' : '<i class="fas fa-robot"></i>';
  const content = m.role === 'assistant' ? fmtMd(m.content) : esc(m.content).replace(/\n/g,'<br>');
  return `<div class="msg ${m.role}">
    <div class="msg-av">${av}</div>
    <div><div class="msg-bub">${content}</div></div>
  </div>`;
}

function fmtMd(t) {
  return esc(t)
    .replace(/```([\s\S]*?)```/g,'<pre>$1</pre>')
    .replace(/`([^`]+)`/g,'<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g,'<em>$1</em>')
    .replace(/^### (.+)$/gm,'<h4 style="margin:8px 0 4px;font-size:13.5px;font-weight:600">$1</h4>')
    .replace(/^## (.+)$/gm,'<h3 style="margin:10px 0 5px;font-size:14.5px;font-weight:600">$1</h3>')
    .replace(/^# (.+)$/gm,'<h2 style="margin:12px 0 6px;font-size:16px;font-weight:600">$1</h2>')
    .replace(/^\- (.+)$/gm,'<li>$1</li>')
    .replace(/(<li>.*<\/li>(\n)?)+/g,'<ul>$&</ul>')
    .replace(/\n\n/g,'</p><p style="margin:8px 0">')
    .replace(/\n/g,'<br>');
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function relTime(dt) {
  const s = Math.floor((Date.now() - new Date(dt).getTime())/1000);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.floor(s/60)+'m ago';
  if (s < 86400) return Math.floor(s/3600)+'h ago';
  return Math.floor(s/86400)+'d ago';
}

let activeAbortCtrl = null;

async function sendMessage() {
  const input = document.getElementById('chatInput');
  const text = input.value.trim();
  if (!text) return;
  input.value = ''; input.style.height = 'auto';

  const ws = document.getElementById('welcomeScreen');
  if (ws) ws.remove();

  messages.push({ role:'user', content:text });
  const c = document.getElementById('messages');
  c.insertAdjacentHTML('beforeend', msgHtml({ role:'user', content:text }));

  const tid = 'tp_' + Date.now();
  c.insertAdjacentHTML('beforeend', `
    <div class="msg assistant" id="${tid}">
      <div class="msg-av"><i class="fas fa-robot"></i></div>
      <div><div class="msg-bub" id="${tid}_bub"><div class="typing-ind">
        <div class="tdot"></div><div class="tdot"></div><div class="tdot"></div>
      </div></div></div>
    </div>`);
  scrollBottom();

  const sb = document.getElementById('sendBtn');
  sb.disabled = true;

  activeAbortCtrl = new AbortController();
  let fullReply = '';
  let firstToken = true;
  let lineBuffer = '';

  try {
    const resp = await fetch('/api/ollama/chat', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        messages: messages.slice(-20),
        conversation_id: currentConvId,
        title: messages[0]?.content?.substring(0, 60)
      }),
      signal: activeAbortCtrl.signal
    });

    if (!resp.ok) {
      const errData = await resp.json().catch(() => ({ error: 'Server error' }));
      showBubbleError(tid, errData.error || 'Server error');
      scrollBottom(); return;
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
        const jsonStr = line.slice(6).trim();
        if (!jsonStr) continue;

        let evt;
        try { evt = JSON.parse(jsonStr); } catch { continue; }

        if (evt.error) {
          showBubbleError(tid, evt.error);
          scrollBottom(); return;
        }

        if (evt.token) {
          fullReply += evt.token;
          const bubble = document.getElementById(tid + '_bub');
          if (bubble) {
            if (firstToken) { bubble.innerHTML = ''; firstToken = false; }
            bubble.innerHTML = fmtMd(fullReply);
          }
          scrollBottom();
        }

        if (evt.done) {
          currentConvId = evt.conversation_id;
          messages.push({ role: 'assistant', content: fullReply });
          document.getElementById('chatTitle').textContent =
            messages[0]?.content?.substring(0, 50) || 'Chat';
          document.getElementById('modelBadge').textContent = evt.model || aiSettings.model;
          await loadConversations();
          scrollBottom();
        }
      }
    }
  } catch(e) {
    if (e.name !== 'AbortError') {
      showBubbleError(tid, 'Network error: ' + e.message);
      scrollBottom();
    }
  } finally {
    activeAbortCtrl = null;
    sb.disabled = false;
    input.focus();
  }
}

function showBubbleError(tid, msg) {
  const bubble = document.getElementById(tid + '_bub');
  if (bubble) {
    bubble.className = 'msg-bub msg-err';
    bubble.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${esc(msg)}`;
  }
}

function scrollBottom() {
  const c = document.getElementById('messages');
  c.scrollTop = c.scrollHeight;
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 130) + 'px';
}

function openSettings() {
  loadSettings();
  document.getElementById('settingsModal').classList.remove('hidden');
}

function closeSettings() {
  document.getElementById('settingsModal').classList.add('hidden');
}

async function saveSettings() {
  const url = document.getElementById('settingUrl').value.trim();
  const model = document.getElementById('settingModel').value.trim();
  const enabled = document.getElementById('settingEnabled').checked;
  const system_prompt = document.getElementById('settingSystemPrompt').value.trim();
  if (!url) { alert('Ollama URL is required'); return; }
  if (!model) { alert('Model name is required'); return; }
  try {
    const r = await fetch('/api/ollama/settings', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ url, model, enabled, system_prompt })
    });
    const d = await r.json();
    if (d.success) {
      closeSettings(); await loadSettings(); checkStatus();
      notify('Settings saved!', 'ok');
    } else notify('Failed to save', 'err');
  } catch(e) { notify('Error: '+e.message, 'err'); }
}

async function loadModels() {
  const el = document.getElementById('modelChips');
  el.innerHTML = '<span style="color:#6b7280;font-size:12px;">Loading…</span>';
  const tmpUrl = document.getElementById('settingUrl').value.trim();
  if (tmpUrl) await fetch('/api/ollama/settings', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url:tmpUrl})
  });
  try {
    const r = await fetch('/api/ollama/models');
    const d = await r.json();
    if (!r.ok||d.error) { el.innerHTML=`<span style="color:#ef4444;font-size:12px;">⚠ ${esc(d.error)}</span>`; return; }
    if (!d.models?.length) { el.innerHTML='<span style="color:#94a3b8;font-size:12px;">No models. Run: <code>ollama pull llama3</code></span>'; return; }
    const cur = document.getElementById('settingModel').value;
    el.innerHTML = d.models.map(m=>`<div class="mchip ${m===cur?'sel':''}" onclick="pickModel('${esc(m)}')">${esc(m)}</div>`).join('');
  } catch { el.innerHTML='<span style="color:#ef4444;font-size:12px;">⚠ Could not connect to Ollama</span>'; }
}

function pickModel(name) {
  document.getElementById('settingModel').value = name;
  document.querySelectorAll('.mchip').forEach(c=>c.classList.toggle('sel', c.textContent===name));
}

async function testConn() {
  const el = document.getElementById('testResult');
  const tmpUrl = document.getElementById('settingUrl').value.trim();
  el.textContent = 'Testing connection…'; el.style.color='#6b7280';
  if (tmpUrl) await fetch('/api/ollama/settings', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url:tmpUrl})
  });
  try {
    const r = await fetch('/api/ollama/models');
    const d = await r.json();
    if (r.ok&&d.models) {
      el.textContent = `✅ Connected! ${d.models.length} model(s) available: ${d.models.slice(0,3).join(', ')}${d.models.length>3?'…':''}`;
      el.style.color='#059669';
    } else {
      el.textContent='❌ '+( d.error||'Connection failed');
      el.style.color='#dc2626';
    }
  } catch(e) { el.textContent='❌ '+e.message; el.style.color='#dc2626'; }
}

function notify(msg, type) {
  const n = document.createElement('div');
  n.className = 'notice';
  n.style.background = type==='ok'?'#059669':'#dc2626';
  n.style.color = '#fff';
  n.textContent = msg;
  document.body.appendChild(n);
  setTimeout(()=>{ n.style.opacity='0'; setTimeout(()=>n.remove(),300); }, 2500);
}

init();
</script>
</body>
</html>
