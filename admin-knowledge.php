<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    header('Location: /portal');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$pdo = getDB();
$success_msg = '';
$error_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_article') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $tags = trim($_POST['tags'] ?? '');
        $is_published = isset($_POST['is_published']) ? true : false;

        if (empty($title) || empty($content)) {
            $error_msg = 'Title and content are required.';
        } else {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)));
            $slug = trim($slug, '-');
            $existing = $pdo->prepare("SELECT id FROM knowledge_articles WHERE slug = ?");
            $existing->execute([$slug]);
            if ($existing->fetch()) {
                $slug .= '-' . time();
            }

            $stmt = $pdo->prepare("INSERT INTO knowledge_articles (title, slug, content, category, tags, is_published, author_id) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$title, $slug, $content, $category, $tags, $is_published, $_SESSION['user_id']]);
            $success_msg = 'Article created successfully.';
        }
    } elseif ($action === 'update_article') {
        $id = intval($_POST['article_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $tags = trim($_POST['tags'] ?? '');
        $is_published = isset($_POST['is_published']) ? true : false;

        if ($id && $title && $content) {
            $stmt = $pdo->prepare("UPDATE knowledge_articles SET title = ?, content = ?, category = ?, tags = ?, is_published = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $content, $category, $tags, $is_published, $id]);
            $success_msg = 'Article updated.';
        }
    } elseif ($action === 'delete_article') {
        $id = intval($_POST['article_id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM knowledge_articles WHERE id = ?");
            $stmt->execute([$id]);
            $success_msg = 'Article deleted.';
        }
    } elseif ($action === 'toggle_publish') {
        $id = intval($_POST['article_id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE knowledge_articles SET is_published = NOT is_published, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $success_msg = 'Article status updated.';
        }
    }
}

$edit_id = intval($_GET['edit'] ?? 0);
$edit_article = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM knowledge_articles WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_article = $stmt->fetch(PDO::FETCH_ASSOC);
}

$filter_cat = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

$query = "SELECT ka.*, u.name as author_name FROM knowledge_articles ka LEFT JOIN users u ON ka.author_id = u.id WHERE 1=1";
$params = [];
if ($filter_cat) {
    $query .= " AND ka.category = ?";
    $params[] = $filter_cat;
}
if ($search) {
    $query .= " AND (ka.title ILIKE ? OR ka.content ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= " ORDER BY ka.updated_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat_stmt = $pdo->query("SELECT DISTINCT category FROM knowledge_articles ORDER BY category");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

$total = count($articles);
$published = 0;
$drafts = 0;
$total_views = 0;
foreach ($articles as $a) {
    if ($a['is_published']) $published++;
    else $drafts++;
    $total_views += $a['view_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Base - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-book text-blue-500 mr-2"></i>Knowledge Base</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Help articles for clients — self-service support</p>
                </div>
                <a href="?edit=new" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-new-article">
                    <i class="fas fa-plus mr-1"></i>New Article
                </a>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm mb-4"><i class="fas fa-check-circle mr-2"></i><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm mb-4"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <?php if ($edit_article || isset($_GET['edit']) && $_GET['edit'] === 'new'): ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><?php echo $edit_article ? 'Edit Article' : 'New Article'; ?></h2>
                    <a href="admin-knowledge.php" class="text-sm text-gray-500 hover:text-gray-700"><i class="fas fa-times mr-1"></i>Cancel</a>
                </div>
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="<?php echo $edit_article ? 'update_article' : 'create_article'; ?>">
                    <?php if ($edit_article): ?><input type="hidden" name="article_id" value="<?php echo $edit_article['id']; ?>"><?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                            <input type="text" name="title" value="<?php echo htmlspecialchars($edit_article['title'] ?? ''); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-article-title">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="Getting Started" <?php echo ($edit_article['category'] ?? '') === 'Getting Started' ? 'selected' : ''; ?>>Getting Started</option>
                                    <option value="Billing" <?php echo ($edit_article['category'] ?? '') === 'Billing' ? 'selected' : ''; ?>>Billing</option>
                                    <option value="Email & Communication" <?php echo ($edit_article['category'] ?? '') === 'Email & Communication' ? 'selected' : ''; ?>>Email & Communication</option>
                                    <option value="Network & Security" <?php echo ($edit_article['category'] ?? '') === 'Network & Security' ? 'selected' : ''; ?>>Network & Security</option>
                                    <option value="Products & Services" <?php echo ($edit_article['category'] ?? '') === 'Products & Services' ? 'selected' : ''; ?>>Products & Services</option>
                                    <option value="Troubleshooting" <?php echo ($edit_article['category'] ?? '') === 'Troubleshooting' ? 'selected' : ''; ?>>Troubleshooting</option>
                                    <option value="general" <?php echo ($edit_article['category'] ?? '') === 'general' ? 'selected' : ''; ?>>General</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tags</label>
                                <input type="text" name="tags" value="<?php echo htmlspecialchars($edit_article['tags'] ?? ''); ?>" placeholder="comma-separated" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Content <span class="text-gray-400 font-normal">(supports Markdown)</span></label>
                        <textarea name="content" rows="15" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-article-content"><?php echo htmlspecialchars($edit_article['content'] ?? ''); ?></textarea>
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_published" <?php echo ($edit_article['is_published'] ?? false) ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                            <span class="text-sm text-gray-700">Published (visible to clients)</span>
                        </label>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-article">
                            <i class="fas fa-save mr-1"></i><?php echo $edit_article ? 'Update Article' : 'Create Article'; ?>
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Articles</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $total; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Published</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo $published; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Drafts</p>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $drafts; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Views</p>
                    <p class="text-2xl font-bold text-blue-600"><?php echo number_format($total_views); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-4">
                <div class="px-6 py-4 border-b border-gray-100">
                    <form method="GET" class="flex items-center gap-3">
                        <div class="relative flex-1">
                            <i class="fas fa-search text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 text-xs"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search articles..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-search-articles">
                        </div>
                        <select name="category" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_cat === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">Filter</button>
                    </form>
                </div>

                <div class="divide-y divide-gray-100">
                    <?php foreach ($articles as $article): ?>
                        <div class="px-6 py-4 hover:bg-gray-50 transition flex items-center gap-4" data-testid="article-row-<?php echo $article['id']; ?>">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <h3 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($article['title']); ?></h3>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $article['is_published'] ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                        <?php echo $article['is_published'] ? 'Published' : 'Draft'; ?>
                                    </span>
                                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-[10px] font-medium"><?php echo htmlspecialchars($article['category']); ?></span>
                                </div>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars(substr($article['content'], 0, 120)); ?>...</p>
                                <div class="flex items-center gap-4 mt-1.5">
                                    <span class="text-[10px] text-gray-400"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($article['author_name'] ?? 'Unknown'); ?></span>
                                    <span class="text-[10px] text-gray-400"><i class="fas fa-eye mr-1"></i><?php echo $article['view_count']; ?> views</span>
                                    <span class="text-[10px] text-gray-400"><i class="fas fa-clock mr-1"></i><?php echo date('M d, Y', strtotime($article['updated_at'])); ?></span>
                                    <?php if ($article['tags']): ?>
                                        <?php foreach (explode(',', $article['tags']) as $tag): ?>
                                            <span class="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded text-[10px]"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="?edit=<?php echo $article['id']; ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition"><i class="fas fa-edit mr-1"></i>Edit</a>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_publish">
                                    <input type="hidden" name="article_id" value="<?php echo $article['id']; ?>">
                                    <button type="submit" class="px-3 py-1.5 <?php echo $article['is_published'] ? 'bg-yellow-100 hover:bg-yellow-200 text-yellow-700' : 'bg-green-100 hover:bg-green-200 text-green-700'; ?> text-xs font-medium rounded-lg transition">
                                        <i class="fas <?php echo $article['is_published'] ? 'fa-eye-slash' : 'fa-eye'; ?> mr-1"></i><?php echo $article['is_published'] ? 'Unpublish' : 'Publish'; ?>
                                    </button>
                                </form>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this article?');">
                                    <input type="hidden" name="action" value="delete_article">
                                    <input type="hidden" name="article_id" value="<?php echo $article['id']; ?>">
                                    <button type="submit" class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 text-xs font-medium rounded-lg transition"><i class="fas fa-trash-alt mr-1"></i>Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($articles)): ?>
                        <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-book text-gray-300 text-2xl mb-2 block"></i>No articles found</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>