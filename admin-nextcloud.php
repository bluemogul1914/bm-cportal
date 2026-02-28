<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    header('Location: /portal');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$nextcloud_url = getenv('NEXTCLOUD_URL') ?: '';
$nextcloud_token = getenv('NEXTCLOUD_TOKEN') ?: '';
$nextcloud_connected = !empty($nextcloud_url) && !empty($nextcloud_token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nextcloud Integration - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-cloud text-blue-500 mr-2"></i>Nextcloud Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">File sharing &mdash; Document syncing and collaboration</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($nextcloud_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($nextcloud_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-endpoint">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Nextcloud URL</p>
                    <?php if (!empty($nextcloud_url)): ?>
                        <p class="text-sm font-medium text-gray-900 break-all"><?php echo htmlspecialchars($nextcloud_url); ?></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">Not configured</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-token">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Token</p>
                    <?php if (!empty($nextcloud_token)): ?>
                        <p class="text-sm font-medium text-gray-900"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo substr($nextcloud_token, 0, 8); ?>...****</code></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">Not configured</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sync-alt text-blue-500 mr-2"></i>Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-folder-open text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Document Syncing</h3>
                                <p class="text-xs text-gray-500">Automatically sync client documents between Nextcloud and the Blue Mogul Portal. Keep invoices, contracts, and project files up to date across platforms.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-share-alt text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">File Sharing</h3>
                                <p class="text-xs text-gray-500">Share files securely with clients via Nextcloud share links. Generate password-protected, time-limited download links for sensitive documents.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-users text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Collaboration</h3>
                                <p class="text-xs text-gray-500">Enable real-time document collaboration with clients using Nextcloud Office. Co-edit spreadsheets, documents, and presentations.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-archive text-yellow-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Backup & Versioning</h3>
                                <p class="text-xs text-gray-500">Leverage Nextcloud&rsquo;s built-in file versioning for document history. Restore previous versions and maintain a complete audit trail of file changes.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">NEXTCLOUD_URL</p>
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
                            </div>
                            <?php if (!empty($nextcloud_url)): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-nextcloud-url"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-nextcloud-url"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">NEXTCLOUD_TOKEN</p>
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
                            </div>
                            <?php if (!empty($nextcloud_token)): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-nextcloud-token"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-nextcloud-token"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>