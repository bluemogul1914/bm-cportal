<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$pdo = getDB();
$success_msg = '';
$error_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
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
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
                            <?= csrf_field() ?>
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
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-gray-700">Content</label>
                            <div class="flex items-center gap-2">
                                <button type="button" id="btn-mode-raw" onclick="setEditorMode('raw')" class="px-3 py-1 text-xs rounded border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-medium" data-testid="button-editor-raw"><i class="fas fa-code mr-1"></i>Raw HTML</button>
                                <button type="button" id="btn-mode-rich" onclick="setEditorMode('rich')" class="px-3 py-1 text-xs rounded border border-blue-400 bg-blue-50 text-blue-700 font-medium" data-testid="button-editor-rich"><i class="fas fa-paint-brush mr-1"></i>Rich Text</button>
                                <label class="flex items-center gap-1 cursor-pointer ml-2 text-xs text-gray-600 border border-gray-300 rounded px-2 py-1 hover:bg-gray-50" title="Upload PDF (converts to link)">
                                    <i class="fas fa-file-pdf text-red-500"></i> PDF Upload
                                    <input type="file" id="pdf-upload-input" accept=".pdf" class="hidden" data-testid="input-pdf-upload">
                                </label>
                                <span id="pdf-upload-status" class="text-xs text-gray-500 hidden"></span>
                            </div>
                        </div>
                        <div id="editor-rich-container" class="border border-gray-300 rounded-lg overflow-hidden">
                            <div id="editor-toolbar" class="flex flex-wrap gap-1 px-2 py-1 bg-gray-50 border-b border-gray-200">
                                <button type="button" onclick="execCmd('bold')" class="px-2 py-1 text-xs rounded hover:bg-gray-200 font-bold" title="Bold"><b>B</b></button>
                                <button type="button" onclick="execCmd('italic')" class="px-2 py-1 text-xs rounded hover:bg-gray-200 italic" title="Italic"><i>I</i></button>
                                <button type="button" onclick="execCmd('underline')" class="px-2 py-1 text-xs rounded hover:bg-gray-200 underline" title="Underline">U</button>
                                <span class="w-px bg-gray-300 mx-1"></span>
                                <button type="button" onclick="execCmd('insertUnorderedList')" class="px-2 py-1 text-xs rounded hover:bg-gray-200" title="Bullet List"><i class="fas fa-list-ul"></i></button>
                                <button type="button" onclick="execCmd('insertOrderedList')" class="px-2 py-1 text-xs rounded hover:bg-gray-200" title="Numbered List"><i class="fas fa-list-ol"></i></button>
                                <span class="w-px bg-gray-300 mx-1"></span>
                                <button type="button" onclick="insertHeading(2)" class="px-2 py-1 text-xs rounded hover:bg-gray-200 font-bold" title="H2">H2</button>
                                <button type="button" onclick="insertHeading(3)" class="px-2 py-1 text-xs rounded hover:bg-gray-200 font-bold" title="H3">H3</button>
                                <span class="w-px bg-gray-300 mx-1"></span>
                                <button type="button" onclick="insertLink()" class="px-2 py-1 text-xs rounded hover:bg-gray-200" title="Link"><i class="fas fa-link"></i></button>
                                <button type="button" onclick="execCmd('removeFormat')" class="px-2 py-1 text-xs rounded hover:bg-gray-200 text-red-500" title="Clear Formatting"><i class="fas fa-eraser"></i></button>
                            </div>
                            <div id="rich-editor" contenteditable="true" class="min-h-[250px] p-3 text-sm focus:outline-none" style="line-height:1.7"><?php echo $edit_article['content'] ?? ''; ?></div>
                        </div>
                        <textarea name="content" id="content-hidden" required class="hidden" data-testid="input-article-content"><?php echo htmlspecialchars($edit_article['content'] ?? ''); ?></textarea>
                        <div id="raw-editor-container" class="hidden">
                            <textarea id="raw-editor" name="content_raw" rows="15" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500" placeholder="Enter HTML or plain content..."><?php echo htmlspecialchars($edit_article['content'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <script>
                    let editorMode = 'rich';
                    function setEditorMode(mode) {
                        editorMode = mode;
                        const rich = document.getElementById('editor-rich-container');
                        const raw = document.getElementById('raw-editor-container');
                        if (mode === 'rich') {
                            const rawVal = document.getElementById('raw-editor').value;
                            document.getElementById('rich-editor').innerHTML = rawVal;
                            rich.classList.remove('hidden'); raw.classList.add('hidden');
                            document.getElementById('btn-mode-rich').className = 'px-3 py-1 text-xs rounded border border-blue-400 bg-blue-50 text-blue-700 font-medium';
                            document.getElementById('btn-mode-raw').className = 'px-3 py-1 text-xs rounded border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-medium';
                        } else {
                            const richContent = document.getElementById('rich-editor').innerHTML;
                            document.getElementById('raw-editor').value = richContent;
                            raw.classList.remove('hidden'); rich.classList.add('hidden');
                            document.getElementById('btn-mode-raw').className = 'px-3 py-1 text-xs rounded border border-blue-400 bg-blue-50 text-blue-700 font-medium';
                            document.getElementById('btn-mode-rich').className = 'px-3 py-1 text-xs rounded border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-medium';
                        }
                    }
                    function execCmd(cmd) { document.execCommand(cmd, false, null); document.getElementById('rich-editor').focus(); }
                    function insertHeading(level) {
                        document.execCommand('formatBlock', false, 'h' + level);
                        document.getElementById('rich-editor').focus();
                    }
                    function insertLink() {
                        const url = prompt('Enter URL:');
                        if (url) document.execCommand('createLink', false, url);
                        document.getElementById('rich-editor').focus();
                    }
                    document.querySelector('form').addEventListener('submit', function() {
                        const hidden = document.getElementById('content-hidden');
                        if (editorMode === 'rich') {
                            hidden.value = document.getElementById('rich-editor').innerHTML;
                        } else {
                            hidden.value = document.getElementById('raw-editor').value;
                        }
                    });
                    document.getElementById('pdf-upload-input').addEventListener('change', async function() {
                        if (!this.files || !this.files[0]) return;
                        const statusEl = document.getElementById('pdf-upload-status');
                        statusEl.textContent = 'Uploading PDF...'; statusEl.classList.remove('hidden');
                        const fd = new FormData(); fd.append('pdf', this.files[0]);
                        try {
                            const resp = await fetch('/api/upload/article-pdf', { method: 'POST', body: fd });
                            const data = await resp.json();
                            if (data.success) {
                                const link = `<p><a href="${data.path}" target="_blank" style="color:#2563eb;text-decoration:underline;">📄 ${data.filename}</a></p>`;
                                if (editorMode === 'rich') {
                                    document.getElementById('rich-editor').innerHTML += link;
                                } else {
                                    document.getElementById('raw-editor').value += '\n' + link;
                                }
                                statusEl.textContent = '✓ PDF added to content';
                            } else {
                                statusEl.textContent = 'Error: ' + (data.error || 'Upload failed');
                            }
                        } catch(e) { statusEl.textContent = 'Network error.'; }
                    });
                    </script>

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
                            <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_publish">
                                    <input type="hidden" name="article_id" value="<?php echo $article['id']; ?>">
                                    <button type="submit" class="px-3 py-1.5 <?php echo $article['is_published'] ? 'bg-yellow-100 hover:bg-yellow-200 text-yellow-700' : 'bg-green-100 hover:bg-green-200 text-green-700'; ?> text-xs font-medium rounded-lg transition">
                                        <i class="fas <?php echo $article['is_published'] ? 'fa-eye-slash' : 'fa-eye'; ?> mr-1"></i><?php echo $article['is_published'] ? 'Unpublish' : 'Publish'; ?>
                                    </button>
                                </form>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this article?');">
                            <?= csrf_field() ?>
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