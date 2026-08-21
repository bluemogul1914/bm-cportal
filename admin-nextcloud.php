<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$nextcloud_url = defined('NEXTCLOUD_URL') ? NEXTCLOUD_URL : (getenv('NEXTCLOUD_URL') ?: '');
$nextcloud_user = defined('NEXTCLOUD_USER') ? NEXTCLOUD_USER : (getenv('NEXTCLOUD_USER') ?: '');
$nextcloud_password = defined('NEXTCLOUD_PASSWORD') ? NEXTCLOUD_PASSWORD : (getenv('NEXTCLOUD_PASSWORD') ?: '');
$nextcloud_connected = !empty($nextcloud_url) && !empty($nextcloud_user) && !empty($nextcloud_password);

$connection_status = $nextcloud_connected ? 'Connected' : 'Not Configured';
$total_documents = 0;
$recent_documents = [];
$documents_by_type = [];
$total_clients_with_docs = 0;
$storage_used = 'N/A';

try {
    $db = getDB();

    $stmt = $db->query("SELECT COUNT(*) as count FROM documents");
    $result = $stmt->fetch();
    $total_documents = $result['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(DISTINCT client_id) as count FROM documents");
    $result = $stmt->fetch();
    $total_clients_with_docs = $result['count'] ?? 0;

    $stmt = $db->query("SELECT d.*, c.name as client_name, c.company as client_company, u.name as uploader_name FROM documents d LEFT JOIN clients c ON d.client_id = c.id LEFT JOIN users u ON d.uploaded_by = u.id ORDER BY d.uploaded_at DESC LIMIT 10");
    $recent_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT COALESCE(file_type, 'unknown') as file_type, COUNT(*) as cnt FROM documents GROUP BY file_type ORDER BY cnt DESC LIMIT 8");
    $documents_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT COALESCE(SUM(file_size), 0) as total_size FROM documents");
    $result = $stmt->fetch();
    $total_bytes = $result['total_size'] ?? 0;
    if ($total_bytes > 0) {
        if ($total_bytes >= 1073741824) {
            $storage_used = round($total_bytes / 1073741824, 2) . ' GB';
        } elseif ($total_bytes >= 1048576) {
            $storage_used = round($total_bytes / 1048576, 2) . ' MB';
        } elseif ($total_bytes >= 1024) {
            $storage_used = round($total_bytes / 1024, 2) . ' KB';
        } else {
            $storage_used = $total_bytes . ' B';
        }
    }
} catch (Exception $e) {
    // tables may not exist yet
}
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
    <link rel="stylesheet" href="/assets/css/style.css">
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
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($nextcloud_connected): ?>
                        <p class="text-lg font-bold text-green-600" data-testid="text-connection-status"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">All credentials configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-yellow-600" data-testid="text-connection-status"><i class="fas fa-exclamation-circle mr-1"></i>Not Configured</p>
                        <p class="text-xs text-gray-400 mt-1">Missing credentials</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-documents">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Documents</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-documents"><?php echo (int)$total_documents; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Across all clients</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-clients-with-docs">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Clients with Documents</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-clients-with-docs"><?php echo (int)$total_clients_with_docs; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Active document portals</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-storage-used">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Storage Used</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-storage-used"><?php echo $storage_used; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Total file storage</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-recent-docs-title"><i class="fas fa-file-alt text-blue-500 mr-2"></i>Recent Documents</h2>
                            <span class="text-xs text-gray-400"><?php echo count($recent_documents); ?> most recent</span>
                        </div>
                        <?php if (!empty($recent_documents)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-recent-documents">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Document</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Uploaded By</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recent_documents as $index => $doc): ?>
                                        <?php
                                            $ext = strtolower(pathinfo($doc['file_name'] ?? '', PATHINFO_EXTENSION));
                                            $icon_map = [
                                                'pdf' => 'fa-file-pdf text-red-500',
                                                'doc' => 'fa-file-word text-blue-500',
                                                'docx' => 'fa-file-word text-blue-500',
                                                'xls' => 'fa-file-excel text-green-500',
                                                'xlsx' => 'fa-file-excel text-green-500',
                                                'png' => 'fa-file-image text-purple-500',
                                                'jpg' => 'fa-file-image text-purple-500',
                                                'jpeg' => 'fa-file-image text-purple-500',
                                                'zip' => 'fa-file-archive text-yellow-500',
                                                'txt' => 'fa-file-alt text-gray-500',
                                                'csv' => 'fa-file-csv text-green-600',
                                            ];
                                            $file_icon = $icon_map[$ext] ?? 'fa-file text-gray-400';
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="document-row-<?php echo $index; ?>">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <i class="fas <?php echo $file_icon; ?>"></i>
                                                    <span class="text-sm font-medium text-gray-900 truncate max-w-[200px]"><?php echo htmlspecialchars($doc['file_name'] ?? 'Untitled'); ?></span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($doc['client_company'] ?: ($doc['client_name'] ?? 'N/A')); ?></td>
                                            <td class="px-4 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-medium uppercase"><?php echo htmlspecialchars($doc['file_type'] ?? strtoupper($ext) ?: 'N/A'); ?></span></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($doc['uploader_name'] ?? 'System'); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-500"><?php echo isset($doc['uploaded_at']) ? date('M d, Y', strtotime($doc['uploaded_at'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500 text-sm">
                                <i class="fas fa-folder-open text-gray-300 text-2xl mb-2 block"></i>
                                No documents found in the system yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-doc-types-title"><i class="fas fa-chart-pie text-blue-500 mr-2"></i>Document Types</h2>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($documents_by_type)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($documents_by_type as $dt): ?>
                                        <?php
                                            $pct = $total_documents > 0 ? round(($dt['cnt'] / $total_documents) * 100) : 0;
                                            $type_colors = [
                                                'pdf' => 'bg-red-500',
                                                'docx' => 'bg-blue-500',
                                                'doc' => 'bg-blue-500',
                                                'xlsx' => 'bg-green-500',
                                                'xls' => 'bg-green-500',
                                                'png' => 'bg-purple-500',
                                                'jpg' => 'bg-purple-400',
                                                'jpeg' => 'bg-purple-400',
                                                'txt' => 'bg-gray-500',
                                                'csv' => 'bg-green-600',
                                                'zip' => 'bg-yellow-500',
                                            ];
                                            $bar_color = $type_colors[strtolower($dt['file_type'])] ?? 'bg-blue-400';
                                        ?>
                                        <div data-testid="doc-type-<?php echo htmlspecialchars(strtolower($dt['file_type'] ?? ''), ENT_QUOTES); ?>">
                                            <div class="flex items-center justify-between text-sm mb-1">
                                                <span class="text-gray-700 font-medium uppercase"><?php echo htmlspecialchars($dt['file_type']); ?></span>
                                                <span class="text-gray-500"><?php echo $dt['cnt']; ?> (<?php echo $pct; ?>%)</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-2">
                                                <div class="<?php echo $bar_color; ?> rounded-full h-2" style="width: <?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center py-4">No document data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-capabilities-heading"><i class="fas fa-star text-blue-500 mr-2"></i>Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="flex items-start gap-4" data-testid="capability-document-sync">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-sync-alt text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Document Syncing</h3>
                                <p class="text-xs text-gray-500">Automatically sync client documents between Nextcloud and the Blue Mogul Portal. Keep invoices, contracts, and project files up to date.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-shared-folders">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-folder-open text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Shared Folders</h3>
                                <p class="text-xs text-gray-500">Create and manage shared folders for each client. Control access permissions, set expiration dates, and organize by project or category.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-version-control">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-history text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Version Control</h3>
                                <p class="text-xs text-gray-500">Track all changes to documents with full version history. Restore previous versions and maintain a complete audit trail of modifications.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-client-portals">
                            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user-circle text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Client Portals</h3>
                                <p class="text-xs text-gray-500">Provide clients with secure access to their documents through the portal. Auto-provision folders when new clients are created.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <?php if (!$nextcloud_connected): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-setup-title"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Setup Instructions</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Set your Nextcloud URL</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Add <code class="bg-gray-100 px-1 rounded text-xs">NEXTCLOUD_URL</code> to Replit Secrets with your Nextcloud instance URL (e.g., <code class="bg-gray-100 px-1 rounded text-xs">https://cloud.yourdomain.com</code>).</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Add Admin Credentials</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Add <code class="bg-gray-100 px-1 rounded text-xs">NEXTCLOUD_USER</code> and <code class="bg-gray-100 px-1 rounded text-xs">NEXTCLOUD_PASSWORD</code> to Replit Secrets. Use an app password for enhanced security.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Verify Connection</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Refresh this page after adding all three secrets. The status should change to &ldquo;Connected&rdquo; and document sync will be available.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">4</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Enable WebDAV Access</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Ensure WebDAV is enabled on your Nextcloud instance for seamless file access from any compatible client application.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-additional-features-title"><i class="fas fa-bolt text-blue-500 mr-2"></i>Additional Features</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-4" data-testid="feature-webdav">
                                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-plug text-yellow-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 mb-1">WebDAV Integration</h3>
                                    <p class="text-xs text-gray-500">Connect via WebDAV protocol for seamless file access. Mount Nextcloud as a network drive on desktops and mobile devices.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-4" data-testid="feature-automatic-backup">
                                <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-shield-alt text-red-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Automatic Backup</h3>
                                    <p class="text-xs text-gray-500">Schedule automatic backups of critical client files. Ensure data redundancy and disaster recovery with automated sync.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-4" data-testid="feature-collaborative-editing">
                                <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-users text-teal-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Collaborative Editing</h3>
                                    <p class="text-xs text-gray-500">Enable real-time collaborative document editing through Nextcloud Office integration with your team and clients.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-config-heading"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
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
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">Sync Mode</p>
                                <p class="text-xs text-gray-500">Document synchronization method</p>
                            </div>
                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-medium" data-testid="text-sync-mode">Manual Upload</span>
                        </div>
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">WebDAV Endpoint</p>
                                <p class="text-xs text-gray-500">Remote file access protocol endpoint</p>
                            </div>
                            <?php if (!empty($nextcloud_url)): ?>
                                <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium" data-testid="text-webdav-endpoint"><?php echo htmlspecialchars($nextcloud_url); ?>/remote.php/dav</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded text-xs font-medium" data-testid="text-webdav-endpoint">Not configured</span>
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