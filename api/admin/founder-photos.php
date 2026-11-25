<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

function fp_reply(int $status, array $payload): never { admin_json($status, $payload); }
function fp_dir(): string { return dirname(__DIR__, 2) . '/uploads/founder-photos'; }
function fp_delete_file(?string $path): void
{
    if (!$path || !preg_match('#^uploads/founder-photos/[a-f0-9]{40}\.(jpg|jpeg|png|webp)$#i', $path)) return;
    $file = fp_dir() . '/' . basename($path);
    if (is_file($file)) @unlink($file);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($method === 'GET' && $action === 'list') {
    $photos = $pdo->query('SELECT id,caption_ar,caption_en,image_path,sort_order,is_active FROM founder_photos ORDER BY sort_order ASC,id ASC')->fetchAll(PDO::FETCH_ASSOC);
    fp_reply(200, ['success' => true, 'photos' => $photos]);
}
if ($method !== 'POST') { header('Allow: GET, POST'); fp_reply(405, ['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']); }

if ($action === 'delete') {
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT image_path FROM founder_photos WHERE id=?');
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    if (!$path) fp_reply(404, ['success' => false, 'message' => 'الصورة غير موجودة.']);
    $pdo->prepare('DELETE FROM founder_photos WHERE id=?')->execute([$id]);
    fp_delete_file((string)$path);
    fp_reply(200, ['success' => true, 'message' => 'تم حذف صورة المؤسس.']);
}
if ($action !== 'save') fp_reply(400, ['success' => false, 'message' => 'الإجراء غير مدعوم.']);

$id = (int)($_POST['id'] ?? 0);
$captionAr = trim((string)($_POST['caption_ar'] ?? ''));
$captionEn = trim((string)($_POST['caption_en'] ?? ''));
$sortOrder = max(0, min(10000, (int)($_POST['sort_order'] ?? 0)));
$isActive = !empty($_POST['is_active']) ? 1 : 0;
if (mb_strlen($captionAr) > 190 || mb_strlen($captionEn) > 190) fp_reply(422, ['success' => false, 'message' => 'راجع طول وصف الصورة.']);

$existingPath = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT image_path FROM founder_photos WHERE id=?');
    $stmt->execute([$id]);
    $existingPath = $stmt->fetchColumn();
    if (!$existingPath) fp_reply(404, ['success' => false, 'message' => 'الصورة غير موجودة.']);
}

$newPath = null;
$newAbsolute = null;
$file = $_FILES['image_file'] ?? null;
if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) fp_reply(422, ['success' => false, 'message' => 'تعذر استلام الصورة من الجهاز.']);
    if ((int)$file['size'] < 1 || (int)$file['size'] > 8 * 1024 * 1024) fp_reply(422, ['success' => false, 'message' => 'حجم الصورة يجب ألا يتجاوز 8 ميجابايت.']);
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']));
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = @getimagesize((string)$file['tmp_name']);
    if (!isset($extensions[$mime]) || !$imageInfo || $imageInfo[0] < 320 || $imageInfo[1] < 320) fp_reply(422, ['success' => false, 'message' => 'ارفع صورة JPG أو PNG أو WEBP بأبعاد لا تقل عن 320×320.']);
    $dir = fp_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) fp_reply(500, ['success' => false, 'message' => 'تعذر تجهيز مجلد الصور.']);
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    $name = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $newAbsolute = $dir . '/' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $newAbsolute)) fp_reply(500, ['success' => false, 'message' => 'تعذر حفظ الصورة.']);
    $newPath = 'uploads/founder-photos/' . $name;
} elseif ($id === 0) {
    fp_reply(422, ['success' => false, 'message' => 'اختر صورة من جهازك لإضافتها.']);
}

try {
    if ($id > 0) {
        $pdo->prepare('UPDATE founder_photos SET caption_ar=?,caption_en=?,image_path=?,sort_order=?,is_active=? WHERE id=?')->execute([$captionAr, $captionEn, $newPath ?? $existingPath, $sortOrder, $isActive, $id]);
    } else {
        $pdo->prepare('INSERT INTO founder_photos (caption_ar,caption_en,image_path,sort_order,is_active,created_by) VALUES (?,?,?,?,?,?)')->execute([$captionAr, $captionEn, $newPath, $sortOrder, $isActive, (int)($currentUser['id'] ?? 0) ?: null]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    if ($newAbsolute && is_file($newAbsolute)) @unlink($newAbsolute);
    error_log('[Founder photos save] ' . $e->getMessage());
    fp_reply(500, ['success' => false, 'message' => 'تعذر حفظ بيانات الصورة.']);
}
if ($newPath && $existingPath) fp_delete_file((string)$existingPath);
fp_reply(200, ['success' => true, 'id' => $id, 'message' => 'تم حفظ صورة المؤسس.']);
