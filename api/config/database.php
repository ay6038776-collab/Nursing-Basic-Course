<?php
declare(strict_types=1);

// Keep PHP warnings, paths, and stack details out of visitor responses even on a misconfigured local host.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!headers_sent()) header_remove('X-Powered-By');

$host = '127.0.0.1';
$database = 'nursing_basic_course';
$username = 'root';
$password = '';
$charset = 'utf8mb4';
$dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success'=>false,'message'=>'قاعدة البيانات غير متاحة. تأكد من تشغيل MySQL في XAMPP.'], JSON_UNESCAPED_UNICODE));
    }
    exit('Database connection failed.');
}
