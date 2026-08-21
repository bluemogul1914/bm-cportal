<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;

$active_room = 'support';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Chat - Blue Mogul</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="bg-white border-b border-gray-200 flex-shrink-0">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-headset text-blue-500 mr-2"></i>Support Chat
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Chat with our support team in real time</p>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-400">
                    <span id="connection-status"><i class="fas fa-circle text-green-400 text-[8px] mr-1"></i>Connected</span>
                </div>
            </div>
        </header>

        <div class="flex-1 flex flex-col overflow-hidden">
            <div class="px-6 py-3 border-b border-gray-200 bg-blue-50">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-headset text-blue-600 text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 text-sm" data-testid="text-active-channel">Support</h3>
                        <p class="text-xs text-gray-500">Our team typically responds within a few minutes</p>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-6 space-y-1" id="messages-container" data-testid="messages-container">
                <div class="flex items-center justify-center py-8" id="loading-messages">
                    <i class="fas fa-spinner fa-spin text-gray-400 mr-2"></i>
                    <span class="text-gray-400 text-sm">Loading messages...</span>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 bg-white flex-shrink-0">
                <form id="chat-form" class="flex items-end gap-3" data-testid="chat-form">
                    <div class="flex-1">
                        <textarea id="message-input" rows="1" maxlength="2000" class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none transition" placeholder="Type your message to support..." data-testid="input-message" style="min-height: 44px; max-height: 120px;"></textarea>
                    </div>
                    <button type="submit" id="send-btn" class="px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-send-message" disabled>
                        <i class="fas fa-paper-plane"></i>
                        <span>Send</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
const ROOM = 'support';
const CURRENT_USER_ID = <?php echo $user_id; ?>;
const CURRENT_USER_NAME = '<?php echo addslashes($user_name); ?>';
const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
let lastMessageId = 0;
let pollInterval = null;
let isLoading = false;

const messagesContainer = document.getElementById('messages-container');
const messageInput = document.getElementById('message-input');
const sendBtn = document.getElementById('send-btn');
const chatForm = document.getElementById('chat-form');
const loadingDiv = document.getElementById('loading-messages');

messageInput.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    sendBtn.disabled = !this.value.trim();
});

messageInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (this.value.trim()) chatForm.dispatchEvent(new Event('submit'));
    }
});

chatForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg = messageInput.value.trim();
    if (!msg) return;
    sendBtn.disabled = true;
    messageInput.value = '';
    messageInput.style.height = 'auto';
    appendMessage({
        id: 'temp-' + Date.now(),
        user_id: CURRENT_USER_ID,
        user_name: CURRENT_USER_NAME,
        is_admin: IS_ADMIN,
        message: msg,
        created_at: new Date().toISOString(),
        _pending: true
    });
    try {
        const resp = await fetch('/api/chat/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ room: ROOM, message: msg })
        });
        if (!resp.ok) throw new Error('Failed to send');
        fetchMessages();
    } catch (err) {
        console.error('Send error:', err);
    }
});

function formatTime(dateStr) {
    const d = new Date(dateStr);
    const now = new Date();
    const diff = now - d;
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
    const isToday = d.toDateString() === now.toDateString();
    const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (isToday) return time;
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function appendMessage(msg) {
    const isMe = msg.user_id === CURRENT_USER_ID;
    const isPending = msg._pending;
    const initial = (msg.user_name || 'U')[0].toUpperCase();
    const avatarColor = msg.is_admin ? 'bg-blue-600' : 'bg-emerald-600';
    const badge = msg.is_admin
        ? '<span class="px-1.5 py-0.5 bg-blue-100 text-blue-600 rounded text-[10px] font-medium"><i class="fas fa-headset text-[8px] mr-0.5"></i>Support</span>'
        : '';
    const bubbleClass = isMe ? 'bg-emerald-600 text-white' : (msg.is_admin ? 'bg-blue-50 text-gray-900 border border-blue-200' : 'bg-gray-100 text-gray-900');
    const html = `
        <div class="flex items-start gap-3 ${isMe ? 'flex-row-reverse' : ''} ${isPending ? 'opacity-60' : ''}" data-msg-id="${msg.id}">
            <div class="${avatarColor} text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm flex-shrink-0">${initial}</div>
            <div class="max-w-[70%] ${isMe ? 'items-end' : 'items-start'} flex flex-col">
                <div class="flex items-center gap-2 mb-0.5 ${isMe ? 'flex-row-reverse' : ''}">
                    <span class="text-xs font-medium ${isMe ? 'text-emerald-600' : 'text-gray-700'}">${escapeHtml(msg.user_name)}</span>
                    ${badge}
                    <span class="text-[10px] text-gray-400">${formatTime(msg.created_at)}</span>
                </div>
                <div class="${bubbleClass} rounded-2xl ${isMe ? 'rounded-tr-sm' : 'rounded-tl-sm'} px-4 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words">${escapeHtml(msg.message)}</div>
            </div>
        </div>
    `;
    const tempMsgs = messagesContainer.querySelectorAll('[data-msg-id^="temp-"]');
    if (!isPending && tempMsgs.length > 0) {
        tempMsgs.forEach(el => {
            const tempText = el.querySelector('.whitespace-pre-wrap')?.textContent;
            if (tempText === msg.message) el.remove();
        });
    }
    if (!isPending && messagesContainer.querySelector(`[data-msg-id="${msg.id}"]`)) return;
    messagesContainer.insertAdjacentHTML('beforeend', html);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function insertDateSeparator(dateStr) {
    const d = new Date(dateStr);
    const now = new Date();
    let label;
    if (d.toDateString() === now.toDateString()) label = 'Today';
    else {
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        label = d.toDateString() === yesterday.toDateString() ? 'Yesterday' : d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
    }
    const html = `<div class="flex items-center gap-3 py-3"><div class="flex-1 h-px bg-gray-200"></div><span class="text-xs text-gray-400 font-medium px-2">${label}</span><div class="flex-1 h-px bg-gray-200"></div></div>`;
    messagesContainer.insertAdjacentHTML('beforeend', html);
}

async function fetchMessages() {
    if (isLoading) return;
    isLoading = true;
    try {
        const resp = await fetch(`/api/chat/messages?room=${ROOM}&after=${lastMessageId}`);
        if (!resp.ok) throw new Error('Failed to fetch');
        const data = await resp.json();
        if (loadingDiv) loadingDiv.remove();
        if (data.messages && data.messages.length > 0) {
            const emptyState = messagesContainer.querySelector('.empty-state');
            if (emptyState) emptyState.remove();
            let lastDate = null;
            data.messages.forEach(msg => {
                const msgDate = new Date(msg.created_at).toDateString();
                if (msgDate !== lastDate && lastMessageId === 0) {
                    insertDateSeparator(msg.created_at);
                    lastDate = msgDate;
                }
                appendMessage(msg);
                if (msg.id > lastMessageId) lastMessageId = msg.id;
            });
        } else if (lastMessageId === 0) {
            if (loadingDiv) loadingDiv.remove();
            if (!messagesContainer.querySelector('.empty-state') && !messagesContainer.querySelector('[data-msg-id]')) {
                messagesContainer.innerHTML = '<div class="empty-state flex flex-col items-center justify-center py-16 text-center"><div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-4"><i class="fas fa-headset text-blue-400 text-2xl"></i></div><p class="text-gray-500 font-medium">Welcome to Support Chat</p><p class="text-sm text-gray-400 mt-1">Send a message and our team will respond shortly.</p></div>';
            }
        }
    } catch (err) {
        console.error('Fetch error:', err);
    }
    isLoading = false;
}

fetchMessages();
pollInterval = setInterval(fetchMessages, 3000);
messageInput.focus();
</script>
</body>
</html>
