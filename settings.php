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
                <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-settings-title"><i class="fas fa-cog mr-2 text-gray-400"></i>Settings</h1>
                <p class="text-sm text-gray-600 mt-1">Manage your account preferences</p>
            </div>
        </header>

        <div class="p-6 max-w-3xl space-y-6">

            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-notifications">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Notification Preferences</h2>
                    <p class="text-sm text-gray-500 mt-1">Choose which notifications you'd like to receive</p>
                </div>
                <div class="p-6 space-y-4">
                    <label class="flex items-center justify-between cursor-pointer" data-testid="toggle-ticket-update">
                        <div>
                            <span class="text-sm font-medium text-gray-900">Ticket Updates</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive email when a ticket is updated or replied to</p>
                        </div>
                        <div class="relative">
                            <input type="checkbox" class="sr-only peer" checked>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <label class="flex items-center justify-between cursor-pointer" data-testid="toggle-invoice-due">
                        <div>
                            <span class="text-sm font-medium text-gray-900">Invoice Due Reminders</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive email when an invoice is approaching its due date</p>
                        </div>
                        <div class="relative">
                            <input type="checkbox" class="sr-only peer" checked>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <label class="flex items-center justify-between cursor-pointer" data-testid="toggle-new-document">
                        <div>
                            <span class="text-sm font-medium text-gray-900">New Documents</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive email when a new document is shared with you</p>
                        </div>
                        <div class="relative">
                            <input type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>

                    <label class="flex items-center justify-between cursor-pointer" data-testid="toggle-weekly-summary">
                        <div>
                            <span class="text-sm font-medium text-gray-900">Weekly Summary</span>
                            <p class="text-xs text-gray-500 mt-0.5">Receive a weekly email summary of your account activity</p>
                        </div>
                        <div class="relative">
                            <input type="checkbox" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-two-factor">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Two-Factor Authentication</h2>
                        <p class="text-sm text-gray-500 mt-1">Add an extra layer of security to your account</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-amber-100 text-amber-700" data-testid="badge-2fa-coming-soon">
                        <i class="fas fa-clock mr-1.5"></i>Coming Soon
                    </span>
                </div>
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-shield-alt text-gray-400"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-700">Two-factor authentication adds an additional layer of security by requiring a verification code from your authenticator app when signing in.</p>
                            <button disabled class="mt-3 bg-gray-100 text-gray-400 px-4 py-2 rounded-md font-medium text-sm cursor-not-allowed" data-testid="button-enable-2fa">
                                <i class="fas fa-lock mr-2"></i>Enable 2FA
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-theme">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Theme Preference</h2>
                        <p class="text-sm text-gray-500 mt-1">Choose your preferred display theme</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-amber-100 text-amber-700" data-testid="badge-theme-coming-soon">
                        <i class="fas fa-clock mr-1.5"></i>Coming Soon
                    </span>
                </div>
                <div class="p-6">
                    <div class="flex gap-4">
                        <label class="flex-1 cursor-not-allowed">
                            <input type="radio" name="theme" value="light" checked disabled class="sr-only peer">
                            <div class="border-2 border-gray-200 rounded-lg p-4 text-center peer-checked:border-blue-500 peer-checked:bg-blue-50 opacity-60">
                                <i class="fas fa-sun text-2xl text-yellow-500 mb-2"></i>
                                <p class="text-sm font-medium text-gray-900">Light</p>
                            </div>
                        </label>
                        <label class="flex-1 cursor-not-allowed">
                            <input type="radio" name="theme" value="dark" disabled class="sr-only peer">
                            <div class="border-2 border-gray-200 rounded-lg p-4 text-center peer-checked:border-blue-500 peer-checked:bg-blue-50 opacity-60">
                                <i class="fas fa-moon text-2xl text-indigo-500 mb-2"></i>
                                <p class="text-sm font-medium text-gray-900">Dark</p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-communication">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Communication Preferences</h2>
                    <p class="text-sm text-gray-500 mt-1">Set your preferred contact method</p>
                </div>
                <div class="p-6">
                    <div class="max-w-sm">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Preferred Contact Method</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-contact-method">
                            <option value="email">Email</option>
                            <option value="phone">Phone</option>
                            <option value="sms">SMS</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-2">This determines how we primarily reach out to you regarding your account.</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-red-200" data-testid="section-delete-account">
                <div class="px-6 py-4 border-b border-red-200">
                    <h2 class="text-lg font-semibold text-red-700">Delete Account</h2>
                </div>
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-500"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-700 mb-1">Once you delete your account, there is no going back. All of your data including tickets, documents, and billing history will be permanently removed.</p>
                            <p class="text-sm text-gray-500 mb-3">To request account deletion, please contact our support team.</p>
                            <button onclick="alert('Your account deletion request has been submitted. Our team will contact you within 48 hours to confirm.')" class="inline-flex items-center bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-request-delete">
                                <i class="fas fa-trash-alt mr-2"></i>Request Deletion
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
</body>
</html>