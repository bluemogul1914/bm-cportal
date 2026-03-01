<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$itflow_connected = !empty(getenv('ITFLOW_API_KEY'));
$itflow_url = ITFLOW_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITFlow Integration - Blue Mogul Admin</title>
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
            <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-ticket-alt text-primary mr-2"></i>ITFlow Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Professional Services Automation &mdash; Client & ticket management</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($itflow_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($itflow_connected): ?>
                        <p class="text-lg font-bold text-green-600"><span class="inline-block h-2.5 w-2.5 bg-green-500 rounded-full mr-1.5"></span>Active</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><span class="inline-block h-2.5 w-2.5 bg-red-500 rounded-full mr-1.5"></span>Inactive</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-key-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Key Status</p>
                    <?php if ($itflow_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Not Set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-last-sync">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Last Sync</p>
                    <p class="text-lg font-bold text-gray-400">Never</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-sync-mode">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Sync Mode</p>
                    <p class="text-lg font-bold text-gray-700">Manual</p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-api-endpoint">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-link text-primary mr-2"></i>API Endpoint</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase mb-1">ITFlow URL</p>
                            <p class="text-sm font-medium text-gray-900 break-all" data-testid="text-itflow-url"><?php echo htmlspecialchars($itflow_url); ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Key</p>
                            <?php if ($itflow_connected): ?>
                                <p class="text-sm font-medium text-gray-900" data-testid="text-api-key"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo substr(getenv('ITFLOW_API_KEY'), 0, 8); ?>...****</code></p>
                            <?php else: ?>
                                <p class="text-sm text-gray-500" data-testid="text-api-key">Not configured</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-capabilities">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sync-alt text-primary mr-2"></i>Sync Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex items-start gap-4" data-testid="capability-client-sync">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Client Sync</h3>
                                <p class="text-xs text-gray-500">Import and synchronize client records from ITFlow. Keep contact information, company details, and service agreements in sync between both platforms.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-ticket-sync">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-ticket-alt text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Ticket Sync</h3>
                                <p class="text-xs text-gray-500">Synchronize support tickets between ITFlow and Blue Mogul Portal. Track ticket status, priority, and resolution across both systems.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-asset-management">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-laptop text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Asset Management</h3>
                                <p class="text-xs text-gray-500">Sync hardware and software asset information from ITFlow&rsquo;s asset management module into the network documentation system.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-contact-management">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-address-book text-yellow-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Contact Management</h3>
                                <p class="text-xs text-gray-500">Pull contact records and communication history from ITFlow to maintain a unified view of all client interactions.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-features">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list-check text-primary mr-2"></i>Features</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="flex items-center gap-3 py-2" data-testid="feature-client-sync">
                            <span class="w-8 h-8 bg-blue-50 rounded flex items-center justify-center"><i class="fas fa-users text-blue-500 text-sm"></i></span>
                            <span class="text-sm font-medium text-gray-900">Client Sync</span>
                        </div>
                        <div class="flex items-center gap-3 py-2" data-testid="feature-ticket-sync">
                            <span class="w-8 h-8 bg-purple-50 rounded flex items-center justify-center"><i class="fas fa-ticket-alt text-purple-500 text-sm"></i></span>
                            <span class="text-sm font-medium text-gray-900">Ticket Sync</span>
                        </div>
                        <div class="flex items-center gap-3 py-2" data-testid="feature-asset-management">
                            <span class="w-8 h-8 bg-green-50 rounded flex items-center justify-center"><i class="fas fa-laptop text-green-500 text-sm"></i></span>
                            <span class="text-sm font-medium text-gray-900">Asset Management</span>
                        </div>
                        <div class="flex items-center gap-3 py-2" data-testid="feature-contact-management">
                            <span class="w-8 h-8 bg-yellow-50 rounded flex items-center justify-center"><i class="fas fa-address-book text-yellow-500 text-sm"></i></span>
                            <span class="text-sm font-medium text-gray-900">Contact Management</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-quick-actions">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-bolt text-primary mr-2"></i>Quick Actions</h2>
                </div>
                <div class="p-6">
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition" data-testid="button-sync-clients">
                            <i class="fas fa-users"></i> Sync Clients
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition" data-testid="button-sync-tickets">
                            <i class="fas fa-ticket-alt"></i> Sync Tickets
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition" data-testid="button-sync-assets">
                            <i class="fas fa-laptop"></i> Sync Assets
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition" data-testid="button-test-connection">
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition" data-testid="button-view-logs">
                            <i class="fas fa-file-alt"></i> View Logs
                        </button>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-configuration">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between flex-wrap gap-3 py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">ITFLOW_API_KEY</p>
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
                            </div>
                            <?php if ($itflow_connected): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-itflow-api-key"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-itflow-api-key"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between flex-wrap gap-3 py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">ITFLOW_URL</p>
                                <p class="text-xs text-gray-500">ITFlow instance URL</p>
                            </div>
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium" data-testid="env-itflow-url"><?php echo htmlspecialchars($itflow_url); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>