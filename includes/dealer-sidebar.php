<div class="w-64 bg-secondary text-white flex-shrink-0 flex flex-col">
    <div class="p-5 border-b border-gray-700">
        <div class="flex items-center space-x-3">
            <img src="/assets/img/bluemogul-logo.png" alt="Blue Mogul" class="h-9 w-auto">
            <div>
                <p class="text-xs font-semibold text-blue-300">Partner Portal</p>
                <?php if (!empty($dealer['referral_code'])): ?>
                <p class="text-xs text-gray-400">Code: <span class="font-mono text-yellow-300"><?= htmlspecialchars($dealer['referral_code']) ?></span></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto py-6 px-4">
        <?php $current_page = basename($_SERVER['PHP_SELF'] ?? ''); ?>
        <div class="space-y-1">
            <a href="dealer-dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-dashboard.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-dashboard">
                <i class="fas fa-tachometer-alt w-5"></i>
                <span>Dashboard</span>
            </a>

            <a href="dealer-orders.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-orders.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-orders">
                <i class="fas fa-clipboard-list w-5 text-green-400"></i>
                <span>Submit Order</span>
            </a>

            <a href="dealer-commissions.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-commissions.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-commissions">
                <i class="fas fa-dollar-sign w-5 text-yellow-400"></i>
                <span>Commissions</span>
            </a>

            <a href="dealer-customers.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-customers.php' || $current_page==='dealer-customer-detail.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-customers">
                <i class="fas fa-users w-5 text-purple-400"></i>
                <span>My Customers</span>
            </a>

            <a href="dealer-payouts.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-payouts.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-payouts">
                <i class="fas fa-money-bill-wave w-5 text-emerald-400"></i>
                <span>Payouts / ACH</span>
            </a>

            <a href="dealer-smtp.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-smtp.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-smtp">
                <i class="fas fa-envelope-open-text w-5 text-cyan-400"></i>
                <span>Email / SMTP</span>
            </a>

            <a href="dealer-training.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-training.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-training">
                <i class="fas fa-book-open w-5 text-orange-400"></i>
                <span>Training Docs</span>
            </a>
        </div>

        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Account</p>
            <div class="space-y-1">
                <a href="dealer-profile.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $current_page==='dealer-profile.php' ? 'bg-blue-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white transition' ?>" data-testid="link-dealer-profile">
                    <i class="fas fa-user-circle w-5"></i>
                    <span>My Profile</span>
                </a>
            </div>
        </div>

        <?php if ($_SESSION['is_admin'] ?? false): ?>
        <div class="mt-8">
            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Admin</p>
            <div class="space-y-1">
                <a href="admin-dealers.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg text-gray-300 hover:bg-gray-700 hover:text-white transition" data-testid="link-admin-dealers">
                    <i class="fas fa-handshake w-5 text-blue-400"></i>
                    <span>Dealers Dashboard</span>
                    <i class="fas fa-arrow-right ml-auto text-xs"></i>
                </a>
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
                <?= strtoupper(substr($user_name ?? 'D', 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($user_name ?? 'Dealer') ?></p>
                <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($user_email ?? '') ?></p>
            </div>
        </div>
        <a href="logout.php" class="flex items-center justify-center space-x-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm transition text-gray-300 hover:text-white" data-testid="link-dealer-logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Sign Out</span>
        </a>
    </div>
</div>

<style>
.bg-secondary { background-color: #0d1b3e; }
nav::-webkit-scrollbar { width: 4px; }
nav::-webkit-scrollbar-track { background: rgba(255,255,255,.05); }
nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 2px; }
</style>
