<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_id = $_SESSION['user_id'];
$pdo = getDB();

$success_msg = '';
$error_msg = '';

$categories = [
    'news_blast' => ['label' => 'News Blast', 'color' => 'blue', 'icon' => 'fa-newspaper'],
    'alert' => ['label' => 'Alert', 'color' => 'red', 'icon' => 'fa-exclamation-triangle'],
    'promotion' => ['label' => 'Promotion', 'color' => 'green', 'icon' => 'fa-tag'],
    'network_outage' => ['label' => 'Network Outage', 'color' => 'orange', 'icon' => 'fa-wifi'],
    'maintenance' => ['label' => 'Maintenance', 'color' => 'yellow', 'icon' => 'fa-wrench'],
    'general' => ['label' => 'General', 'color' => 'gray', 'icon' => 'fa-envelope'],
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $tpl_id = intval($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = $_POST['body'] ?? '';
        $category = $_POST['category'] ?? 'general';

        if (empty($name)) {
            $error_msg = 'Template name is required.';
        } else {
            try {
                if ($action === 'update' && $tpl_id > 0) {
                    $stmt = $pdo->prepare("UPDATE message_templates SET name = ?, subject = ?, body = ?, category = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$name, $subject, $body, $category, $tpl_id]);
                    $success_msg = 'Template updated!';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO message_templates (name, subject, body, category, created_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $subject, $body, $category, $user_id]);
                    $success_msg = 'Template created!';
                }
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'template_' . $action . 'd', 'template', $tpl_id ?: $pdo->lastInsertId(), ($action === 'update' ? 'Updated' : 'Created') . ' template: ' . $name, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            } catch (PDOException $e) {
                $error_msg = 'Failed to save template.';
            }
        }
    } elseif ($action === 'delete') {
        $tpl_id = intval($_POST['template_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM message_templates WHERE id = ?")->execute([$tpl_id]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'template_deleted', 'template', $tpl_id, 'Deleted template #' . $tpl_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Template deleted.';
        } catch (PDOException $e) {
            $error_msg = 'Failed to delete template.';
        }
    }
}

try {
    $stmt = $pdo->query("SELECT t.*, u.name as creator_name FROM message_templates t LEFT JOIN users u ON t.created_by = u.id ORDER BY t.name");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $templates = [];
}

$edit_tpl = null;
$edit_id = intval($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    foreach ($templates as $t) {
        if ($t['id'] == $edit_id) { $edit_tpl = $t; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message Templates - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#1a56db', secondary: '#0d1b3e' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans min-h-screen flex">
    <?php include 'includes/admin-sidebar.php'; ?>

    <div class="flex-1 overflow-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center space-x-4">
                    <a href="admin-messages.php" class="text-gray-500 hover:text-gray-700 transition">
                        <i class="fas fa-arrow-left text-lg"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900" data-testid="text-page-title">Message Templates</h1>
                        <p class="text-gray-500 mt-1">Create and manage reusable message templates</p>
                    </div>
                </div>
                <button onclick="showCreateForm()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition font-medium" data-testid="button-new-template">
                    <i class="fas fa-plus mr-2"></i>New Template
                </button>
            </div>

            <?php if (!empty($success_msg)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-800"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-800"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></p>
                </div>
            <?php endif; ?>

            <div id="template-form" class="bg-white rounded-lg border border-gray-200 p-6 mb-6 <?php echo ($edit_tpl || isset($_GET['new'])) ? '' : 'hidden'; ?>">
                <h2 class="text-lg font-semibold text-gray-900 mb-4" id="form-title"><?php echo $edit_tpl ? 'Edit Template' : 'Create Template'; ?></h2>
                <form method="POST">
                    <input type="hidden" name="action" value="<?php echo $edit_tpl ? 'update' : 'create'; ?>">
                    <?php if ($edit_tpl): ?>
                        <input type="hidden" name="template_id" value="<?php echo $edit_tpl['id']; ?>">
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Template Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" required value="<?php echo htmlspecialchars($edit_tpl['name'] ?? ''); ?>"
                                   placeholder="e.g. Scheduled Maintenance Notice"
                                   data-testid="input-template-name"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <select name="category" data-testid="select-template-category" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($categories as $key => $cat): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($edit_tpl && $edit_tpl['category'] === $key) ? 'selected' : ''; ?>><?php echo $cat['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                        <input type="text" name="subject" value="<?php echo htmlspecialchars($edit_tpl['subject'] ?? ''); ?>"
                               placeholder="Message subject line..."
                               data-testid="input-template-subject"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Body</label>
                        <div class="border border-gray-300 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 border-b border-gray-300 px-3 py-2 flex flex-wrap gap-1">
                                <button type="button" onclick="tplExecCmd('bold')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded"><i class="fas fa-bold"></i></button>
                                <button type="button" onclick="tplExecCmd('italic')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded"><i class="fas fa-italic"></i></button>
                                <button type="button" onclick="tplExecCmd('underline')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded"><i class="fas fa-underline"></i></button>
                                <span class="border-l border-gray-300 mx-1"></span>
                                <button type="button" onclick="tplExecCmd('insertUnorderedList')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded"><i class="fas fa-list-ul"></i></button>
                                <button type="button" onclick="tplExecCmd('insertOrderedList')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded"><i class="fas fa-list-ol"></i></button>
                            </div>
                            <div id="tpl-editor" contenteditable="true" data-testid="editor-template-body"
                                 class="min-h-[200px] p-4 text-sm text-gray-800 focus:outline-none"><?php echo $edit_tpl['body'] ?? ''; ?></div>
                            <textarea name="body" id="tpl-body-hidden" class="hidden"></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="hideForm()" class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium text-sm">Cancel</button>
                        <button type="submit" onclick="document.getElementById('tpl-body-hidden').value = document.getElementById('tpl-editor').innerHTML;"
                                class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium text-sm" data-testid="button-save-template-form">
                            <i class="fas fa-save mr-2"></i><?php echo $edit_tpl ? 'Update Template' : 'Create Template'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <?php if (empty($templates)): ?>
                <div class="bg-white rounded-lg border border-gray-200 py-16 text-center">
                    <i class="fas fa-file-alt text-5xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-semibold text-gray-700 mb-2">No templates yet</h3>
                    <p class="text-gray-500 mb-6">Create reusable templates for common messages like outage notices, billing reminders, and promotions.</p>
                    <button onclick="showCreateForm()" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition" data-testid="button-create-first-template">
                        <i class="fas fa-plus mr-2"></i>Create First Template
                    </button>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($templates as $tpl): ?>
                        <?php $cat = $categories[$tpl['category']] ?? $categories['general']; ?>
                        <div class="bg-white rounded-lg border border-gray-200 hover:shadow-md transition" data-testid="card-template-<?php echo $tpl['id']; ?>">
                            <div class="p-5">
                                <div class="flex items-start justify-between mb-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-<?php echo $cat['color']; ?>-100 text-<?php echo $cat['color']; ?>-800">
                                        <i class="fas <?php echo $cat['icon']; ?> mr-1"></i><?php echo $cat['label']; ?>
                                    </span>
                                    <div class="flex space-x-2">
                                        <a href="admin-message-compose.php" onclick="localStorage.setItem('loadTemplate','<?php echo $tpl['id']; ?>')" class="text-green-600 hover:text-green-800 text-sm" title="Use in message">
                                            <i class="fas fa-paper-plane"></i>
                                        </a>
                                        <a href="?edit=<?php echo $tpl['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this template?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <h3 class="font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($tpl['name']); ?></h3>
                                <?php if (!empty($tpl['subject'])): ?>
                                    <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($tpl['subject']); ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-400 line-clamp-2"><?php echo htmlspecialchars(strip_tags(substr($tpl['body'], 0, 150))); ?></p>
                            </div>
                            <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 rounded-b-lg">
                                <div class="flex items-center justify-between text-xs text-gray-500">
                                    <span>By <?php echo htmlspecialchars($tpl['creator_name'] ?? 'Admin'); ?></span>
                                    <span><?php echo date('M j, Y', strtotime($tpl['updated_at'] ?? $tpl['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showCreateForm() {
            document.getElementById('template-form').classList.remove('hidden');
            window.scrollTo({top: 0, behavior: 'smooth'});
        }

        function hideForm() {
            document.getElementById('template-form').classList.add('hidden');
        }

        function tplExecCmd(cmd) {
            document.execCommand(cmd, false, null);
            document.getElementById('tpl-editor').focus();
        }
    </script>
</body>
</html>
