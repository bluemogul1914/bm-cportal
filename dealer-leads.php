<?php
$page_title = 'Leads';
require_once __DIR__ . '/includes/dealer-header.php';
dealer_auth();
$dealer = dealer_me();
$pdo    = get_db();

$success = $error = '';

// CSRF guard for all POST actions on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

// Active logins on this dealer's team — used to assign a lead to a rep.
$team = [];
try {
    $tq = $pdo->prepare("SELECT du.user_id, du.role, u.name FROM dealer_users du JOIN users u ON u.id = du.user_id
                          WHERE du.dealer_id = ? AND COALESCE(du.status,'active') = 'active' ORDER BY u.name");
    $tq->execute([$dealer['id']]); $team = $tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $team = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lead_add') {
    $name    = trim((string)($_POST['name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $company = trim((string)($_POST['company'] ?? ''));
    $street  = trim((string)($_POST['street'] ?? ''));
    $city    = trim((string)($_POST['city'] ?? ''));
    $zip     = trim((string)($_POST['zip_code'] ?? ''));
    $source  = trim((string)($_POST['source'] ?? 'dealer'));
    $notes   = trim((string)($_POST['notes'] ?? ''));
    $assign  = (int)($_POST['assigned_user_id'] ?? 0) ?: null;
    if ($name === '' && $email === '') {
        $error = 'A lead needs at least a name or an email.';
    } else {
        try {
            // Tenant scope is the session's dealer — never a posted dealer_id.
            if ($assign !== null) {
                $ok = false;
                foreach ($team as $tm) { if ((int)$tm['user_id'] === $assign) { $ok = true; break; } }
                if (!$ok) $assign = null;   // not on this dealer's team — drop it
            }
            $st = $pdo->prepare("INSERT INTO leads
                (name, full_name, email, phone, company, company_name, street, city, zip_code, source, status, pipeline_status, notes, dealer_id, assigned_user_id, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?, 'new', 'new_enquiry', ?, ?, ?, NOW(), NOW()) RETURNING id");
            $st->execute([$name ?: null, $name ?: null, $email ?: null, $phone ?: null, $company ?: null, $company ?: null,
                          $street ?: null, $city ?: null, $zip ?: null, $source ?: null, $notes ?: null,
                          (int)$dealer['id'], $assign]);
            $new_id = (int)$st->fetchColumn();
            try { $pdo->prepare("UPDATE leads SET lead_number = LPAD(id::text, 6, '0') WHERE id = ?")->execute([$new_id]); } catch (Throwable $e) {}
            try { $pdo->prepare("INSERT INTO lead_activities (lead_id, action, actor) VALUES (?,?,?)")
                       ->execute([$new_id, 'Lead created by dealer ' . $dealer['dealer_code'], $dealer['full_name']]); } catch (Throwable $e) {}
            $success = 'Lead added.';
        } catch (Throwable $e) { $error = 'Could not add lead: ' . $e->getMessage(); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lead_status') {
    $lid    = (int)($_POST['lead_id'] ?? 0);
    $status = in_array(($_POST['status'] ?? ''), ['new', 'contacted', 'quoted', 'won', 'lost'], true) ? $_POST['status'] : 'new';
    try {
        $pdo->prepare("UPDATE leads SET status = ?, last_contacted = NOW(), updated_at = NOW()
                        WHERE id = ? AND dealer_id = ?")->execute([$status, $lid, (int)$dealer['id']]);
        $success = 'Lead updated.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lead_assign') {
    $lid    = (int)($_POST['lead_id'] ?? 0);
    $assign = (int)($_POST['assigned_user_id'] ?? 0) ?: null;
    try {
        if ($assign !== null) {
            $ok = false;
            foreach ($team as $tm) { if ((int)$tm['user_id'] === $assign) { $ok = true; break; } }
            if (!$ok) $assign = null;
        }
        // Scoped to this dealer's own leads, so a rogue lead_id cannot touch another tenant.
        $pdo->prepare("UPDATE leads SET assigned_user_id = ?, updated_at = NOW() WHERE id = ? AND dealer_id = ?")
            ->execute([$assign, $lid, (int)$dealer['id']]);
        $success = 'Lead assignment updated.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

// This dealer's leads only.
$leads = [];
try {
    $q = $pdo->prepare("SELECT l.*, u.name AS assigned_name
                          FROM leads l LEFT JOIN users u ON u.id = l.assigned_user_id
                         WHERE l.dealer_id = ?
                         ORDER BY CASE l.status WHEN 'new' THEN 0 WHEN 'contacted' THEN 1 WHEN 'quoted' THEN 2 WHEN 'won' THEN 3 ELSE 4 END,
                                  l.created_at DESC");
    $q->execute([(int)$dealer['id']]); $leads = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $leads = []; }

$open_leads = array_values(array_filter($leads, fn($l) => !in_array(($l['status'] ?? ''), ['won', 'lost'], true)));
$won = count(array_filter($leads, fn($l) => ($l['status'] ?? '') === 'won'));
?>

  <div class="topbar">
    <div>
      <div class="topbar-title">Leads</div>
      <div class="topbar-sub">Your pipeline — turn a lead into an order</div>
    </div>
    <div class="topbar-right"><?= tier_badge($dealer['tier']) ?></div>
  </div>

  <div class="page-body">
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert" style="background:var(--red-bg);border-color:var(--red);color:var(--red-text);"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="two-col" style="align-items:start;">
      <div class="card" style="margin-bottom:16px;">
        <div class="card-title" style="margin-bottom:6px;">Add a lead</div>
        <div style="font-size:11px;color:var(--text-lt);margin-bottom:14px;">Leads you add stay on your account and can be turned into an order in one click.</div>
        <form method="POST" data-testid="form-lead-add">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="lead_add">
          <div class="form-group">
            <label class="form-label">Full name</label>
            <input type="text" name="name" class="form-control" placeholder="First Last" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" placeholder="lead@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control" placeholder="832-555-0100" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Company</label>
            <input type="text" name="company" class="form-control" value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Street</label>
              <input type="text" name="street" class="form-control" value="<?= htmlspecialchars($_POST['street'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">ZIP</label>
              <input type="text" name="zip_code" class="form-control" value="<?= htmlspecialchars($_POST['zip_code'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Source</label>
              <input type="text" name="source" class="form-control" placeholder="referral, walk-in, ad" value="<?= htmlspecialchars($_POST['source'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Assign to</label>
            <select name="assigned_user_id" class="form-control">
              <option value="">— unassigned —</option>
              <?php foreach ($team as $tm): ?>
              <option value="<?= (int)$tm['user_id'] ?>"><?= htmlspecialchars((string)$tm['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;" data-testid="button-lead-add">Add lead</button>
        </form>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-title">Your leads (<?= count($leads) ?>)</span>
          <span style="font-size:11px;color:var(--text-lt);"><?= $won ?> won</span>
        </div>
        <?php if ($leads): ?>
        <table class="table">
          <thead>
            <tr><th>Lead</th><th>Contact</th><th>Status</th><th>Assigned</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($leads as $l): ?>
            <tr data-testid="row-lead-<?= (int)$l['id'] ?>">
              <td>
                <div style="font-weight:600;"><?= htmlspecialchars((string)($l['name'] ?: $l['full_name'] ?: '—')) ?></div>
                <div style="font-size:11px;color:var(--text-lt);"><?= htmlspecialchars((string)($l['company'] ?: $l['company_name'] ?: '')) ?><?= !empty($l['lead_number']) ? ' · #' . htmlspecialchars((string)$l['lead_number']) : '' ?></div>
              </td>
              <td style="font-size:12px;">
                <?= htmlspecialchars((string)($l['email'] ?? '')) ?><br>
                <span style="color:var(--text-lt);"><?= htmlspecialchars((string)($l['phone'] ?? '')) ?></span>
              </td>
              <td>
                <form method="POST" style="display:flex;gap:4px;align-items:center;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="lead_status">
                  <input type="hidden" name="lead_id" value="<?= (int)$l['id'] ?>">
                  <select name="status" class="form-control" style="padding:4px 6px;font-size:12px;">
                    <?php foreach (['new'=>'New','contacted'=>'Contacted','quoted'=>'Quoted','won'=>'Won','lost'=>'Lost'] as $sv=>$sl): ?>
                    <option value="<?= $sv ?>" <?= ($l['status'] ?? '') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-outline btn-sm" style="padding:4px 8px;">Save</button>
                </form>
              </td>
              <td>
                <form method="POST" style="display:flex;gap:4px;align-items:center;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="lead_assign">
                  <input type="hidden" name="lead_id" value="<?= (int)$l['id'] ?>">
                  <select name="assigned_user_id" class="form-control" style="padding:4px 6px;font-size:12px;">
                    <option value="">— unassigned —</option>
                    <?php foreach ($team as $tm): ?>
                    <option value="<?= (int)$tm['user_id'] ?>" <?= (int)($l['assigned_user_id'] ?? 0) === (int)$tm['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$tm['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-outline btn-sm" style="padding:4px 8px;">Set</button>
                </form>
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <?php if (!empty($l['converted_order_id'])): ?>
                  <span style="font-size:11px;color:var(--green);">order #<?= (int)$l['converted_order_id'] ?></span>
                <?php else: ?>
                  <a href="dealer-orders.php?lead_id=<?= (int)$l['id'] ?>" class="btn btn-primary btn-sm" data-testid="button-lead-order-<?= (int)$l['id'] ?>">Create order</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div style="padding:28px;text-align:center;color:var(--text-lt);font-size:13px;">
          No leads yet. Add your first one — then turn it into an order in one click.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
