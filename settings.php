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
    <title>Settings - Blue Mogul Client Portal</title>
    <meta name="description" content="Manage your account preferences, notification settings, and security options in the Blue Mogul Client Portal.">
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
                            <p class="text-sm text-gray-500 mt-0.5">Add an extra layer of security to your account</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-indigo-100 text-indigo-700" data-testid="badge-2fa-coming-soon">
                        <i class="fas fa-clock mr-1.5"></i>Coming Soon
                    </span>
                </div>
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-mobile-alt text-gray-400 text-lg"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-sm font-medium text-gray-900 mb-1">Authenticator App</h3>
                            <p class="text-sm text-gray-600 mb-2">
                                Two-factor authentication adds an additional layer of security by requiring a verification code from your authenticator app (such as Google Authenticator or Authy) when signing in.
                            </p>
                            <p class="text-sm text-gray-500 mb-4">
                                When enabled, you'll need to enter a 6-digit code from your authenticator app each time you log in, in addition to your password. This helps protect your account even if your password is compromised.
                            </p>
                            <div class="flex items-center gap-3 flex-wrap">
                                <button disabled class="inline-flex items-center bg-gray-100 text-gray-400 px-4 py-2 rounded-md font-medium text-sm cursor-not-allowed" data-testid="button-enable-2fa">
                                    <i class="fas fa-lock mr-2"></i>Enable Two-Factor Authentication
                                </button>
                                <span class="text-xs text-gray-400">
                                    <i class="fas fa-info-circle mr-1"></i>This feature is under development
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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