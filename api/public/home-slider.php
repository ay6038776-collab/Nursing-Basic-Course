<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/database.php';

try {
    $slides = $pdo->query(
        'SELECT id,title_ar,title_en,subtitle_ar,subtitle_en,image_path,sort_order
         FROM home_slider_slides WHERE is_active=1 ORDER BY sort_order ASC,id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'slides' => $slides], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[Public home slider] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'slides' => []], JSON_UNESCAPED_UNICODE);
}
