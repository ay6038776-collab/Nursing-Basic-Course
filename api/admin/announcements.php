<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');
// broadcast is an alias for create
if ($action === 'broadcast') $action = 'create';

if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT a.*, c.title AS course_title, u.full_name AS author_name
        FROM announcements a
        LEFT JOIN courses c ON c.id = a.course_id
        LEFT JOIN users u ON u.id = a.created_by
        ORDER BY a.id DESC
    ");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    admin_json(200, ['success' => true, 'announcements' => $announcements]);
}

if ($action === 'create') {
    $input = json_decode(file_get_contents('php://input'), true);
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $targetRole = (string)($input['target_role'] ?? 'all');
    $courseId = !empty($input['course_id']) ? (int)$input['course_id'] : null;

    if ($title === '' || $body === '') {
        admin_json(400, ['success' => false, 'message' => 'يرجى كتابة عنوان الإعلان ونص الرسالة.']);
    }

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO announcements (title, body, target_role, course_id, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'published', ?, NOW(), NOW())
        ");
        $ins->execute([$title, $body, $targetRole, $courseId, $currentUser['id']]);

        // Broadcast notifications to relevant users
        if ($courseId) {
            $uStmt = $pdo->prepare("SELECT student_id FROM enrollments WHERE course_id = ? AND status = 'active'");
            $uStmt->execute([$courseId]);
        } else {
            $uStmt = $pdo->query("SELECT id FROM users WHERE status = 'active' AND role = 'student'");
        }
        $userIds = $uStmt->fetchAll(PDO::FETCH_COLUMN);

        $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'announcement', NOW())");
        foreach ($userIds as $uid) {
            $notif->execute([$uid, $title, $body]);
        }

        $pdo->commit();

        admin_json(201, ['success' => true, 'message' => 'تم نشر الإعلان بنجاح وتوزيعه على الطلاب.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_json(500, ['success' => false, 'message' => 'تعذر نشر الإعلان.']);
    }
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الإعلان غير صالح.']);

    try {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        admin_json(200, ['success' => true, 'message' => 'تم حذف الإعلان بنجاح.']);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر حذف الإعلان.']);
    }
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير مدعوم.']);
