<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
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
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $doc_id = intval($_POST['doc_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT filepath FROM documents WHERE id = ? AND client_id = ?");
            $stmt->execute([$doc_id, $client_id]);
            $docRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($docRow && !empty($docRow['filepath']) && file_exists($docRow['filepath'])) {
                @unlink($docRow['filepath']);
            }
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ? AND client_id = ?");
            $stmt->execute([$doc_id, $client_id]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'document_deleted', 'document', $doc_id, 'Deleted document #' . $doc_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
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
                                    <?php if ($doc['filesize'] > 0): ?>
                                        <span class="text-xs text-gray-400 mr-2"><?php
                                            $size = $doc['filesize'];
                                            if ($size >= 1048576) echo round($size / 1048576, 1) . ' MB';
                                            elseif ($size >= 1024) echo round($size / 1024, 1) . ' KB';
                                            else echo $size . ' B';
                                        ?></span>
                                        <a href="/api/documents/download/<?php echo $doc['id']; ?>" class="text-blue-400 hover:text-blue-600 p-2 transition" title="Download" data-testid="button-download-<?php echo $doc['id']; ?>">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" action="documents.php" onsubmit="return confirm('Delete this document?');" class="inline">
                            <?= csrf_field() ?>
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
        <form id="upload-form" class="p-6 space-y-4" onsubmit="return handleUpload(event)">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">File *</label>
                <input type="file" name="file" id="upload-file" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" data-testid="input-file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.jpg,.jpeg,.png,.gif,.bmp,.svg,.webp,.zip,.rar,.7z,.tar,.gz">
                <p class="text-xs text-gray-400 mt-1">Max file size: 25 MB. Allowed: PDF, DOC, XLS, PPT, images, archives.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Name *</label>
                <input type="text" name="doc_name" id="upload-doc-name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Service Agreement" data-testid="input-doc-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category" id="upload-category" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-category">
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
                <textarea name="description" id="upload-description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional description..." data-testid="textarea-description"></textarea>
            </div>
            <div id="upload-progress" class="hidden">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div id="upload-progress-bar" class="bg-blue-600 h-2 rounded-full transition-all" style="width: 0%"></div>
                </div>
                <p id="upload-status" class="text-xs text-gray-500 mt-1">Uploading...</p>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('upload-modal').classList.add('hidden')" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition">Cancel</button>
                <button type="submit" id="upload-submit-btn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-submit-upload">Upload</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('upload-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});

document.getElementById('upload-file').addEventListener('change', function() {
    var nameInput = document.getElementById('upload-doc-name');
    if (this.files.length > 0 && !nameInput.value.trim()) {
        var fileName = this.files[0].name;
        nameInput.value = fileName.replace(/\.[^/.]+$/, '').replace(/[_-]/g, ' ');
    }
});

function handleUpload(e) {
    e.preventDefault();
    var fileInput = document.getElementById('upload-file');
    var docName = document.getElementById('upload-doc-name').value.trim();
    var category = document.getElementById('upload-category').value;
    var description = document.getElementById('upload-description').value.trim();
    var submitBtn = document.getElementById('upload-submit-btn');
    var progressDiv = document.getElementById('upload-progress');
    var progressBar = document.getElementById('upload-progress-bar');
    var statusText = document.getElementById('upload-status');

    if (!fileInput.files.length) {
        alert('Please select a file to upload.');
        return false;
    }
    if (!docName) {
        alert('Please enter a document name.');
        return false;
    }

    var maxSize = 25 * 1024 * 1024;
    if (fileInput.files[0].size > maxSize) {
        alert('File is too large. Maximum size is 25 MB.');
        return false;
    }

    var formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('doc_name', docName);
    formData.append('category', category);
    formData.append('description', description);
    formData.append('csrf_token', '<?= csrf_token() ?>');

    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';
    progressDiv.classList.remove('hidden');
    progressBar.style.width = '0%';
    statusText.textContent = 'Uploading...';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/documents/upload', true);

    xhr.upload.addEventListener('progress', function(evt) {
        if (evt.lengthComputable) {
            var pct = Math.round((evt.loaded / evt.total) * 100);
            progressBar.style.width = pct + '%';
            statusText.textContent = 'Uploading... ' + pct + '%';
        }
    });

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success) {
                    statusText.textContent = 'Upload complete!';
                    progressBar.style.width = '100%';
                    window.location.reload();
                } else {
                    alert(resp.error || 'Upload failed.');
                    resetUploadForm();
                }
            } catch(err) {
                alert('Upload failed: unexpected response.');
                resetUploadForm();
            }
        } else {
            try {
                var errResp = JSON.parse(xhr.responseText);
                alert(errResp.error || 'Upload failed (status ' + xhr.status + ')');
            } catch(err) {
                alert('Upload failed (status ' + xhr.status + ')');
            }
            resetUploadForm();
        }
    };

    xhr.onerror = function() {
        alert('Upload failed: network error.');
        resetUploadForm();
    };

    xhr.send(formData);
    return false;
}

function resetUploadForm() {
    var submitBtn = document.getElementById('upload-submit-btn');
    var progressDiv = document.getElementById('upload-progress');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Upload';
    progressDiv.classList.add('hidden');
}
</script>
</body>
</html>