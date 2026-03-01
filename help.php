<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$pdo = getDB();

$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$article_slug = $_GET['article'] ?? '';

$view_article = null;
if ($article_slug) {
    $stmt = $pdo->prepare("SELECT * FROM knowledge_articles WHERE slug = ? AND is_published = true");
    $stmt->execute([$article_slug]);
    $view_article = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($view_article) {
        $pdo->prepare("UPDATE knowledge_articles SET view_count = view_count + 1 WHERE id = ?")->execute([$view_article['id']]);
    }
}

$query = "SELECT id, title, slug, category, tags, content, view_count, updated_at FROM knowledge_articles WHERE is_published = true";
$params = [];
if ($search) {
    $query .= " AND (title ILIKE ? OR content ILIKE ? OR tags ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category) {
    $query .= " AND category = ?";
    $params[] = $category;
}
$query .= " ORDER BY view_count DESC, updated_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat_stmt = $pdo->query("SELECT DISTINCT category FROM knowledge_articles WHERE is_published = true ORDER BY category");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

$articles_by_cat = [];
foreach ($articles as $a) {
    $articles_by_cat[$a['category']][] = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view_article ? htmlspecialchars($view_article['title']) . ' - ' : ''; ?>Help Center - Blue Mogul</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <?php if ($view_article): ?>
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <a href="help.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($view_article['title']); ?></h1>
                        <div class="flex items-center gap-3 mt-0.5">
                            <span class="text-xs text-gray-500"><i class="fas fa-folder mr-1"></i><?php echo htmlspecialchars($view_article['category']); ?></span>
                            <span class="text-xs text-gray-400"><i class="fas fa-eye mr-1"></i><?php echo $view_article['view_count']; ?> views</span>
                            <span class="text-xs text-gray-400"><i class="fas fa-clock mr-1"></i>Updated <?php echo date('M d, Y', strtotime($view_article['updated_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-3xl">
            <div class="bg-white rounded-lg border border-gray-200 p-8">
                <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed article-content">
                    <?php
                        $content = htmlspecialchars($view_article['content']);
                        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
                        $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
                        $content = preg_replace('/`(.+?)`/', '<code class="bg-gray-100 px-1.5 py-0.5 rounded text-sm font-mono text-blue-700">$1</code>', $content);
                        $content = preg_replace('/^### (.+)$/m', '<h3 class="text-lg font-semibold text-gray-900 mt-6 mb-2">$1</h3>', $content);
                        $content = preg_replace('/^## (.+)$/m', '<h2 class="text-xl font-semibold text-gray-900 mt-6 mb-3">$1</h2>', $content);
                        $content = preg_replace('/^- (.+)$/m', '<li class="ml-4 mb-1">$1</li>', $content);
                        $content = preg_replace('/^(\d+)\. (.+)$/m', '<li class="ml-4 mb-1"><span class="font-medium text-blue-600">$1.</span> $2</li>', $content);
                        $content = nl2br($content);
                        echo $content;
                    ?>
                </div>

                <?php if ($view_article['tags']): ?>
                    <div class="mt-8 pt-4 border-t border-gray-200">
                        <p class="text-xs text-gray-500 mb-2">Tags:</p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (explode(',', $view_article['tags']) as $tag): ?>
                                <span class="px-2 py-1 bg-blue-50 text-blue-600 rounded-full text-xs font-medium"><?php echo htmlspecialchars(trim($tag)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-6 text-center">
                <p class="text-sm text-blue-800 font-medium mb-2">Still need help?</p>
                <a href="tickets.php" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="link-submit-ticket">
                    <i class="fas fa-ticket-alt"></i>Submit a Support Ticket
                </a>
            </div>
        </div>

        <?php else: ?>
        <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white">
            <div class="px-6 py-10 text-center">
                <h1 class="text-3xl font-bold mb-2">Help Center</h1>
                <p class="text-blue-200 mb-6">Find answers to common questions and learn how to use our services</p>
                <form method="GET" class="max-w-xl mx-auto">
                    <div class="relative">
                        <i class="fas fa-search text-gray-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search help articles..." class="w-full pl-11 pr-4 py-3 rounded-xl text-gray-900 text-sm focus:ring-2 focus:ring-blue-300 border-0" data-testid="input-search-help">
                    </div>
                </form>
            </div>
        </header>

        <div class="p-6">
            <?php if (!$search && !$category): ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <?php
                    $cat_icons = [
                        'Getting Started' => ['fa-rocket', 'blue'],
                        'Billing' => ['fa-credit-card', 'green'],
                        'Email & Communication' => ['fa-envelope', 'purple'],
                        'Network & Security' => ['fa-shield-alt', 'red'],
                        'Products & Services' => ['fa-box', 'orange'],
                        'Troubleshooting' => ['fa-wrench', 'yellow'],
                        'general' => ['fa-book', 'gray'],
                    ];
                ?>
                <?php foreach ($categories as $cat): ?>
                    <?php $ci = $cat_icons[$cat] ?? ['fa-folder', 'gray']; ?>
                    <a href="?category=<?php echo urlencode($cat); ?>" class="bg-white rounded-lg border border-gray-200 p-5 text-center hover:border-blue-300 hover:shadow-md transition group" data-testid="cat-<?php echo strtolower(str_replace([' ', '&'], '-', $cat)); ?>">
                        <div class="w-12 h-12 bg-<?php echo $ci[1]; ?>-100 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition">
                            <i class="fas <?php echo $ci[0]; ?> text-<?php echo $ci[1]; ?>-600 text-lg"></i>
                        </div>
                        <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($cat); ?></p>
                        <p class="text-xs text-gray-500 mt-0.5"><?php echo count($articles_by_cat[$cat] ?? []); ?> articles</p>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($category): ?>
                <div class="flex items-center gap-2 mb-4">
                    <a href="help.php" class="text-sm text-gray-500 hover:text-gray-700">Help Center</a>
                    <i class="fas fa-chevron-right text-gray-400 text-[10px]"></i>
                    <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category); ?></span>
                    <a href="help.php" class="ml-2 text-xs text-blue-600 hover:text-blue-800">Clear filter</a>
                </div>
            <?php endif; ?>
            <?php if ($search): ?>
                <div class="mb-4">
                    <p class="text-sm text-gray-600">Search results for "<strong><?php echo htmlspecialchars($search); ?></strong>" — <?php echo count($articles); ?> articles found</p>
                </div>
            <?php endif; ?>

            <?php if ($search || $category): ?>
                <div class="bg-white rounded-lg border border-gray-200 divide-y divide-gray-100">
                    <?php foreach ($articles as $article): ?>
                        <a href="?article=<?php echo urlencode($article['slug']); ?>" class="block px-6 py-4 hover:bg-gray-50 transition" data-testid="article-link-<?php echo $article['id']; ?>">
                            <h3 class="text-sm font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($article['title']); ?></h3>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars(substr($article['content'], 0, 150)); ?>...</p>
                            <div class="flex items-center gap-3 mt-2">
                                <span class="text-[10px] text-gray-400"><i class="fas fa-folder mr-1"></i><?php echo htmlspecialchars($article['category']); ?></span>
                                <span class="text-[10px] text-gray-400"><i class="fas fa-eye mr-1"></i><?php echo $article['view_count']; ?> views</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($articles)): ?>
                        <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-search text-gray-300 text-2xl mb-2 block"></i>No articles found</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($articles_by_cat as $cat => $cat_articles): ?>
                    <div class="mb-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3"><?php echo htmlspecialchars($cat); ?></h2>
                        <div class="bg-white rounded-lg border border-gray-200 divide-y divide-gray-100">
                            <?php foreach ($cat_articles as $article): ?>
                                <a href="?article=<?php echo urlencode($article['slug']); ?>" class="block px-6 py-4 hover:bg-gray-50 transition" data-testid="article-link-<?php echo $article['id']; ?>">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h3 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($article['title']); ?></h3>
                                            <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars(substr($article['content'], 0, 100)); ?>...</p>
                                        </div>
                                        <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6 text-center">
                <p class="text-sm text-blue-800 font-medium mb-1">Can't find what you're looking for?</p>
                <p class="text-xs text-blue-600 mb-3">Our support team is here to help</p>
                <a href="tickets.php" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="link-submit-ticket-bottom">
                    <i class="fas fa-ticket-alt"></i>Submit a Support Ticket
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>