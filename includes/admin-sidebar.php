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

            <a href="admin-clients.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-clients.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-users w-5"></i>
                <span>Clients</span>
            </a>

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
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Integrations</p>
            <div class="space-y-1">
                <a href="admin-itflow.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-itflow.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-ticket-alt w-5"></i>
                    <span>ITFlow</span>
                </a>

                <a href="admin-uisp.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'admin-uisp.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                    <i class="fas fa-wifi w-5"></i>
                    <span>UISP</span>
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
            </div>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">AI Agent Army</p>
            <div class="space-y-1">
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
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">System</p>
            <div class="space-y-1">
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
            <div class="space-y-1 text-xs text-gray-400">
                <div class="flex justify-between">
                    <span>MRR</span>
                    <span class="font-semibold text-white">$29,895</span>
                </div>
                <div class="flex justify-between">
                    <span>Clients</span>
                    <span class="font-semibold text-white">48</span>
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