<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config/database.php';

try {
    $photos = $pdo->query('SELECT id,caption_ar,caption_en,image_path,sort_order FROM founder_photos WHERE is_active=1 ORDER BY sort_order ASC,id ASC')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'photos' => $photos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[Public founder photos] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'photos' => []], JSON_UNESCAPED_UNICODE);
}
