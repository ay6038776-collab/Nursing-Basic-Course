<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

require_once __DIR__ . '/../config/database.php';

try {
    $search = trim((string)($_GET['search'] ?? ''));
    $level = trim((string)($_GET['level'] ?? ''));

    $sql = "SELECT c.id, c.title, c.title_ar, c.title_en, c.slug, c.description, c.price, c.discounted_price,
                   c.thumbnail, c.level, c.duration_minutes, c.status,
                   u.full_name AS instructor_name, u.avatar AS instructor_avatar,
                   (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.status = 'published') AS lessons_count,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS enrolled_count,
                   (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.item_type = 'course' AND r.item_id = c.id AND r.status = 'approved') AS rating,
                   (SELECT COUNT(*) FROM reviews r WHERE r.item_type = 'course' AND r.item_id = c.id AND r.status = 'approved') AS reviews_count
            FROM courses c
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE c.status = 'published'";

    $params = [];
    if ($search !== '') {
        $sql .= " AND (c.title LIKE ? OR c.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($level !== '' && in_array($level, ['beginner', 'intermediate', 'advanced'], true)) {
        $sql .= " AND c.level = ?";
        $params[] = $level;
    }

    $sql .= " ORDER BY c.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => count($courses),
        'courses' => $courses
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر جلب قائمة الكورسات.'
    ], JSON_UNESCAPED_UNICODE);
}
