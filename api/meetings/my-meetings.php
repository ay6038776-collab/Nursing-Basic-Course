<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

try {
    // Meetings for enrolled courses or general academy sessions
    $stmt = $pdo->prepare("
        SELECT m.id, m.title, m.description, m.meeting_url,
               m.starts_at, m.ends_at, m.starts_at AS scheduled_at,
               m.status,
               c.id AS course_id, c.title AS course_title,
               u.full_name AS instructor_name, u.avatar AS instructor_avatar
        FROM live_sessions m
        LEFT JOIN courses c ON c.id = m.course_id
        LEFT JOIN users u ON u.id = m.created_by
        WHERE m.course_id IS NULL 
           OR m.course_id IN (SELECT course_id FROM enrollments WHERE student_id = ? AND status = 'active')
        ORDER BY m.starts_at ASC
    ");
    $stmt->execute([$currentStudent['id']]);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    student_json(200, [
        'success' => true,
        'meetings' => $meetings
    ]);
} catch (Throwable $e) {
    error_log('[My meetings error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر جلب جدول الاجتماعات.']);
}
