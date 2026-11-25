<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    student_json(405, ['success' => false, 'message' => 'طريقة الطلب غير مسموح بها.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    student_json(400, ['success' => false, 'message' => 'بيانات التقييم غير صالحة.']);
}

$itemType = (string)($input['item_type'] ?? 'course');
$itemId = (int)($input['item_id'] ?? 0);
$rating = max(1, min(5, (int)($input['rating'] ?? 5)));
$comment = trim((string)($input['comment'] ?? ''));

if (!in_array($itemType, ['course', 'book'], true) || $itemId <= 0 || $comment === '') {
    student_json(400, ['success' => false, 'message' => 'يرجى إدخال تفاصيل التقييم وكتابة تعليقك.']);
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO reviews (user_id, item_type, item_id, rating, comment, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $currentStudent['id'],
        $itemType,
        $itemId,
        $rating,
        $comment
    ]);

    student_json(201, [
        'success' => true,
        'message' => 'شكراً لمشاركتك! تم إرسال تقييمك للمراجعة وسيظهر بعد اعتماد الإدارة.'
    ]);
} catch (Throwable $e) {
    error_log('[Submit review error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر إرسال التقييم.']);
}
