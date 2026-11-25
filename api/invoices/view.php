<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings_helper.php';

$orderId = (int)($_GET['order_id'] ?? 0);
$invoiceNum = trim((string)($_GET['invoice_number'] ?? ''));

// Check authentication: can be viewed if user is admin, or if student owns the order
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Check admin session first
    session_name('NBC_ADMIN_SESSID');
    @session_start();
    $userId = $_SESSION['user_id'] ?? null;
    $role = 'admin';

    if (!$userId) {
        // Try student session
        session_write_close();
        session_name('NBC_STUDENT_SESSID');
        @session_start();
        $userId = $_SESSION['user_id'] ?? null;
        $role = 'student';
    }
}

if (!$userId) {
    http_response_code(401);
    exit('<!DOCTYPE html><html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>يجب تسجيل الدخول لعرض الفاتورة</h2></body></html>');
}

$identityStmt = $pdo->prepare('SELECT role,status FROM users WHERE id=? LIMIT 1');
$identityStmt->execute([(int)$userId]);
$identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
$roles = $identity ? [(string)$identity['role']] : [];
if ($identity) {
    try {
        $roleStmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id=?');
        $roleStmt->execute([(int)$userId]);
        $roles = array_merge($roles, $roleStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {}
}
$requiredRole = $role === 'admin' ? 'admin' : 'student';
if (!$identity || $identity['status'] !== 'active' || !in_array($requiredRole, $roles, true)) {
    http_response_code(403);
    exit('الحساب غير نشط أو غير مصرح له.');
}
$role = $requiredRole;

// Fetch order
$stmt = $pdo->prepare("
    SELECT o.*, u.full_name, u.email, u.phone AS user_phone,
           inv.id AS invoice_id, inv.invoice_number, inv.invoice_date
    FROM orders o
    JOIN users u ON u.id = o.student_id
    LEFT JOIN invoices inv ON inv.order_id = o.id
    WHERE (o.id = ? OR inv.invoice_number = ?)
    LIMIT 1
");
$stmt->execute([$orderId, $invoiceNum]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('<!DOCTYPE html><html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>الفاتورة غير موجودة</h2></body></html>');
}

// Security: If not admin, must be order owner
if ($role === 'student' && (int)$order['student_id'] !== (int)$userId) {
    http_response_code(403);
    exit('<!DOCTYPE html><html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>غير مصرح لك بمشاهدة هذه الفاتورة</h2></body></html>');
}

// Fetch items
$itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemStmt->execute([$order['id']]);
$items = $itemStmt->fetchAll();

$settings = get_platform_settings($pdo);

$invNumber = $order['invoice_number'] ?? ('INV-' . date('Ym', strtotime($order['created_at'])) . '-' . str_pad((string)$order['id'], 4, '0', STR_PAD_LEFT));
$invDate = $order['invoice_date'] ?? date('Y-m-d', strtotime($order['created_at']));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>فاتورة رسمية | <?= htmlspecialchars($invNumber) ?> — Edu Nursing</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --teal: #0d9488;
    --navy: #0a1f3d;
    --ink: #0f172a;
    --muted: #64748b;
    --line: #e2e8f0;
    --bg-card: #ffffff;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    padding: 30px 15px;
    font-family: 'Cairo', system-ui, sans-serif;
    background: #f8fafc;
    color: var(--ink);
    line-height: 1.6;
  }
  .invoice-card {
    max-width: 820px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(10,31,61,0.06);
    padding: 40px;
    position: relative;
    overflow: hidden;
  }
  .invoice-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0; left: 0;
    height: 6px;
    background: linear-gradient(90deg, var(--teal), var(--navy));
  }
  .inv-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 20px;
    padding-bottom: 25px;
    border-bottom: 2px dashed var(--line);
  }
  .brand-block h1 {
    margin: 0;
    font-size: 24px;
    color: var(--navy);
    font-weight: 900;
  }
  .brand-block p {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--teal);
    font-weight: 700;
  }
  .brand-founder {
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
  }
  .inv-meta {
    text-align: left;
  }
  .inv-meta h2 {
    margin: 0 0 6px;
    font-size: 20px;
    color: var(--teal);
    font-family: 'Inter', sans-serif;
  }
  .inv-meta-row {
    font-size: 13px;
    color: var(--muted);
  }
  .inv-meta-row strong {
    color: var(--ink);
  }
  .badge-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    margin-top: 6px;
  }
  .badge-paid { background: #dcfce7; color: #15803d; }
  .badge-pending { background: #fef3c7; color: #b45309; }
  .badge-review { background: #e0f2fe; color: #0369a1; }
  
  .parties-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin: 25px 0;
    background: #f8fafc;
    padding: 20px;
    border-radius: 12px;
  }
  .party-col h4 {
    margin: 0 0 8px;
    font-size: 14px;
    color: var(--navy);
    font-weight: 800;
  }
  .party-col p {
    margin: 3px 0;
    font-size: 13px;
    color: var(--muted);
  }
  .party-col p strong { color: var(--ink); }

  table {
    width: 100%;
    border-collapse: collapse;
    margin: 25px 0;
    font-size: 14px;
  }
  th {
    background: var(--navy);
    color: #fff;
    padding: 12px 16px;
    text-align: right;
    font-weight: 700;
  }
  td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--line);
  }
  .totals-area {
    width: 320px;
    margin-right: auto;
    margin-left: 0;
    border-top: 2px solid var(--line);
    padding-top: 15px;
  }
  .totals-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 14px;
    color: var(--muted);
  }
  .totals-row.grand {
    font-size: 18px;
    font-weight: 900;
    color: var(--navy);
    border-top: 1px solid var(--line);
    margin-top: 8px;
    padding-top: 8px;
  }
  .inv-footer {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid var(--line);
    text-align: center;
    font-size: 12.5px;
    color: var(--muted);
  }
  .actions-bar {
    max-width: 820px;
    margin: 0 auto 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }
  .btn-print {
    padding: 10px 20px;
    background: var(--teal);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(13,148,136,0.25);
  }
  .btn-print:hover { background: #0f766e; }
  @media print {
    body { background: #fff; padding: 0; }
    .actions-bar { display: none; }
    .invoice-card { box-shadow: none; border: none; padding: 0; }
  }
</style>
</head>
<body>

<div class="actions-bar">
  <button class="btn-print" onclick="window.print()">🖨️ طباعة الفاتورة / حفظ PDF</button>
</div>

<div class="invoice-card">
  <div class="inv-header">
    <div class="brand-block">
      <h1>Edu Nursing</h1>
      <p><?= htmlspecialchars($settings['academy_name_ar']) ?></p>
      <div class="brand-founder">المشرف الأكاديمي: <?= htmlspecialchars($settings['founder_name']) ?></div>
      <div class="brand-founder"><?= htmlspecialchars($settings['founder_title']) ?></div>
    </div>
    <div class="inv-meta">
      <h2><?= htmlspecialchars($invNumber) ?></h2>
      <div class="inv-meta-row">رقم الطلب: <strong><?= htmlspecialchars($order['order_number']) ?></strong></div>
      <div class="inv-meta-row">تاريخ الإصدار: <strong><?= htmlspecialchars($invDate) ?></strong></div>
      <?php
        $st = $order['status'];
        $badgeClass = $st === 'paid' ? 'badge-paid' : ($st === 'pending_review' ? 'badge-review' : 'badge-pending');
        $statusLabels = [
          'paid' => 'مدفوع ومعتمد ✓',
          'pending_review' => 'قيد المراجعة والاعتماد',
          'pending_payment' => 'بانتظار الدفع',
          'in_preparation' => 'قيد التجهيز',
          'shipped' => 'تم الشحن',
          'delivered' => 'تم التوصيل',
          'rejected' => 'مرفوض',
          'cancelled' => 'ملغي'
        ];
        $stText = $statusLabels[$st] ?? $st;
      ?>
      <div><span class="badge-status <?= $badgeClass ?>"><?= htmlspecialchars($stText) ?></span></div>
    </div>
  </div>

  <div class="parties-grid">
    <div class="party-col">
      <h4>بيانات العميل (الطالب):</h4>
      <p>الاسم: <strong><?= htmlspecialchars($order['shipping_name'] ?: $order['full_name']) ?></strong></p>
      <p>البريد: <strong><?= htmlspecialchars($order['email']) ?></strong></p>
      <p>الهاتف: <strong><?= htmlspecialchars($order['shipping_phone'] ?: ($order['user_phone'] ?? '—')) ?></strong></p>
      <?php if (!empty($order['shipping_city']) || !empty($order['shipping_address'])): ?>
      <p>العنوان: <strong><?= htmlspecialchars(trim(($order['shipping_city'] ?? '') . ' - ' . ($order['shipping_address'] ?? ''))) ?></strong></p>
      <?php endif; ?>
    </div>
    <div class="party-col">
      <h4>تفاصيل السداد والدفع:</h4>
      <p>وسيلة الدفع: <strong><?= strtoupper(htmlspecialchars(str_replace('_', ' ', $order['payment_method']))) ?></strong></p>
      <p>رقم التحويل المعتمد: <strong><?= htmlspecialchars($settings['payment_vodafone']) ?></strong></p>
      <?php if (!empty($order['transaction_reference'])): ?>
      <p>الرقم المرجعي للعملية: <strong><?= htmlspecialchars($order['transaction_reference']) ?></strong></p>
      <?php endif; ?>
      <?php if (!empty($order['tracking_number'])): ?>
      <p>رقم بوليصة الشحن: <strong><?= htmlspecialchars($order['tracking_number']) ?></strong></p>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>المنتج / البيان</th>
        <th>النوع</th>
        <th>السعر</th>
        <th>الكمية</th>
        <th>الإجمالي</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $idx => $it): ?>
      <tr>
        <td><?= $idx + 1 ?></td>
        <td><strong><?= htmlspecialchars($it['title_snapshot']) ?></strong></td>
        <td><?= $it['item_type'] === 'course' ? 'كورس تدريبي' : 'كتاب طبي' ?></td>
        <td><?= number_format((float)$it['price_snapshot'], 2) ?> <?= htmlspecialchars($order['currency']) ?></td>
        <td><?= (int)$it['quantity'] ?></td>
        <td><?= number_format((float)$it['subtotal'], 2) ?> <?= htmlspecialchars($order['currency']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals-area">
    <div class="totals-row">
      <span>المجموع الفرعي:</span>
      <span><?= number_format((float)$order['subtotal'], 2) ?> <?= htmlspecialchars($order['currency']) ?></span>
    </div>
    <?php if ((float)$order['shipping_cost'] > 0): ?>
    <div class="totals-row">
      <span>تكلفة الشحن والتوصيل:</span>
      <span><?= number_format((float)$order['shipping_cost'], 2) ?> <?= htmlspecialchars($order['currency']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ((float)$order['discount_amount'] > 0): ?>
    <div class="totals-row">
      <span>الخصم المطبق:</span>
      <span>-<?= number_format((float)$order['discount_amount'], 2) ?> <?= htmlspecialchars($order['currency']) ?></span>
    </div>
    <?php endif; ?>
    <div class="totals-row grand">
      <span>الإجمالي النهائي:</span>
      <span><?= number_format((float)$order['total_amount'], 2) ?> <?= htmlspecialchars($order['currency']) ?></span>
    </div>
  </div>

  <div class="inv-footer">
    <p>شكراً لثقتكم بأكاديمية Edu Nursing — مع تحيات المشرف العام د. خالد عبد الناصر محمد المرسي.</p>
    <p>لأي استفسارات أو دعم فني: واتساب <strong><?= htmlspecialchars($settings['whatsapp_number']) ?></strong> | البريد: <strong>support@edunursing.com</strong></p>
  </div>
</div>

</body>
</html>
