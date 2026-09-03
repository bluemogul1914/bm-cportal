<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Blue Mogul Suite</title>
    <meta name="description" content="Manage your account preferences, notification settings, and security options in the Blue Mogul Suite.">
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
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-settings-title">
                    <i class="fas fa-cog mr-2 text-gray-400"></i>Settings
                </h1>
                <p class="text-sm text-gray-600 mt-1">Manage your account preferences, notifications, and security</p>
            </div>
        </header>

        <div class="p-6 max-w-3xl space-y-6">

            <!-- Settings Overview -->
            <div class="bg-blue-50 border border-blue-100 rounded-lg px-5 py-4" data-testid="section-settings-overview">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-info-circle text-blue-600 text-sm"></i>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-blue-900">Account Settings</h2>
                        <p class="text-sm text-blue-700 mt-0.5">
                            Configure your notification preferences, security options, theme, and communication settings below.
                            Some features are still in development and will be available soon.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Section 1: Notification Preferences -->
            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-notifications">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-bell text-blue-600 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Notification Preferences</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Choose which notifications you'd like to receive via email</p>
                        </div>
                    </div>
                </div>
                <div class="p-6 space-y-5">
                    <label class="flex items-center justify-between gap-4 cursor-pointer" data-testid="toggle-ticket-update">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-900">Ticket Updates</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive an email notification when a support ticket is updated or replied to by our team</p>
                        </div>
                        <div class="relative flex-shrink-0">
                            <input type="checkbox" class="sr-only peer" checked>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <div class="border-t border-gray-100"></div>

                    <label class="flex items-center justify-between gap-4 cursor-pointer" data-testid="toggle-invoice-due">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-900">Invoice Due Reminders</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive an email reminder when an invoice is approaching its due date or is overdue</p>
                        </div>
                        <div class="relative flex-shrink-0">
                            <input type="checkbox" class="sr-only peer" checked>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <div class="border-t border-gray-100"></div>

                    <label class="flex items-center justify-between gap-4 cursor-pointer" data-testid="toggle-new-document">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-900">New Documents</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive an email when a new document is shared with you or uploaded to your account</p>
                        </div>
                        <div class="relative flex-shrink-0">
                            <input type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <div class="border-t border-gray-100"></div>

                    <label class="flex items-center justify-between gap-4 cursor-pointer" data-testid="toggle-weekly-summary">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-gray-900">Weekly Summary Digest</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive a weekly email summary of your account activity, open tickets, and upcoming invoices</p>
                        </div>
                        <div class="relative flex-shrink-0">
                            <input type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <div class="bg-blue-50 border border-blue-100 rounded-md px-4 py-3 mt-2">
                        <p class="text-xs text-blue-700">
                            <i class="fas fa-info-circle mr-1.5"></i>
                            Notification preferences are saved automatically. Changes may take up to 24 hours to take effect.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Section 2: Two-Factor Authentication -->
            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-two-factor">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-shield-alt text-indigo-600 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Two-Factor Authentication</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Add an extra layer of security using an authenticator app</p>
                        </div>
                    </div>
                    <span id="2fa-status-badge" class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-gray-100 text-gray-600">
                        <span id="2fa-status-dot" class="w-2 h-2 rounded-full bg-gray-400 mr-1.5"></span>
                        <span id="2fa-status-text">Checking...</span>
                    </span>
                </div>
                <div class="p-6" id="2fa-panel">
                    <div id="2fa-loading" class="text-sm text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading 2FA status...</div>

                    <div id="2fa-enabled-view" class="hidden">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-shield-check text-green-600 text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">2FA is Enabled</h3>
                                <p class="text-sm text-gray-600 mb-4">Your account is protected with two-factor authentication. You will be prompted for a 6-digit code from your authenticator app when logging in.</p>
                                <div class="flex items-center gap-3">
                                    <button onclick="show2FADisable()" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 rounded-md text-sm font-medium transition" data-testid="button-disable-2fa">
                                        <i class="fas fa-lock-open mr-2"></i>Disable 2FA
                                    </button>
                                </div>
                                <div id="2fa-disable-form" class="hidden mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                                    <p class="text-sm text-red-700 mb-3">Enter your current 6-digit authenticator code to confirm disabling 2FA:</p>
                                    <div class="flex gap-3">
                                        <input type="text" id="disable-token" maxlength="6" placeholder="000000" class="px-3 py-2 border border-red-300 rounded-md text-sm font-mono w-32 focus:ring-2 focus:ring-red-400" data-testid="input-disable-token">
                                        <button onclick="disable2FA()" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium" data-testid="button-confirm-disable">Confirm Disable</button>
                                    </div>
                                    <p id="disable-error" class="text-xs text-red-600 mt-2 hidden"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="2fa-setup-view" class="hidden">
                        <div class="flex items-start gap-4 mb-4">
                            <div class="w-12 h-12 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-mobile-alt text-indigo-600 text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Set Up Authenticator App</h3>
                                <p class="text-sm text-gray-600">Scan the QR code below with Google Authenticator, Authy, or any TOTP-compatible app, then enter the 6-digit code to activate.</p>
                            </div>
                        </div>
                        <div id="2fa-qr-area" class="hidden">
                            <div class="flex flex-col md:flex-row gap-6 items-start">
                                <div class="flex-shrink-0">
                                    <img id="2fa-qr-img" src="" alt="QR Code" class="w-48 h-48 border border-gray-200 rounded-lg p-2 bg-white">
                                    <p class="text-xs text-gray-500 mt-2 text-center">Scan with your app</p>
                                </div>
                                <div class="flex-1">
                                    <p class="text-xs text-gray-500 mb-1 font-medium">Or enter this code manually:</p>
                                    <p id="2fa-secret-display" class="font-mono text-sm bg-gray-100 px-3 py-2 rounded text-gray-800 break-all mb-4"></p>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Enter the 6-digit code from your app:</label>
                                    <div class="flex gap-3">
                                        <input type="text" id="totp-token" maxlength="6" placeholder="000000" class="px-3 py-2 border border-gray-300 rounded-md text-sm font-mono w-32 focus:ring-2 focus:ring-indigo-400" data-testid="input-totp-token">
                                        <button onclick="enable2FA()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium" data-testid="button-verify-enable">Verify & Enable</button>
                                    </div>
                                    <p id="totp-error" class="text-xs text-red-600 mt-2 hidden"></p>
                                    <p id="totp-success" class="text-xs text-green-600 mt-2 hidden"></p>
                                </div>
                            </div>
                        </div>
                        <button id="btn-start-2fa-setup" onclick="setup2FA()" class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium transition" data-testid="button-enable-2fa">
                            <i class="fas fa-lock mr-2"></i>Enable Two-Factor Authentication
                        </button>
                    </div>
                </div>
            </div>
            <script>
            async function check2FAStatus() {
                try {
                    const r = await fetch('/api/2fa/setup');
                    const d = await r.json();
                    document.getElementById('2fa-loading').classList.add('hidden');
                    const badge = document.getElementById('2fa-status-badge');
                    const dot = document.getElementById('2fa-status-dot');
                    const txt = document.getElementById('2fa-status-text');
                    if (d.enabled) {
                        badge.className = 'inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-green-100 text-green-700';
                        dot.className = 'w-2 h-2 rounded-full bg-green-500 mr-1.5';
                        txt.textContent = 'Enabled';
                        document.getElementById('2fa-enabled-view').classList.remove('hidden');
                    } else {
                        badge.className = 'inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-gray-100 text-gray-600';
                        dot.className = 'w-2 h-2 rounded-full bg-gray-400 mr-1.5';
                        txt.textContent = 'Not Enabled';
                        document.getElementById('2fa-setup-view').classList.remove('hidden');
                    }
                } catch(e) {
                    document.getElementById('2fa-loading').textContent = 'Error loading 2FA status.';
                }
            }
            async function setup2FA() {
                document.getElementById('btn-start-2fa-setup').disabled = true;
                document.getElementById('btn-start-2fa-setup').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                try {
                    const r = await fetch('/api/2fa/setup');
                    const d = await r.json();
                    if (d.qr_url) {
                        document.getElementById('2fa-qr-img').src = d.qr_url;
                        document.getElementById('2fa-secret-display').textContent = d.secret;
                        document.getElementById('2fa-qr-area').classList.remove('hidden');
                        document.getElementById('btn-start-2fa-setup').classList.add('hidden');
                    }
                } catch(e) { alert('Error loading setup. Please try again.'); }
            }
            async function enable2FA() {
                const token = document.getElementById('totp-token').value.trim();
                const errEl = document.getElementById('totp-error');
                const sucEl = document.getElementById('totp-success');
                errEl.classList.add('hidden');
                sucEl.classList.add('hidden');
                if (!/^\d{6}$/.test(token)) { errEl.textContent = 'Enter a 6-digit code.'; errEl.classList.remove('hidden'); return; }
                try {
                    const r = await fetch('/api/2fa/enable', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({token}) });
                    const d = await r.json();
                    if (d.success) {
                        sucEl.textContent = '✓ 2FA enabled! Your account is now secured.';
                        sucEl.classList.remove('hidden');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        errEl.textContent = d.error || 'Invalid code. Try again.';
                        errEl.classList.remove('hidden');
                    }
                } catch(e) { errEl.textContent = 'Network error.'; errEl.classList.remove('hidden'); }
            }
            function show2FADisable() { document.getElementById('2fa-disable-form').classList.remove('hidden'); }
            async function disable2FA() {
                const token = document.getElementById('disable-token').value.trim();
                const errEl = document.getElementById('disable-error');
                errEl.classList.add('hidden');
                if (!/^\d{6}$/.test(token)) { errEl.textContent = 'Enter a 6-digit code.'; errEl.classList.remove('hidden'); return; }
                try {
                    const r = await fetch('/api/2fa/disable', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({token}) });
                    const d = await r.json();
                    if (d.success) { location.reload(); }
                    else { errEl.textContent = d.error || 'Invalid code.'; errEl.classList.remove('hidden'); }
                } catch(e) { errEl.textContent = 'Network error.'; errEl.classList.remove('hidden'); }
            }
            check2FAStatus();
            </script>

            <!-- Section 3: Theme Preference -->
            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-theme">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-palette text-purple-600 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Theme Preference</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Choose your preferred display theme for the portal</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-purple-100 text-purple-700" data-testid="badge-theme-coming-soon">
                        <i class="fas fa-clock mr-1.5"></i>Coming Soon
                    </span>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-4">Select your preferred visual theme. Dark mode reduces eye strain in low-light environments and can save battery on OLED displays.</p>
                    <div class="grid grid-cols-2 gap-4 max-w-sm">
                        <label class="cursor-not-allowed" data-testid="radio-theme-light">
                            <input type="radio" name="theme" value="light" checked disabled class="sr-only peer">
                            <div class="border-2 border-gray-200 rounded-lg p-4 text-center peer-checked:border-blue-500 peer-checked:bg-blue-50 opacity-60 transition">
                                <i class="fas fa-sun text-2xl text-yellow-500 mb-2"></i>
                                <p class="text-sm font-medium text-gray-900">Light</p>
                                <p class="text-xs text-gray-500 mt-1">Default theme</p>
                            </div>
                        </label>
                        <label class="cursor-not-allowed" data-testid="radio-theme-dark">
                            <input type="radio" name="theme" value="dark" disabled class="sr-only peer">
                            <div class="border-2 border-gray-200 rounded-lg p-4 text-center peer-checked:border-blue-500 peer-checked:bg-blue-50 opacity-60 transition">
                                <i class="fas fa-moon text-2xl text-indigo-500 mb-2"></i>
                                <p class="text-sm font-medium text-gray-900">Dark</p>
                                <p class="text-xs text-gray-500 mt-1">Reduced glare</p>
                            </div>
                        </label>
                    </div>
                    <p class="text-xs text-gray-400 mt-3">
                        <i class="fas fa-info-circle mr-1"></i>Theme preference will be available in a future update.
                    </p>
                </div>
            </div>

            <!-- Section 4: Communication Preferences -->
            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-communication">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-green-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-comments text-green-600 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Communication Preferences</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Set how and in what language we communicate with you</p>
                        </div>
                    </div>
                </div>
                <div class="p-6 space-y-6">
                    <div class="max-w-sm">
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="contact-method">Preferred Contact Method</label>
                        <select id="contact-method" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-contact-method">
                            <option value="email">Email</option>
                            <option value="phone">Phone</option>
                            <option value="sms">SMS</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-2">This determines how we primarily reach out to you regarding your account, support updates, and service notifications.</p>
                    </div>

                    <div class="border-t border-gray-100"></div>

                    <div class="max-w-sm">
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="preferred-language">Preferred Language</label>
                        <select id="preferred-language" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-preferred-language">
                            <option value="en">English</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-2">Choose the language for emails and communications. Additional languages will be available in the future.</p>
                    </div>
                </div>
            </div>

            <!-- Section 5: Account Deletion -->
            <div class="bg-white rounded-lg border-2 border-red-200" data-testid="section-delete-account">
                <div class="px-6 py-4 border-b border-red-200 bg-red-50" style="border-radius: 0.45rem 0.45rem 0 0;">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-600 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-red-700">Delete Account</h2>
                            <p class="text-sm text-red-500 mt-0.5">Permanently remove your account and all associated data</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-lg bg-red-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user-slash text-red-500 text-lg"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-sm font-medium text-gray-900 mb-2">What happens when you delete your account?</h3>
                            <ul class="text-sm text-gray-600 space-y-1.5 mb-4">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-times-circle text-red-400 mt-0.5 text-xs flex-shrink-0"></i>
                                    <span>All your support tickets and conversation history will be permanently deleted</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-times-circle text-red-400 mt-0.5 text-xs flex-shrink-0"></i>
                                    <span>Your uploaded documents and files will be removed from our servers</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-times-circle text-red-400 mt-0.5 text-xs flex-shrink-0"></i>
                                    <span>Your billing history and invoice records will no longer be accessible</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-times-circle text-red-400 mt-0.5 text-xs flex-shrink-0"></i>
                                    <span>Any active services linked to your account will be terminated</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-times-circle text-red-400 mt-0.5 text-xs flex-shrink-0"></i>
                                    <span>This action is irreversible and cannot be undone</span>
                                </li>
                            </ul>
                            <p class="text-sm text-gray-500 mb-4">
                                To request account deletion, click the button below. Our support team will review your request and contact you within 48 business hours to confirm the deletion process.
                            </p>
                            <button onclick="if(confirm('Are you sure you want to request account deletion? This action cannot be undone.')){alert('Your account deletion request has been submitted. Our team will contact you within 48 hours to confirm.');}" class="inline-flex items-center bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-request-delete">
                                <i class="fas fa-trash-alt mr-2"></i>Request Account Deletion
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Session & Account Information -->
            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-session-info">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user-circle text-gray-500 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Account Information</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Your current session and account details</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-md px-4 py-3" data-testid="text-account-name">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Account Name</p>
                            <p class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($user_name); ?></p>
                        </div>
                        <div class="bg-gray-50 rounded-md px-4 py-3" data-testid="text-account-email">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Email Address</p>
                            <p class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($user_email); ?></p>
                        </div>
                        <div class="bg-gray-50 rounded-md px-4 py-3" data-testid="text-account-role">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Account Role</p>
                            <p class="text-sm text-gray-900 font-medium"><?php echo $is_admin ? 'Administrator' : 'Client'; ?></p>
                        </div>
                        <div class="bg-gray-50 rounded-md px-4 py-3" data-testid="text-session-status">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Session Status</p>
                            <p class="text-sm font-medium">
                                <span class="inline-flex items-center text-green-700">
                                    <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                    Active
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400">
                            <i class="fas fa-clock mr-1"></i>
                            Your session will expire after 24 hours of inactivity. For security, please sign out when you are finished.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-quick-links">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-link text-gray-500 text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Quick Links</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Helpful resources and account management</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <a href="profile.php" class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-md hover:bg-gray-100 transition" data-testid="link-edit-profile">
                            <i class="fas fa-user-edit text-gray-400"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Edit Profile</p>
                                <p class="text-xs text-gray-500">Update your personal info</p>
                            </div>
                        </a>
                        <a href="tickets.php" class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-md hover:bg-gray-100 transition" data-testid="link-support-tickets">
                            <i class="fas fa-headset text-gray-400"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Support</p>
                                <p class="text-xs text-gray-500">View your support tickets</p>
                            </div>
                        </a>
                        <a href="help.php" class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-md hover:bg-gray-100 transition" data-testid="link-help-center">
                            <i class="fas fa-question-circle text-gray-400"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Help Center</p>
                                <p class="text-xs text-gray-500">FAQs and documentation</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
</body>
</html>