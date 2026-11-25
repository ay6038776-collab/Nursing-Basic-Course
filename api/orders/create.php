<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    student_json(405, ['success' => false, 'message' => 'طريقة الطلب غير مسموح بها.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    student_json(400, ['success' => false, 'message' => 'بيانات الطلب غير صالحة.']);
}

$items = $input['items'] ?? [];
$paymentMethod = (string)($input['payment_method'] ?? 'vodafone_cash');
$shippingName = trim((string)($input['shipping_name'] ?? $currentStudent['full_name']));
$shippingPhone = trim((string)($input['shipping_phone'] ?? ($currentStudent['phone'] ?? '')));
$shippingAddress = trim((string)($input['shipping_address'] ?? ''));
$shippingCity = trim((string)($input['shipping_city'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));

if (empty($items) || !is_array($items)) {
    student_json(400, ['success' => false, 'message' => 'سلة الشراء فارغة.']);
}

if (!in_array($paymentMethod, ['vodafone_cash', 'instapay', 'manual_transfer', 'card'], true)) {
    $paymentMethod = 'vodafone_cash';
}

try {
    $pdo->beginTransaction();

    $calculatedSubtotal = 0.0;
    $hasPrintedBook = false;
    $orderItemsData = [];

    foreach ($items as $item) {
        $type = (string)($item['type'] ?? '');
        $itemId = (int)($item['id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));

        if ($type === 'course') {
            $stmt = $pdo->prepare('SELECT id, title, price, discounted_price, status FROM courses WHERE id = ?');
            $stmt->execute([$itemId]);
            $course = $stmt->fetch();
            if (!$course || $course['status'] !== 'published') {
                $pdo->rollBack();
                student_json(400, ['success' => false, 'message' => "الكورس المختار (ID: $itemId) غير متاح للشراء حالياً."]);
            }
            $price = (float)($course['discounted_price'] ?? $course['price']);
            $lineTotal = $price * 1; // Course quantity is always 1 per student
            $calculatedSubtotal += $lineTotal;
            $orderItemsData[] = [
                'item_type' => 'course',
                'item_id' => $course['id'],
                'title_snapshot' => $course['title'],
                'price_snapshot' => $price,
                'quantity' => 1,
                'subtotal' => $lineTotal
            ];
        } elseif ($type === 'book') {
            $stmt = $pdo->prepare('SELECT id, title_ar, price, discount_price, type, stock, status FROM books WHERE id = ?');
            $stmt->execute([$itemId]);
            $book = $stmt->fetch();
            if (!$book || $book['status'] !== 'published') {
                $pdo->rollBack();
                student_json(400, ['success' => false, 'message' => "الكتاب المختار (ID: $itemId) غير متاح للشراء حالياً."]);
            }
            if ($book['type'] === 'printed' || $book['type'] === 'both') {
                $hasPrintedBook = true;
            }
            $price = (float)($book['discount_price'] ?? $book['price']);
            $lineTotal = $price * $qty;
            $calculatedSubtotal += $lineTotal;
            $orderItemsData[] = [
                'item_type' => 'book',
                'item_id' => $book['id'],
                'title_snapshot' => $book['title_ar'],
                'price_snapshot' => $price,
                'quantity' => $qty,
                'subtotal' => $lineTotal
            ];
        }
    }

    $shippingCost = $hasPrintedBook ? 50.00 : 0.00;
    $totalAmount = $calculatedSubtotal + $shippingCost;

    // Generate unique order number e.g. EDU-202611-XXXX
    $orderNumber = 'EDU-' . date('Ym') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    $insOrder = $pdo->prepare("
        INSERT INTO orders (
            order_number, student_id, subtotal, discount_amount, shipping_cost, 
            total_amount, currency, payment_method, status, shipping_name, 
            shipping_phone, shipping_address, shipping_city, admin_notes
        ) VALUES (?, ?, ?, 0.00, ?, ?, 'EGP', ?, 'pending_payment', ?, ?, ?, ?, ?)
    ");
    $insOrder->execute([
        $orderNumber,
        $currentStudent['id'],
        $calculatedSubtotal,
        $shippingCost,
        $totalAmount,
        $paymentMethod,
        $shippingName,
        $shippingPhone,
        $shippingAddress,
        $shippingCity,
        $notes
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare("
        INSERT INTO order_items (order_id, item_type, item_id, title_snapshot, price_snapshot, quantity, subtotal)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($orderItemsData as $oi) {
        $insItem->execute([
            $orderId,
            $oi['item_type'],
            $oi['item_id'],
            $oi['title_snapshot'],
            $oi['price_snapshot'],
            $oi['quantity'],
            $oi['subtotal']
        ]);
    }

    $pdo->commit();

    student_json(201, [
        'success' => true,
        'message' => 'تم إنشاء الطلب بنجاح. يرجى إتمام التحويل ورفع إثبات الدفع.',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'subtotal' => $calculatedSubtotal,
        'shipping_cost' => $shippingCost,
        'total_amount' => $totalAmount,
        'currency' => 'EGP',
        'payment_method' => $paymentMethod,
        'payment_number' => '01061709999'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Order creation error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'حدث خطأ أثناء إنشاء الطلب.']);
}
