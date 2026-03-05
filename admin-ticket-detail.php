<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$sd_connected = !empty(ITARIAN_SD_API_KEY);
$sd_url = rtrim(ITARIAN_SD_URL, '/');
$is_sd_source = isset($_GET['source']) && $_GET['source'] === 'sd';

$ticket_id = $_GET['id'] ?? '';
if (empty($ticket_id)) {
    portal_redirect('/portal/admin-tickets.php');
}

$success_msg = '';
$error_msg = '';
$ticket = null;
$comments = [];
$api_ticket = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];

    if ($action === 'post_reply_sd' && $sd_connected) {
        $message = trim($_POST['message'] ?? '');
        $reply_email = trim($_POST['reply_email'] ?? $user_email);
        if (!empty($message)) {
            $result = itarian_sd_api('ticketpostreply', [
                'email' => $reply_email,
                'ticketId' => intval($ticket_id),
                'message' => $message,
            ]);
            if (isset($result['error'])) {
                $error_msg = 'Failed to post reply to Service Desk: ' . $result['error'];
            } else {
                $success_msg = 'Reply posted to Service Desk.';
            }
        }
    } elseif ($action === 'close_ticket_sd' && $sd_connected) {
        $result = itarian_sd_api('closeTicket', ['ticketId' => intval($ticket_id)]);
        if (!isset($result['error'])) {
            $success_msg = 'Ticket closed in Service Desk.';
        }
    } elseif ($action === 'add_reply') {
        $comment = trim($_POST['comment'] ?? '');
        $is_internal = isset($_POST['is_internal']) ? true : false;
        if (!empty($comment)) {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([intval($ticket_id), $user_id, $comment, $is_internal]);
                $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([intval($ticket_id)]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'comment_added', 'ticket', $ticket_id, ($is_internal ? 'Internal note on' : 'Reply on') . ' ticket #' . $ticket_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                if (!$is_internal) {
                    $t_stmt = $pdo->prepare("SELECT t.subject, c.email, c.name FROM tickets t LEFT JOIN clients c ON t.client_id = c.id WHERE t.id = ?");
                    $t_stmt->execute([intval($ticket_id)]);
                    $t_info = $t_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($t_info && !empty($t_info['email'])) {
                        notify_ticket_reply(intval($ticket_id), $t_info['subject'], $t_info['email'], $t_info['name'] ?? 'Client', $comment, $user_name);
                    }
                }
                $success_msg = $is_internal ? 'Internal note added.' : 'Reply sent.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to add reply.';
            }
        }
    } elseif ($action === 'update_ticket') {
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE tickets SET status=?, priority=?, assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$_POST['status'], $_POST['priority'], trim($_POST['assigned_to'] ?? ''), intval($ticket_id)]);
            $success_msg = 'Ticket updated.';
        } catch (PDOException $e) {
            $error_msg = 'Failed to update ticket.';
        }
    }
}

if ($is_sd_source && $sd_connected) {
    $result = itarian_sd_api('listtickets', []);
    if (!isset($result['error'])) {
        $all_tickets = $result['data'] ?? $result['tickets'] ?? $result ?? [];
        if (is_array($all_tickets)) {
            foreach ($all_tickets as $t) {
                $tid = (string)($t['ticketId'] ?? $t['id'] ?? '');
                if ($tid === (string)$ticket_id) {
                    $ticket = $t;
                    $api_ticket = true;
                    break;
                }
            }
        }
    }
    if ($api_ticket) {
        $replies_result = itarian_sd_api('getticketreplies', ['ticketId' => intval($ticket_id)]);
        if (!isset($replies_result['error'])) {
            $comments = $replies_result['data'] ?? $replies_result['replies'] ?? [];
            if (!is_array($comments)) $comments = [];
        }
    }
}

if (!$api_ticket) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT t.*, c.name as client_name, c.email as client_email, c.company as client_company FROM tickets t LEFT JOIN clients c ON t.client_id = c.id WHERE t.id = ?");
        $stmt->execute([intval($ticket_id)]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            portal_redirect('/portal/admin-tickets.php');
        }
        $stmt = $pdo->prepare("SELECT tc.*, u.name as author_name, u.is_admin as author_is_admin FROM ticket_comments tc LEFT JOIN users u ON tc.user_id = u.id WHERE tc.ticket_id = ? ORDER BY tc.created_at ASC");
        $stmt->execute([intval($ticket_id)]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        portal_redirect('/portal/admin-tickets.php');
    }
}

if ($api_ticket) {
    $tid = $ticket['ticketId'] ?? $ticket['id'] ?? $ticket_id;
    $subject = $ticket['subject'] ?? $ticket['summary'] ?? 'No subject';
    $description = $ticket['description'] ?? $ticket['message'] ?? '';
    $status = strtolower($ticket['status'] ?? 'open');
    $priority = $ticket['priority'] ?? $ticket['priorityName'] ?? '';
    $client_name = $ticket['clientName'] ?? $ticket['name'] ?? ($ticket['email'] ?? '');
    $client_email = $ticket['email'] ?? '';
    $assigned = $ticket['assigned'] ?? $ticket['assignedTo'] ?? '';
    $department = $ticket['department'] ?? '';
    $created = $ticket['created'] ?? $ticket['createdDate'] ?? '';
    $updated = $ticket['updated'] ?? $created;
    $source = 'ITarian Service Desk';
} else {
    $tid = $ticket['id'];
    $subject = $ticket['subject'];
    $description = $ticket['description'] ?? '';
    $status = $ticket['status'] ?? 'open';
    $priority = $ticket['priority'] ?? 'medium';
    $client_name = $ticket['client_name'] ?? 'Unknown';
    $client_email = $ticket['client_email'] ?? '';
    $assigned = $ticket['assigned_to'] ?? '';
    $department = '';
    $created = $ticket['created_at'] ?? '';
    $updated = $ticket['updated_at'] ?? '';
    $source = ucfirst($ticket['source'] ?? 'Portal');
}
$is_closed = in_array($status, ['closed', 'resolved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo htmlspecialchars($tid); ?> - Admin</title>
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
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center gap-3 mb-1">
                    <a href="admin-tickets.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back"><i class="fas fa-arrow-left"></i></a>
                    <span class="text-sm font-mono text-gray-400">#<?php echo htmlspecialchars($tid); ?></span>
                    <h1 class="text-xl font-semibold text-gray-900" data-testid="text-ticket-subject"><?php echo htmlspecialchars($subject); ?></h1>
                </div>
                <div class="flex items-center gap-3 ml-8 mt-1">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        echo match(true) {
                            in_array($status, ['open', 'new']) => 'bg-blue-100 text-blue-700',
                            in_array($status, ['closed', 'resolved']) => 'bg-green-100 text-green-700',
                            $status === 'in_progress' => 'bg-yellow-100 text-yellow-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="badge-status"><?php echo ucwords(str_replace('_', ' ', $status)); ?></span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        $pl = strtolower($priority);
                        echo match(true) {
                            in_array($pl, ['high', 'urgent', 'critical']) => 'bg-red-100 text-red-700',
                            in_array($pl, ['medium', 'normal']) => 'bg-yellow-100 text-yellow-700',
                            default => 'bg-green-100 text-green-700'
                        };
                    ?>" data-testid="badge-priority"><?php echo ucfirst($priority); ?> Priority</span>
                    <?php if ($created): ?>
                    <span class="text-xs text-gray-500"><i class="far fa-clock mr-1"></i><?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                            <div class="bg-gray-500 text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm">
                                <?php echo strtoupper(substr($client_name ?: 'C', 0, 1)); ?>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($client_name ?: 'Client'); ?></p>
                                <p class="text-xs text-gray-500"><?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></p>
                            </div>
                        </div>
                        <div class="px-6 py-4">
                            <p class="text-gray-700 whitespace-pre-wrap" data-testid="text-description"><?php echo htmlspecialchars($description ?: 'No description provided.'); ?></p>
                        </div>
                    </div>

                    <?php foreach ($comments as $comment):
                        if ($api_ticket) {
                            $c_author = $comment['posterName'] ?? $comment['author'] ?? 'Unknown';
                            $c_text = $comment['message'] ?? $comment['body'] ?? '';
                            $c_date = $comment['created'] ?? $comment['date'] ?? '';
                            $c_is_staff = ($comment['posterType'] ?? '') !== 'client';
                            $c_is_internal = false;
                        } else {
                            $c_author = $comment['author_name'] ?? 'Unknown';
                            $c_text = $comment['comment'];
                            $c_date = $comment['created_at'];
                            $c_is_staff = $comment['author_is_admin'] ?? false;
                            $c_is_internal = $comment['is_internal'] ?? false;
                        }
                    ?>
                        <div class="bg-white rounded-lg border mb-4 <?php
                            if ($c_is_internal) echo 'border-yellow-200 bg-yellow-50';
                            elseif ($c_is_staff) echo 'border-gray-200 border-l-4 border-l-blue-500';
                            else echo 'border-gray-200';
                        ?>">
                            <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-3">
                                <div class="<?php echo $c_is_staff ? 'bg-blue-600' : 'bg-gray-500'; ?> text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm">
                                    <?php echo strtoupper(substr($c_author, 0, 1)); ?>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($c_author); ?></p>
                                        <?php if ($c_is_staff): ?><span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">Staff</span><?php endif; ?>
                                        <?php if ($c_is_internal): ?><span class="px-1.5 py-0.5 bg-yellow-100 text-yellow-700 rounded text-xs font-medium"><i class="fas fa-lock mr-1"></i>Internal</span><?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-500"><?php $ts = strtotime($c_date); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($c_date); ?></p>
                                </div>
                            </div>
                            <div class="px-6 py-4">
                                <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($c_text); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$is_closed): ?>
                        <div class="bg-white rounded-lg border border-gray-200 mt-6">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <?php if (!$api_ticket): ?>
                                    <div class="flex items-center gap-4">
                                        <button onclick="setReplyType('public')" id="btn-public" class="text-sm font-medium px-3 py-1.5 rounded-md bg-blue-100 text-blue-700 transition" data-testid="button-public-reply">Public Reply</button>
                                        <button onclick="setReplyType('internal')" id="btn-internal" class="text-sm font-medium px-3 py-1.5 rounded-md text-gray-600 hover:bg-gray-100 transition" data-testid="button-internal-note">Internal Note</button>
                                    </div>
                                <?php else: ?>
                                    <h3 class="font-semibold text-gray-900"><i class="fas fa-reply mr-2"></i>Post Reply via Service Desk</h3>
                                <?php endif; ?>
                            </div>
                            <form method="POST" action="admin-ticket-detail.php?id=<?php echo urlencode($ticket_id); ?><?php echo $api_ticket ? '&source=sd' : ''; ?>" class="p-6">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?php echo $api_ticket ? 'post_reply_sd' : 'add_reply'; ?>">
                                <?php if (!$api_ticket): ?><input type="hidden" name="is_internal" id="is_internal" value=""><?php endif; ?>
                                <?php if ($api_ticket): ?>
                                    <div class="mb-4">
                                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Reply as (email)</label>
                                        <input type="email" name="reply_email" value="<?php echo htmlspecialchars($user_email); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-reply-email">
                                    </div>
                                <?php endif; ?>
                                <textarea name="<?php echo $api_ticket ? 'message' : 'comment'; ?>" rows="4" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 mb-4" placeholder="Type your reply..." data-testid="textarea-reply"></textarea>
                                <div class="flex justify-end">
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-md font-medium text-sm transition" data-testid="button-send-reply">
                                        <i class="fas fa-paper-plane mr-2"></i>Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center mt-6">
                            <i class="fas fa-lock text-gray-400 text-xl mb-2"></i>
                            <p class="text-gray-600 font-medium">This ticket is closed</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <?php if (!$api_ticket): ?>
                        <div class="bg-white rounded-lg border border-gray-200 mb-6">
                            <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Ticket Details</h3></div>
                            <form method="POST" action="admin-ticket-detail.php?id=<?php echo urlencode($ticket_id); ?>" class="p-6 space-y-4">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_ticket">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Status</label>
                                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-status">
                                        <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Priority</label>
                                    <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-priority">
                                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Assigned To</label>
                                    <input type="text" name="assigned_to" value="<?php echo htmlspecialchars($assigned); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Staff name" data-testid="input-assigned-to">
                                </div>
                                <button type="submit" class="w-full bg-gray-800 hover:bg-gray-900 text-white py-2 rounded-md font-medium text-sm transition" data-testid="button-update-ticket">Update Ticket</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-lg border border-gray-200 mb-6">
                            <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Ticket Details</h3></div>
                            <div class="p-6 space-y-3">
                                <div><p class="text-xs text-gray-500">Ticket ID</p><p class="font-medium text-gray-900 text-sm font-mono">#<?php echo htmlspecialchars($tid); ?></p></div>
                                <div><p class="text-xs text-gray-500">Status</p><p class="font-medium text-gray-900 text-sm"><?php echo ucfirst($status); ?></p></div>
                                <?php if ($priority): ?><div><p class="text-xs text-gray-500">Priority</p><p class="font-medium text-gray-900 text-sm"><?php echo ucfirst($priority); ?></p></div><?php endif; ?>
                                <?php if ($assigned): ?><div><p class="text-xs text-gray-500">Assigned To</p><p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($assigned); ?></p></div><?php endif; ?>
                                <?php if ($department): ?><div><p class="text-xs text-gray-500">Department</p><p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($department); ?></p></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-lg border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Client Info</h3></div>
                        <div class="p-6 space-y-3">
                            <div><p class="text-xs text-gray-500">Name</p><p class="font-medium text-gray-900 text-sm" data-testid="text-client-name"><?php echo htmlspecialchars($client_name ?: 'N/A'); ?></p></div>
                            <div><p class="text-xs text-gray-500">Email</p><p class="font-medium text-gray-900 text-sm" data-testid="text-client-email"><?php echo htmlspecialchars($client_email ?: 'N/A'); ?></p></div>
                            <?php if (!$api_ticket && ($ticket['client_company'] ?? '')): ?>
                            <div><p class="text-xs text-gray-500">Company</p><p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($ticket['client_company']); ?></p></div>
                            <?php endif; ?>
                            <div><p class="text-xs text-gray-500">Source</p><p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($source); ?></p></div>
                            <div><p class="text-xs text-gray-500">Created</p><p class="font-medium text-gray-900 text-sm"><?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></p></div>
                            <?php if ($updated && $updated !== $created): ?>
                            <div><p class="text-xs text-gray-500">Updated</p><p class="font-medium text-gray-900 text-sm"><?php $ts = strtotime($updated); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($updated); ?></p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($sd_connected): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Quick Actions</h3></div>
                        <div class="p-6 space-y-3">
                            <a href="<?php echo htmlspecialchars($sd_url); ?>/scp" target="_blank" class="w-full flex items-center justify-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition" data-testid="link-open-in-sd">
                                <i class="fas fa-external-link-alt mr-2"></i>Open in Service Desk
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$api_ticket): ?>
<script>
function setReplyType(type) {
    const internalField = document.getElementById('is_internal');
    const btnPublic = document.getElementById('btn-public');
    const btnInternal = document.getElementById('btn-internal');
    if (type === 'internal') {
        internalField.value = '1';
        btnInternal.className = 'text-sm font-medium px-3 py-1.5 rounded-md bg-yellow-100 text-yellow-700 transition';
        btnPublic.className = 'text-sm font-medium px-3 py-1.5 rounded-md text-gray-600 hover:bg-gray-100 transition';
    } else {
        internalField.value = '';
        btnPublic.className = 'text-sm font-medium px-3 py-1.5 rounded-md bg-blue-100 text-blue-700 transition';
        btnInternal.className = 'text-sm font-medium px-3 py-1.5 rounded-md text-gray-600 hover:bg-gray-100 transition';
    }
}
</script>
<?php endif; ?>
</body>
</html>
