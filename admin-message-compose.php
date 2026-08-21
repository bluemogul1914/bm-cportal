<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_id = $_SESSION['user_id'];
$pdo = getDB();

$success_msg = '';
$error_msg = '';
$edit_id = intval($_GET['edit'] ?? 0);
$editing = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM messages WHERE id = ? AND status = 'draft'");
    $stmt->execute([$edit_id]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
}

$templates = [];
try {
    $stmt = $pdo->query("SELECT id, name, subject, body, category FROM message_templates ORDER BY name");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$clients = [];
try {
    $stmt = $pdo->query("SELECT id, name, email, company FROM clients WHERE email IS NOT NULL AND email != '' ORDER BY name");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$placeholders = [
    ['var' => '{{ client.name }}', 'desc' => 'Client full name'],
    ['var' => '{{ client.email }}', 'desc' => 'Client email address'],
    ['var' => '{{ client.company }}', 'desc' => 'Company name'],
    ['var' => '{{ client.id }}', 'desc' => 'Client ID'],
    ['var' => '{{ company.name }}', 'desc' => 'Your company name'],
    ['var' => '{{ company.email }}', 'desc' => 'Your support email'],
    ['var' => '{{ company.phone }}', 'desc' => 'Your company phone'],
    ['var' => '{{ date.today }}', 'desc' => 'Today\'s date'],
    ['var' => '{{ date.time }}', 'desc' => 'Current time'],
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $body = $_POST['body'] ?? '';
    $category = $_POST['category'] ?? 'general';
    $recipient_type = $_POST['recipient_type'] ?? 'all';
    $selected_clients = $_POST['selected_clients'] ?? [];

    if ($action === 'save_draft') {
        if (empty($subject)) {
            $error_msg = 'Subject is required.';
        } else {
            try {
                if ($edit_id > 0) {
                    $stmt = $pdo->prepare("UPDATE messages SET subject = ?, body = ?, category = ?, recipient_type = ?, recipient_filter = ? WHERE id = ? AND status = 'draft'");
                    $stmt->execute([$subject, $body, $category, $recipient_type, json_encode($selected_clients), $edit_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO messages (subject, body, category, recipient_type, recipient_filter, sent_by, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')");
                    $stmt->execute([$subject, $body, $category, $recipient_type, json_encode($selected_clients), $user_id]);
                    $edit_id = $pdo->lastInsertId();
                }
                $success_msg = 'Draft saved successfully!';
            } catch (PDOException $e) {
                $error_msg = 'Failed to save draft.';
            }
        }
    } elseif ($action === 'send') {
        if (empty($subject) || empty($body)) {
            $error_msg = 'Subject and message body are required.';
        } else {
            $target_clients = [];
            if ($recipient_type === 'all') {
                $target_clients = $clients;
            } elseif ($recipient_type === 'selected' && !empty($selected_clients)) {
                $ids = array_map('intval', $selected_clients);
                $placeholders_sql = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, name, email, company FROM clients WHERE id IN ($placeholders_sql) AND email IS NOT NULL AND email != ''");
                $stmt->execute($ids);
                $target_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($target_clients)) {
                $error_msg = 'No recipients selected or no clients have email addresses.';
            } else {
                $sent = 0;
                $failed = 0;
                $company_name = 'Blue Mogul';
                $company_email = 'support@bluemogul.biz';
                $company_phone = '';
                try {
                    $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name','company_email','company_phone')");
                    $settings = $s->fetchAll(PDO::FETCH_KEY_PAIR);
                    $company_name = $settings['company_name'] ?? $company_name;
                    $company_email = $settings['company_email'] ?? $company_email;
                    $company_phone = $settings['company_phone'] ?? $company_phone;
                } catch (PDOException $e) {}

                foreach ($target_clients as $client) {
                    $personalized_body = $body;
                    $personalized_subject = $subject;
                    $replacements = [
                        'client.name' => $client['name'] ?? '',
                        'client.email' => $client['email'] ?? '',
                        'client.company' => $client['company'] ?? '',
                        'client.id' => $client['id'] ?? '',
                        'company.name' => $company_name,
                        'company.email' => $company_email,
                        'company.phone' => $company_phone,
                        'date.today' => date('F j, Y'),
                        'date.time' => date('g:i A T'),
                    ];
                    foreach ($replacements as $key => $val) {
                        $pattern = '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/';
                        $personalized_body = preg_replace($pattern, htmlspecialchars($val), $personalized_body);
                        $personalized_subject = preg_replace($pattern, $val, $personalized_subject);
                    }

                    $html = email_template($personalized_subject, '<div style="font-size:14px;color:#374151;line-height:1.6;">' . $personalized_body . '</div>');
                    $result = send_email($client['email'], $personalized_subject, $html);
                    if ($result['success']) {
                        $sent++;
                    } else {
                        $failed++;
                        error_log("Failed to send message to {$client['email']}: " . ($result['error'] ?? 'unknown'));
                    }
                }

                try {
                    if ($edit_id > 0) {
                        $stmt = $pdo->prepare("UPDATE messages SET subject = ?, body = ?, category = ?, recipient_type = ?, recipient_filter = ?, sent_count = ?, failed_count = ?, status = 'sent', sent_at = NOW() WHERE id = ?");
                        $stmt->execute([$subject, $body, $category, $recipient_type, json_encode($selected_clients), $sent, $failed, $edit_id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO messages (subject, body, category, recipient_type, recipient_filter, sent_by, sent_count, failed_count, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())");
                        $stmt->execute([$subject, $body, $category, $recipient_type, json_encode($selected_clients), $user_id, $sent, $failed]);
                    }
                    $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'message_sent', 'message', $edit_id ?: $pdo->lastInsertId(), "Sent '$subject' to $sent clients ($failed failed)", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                } catch (PDOException $e) {}

                $success_msg = "Message sent to $sent client(s)!" . ($failed > 0 ? " ($failed failed)" : '');
            }
        }
    } elseif ($action === 'save_template') {
        $tpl_name = trim($_POST['template_name'] ?? '');
        if (empty($tpl_name) || empty($subject)) {
            $error_msg = 'Template name and subject are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO message_templates (name, subject, body, category, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$tpl_name, $subject, $body, $category, $user_id]);
                $success_msg = "Template '$tpl_name' saved!";
                $stmt = $pdo->query("SELECT id, name, subject, body, category FROM message_templates ORDER BY name");
                $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error_msg = 'Failed to save template.';
            }
        }
    }
}

$categories = [
    'news_blast' => ['label' => 'News Blast', 'color' => 'blue', 'icon' => 'fa-newspaper'],
    'alert' => ['label' => 'Alert', 'color' => 'red', 'icon' => 'fa-exclamation-triangle'],
    'promotion' => ['label' => 'Promotion', 'color' => 'green', 'icon' => 'fa-tag'],
    'network_outage' => ['label' => 'Network Outage', 'color' => 'orange', 'icon' => 'fa-wifi'],
    'maintenance' => ['label' => 'Maintenance', 'color' => 'yellow', 'icon' => 'fa-wrench'],
    'general' => ['label' => 'General', 'color' => 'gray', 'icon' => 'fa-envelope'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editing ? 'Edit Message' : 'New Message'; ?> - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
                        <h1 class="text-2xl font-bold text-gray-900" data-testid="text-page-title">
                            Messaging / <span class="text-blue-600"><?php echo $editing ? 'Edit Message' : 'New Message'; ?></span>
                        </h1>
                        <p class="text-gray-500 mt-1">Compose and send messages to your clients</p>
                    </div>
                </div>
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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <form method="POST" id="compose-form">
                            <?= csrf_field() ?>
                        <input type="hidden" name="action" id="form-action" value="send">

                        <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Message Type</h2>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($categories as $key => $cat): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="category" value="<?php echo $key; ?>"
                                               <?php echo ($editing ? $editing['category'] : 'news_blast') === $key ? 'checked' : ''; ?>
                                               class="hidden peer" data-testid="radio-category-<?php echo $key; ?>">
                                        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium border-2 transition
                                            peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700
                                            border-gray-200 text-gray-600 hover:border-gray-300">
                                            <i class="fas <?php echo $cat['icon']; ?> mr-2"></i>
                                            <?php echo $cat['label']; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Subject <span class="text-red-500">*</span></label>
                                <input type="text" name="subject" id="subject" required
                                       value="<?php echo htmlspecialchars($editing['subject'] ?? ''); ?>"
                                       placeholder="Enter message subject..."
                                       data-testid="input-subject"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Message <span class="text-red-500">*</span></label>
                                <div class="border border-gray-300 rounded-lg overflow-hidden">
                                    <div class="bg-gray-50 border-b border-gray-300 px-3 py-2 flex flex-wrap gap-1">
                                        <button type="button" onclick="execCmd('bold')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Bold"><i class="fas fa-bold"></i></button>
                                        <button type="button" onclick="execCmd('italic')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Italic"><i class="fas fa-italic"></i></button>
                                        <button type="button" onclick="execCmd('underline')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Underline"><i class="fas fa-underline"></i></button>
                                        <span class="border-l border-gray-300 mx-1"></span>
                                        <button type="button" onclick="execCmd('justifyLeft')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Align Left"><i class="fas fa-align-left"></i></button>
                                        <button type="button" onclick="execCmd('justifyCenter')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Align Center"><i class="fas fa-align-center"></i></button>
                                        <button type="button" onclick="execCmd('justifyRight')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Align Right"><i class="fas fa-align-right"></i></button>
                                        <span class="border-l border-gray-300 mx-1"></span>
                                        <button type="button" onclick="execCmd('insertUnorderedList')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Bullet List"><i class="fas fa-list-ul"></i></button>
                                        <button type="button" onclick="execCmd('insertOrderedList')" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Number List"><i class="fas fa-list-ol"></i></button>
                                        <span class="border-l border-gray-300 mx-1"></span>
                                        <select onchange="execCmdVal('formatBlock', this.value); this.value='';" class="px-2 py-1 text-sm text-gray-600 bg-white border border-gray-200 rounded">
                                            <option value="">Styles</option>
                                            <option value="h2">Heading</option>
                                            <option value="h3">Subheading</option>
                                            <option value="p">Paragraph</option>
                                            <option value="blockquote">Quote</option>
                                        </select>
                                        <span class="border-l border-gray-300 mx-1"></span>
                                        <button type="button" onclick="insertLink()" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="Insert Link"><i class="fas fa-link"></i></button>
                                        <button type="button" onclick="toggleSource()" class="px-2 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded" title="View Source"><i class="fas fa-code"></i></button>
                                    </div>
                                    <div id="editor" contenteditable="true" data-testid="editor-body"
                                         class="min-h-[300px] p-4 text-sm text-gray-800 focus:outline-none"
                                         style="line-height:1.6;"><?php echo $editing['body'] ?? ''; ?></div>
                                    <textarea name="body" id="body-hidden" class="hidden"></textarea>
                                    <textarea id="source-editor" class="hidden w-full min-h-[300px] p-4 text-sm font-mono text-gray-800 focus:outline-none border-0"></textarea>
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-sm text-gray-500">
                                <button type="button" onclick="showPreview()" class="hover:text-blue-600 transition" data-testid="button-preview">
                                    <i class="fas fa-search mr-1"></i>Preview
                                </button>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Recipients</h2>
                            <div class="space-y-3">
                                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition">
                                    <input type="radio" name="recipient_type" value="all"
                                           <?php echo ($editing ? $editing['recipient_type'] : 'all') === 'all' ? 'checked' : ''; ?>
                                           onchange="toggleClientSelector()" data-testid="radio-recipients-all"
                                           class="mr-3 text-blue-600">
                                    <div>
                                        <span class="font-medium text-gray-900">All Clients</span>
                                        <p class="text-xs text-gray-500"><?php echo count($clients); ?> clients with email addresses</p>
                                    </div>
                                </label>
                                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition">
                                    <input type="radio" name="recipient_type" value="selected"
                                           <?php echo ($editing && $editing['recipient_type'] === 'selected') ? 'checked' : ''; ?>
                                           onchange="toggleClientSelector()" data-testid="radio-recipients-selected"
                                           class="mr-3 text-blue-600">
                                    <div>
                                        <span class="font-medium text-gray-900">Select Specific Clients</span>
                                        <p class="text-xs text-gray-500">Choose individual recipients</p>
                                    </div>
                                </label>
                            </div>

                            <div id="client-selector" class="mt-4 <?php echo ($editing && $editing['recipient_type'] === 'selected') ? '' : 'hidden'; ?>">
                                <input type="text" id="client-search" placeholder="Search clients..." oninput="filterClients()"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm mb-3 focus:ring-2 focus:ring-blue-500">
                                <div class="max-h-48 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-100">
                                    <?php
                                    $selected_ids = [];
                                    if ($editing && $editing['recipient_filter']) {
                                        $selected_ids = json_decode($editing['recipient_filter'], true) ?: [];
                                    }
                                    ?>
                                    <?php foreach ($clients as $c): ?>
                                        <label class="client-row flex items-center p-2.5 hover:bg-gray-50 cursor-pointer" data-name="<?php echo strtolower($c['name'] . ' ' . $c['email'] . ' ' . $c['company']); ?>">
                                            <input type="checkbox" name="selected_clients[]" value="<?php echo $c['id']; ?>"
                                                   <?php echo in_array($c['id'], $selected_ids) ? 'checked' : ''; ?>
                                                   class="mr-3 text-blue-600 rounded">
                                            <div>
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($c['name']); ?></span>
                                                <span class="text-xs text-gray-500 ml-2"><?php echo htmlspecialchars($c['email']); ?></span>
                                                <?php if ($c['company']): ?>
                                                    <span class="text-xs text-gray-400 ml-1">(<?php echo htmlspecialchars($c['company']); ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex space-x-3">
                                <button type="button" onclick="document.getElementById('form-action').value='save_draft'; document.getElementById('compose-form').submit();"
                                        class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium" data-testid="button-save-draft">
                                    <i class="fas fa-save mr-2"></i>Save Draft
                                </button>
                                <button type="button" onclick="showSaveTemplateModal()"
                                        class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium" data-testid="button-save-template">
                                    <i class="fas fa-file-alt mr-2"></i>Save as Template
                                </button>
                            </div>
                            <button type="button" onclick="confirmSend()"
                                    class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition font-medium" data-testid="button-send">
                                <i class="fas fa-paper-plane mr-2"></i>Send Message
                            </button>
                        </div>
                    </form>
                </div>

                <div class="lg:col-span-1 space-y-6">
                    <?php if (!empty($templates)): ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-5">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-file-alt mr-2 text-blue-600"></i>Load Template</h3>
                            <select id="template-selector" onchange="loadTemplate()" data-testid="select-template"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                                <option value="">Choose a template...</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo $tpl['id']; ?>"
                                            data-subject="<?php echo htmlspecialchars($tpl['subject']); ?>"
                                            data-body="<?php echo htmlspecialchars($tpl['body']); ?>"
                                            data-category="<?php echo htmlspecialchars($tpl['category']); ?>">
                                        <?php echo htmlspecialchars($tpl['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-lg border border-gray-200 p-5">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-code mr-2 text-blue-600"></i>Placeholders</h3>
                        <p class="text-xs text-gray-500 mb-3">Click to insert into your message. These will be replaced with actual client data when sent.</p>
                        <div class="space-y-0.5">
                            <p class="text-xs font-semibold text-gray-500 uppercase mt-2 mb-1">Client</p>
                            <?php foreach ($placeholders as $ph): ?>
                                <?php if ($ph['var'] === '{{ company.name }}'): ?>
                                    <p class="text-xs font-semibold text-gray-500 uppercase mt-3 mb-1">Company</p>
                                <?php endif; ?>
                                <?php if ($ph['var'] === '{{ date.today }}'): ?>
                                    <p class="text-xs font-semibold text-gray-500 uppercase mt-3 mb-1">Date/Time</p>
                                <?php endif; ?>
                                <div class="flex items-center justify-between py-1.5 px-2 hover:bg-gray-50 rounded cursor-pointer group" onclick="insertPlaceholder('<?php echo $ph['var']; ?>')">
                                    <div>
                                        <code class="text-xs text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded font-mono"><?php echo $ph['var']; ?></code>
                                        <span class="text-xs text-gray-400 ml-2"><?php echo $ph['desc']; ?></span>
                                    </div>
                                    <span class="text-xs text-blue-500 opacity-0 group-hover:opacity-100 transition font-medium">COPY</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="preview-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="font-semibold text-gray-900"><i class="fas fa-eye mr-2"></i>Message Preview</h3>
                <button onclick="closePreview()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="p-6 overflow-y-auto max-h-[60vh]">
                <div class="mb-3">
                    <span class="text-xs font-medium text-gray-500">Subject:</span>
                    <p class="text-sm font-semibold text-gray-900" id="preview-subject"></p>
                </div>
                <hr class="my-3">
                <div id="preview-body" class="text-sm text-gray-800" style="line-height:1.6;"></div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-right">
                <button onclick="closePreview()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium">Close</button>
            </div>
        </div>
    </div>

    <div id="template-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="font-semibold text-gray-900"><i class="fas fa-file-alt mr-2"></i>Save as Template</h3>
                <button onclick="closeTemplateModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="p-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Template Name <span class="text-red-500">*</span></label>
                <input type="text" id="template-name-input" placeholder="e.g. Network Outage Notice"
                       data-testid="input-template-name"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
            </div>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end space-x-3">
                <button onclick="closeTemplateModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm font-medium">Cancel</button>
                <button onclick="saveTemplate()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-confirm-save-template">
                    <i class="fas fa-save mr-1"></i>Save Template
                </button>
            </div>
        </div>
    </div>

    <script>
        const editor = document.getElementById('editor');
        const bodyHidden = document.getElementById('body-hidden');
        const sourceEditor = document.getElementById('source-editor');
        let sourceMode = false;

        function syncBody() {
            bodyHidden.value = editor.innerHTML;
        }

        document.getElementById('compose-form').addEventListener('submit', function() {
            if (sourceMode) {
                editor.innerHTML = sourceEditor.value;
            }
            syncBody();
        });

        function execCmd(cmd) {
            document.execCommand(cmd, false, null);
            editor.focus();
        }

        function execCmdVal(cmd, val) {
            if (!val) return;
            document.execCommand(cmd, false, val);
            editor.focus();
        }

        function insertLink() {
            const url = prompt('Enter URL:', 'https://');
            if (url) {
                document.execCommand('createLink', false, url);
                editor.focus();
            }
        }

        function toggleSource() {
            sourceMode = !sourceMode;
            if (sourceMode) {
                sourceEditor.value = editor.innerHTML;
                editor.classList.add('hidden');
                sourceEditor.classList.remove('hidden');
            } else {
                editor.innerHTML = sourceEditor.value;
                sourceEditor.classList.add('hidden');
                editor.classList.remove('hidden');
            }
        }

        function insertPlaceholder(text) {
            editor.focus();
            document.execCommand('insertText', false, text);
        }

        function toggleClientSelector() {
            const sel = document.querySelector('input[name="recipient_type"]:checked').value;
            document.getElementById('client-selector').classList.toggle('hidden', sel !== 'selected');
        }

        function filterClients() {
            const q = document.getElementById('client-search').value.toLowerCase();
            document.querySelectorAll('.client-row').forEach(row => {
                row.style.display = row.dataset.name.includes(q) ? '' : 'none';
            });
        }

        function loadTemplate() {
            const sel = document.getElementById('template-selector');
            const opt = sel.options[sel.selectedIndex];
            if (!opt.value) return;
            document.getElementById('subject').value = opt.dataset.subject || '';
            editor.innerHTML = opt.dataset.body || '';
            const catRadio = document.querySelector('input[name="category"][value="' + (opt.dataset.category || 'general') + '"]');
            if (catRadio) catRadio.checked = true;
        }

        function showPreview() {
            if (sourceMode) editor.innerHTML = sourceEditor.value;
            syncBody();
            document.getElementById('preview-subject').textContent = document.getElementById('subject').value || '(No subject)';
            let body = editor.innerHTML;
            body = body.replace(/\{\{\s*client\.name\s*\}\}/g, 'John Smith');
            body = body.replace(/\{\{\s*client\.email\s*\}\}/g, 'john@example.com');
            body = body.replace(/\{\{\s*client\.company\s*\}\}/g, 'Acme Corp');
            body = body.replace(/\{\{\s*client\.id\s*\}\}/g, '42');
            body = body.replace(/\{\{\s*company\.name\s*\}\}/g, 'Blue Mogul');
            body = body.replace(/\{\{\s*company\.email\s*\}\}/g, 'support@bluemogul.biz');
            body = body.replace(/\{\{\s*company\.phone\s*\}\}/g, '(555) 123-4567');
            body = body.replace(/\{\{\s*date\.today\s*\}\}/g, new Date().toLocaleDateString('en-US', {month:'long',day:'numeric',year:'numeric'}));
            body = body.replace(/\{\{\s*date\.time\s*\}\}/g, new Date().toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit'}));
            document.getElementById('preview-body').innerHTML = body;
            document.getElementById('preview-modal').classList.remove('hidden');
        }

        function closePreview() {
            document.getElementById('preview-modal').classList.add('hidden');
        }

        function showSaveTemplateModal() {
            document.getElementById('template-modal').classList.remove('hidden');
            document.getElementById('template-name-input').focus();
        }

        function closeTemplateModal() {
            document.getElementById('template-modal').classList.add('hidden');
        }

        function saveTemplate() {
            const name = document.getElementById('template-name-input').value.trim();
            if (!name) { alert('Please enter a template name.'); return; }
            if (sourceMode) editor.innerHTML = sourceEditor.value;
            syncBody();
            const form = document.getElementById('compose-form');
            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = 'template_name';
            nameInput.value = name;
            form.appendChild(nameInput);
            document.getElementById('form-action').value = 'save_template';
            form.submit();
        }

        function confirmSend() {
            const recipientType = document.querySelector('input[name="recipient_type"]:checked').value;
            let recipientDesc = recipientType === 'all' ? 'ALL clients (<?php echo count($clients); ?>)' :
                document.querySelectorAll('input[name="selected_clients[]"]:checked').length + ' selected client(s)';

            if (!document.getElementById('subject').value.trim()) {
                alert('Please enter a subject.');
                return;
            }

            if (!confirm('Send this message to ' + recipientDesc + '?\n\nThis action cannot be undone.')) return;

            if (sourceMode) editor.innerHTML = sourceEditor.value;
            syncBody();
            document.getElementById('form-action').value = 'send';
            document.getElementById('compose-form').submit();
        }

        document.addEventListener('click', function(e) {
            if (e.target === document.getElementById('preview-modal')) closePreview();
            if (e.target === document.getElementById('template-modal')) closeTemplateModal();
        });
    </script>
</body>
</html>
