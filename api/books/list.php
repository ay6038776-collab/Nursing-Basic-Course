<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

require_once __DIR__ . '/../config/database.php';

try {
    $search = trim((string)($_GET['search'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));

    $sql = "SELECT b.id, b.title_ar, b.title_en, b.author, b.description_ar, b.description_en,
                   b.cover_image, b.type, b.price, b.discount_price, b.stock, b.pages_count, b.status,
                   (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.item_type = 'book' AND r.item_id = b.id AND r.status = 'approved') AS rating,
                   (SELECT COUNT(*) FROM reviews r WHERE r.item_type = 'book' AND r.item_id = b.id AND r.status = 'approved') AS reviews_count
            FROM books b
            WHERE b.status = 'published'";

    $params = [];
    if ($search !== '') {
        $sql .= " AND (b.title_ar LIKE ? OR b.title_en LIKE ? OR b.description_ar LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($type !== '' && in_array($type, ['printed', 'digital', 'both'], true)) {
        $sql .= " AND (b.type = ? OR b.type = 'both')";
        $params[] = $type;
    }

    $sql .= " ORDER BY b.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => count($books),
        'books' => $books
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر جلب قائمة الكتب.'], JSON_UNESCAPED_UNICODE);
}
