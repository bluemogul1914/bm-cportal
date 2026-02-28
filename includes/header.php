<header class="bg-white shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <div class="flex items-center space-x-8">
                <a href="dashboard.php" class="flex items-center">
                    <img src="/assets/img/logo.png" alt="Blue Mogul" class="h-10">
                </a>
                <nav class="hidden md:flex space-x-6">
                    <a href="dashboard.php" class="text-primary font-semibold border-b-2 border-primary pb-1">
                        <i class="fas fa-home mr-2"></i>Dashboard
                    </a>
                    <a href="tickets.php" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="fas fa-ticket-alt mr-2"></i>Tickets
                    </a>
                    <a href="invoices.php" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="fas fa-file-invoice-dollar mr-2"></i>Invoices
                    </a>
                    <a href="services.php" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="fas fa-server mr-2"></i>Services
                    </a>
                    <a href="products.php" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="fas fa-shopping-cart mr-2"></i>Products
                    </a>
                </nav>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="profile-btn" class="flex items-center space-x-3 p-2 hover:bg-gray-100 rounded-lg transition-all">
                        <div class="w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center font-semibold">
                            <?php echo strtoupper(substr($user_name ?? $_SESSION['user_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <p class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($user_name ?? $_SESSION['user_name'] ?? 'User'); ?></p>
                            <p class="text-xs text-gray-500">Client</p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400 text-sm"></i>
                    </button>
                    <div id="profile-menu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 py-2 z-50">
                        <div class="px-4 py-3 border-b border-gray-100">
                            <p class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($user_name ?? $_SESSION['user_name'] ?? 'User'); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user_email ?? $_SESSION['user_email'] ?? ''); ?></p>
                        </div>
                        <a href="profile.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-user-circle mr-3 text-gray-400"></i>My Profile
                        </a>
                        <a href="billing.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-credit-card mr-3 text-gray-400"></i>Billing & Payment
                        </a>
                        <a href="settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-cog mr-3 text-gray-400"></i>Settings
                        </a>
                        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                        <a href="admin.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 border-t border-gray-100">
                            <i class="fas fa-tools mr-3 text-gray-400"></i>Admin Panel
                        </a>
                        <?php endif; ?>
                        <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 border-t border-gray-100">
                            <i class="fas fa-sign-out-alt mr-3"></i>Logout
                        </a>
                    </div>
                </div>
                <button id="mobile-menu-btn" class="md:hidden p-2 text-gray-600 hover:text-primary">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="mobile-menu" class="hidden md:hidden border-t border-gray-100 bg-white">
        <nav class="px-4 py-4 space-y-2">
            <a href="dashboard.php" class="flex items-center px-4 py-2 text-primary bg-blue-50 rounded-lg font-semibold">
                <i class="fas fa-home mr-3"></i>Dashboard
            </a>
            <a href="tickets.php" class="flex items-center px-4 py-2 text-gray-600 hover:bg-gray-50 rounded-lg">
                <i class="fas fa-ticket-alt mr-3"></i>Tickets
            </a>
            <a href="invoices.php" class="flex items-center px-4 py-2 text-gray-600 hover:bg-gray-50 rounded-lg">
                <i class="fas fa-file-invoice-dollar mr-3"></i>Invoices
            </a>
            <a href="services.php" class="flex items-center px-4 py-2 text-gray-600 hover:bg-gray-50 rounded-lg">
                <i class="fas fa-server mr-3"></i>Services
            </a>
            <a href="products.php" class="flex items-center px-4 py-2 text-gray-600 hover:bg-gray-50 rounded-lg">
                <i class="fas fa-shopping-cart mr-3"></i>Products
            </a>
        </nav>
    </div>
</header>