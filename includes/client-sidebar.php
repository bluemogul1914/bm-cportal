<div class="w-64 bg-secondary text-white flex-shrink-0 flex flex-col">
    <div class="p-5 border-b border-gray-700">
        <div class="flex items-center space-x-3">
            <img src="/assets/img/bluemogul-logo.png" alt="Blue Mogul" class="h-9 w-auto">
            <p class="text-xs text-gray-400">Client Portal</p>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto py-6 px-4">
        <?php $current_page = basename($_SERVER['PHP_SELF'] ?? ''); ?>
        <div class="space-y-1">
            <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'dashboard.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-tachometer-alt w-5"></i>
                <span>Dashboard</span>
            </a>

            <a href="tickets.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'tickets.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-ticket-alt w-5"></i>
                <span>Tickets</span>
            </a>

            <a href="billing.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'billing.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-file-invoice-dollar w-5"></i>
                <span>Billing</span>
            </a>

            <a href="services.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'services.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-server w-5"></i>
                <span>Services</span>
            </a>

            <a href="products.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'products.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-box w-5"></i>
                <span>Products</span>
            </a>

            <a href="projects.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'projects.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-projects">
                <i class="fas fa-project-diagram w-5"></i>
                <span>Projects</span>
            </a>

            <a href="documents.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'documents.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-folder w-5"></i>
                <span>Documents</span>
            </a>

            <a href="client-voip.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'client-voip.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-voice-services">
                <i class="fas fa-phone-alt w-5"></i>
                <span>Voice Services</span>
            </a>

            <a href="client-chat.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'client-chat.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-support-chat">
                <i class="fas fa-headset w-5"></i>
                <span>Support Chat</span>
            </a>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Account</p>
            <div class="space-y-1">
                <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'profile.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-user w-5"></i>
                    <span>My Profile</span>
                </a>

                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'settings.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>

                <a href="help.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'help.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-question-circle w-5"></i>
                    <span>Help Center</span>
                </a>
            </div>
        </div>

        <?php if ($is_admin ?? false): ?>
        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Admin</p>
            <div class="space-y-1">
                <a href="admin-dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-700 hover:text-white transition">
                    <i class="fas fa-shield-alt w-5"></i>
                    <span>Admin Panel</span>
                    <i class="fas fa-arrow-right ml-auto text-xs"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </nav>

    <div class="p-4 border-t border-gray-700">
        <div class="flex items-center space-x-3 px-2 mb-4">
            <div class="bg-blue-600 text-white rounded-full h-9 w-9 flex items-center justify-center font-semibold text-sm flex-shrink-0">
                <?php echo strtoupper(substr($user_name ?? 'U', 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($user_name ?? 'User'); ?></p>
                <p class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars($user_email ?? ''); ?></p>
            </div>
        </div>

        <a href="logout.php" class="flex items-center justify-center space-x-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm transition text-gray-300 hover:text-white">
            <i class="fas fa-sign-out-alt"></i>
            <span>Sign Out</span>
        </a>
    </div>
</div>

<!-- Chatbot Widget -->
<div id="chatbot-widget" class="fixed bottom-6 right-6 z-50">
    <button id="chatbot-toggle" onclick="toggleChatbot()" class="w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center transition hover:scale-105" data-testid="button-chatbot-toggle" title="Need help?">
        <i id="chatbot-icon" class="fas fa-comments text-xl"></i>
    </button>
    <div id="chatbot-panel" class="hidden absolute bottom-16 right-0 w-80 bg-white rounded-2xl shadow-2xl border border-gray-200 flex flex-col overflow-hidden" style="height:420px;">
        <div class="bg-blue-600 text-white px-4 py-3 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center">
                    <i class="fas fa-robot text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-semibold">Blue Mogul Support</p>
                    <p class="text-xs text-blue-200">Online</p>
                </div>
            </div>
            <button onclick="toggleChatbot()" class="text-blue-200 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <div id="chatbot-messages" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50">
            <div class="flex gap-2">
                <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-robot text-blue-500 text-xs"></i></div>
                <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 text-sm text-gray-700 shadow-sm max-w-[85%]">
                    Hi! I'm your Blue Mogul assistant. How can I help you today?
                </div>
            </div>
        </div>
        <div class="flex-shrink-0 px-3 py-2 bg-white border-t border-gray-100">
            <div class="flex gap-2">
                <input type="text" id="chatbot-input" placeholder="Type a message..." class="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-400" data-testid="input-chatbot-message" onkeydown="if(event.key==='Enter')sendChatMessage()">
                <button onclick="sendChatMessage()" class="w-9 h-9 bg-blue-600 hover:bg-blue-700 text-white rounded-full flex items-center justify-center flex-shrink-0" data-testid="button-chatbot-send">
                    <i class="fas fa-paper-plane text-xs"></i>
                </button>
            </div>
        </div>
    </div>
</div>
<script>
function toggleChatbot() {
    const panel = document.getElementById('chatbot-panel');
    const icon = document.getElementById('chatbot-icon');
    panel.classList.toggle('hidden');
    icon.className = panel.classList.contains('hidden') ? 'fas fa-comments text-xl' : 'fas fa-times text-xl';
    if (!panel.classList.contains('hidden')) {
        document.getElementById('chatbot-input').focus();
    }
}
async function sendChatMessage() {
    const input = document.getElementById('chatbot-input');
    const msg = input.value.trim();
    if (!msg) return;
    input.value = '';
    const container = document.getElementById('chatbot-messages');
    container.insertAdjacentHTML('beforeend', `
        <div class="flex gap-2 justify-end">
            <div class="bg-blue-600 text-white rounded-2xl rounded-tr-sm px-3 py-2 text-sm max-w-[85%]">${escapeHtml(msg)}</div>
        </div>`);
    container.scrollTop = container.scrollHeight;
    const typing = document.createElement('div');
    typing.id = 'chatbot-typing';
    typing.className = 'flex gap-2';
    typing.innerHTML = `<div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-robot text-blue-500 text-xs"></i></div>
        <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 text-sm text-gray-500 shadow-sm"><i class="fas fa-ellipsis-h"></i></div>`;
    container.appendChild(typing);
    container.scrollTop = container.scrollHeight;
    try {
        const resp = await fetch('/api/chatbot/message', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: msg})
        });
        const data = await resp.json();
        document.getElementById('chatbot-typing')?.remove();
        const reply = data.reply || 'I\'m here to help! For urgent issues, please open a support ticket.';
        container.insertAdjacentHTML('beforeend', `
            <div class="flex gap-2">
                <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-robot text-blue-500 text-xs"></i></div>
                <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 text-sm text-gray-700 shadow-sm max-w-[85%]">${escapeHtml(reply)}</div>
            </div>`);
    } catch(e) {
        document.getElementById('chatbot-typing')?.remove();
        container.insertAdjacentHTML('beforeend', `
            <div class="flex gap-2">
                <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-robot text-blue-500 text-xs"></i></div>
                <div class="bg-white rounded-2xl rounded-tl-sm px-3 py-2 text-sm text-red-500 shadow-sm max-w-[85%]">Sorry, I couldn't connect. Please try again.</div>
            </div>`);
    }
    container.scrollTop = container.scrollHeight;
}
function escapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}
</script>

<style>
.bg-secondary {
    background-color: #0d1b3e;
}

nav::-webkit-scrollbar {
    width: 4px;
}

nav::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
}

nav::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 2px;
}

nav::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3);
}
</style>

<?php require_once __DIR__ . '/client-ai-widget.php'; ?>