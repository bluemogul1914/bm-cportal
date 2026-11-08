<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();

// Check if Contabo credentials exist
$contabo_cred = null;
try {
    $stmt = $pdo->prepare("SELECT id, label, username, category, notes, url, created_at FROM credentials WHERE label = 'Contabo API' LIMIT 1");
    $stmt->execute();
    $contabo_cred = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$connected = $contabo_cred !== false && $contabo_cred !== null;

include 'includes/admin-header.php';
?>
<div class="p-6">
    <h1 class="text-2xl font-bold text-white mb-2">Contabo Cloud</h1>
    <p class="text-blue-mogul-dim mb-6">Contabo VPS and cloud hosting management</p>

    <div class="bg-blue-mogul-surface rounded-lg border border-blue-mogul-dim/30 p-6 mb-6">
        <div class="flex items-center space-x-4 mb-4">
            <div class="w-3 h-3 rounded-full <?php echo $connected ? 'bg-blue-mogul-green' : 'bg-blue-mogul-red'; ?>"></div>
            <span class="text-white font-medium">Status: <?php echo $connected ? 'Connected' : 'Not configured'; ?></span>
        </div>
        <?php if ($connected): ?>
            <p class="text-sm text-blue-mogul-dim">Client ID: <?php echo htmlspecialchars($contabo_cred['username']); ?></p>
            <p class="text-sm text-blue-mogul-dim">Credentials stored in vault (ID: <?php echo $contabo_cred['id']; ?>)</p>
            <p class="text-sm text-blue-mogul-dim mt-2">API Docs: <a href="https://contabo.com/en-us/contabo-api/" class="text-blue-mogul-primary hover:underline" target="_blank">Contabo API</a></p>
        <?php else: ?>
            <p class="text-sm text-blue-mogul-dim">No Contabo credentials found. Add them in <a href="admin-credentials.php" class="text-blue-mogul-primary hover:underline">Credentials</a>.</p>
        <?php endif; ?>
    </div>

    <div class="bg-blue-mogul-surface rounded-lg border border-blue-mogul-dim/30 p-6">
        <h2 class="text-lg font-semibold text-white mb-4">Contabo Resources</h2>
        <p class="text-sm text-blue-mogul-dim">VPS instances, storage, and network management will be available here once the Contabo API integration is built out.</p>
        <p class="text-sm text-blue-mogul-dim mt-2">API Base: https://api.contabo.com</p>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>
