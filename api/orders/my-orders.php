<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.subtotal, o.shipping_cost, o.total_amount, o.currency,
               o.payment_method, o.status, o.transaction_reference, o.proof_path,
               o.shipping_name, o.shipping_phone, o.shipping_address, o.shipping_city, o.tracking_number,
               o.created_at,
               inv.id AS invoice_id, inv.invoice_number
        FROM orders o
        LEFT JOIN invoices inv ON inv.order_id = o.id
        WHERE o.student_id = ?
        ORDER BY o.id DESC
    ");
    $stmt->execute([$currentStudent['id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch items for each order
    $itemStmt = $pdo->prepare("
        SELECT id, item_type, item_id, title_snapshot, price_snapshot, quantity, subtotal
        FROM order_items
        WHERE order_id = ?
    ");

    foreach ($orders as &$ord) {
        $itemStmt->execute([$ord['id']]);
        $ord['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ord);

    student_json(200, [
        'success' => true,
        'orders' => $orders
    ]);
} catch (Throwable $e) {
    error_log('[My orders error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر جلب سجل الطلبات.']);
}
