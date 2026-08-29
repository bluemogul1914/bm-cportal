<!-- Admin Sidebar Navigation -->
<div class="w-64 bg-secondary text-white flex-shrink-0 flex flex-col">
    <div class="p-5 border-b border-gray-700">
        <div class="flex items-center space-x-3">
            <img src="/assets/img/bluemogul-logo.png" alt="Blue Mogul" class="h-9 w-auto">
            <p class="text-xs text-gray-400">Admin Panel</p>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto py-6 px-4">
        <?php $current_page = basename($_SERVER['PHP_SELF'] ?? ''); ?>
        <div class="space-y-1">
            <a href="admin-dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-dashboard.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-tachometer-alt w-5"></i>
                <span>Dashboard</span>
            </a>

            <a href="admin-crm.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-crm.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-crm">
                <i class="fas fa-handshake w-5 text-emerald-400"></i>
                <span>CRM</span>
            </a>

            <?php
            $clients_pages = ['admin-clients.php','admin-client-add.php','admin-client-detail.php','admin-client-edit.php','admin-client-emails.php'];
            $clients_open  = in_array($current_page, $clients_pages);
            ?>
            <div>
                <button onclick="toggleClients()" class="w-full flex items-center justify-between px-4 py-3 rounded-lg <?= $clients_open ? 'bg-blue-900/40 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white' ?> transition" data-testid="nav-clients-toggle">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-users w-5"></i>
                        <span>Clients</span>
                    </div>
                    <i class="fas fa-chevron-up text-xs transition-transform" id="clients-chevron"></i>
                </button>
                <div id="clients-subnav" class="<?= $clients_open ? '' : 'hidden' ?> ml-4 mt-1 space-y-0.5 border-l-2 border-gray-700 pl-3">
                    <?php foreach ([
                        ['admin-clients.php',       'fa-list',         'Client List',       'link-clients-list'],
                        ['admin-client-add.php',    'fa-user-plus',    'Add Client',        'link-clients-add'],
                        ['admin-client-emails.php', 'fa-envelope',     'Email Clients',     'link-client-emails'],
                    ] as [$href,$icon,$label,$tid]): ?>
                    <a href="<?= $href ?>" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-sm <?= $current_page===$href ? 'bg-blue-600 text-white font-medium' : 'text-gray-400 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="<?= $tid ?>">
                        <i class="fas <?= $icon ?> w-4 text-xs text-center shrink-0"></i><span><?= $label ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            $leads_pages = ['admin-leads-dashboard.php','admin-leads-add.php','admin-leads-list.php','admin-leads-view.php','admin-leads-quotes.php','admin-leads-maps.php','admin-smtp-settings.php','admin-companies.php'];
            $leads_open  = in_array($current_page, $leads_pages);
            ?>
            <div>
                <button onclick="toggleLeads()" class="w-full flex items-center justify-between px-4 py-3 rounded-lg <?= $leads_open ? 'bg-blue-900/40 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white' ?> transition" data-testid="nav-leads-toggle">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-user-tag w-5 text-yellow-400"></i>
                        <span>Leads</span>
                    </div>
                    <i class="fas fa-chevron-up text-xs transition-transform" id="leads-chevron"></i>
                </button>
                <div id="leads-subnav" class="<?= $leads_open ? '' : 'hidden' ?> ml-4 mt-1 space-y-0.5 border-l-2 border-gray-700 pl-3">
                    <?php foreach ([
                        ['admin-leads-dashboard.php', 'fa-tachometer-alt',   'Dashboard',   'link-leads-dashboard'],
                        ['admin-leads-add.php',       'fa-plus',             'Add Lead',    'link-leads-add'],
                        ['admin-leads-list.php',      'fa-list',             'List',        'link-leads-list'],
                        ['admin-companies.php',       'fa-building',         'Companies',   'link-companies'],
                        ['admin-leads-quotes.php',    'fa-file-alt',         'Quotes',      'link-leads-quotes'],
                        ['admin-leads-maps.php',      'fa-map-marked-alt',   'Maps',        'link-leads-maps'],
                        ['admin-smtp-settings.php',   'fa-envelope-open-text','Email SMTP', 'link-smtp-settings'],
                    ] as [$href,$icon,$label,$tid]): ?>
                    <a href="<?= $href ?>" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-sm <?= $current_page===$href ? 'bg-blue-600 text-white font-medium' : 'text-gray-400 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="<?= $tid ?>">
                        <i class="fas <?= $icon ?> w-4 text-xs text-center shrink-0"></i><span><?= $label ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <a href="admin-tickets.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-tickets.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-ticket-alt w-5"></i>
                <span>Tickets</span>
            </a>

            <a href="admin-invoices.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-invoices.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-file-invoice-dollar w-5"></i>
                <span>Invoices</span>
            </a>

            <a href="admin-services.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-services.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-server w-5"></i>
                <span>Services</span>
            </a>

            <a href="admin-products.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-products.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-box w-5"></i>
                <span>Products</span>
            </a>

            <a href="admin-projects.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo in_array($current_page, ['admin-projects.php','admin-project-detail.php']) ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-projects">
                <i class="fas fa-project-diagram w-5"></i>
                <span>Projects</span>
            </a>

            <a href="admin-network.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-network.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-network-wired w-5"></i>
                <span>Network Docs</span>
            </a>

            <a href="admin-knowledge.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-knowledge.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-book w-5"></i>
                <span>Knowledge Base</span>
            </a>

            <a href="admin-messages.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo in_array($current_page, ['admin-messages.php','admin-message-compose.php','admin-message-templates.php']) ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-messages">
                <i class="fas fa-envelope w-5"></i>
                <span>Messages</span>
            </a>

            <?php
            // Unread mail badge
            $mail_unread = 0;
            try {
                $mu = getDB()->query("SELECT COUNT(*) FROM mail_messages WHERE folder='inbox' AND is_read=false");
                $mail_unread = (int)$mu->fetchColumn();
            } catch(Exception $e) {}
            ?>
            <a href="admin-mail.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-mail.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-mail">
                <i class="fas fa-inbox w-5"></i>
                <span>Mail</span>
                <?php if ($mail_unread > 0): ?>
                <span class="ml-auto text-xs bg-red-500 text-white rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1 font-bold"><?= $mail_unread ?></span>
                <?php endif; ?>
            </a>

            <a href="admin-chat.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-chat.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-chat">
                <i class="fas fa-comments w-5"></i>
                <span>Chat</span>
            </a>

            <a href="admin-monitoring.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-monitoring.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-monitoring">
                <i class="fas fa-heartbeat w-5"></i>
                <span>Monitoring</span>
            </a>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Integrations</p>
            <div class="space-y-1">
                <a href="admin-action1.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-action1.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-action1">
                    <i class="fas fa-shield-alt w-5"></i>
                    <span>Action1 RMM</span>
                </a>

                <a href="admin-jumpcloud.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-jumpcloud.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-jumpcloud">
                    <i class="fas fa-cloud-upload-alt w-5 text-green-400"></i>
                    <span>JumpCloud</span>
                </a>

                <a href="admin-frontier.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-frontier.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-frontier">
                    <i class="fas fa-network-wired w-5 text-orange-400"></i>
                    <span>Frontier ASR</span>
                </a>

                <a href="admin-resellerclub.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-resellerclub.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-resellerclub">
                    <i class="fas fa-store w-5 text-sky-400"></i>
                    <span>ResellerClub</span>
                </a>

                <a href="admin-travelsim.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-travelsim.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-travelsim">
                    <i class="fas fa-sim-card w-5 text-emerald-400"></i>
                    <span>TravelSim</span>
                </a>

                <a href="admin-coolify.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-coolify.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-coolify">
                    <i class="fas fa-rocket w-5 text-purple-400"></i>
                    <span>Coolify</span>
                </a>

                <a href="admin-varphonex.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-varphonex.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-varphonex">
                    <i class="fas fa-phone-alt w-5 text-cyan-400"></i>
                    <span>VarPhonex</span>
                </a>

                <a href="admin-itflow.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-itflow.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-ticket-alt w-5"></i>
                    <span>ITFlow</span>
                </a>

                <a href="admin-voip.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-voip.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-phone w-5"></i>
                    <span>VoIP.ms</span>
                </a>

                <a href="admin-nextcloud.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-nextcloud.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-cloud w-5"></i>
                    <span>Nextcloud</span>
                </a>

                <a href="admin-stripe.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-stripe.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-credit-card w-5"></i>
                    <span>Stripe</span>
                </a>

                <a href="admin-hostwinds.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-hostwinds.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-hostwinds">
                    <i class="fas fa-server w-5 text-cyan-400"></i>
                    <span>Hostwinds Cloud</span>
                </a>

                <a href="admin-hetzner.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-hetzner.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-hetzner">
                    <i class="fas fa-database w-5 text-orange-400"></i>
                    <span>Hetzner</span>
                </a>

                <a href="admin-dandh.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-dandh.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-dandh">
                    <i class="fas fa-truck w-5 text-orange-400"></i>
                    <span>D&amp;H Distributing</span>
                </a>

            </div>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">AI &amp; Automation</p>
            <div class="space-y-1">
                <a href="admin-ai-assistant.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-ai-assistant.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-ai-assistant">
                    <i class="fas fa-comments w-5"></i>
                    <span>AI Assistant</span>
                    <span class="ml-auto bg-purple-500 text-white text-xs px-2 py-0.5 rounded-full">NEW</span>
                </a>

                <a href="admin-ai-agents.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-ai-agents.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-robot w-5"></i>
                    <span>AI Agents</span>
                    <span class="ml-auto bg-green-500 text-white text-xs px-2 py-0.5 rounded-full">10</span>
                </a>

                <a href="admin-automation.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-automation.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-magic w-5"></i>
                    <span>Automation</span>
                </a>
            </div>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Dealer Program</p>
            <div class="space-y-1">
                <?php
                $dealer_pending_count = 0;
                try {
                    if (function_exists('getDB')) {
                        $db_temp = getDB();
                    } elseif (function_exists('get_db')) {
                        $db_temp = get_db();
                    } else {
                        $db_temp = null;
                    }
                    if ($db_temp) {
                        $dp = $db_temp->query("SELECT COUNT(*) FROM dealers WHERE status='pending'");
                        $dealer_pending_count = (int)$dp->fetchColumn();
                    }
                } catch (Exception $e) {}
                ?>
                <a href="/portal/admin-dealers.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo in_array($current_page, ['admin-dealers.php','admin-dealer-detail.php']) ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-dealer-dashboard">
                    <i class="fas fa-handshake w-5"></i>
                    <span>Dealers Dashboard</span>
                    <?php if ($dealer_pending_count > 0): ?>
                    <span class="ml-auto bg-amber-500 text-white text-xs px-2 py-0.5 rounded-full"><?= $dealer_pending_count ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">System</p>
            <div class="space-y-1">
                <a href="admin-roles.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-roles.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-roles">
                    <i class="fas fa-user-shield w-5"></i>
                    <span>Roles & Access</span>
                </a>
                <a href="admin-audit.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-audit.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-audit-trail">
                    <i class="fas fa-clipboard-list w-5"></i>
                    <span>Audit Trail</span>
                </a>
                <a href="admin-email-log.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-email-log.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>" data-testid="link-email-log">
                    <i class="fas fa-paper-plane w-5"></i>
                    <span>Email Log</span>
                </a>

                <a href="admin-settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-settings.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>

                <a href="admin-reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-reports.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="p-4 border-t border-gray-700">
        <div class="bg-gray-800 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs text-gray-400">System Status</span>
                <span class="text-xs text-green-400 font-semibold flex items-center">
                    <span class="h-2 w-2 bg-green-400 rounded-full mr-1 animate-pulse"></span>
                    Online
                </span>
            </div>
            <?php
            try {
                $sidebar_pdo = getDB();
                $mrr_row = $sidebar_pdo->query("SELECT COALESCE(SUM(p.price), 0) as mrr FROM subscriptions s JOIN products p ON s.product_id = p.id WHERE s.status = 'active'")->fetch(PDO::FETCH_ASSOC);
                $sidebar_mrr = $mrr_row['mrr'] ?? 0;
                $clients_row = $sidebar_pdo->query("SELECT COUNT(*) as cnt FROM clients WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);
                $sidebar_clients = $clients_row['cnt'] ?? 0;
            } catch (PDOException $e) {
                $sidebar_mrr = 0;
                $sidebar_clients = 0;
            }
            ?>
            <div class="space-y-1 text-xs text-gray-400">
                <div class="flex justify-between">
                    <span>MRR</span>
                    <span class="font-semibold text-white">$<?php echo number_format($sidebar_mrr, 0); ?></span>
                </div>
                <div class="flex justify-between">
                    <span>Clients</span>
                    <span class="font-semibold text-white"><?php echo (int)$sidebar_clients; ?></span>
                </div>
            </div>
        </div>

        <a href="dashboard.php" class="mt-4 flex items-center justify-center space-x-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm transition">
            <i class="fas fa-arrow-left"></i>
            <span>Client View</span>
        </a>
    </div>
</div>

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
<?php require_once __DIR__ . '/admin-ai-widget.php'; ?>
<script>
function toggleLeads() {
    const nav = document.getElementById('leads-subnav');
    const chev = document.getElementById('leads-chevron');
    if (nav) nav.classList.toggle('hidden');
    if (chev) chev.classList.toggle('rotate-180');
}
function toggleClients() {
    const nav = document.getElementById('clients-subnav');
    const chev = document.getElementById('clients-chevron');
    if (nav) nav.classList.toggle('hidden');
    if (chev) chev.classList.toggle('rotate-180');
}
// Init chevron states
(function() {
    [['leads-subnav','leads-chevron'],['clients-subnav','clients-chevron']].forEach(([navId,chevId]) => {
        const nav = document.getElementById(navId);
        const chev = document.getElementById(chevId);
        if (nav && nav.classList.contains('hidden') && chev) chev.classList.add('rotate-180');
    });
})();
</script>