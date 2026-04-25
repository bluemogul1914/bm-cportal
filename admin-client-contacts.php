<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id  = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';

$client_id = intval($_GET['id'] ?? 0);
if ($client_id <= 0) {
    portal_redirect('/portal/admin-clients.php');
}

$pdo = getDB();
$error_msg   = '';
$success_msg = '';

// Load client
$client = null;
try {
    $client = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $client->execute([$client_id]);
    $client = $client->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
if (!$client) {
    portal_redirect('/portal/admin-clients.php');
}

// Handle POST (create/delete contact)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_contact') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $role     = trim($_POST['role'] ?? '');
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $notes    = trim($_POST['notes'] ?? '');
        if (empty($name)) {
            $error_msg = 'Name is required.';
        } else {
            try {
                $pdo->prepare("INSERT INTO contacts (client_id, name, email, phone, role, is_primary, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$client_id, $name, $email ?: null, $phone ?: null, $role ?: null, $is_primary, $notes ?: null]);
                $success_msg = 'Contact added.';
            } catch (PDOException $e) {
                error_log("add_contact error: " . $e->getMessage());
                $error_msg = 'Failed to add contact.';
            }
        }
    } elseif ($action === 'delete_contact') {
        $contact_id = intval($_POST['contact_id'] ?? 0);
        if ($contact_id > 0) {
            try {
                $pdo->prepare("DELETE FROM contacts WHERE id = ? AND client_id = ?")
                    ->execute([$contact_id, $client_id]);
                $success_msg = 'Contact removed.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to delete contact.';
            }
        }
    }
}

// Load contacts
$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE client_id = ? ORDER BY is_primary DESC, name ASC");
    $stmt->execute([$client_id]);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("contacts load error: " . $e->getMessage());
}
?>
<?php include 'includes/header.php'; ?>
<div class="flex h-screen overflow-hidden bg-gray-100">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm text-gray-500 mb-1">
                        <a href="/portal/admin-clients.php" class="hover:text-blue-600">Clients</a>
                        <span>/</span>
                        <a href="/portal/admin-client-detail.php?id=<?php echo $client_id; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($client['name']); ?></a>
                        <span>/</span>
                        <span class="text-gray-900 font-medium">Contacts</span>
                    </div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-address-book mr-2"></i>Contacts — <?php echo htmlspecialchars($client['name']); ?></h1>
                </div>
                <a href="/portal/admin-client-detail.php?id=<?php echo $client_id; ?>" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition">
                    <i class="fas fa-arrow-left mr-1"></i>Back to Client
                </a>
            </div>
        </header>

        <?php if ($success_msg): ?>
            <div class="mx-6 mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="mx-6 mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="flex-1 overflow-y-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Add contact form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6 h-fit">
                    <h2 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-user-plus mr-2 text-blue-500"></i>Add Contact</h2>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_contact">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Full name">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="email@example.com">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="+1 (555) 000-0000">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Role / Title</label>
                                <input type="text" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="e.g. IT Manager">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Optional notes"></textarea>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="is_primary" id="is_primary" class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <label for="is_primary" class="text-sm text-gray-700">Primary contact</label>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                                <i class="fas fa-plus mr-1"></i>Add Contact
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Contacts list -->
                <div class="lg:col-span-2">
                    <?php if (empty($contacts)): ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <i class="fas fa-address-book text-4xl text-gray-300 mb-3"></i>
                            <p class="font-medium text-gray-900">No contacts yet</p>
                            <p class="text-sm text-gray-500 mt-1">Add the first contact for this client using the form.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3" data-testid="contacts-list">
                            <?php foreach ($contacts as $contact): ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-4 flex items-start justify-between" data-testid="contact-<?php echo $contact['id']; ?>">
                                <div class="flex items-start gap-3">
                                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                                        <span class="text-blue-700 font-semibold text-sm"><?php echo strtoupper(substr($contact['name'], 0, 1)); ?></span>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($contact['name']); ?></span>
                                            <?php if ($contact['is_primary']): ?>
                                            <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">Primary</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($contact['role']): ?><p class="text-sm text-gray-500"><?php echo htmlspecialchars($contact['role']); ?></p><?php endif; ?>
                                        <div class="flex flex-wrap gap-3 mt-1">
                                            <?php if ($contact['email']): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($contact['email']); ?>" class="text-sm text-blue-600 hover:underline"><i class="fas fa-envelope mr-1 text-gray-400"></i><?php echo htmlspecialchars($contact['email']); ?></a>
                                            <?php endif; ?>
                                            <?php if ($contact['phone']): ?>
                                            <span class="text-sm text-gray-600"><i class="fas fa-phone mr-1 text-gray-400"></i><?php echo htmlspecialchars($contact['phone']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($contact['notes']): ?><p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($contact['notes']); ?></p><?php endif; ?>
                                    </div>
                                </div>
                                <form method="POST" onsubmit="return confirm('Remove this contact?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_contact">
                                    <input type="hidden" name="contact_id" value="<?php echo $contact['id']; ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-600 text-sm p-1"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
