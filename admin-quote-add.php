<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$leads_for_form = $pdo->query("SELECT id, full_name, email FROM crm_leads ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$create_msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'create_quote') {
        $lead_id       = (int)($_POST['lead_id'] ?? 0);
        $doc_date      = $_POST['document_date'] ?? date('Y-m-d');
        $valid_until   = trim($_POST['valid_until'] ?? '');
        $note          = trim($_POST['note'] ?? '');
        $raw_items = $_POST['items'] ?? [];
        $items = [];
        $subtotal = 0; $tax_total = 0;
        if (is_array($raw_items)) {
            foreach ($raw_items as $ri) {
                $name = trim($ri['item'] ?? '');
                if ($name === '') continue;
                $qty = (float)($ri['qty'] ?? 1);
                $unit = (float)($ri['unit_price'] ?? 0);
                $tax_rate = (float)($ri['tax_rate'] ?? 0);
                $amount = $qty * $unit;
                $tax = $amount * ($tax_rate / 100);
                $items[] = ['item'=>$name,'description'=>trim($ri['description']??''),'qty'=>$qty,'unit_price'=>$unit,'tax_rate'=>$tax_rate,'amount'=>round($amount+$tax,2)];
                $subtotal += $amount;
                $tax_total += $tax;
            }
        }
        $grand_total = $subtotal + $tax_total;
        if ($lead_id > 0) {
            $qnum = 'Q-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
            try {
                $stmt = $pdo->prepare("INSERT INTO lead_quotes (lead_id, quote_number, status, document_date, valid_until, deal_value, note, items, total_without_tax, tax_amount, total, created_at) VALUES (?,?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$lead_id, $qnum, $doc_date, $valid_until !== '' ? $valid_until : null, $grand_total, $note, json_encode($items), $subtotal, $tax_total, $grand_total]);
                portal_redirect('admin-leads-quotes.php?created=1');
            } catch (Exception $e) {
                $create_msg = 'Error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Quote</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{font-family:Inter,sans-serif;background:#000;color:#fff;margin:0;padding:20px;}
.card{background:#141824;border:1px solid #1E2436;border-radius:12px;padding:24px;margin-bottom:24px;}
.input{width:100%;padding:8px 12px;background:#000;border:1px solid #1E2436;border-radius:6px;color:#fff;}
.input:focus{border-color:#3B5BFD;box-shadow:0 0 0 2px rgba(59,91,253,0.15);outline:none;}
.btn-primary{background:#3B5BFD;color:#fff;padding:8px 20px;border:none;border-radius:6px;cursor:pointer;font-weight:600;}
.btn-secondary{background:transparent;color:#fff;border:1px solid #1E2436;padding:8px 20px;border-radius:6px;cursor:pointer;}
.quote-table{width:100%;border-collapse:collapse;}
.quote-table th,.quote-table td{padding:8px;border-bottom:1px solid #1E2436;}
.quote-table input{width:100%;padding:6px;background:#000;border:1px solid #1E2436;border-radius:4px;color:#fff;}
</style>
</head>
<body>
<div class="card">
  <h1 style="margin-bottom:16px;">Create Quote</h1>
  <?php if ($create_msg): ?><div style="color:#E05A4E;margin-bottom:12px;"><?= htmlspecialchars($create_msg) ?></div><?php endif; ?>
  <form method="POST">
    <input type="hidden" name="action" value="create_quote">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;color:#8C8C8C;text-transform:uppercase;">Lead *</label>
        <select name="lead_id" required class="input">
          <option value="">Select lead…</option>
          <?php foreach ($leads_for_form as $ld): ?>
            <option value="<?= (int)$ld['id'] ?>"><?= htmlspecialchars($ld['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:11px;color:#8C8C8C;text-transform:uppercase;">Document Date</label>
        <input type="date" name="document_date" value="<?= date('Y-m-d') ?>" class="input">
      </div>
      <div>
        <label style="font-size:11px;color:#8C8C8C;text-transform:uppercase;">Valid Until</label>
        <input type="date" name="valid_until" class="input">
      </div>
    </div>
    <table class="quote-table" id="quoteItemsTable">
      <thead><tr><th>Item</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Tax %</th><th>Amount</th><th></th></tr></thead>
      <tbody id="quoteItemsBody"></tbody>
    </table>
    <button type="button" onclick="addQuoteItem()" class="btn-secondary" style="margin:12px 0;">+ Add Line Item</button>
    <div style="text-align:right;margin:16px 0;">
      <div>Subtotal: <span id="qSubtotal">$0.00</span></div>
      <div>Tax: <span id="qTax">$0.00</span></div>
      <div style="font-weight:700;color:#3B5BFD;">Total: <span id="qTotal">$0.00</span></div>
    </div>
    <div style="margin-bottom:16px;">
      <label style="font-size:11px;color:#8C8C8C;text-transform:uppercase;">Notes</label>
      <textarea name="note" class="input" rows="3"></textarea>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" onclick="location.href='admin-leads-quotes.php'" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary">Create Quote</button>
    </div>
  </form>
</div>
<script>
let qi=0;
function addQuoteItem(){
  const tbody=document.getElementById('quoteItemsBody');
  const idx=qi++;
  const tr=document.createElement('tr');
  tr.innerHTML=`<td><input name="items[${idx}][item]" required></td><td><input name="items[${idx}][description]"></td><td><input name="items[${idx}][qty]" type="number" step="0.01" value="1" onchange="recalcQuote()" oninput="recalcQuote()"></td><td><input name="items[${idx}][unit_price]" type="number" step="0.01" value="0" onchange="recalcQuote()" oninput="recalcQuote()"></td><td><input name="items[${idx}][tax_rate]" type="number" step="0.01" value="0" onchange="recalcQuote()" oninput="recalcQuote()"></td><td class="qAmount">$0.00</td><td><button type="button" onclick="this.closest('tr').remove();recalcQuote();" style="color:#E05A4E;">&times;</button></td>`;
  tbody.appendChild(tr);
  recalcQuote();
}
function recalcQuote(){
  let sub=0,tax=0;
  document.querySelectorAll('#quoteItemsBody tr').forEach(tr=>{
    const qty=parseFloat(tr.querySelector('input[name*="[qty]"]').value)||0;
    const unit=parseFloat(tr.querySelector('input[name*="[unit_price]"]').value)||0;
    const trate=parseFloat(tr.querySelector('input[name*="[tax_rate]"]').value)||0;
    const amt=qty*unit;const t=amt*(trate/100);
    sub+=amt;tax+=t;
    tr.querySelector('.qAmount').textContent='$'+(amt+t).toFixed(2);
  });
  document.getElementById('qSubtotal').textContent='$'+sub.toFixed(2);
  document.getElementById('qTax').textContent='$'+tax.toFixed(2);
  document.getElementById('qTotal').textContent='$'+(sub+tax).toFixed(2);
}
addQuoteItem();
</script>
</body>
</html>
