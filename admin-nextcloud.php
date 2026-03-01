<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$nextcloud_url = getenv('NEXTCLOUD_URL') ?: '';
$nextcloud_user = getenv('NEXTCLOUD_USER') ?: '';
$nextcloud_password = getenv('NEXTCLOUD_PASSWORD') ?: '';
$nextcloud_connected = !empty($nextcloud_url) && !empty($nextcloud_user) && !empty($nextcloud_password);

$connection_status = $nextcloud_connected ? 'Connected' : 'Not Configured';
$documents_synced = 0;
$shared_folders = 0;
$storage_used = 'N/A';
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
        <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-cloud text-blue-500 mr-2"></i>Nextcloud Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">File sharing &mdash; Document syncing and collaboration</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($nextcloud_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-not-configured"><i class="fas fa-circle text-[8px] mr-1"></i>Not Configured</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($nextcloud_connected): ?>
                        <p class="text-lg font-bold text-green-600" data-testid="text-connection-status"><i class="fas fa-check-circle mr-1"></i>Active</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-yellow-600" data-testid="text-connection-status"><i class="fas fa-exclamation-circle mr-1"></i>Not Configured</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-documents-synced">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Documents Synced</p>
                    <p class="text-lg font-bold text-gray-900" data-testid="text-documents-synced"><?php echo $documents_synced; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-shared-folders">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Shared Folders</p>
                    <p class="text-lg font-bold text-gray-900" data-testid="text-shared-folders"><?php echo $shared_folders; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-storage-used">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Storage Used</p>
                    <p class="text-lg font-bold text-gray-900" data-testid="text-storage-used"><?php echo $storage_used; ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-features-heading"><i class="fas fa-star text-blue-500 mr-2"></i>Features</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex items-start gap-4" data-testid="feature-document-sync">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-sync-alt text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Document Sync to Client Portal</h3>
                                <p class="text-xs text-gray-500">Automatically sync client documents between Nextcloud and the Blue Mogul Portal. Keep invoices, contracts, and project files up to date across platforms.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="feature-shared-folder">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-folder-open text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Shared Folder Management</h3>
                                <p class="text-xs text-gray-500">Create and manage shared folders for each client. Control access permissions, set expiration dates, and organize documents by project or category.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="feature-version-history">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-history text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">File Version History</h3>
                                <p class="text-xs text-gray-500">Track all changes to documents with full version history. Restore previous versions and maintain a complete audit trail of file modifications.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="feature-webdav">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-plug text-yellow-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">WebDAV Integration</h3>
                                <p class="text-xs text-gray-500">Connect via WebDAV protocol for seamless file access from any compatible client. Mount Nextcloud as a network drive on desktops and mobile devices.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="feature-automatic-backup">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-shield-alt text-red-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Automatic Backup</h3>
                                <p class="text-xs text-gray-500">Schedule automatic backups of critical client files to Nextcloud. Ensure data redundancy and disaster recovery with automated sync schedules.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-config-heading"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                    <button class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition" data-testid="button-configure" disabled>Configure</button>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">NEXTCLOUD_URL</p>
                                <p class="text-xs text-gray-500">The URL of your Nextcloud instance &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if (!empty($nextcloud_url)): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-nextcloud-url"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="env-nextcloud-url"><i class="fas fa-exclamation-triangle mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">NEXTCLOUD_USER</p>
                                <p class="text-xs text-gray-500">Admin username for Nextcloud API access &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if (!empty($nextcloud_user)): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-nextcloud-user"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="env-nextcloud-user"><i class="fas fa-exclamation-triangle mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">NEXTCLOUD_PASSWORD</p>
                                <p class="text-xs text-gray-500">Admin password or app token for Nextcloud API &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if (!empty($nextcloud_password)): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-nextcloud-password"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="env-nextcloud-password"><i class="fas fa-exclamation-triangle mr-1"></i>Not Set</span>
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
