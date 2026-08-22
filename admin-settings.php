<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

require_once 'includes/email.php';

$test_email_result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['section'] ?? '') === 'test_email') {
    require_csrf();
    
    $test_to = trim($_POST['test_email_to'] ?? '');
    if (!empty($test_to)) {
        $test_email_result = send_test_email($test_to);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $section = $_POST['section'] ?? '';

    try {
        $pdo = getDB();

        if ($section === 'company') {
            $settings = [
                'company_name' => $_POST['company_name'],
                'company_email' => $_POST['company_email'],
                'company_phone' => $_POST['company_phone']
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()");
                $stmt->execute([$key, $value]);
            }
            $success_message = "Company settings updated successfully!";
        } elseif ($section === 'api') {
            $api_keys = [
                'stripe_public_key' => $_POST['stripe_public_key'],
                'stripe_secret_key' => $_POST['stripe_secret_key'],
                'voip_ms_user' => $_POST['voip_ms_user'],
                'voip_ms_pass' => $_POST['voip_ms_pass'],
                'itflow_url' => $_POST['itflow_url'],
                'itflow_api_key' => $_POST['itflow_api_key']
            ];
            foreach ($api_keys as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()");
                $stmt->execute([$key, $value]);
            }
            $success_message = "API keys updated successfully!";
        } elseif ($section === 'email') {
            $email_settings = [
                'smtp_host' => $_POST['smtp_host'],
                'smtp_port' => $_POST['smtp_port'],
                'smtp_user' => $_POST['smtp_user'],
                'smtp_pass' => $_POST['smtp_pass'],
                'from_email' => $_POST['from_email'],
                'from_name' => $_POST['from_name']
            ];
            foreach ($email_settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()");
                $stmt->execute([$key, $value]);
            }
            $success_message = "Email settings updated successfully!";
        }
    } catch (PDOException $e) {
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}

try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $all_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $all_settings = [];
}

function getSetting($key, $default = '') {
    global $all_settings;
    return $all_settings[$key] ?? $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Blue Mogul Admin</title>

    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#1a56db', secondary: '#0d1b3e' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">

    <div class="flex h-screen overflow-hidden">

        <?php include 'includes/admin-sidebar.php'; ?>

        <div class="flex-1 overflow-y-auto">

            <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900">System Settings</h1>
                            <p class="text-sm text-gray-600 mt-1">Configure your portal and integrations</p>
                        </div>
                    </div>
                </div>
            </header>

            <?php if (isset($success_message)): ?>
                <div class="mx-6 mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="mx-6 mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <div class="p-6">

                <div class="mb-6">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8">
                            <button onclick="showTab('company')" id="tab-company" class="tab-button border-b-2 border-blue-600 py-4 px-1 text-sm font-medium text-blue-600">
                                <i class="fas fa-building mr-2"></i>Company Info
                            </button>
                            <button onclick="showTab('api')" id="tab-api" class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                <i class="fas fa-key mr-2"></i>API Keys
                            </button>
                            <button onclick="showTab('email')" id="tab-email" class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                <i class="fas fa-envelope mr-2"></i>Email Settings
                            </button>
                            <button onclick="showTab('system')" id="tab-system" class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                <i class="fas fa-cog mr-2"></i>System
                            </button>
                        </nav>
                    </div>
                </div>

                <div id="content-company" class="tab-content">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Company Information</h2>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="section" value="company">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Company Name</label>
                                    <input type="text" name="company_name" value="<?php echo htmlspecialchars(getSetting('company_name', 'Blue Mogul')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Support Email</label>
                                    <input type="email" name="company_email" value="<?php echo htmlspecialchars(getSetting('company_email', 'support@bluemogul.biz')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Support Phone</label>
                                <input type="text" name="company_phone" value="<?php echo htmlspecialchars(getSetting('company_phone', '(555) 123-4567')); ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            </div>

                            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium transition">
                                <i class="fas fa-save mr-2"></i>Save Company Info
                            </button>
                        </form>
                    </div>
                </div>

                <div id="content-api" class="tab-content hidden">
                    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">API Keys & Credentials</h2>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="section" value="api">

                            <div class="mb-8">
                                <h3 class="text-md font-semibold text-gray-900 mb-4 flex items-center">
                                    <i class="fab fa-stripe text-purple-600 mr-2 text-xl"></i>Stripe Payment Gateway
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Publishable Key</label>
                                        <input type="text" name="stripe_public_key" value="<?php echo htmlspecialchars(getSetting('stripe_public_key')); ?>"
                                               placeholder="pk_test_..." class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Secret Key</label>
                                        <input type="password" name="stripe_secret_key" value="<?php echo htmlspecialchars(getSetting('stripe_secret_key')); ?>"
                                               placeholder="sk_test_..." class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-8">
                                <h3 class="text-md font-semibold text-gray-900 mb-4 flex items-center">
                                    <i class="fas fa-phone text-blue-600 mr-2"></i>VoIP.ms
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">API Username</label>
                                        <input type="text" name="voip_ms_user" value="<?php echo htmlspecialchars(getSetting('voip_ms_user', '222720')); ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">API Password</label>
                                        <input type="password" name="voip_ms_pass" value="<?php echo htmlspecialchars(getSetting('voip_ms_pass')); ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-8">
                                <h3 class="text-md font-semibold text-gray-900 mb-4 flex items-center">
                                    <i class="fas fa-ticket-alt text-green-600 mr-2"></i>ITFlow Ticketing
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">ITFlow URL</label>
                                        <input type="url" name="itflow_url" value="<?php echo htmlspecialchars(getSetting('itflow_url', 'https://itflow.bluemogul.us')); ?>"
                                               placeholder="https://itflow.yourdomain.com" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                                        <input type="password" name="itflow_api_key" value="<?php echo htmlspecialchars(getSetting('itflow_api_key')); ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium transition">
                                <i class="fas fa-save mr-2"></i>Save API Keys
                            </button>
                        </form>
                    </div>
                </div>

                <div id="content-email" class="tab-content hidden">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Email Configuration</h2>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="section" value="email">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Host</label>
                                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars(getSetting('smtp_host', 'smtp.gmail.com')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Port</label>
                                    <input type="number" name="smtp_port" value="<?php echo htmlspecialchars(getSetting('smtp_port', '587')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Username</label>
                                    <input type="text" name="smtp_user" value="<?php echo htmlspecialchars(getSetting('smtp_user')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Password</label>
                                    <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars(getSetting('smtp_pass')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">From Email</label>
                                    <input type="email" name="from_email" value="<?php echo htmlspecialchars(getSetting('from_email', 'noreply@bluemogul.biz')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">From Name</label>
                                    <input type="text" name="from_name" value="<?php echo htmlspecialchars(getSetting('from_name', 'Blue Mogul')); ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>

                            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium transition">
                                <i class="fas fa-save mr-2"></i>Save Email Settings
                            </button>
                        </form>

                        <div class="mt-8 pt-6 border-t border-gray-200">
                            <h3 class="text-md font-semibold text-gray-900 mb-4">Test Email Configuration</h3>
                            <?php if ($test_email_result !== null): ?>
                                <?php if ($test_email_result['success']): ?>
                                    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                                        <p class="text-sm text-green-800"><i class="fas fa-check-circle mr-2"></i>Test email sent successfully! Check your inbox.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                                        <p class="text-sm text-red-800"><i class="fas fa-exclamation-circle mr-2"></i>Failed to send: <?php echo htmlspecialchars($test_email_result['error'] ?? 'Unknown error'); ?></p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <form method="POST" class="flex items-end gap-4">
                            <?= csrf_field() ?>
                                <input type="hidden" name="section" value="test_email">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Recipient Email</label>
                                    <input type="email" name="test_email_to" required placeholder="test@example.com"
                                           data-testid="input-test-email"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                                </div>
                                <button type="submit" data-testid="button-send-test-email"
                                        class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md font-medium transition whitespace-nowrap">
                                    <i class="fas fa-paper-plane mr-2"></i>Send Test Email
                                </button>
                            </form>
                            <p class="mt-2 text-xs text-gray-500">Save your SMTP settings above first, then send a test email to verify the configuration.</p>
                        </div>
                    </div>
                </div>

                <div id="content-system" class="tab-content hidden">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">System Information</h2>

                        <div class="space-y-4">
                            <div class="flex justify-between items-center py-3 border-b border-gray-200">
                                <div>
                                    <p class="font-medium text-gray-900">Portal Version</p>
                                    <p class="text-sm text-gray-600">Current software version</p>
                                </div>
                                <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm font-medium">v1.0.0</span>
                            </div>

                            <div class="flex justify-between items-center py-3 border-b border-gray-200">
                                <div>
                                    <p class="font-medium text-gray-900">PHP Version</p>
                                    <p class="text-sm text-gray-600">Server PHP version</p>
                                </div>
                                <span class="text-sm text-gray-900"><?php echo phpversion(); ?></span>
                            </div>

                            <div class="flex justify-between items-center py-3 border-b border-gray-200">
                                <div>
                                    <p class="font-medium text-gray-900">Database</p>
                                    <p class="text-sm text-gray-600">PostgreSQL connection status</p>
                                </div>
                                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-medium flex items-center">
                                    <span class="h-2 w-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>Connected
                                </span>
                            </div>

                            <div class="flex justify-between items-center py-3">
                                <div>
                                    <p class="font-medium text-gray-900">Cache</p>
                                    <p class="text-sm text-gray-600">Clear application cache</p>
                                </div>
                                <button onclick="clearCache()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium transition">
                                    <i class="fas fa-trash-alt mr-2"></i>Clear Cache
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('border-blue-600', 'text-blue-600');
                button.classList.add('border-transparent', 'text-gray-500');
            });

            document.getElementById('content-' + tabName).classList.remove('hidden');

            const activeTab = document.getElementById('tab-' + tabName);
            activeTab.classList.add('border-blue-600', 'text-blue-600');
            activeTab.classList.remove('border-transparent', 'text-gray-500');
        }

        function clearCache() {
            if (confirm('Clear application cache?')) {
                alert('Cache cleared successfully!');
            }
        }
    </script>

</body>
</html>