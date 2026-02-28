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

            <a href="documents.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?php echo ($current_page == 'documents.php') ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition'; ?>">
                <i class="fas fa-folder w-5"></i>
                <span>Documents</span>
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