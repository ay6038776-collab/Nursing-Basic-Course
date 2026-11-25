<?php
declare(strict_types=1);

/**
 * Unified Logout API
 * Destroys all portal sessions (student, instructor, admin)
 * and returns redirect_url to unified login page.
 */

require_once __DIR__ . '/../config/session.php';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '');
$csrfValid = false;
foreach (['NBC_STUDENT_SESSID', 'NBC_INSTRUCTOR_SESSID', 'NBC_ADMIN_SESSID'] as $portalSession) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name($portalSession);
    session_start();
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrf !== '' && $expected !== '' && hash_equals($expected, $csrf)) $csrfValid = true;
    session_write_close();
    if ($csrfValid) break;
}
if (!$csrfValid) {
    http_response_code(419);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode(['success'=>false,'message'=>'رمز الحماية غير صالح. حدّث الصفحة وحاول مرة أخرى.'], JSON_UNESCAPED_UNICODE));
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

$sessionPortals = [
    'NBC_STUDENT_SESSID',
    'NBC_INSTRUCTOR_SESSID',
    'NBC_ADMIN_SESSID',
    session_name()
];

foreach (array_unique($sessionPortals) as $sessName) {
    if (!$sessName) continue;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name($sessName);
    session_start();
    $_SESSION = [];
    session_destroy();
}

// Clean cookies for all portal sessions
foreach (array_unique($sessionPortals) as $sessName) {
    if (!empty($sessName)) {
        setcookie($sessName, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'success' => true,
    'message' => 'تم تسجيل الخروج بنجاح.',
    'redirect_url' => 'auth/login.php'
], JSON_UNESCAPED_UNICODE);
