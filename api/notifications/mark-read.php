<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

$id = (int)($_POST['id'] ?? 0);

try {
    if ($id > 0) {
        $up = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL");
        $up->execute([$id, $currentStudent['id']]);
    } else {
        $up = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
        $up->execute([$currentStudent['id']]);
    }
    student_json(200, ['success' => true]);
} catch (Throwable $e) {
    error_log('[Mark read error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر تحديث الإشعار.']);
}
