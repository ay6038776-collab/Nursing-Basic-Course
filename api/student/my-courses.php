<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.title_ar, c.title_en, c.thumbnail, c.level, c.duration_minutes,
               e.id AS enrollment_id, e.enrolled_at, e.status AS enrollment_status,
               u.full_name AS instructor_name,
               (SELECT COUNT(*) FROM course_lessons l WHERE l.course_id = c.id AND l.status = 'published') AS total_lessons,
               (SELECT COUNT(*) FROM lesson_progress lp 
                WHERE lp.enrollment_id = e.id AND (lp.status = 'completed' OR lp.progress_percent >= 85.00)) AS completed_lessons,
               (SELECT lp2.last_position_seconds FROM lesson_progress lp2 
                WHERE lp2.enrollment_id = e.id 
                ORDER BY lp2.updated_at DESC LIMIT 1) AS last_position_seconds
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        LEFT JOIN users u ON u.id = c.instructor_id
        WHERE e.student_id = ? AND e.status = 'active'
        ORDER BY e.enrolled_at DESC
    ");
    $stmt->execute([$currentStudent['id']]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    student_json(200, [
        'success' => true,
        'courses' => $courses
    ]);
} catch (Throwable $e) {
    error_log('[My courses error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر جلب قائمة الكورسات المشترك بها.']);
}
