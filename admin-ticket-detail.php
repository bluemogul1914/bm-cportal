<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';

$ticket_id = intval($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    portal_redirect('/portal/admin-tickets.php');
}

$success_msg = '';
$error_msg = '';
$ticket = null;
$comments = [];
$time_entries = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];

    if ($action === 'add_reply') {
        $comment = trim($_POST['comment'] ?? '');
        $is_internal = isset($_POST['is_internal']) && $_POST['is_internal'] === '1';
        if (!empty($comment)) {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
                $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'comment_added', 'ticket', $ticket_id, ($is_internal ? 'Internal note on' : 'Reply on') . ' ticket #' . $ticket_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                if (!$is_internal) {
                    $t_stmt = $pdo->prepare("SELECT t.subject, c.email, c.name FROM tickets t LEFT JOIN clients c ON t.client_id = c.id WHERE t.id = ?");
                    $t_stmt->execute([$ticket_id]);
                    $t_info = $t_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($t_info && !empty($t_info['email'])) {
                        notify_ticket_reply($ticket_id, $t_info['subject'], $t_info['email'], $t_info['name'] ?? 'Client', $comment, $user_name);
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
            $pdo->prepare("UPDATE tickets SET status=?, priority=?, assigned_to=?, updated_at=NOW() WHERE id=?")
                ->execute([$_POST['status'], $_POST['priority'], trim($_POST['assigned_to'] ?? ''), $ticket_id]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$user_id, 'ticket_updated', 'ticket', $ticket_id, 'Updated ticket #' . $ticket_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Ticket updated.';
        } catch (PDOException $e) {
            $error_msg = 'Failed to update ticket.';
        }
    } elseif ($action === 'add_time') {
        $hours = floatval($_POST['hours'] ?? 0);
        $minutes = intval($_POST['minutes'] ?? 0);
        $duration = intval($hours * 60) + $minutes;
        $description = trim($_POST['time_description'] ?? '');
        $billable = isset($_POST['billable']) ? true : false;
        $hourly_rate = floatval($_POST['hourly_rate'] ?? 150.00);
        if ($duration > 0) {
            try {
                $pdo = getDB();
                $pdo->prepare("INSERT INTO ticket_time_entries (ticket_id, user_id, description, duration_minutes, billable, hourly_rate, started_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$ticket_id, $user_id, $description, $duration, $billable, $hourly_rate]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'time_logged', 'ticket', $ticket_id, "Logged {$duration} min on ticket #{$ticket_id}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = "Logged " . ($duration >= 60 ? floor($duration/60) . "h " . ($duration%60) . "m" : $duration . " min") . " to this ticket.";
            } catch (PDOException $e) {
                $error_msg = 'Failed to log time.';
            }
        } else {
            $error_msg = 'Duration must be greater than 0.';
        }
    } elseif ($action === 'save_timer') {
        $duration = intval($_POST['timer_duration'] ?? 0);
        $description = trim($_POST['timer_description'] ?? '');
        $billable = isset($_POST['timer_billable']) ? true : false;
        $hourly_rate = floatval($_POST['timer_rate'] ?? 150.00);
        if ($duration > 0) {
            try {
                $pdo = getDB();
                $pdo->prepare("INSERT INTO ticket_time_entries (ticket_id, user_id, description, duration_minutes, billable, hourly_rate, started_at, ended_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$ticket_id, $user_id, $description, $duration, $billable, $hourly_rate, date('Y-m-d H:i:s', time() - ($duration * 60))]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'timer_saved', 'ticket', $ticket_id, "Timer: {$duration} min on ticket #{$ticket_id}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = "Timer saved: " . ($duration >= 60 ? floor($duration/60) . "h " . ($duration%60) . "m" : $duration . " min");
            } catch (PDOException $e) {
                $error_msg = 'Failed to save timer.';
            }
        } else {
            $error_msg = 'Timer has no time recorded.';
        }
    } elseif ($action === 'delete_time') {
        $entry_id = intval($_POST['entry_id'] ?? 0);
        if ($entry_id > 0) {
            try {
                $pdo = getDB();
                $pdo->prepare("DELETE FROM ticket_time_entries WHERE id = ? AND ticket_id = ?")->execute([$entry_id, $ticket_id]);
                $success_msg = 'Time entry deleted.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to delete time entry.';
            }
        }
    }
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT t.*, c.name as client_name, c.email as client_email, c.company as client_company FROM tickets t LEFT JOIN clients c ON t.client_id = c.id WHERE t.id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        portal_redirect('/portal/admin-tickets.php');
    }
    $stmt = $pdo->prepare("SELECT tc.*, u.name as author_name, u.is_admin as author_is_admin FROM ticket_comments tc LEFT JOIN users u ON tc.user_id = u.id WHERE tc.ticket_id = ? ORDER BY tc.created_at ASC");
    $stmt->execute([$ticket_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT te.*, u.name as user_name FROM ticket_time_entries te LEFT JOIN users u ON te.user_id = u.id WHERE te.ticket_id = ? ORDER BY te.created_at DESC");
    $stmt->execute([$ticket_id]);
    $time_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    portal_redirect('/portal/admin-tickets.php');
}

$tid = $ticket['id'];
$subject = $ticket['subject'];
$description = $ticket['description'] ?? '';
$status = $ticket['status'] ?? 'open';
$priority = $ticket['priority'] ?? 'medium';
$client_name = $ticket['client_name'] ?? 'Unknown';
$client_email = $ticket['client_email'] ?? '';
$assigned = $ticket['assigned_to'] ?? '';
$created = $ticket['created_at'] ?? '';
$updated = $ticket['updated_at'] ?? '';
$source = ucfirst($ticket['source'] ?? 'Portal');
$is_closed = ($status === 'closed');

$total_minutes = 0;
$billable_minutes = 0;
$total_billable_amount = 0;
foreach ($time_entries as $te) {
    $total_minutes += $te['duration_minutes'];
    if ($te['billable']) {
        $billable_minutes += $te['duration_minutes'];
        $total_billable_amount += ($te['duration_minutes'] / 60) * floatval($te['hourly_rate']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $tid; ?> - Admin</title>
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
                    <span class="text-sm font-mono text-gray-400">#<?php echo $tid; ?></span>
                    <h1 class="text-xl font-semibold text-gray-900" data-testid="text-ticket-subject"><?php echo htmlspecialchars($subject); ?></h1>
                </div>
                <div class="flex items-center gap-3 ml-8 mt-1">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        echo match($status) {
                            'open' => 'bg-blue-100 text-blue-700',
                            'in_progress' => 'bg-yellow-100 text-yellow-700',
                            'closed' => 'bg-green-100 text-green-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="badge-status"><?php echo ucwords(str_replace('_', ' ', $status)); ?></span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        echo match($priority) {
                            'urgent' => 'bg-red-200 text-red-800',
                            'high' => 'bg-red-100 text-red-700',
                            'medium' => 'bg-yellow-100 text-yellow-700',
                            'low' => 'bg-green-100 text-green-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="badge-priority"><?php echo ucfirst($priority); ?> Priority</span>
                    <?php if (strtolower($ticket['source'] ?? '') === 'itflow'): ?>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700" data-testid="badge-itflow-source">
                        <i class="fas fa-exchange-alt mr-1"></i>ITFlow
                    </span>
                    <?php endif; ?>
                    <?php if ($created): ?>
                    <span class="text-xs text-gray-500"><i class="far fa-clock mr-1"></i><?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></span>
                    <?php endif; ?>
                    <?php if ($total_minutes > 0): ?>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700" data-testid="badge-time-total">
                        <i class="fas fa-stopwatch mr-1"></i><?php echo floor($total_minutes/60); ?>h <?php echo $total_minutes%60; ?>m logged
                    </span>
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
                        $c_author = $comment['author_name'] ?? 'Unknown';
                        $c_text = $comment['comment'];
                        $c_date = $comment['created_at'];
                        $c_is_staff = $comment['author_is_admin'] ?? false;
                        $c_is_internal = $comment['is_internal'] ?? false;
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
                                <div class="flex items-center gap-4">
                                    <button onclick="setReplyType('public')" id="btn-public" class="text-sm font-medium px-3 py-1.5 rounded-md bg-blue-100 text-blue-700 transition" data-testid="button-public-reply">Public Reply</button>
                                    <button onclick="setReplyType('internal')" id="btn-internal" class="text-sm font-medium px-3 py-1.5 rounded-md text-gray-600 hover:bg-gray-100 transition" data-testid="button-internal-note">Internal Note</button>
                                </div>
                            </div>
                            <form method="POST" action="admin-ticket-detail.php?id=<?php echo $ticket_id; ?>" class="p-6">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_reply">
                                <input type="hidden" name="is_internal" id="is_internal" value="">
                                <textarea name="comment" rows="4" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 mb-4" placeholder="Type your reply..." data-testid="textarea-reply"></textarea>
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
                            <p class="text-sm text-gray-500 mt-1">Reopen it by changing the status in the sidebar.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Ticket Details</h3></div>
                        <form method="POST" action="admin-ticket-detail.php?id=<?php echo $ticket_id; ?>" class="p-6 space-y-4">
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

                    <div class="bg-white rounded-lg border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="font-semibold text-gray-900"><i class="fas fa-stopwatch text-purple-600 mr-2"></i>Billable Time</h3>
                            <?php if ($total_billable_amount > 0): ?>
                            <span class="text-sm font-bold text-green-700" data-testid="text-billable-total">$<?php echo number_format($total_billable_amount, 2); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="bg-gray-900 rounded-lg p-4 text-center" data-testid="timer-display">
                                <div class="text-3xl font-mono text-white tracking-widest" id="timer-clock">00:00:00</div>
                                <div class="flex items-center justify-center gap-3 mt-3">
                                    <button type="button" onclick="toggleTimer()" id="timer-toggle-btn" class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition" data-testid="button-timer-toggle">
                                        <i class="fas fa-play mr-1" id="timer-icon"></i><span id="timer-label">Start</span>
                                    </button>
                                    <button type="button" onclick="resetTimer()" class="px-4 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-md text-sm font-medium transition" data-testid="button-timer-reset">
                                        <i class="fas fa-redo-alt mr-1"></i>Reset
                                    </button>
                                </div>
                            </div>

                            <form method="POST" action="admin-ticket-detail.php?id=<?php echo $ticket_id; ?>" id="timer-save-form" class="hidden" data-testid="form-timer-save">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_timer">
                                <input type="hidden" name="timer_duration" id="timer-duration-input" value="0">
                                <input type="text" name="timer_description" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm mb-2" placeholder="What did you work on?" data-testid="input-timer-description">
                                <div class="flex items-center gap-3 mb-2">
                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                        <input type="checkbox" name="timer_billable" checked class="rounded border-gray-300" data-testid="checkbox-timer-billable"> Billable
                                    </label>
                                    <div class="flex items-center gap-1">
                                        <span class="text-sm text-gray-500">$</span>
                                        <input type="number" name="timer_rate" value="150" step="0.01" min="0" class="w-20 px-2 py-1 border border-gray-300 rounded text-sm" data-testid="input-timer-rate">
                                        <span class="text-sm text-gray-500">/hr</span>
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2 rounded-md font-medium text-sm transition" data-testid="button-timer-save">
                                    <i class="fas fa-save mr-1"></i>Save Timer Entry
                                </button>
                            </form>

                            <div class="border-t border-gray-200 pt-4">
                                <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Manual Entry</p>
                                <form method="POST" action="admin-ticket-detail.php?id=<?php echo $ticket_id; ?>" data-testid="form-manual-time">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_time">
                                    <div class="grid grid-cols-2 gap-2 mb-2">
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-0.5">Hours</label>
                                            <input type="number" name="hours" min="0" max="24" value="0" step="1" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm" data-testid="input-hours">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-0.5">Minutes</label>
                                            <input type="number" name="minutes" min="0" max="59" value="0" step="5" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm" data-testid="input-minutes">
                                        </div>
                                    </div>
                                    <input type="text" name="time_description" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm mb-2" placeholder="Description..." data-testid="input-time-description">
                                    <div class="flex items-center gap-3 mb-2">
                                        <label class="flex items-center gap-2 text-sm text-gray-600">
                                            <input type="checkbox" name="billable" checked class="rounded border-gray-300" data-testid="checkbox-billable"> Billable
                                        </label>
                                        <div class="flex items-center gap-1">
                                            <span class="text-sm text-gray-500">$</span>
                                            <input type="number" name="hourly_rate" value="150" step="0.01" min="0" class="w-20 px-2 py-1 border border-gray-300 rounded text-sm" data-testid="input-hourly-rate">
                                            <span class="text-sm text-gray-500">/hr</span>
                                        </div>
                                    </div>
                                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white py-1.5 rounded-md font-medium text-sm transition" data-testid="button-add-time">
                                        <i class="fas fa-plus mr-1"></i>Log Time
                                    </button>
                                </form>
                            </div>

                            <?php if (!empty($time_entries)): ?>
                            <div class="border-t border-gray-200 pt-4">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">Time Log</p>
                                    <p class="text-xs text-gray-500"><?php echo floor($total_minutes/60); ?>h <?php echo $total_minutes%60; ?>m total</p>
                                </div>
                                <div class="space-y-2 max-h-64 overflow-y-auto" data-testid="time-entries-list">
                                    <?php foreach ($time_entries as $te):
                                        $te_mins = $te['duration_minutes'];
                                        $te_hrs = floor($te_mins / 60);
                                        $te_rem = $te_mins % 60;
                                        $te_amount = $te['billable'] ? ($te_mins / 60) * floatval($te['hourly_rate']) : 0;
                                    ?>
                                    <div class="bg-gray-50 rounded-md p-3 text-sm" data-testid="time-entry-<?php echo $te['id']; ?>">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="font-medium text-gray-900">
                                                <?php echo $te_hrs > 0 ? $te_hrs . 'h ' : ''; ?><?php echo $te_rem; ?>m
                                                <?php if ($te['billable']): ?>
                                                    <span class="text-green-600 text-xs ml-1">($<?php echo number_format($te_amount, 2); ?>)</span>
                                                <?php endif; ?>
                                            </span>
                                            <div class="flex items-center gap-2">
                                                <?php if ($te['billable']): ?>
                                                    <span class="px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-xs">Billable</span>
                                                <?php else: ?>
                                                    <span class="px-1.5 py-0.5 bg-gray-100 text-gray-500 rounded text-xs">Non-billable</span>
                                                <?php endif; ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this time entry?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_time">
                                                    <input type="hidden" name="entry_id" value="<?php echo $te['id']; ?>">
                                                    <button type="submit" class="text-red-400 hover:text-red-600 text-xs" data-testid="button-delete-time-<?php echo $te['id']; ?>"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </div>
                                        <?php if (!empty($te['description'])): ?>
                                        <p class="text-gray-600 text-xs"><?php echo htmlspecialchars($te['description']); ?></p>
                                        <?php endif; ?>
                                        <p class="text-gray-400 text-xs mt-1"><?php echo htmlspecialchars($te['user_name'] ?? 'Unknown'); ?> &middot; <?php $ts = strtotime($te['created_at']); echo $ts ? date('M d g:i A', $ts) : ''; ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Client Info</h3></div>
                        <div class="p-6 space-y-3">
                            <div><p class="text-xs text-gray-500">Name</p><p class="font-medium text-gray-900 text-sm" data-testid="text-client-name"><?php echo htmlspecialchars($client_name ?: 'N/A'); ?></p></div>
                            <div><p class="text-xs text-gray-500">Email</p><p class="font-medium text-gray-900 text-sm" data-testid="text-client-email"><?php echo htmlspecialchars($client_email ?: 'N/A'); ?></p></div>
                            <?php if ($ticket['client_company'] ?? ''): ?>
                            <div><p class="text-xs text-gray-500">Company</p><p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($ticket['client_company']); ?></p></div>
                            <?php endif; ?>
                            <div>
                                <p class="text-xs text-gray-500">Source</p>
                                <p class="font-medium text-gray-900 text-sm">
                                    <?php if (strtolower($ticket['source'] ?? '') === 'itflow'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded text-xs font-medium" data-testid="badge-source-itflow">
                                            <i class="fas fa-exchange-alt"></i>ITFlow
                                        </span>
                                        <?php if (!empty($ticket['external_id'])): ?>
                                            <a href="<?php echo htmlspecialchars(rtrim(ITFLOW_URL, '/')); ?>/ticket.php?ticket_id=<?php echo htmlspecialchars($ticket['external_id']); ?>"
                                               target="_blank" rel="noopener noreferrer"
                                               class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium hover:bg-blue-200 transition"
                                               data-testid="link-itflow-ticket">
                                                <i class="fas fa-external-link-alt"></i>View in ITFlow
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($source); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div><p class="text-xs text-gray-500">Created</p><p class="font-medium text-gray-900 text-sm"><?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></p></div>
                            <?php if ($updated && $updated !== $created): ?>
                            <div><p class="text-xs text-gray-500">Updated</p><p class="font-medium text-gray-900 text-sm"><?php $ts = strtotime($updated); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($updated); ?></p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200"><h3 class="font-semibold text-gray-900">Quick Actions</h3></div>
                        <div class="p-6 space-y-3">
                            <?php if (!$is_closed): ?>
                                <form method="POST" onsubmit="return confirm('Close this ticket?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_ticket">
                                    <input type="hidden" name="status" value="closed">
                                    <input type="hidden" name="priority" value="<?php echo htmlspecialchars($priority); ?>">
                                    <input type="hidden" name="assigned_to" value="<?php echo htmlspecialchars($assigned); ?>">
                                    <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition" data-testid="button-quick-close">
                                        <i class="fas fa-check mr-2"></i>Close Ticket
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_ticket">
                                    <input type="hidden" name="status" value="open">
                                    <input type="hidden" name="priority" value="<?php echo htmlspecialchars($priority); ?>">
                                    <input type="hidden" name="assigned_to" value="<?php echo htmlspecialchars($assigned); ?>">
                                    <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-reopen">
                                        <i class="fas fa-redo mr-2"></i>Reopen Ticket
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($client_email): ?>
                            <a href="admin-messages.php?to=<?php echo urlencode($client_email); ?>" class="block w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition text-center" data-testid="link-message-client">
                                <i class="fas fa-envelope mr-2"></i>Message Client
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function setReplyType(type) {
    const isInternal = type === 'internal';
    document.getElementById('is_internal').value = isInternal ? '1' : '';
    document.getElementById('btn-public').className = isInternal
        ? 'text-sm font-medium px-3 py-1.5 rounded-md text-gray-600 hover:bg-gray-100 transition'
        : 'text-sm font-medium px-3 py-1.5 rounded-md bg-blue-100 text-blue-700 transition';
    document.getElementById('btn-internal').className = isInternal
        ? 'text-sm font-medium px-3 py-1.5 rounded-md bg-yellow-100 text-yellow-700 transition'
        : 'text-sm font-medium px-3 py-1.5 rounded-md text-gray-600 hover:bg-gray-100 transition';
}

let timerRunning = false;
let timerSeconds = 0;
let timerInterval = null;

function padZero(n) { return n < 10 ? '0' + n : n; }

function updateTimerDisplay() {
    const h = Math.floor(timerSeconds / 3600);
    const m = Math.floor((timerSeconds % 3600) / 60);
    const s = timerSeconds % 60;
    document.getElementById('timer-clock').textContent = padZero(h) + ':' + padZero(m) + ':' + padZero(s);
    const saveForm = document.getElementById('timer-save-form');
    if (timerSeconds > 0 && !timerRunning) {
        saveForm.classList.remove('hidden');
        document.getElementById('timer-duration-input').value = Math.ceil(timerSeconds / 60);
    } else {
        saveForm.classList.add('hidden');
    }
}

function toggleTimer() {
    if (timerRunning) {
        clearInterval(timerInterval);
        timerRunning = false;
        document.getElementById('timer-icon').className = 'fas fa-play mr-1';
        document.getElementById('timer-label').textContent = 'Resume';
        document.getElementById('timer-toggle-btn').className = 'px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition';
    } else {
        timerRunning = true;
        timerInterval = setInterval(function() {
            timerSeconds++;
            updateTimerDisplay();
        }, 1000);
        document.getElementById('timer-icon').className = 'fas fa-pause mr-1';
        document.getElementById('timer-label').textContent = 'Pause';
        document.getElementById('timer-toggle-btn').className = 'px-4 py-1.5 bg-yellow-600 hover:bg-yellow-700 text-white rounded-md text-sm font-medium transition';
    }
    updateTimerDisplay();
}

function resetTimer() {
    clearInterval(timerInterval);
    timerRunning = false;
    timerSeconds = 0;
    document.getElementById('timer-icon').className = 'fas fa-play mr-1';
    document.getElementById('timer-label').textContent = 'Start';
    document.getElementById('timer-toggle-btn').className = 'px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition';
    updateTimerDisplay();
}
</script>
</body>
</html>