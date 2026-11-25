<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

try {
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, action_url,
               (CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS is_read,
               read_at, created_at 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY id DESC LIMIT 30
    ");
    $stmt->execute([$currentStudent['id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $unreadStmt->execute([$currentStudent['id']]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    student_json(200, [
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifications
    ]);
} catch (Throwable $e) {
    error_log('[Notifications list error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر جلب الإشعارات.']);
}
