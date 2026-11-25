<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/settings_helper.php';
require_once __DIR__ . '/../config/video_upload.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') admin_json(405, ['success'=>false, 'message'=>'الطريقة غير مسموح بها.']);
$file = $_FILES['file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] < 1 || (int)$file['size'] > 10 * 1024 * 1024 * 1024) {
    admin_json(422, ['success'=>false, 'message'=>'اختر فيديو MP4 صالحًا لا يتجاوز 10 جيجابايت.']);
}
if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'mp4' || !nbc_is_mp4_file((string)$file['tmp_name'])) admin_json(422, ['success'=>false, 'message'=>'الملف ليس فيديو MP4 صالحًا. تأكد من صيغة الفيديو ثم أعد اختياره.']);
$dir = __DIR__ . '/../../uploads/platform';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) admin_json(500, ['success'=>false, 'message'=>'تعذر تجهيز مجلد الرفع.']);
$name = 'intro-video-' . bin2hex(random_bytes(16)) . '.mp4';
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) admin_json(500, ['success'=>false, 'message'=>'تعذر حفظ الفيديو.']);
$path = 'uploads/platform/' . $name;
$stmt = $pdo->prepare("INSERT INTO platform_settings (setting_key,setting_value,is_sensitive,updated_by,updated_at) VALUES ('hero_video_url',?,0,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()");
$stmt->execute([$path, (int)$currentUser['id']]);
admin_json(200, ['success'=>true, 'message'=>'تم رفع الفيديو التعريفي من جهازك بنجاح.', 'path'=>$path]);
