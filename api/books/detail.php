<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

require_once __DIR__ . '/../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'معرّف الكتاب غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.item_type = 'book' AND r.item_id = b.id AND r.status = 'approved') AS rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.item_type = 'book' AND r.item_id = b.id AND r.status = 'approved') AS reviews_count
        FROM books b
        WHERE b.id = ? AND b.status = 'published'
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'الكتاب غير موجود.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Do not reveal raw full PDF path to public unauthenticated requests
    unset($book['pdf_path']);

    $revStmt = $pdo->prepare("
        SELECT r.id, r.rating, r.comment, r.created_at, u.full_name AS student_name, u.avatar AS student_avatar
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        WHERE r.item_type = 'book' AND r.item_id = ? AND r.status = 'approved'
        ORDER BY r.id DESC LIMIT 10
    ");
    $revStmt->execute([$id]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'book' => $book,
        'reviews' => $reviews
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر جلب تفاصيل الكتاب.'], JSON_UNESCAPED_UNICODE);
}
