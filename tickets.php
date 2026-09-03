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
$tickets = [];
$status_counts = [];
$total_tickets = 0;

$last_ticket_id = 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_ticket') {
    require_csrf();
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $ticket_group = $_POST['ticket_group'] ?? 'general';
    if (!in_array($ticket_group, ['general','sales','billing','support'])) $ticket_group = 'general';

    if (empty($subject)) {
        $error_msg = 'Subject is required.';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            $client_id = $client ? $client['id'] : $user_id;

            $stmt = $pdo->prepare("INSERT INTO tickets (client_id, subject, description, status, priority, ticket_group, source, created_at, updated_at) VALUES (?, ?, ?, 'open', ?, ?, 'portal', NOW(), NOW()) RETURNING id");
            $stmt->execute([$client_id, $subject, $description, $priority, $ticket_group]);
            $new_ticket_id = $stmt->fetchColumn();
            $last_ticket_id = $new_ticket_id;
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$user_id, 'ticket_created', 'ticket', $new_ticket_id, 'Created ticket: ' . $subject, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            if (!empty($user_email)) {
                notify_ticket_created($new_ticket_id, $subject, $user_email, $user_name);
            }
            $success_msg = "Ticket #$new_ticket_id created successfully!";
        } catch (PDOException $e) {
            error_log("Ticket creation error: " . $e->getMessage());
            $error_msg = 'Failed to create ticket. Please try again.';
        }
    }
}

$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;

    $where = "WHERE t.client_id = ?";
    $params = [$client_id];
    if ($status_filter !== 'all') { $where .= " AND t.status = ?"; $params[] = $status_filter; }
    if (!empty($search)) { $where .= " AND (t.subject ILIKE ? OR t.description ILIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

    $stmt = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = t.id AND is_internal = false) as comment_count FROM tickets t $where ORDER BY t.created_at DESC");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM tickets WHERE client_id = ? GROUP BY status");
    $stmt->execute([$client_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status_counts[$row['status']] = $row['count'];
    }
    $total_tickets = array_sum($status_counts);
} catch (PDOException $e) {
    $tickets = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - Blue Mogul Suite</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
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
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">Support Tickets</h1>
                    <p class="text-sm text-gray-600 mt-1">Create and manage your support requests</p>
                </div>
                <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-create-ticket">
                    <i class="fas fa-plus mr-2"></i>New Ticket
                </button>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6" data-testid="alert-success">
                    <div class="flex items-center mb-2"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php if ($last_ticket_id): ?>
                    <div class="mt-3 pt-3 border-t border-green-200">
                        <p class="text-sm font-medium text-green-800 mb-2"><i class="fas fa-paperclip mr-1"></i>Attach a file to ticket #<?php echo $last_ticket_id; ?> (optional)</p>
                        <div class="flex items-center gap-3">
                            <input type="file" id="ticket-attach-file" accept=".pdf,.gif,.jpeg,.jpg,.txt,.png" class="text-sm text-green-700 file:mr-2 file:py-1 file:px-3 file:border file:border-green-300 file:rounded file:text-xs file:bg-green-50 file:text-green-700" data-testid="input-ticket-attachment">
                            <button onclick="uploadTicketAttachment(<?php echo $last_ticket_id; ?>)" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded transition" data-testid="button-upload-attachment">Upload File</button>
                        </div>
                        <p id="attach-status" class="text-xs mt-1 text-green-600"></p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <a href="tickets.php" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'all' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-all">
                    <p class="text-xs font-semibold text-gray-500 uppercase">All Tickets</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $total_tickets; ?></p>
                </a>
                <a href="tickets.php?status=open" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'open' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-open">
                    <p class="text-xs font-semibold text-gray-500 uppercase">Open</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo ($status_counts['open'] ?? 0); ?></p>
                </a>
                <a href="tickets.php?status=in_progress" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'in_progress' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-in-progress">
                    <p class="text-xs font-semibold text-gray-500 uppercase">In Progress</p>
                    <p class="text-2xl font-bold text-yellow-600 mt-1"><?php echo $status_counts['in_progress'] ?? 0; ?></p>
                </a>
                <a href="tickets.php?status=closed" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'closed' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-closed">
                    <p class="text-xs font-semibold text-gray-500 uppercase">Closed</p>
                    <p class="text-2xl font-bold text-green-600 mt-1"><?php echo ($status_counts['closed'] ?? 0); ?></p>
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <form method="GET" action="tickets.php" class="flex items-center gap-3">
                        <?php if ($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search tickets..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-search">
                        </div>
                        <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium transition" data-testid="button-search">Search</button>
                    </form>
                </div>

                <?php if (empty($tickets)): ?>
                    <div class="text-center py-16">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                            <i class="fas fa-ticket-alt text-gray-400 text-2xl"></i>
                        </div>
                        <p class="text-gray-900 font-semibold mb-1">No tickets found</p>
                        <p class="text-sm text-gray-500 mb-4">Create a support ticket to get help</p>
                        <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition">
                            <i class="fas fa-plus mr-2"></i>Create Ticket
                        </button>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($tickets as $ticket):
                            $tid = $ticket['id'];
                            $t_subject = $ticket['subject'];
                            $t_status = $ticket['status'];
                            $t_priority = $ticket['priority'] ?? 'medium';
                            $t_created = $ticket['created_at'];
                            $t_comments = $ticket['comment_count'] ?? 0;
                            $detail_link = "ticket-detail.php?id=" . urlencode($tid);
                        ?>
                            <a href="<?php echo $detail_link; ?>" class="flex items-center px-6 py-4 hover:bg-gray-50 transition group" data-testid="ticket-row-<?php echo $tid; ?>">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 mb-1">
                                        <span class="text-xs font-mono text-gray-400">#<?php echo $tid; ?></span>
                                        <h3 class="font-medium text-gray-900 truncate group-hover:text-blue-600 transition"><?php echo htmlspecialchars($t_subject); ?></h3>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-gray-500">
                                        <span><i class="far fa-clock mr-1"></i><?php $ts = strtotime($t_created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($t_created); ?></span>
                                        <?php if ($t_comments > 0): ?>
                                            <span><i class="far fa-comment mr-1"></i><?php echo $t_comments; ?> replies</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 ml-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                        echo match($t_priority) {
                                            'urgent' => 'bg-red-200 text-red-800',
                                            'high' => 'bg-red-100 text-red-700',
                                            'medium' => 'bg-yellow-100 text-yellow-700',
                                            'low' => 'bg-green-100 text-green-700',
                                            default => 'bg-gray-100 text-gray-700'
                                        };
                                    ?>"><?php echo ucfirst($t_priority); ?></span>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                        echo match($t_status) {
                                            'open' => 'bg-blue-100 text-blue-700',
                                            'in_progress' => 'bg-yellow-100 text-yellow-700',
                                            'closed' => 'bg-gray-100 text-gray-700',
                                            default => 'bg-gray-100 text-gray-700'
                                        };
                                    ?>"><?php echo ucfirst(str_replace('_', ' ', $t_status)); ?></span>
                                    <i class="fas fa-chevron-right text-gray-300 group-hover:text-blue-500 transition"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="create-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900" id="modal-title">Create New Ticket</h2>
            <button onclick="closeTicketModal()" class="text-gray-400 hover:text-gray-600" data-testid="button-close-modal"><i class="fas fa-times"></i></button>
        </div>

        <div id="modal-create-step" class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                <input type="text" id="new-ticket-subject" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Brief description of your issue" data-testid="input-subject">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select id="new-ticket-group" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-group">
                        <option value="general">General</option>
                        <option value="sales">Sales</option>
                        <option value="billing">Billing</option>
                        <option value="support">Support</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                    <select id="new-ticket-priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-priority">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea id="new-ticket-description" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Describe your issue in detail..." data-testid="textarea-description"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Attachment <span class="text-gray-400 font-normal">(optional)</span></label>
                <input type="file" id="new-ticket-file" accept=".pdf,.gif,.jpeg,.jpg,.txt,.png" class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:border file:border-gray-300 file:rounded file:text-xs file:bg-gray-50 file:text-gray-700 hover:file:bg-gray-100" data-testid="input-ticket-file">
                <p class="text-xs text-gray-400 mt-1">PDF, PNG, JPG, GIF, TXT — max 10 MB</p>
            </div>
            <div id="modal-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 rounded"></div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeTicketModal()" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition" data-testid="button-cancel">Cancel</button>
                <button type="button" onclick="submitTicket()" id="btn-submit-ticket" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-submit-ticket">Create Ticket</button>
            </div>
        </div>

        <div id="modal-success-step" class="hidden p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-check text-green-600"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900">Ticket Created!</p>
                    <p class="text-sm text-gray-500">Your ticket has been submitted successfully.</p>
                </div>
            </div>
            <div id="modal-upload-section" class="hidden bg-gray-50 rounded-lg p-4 mb-4">
                <p class="text-sm font-medium text-gray-700 mb-2"><i class="fas fa-paperclip mr-1 text-blue-500"></i>Uploading your attachment...</p>
                <div id="modal-upload-progress" class="w-full bg-gray-200 rounded-full h-1.5 mb-1">
                    <div id="modal-upload-bar" class="bg-blue-600 h-1.5 rounded-full transition-all" style="width:0%"></div>
                </div>
                <p id="modal-upload-status" class="text-xs text-gray-500"></p>
            </div>
            <div class="flex justify-end gap-3">
                <a id="modal-view-ticket" href="#" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="link-view-ticket">View Ticket</a>
                <button type="button" onclick="location.reload()" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition">Done</button>
            </div>
        </div>
    </div>
</div>
<script>
var _csrfToken = '<?= csrf_token() ?>';

function closeTicketModal() {
    document.getElementById('create-modal').classList.add('hidden');
}
document.getElementById('create-modal').addEventListener('click', function(e) {
    if (e.target === this) closeTicketModal();
});

async function submitTicket() {
    var subject = document.getElementById('new-ticket-subject').value.trim();
    var description = document.getElementById('new-ticket-description').value.trim();
    var priority = document.getElementById('new-ticket-priority').value;
    var ticket_group = document.getElementById('new-ticket-group').value;
    var fileInput = document.getElementById('new-ticket-file');
    var errDiv = document.getElementById('modal-error');
    var btn = document.getElementById('btn-submit-ticket');

    if (!subject) {
        errDiv.textContent = 'Subject is required.';
        errDiv.classList.remove('hidden');
        return;
    }
    errDiv.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Creating...';

    try {
        var resp = await fetch('/api/tickets/create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subject, description, priority, ticket_group, csrf_token: _csrfToken })
        });
        var data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Failed to create ticket');

        var ticketId = data.ticket_id;
        document.getElementById('modal-view-ticket').href = 'ticket-detail.php?id=' + ticketId;
        document.getElementById('modal-create-step').classList.add('hidden');
        document.getElementById('modal-success-step').classList.remove('hidden');
        document.getElementById('modal-title').textContent = 'Ticket #' + ticketId;

        if (fileInput.files.length > 0) {
            var uploadSection = document.getElementById('modal-upload-section');
            uploadSection.classList.remove('hidden');
            var bar = document.getElementById('modal-upload-bar');
            var statusEl = document.getElementById('modal-upload-status');

            var fd = new FormData();
            fd.append('attachment', fileInput.files[0]);
            fd.append('ticket_id', ticketId);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/api/upload/ticket-attachment', true);
            xhr.upload.addEventListener('progress', function(evt) {
                if (evt.lengthComputable) bar.style.width = Math.round((evt.loaded/evt.total)*100) + '%';
            });
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        statusEl.textContent = '✓ File attached successfully.';
                        bar.style.width = '100%';
                        bar.classList.replace('bg-blue-600','bg-green-500');
                    } else {
                        statusEl.textContent = 'Upload failed: ' + (r.error || 'unknown error');
                    }
                } catch(e) { statusEl.textContent = 'Upload failed.'; }
            };
            xhr.onerror = function() { statusEl.textContent = 'Upload failed.'; };
            xhr.send(fd);
        }
    } catch (e) {
        errDiv.textContent = e.message || 'Failed to create ticket.';
        errDiv.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Create Ticket';
    }
}

async function uploadTicketAttachment(ticketId) {
    const input = document.getElementById('ticket-attach-file');
    const status = document.getElementById('attach-status');
    if (!input || !input.files || !input.files[0]) {
        status.textContent = 'Please select a file first.';
        status.className = 'text-xs mt-1 text-red-600';
        return;
    }
    const fd = new FormData();
    fd.append('attachment', input.files[0]);
    fd.append('ticket_id', ticketId);
    status.textContent = 'Uploading...';
    status.className = 'text-xs mt-1 text-green-600';
    try {
        const resp = await fetch('/api/upload/ticket-attachment', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            status.textContent = '✓ File "' + data.name + '" attached successfully.';
            status.className = 'text-xs mt-1 text-green-700 font-medium';
            input.disabled = true;
        } else {
            status.textContent = 'Error: ' + (data.error || 'Upload failed');
            status.className = 'text-xs mt-1 text-red-600';
        }
    } catch(e) {
        status.textContent = 'Network error. Please try again.';
        status.className = 'text-xs mt-1 text-red-600';
    }
}
</script>
</body>
</html>