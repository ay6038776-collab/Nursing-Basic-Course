<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

// Auto-heal orders & payments tables
try {
    $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_proof_path', $oCols)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_proof_path VARCHAR(500) NULL");
        if (in_array('proof_path', $oCols)) {
            $pdo->exec("UPDATE orders SET payment_proof_path = proof_path WHERE payment_proof_path IS NULL");
        }
    }
    if (!in_array('admin_notes', $oCols)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN admin_notes TEXT NULL");
        if (in_array('notes', $oCols)) {
            $pdo->exec("UPDATE orders SET admin_notes = notes WHERE admin_notes IS NULL");
        }
    }
    if (!in_array('reviewed_by', $oCols)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN reviewed_by INT UNSIGNED NULL");
    }
    if (!in_array('reviewed_at', $oCols)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN reviewed_at TIMESTAMP NULL");
    }
} catch (Throwable $ignored) {}

$action = (string)($_GET['action'] ?? 'list');

/* ─── LIST PAYMENTS ─── */
if ($action === 'list') {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = 20;
    $offset  = ($page - 1) * $limit;
    $status  = trim((string)($_GET['status'] ?? ''));
    $method  = trim((string)($_GET['method'] ?? ''));
    $search  = trim((string)($_GET['search'] ?? ''));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo   = trim((string)($_GET['date_to']   ?? ''));

    $where = ['1=1']; $p = [];
    if ($status !== '') { $where[] = 'o.status = ?'; $p[] = $status; }
    if ($method !== '') { $where[] = 'o.payment_method = ?'; $p[] = $method; }
    if ($dateFrom) { $where[] = 'DATE(o.created_at) >= ?'; $p[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(o.created_at) <= ?'; $p[] = $dateTo; }
    if ($search !== '') {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR o.order_number LIKE ? OR o.transaction_reference LIKE ?)';
        $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%";
    }
    $w = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON u.id=o.student_id WHERE $w");
    $countStmt->execute($p);
    $total = (int)$countStmt->fetchColumn();

    $ordCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    $proofCol = in_array('payment_proof_path', $ordCols) ? 'o.payment_proof_path' : (in_array('proof_path', $ordCols) ? 'o.proof_path' : 'NULL');
    $notesCol = in_array('admin_notes', $ordCols) ? 'o.admin_notes' : (in_array('notes', $ordCols) ? 'o.notes' : 'NULL');
    $revAtCol = in_array('reviewed_at', $ordCols) ? 'o.reviewed_at' : 'NULL';
    $revByCol = in_array('reviewed_by', $ordCols) ? 'o.reviewed_by' : 'NULL';

    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.total_amount, o.currency, o.status,
               o.payment_method, o.transaction_reference,
               {$proofCol} AS payment_proof_path,
               {$notesCol} AS admin_notes,
               o.created_at,
               {$revAtCol} AS reviewed_at,
               u.id AS student_id, u.full_name AS student_name, u.email AS student_email,
               reviewer.full_name AS reviewed_by_name
        FROM orders o
        JOIN users u ON u.id = o.student_id
        LEFT JOIN users reviewer ON reviewer.id = {$revByCol}
        WHERE $w ORDER BY o.id DESC LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($p);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach items (course names)
    $oiCols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
    $oiJoinCol = in_array('course_id', $oiCols) ? 'oi.course_id' : (in_array('item_id', $oiCols) ? 'oi.item_id' : '0');
    $itemStmt = $pdo->prepare("SELECT oi.*, COALESCE(c.title_ar, c.title, 'كورس') AS course_title FROM order_items oi LEFT JOIN courses c ON c.id={$oiJoinCol} WHERE oi.order_id=?");
    foreach ($orders as &$ord) {
        $itemStmt->execute([$ord['id']]);
        $ord['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ord);

    admin_json(200, ['success' => true, 'orders' => $orders,
        'meta' => ['total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $limit)]]);
}

/* ─── APPROVE PAYMENT ─── */
if ($action === 'approve') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($input['order_id'] ?? 0);
    $note    = trim((string)($input['note'] ?? ''));

    if ($orderId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الطلب مطلوب.']);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) { $pdo->rollBack(); admin_json(404, ['success' => false, 'message' => 'الطلب غير موجود.']); }
        if ($order['status'] === 'paid') { $pdo->rollBack(); admin_json(400, ['success' => false, 'message' => 'هذا الطلب مؤكد الدفع بالفعل.']); }

        // Idempotency: check if already processed
        $existNot = $pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND type=? AND message LIKE ?');
        $existNot->execute([$order['student_id'], 'order_approved', '%' . $order['order_number'] . '%']);

        $pdo->prepare("UPDATE orders SET status='paid', admin_notes=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$note, $currentUser['id'], $orderId]);

        // Activate enrollments
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id=?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll() as $it) {
            if ($it['item_type'] === 'course') {
                $courseId  = (int)$it['item_id'];
                $studentId = (int)$order['student_id'];

                $dup = $pdo->prepare('SELECT id, status FROM enrollments WHERE student_id=? AND course_id=?');
                $dup->execute([$studentId, $courseId]);
                $existing = $dup->fetch();

                if ($existing) {
                    $pdo->prepare("UPDATE enrollments SET status='active', access_status='granted', updated_at=NOW() WHERE id=?")
                        ->execute([$existing['id']]);
                    $enrollId = $existing['id'];
                } else {
                    $ins = $pdo->prepare("INSERT INTO enrollments (student_id,course_id,status,access_status,enrolled_at,starts_at,created_by,created_at,updated_at)
                        VALUES (?,?,'active','granted',NOW(),CURDATE(),?,NOW(),NOW())");
                    $ins->execute([$studentId, $courseId, $currentUser['id']]);
                    $enrollId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare("INSERT INTO enrollment_events (enrollment_id,new_status,old_status,actor_user_id,note,created_at)
                    VALUES (?,'active','pending',?,?,NOW())")
                    ->execute([$enrollId, $currentUser['id'], 'تفعيل تلقائي بعد تأكيد الدفع ' . $order['order_number']]);
            }
        }

        // Invoice
        $inv = $pdo->prepare('SELECT id FROM invoices WHERE order_id=?');
        $inv->execute([$orderId]);
        if (!$inv->fetch()) {
            $invNum = 'INV-' . date('Ym') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO invoices (invoice_number,order_id,student_id,amount,currency,status,invoice_date,created_at)
                VALUES (?,?,?,?,'EGP','paid',CURDATE(),NOW())")
                ->execute([$invNum, $orderId, $order['student_id'], $order['total_amount']]);
        }

        // Audit log
        $pdo->prepare("INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,details_json,created_at)
            VALUES (?,'payment_approved','order',?,?,NOW())")
            ->execute([$currentUser['id'], $orderId, json_encode(['order_number' => $order['order_number'], 'amount' => $order['total_amount']])]);

        // Notify student
        $pdo->prepare("INSERT INTO notifications (user_id,title,message,type,created_at) VALUES (?,?,?,'order_approved',NOW())")
            ->execute([$order['student_id'], 'تم تأكيد الدفع وتفعيل اشتراكك', "تم تأكيد عملية الدفع واعتماد طلبك رقم {$order['order_number']}. يمكنك الوصول للمحتوى الآن."]);

        $pdo->commit();
        admin_json(200, ['success' => true, 'message' => 'تم تأكيد الدفع وتفعيل التسجيلات بنجاح.']);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Payment approve] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'تعذر تأكيد الدفع.']);
    }
}

/* ─── REJECT PAYMENT ─── */
if ($action === 'reject') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($input['order_id'] ?? 0);
    $reason  = trim((string)($input['reason'] ?? 'لم يتطابق إثبات التحويل.'));

    if ($orderId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الطلب مطلوب.']);

    $ord = $pdo->prepare('SELECT student_id, order_number FROM orders WHERE id=?');
    $ord->execute([$orderId]);
    $order = $ord->fetch();
    if (!$order) admin_json(404, ['success' => false, 'message' => 'الطلب غير موجود.']);

    $pdo->prepare("UPDATE orders SET status='rejected', admin_notes=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
        ->execute([$reason, $currentUser['id'], $orderId]);

    $pdo->prepare("INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,details_json,created_at) VALUES (?,'payment_rejected','order',?,?,NOW())")
        ->execute([$currentUser['id'], $orderId, json_encode(['reason' => $reason])]);

    $pdo->prepare("INSERT INTO notifications (user_id,title,message,type,created_at) VALUES (?,?,?,'order_rejected',NOW())")
        ->execute([$order['student_id'], 'بشأن طلب الدفع', "نأسف، لم يتم اعتماد عملية الدفع للطلب {$order['order_number']}. السبب: $reason. يرجى التواصل معنا."]);

    admin_json(200, ['success' => true, 'message' => 'تم رفض الطلب وإشعار الطالب.']);
}

/* ─── REFUND ─── */
if ($action === 'refund') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId  = (int)($input['order_id'] ?? 0);
    $reason   = trim((string)($input['reason'] ?? 'استرداد مالي'));
    $cancelEnrollment = (bool)($input['cancel_enrollment'] ?? true);

    if ($orderId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الطلب مطلوب.']);

    try {
        $pdo->beginTransaction();

        $ord = $pdo->prepare('SELECT * FROM orders WHERE id=?');
        $ord->execute([$orderId]);
        $order = $ord->fetch();
        if (!$order) { $pdo->rollBack(); admin_json(404, ['success' => false, 'message' => 'الطلب غير موجود.']); }

        $pdo->prepare("UPDATE orders SET status='refunded', admin_notes=?, reviewed_by=?, updated_at=NOW() WHERE id=?")
            ->execute(["استرداد: $reason", $currentUser['id'], $orderId]);

        if ($cancelEnrollment) {
            $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? AND item_type=?');
            $items->execute([$orderId, 'course']);
            foreach ($items->fetchAll() as $it) {
                $pdo->prepare("UPDATE enrollments SET status='cancelled', access_status='blocked', updated_at=NOW()
                    WHERE student_id=? AND course_id=? AND status != 'cancelled'")
                    ->execute([$order['student_id'], $it['item_id']]);
            }
        }

        $pdo->prepare("INSERT INTO notifications (user_id,title,message,type,created_at) VALUES (?,?,?,'refund',NOW())")
            ->execute([$order['student_id'], 'تم معالجة طلب الاسترداد', "تم معالجة طلب الاسترداد للطلب {$order['order_number']}. سيتم التواصل معك قريباً."]);

        $pdo->commit();
        admin_json(200, ['success' => true, 'message' => 'تم تسجيل الاسترداد.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_json(500, ['success' => false, 'message' => 'تعذر معالجة الاسترداد.']);
    }
}

/* ─── STATS ─── */
if ($action === 'stats') {
    $dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
    $dateTo   = trim((string)($_GET['date_to']   ?? date('Y-m-d')));

    $stats = [];
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt, SUM(total_amount) AS total FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status");
    $stmt->execute([$dateFrom, $dateTo]);
    foreach ($stmt->fetchAll() as $row) {
        $stats[$row['status']] = ['count' => $row['cnt'], 'total' => $row['total']];
    }

    $methodStmt = $pdo->prepare("SELECT payment_method, COUNT(*) AS cnt, SUM(total_amount) AS total FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY payment_method");
    $methodStmt->execute([$dateFrom, $dateTo]);

    admin_json(200, ['success' => true, 'by_status' => $stats, 'by_method' => $methodStmt->fetchAll(PDO::FETCH_ASSOC)]);
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير معروف.']);
