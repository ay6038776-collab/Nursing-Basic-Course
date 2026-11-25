<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings_helper.php';

$settings = get_platform_settings($pdo);

echo json_encode([
    'success' => true,
    'settings' => $settings
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
