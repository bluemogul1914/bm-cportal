<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$user_id = $_SESSION['user_id'];
$is_admin = true;

$active_room = $_GET['room'] ?? 'support';
$pdo = getDB();

$channels_stmt = $pdo->query("SELECT * FROM chat_channels ORDER BY id");
$channels = $channels_stmt->fetchAll(PDO::FETCH_ASSOC);

$rooms = [];
foreach ($channels as $ch) {
    $rooms[$ch['slug']] = [
        'id' => $ch['id'],
        'name' => $ch['name'],
        'icon' => $ch['icon'],
        'color' => $ch['color'],
        'description' => $ch['description'],
    ];
}

if (!isset($rooms[$active_room]) && count($rooms) > 0) {
    $active_room = array_key_first($rooms);
}

$unread_counts = [];
foreach ($rooms as $key => $r) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE room = ?");
    $stmt->execute([$key]);
    $unread_counts[$key] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
}

$active_channel_id = $rooms[$active_room]['id'] ?? 0;
$members_stmt = $pdo->prepare("SELECT cm.*, u.name as user_name, u.email as user_email FROM chat_channel_members cm LEFT JOIN users u ON u.id = cm.user_id WHERE cm.channel_id = ? ORDER BY cm.added_at");
$members_stmt->execute([$active_channel_id]);
$channel_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

$staff_stmt = $pdo->query("SELECT id, name, email FROM users WHERE is_admin = true ORDER BY name");
$all_staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$member_ids = array_column($channel_members, 'user_id');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="bg-white border-b border-gray-200 flex-shrink-0">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-comments text-blue-500 mr-2"></i>Chat
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Team & client communication channels</p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="document.getElementById('members-panel').classList.toggle('hidden')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition" data-testid="button-toggle-members">
                        <i class="fas fa-users mr-2"></i>Members
                    </button>
                </div>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <div class="w-72 bg-white border-r border-gray-200 flex flex-col flex-shrink-0">
                <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                    <h3 class="font-semibold text-gray-900 text-sm"><i class="fas fa-hashtag text-gray-400 mr-2"></i>Channels</h3>
                </div>
                <div class="p-2 space-y-1 flex-1 overflow-y-auto" data-testid="channel-list">
                    <?php foreach ($rooms as $key => $room):
                        $is_active = ($active_room === $key);
                        $color_classes = match($room['color']) {
                            'blue' => $is_active ? 'bg-blue-50 border-blue-200 text-blue-800' : 'hover:bg-blue-50 text-gray-700',
                            'green' => $is_active ? 'bg-green-50 border-green-200 text-green-800' : 'hover:bg-green-50 text-gray-700',
                            'purple' => $is_active ? 'bg-purple-50 border-purple-200 text-purple-800' : 'hover:bg-purple-50 text-gray-700',
                            default => $is_active ? 'bg-gray-100 border-gray-200 text-gray-800' : 'hover:bg-gray-50 text-gray-700',
                        };
                        $icon_color = match($room['color']) {
                            'blue' => 'text-blue-500',
                            'green' => 'text-green-500',
                            'purple' => 'text-purple-500',
                            default => 'text-gray-500',
                        };
                    ?>
                    <a href="?room=<?php echo $key; ?>" class="flex items-center gap-3 px-3 py-3 rounded-lg border <?php echo $is_active ? $color_classes : 'border-transparent ' . $color_classes; ?> transition" data-testid="channel-<?php echo $key; ?>">
                        <i class="<?php echo $room['icon']; ?> <?php echo $icon_color; ?> text-lg w-6 text-center"></i>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-sm"><?php echo htmlspecialchars($room['name']); ?></p>
                            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($room['description']); ?></p>
                        </div>
                        <?php if ($unread_counts[$key] > 0): ?>
                        <span class="bg-gray-200 text-gray-600 text-xs px-1.5 py-0.5 rounded-full min-w-[20px] text-center"><?php echo $unread_counts[$key]; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <div class="p-3 border-t border-gray-200">
                    <div class="bg-gray-50 rounded-lg p-3 flex items-center gap-2">
                        <div class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-semibold text-sm flex-shrink-0">
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?></p>
                            <p class="text-xs text-green-600"><i class="fas fa-circle text-[8px] mr-1"></i>Online</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex-1 flex flex-col overflow-hidden">
                <div class="px-6 py-3 border-b border-gray-200 bg-white flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <?php $room = $rooms[$active_room]; ?>
                        <div class="w-8 h-8 bg-<?php echo $room['color']; ?>-100 rounded-lg flex items-center justify-center">
                            <i class="<?php echo $room['icon']; ?> text-<?php echo $room['color']; ?>-600 text-sm"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900 text-sm" data-testid="text-active-channel"><?php echo htmlspecialchars($room['name']); ?></h3>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($room['description']); ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <span id="connection-status"><i class="fas fa-circle text-green-400 text-[8px] mr-1"></i>Connected</span>
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
                            <textarea id="message-input" rows="1" maxlength="2000" class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none transition" placeholder="Type your message..." data-testid="input-message" style="min-height: 44px; max-height: 120px;"></textarea>
                        </div>
                        <button type="submit" id="send-btn" class="px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-send-message" disabled>
                            <i class="fas fa-paper-plane"></i>
                            <span>Send</span>
                        </button>
                    </form>
                </div>
            </div>

            <div id="members-panel" class="w-72 bg-white border-l border-gray-200 flex-shrink-0 flex flex-col hidden">
                <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900 text-sm"><i class="fas fa-users text-gray-400 mr-2"></i>Channel Members</h3>
                    <button onclick="document.getElementById('members-panel').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-4 flex-1 overflow-y-auto">
                    <div class="space-y-2 mb-4">
                        <?php foreach ($channel_members as $member): ?>
                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-lg" data-testid="member-<?php echo $member['user_id']; ?>">
                            <div class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-semibold text-xs flex-shrink-0">
                                <?php echo strtoupper(substr($member['user_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($member['user_name'] ?? 'Unknown'); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($member['role']); ?></p>
                            </div>
                            <button onclick="removeMember(<?php echo $active_channel_id; ?>, <?php echo $member['user_id']; ?>)" class="text-red-400 hover:text-red-600 text-xs" data-testid="button-remove-member-<?php echo $member['user_id']; ?>">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($channel_members)): ?>
                        <p class="text-sm text-gray-500 text-center py-4">No members assigned</p>
                        <?php endif; ?>
                    </div>

                    <div class="border-t border-gray-200 pt-4">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Add Staff Member</h4>
                        <select id="add-member-select" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-2" data-testid="select-add-member">
                            <option value="">Select staff...</option>
                            <?php foreach ($all_staff as $staff):
                                if (!in_array($staff['id'], $member_ids)):
                            ?>
                            <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?> (<?php echo htmlspecialchars($staff['email']); ?>)</option>
                            <?php endif; endforeach; ?>
                        </select>
                        <button onclick="addMember()" class="w-full px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-member">
                            <i class="fas fa-plus mr-1"></i>Add to Channel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const ROOM = '<?php echo $active_room; ?>';
const CHANNEL_ID = <?php echo $active_channel_id; ?>;
const CURRENT_USER_ID = <?php echo $user_id; ?>;
const CURRENT_USER_NAME = '<?php echo addslashes($user_name); ?>';
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
        is_admin: true,
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
        ? '<span class="px-1 py-0.5 bg-blue-100 text-blue-600 rounded text-[10px] font-medium">Staff</span>'
        : '<span class="px-1 py-0.5 bg-emerald-100 text-emerald-600 rounded text-[10px] font-medium">Client</span>';
    const bubbleClass = isMe ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-900';
    const html = `
        <div class="flex items-start gap-3 ${isMe ? 'flex-row-reverse' : ''} ${isPending ? 'opacity-60' : ''}" data-msg-id="${msg.id}">
            <div class="${avatarColor} text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm flex-shrink-0">${initial}</div>
            <div class="max-w-[70%] ${isMe ? 'items-end' : 'items-start'} flex flex-col">
                <div class="flex items-center gap-2 mb-0.5 ${isMe ? 'flex-row-reverse' : ''}">
                    <span class="text-xs font-medium ${isMe ? 'text-blue-600' : 'text-gray-700'}">${escapeHtml(msg.user_name)}</span>
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
                messagesContainer.innerHTML = '<div class="empty-state flex flex-col items-center justify-center py-16 text-center"><div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4"><i class="fas fa-comments text-gray-400 text-2xl"></i></div><p class="text-gray-500 font-medium">No messages yet</p><p class="text-sm text-gray-400 mt-1">Be the first to send a message in this channel!</p></div>';
            }
        }
    } catch (err) {
        console.error('Fetch error:', err);
    }
    isLoading = false;
}

async function addMember() {
    const sel = document.getElementById('add-member-select');
    const userId = sel.value;
    if (!userId) return;
    try {
        const resp = await fetch(`/api/chat/channels/${CHANNEL_ID}/members`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: parseInt(userId), role: 'member' })
        });
        if (resp.ok) location.reload();
    } catch (err) {
        console.error('Add member error:', err);
    }
}

async function removeMember(channelId, userId) {
    if (!confirm('Remove this member from the channel?')) return;
    try {
        const resp = await fetch(`/api/chat/channels/${channelId}/members/${userId}`, { method: 'DELETE' });
        if (resp.ok) location.reload();
    } catch (err) {
        console.error('Remove member error:', err);
    }
}

fetchMessages();
pollInterval = setInterval(fetchMessages, 3000);
messageInput.focus();
</script>
</body>
</html>
