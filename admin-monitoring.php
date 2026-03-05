<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$uptime_kuma_url = defined('UPTIME_KUMA_URL') ? UPTIME_KUMA_URL : (getenv('UPTIME_KUMA_URL') ?: '');
$grafana_url = defined('GRAFANA_URL') ? GRAFANA_URL : (getenv('GRAFANA_URL') ?: '');

$kuma_configured = !empty($uptime_kuma_url);
$grafana_configured = !empty($grafana_url);

$active_tab = $_GET['tab'] ?? 'uptime';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-heartbeat text-red-500 mr-2"></i>Monitoring
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Uptime monitoring and infrastructure dashboards</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($kuma_configured): ?>
                    <a href="<?php echo htmlspecialchars($uptime_kuma_url); ?>" target="_blank" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition" data-testid="link-kuma-external">
                        <i class="fas fa-external-link-alt mr-2"></i>Open Uptime Kuma
                    </a>
                    <?php endif; ?>
                    <?php if ($grafana_configured): ?>
                    <a href="<?php echo htmlspecialchars($grafana_url); ?>" target="_blank" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-md text-sm font-medium transition" data-testid="link-grafana-external">
                        <i class="fas fa-external-link-alt mr-2"></i>Open Grafana
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="px-6 flex items-center gap-1 border-t border-gray-100">
                <a href="?tab=uptime" class="px-4 py-3 text-sm font-medium border-b-2 transition <?php echo $active_tab === 'uptime' ? 'border-green-600 text-green-700' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>" data-testid="tab-uptime">
                    <i class="fas fa-signal mr-1"></i>Uptime Kuma
                </a>
                <a href="?tab=grafana" class="px-4 py-3 text-sm font-medium border-b-2 transition <?php echo $active_tab === 'grafana' ? 'border-orange-600 text-orange-700' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>" data-testid="tab-grafana">
                    <i class="fas fa-chart-area mr-1"></i>Grafana
                </a>
                <a href="?tab=overview" class="px-4 py-3 text-sm font-medium border-b-2 transition <?php echo $active_tab === 'overview' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>" data-testid="tab-overview">
                    <i class="fas fa-th-large mr-1"></i>Overview
                </a>
            </div>
        </header>

        <div class="p-6">
            <?php if ($active_tab === 'uptime'): ?>
                <?php if ($kuma_configured): ?>
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden" style="height: calc(100vh - 200px);">
                    <iframe src="<?php echo htmlspecialchars($uptime_kuma_url); ?>/dashboard" class="w-full h-full border-0" title="Uptime Kuma Dashboard" data-testid="iframe-uptime-kuma" allow="fullscreen"></iframe>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-signal text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Uptime Kuma Not Configured</h3>
                    <p class="text-gray-500 mb-6 max-w-md mx-auto">Set the <code class="bg-gray-100 px-2 py-0.5 rounded text-sm">UPTIME_KUMA_URL</code> environment variable to your Uptime Kuma instance URL to embed the dashboard here.</p>
                    <div class="bg-gray-50 rounded-lg p-4 max-w-sm mx-auto text-left">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Example</p>
                        <code class="text-sm text-gray-700">UPTIME_KUMA_URL=https://status.yourdomain.com</code>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($active_tab === 'grafana'): ?>
                <?php if ($grafana_configured): ?>
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden" style="height: calc(100vh - 200px);">
                    <iframe src="<?php echo htmlspecialchars($grafana_url); ?>/dashboards" class="w-full h-full border-0" title="Grafana Dashboards" data-testid="iframe-grafana" allow="fullscreen"></iframe>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                    <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-chart-area text-orange-600 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Grafana Not Configured</h3>
                    <p class="text-gray-500 mb-6 max-w-md mx-auto">Set the <code class="bg-gray-100 px-2 py-0.5 rounded text-sm">GRAFANA_URL</code> environment variable to your Grafana instance URL to embed dashboards here.</p>
                    <div class="bg-gray-50 rounded-lg p-4 max-w-sm mx-auto text-left">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Example</p>
                        <code class="text-sm text-gray-700">GRAFANA_URL=https://grafana.yourdomain.com</code>
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-signal text-green-600"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">Uptime Kuma</h3>
                                    <p class="text-xs text-gray-500">Service uptime monitoring</p>
                                </div>
                            </div>
                            <?php if ($kuma_configured): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-kuma"><i class="fas fa-check-circle mr-1"></i>Connected</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-kuma"><i class="fas fa-exclamation-circle mr-1"></i>Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($kuma_configured): ?>
                        <p class="text-sm text-gray-600 mb-3">URL: <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs"><?php echo htmlspecialchars($uptime_kuma_url); ?></code></p>
                        <div class="flex gap-2">
                            <a href="?tab=uptime" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-medium transition">View Dashboard</a>
                            <a href="<?php echo htmlspecialchars($uptime_kuma_url); ?>" target="_blank" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-sm font-medium transition"><i class="fas fa-external-link-alt mr-1"></i>Open</a>
                        </div>
                        <?php else: ?>
                        <p class="text-sm text-gray-500">Add <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">UPTIME_KUMA_URL</code> to configure.</p>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-chart-area text-orange-600"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">Grafana</h3>
                                    <p class="text-xs text-gray-500">Infrastructure dashboards</p>
                                </div>
                            </div>
                            <?php if ($grafana_configured): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-grafana"><i class="fas fa-check-circle mr-1"></i>Connected</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-grafana"><i class="fas fa-exclamation-circle mr-1"></i>Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($grafana_configured): ?>
                        <p class="text-sm text-gray-600 mb-3">URL: <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs"><?php echo htmlspecialchars($grafana_url); ?></code></p>
                        <div class="flex gap-2">
                            <a href="?tab=grafana" class="px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded text-sm font-medium transition">View Dashboards</a>
                            <a href="<?php echo htmlspecialchars($grafana_url); ?>" target="_blank" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-sm font-medium transition"><i class="fas fa-external-link-alt mr-1"></i>Open</a>
                        </div>
                        <?php else: ?>
                        <p class="text-sm text-gray-500">Add <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">GRAFANA_URL</code> to configure.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="font-semibold text-gray-900"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Monitoring Tools</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-medium text-gray-900 mb-2"><i class="fas fa-signal text-green-500 mr-2"></i>Uptime Kuma</h4>
                                <p class="text-sm text-gray-600 mb-3">Open-source uptime monitoring tool that tracks the availability of your services, websites, and infrastructure with real-time alerts.</p>
                                <ul class="text-sm text-gray-500 space-y-1">
                                    <li><i class="fas fa-check text-green-400 mr-2"></i>HTTP(s), TCP, DNS, MQTT monitoring</li>
                                    <li><i class="fas fa-check text-green-400 mr-2"></i>Custom status pages</li>
                                    <li><i class="fas fa-check text-green-400 mr-2"></i>Notification channels (Email, Slack, Discord, Telegram)</li>
                                    <li><i class="fas fa-check text-green-400 mr-2"></i>Certificate expiry tracking</li>
                                    <li><i class="fas fa-check text-green-400 mr-2"></i>Maintenance windows</li>
                                </ul>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-900 mb-2"><i class="fas fa-chart-area text-orange-500 mr-2"></i>Grafana</h4>
                                <p class="text-sm text-gray-600 mb-3">Open-source analytics and interactive visualization platform for infrastructure metrics, logs, and application data.</p>
                                <ul class="text-sm text-gray-500 space-y-1">
                                    <li><i class="fas fa-check text-orange-400 mr-2"></i>Custom dashboards and panels</li>
                                    <li><i class="fas fa-check text-orange-400 mr-2"></i>Prometheus, InfluxDB, MySQL data sources</li>
                                    <li><i class="fas fa-check text-orange-400 mr-2"></i>Alerting rules and notifications</li>
                                    <li><i class="fas fa-check text-orange-400 mr-2"></i>Team sharing and annotations</li>
                                    <li><i class="fas fa-check text-orange-400 mr-2"></i>Log aggregation and analysis</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mt-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-900">UPTIME_KUMA_URL</p>
                                    <?php if ($kuma_configured): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-kuma-url"><i class="fas fa-check mr-1"></i>Set</span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="env-kuma-url"><i class="fas fa-exclamation-triangle mr-1"></i>Not Set</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500">Your Uptime Kuma instance URL</p>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-900">GRAFANA_URL</p>
                                    <?php if ($grafana_configured): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-grafana-url"><i class="fas fa-check mr-1"></i>Set</span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="env-grafana-url"><i class="fas fa-exclamation-triangle mr-1"></i>Not Set</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500">Your Grafana instance URL</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>