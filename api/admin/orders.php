<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

if ($action === 'list') {
    $status = trim((string)($_GET['status'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));

    $sql = "
        SELECT o.*, u.full_name AS student_name, u.email AS student_email, u.phone AS student_phone,
               inv.id AS invoice_id, inv.invoice_number
        FROM orders o
        JOIN users u ON u.id = o.student_id
        LEFT JOIN invoices inv ON inv.order_id = o.id
        WHERE 1=1
    ";
    $params = [];

    if ($status !== '') {
        $sql .= " AND o.status = ?";
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= " AND (o.order_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR o.transaction_reference LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY o.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    foreach ($orders as &$ord) {
        $itemStmt->execute([$ord['id']]);
        $ord['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ord);

    admin_json(200, [
        'success' => true,
        'orders' => $orders
    ]);
}

if ($action === 'approve_payment') {
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);

    if ($orderId <= 0) {
        admin_json(400, ['success' => false, 'message' => 'رقم الطلب غير صالح.']);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $pdo->rollBack();
            admin_json(404, ['success' => false, 'message' => 'الطلب غير موجود.']);
        }

        if ($order['status'] === 'paid') {
            $pdo->rollBack();
            admin_json(400, ['success' => false, 'message' => 'هذا الطلب مدفوع ومعتمد بالفعل.']);
        }

        // 1) Update order status to paid
        $up = $pdo->prepare("
            UPDATE orders 
            SET status = 'paid', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() 
            WHERE id = ?
        ");
        $up->execute([$currentUser['id'], $orderId]);

        // 2) Fetch order items
        $itStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $itStmt->execute([$orderId]);
        $items = $itStmt->fetchAll();

        // 3) Activate enrollments for courses & grant access for books
        $studentId = (int)$order['student_id'];
        foreach ($items as $it) {
            if ($it['item_type'] === 'course') {
                $courseId = (int)$it['item_id'];
                // Check existing enrollment
                $checkEnr = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND course_id = ?');
                $checkEnr->execute([$studentId, $courseId]);
                $existing = $checkEnr->fetch();
                if ($existing) {
                    $pdo->prepare("UPDATE enrollments SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$existing['id']]);
                } else {
                    $pdo->prepare("INSERT INTO enrollments (student_id, course_id, status, enrolled_at) VALUES (?, ?, 'active', NOW())")->execute([$studentId, $courseId]);
                }
            } elseif ($it['item_type'] === 'book') {
                $bookId = (int)$it['item_id'];
                $pdo->prepare("INSERT IGNORE INTO book_access (student_id, book_id, order_id, status, granted_at) VALUES (?, ?, ?, 'active', NOW())")
                    ->execute([$studentId, $bookId, $orderId]);
            }
        }

        // 4) Create invoice record if not exists
        $checkInv = $pdo->prepare('SELECT id FROM invoices WHERE order_id = ?');
        $checkInv->execute([$orderId]);
        if (!$checkInv->fetch()) {
            $invNumber = 'INV-' . date('Ym') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("
                INSERT INTO invoices (invoice_number, order_id, student_id, amount, currency, status, invoice_date, created_at)
                VALUES (?, ?, ?, ?, 'EGP', 'paid', CURDATE(), NOW())
            ")->execute([$invNumber, $orderId, $studentId, $order['total_amount']]);
        }

        // 5) Log in audit_logs
        $pdo->prepare("
            INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details_json, created_at)
            VALUES (?, 'order_approved', 'order', ?, ?, NOW())
        ")->execute([
            $currentUser['id'],
            $orderId,
            json_encode(['order_number' => $order['order_number'], 'amount' => $order['total_amount']])
        ]);

        // 6) Create notification for student
        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at)
            VALUES (?, 'تم اعتماد وتفعيل طلبك بنجاح', ?, 'order_approved', NOW())
        ")->execute([
            $studentId,
            "تم تأكيد عملية الدفع واعتماد طلبك رقم {$order['order_number']}. يمكنك الآن الوصول للمحتوى مباشرة."
        ]);

        $pdo->commit();

        admin_json(200, [
            'success' => true,
            'message' => 'تم اعتماد الدفع وتفعيل الكورسات والكتب بنجاح وإصدار الفاتورة.',
            'order_id' => $orderId
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Approve payment error] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'تعذر اعتماد الدفع.']);
    }
}

if ($action === 'reject_payment') {
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? 'بيانات التحويل غير مطابقة أو تعذر التحقق من الإيصال.'));

    if ($orderId <= 0) {
        admin_json(400, ['success' => false, 'message' => 'رقم الطلب غير صالح.']);
    }

    try {
        $up = $pdo->prepare("
            UPDATE orders 
            SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$reason, $currentUser['id'], $orderId]);

        $ordStmt = $pdo->prepare('SELECT student_id, order_number FROM orders WHERE id = ?');
        $ordStmt->execute([$orderId]);
        $ord = $ordStmt->fetch();

        if ($ord) {
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, 'تنبيه بشأن طلبك', ?, 'order_rejected', NOW())
            ")->execute([
                $ord['student_id'],
                "نأسف لعدم اعتماد عملية الدفع للطلب {$ord['order_number']}. السبب: $reason. يرجى التواصل معنا عبر واتساب للمساعدة."
            ]);
        }

        admin_json(200, [
            'success' => true,
            'message' => 'تم رفض الطلب وتسجيل السبب وإشعار الطالب.'
        ]);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر تحديث حالة الطلب.']);
    }
}

if ($action === 'update_shipping') {
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    $status = (string)($input['status'] ?? 'shipped');
    $tracking = trim((string)($input['tracking_number'] ?? ''));

    if ($orderId <= 0 || !in_array($status, ['in_preparation', 'shipped', 'delivered', 'cancelled'], true)) {
        admin_json(400, ['success' => false, 'message' => 'بيانات الشحن غير صالحة.']);
    }

    try {
        $up = $pdo->prepare("UPDATE orders SET status = ?, tracking_number = ?, updated_at = NOW() WHERE id = ?");
        $up->execute([$status, $tracking, $orderId]);

        admin_json(200, [
            'success' => true,
            'message' => 'تم تحديث حالة الشحن بنجاح.'
        ]);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر تحديث الشحن.']);
    }
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير معروف.']);
