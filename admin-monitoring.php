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
// Embeddable URLs — status page (Kuma) and specific dashboard (Grafana)
$uptime_kuma_embed_url = defined('UPTIME_KUMA_EMBED_URL') ? UPTIME_KUMA_EMBED_URL : (getenv('UPTIME_KUMA_EMBED_URL') ?: $uptime_kuma_url);
$grafana_embed_url = defined('GRAFANA_EMBED_URL') ? GRAFANA_EMBED_URL : (getenv('GRAFANA_EMBED_URL') ?: $grafana_url);

$kuma_configured = !empty($uptime_kuma_url);
$grafana_configured = !empty($grafana_url);

function check_service_health($url) {
    if (empty($url)) return ['configured' => false, 'reachable' => false];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'configured' => true,
        'reachable' => ($code >= 200 && $code < 500),
        'status_code' => $code,
        'response_time' => $total_time,
        'error' => $error,
    ];
}

$kuma_health = check_service_health($uptime_kuma_url);
$grafana_health = check_service_health($grafana_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-green-50 to-white flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-signal text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Uptime Kuma</h3>
                                <p class="text-xs text-gray-500">Service uptime monitoring</p>
                            </div>
                        </div>
                        <?php if ($kuma_health['configured'] && $kuma_health['reachable']): ?>
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-kuma">
                            <i class="fas fa-check-circle mr-1"></i>Online
                        </span>
                        <?php elseif ($kuma_health['configured']): ?>
                        <span class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-kuma">
                            <i class="fas fa-times-circle mr-1"></i>Unreachable
                        </span>
                        <?php else: ?>
                        <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-kuma">
                            <i class="fas fa-exclamation-circle mr-1"></i>Not Configured
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="p-6">
                        <?php if ($kuma_configured): ?>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">URL</span>
                                <code class="bg-gray-100 px-2 py-0.5 rounded text-xs text-gray-700"><?php echo htmlspecialchars($uptime_kuma_url); ?></code>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Status</span>
                                <span class="font-medium <?php echo $kuma_health['reachable'] ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $kuma_health['reachable'] ? 'Reachable' : 'Unreachable'; ?>
                                    <?php if ($kuma_health['status_code']): ?>(HTTP <?php echo $kuma_health['status_code']; ?>)<?php endif; ?>
                                </span>
                            </div>
                            <?php if ($kuma_health['reachable']): ?>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Response Time</span>
                                <span class="font-medium text-gray-900"><?php echo $kuma_health['response_time']; ?>ms</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($kuma_health['error']): ?>
                            <div class="bg-red-50 text-red-700 px-3 py-2 rounded text-xs"><?php echo htmlspecialchars($kuma_health['error']); ?></div>
                            <?php endif; ?>
                            <div class="pt-3 border-t border-gray-100">
                                <a href="<?php echo htmlspecialchars($uptime_kuma_url); ?>" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition w-full justify-center" data-testid="button-open-kuma">
                                    <i class="fas fa-external-link-alt"></i>Open Uptime Kuma Dashboard
                                </a>
                                <p class="text-xs text-gray-400 mt-2 text-center">Opens in a new tab. Iframe embedding is blocked by Uptime Kuma's security settings.</p>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <p class="text-gray-500 text-sm mb-3">Set the <code class="bg-gray-100 px-2 py-0.5 rounded text-xs">UPTIME_KUMA_URL</code> environment variable to connect.</p>
                            <div class="bg-gray-50 rounded-lg p-3 text-left">
                                <p class="text-xs font-semibold text-gray-500 mb-1">Example</p>
                                <code class="text-xs text-gray-700">UPTIME_KUMA_URL=https://status.yourdomain.com</code>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-orange-50 to-white flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-chart-area text-orange-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Grafana</h3>
                                <p class="text-xs text-gray-500">Infrastructure dashboards</p>
                            </div>
                        </div>
                        <?php if ($grafana_health['configured'] && $grafana_health['reachable']): ?>
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-grafana">
                            <i class="fas fa-check-circle mr-1"></i>Online
                        </span>
                        <?php elseif ($grafana_health['configured']): ?>
                        <span class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-grafana">
                            <i class="fas fa-times-circle mr-1"></i>Unreachable
                        </span>
                        <?php else: ?>
                        <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-grafana">
                            <i class="fas fa-exclamation-circle mr-1"></i>Not Configured
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="p-6">
                        <?php if ($grafana_configured): ?>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">URL</span>
                                <code class="bg-gray-100 px-2 py-0.5 rounded text-xs text-gray-700"><?php echo htmlspecialchars($grafana_url); ?></code>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Status</span>
                                <span class="font-medium <?php echo $grafana_health['reachable'] ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $grafana_health['reachable'] ? 'Reachable' : 'Unreachable'; ?>
                                    <?php if ($grafana_health['status_code']): ?>(HTTP <?php echo $grafana_health['status_code']; ?>)<?php endif; ?>
                                </span>
                            </div>
                            <?php if ($grafana_health['reachable']): ?>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Response Time</span>
                                <span class="font-medium text-gray-900"><?php echo $grafana_health['response_time']; ?>ms</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($grafana_health['error']): ?>
                            <div class="bg-red-50 text-red-700 px-3 py-2 rounded text-xs"><?php echo htmlspecialchars($grafana_health['error']); ?></div>
                            <?php endif; ?>
                            <div class="pt-3 border-t border-gray-100">
                                <a href="<?php echo htmlspecialchars($grafana_url); ?>" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-md text-sm font-medium transition w-full justify-center" data-testid="button-open-grafana">
                                    <i class="fas fa-external-link-alt"></i>Open Grafana Dashboard
                                </a>
                                <p class="text-xs text-gray-400 mt-2 text-center">Opens in a new tab. Iframe embedding is blocked by Grafana's security settings.</p>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <p class="text-gray-500 text-sm mb-3">Set the <code class="bg-gray-100 px-2 py-0.5 rounded text-xs">GRAFANA_URL</code> environment variable to connect.</p>
                            <div class="bg-gray-50 rounded-lg p-3 text-left">
                                <p class="text-xs font-semibold text-gray-500 mb-1">Example</p>
                                <code class="text-xs text-gray-700">GRAFANA_URL=https://grafana.yourdomain.com</code>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($kuma_configured && $kuma_health['reachable']): ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900"><i class="fas fa-signal text-green-500 mr-2"></i>Uptime Kuma — Quick Links</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <a href="<?php echo htmlspecialchars($uptime_kuma_url); ?>" target="_blank" class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition" data-testid="link-kuma-dashboard">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center"><i class="fas fa-tachometer-alt text-green-600"></i></div>
                            <div><p class="font-medium text-gray-900 text-sm">Dashboard</p><p class="text-xs text-gray-500">Main monitoring view</p></div>
                        </a>
                        <a href="<?php echo htmlspecialchars($uptime_kuma_url); ?>/status" target="_blank" class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition" data-testid="link-kuma-status">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center"><i class="fas fa-list-check text-green-600"></i></div>
                            <div><p class="font-medium text-gray-900 text-sm">Status Page</p><p class="text-xs text-gray-500">Public status overview</p></div>
                        </a>
                        <a href="<?php echo htmlspecialchars($uptime_kuma_url); ?>/settings" target="_blank" class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition" data-testid="link-kuma-settings">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center"><i class="fas fa-cog text-green-600"></i></div>
                            <div><p class="font-medium text-gray-900 text-sm">Settings</p><p class="text-xs text-gray-500">Configure monitors</p></div>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($grafana_configured && $grafana_health['reachable']): ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900"><i class="fas fa-chart-area text-orange-500 mr-2"></i>Grafana — Quick Links</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <a href="<?php echo htmlspecialchars($grafana_url); ?>" target="_blank" class="flex items-center gap-3 p-4 bg-orange-50 border border-orange-200 rounded-lg hover:bg-orange-100 transition" data-testid="link-grafana-home">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center"><i class="fas fa-home text-orange-600"></i></div>
                            <div><p class="font-medium text-gray-900 text-sm">Home</p><p class="text-xs text-gray-500">Grafana home page</p></div>
                        </a>
                        <a href="<?php echo htmlspecialchars($grafana_url); ?>/dashboards" target="_blank" class="flex items-center gap-3 p-4 bg-orange-50 border border-orange-200 rounded-lg hover:bg-orange-100 transition" data-testid="link-grafana-dashboards">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center"><i class="fas fa-chart-bar text-orange-600"></i></div>
                            <div><p class="font-medium text-gray-900 text-sm">Dashboards</p><p class="text-xs text-gray-500">View all dashboards</p></div>
                        </a>
                        <a href="<?php echo htmlspecialchars($grafana_url); ?>/alerting" target="_blank" class="flex items-center gap-3 p-4 bg-orange-50 border border-orange-200 rounded-lg hover:bg-orange-100 transition" data-testid="link-grafana-alerting">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center"><i class="fas fa-bell text-orange-600"></i></div>
                            <div><p class="font-medium text-gray-900 text-sm">Alerting</p><p class="text-xs text-gray-500">Alert rules & history</p></div>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($kuma_configured): ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6 overflow-hidden" id="kuma-iframe-wrap">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900"><i class="fas fa-signal text-green-500 mr-2"></i>Uptime Kuma — Embedded View</h3>
                    <div class="flex items-center gap-3">
                        <span id="kuma-iframe-status" class="text-xs text-gray-400">Loading…</span>
                        <a href="<?= htmlspecialchars($uptime_kuma_url) ?>" target="_blank" class="text-xs text-blue-600 hover:underline">Open in new tab</a>
                    </div>
                </div>
                <div id="kuma-iframe-blocked" class="hidden p-6 bg-yellow-50 border-b border-yellow-200">
                    <p class="text-sm text-yellow-800 font-medium mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>Iframe blocked by browser security policy</p>
                    <p class="text-sm text-yellow-700 mb-3">To embed Uptime Kuma directly in this portal, either:</p>
                    <ul class="text-sm text-yellow-700 space-y-1 mb-3 ml-4 list-disc">
                        <li>Set <code class="bg-yellow-100 px-1 rounded">UPTIME_KUMA_EMBED_URL</code> to your <strong>public status page</strong> URL (e.g., <code class="bg-yellow-100 px-1 rounded">https://status.yourdomain.com/status/your-page</code>) — these are embeddable by default</li>
                        <li>Or configure Uptime Kuma to allow iframe embedding from your portal domain</li>
                    </ul>
                </div>
                <div id="kuma-iframe-container" style="height:600px;">
                    <iframe id="kuma-iframe" src="<?= htmlspecialchars($uptime_kuma_embed_url) ?>" width="100%" height="100%" style="border:none;display:block;" onload="iframeLoaded('kuma')" onerror="iframeBlocked('kuma')"></iframe>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($grafana_configured): ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6 overflow-hidden" id="grafana-iframe-wrap">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900"><i class="fas fa-chart-area text-orange-500 mr-2"></i>Grafana — Embedded Dashboard</h3>
                    <div class="flex items-center gap-3">
                        <span id="grafana-iframe-status" class="text-xs text-gray-400">Loading…</span>
                        <a href="<?= htmlspecialchars($grafana_url) ?>" target="_blank" class="text-xs text-blue-600 hover:underline">Open in new tab</a>
                    </div>
                </div>
                <div id="grafana-iframe-blocked" class="hidden p-6 bg-yellow-50 border-b border-yellow-200">
                    <p class="text-sm text-yellow-800 font-medium mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>Iframe blocked by browser security policy</p>
                    <p class="text-sm text-yellow-700 mb-3">To embed Grafana directly:</p>
                    <ul class="text-sm text-yellow-700 space-y-1 mb-3 ml-4 list-disc">
                        <li>Add <code class="bg-yellow-100 px-1 rounded">allow_embedding = true</code> to your <code class="bg-yellow-100 px-1 rounded">grafana.ini</code> under <code class="bg-yellow-100 px-1 rounded">[security]</code></li>
                        <li>Set <code class="bg-yellow-100 px-1 rounded">GRAFANA_EMBED_URL</code> to a specific public dashboard URL</li>
                        <li>Or enable anonymous access for a read-only dashboard view</li>
                    </ul>
                </div>
                <div id="grafana-iframe-container" style="height:600px;">
                    <iframe id="grafana-iframe" src="<?= htmlspecialchars($grafana_embed_url) ?>" width="100%" height="100%" style="border:none;display:block;" onload="iframeLoaded('grafana')" onerror="iframeBlocked('grafana')"></iframe>
                </div>
            </div>
            <?php endif; ?>

            <script>
            function iframeLoaded(name) {
                try {
                    var frame = document.getElementById(name + '-iframe');
                    var doc = frame.contentDocument || frame.contentWindow.document;
                    if (!doc || doc.body === null || doc.body.innerHTML === '') {
                        iframeBlocked(name);
                    } else {
                        document.getElementById(name + '-iframe-status').textContent = 'Loaded';
                        document.getElementById(name + '-iframe-status').className = 'text-xs text-green-600';
                    }
                } catch(e) {
                    iframeBlocked(name);
                }
            }
            function iframeBlocked(name) {
                document.getElementById(name + '-iframe-status').textContent = 'Blocked — see instructions below';
                document.getElementById(name + '-iframe-status').className = 'text-xs text-yellow-600';
                document.getElementById(name + '-iframe-blocked').classList.remove('hidden');
                document.getElementById(name + '-iframe-container').style.height = '80px';
                document.getElementById(name + '-iframe-container').innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">Unable to load embedded view. Use the link above to open in a new tab.</div>';
            }
            setTimeout(function() {
                <?php if ($kuma_configured): ?>
                try { iframeLoaded('kuma'); } catch(e) {}
                <?php endif; ?>
                <?php if ($grafana_configured): ?>
                try { iframeLoaded('grafana'); } catch(e) {}
                <?php endif; ?>
            }, 3000);
            </script>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="font-semibold text-gray-900"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About These Tools</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="font-medium text-gray-900 mb-2"><i class="fas fa-signal text-green-500 mr-2"></i>Uptime Kuma</h4>
                            <p class="text-sm text-gray-600 mb-3">Open-source uptime monitoring tool that tracks the availability of your services with real-time alerts.</p>
                            <ul class="text-sm text-gray-500 space-y-1">
                                <li><i class="fas fa-check text-green-400 mr-2"></i>HTTP(s), TCP, DNS monitoring</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Custom status pages</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Multi-channel notifications</li>
                                <li><i class="fas fa-check text-green-400 mr-2"></i>Certificate expiry tracking</li>
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-gray-900 mb-2"><i class="fas fa-chart-area text-orange-500 mr-2"></i>Grafana</h4>
                            <p class="text-sm text-gray-600 mb-3">Analytics and visualization platform for infrastructure metrics, logs, and application data.</p>
                            <ul class="text-sm text-gray-500 space-y-1">
                                <li><i class="fas fa-check text-orange-400 mr-2"></i>Custom dashboards and panels</li>
                                <li><i class="fas fa-check text-orange-400 mr-2"></i>Prometheus, InfluxDB sources</li>
                                <li><i class="fas fa-check text-orange-400 mr-2"></i>Alerting rules</li>
                                <li><i class="fas fa-check text-orange-400 mr-2"></i>Log aggregation</li>
                            </ul>
                        </div>
                    </div>
                    <?php if (!$kuma_configured || !$grafana_configured): ?>
                    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <p class="text-sm text-blue-700"><i class="fas fa-lightbulb mr-2"></i><strong>Tip:</strong> To enable iframe embedding, configure your Uptime Kuma/Grafana instances to allow your portal domain in their <code class="bg-blue-100 px-1 py-0.5 rounded text-xs">X-Frame-Options</code> or <code class="bg-blue-100 px-1 py-0.5 rounded text-xs">Content-Security-Policy</code> headers.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>