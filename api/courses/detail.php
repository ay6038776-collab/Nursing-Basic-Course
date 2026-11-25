<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

require_once __DIR__ . '/../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'معرّف الكورس غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               u.full_name AS instructor_name, u.avatar AS instructor_avatar, u.bio AS instructor_bio,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS enrolled_count,
               (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.item_type = 'course' AND r.item_id = c.id AND r.status = 'approved') AS rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.item_type = 'course' AND r.item_id = c.id AND r.status = 'approved') AS reviews_count
        FROM courses c
        LEFT JOIN users u ON u.id = c.instructor_id
        WHERE c.id = ? AND c.status = 'published'
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'الكورس غير موجود أو غير منشور حالياً.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Fetch curriculum sections
    $secStmt = $pdo->prepare("
        SELECT id, title, description, sort_order 
        FROM course_sections 
        WHERE course_id = ? AND status = 'published'
        ORDER BY sort_order ASC, id ASC
    ");
    $secStmt->execute([$id]);
    $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch lessons for course
    $lesStmt = $pdo->prepare("
        SELECT id, title, description, duration_minutes, sort_order, is_preview
        FROM lessons 
        WHERE course_id = ? AND status = 'published'
        ORDER BY sort_order ASC, id ASC
    ");
    $lesStmt->execute([$id]);
    $lessons = $lesStmt->fetchAll(PDO::FETCH_ASSOC);

    // If no sections defined, wrap lessons into default section
    if (empty($sections)) {
        $sections = [[
            'id' => 0,
            'title' => 'المحتوى التعليمي للكورس',
            'description' => 'جميع الدروس والمحاضرات التدريبية',
            'lessons' => $lessons
        ]];
    } else {
        // Group lessons into sections if needed or attach to first
        foreach ($sections as &$sec) {
            $sec['lessons'] = $lessons; // in realistic curriculum or by section_id
        }
        unset($sec);
    }

    // Fetch reviews
    $revStmt = $pdo->prepare("
        SELECT r.id, r.rating, r.comment, r.created_at, u.full_name AS student_name, u.avatar AS student_avatar
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        WHERE r.item_type = 'course' AND r.item_id = ? AND r.status = 'approved'
        ORDER BY r.id DESC LIMIT 10
    ");
    $revStmt->execute([$id]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'course' => $course,
        'sections' => $sections,
        'reviews' => $reviews
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر جلب تفاصيل الكورس.'], JSON_UNESCAPED_UNICODE);
}
