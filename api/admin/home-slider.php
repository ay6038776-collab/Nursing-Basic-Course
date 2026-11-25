<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

function hs_reply(int $status, array $payload): never
{
    admin_json($status, $payload);
}

function hs_upload_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads/home-slider';
}

function hs_delete_file(?string $path): void
{
    if (!$path || !preg_match('#^uploads/home-slider/[a-f0-9]{40}\.(jpg|jpeg|png|webp)$#i', $path)) return;
    $file = hs_upload_dir() . '/' . basename($path);
    if (is_file($file)) @unlink($file);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($method === 'GET' && $action === 'list') {
    $slides = $pdo->query('SELECT id,title_ar,title_en,subtitle_ar,subtitle_en,image_path,sort_order,is_active FROM home_slider_slides ORDER BY sort_order ASC,id ASC')->fetchAll(PDO::FETCH_ASSOC);
    hs_reply(200, ['success' => true, 'slides' => $slides]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    hs_reply(405, ['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
}

if ($action === 'delete') {
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT image_path FROM home_slider_slides WHERE id=?');
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    if (!$path) hs_reply(404, ['success' => false, 'message' => 'الصورة غير موجودة.']);
    $pdo->prepare('DELETE FROM home_slider_slides WHERE id=?')->execute([$id]);
    hs_delete_file((string)$path);
    hs_reply(200, ['success' => true, 'message' => 'تم حذف صورة السلايدر.']);
}

if ($action !== 'save') hs_reply(400, ['success' => false, 'message' => 'الإجراء غير مدعوم.']);

$id = (int)($_POST['id'] ?? 0);
$titleAr = trim((string)($_POST['title_ar'] ?? ''));
$titleEn = trim((string)($_POST['title_en'] ?? ''));
$subtitleAr = trim((string)($_POST['subtitle_ar'] ?? ''));
$subtitleEn = trim((string)($_POST['subtitle_en'] ?? ''));
$sortOrder = max(0, min(10000, (int)($_POST['sort_order'] ?? 0)));
$isActive = !empty($_POST['is_active']) ? 1 : 0;

if (mb_strlen($titleAr) > 190 || mb_strlen($titleEn) > 190 || mb_strlen($subtitleAr) > 500 || mb_strlen($subtitleEn) > 500) {
    hs_reply(422, ['success' => false, 'message' => 'راجع طول العنوان أو الوصف.']);
}

$existingPath = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT image_path FROM home_slider_slides WHERE id=?');
    $stmt->execute([$id]);
    $existingPath = $stmt->fetchColumn();
    if (!$existingPath) hs_reply(404, ['success' => false, 'message' => 'الصورة غير موجودة.']);
}

$newPath = null;
$newAbsolute = null;
$file = $_FILES['image_file'] ?? null;
if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        hs_reply(422, ['success' => false, 'message' => 'تعذر استلام الصورة من الجهاز.']);
    }
    if ((int)$file['size'] < 1 || (int)$file['size'] > 8 * 1024 * 1024) hs_reply(422, ['success' => false, 'message' => 'حجم الصورة يجب ألا يتجاوز 8 ميجابايت.']);
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']));
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = @getimagesize((string)$file['tmp_name']);
    if (!isset($extensions[$mime]) || !$imageInfo || $imageInfo[0] < 640 || $imageInfo[1] < 360) {
        hs_reply(422, ['success' => false, 'message' => 'ارفع صورة JPG أو PNG أو WEBP بأبعاد لا تقل عن 640×360.']);
    }
    $dir = hs_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) hs_reply(500, ['success' => false, 'message' => 'تعذر تجهيز مجلد صور السلايدر.']);
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    $name = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $newAbsolute = $dir . '/' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $newAbsolute)) hs_reply(500, ['success' => false, 'message' => 'تعذر حفظ الصورة.']);
    $newPath = 'uploads/home-slider/' . $name;
} elseif ($id === 0) {
    hs_reply(422, ['success' => false, 'message' => 'اختر صورة من جهازك لإضافتها إلى السلايدر.']);
}

try {
    if ($id > 0) {
        $pdo->prepare('UPDATE home_slider_slides SET title_ar=?,title_en=?,subtitle_ar=?,subtitle_en=?,image_path=?,sort_order=?,is_active=? WHERE id=?')
            ->execute([$titleAr, $titleEn, $subtitleAr !== '' ? $subtitleAr : null, $subtitleEn !== '' ? $subtitleEn : null, $newPath ?? $existingPath, $sortOrder, $isActive, $id]);
    } else {
        $pdo->prepare('INSERT INTO home_slider_slides (title_ar,title_en,subtitle_ar,subtitle_en,image_path,sort_order,is_active,created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$titleAr, $titleEn, $subtitleAr !== '' ? $subtitleAr : null, $subtitleEn !== '' ? $subtitleEn : null, $newPath, $sortOrder, $isActive, (int)($currentUser['id'] ?? 0) ?: null]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    if ($newAbsolute && is_file($newAbsolute)) @unlink($newAbsolute);
    error_log('[Home slider save] ' . $e->getMessage());
    hs_reply(500, ['success' => false, 'message' => 'تعذر حفظ بيانات الصورة.']);
}

if ($newPath && $existingPath) hs_delete_file((string)$existingPath);
hs_reply(200, ['success' => true, 'id' => $id, 'message' => 'تم حفظ صورة السلايدر.']);
