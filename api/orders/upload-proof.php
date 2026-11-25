<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    student_json(405, ['success' => false, 'message' => 'طريقة الطلب غير مسموح بها.']);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$transactionRef = trim((string)($_POST['transaction_reference'] ?? ''));

if ($orderId <= 0) {
    student_json(400, ['success' => false, 'message' => 'رقم الطلب غير صالح.']);
}

if ($transactionRef === '') {
    student_json(400, ['success' => false, 'message' => 'يرجى إدخال الرقم المرجعي أو رقم العملية المحول منها.']);
}

try {
    $stmt = $pdo->prepare('SELECT id, order_number, total_amount, currency, status FROM orders WHERE id = ? AND student_id = ?');
    $stmt->execute([$orderId, $currentStudent['id']]);
    $order = $stmt->fetch();

    if (!$order) {
        student_json(404, ['success' => false, 'message' => 'الطلب غير موجود.']);
    }

    if ($order['status'] === 'paid') {
        student_json(400, ['success' => false, 'message' => 'هذا الطلب مدفوع ومعتمد بالفعل.']);
    }

    // Process receipt file upload
    $proofPath = null;
    if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['proof_file'];
        if ($file['size'] > 5 * 1024 * 1024) {
            student_json(400, ['success' => false, 'message' => 'حجم إيصال الدفع يجب ألا يتجاوز 5 ميجابايت.']);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($mime, $allowed, true)) {
            student_json(400, ['success' => false, 'message' => 'صيغة الإيصال يجب أن تكون صورة (JPG, PNG, WEBP) أو ملف PDF.']);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin'
        };

        $uploadDir = __DIR__ . '/../../uploads/receipts';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = 'receipt_' . $order['order_number'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            student_json(500, ['success' => false, 'message' => 'تعذر حفظ ملف الإيصال.']);
        }

        $proofPath = 'uploads/receipts/' . $fileName;
    }

    // Update order status to pending_review
    $up = $pdo->prepare("
        UPDATE orders 
        SET status = 'pending_review',
            transaction_reference = ?,
            proof_path = COALESCE(?, proof_path),
            updated_at = NOW()
        WHERE id = ?
    ");
    $up->execute([$transactionRef, $proofPath, $orderId]);

    // Also record into payments table for admin payment review queue
    $checkPay = $pdo->prepare('SELECT id FROM payments WHERE transaction_reference = ? AND student_id = ?');
    $checkPay->execute([$transactionRef, $currentStudent['id']]);
    if (!$checkPay->fetch()) {
        $crsStmt = $pdo->prepare("SELECT item_id FROM order_items WHERE order_id = ? AND item_type = 'course' LIMIT 1");
        $crsStmt->execute([$orderId]);
        $courseIdVal = $crsStmt->fetchColumn();
        $courseIdVal = $courseIdVal ? (int)$courseIdVal : null;

        $insPay = $pdo->prepare("
            INSERT INTO payments (
                student_id, course_id, amount, currency, payment_method, 
                status, transaction_reference, proof_path, created_at
            ) VALUES (?, ?, ?, ?, 'instapay', 'under_review', ?, ?, NOW())
        ");
        $insPay->execute([
            $currentStudent['id'],
            $courseIdVal,
            $order['total_amount'],
            $order['currency'],
            $transactionRef,
            $proofPath
        ]);
    }

    student_json(200, [
        'success' => true,
        'message' => 'تم استلام إثبات الدفع بنجاح، طلبك الآن قيد المراجعة والاعتماد من قبل الإدارة.',
        'order_status' => 'pending_review',
        'proof_path' => $proofPath
    ]);

} catch (Throwable $e) {
    error_log('[Upload proof error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر رفع إثبات الدفع.']);
}
