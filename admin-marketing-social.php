<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$pdo = getDB();

$posts = [];
$stats = ['total' => 0, 'this_week' => 0, 'total_likes' => 0, 'total_shares' => 0];
try {
    $stmt = $pdo->query("SELECT * FROM social_posts ORDER BY posted_at DESC LIMIT 100");
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats['total'] = count($posts);
    $stats['total_likes'] = array_sum(array_column($posts, 'likes'));
    $stats['total_shares'] = array_sum(array_column($posts, 'shares'));

    $week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    $stats['this_week'] = count(array_filter($posts, fn($p) => $p['posted_at'] >= $week_ago));
} catch (PDOException $e) {}

// Group by platform for breakdown
$platforms = [];
foreach ($posts as $p) {
    $plat = $p['platform'];
    if (!isset($platforms[$plat])) $platforms[$plat] = 0;
    $platforms[$plat]++;
}

include 'includes/admin-header.php';
?>
<div class="flex h-screen bg-gray-50">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/admin-topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Social Content Calendar</h1>
                <p class="text-gray-500 text-sm mt-1">All social media posts across platforms</p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total Posts</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($stats['total']); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">This Week</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo number_format($stats['this_week']); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total Likes</p>
                    <p class="text-2xl font-bold text-red-500 mt-1"><?php echo number_format($stats['total_likes']); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total Shares</p>
                    <p class="text-2xl font-bold text-green-600 mt-1"><?php echo number_format($stats['total_shares']); ?></p>
                </div>
            </div>

            <!-- Platform breakdown -->
            <?php if (!empty($platforms)): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6 flex flex-wrap gap-3">
                <?php foreach ($platforms as $plat => $count): ?>
                <span class="px-3 py-1 rounded-full text-sm bg-blue-50 text-blue-700 font-medium">
                    <?php echo htmlspecialchars(ucfirst($plat)); ?>: <?php echo $count; ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Posts table -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-800">Posts</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="px-6 py-3 text-left">Platform</th>
                                <th class="px-6 py-3 text-left">Content Preview</th>
                                <th class="px-6 py-3 text-left">Posted At</th>
                                <th class="px-6 py-3 text-right">Likes</th>
                                <th class="px-6 py-3 text-right">Comments</th>
                                <th class="px-6 py-3 text-right">Shares</th>
                                <th class="px-6 py-3 text-left">Link</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($posts)): ?>
                            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">No social posts logged yet. PUBLISHER will log posts here via webhook.</td></tr>
                            <?php else: ?>
                            <?php foreach ($posts as $p): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                                        <?php echo htmlspecialchars(ucfirst($p['platform'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-gray-700 max-w-xs truncate"><?php echo htmlspecialchars(substr($p['content_preview'] ?? '', 0, 80)); ?></td>
                                <td class="px-6 py-3 text-gray-500"><?php echo date('M d, Y H:i', strtotime($p['posted_at'])); ?></td>
                                <td class="px-6 py-3 text-right text-red-500"><?php echo number_format(intval($p['likes'])); ?></td>
                                <td class="px-6 py-3 text-right text-blue-500"><?php echo number_format(intval($p['comments'])); ?></td>
                                <td class="px-6 py-3 text-right text-green-600"><?php echo number_format(intval($p['shares'])); ?></td>
                                <td class="px-6 py-3">
                                    <?php if ($p['post_url']): ?>
                                    <a href="<?php echo htmlspecialchars($p['post_url']); ?>" target="_blank" rel="noopener" class="text-blue-600 hover:underline text-xs">View</a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>
