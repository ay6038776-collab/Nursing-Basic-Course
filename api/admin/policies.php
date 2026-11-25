<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT slug, title_ar, title_en, content_ar, content_en, updated_at FROM policy_pages ORDER BY id ASC');
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    admin_json(200, ['success' => true, 'policies' => $pages]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $slug = trim((string)($input['slug'] ?? ''));
    $titleAr = trim((string)($input['title_ar'] ?? ''));
    $titleEn = trim((string)($input['title_en'] ?? ''));
    $contentAr = trim((string)($input['content_ar'] ?? ''));
    $contentEn = trim((string)($input['content_en'] ?? ''));

    if ($slug === '' || $titleAr === '' || $contentAr === '') {
        admin_json(400, ['success' => false, 'message' => 'بيانات الصفحة غير مكتملة.']);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO policy_pages (slug, title_ar, title_en, content_ar, content_en, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE title_ar = VALUES(title_ar), title_en = VALUES(title_en), content_ar = VALUES(content_ar), content_en = VALUES(content_en), updated_at = NOW()
        ");
        $stmt->execute([$slug, $titleAr, $titleEn, $contentAr, $contentEn]);

        admin_json(200, ['success' => true, 'message' => 'تم حفظ محتوى السياسة بنجاح.']);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر حفظ السياسة.']);
    }
}
