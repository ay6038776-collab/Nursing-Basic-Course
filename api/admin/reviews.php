<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

if ($action === 'list') {
    $status = trim((string)($_GET['status'] ?? ''));
    $sql = "
        SELECT r.*, u.full_name AS student_name, u.avatar AS student_avatar,
               CASE 
                 WHEN r.item_type = 'course' THEN (SELECT title FROM courses WHERE id = r.item_id)
                 WHEN r.item_type = 'book' THEN (SELECT title_ar FROM books WHERE id = r.item_id)
               END AS item_title
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        WHERE 1=1
    ";
    $params = [];
    if ($status !== '') {
        $sql .= " AND r.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    admin_json(200, ['success' => true, 'reviews' => $reviews]);
}

if ($action === 'update_status') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') admin_json(405, ['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $status = (string)($input['status'] ?? 'approved');

    if ($id <= 0 || !in_array($status, ['approved', 'rejected', 'pending'], true)) {
        admin_json(400, ['success' => false, 'message' => 'بيانات التحديث غير صالحة.']);
    }

    try {
        $up = $pdo->prepare("UPDATE reviews SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?");
        $reviewedAt = $status === 'pending' ? null : date('Y-m-d H:i:s');
        $reviewedBy = $status === 'pending' ? null : $currentUser['id'];
        $up->execute([$status, $reviewedBy, $reviewedAt, $id]);

        admin_json(200, ['success' => true, 'message' => 'تم تحديث حالة التقييم بنجاح.']);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر تحديث التقييم.']);
    }
}

if ($action === 'delete') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') admin_json(405, ['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) admin_json(400, ['success' => false, 'message' => 'معرف التقييم غير صالح.']);

    try {
        $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
        admin_json(200, ['success' => true, 'message' => 'تم حذف التقييم بنجاح.']);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر حذف التقييم.']);
    }
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير مدعوم.']);
