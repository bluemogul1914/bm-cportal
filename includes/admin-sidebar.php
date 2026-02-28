<aside class="w-64 bg-secondary text-white flex flex-col min-h-screen flex-shrink-0">
    <div class="p-5 border-b border-gray-700">
        <div class="flex items-center space-x-3">
            <img src="/assets/img/logo.png" alt="Blue Mogul" class="h-8 w-auto" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22 viewBox=%220 0 40 40%22><rect fill=%22%231a56db%22 width=%2240%22 height=%2240%22 rx=%224%22/><text x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22white%22 font-family=%22Arial%22 font-weight=%22bold%22 font-size=%2218%22>BM</text></svg>';">
            <div>
                <h1 class="text-base font-bold">Admin Panel</h1>
            </div>
        </div>
    </div>

    <nav class="flex-1 py-4 overflow-y-auto">
        <div class="px-3 space-y-1">
            <?php $current_page = basename($_SERVER['PHP_SELF'] ?? ''); ?>

            <a href="admin-dashboard.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition <?php echo ($current_page == 'admin-dashboard.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-tachometer-alt w-5 mr-3"></i>Dashboard
            </a>

            <a href="admin-clients.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition <?php echo ($current_page == 'admin-clients.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-users w-5 mr-3"></i>Clients
            </a>

            <a href="admin-tickets.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition <?php echo ($current_page == 'admin-tickets.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-ticket-alt w-5 mr-3"></i>Tickets
            </a>

            <a href="admin-invoices.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition <?php echo ($current_page == 'admin-invoices.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-file-invoice-dollar w-5 mr-3"></i>Invoices
            </a>

            <a href="admin-products.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition <?php echo ($current_page == 'admin-products.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-box w-5 mr-3"></i>Services
            </a>

            <a href="admin-settings.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition <?php echo ($current_page == 'admin-settings.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-cog w-5 mr-3"></i>Settings
            </a>
        </div>

        <div class="mt-6 px-3">
            <div class="border-t border-gray-700 pt-4">
                <a href="dashboard.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg text-gray-300 hover:bg-gray-700 hover:text-white transition">
                    <i class="fas fa-eye w-5 mr-3"></i>Client View
                </a>

                <a href="logout.php" class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg text-gray-300 hover:bg-gray-700 hover:text-white transition">
                    <i class="fas fa-sign-out-alt w-5 mr-3"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="p-4 border-t border-gray-700">
        <div class="flex items-center space-x-2">
            <div class="w-2 h-2 rounded-full bg-green-400"></div>
            <span class="text-xs text-gray-400">System Online</span>
        </div>
    </div>
</aside>