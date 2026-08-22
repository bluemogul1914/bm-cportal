<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';

$ticket_id = intval($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    portal_redirect('/portal/tickets.php');
}

$success_msg = '';
$error_msg = '';
$ticket = null;
$comments = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    require_csrf();

    if ($_POST['action'] === 'add_comment') {
        $comment = trim($_POST['comment'] ?? '');
        if (!empty($comment)) {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
                $client_id = $client ? $client['id'] : $user_id;

                $pdo->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) VALUES (?, ?, ?, false, NOW())")->execute([$ticket_id, $user_id, $comment]);
                $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'comment_added', 'ticket', $ticket_id, 'Reply on ticket #' . $ticket_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Reply added successfully.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to add reply.';
            }
        }
    }

    if ($_POST['action'] === 'reopen_ticket') {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            $client_id = $client ? $client['id'] : $user_id;

            // Only the owner client can reopen their own ticket (ownership check same as load)
            $check = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND (client_id = ? OR client_id = ?)");
            $check->execute([$ticket_id, $client_id, $user_id]);
            if ($check->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'ticket_reopened', 'ticket', $ticket_id, 'Client reopened ticket #' . $ticket_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Ticket reopened successfully.';
            } else {
                $error_msg = 'You are not authorized to reopen this ticket.';
            }
        } catch (PDOException $e) {
            $error_msg = 'Failed to reopen ticket.';
        }
    }
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;

    // Try matching by client_id first, fall back to checking if this user created the ticket
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND (client_id = ? OR client_id = ?)");
    $stmt->execute([$ticket_id, $client_id, $user_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        portal_redirect('/portal/tickets.php');
    }
    $stmt = $pdo->prepare("SELECT tc.*, u.name as author_name, u.is_admin as author_is_admin FROM ticket_comments tc LEFT JOIN users u ON tc.user_id = u.id WHERE tc.ticket_id = ? AND tc.is_internal = false ORDER BY tc.created_at ASC");
    $stmt->execute([$ticket_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    portal_redirect('/portal/tickets.php');
}

$tid = $ticket['id'];
$subject = $ticket['subject'];
$description = $ticket['description'] ?? '';
$status = $ticket['status'];
$priority = $ticket['priority'] ?? 'medium';
$created = $ticket['created_at'];
$is_closed = ($status === 'closed');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $tid; ?> - Blue Mogul</title>
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
            <div class="px-6 py-4">
                <div class="flex items-center gap-3 mb-1">
                    <a href="tickets.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back-tickets"><i class="fas fa-arrow-left"></i></a>
                    <span class="text-sm font-mono text-gray-400">#<?php echo $tid; ?></span>
                    <h1 class="text-xl font-semibold text-gray-900" data-testid="text-ticket-subject"><?php echo htmlspecialchars($subject); ?></h1>
                </div>
                <div class="flex items-center gap-3 ml-8 mt-1">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        echo match($status) {
                            'open' => 'bg-blue-100 text-blue-700',
                            'in_progress' => 'bg-yellow-100 text-yellow-700',
                            'closed' => 'bg-gray-100 text-gray-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="status-badge"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        echo match($priority) {
                            'urgent' => 'bg-red-200 text-red-800',
                            'high' => 'bg-red-100 text-red-700',
                            'medium' => 'bg-yellow-100 text-yellow-700',
                            'low' => 'bg-green-100 text-green-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="priority-badge"><?php echo ucfirst($priority); ?> Priority</span>
                    <span class="text-xs text-gray-500"><i class="far fa-clock mr-1"></i>Opened <?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></span>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <div class="max-w-4xl">
                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                        <div class="bg-blue-600 text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm">
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                        </div>
                        <div>
                            <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($user_name); ?></p>
                            <p class="text-xs text-gray-500"><?php $ts = strtotime($created); echo $ts ? date('M d, Y g:i A', $ts) : htmlspecialchars($created); ?></p>
                        </div>
                    </div>
                    <div class="px-6 py-4">
                        <p class="text-gray-700 whitespace-pre-wrap" data-testid="text-ticket-description"><?php echo htmlspecialchars($description ?: 'No description provided.'); ?></p>
                    </div>
                </div>

                <?php foreach ($comments as $comment):
                    $c_author = $comment['author_name'] ?? 'Unknown';
                    $c_text = $comment['comment'];
                    $c_date = $comment['created_at'];
                    $c_is_staff = $comment['author_is_admin'] ?? false;
                ?>
                    <div class="bg-white rounded-lg border border-gray-200 mb-4 <?php echo $c_is_staff ? 'border-l-4 border-l-blue-500' : ''; ?>">
                        <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-3">
                            <div class="<?php echo $c_is_staff ? 'bg-blue-600' : 'bg-gray-500'; ?> text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm">
                                <?php echo strtoupper(substr($c_author, 0, 1)); ?>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($c_author); ?></p>
                                    <?php if ($c_is_staff): ?><span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">Staff</span><?php endif; ?>
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
                            <h3 class="font-semibold text-gray-900">Add Reply</h3>
                        </div>
                        <form method="POST" action="ticket-detail.php?id=<?php echo $ticket_id; ?>" class="p-6">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_comment">
                            <textarea name="comment" rows="4" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 mb-4" placeholder="Type your reply..." data-testid="textarea-reply"></textarea>
                            <div class="flex justify-end">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-md font-medium text-sm transition" data-testid="button-submit-reply">
                                    <i class="fas fa-paper-plane mr-2"></i>Send Reply
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center mt-6">
                        <i class="fas fa-lock text-gray-400 text-xl mb-2"></i>
                        <p class="text-gray-600 font-medium">This ticket is closed</p>
                        <p class="text-sm text-gray-500">If you need further assistance, you can reopen this ticket or create a new one.</p>
                        <form method="POST" action="ticket-detail.php?id=<?php echo $ticket_id; ?>" class="mt-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reopen_ticket">
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-md font-medium text-sm transition" data-testid="button-reopen-ticket">
                                <i class="fas fa-undo mr-2"></i>Reopen Ticket
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>