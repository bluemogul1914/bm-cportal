<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /portal');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$success_msg = '';
$error_msg = '';
$pdo = getDB();

$stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
$stmt->execute([$user_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
$client_id = $client ? $client['id'] : $user_id;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $doc_name = trim($_POST['doc_name'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $description = trim($_POST['description'] ?? '');

        if (empty($doc_name)) {
            $error_msg = 'Document name is required.';
        } else {
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc_name) . '_' . time() . '.txt';
            $filepath = 'uploads/' . $filename;

            try {
                $stmt = $pdo->prepare("INSERT INTO documents (client_id, uploaded_by, name, filename, filepath, filesize, mimetype, category, description, is_public, created_at) VALUES (?, ?, ?, ?, ?, 0, 'text/plain', ?, ?, false, NOW())");
                $stmt->execute([$client_id, $user_id, $doc_name, $filename, $filepath, $category, $description]);
                $success_msg = 'Document record created successfully!';
            } catch (PDOException $e) {
                error_log("Document upload error: " . $e->getMessage());
                $error_msg = 'Failed to create document record.';
            }
        }
    } elseif ($action === 'delete') {
        $doc_id = intval($_POST['doc_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ? AND client_id = ?");
            $stmt->execute([$doc_id, $client_id]);
            $success_msg = 'Document deleted successfully.';
        } catch (PDOException $e) {
            error_log("Document delete error: " . $e->getMessage());
            $error_msg = 'Failed to delete document.';
        }
    }
}

$category_filter = $_GET['category'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $where = "WHERE d.client_id = ?";
    $params = [$client_id];

    if ($category_filter !== 'all') {
        $where .= " AND d.category = ?";
        $params[] = $category_filter;
    }

    if (!empty($search)) {
        $where .= " AND (d.name ILIKE ? OR d.description ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $stmt = $pdo->prepare("SELECT d.*, u.name as uploader_name FROM documents d LEFT JOIN users u ON d.uploaded_by = u.id $where ORDER BY d.created_at DESC");
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT category, COUNT(*) as count FROM documents WHERE client_id = ? GROUP BY category ORDER BY category");
    $stmt->execute([$client_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Documents fetch error: " . $e->getMessage());
    $documents = [];
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - Blue Mogul Client Portal</title>
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
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Documents</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage your files and documents</p>
                </div>
                <button onclick="document.getElementById('upload-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-upload">
                    <i class="fas fa-upload mr-2"></i>Upload Document
                </button>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <div class="flex items-center gap-3 mb-6">
                <a href="documents.php" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $category_filter === 'all' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition">All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="documents.php?category=<?php echo urlencode($cat['category']); ?>" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $category_filter === $cat['category'] ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition">
                        <?php echo ucfirst($cat['category']); ?> (<?php echo $cat['count']; ?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($documents)): ?>
                <div class="bg-white rounded-lg border border-gray-200 text-center py-16">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-folder-open text-gray-400 text-2xl"></i>
                    </div>
                    <p class="text-gray-900 font-semibold mb-1">No documents found</p>
                    <p class="text-sm text-gray-500 mb-4">Upload your first document to get started</p>
                    <button onclick="document.getElementById('upload-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition">
                        <i class="fas fa-upload mr-2"></i>Upload Document
                    </button>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($documents as $doc): ?>
                            <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition" data-testid="document-row-<?php echo $doc['id']; ?>">
                                <div class="flex items-center gap-4 flex-1">
                                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                                        <i class="fas <?php
                                            $ext = pathinfo($doc['filename'], PATHINFO_EXTENSION);
                                            echo match($ext) {
                                                'pdf' => 'fa-file-pdf text-red-500',
                                                'doc', 'docx' => 'fa-file-word text-blue-500',
                                                'xls', 'xlsx' => 'fa-file-excel text-green-500',
                                                'jpg', 'jpeg', 'png', 'gif' => 'fa-file-image text-purple-500',
                                                default => 'fa-file text-gray-500'
                                            };
                                        ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-medium text-gray-900 text-sm truncate"><?php echo htmlspecialchars($doc['name']); ?></h3>
                                        <div class="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
                                            <span class="px-1.5 py-0.5 bg-gray-100 rounded text-xs"><?php echo ucfirst($doc['category']); ?></span>
                                            <span><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                                            <?php if ($doc['description']): ?>
                                                <span class="truncate max-w-xs"><?php echo htmlspecialchars($doc['description']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 ml-4">
                                    <form method="POST" action="documents.php" onsubmit="return confirm('Delete this document?');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-600 p-2 transition" title="Delete" data-testid="button-delete-<?php echo $doc['id']; ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="upload-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Upload Document</h2>
            <button onclick="document.getElementById('upload-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="documents.php" class="p-6 space-y-4">
            <input type="hidden" name="action" value="upload">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Name *</label>
                <input type="text" name="doc_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Service Agreement" data-testid="input-doc-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-category">
                    <option value="general">General</option>
                    <option value="contracts">Contracts</option>
                    <option value="invoices">Invoices</option>
                    <option value="reports">Reports</option>
                    <option value="proposals">Proposals</option>
                    <option value="technical">Technical</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional description..." data-testid="textarea-description"></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('upload-modal').classList.add('hidden')" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-submit-upload">Upload</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('upload-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
</body>
</html>