<?php
/**
 * Client-portal prepaid wallet — balance + top-up.
 *
 * The prepaid model: the client's wallet funds their services; at $0 the account
 * is suspended and a top-up clears it. The Node endpoint POST /api/top-up/checkout
 * (client-authenticated, own account only) mints the Stripe Checkout and returns
 * { url }. This partial is the single implementation of the client-facing wallet
 * so every client page renders it identically.
 */

/** Fetch a client's prepaid wallet state. */
function client_wallet(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(credit_balance, 0)        AS balance,
               COALESCE(status, 'active')         AS status,
               COALESCE(low_balance_threshold, 10) AS threshold
        FROM clients WHERE id = ?
    ");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['balance' => 0, 'status' => 'active', 'threshold' => 10];
}

/**
 * Render the prepaid wallet card (balance + status + Add Funds).
 * No-op when $clientId is null (unlinked login) so pages stay safe.
 * The top-up JS is emitted once per request.
 */
function render_wallet_card(PDO $pdo, ?int $clientId): void
{
    if ($clientId === null) {
        return;
    }
    $w = client_wallet($pdo, $clientId);
    $bal = (float)$w['balance'];
    $thr = (float)$w['threshold'];
    $status = (string)$w['status'];

    $badge = $status === 'active'
        ? 'bg-green-100 text-green-700'
        : 'bg-red-100 text-red-700';
    ?>
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <div class="bg-blue-100 rounded-lg p-3"><i class="fas fa-wallet text-blue-600 text-xl"></i></div>
            <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo $badge; ?>"><?php echo ucfirst(htmlspecialchars($status)); ?></span>
        </div>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Prepaid Balance</p>
        <p class="text-3xl font-bold text-gray-900">$<?php echo number_format($bal, 2); ?></p>
        <?php if ($bal <= 0 && $status !== 'active'): ?>
            <p class="text-xs text-red-600 mt-1"><i class="fas fa-exclamation-circle mr-1"></i>Balance empty — services are suspended until you top up.</p>
        <?php elseif ($bal <= 0): ?>
            <p class="text-xs text-gray-500 mt-1">No prepaid balance on file — top up to fund your services.</p>
        <?php elseif ($bal < $thr): ?>
            <p class="text-xs text-yellow-700 mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>Low balance (below your $<?php echo number_format($thr, 2); ?> threshold).</p>
        <?php endif; ?>
        <div class="mt-4 flex items-center gap-2">
            <input id="topup-amount" type="number" min="5" step="1" inputmode="decimal" placeholder="$ amount"
                   class="w-28 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <button id="topup-btn" onclick="return bmAddFunds();"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">Add Funds</button>
        </div>
        <p class="text-xs text-gray-400 mt-2">Secure card payment via Stripe. Minimum $5.</p>
    </div>
    <?php
    if (empty($GLOBALS['__bm_wallet_js'])) {
        $GLOBALS['__bm_wallet_js'] = true;
        ?>
        <script>
        function bmAddFunds() {
            var el = document.getElementById('topup-amount');
            var amt = parseFloat(el && el.value);
            if (!amt || amt < 5) { alert('Enter an amount of $5 or more.'); return false; }
            var btn = document.getElementById('topup-btn');
            btn.disabled = true; btn.textContent = 'Redirecting…';
            fetch('/api/top-up/checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ amount: amt })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.url) { window.location = d.url; }
                else { alert('Could not start checkout. Please try again.'); btn.disabled = false; btn.textContent = 'Add Funds'; }
            }).catch(function () {
                alert('Network error starting checkout.'); btn.disabled = false; btn.textContent = 'Add Funds';
            });
            return false;
        }
        </script>
        <?php
    }
}