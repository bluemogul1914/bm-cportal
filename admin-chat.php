<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$nextcloud_url = defined('NEXTCLOUD_URL') ? NEXTCLOUD_URL : (getenv('NEXTCLOUD_URL') ?: '');
$nextcloud_connected = !empty($nextcloud_url);

$active_room = $_GET['room'] ?? 'support';
$rooms = [
    'support' => [
        'name' => 'Support',
        'icon' => 'fas fa-headset',
        'color' => 'blue',
        'description' => 'Technical support questions and troubleshooting',
    ],
    'billing' => [
        'name' => 'Billing',
        'icon' => 'fas fa-file-invoice-dollar',
        'color' => 'green',
        'description' => 'Billing inquiries, payment issues, and account questions',
    ],
    'general' => [
        'name' => 'General',
        'icon' => 'fas fa-comments',
        'color' => 'purple',
        'description' => 'General discussion, announcements, and team chat',
    ],
];

if (!isset($rooms[$active_room])) {
    $active_room = 'support';
}

$talk_base_url = $nextcloud_connected ? rtrim($nextcloud_url, '/') . '/apps/spreed' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Blue Mogul Admin</title>
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
                        <i class="fas fa-comments text-blue-500 mr-2"></i>Chat
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Nextcloud Talk — Team communication channels</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($nextcloud_connected): ?>
                    <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-sm font-medium" data-testid="status-chat-connected">
                        <i class="fas fa-check-circle mr-1"></i>Connected
                    </span>
                    <a href="<?php echo htmlspecialchars($talk_base_url); ?>" target="_blank" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="link-talk-external">
                        <i class="fas fa-external-link-alt mr-2"></i>Open Nextcloud Talk
                    </a>
                    <?php else: ?>
                    <span class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-sm font-medium" data-testid="status-chat-disconnected">
                        <i class="fas fa-exclamation-circle mr-1"></i>Not Configured
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($nextcloud_connected): ?>
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6" style="height: calc(100vh - 180px);">
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                        <h3 class="font-semibold text-gray-900 text-sm"><i class="fas fa-hashtag text-gray-400 mr-2"></i>Channels</h3>
                    </div>
                    <div class="p-2 space-y-1" data-testid="channel-list">
                        <?php foreach ($rooms as $key => $room):
                            $is_active = ($active_room === $key);
                            $color_classes = match($room['color']) {
                                'blue' => $is_active ? 'bg-blue-50 border-blue-200 text-blue-800' : 'hover:bg-blue-50 text-gray-700',
                                'green' => $is_active ? 'bg-green-50 border-green-200 text-green-800' : 'hover:bg-green-50 text-gray-700',
                                'purple' => $is_active ? 'bg-purple-50 border-purple-200 text-purple-800' : 'hover:bg-purple-50 text-gray-700',
                                default => $is_active ? 'bg-gray-100 border-gray-200 text-gray-800' : 'hover:bg-gray-50 text-gray-700',
                            };
                            $icon_color = match($room['color']) {
                                'blue' => 'text-blue-500',
                                'green' => 'text-green-500',
                                'purple' => 'text-purple-500',
                                default => 'text-gray-500',
                            };
                        ?>
                        <a href="?room=<?php echo $key; ?>" class="flex items-center gap-3 px-3 py-3 rounded-lg border <?php echo $is_active ? $color_classes : 'border-transparent ' . $color_classes; ?> transition" data-testid="channel-<?php echo $key; ?>">
                            <i class="<?php echo $room['icon']; ?> <?php echo $icon_color; ?> text-lg w-6 text-center"></i>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm"><?php echo $room['name']; ?></p>
                                <p class="text-xs text-gray-500 truncate"><?php echo $room['description']; ?></p>
                            </div>
                            <?php if ($is_active): ?>
                            <span class="w-2 h-2 bg-<?php echo $room['color']; ?>-500 rounded-full"></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="p-4 border-t border-gray-200 mt-auto">
                        <div class="bg-gray-50 rounded-lg p-3">
                            <p class="text-xs font-medium text-gray-700 mb-1"><i class="fas fa-info-circle text-blue-400 mr-1"></i>How it works</p>
                            <p class="text-xs text-gray-500">Chat rooms are powered by Nextcloud Talk. Messages are synced in real-time with your Nextcloud instance.</p>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-3 bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col">
                    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <?php $room = $rooms[$active_room]; ?>
                            <div class="w-8 h-8 bg-<?php echo $room['color']; ?>-100 rounded-lg flex items-center justify-center">
                                <i class="<?php echo $room['icon']; ?> text-<?php echo $room['color']; ?>-600 text-sm"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900 text-sm" data-testid="text-active-channel"><?php echo $room['name']; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $room['description']; ?></p>
                            </div>
                        </div>
                        <a href="<?php echo htmlspecialchars($talk_base_url); ?>" target="_blank" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                            <i class="fas fa-expand-alt mr-1"></i>Full Screen
                        </a>
                    </div>
                    <div class="flex-1">
                        <iframe src="<?php echo htmlspecialchars($talk_base_url); ?>" class="w-full h-full border-0" title="Nextcloud Talk - <?php echo $room['name']; ?>" data-testid="iframe-chat" allow="microphone; camera; fullscreen" style="min-height: 500px;"></iframe>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="max-w-3xl mx-auto">
                <div class="bg-white rounded-lg border border-gray-200 p-12 text-center mb-6">
                    <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-comments text-blue-600 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-3">Connect Nextcloud Talk</h3>
                    <p class="text-gray-500 mb-8 max-w-md mx-auto">Set up your Nextcloud instance to enable real-time chat channels for Support, Billing, and General communication with your team and clients.</p>

                    <div class="bg-gray-50 rounded-lg p-6 text-left max-w-md mx-auto">
                        <p class="text-sm font-semibold text-gray-700 mb-4"><i class="fas fa-cog mr-2"></i>Setup Instructions</p>
                        <ol class="space-y-3 text-sm text-gray-600">
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">1</span>
                                <span>Add <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">NEXTCLOUD_URL</code> to your Replit Secrets with your Nextcloud instance URL</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">2</span>
                                <span>Add <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">NEXTCLOUD_USER</code> and <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">NEXTCLOUD_PASSWORD</code></span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">3</span>
                                <span>Ensure Nextcloud Talk app is installed on your Nextcloud instance</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="w-6 h-6 bg-blue-100 text-blue-700 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">4</span>
                                <span>Create conversation rooms: <strong>Support</strong>, <strong>Billing</strong>, <strong>General</strong></span>
                            </li>
                        </ol>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach ($rooms as $key => $room): ?>
                    <div class="bg-white rounded-lg border border-gray-200 p-6 text-center">
                        <div class="w-12 h-12 bg-<?php echo $room['color']; ?>-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="<?php echo $room['icon']; ?> text-<?php echo $room['color']; ?>-600 text-xl"></i>
                        </div>
                        <h4 class="font-semibold text-gray-900 mb-1"><?php echo $room['name']; ?></h4>
                        <p class="text-xs text-gray-500"><?php echo $room['description']; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>