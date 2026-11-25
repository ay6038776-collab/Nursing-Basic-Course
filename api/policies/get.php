<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');

require_once __DIR__ . '/../config/database.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'الرمز التعريفي للسياسة غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT slug, title_ar, title_en, content_ar, content_en, updated_at FROM policy_pages WHERE slug = ?');
    $stmt->execute([$slug]);
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$policy) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'الصفحة غير موجودة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true, 'policy' => $policy], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر جلب محتوى السياسة.'], JSON_UNESCAPED_UNICODE);
}
