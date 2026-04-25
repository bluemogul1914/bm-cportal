<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$pdo = getDB();

$posts = [];
$stats = ['total' => 0, 'total_views' => 0, 'avg_engagement' => 0, 'this_month' => 0];
try {
    $stmt = $pdo->query("SELECT * FROM blog_posts ORDER BY published_at DESC LIMIT 100");
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats['total'] = count($posts);
    $stats['total_views'] = array_sum(array_column($posts, 'views'));
    $stats['avg_engagement'] = $stats['total'] > 0
        ? round(array_sum(array_column($posts, 'engagement_score')) / $stats['total'], 1)
        : 0;

    $month_start = date('Y-m-01');
    $stats['this_month'] = count(array_filter($posts, fn($p) => substr($p['published_at'], 0, 7) === date('Y-m')));
} catch (PDOException $e) {}

include 'includes/admin-header.php';
?>
<div class="flex h-screen bg-gray-50">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/admin-topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Blog & Script Library</h1>
                <p class="text-gray-500 text-sm mt-1">Published blog posts and content engagement metrics</p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total Published</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($stats['total']); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">This Month</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo number_format($stats['this_month']); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total Views</p>
                    <p class="text-2xl font-bold text-green-600 mt-1"><?php echo number_format($stats['total_views']); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Avg Engagement</p>
                    <p class="text-2xl font-bold text-purple-600 mt-1"><?php echo $stats['avg_engagement']; ?></p>
                </div>
            </div>

            <!-- Posts table -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-800">Published Posts</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="px-6 py-3 text-left">Title</th>
                                <th class="px-6 py-3 text-left">Platform</th>
                                <th class="px-6 py-3 text-left">Published</th>
                                <th class="px-6 py-3 text-right">Views</th>
                                <th class="px-6 py-3 text-right">Engagement</th>
                                <th class="px-6 py-3 text-left">Link</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($posts)): ?>
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No blog posts logged yet. PUBLISHER will log posts here via webhook.</td></tr>
                            <?php else: ?>
                            <?php foreach ($posts as $p): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-medium text-gray-900 max-w-sm truncate"><?php echo htmlspecialchars($p['title']); ?></td>
                                <td class="px-6 py-3">
                                    <span class="px-2 py-0.5 rounded text-xs bg-purple-100 text-purple-700">
                                        <?php echo htmlspecialchars(ucfirst($p['platform'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-gray-500"><?php echo date('M d, Y', strtotime($p['published_at'])); ?></td>
                                <td class="px-6 py-3 text-right text-gray-700"><?php echo number_format(intval($p['views'])); ?></td>
                                <td class="px-6 py-3 text-right">
                                    <span class="px-2 py-0.5 rounded-full text-xs <?php echo intval($p['engagement_score']) >= 50 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                                        <?php echo intval($p['engagement_score']); ?>
                                    </span>
                                </td>
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
