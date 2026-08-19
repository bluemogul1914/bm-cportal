<?php
// Shared admin top bar for the admin-header include pattern.
if (!isset($user_name)) { $user_name = $_SESSION['user_name'] ?? 'Admin'; }
$page_title = $page_title ?? 'Admin';
?>
<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900"><?php echo htmlspecialchars($page_title); ?></h1>
            </div>
            <div class="flex items-center space-x-3">
                <div class="relative">
                    <button onclick="toggleAdminProfile()" class="flex items-center space-x-2 text-gray-700 hover:bg-gray-100 rounded-md px-3 py-2 transition">
                        <div class="bg-blue-600 text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                        <span class="text-sm font-medium"><?php echo htmlspecialchars($user_name); ?></span>
                        <i class="fas fa-chevron-down text-xs"></i>
                    </button>
                    <div id="admin-profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-20">
                        <a href="dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-arrow-left w-4 mr-2"></i>Client View</a>
                        <a href="admin-settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-cog w-4 mr-2"></i>Settings</a>
                        <div class="border-t border-gray-200"></div>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-50"><i class="fas fa-sign-out-alt w-4 mr-2"></i>Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
