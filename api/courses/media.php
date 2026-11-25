<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function media_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($message);
}

$userId = 0;
$portal = '';
foreach (['admin' => 'NBC_ADMIN_SESSID', 'student' => 'NBC_STUDENT_SESSID', 'instructor' => 'NBC_INSTRUCTOR_SESSID'] as $name => $cookieName) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name($cookieName);
    session_start();
    $candidate = (int)($_SESSION['user_id'] ?? 0);
    session_write_close();
    if ($candidate > 0) { $userId = $candidate; $portal = $name; break; }
}

$roles = [];
$courseOwnedByInstructor = false;
if ($userId > 0) {
    $stmt = $pdo->prepare('SELECT role,status FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$identity || $identity['status'] !== 'active') media_fail(401, 'سجّل الدخول مرة أخرى.');
    $roles[] = (string)$identity['role'];
    try {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id=?');
        $stmt->execute([$userId]);
        $roles = array_values(array_unique(array_merge($roles, $stmt->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable) {}
}

$isAdmin = in_array('admin', $roles, true);
$isStudent = in_array('student', $roles, true);
$isInstructor = in_array('instructor', $roles, true);
$courseId = (int)($_GET['course_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
$lessonSource = (string)($_GET['lesson_source'] ?? 'course_lessons');
$fileId = (int)($_GET['file_id'] ?? 0);
$mediaPath = '';
$originalName = '';
$isPreview = false;

if ($fileId > 0) {
    if ($courseId < 1 || $lessonId < 1) media_fail(400, 'طلب الملف غير صالح.');
    $stmt = $pdo->prepare("SELECT f.file_path,f.original_name,l.is_preview,l.status,c.instructor_id,c.status AS course_status
        FROM course_lesson_files f
        JOIN course_lessons l ON l.id=f.lesson_id AND l.course_id=f.course_id
        JOIN courses c ON c.id=f.course_id
        WHERE f.id=? AND f.course_id=? AND f.lesson_id=? LIMIT 1");
    $stmt->execute([$fileId, $courseId, $lessonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) media_fail(404, 'الملف غير متاح.');
    $mediaPath = (string)$row['file_path'];
    $originalName = basename((string)$row['original_name']);
    $isPreview = (bool)$row['is_preview'];
    $courseOwnedByInstructor = $isInstructor && (int)$row['instructor_id'] === $userId;
    if (($row['status'] !== 'published' || $row['course_status'] !== 'published') && !$isAdmin && !$courseOwnedByInstructor) media_fail(404, 'الملف غير متاح.');
} else {
    if ($courseId < 1 || $lessonId < 1 || !in_array($lessonSource, ['course_lessons','lessons'], true)) media_fail(400, 'طلب الفيديو غير صالح.');
    if ($lessonSource === 'lessons') {
        $stmt = $pdo->prepare("SELECT l.video_url,l.is_preview,l.status,c.status AS course_status,c.instructor_id
            FROM lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=? AND l.course_id=? LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT l.video_url,l.is_preview,l.status,c.status AS course_status,c.instructor_id
            FROM course_lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=? AND l.course_id=? LIMIT 1");
    }
    $stmt->execute([$lessonId, $courseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'published') media_fail(404, 'الفيديو غير متاح.');
    $mediaPath = (string)$row['video_url'];
    $isPreview = (bool)$row['is_preview'];
    $courseOwnedByInstructor = $isInstructor && (int)$row['instructor_id'] === $userId;
    if ($row['course_status'] !== 'published' && !$isAdmin && !$courseOwnedByInstructor) media_fail(404, 'الفيديو غير متاح.');
}

$enrolled = false;
if ($userId > 0 && $isStudent) {
    $enrollmentColumns = $pdo->query('SHOW COLUMNS FROM enrollments')->fetchAll(PDO::FETCH_COLUMN);
    $accessFilter = in_array('access_status', $enrollmentColumns, true) ? "AND access_status IN ('granted','allowed')" : '';
    $startsFilter = in_array('starts_at', $enrollmentColumns, true) ? 'AND (starts_at IS NULL OR starts_at <= NOW())' : '';
    $expiresFilter = in_array('expires_at', $enrollmentColumns, true) ? 'AND (expires_at IS NULL OR expires_at >= NOW())' : '';
    $stmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND status IN ('active','completed') $accessFilter $startsFilter $expiresFilter LIMIT 1");
    $stmt->execute([$userId, $courseId]);
    $enrolled = (bool)$stmt->fetchColumn();
}
if (!$isAdmin && !$courseOwnedByInstructor && !$enrolled && !$isPreview) media_fail(403, 'هذا المحتوى مخصص للمشتركين.');

$validManagedPath = preg_match('#^uploads/course-content/(?:videos/(?:[a-f0-9]{40}|course[0-9]+_[a-f0-9]{24})\.(?:mp4|webm|mov)|files/[a-f0-9]{40}\.[a-z0-9]{1,8})$#i', $mediaPath) === 1;
if (!$validManagedPath) media_fail(404, 'الملف غير متاح.');
$root = realpath(__DIR__ . '/../../uploads/course-content');
$absolutePath = realpath(__DIR__ . '/../../' . $mediaPath);
if (!$root || !$absolutePath || !str_starts_with(strtolower($absolutePath), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($absolutePath)) media_fail(404, 'الملف غير موجود.');

$extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
$mime = ['mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','txt'=>'text/plain'][$extension] ?? 'application/octet-stream';
$size = filesize($absolutePath);
$start = 0;
$end = $size - 1;
$status = 200;
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $match)) {
    if ($match[1] === '' && $match[2] === '') media_fail(416, 'نطاق الملف غير صالح.');
    if ($match[1] === '') { $suffix = (int)$match[2]; $start = max(0, $size - $suffix); }
    else { $start = (int)$match[1]; if ($match[2] !== '') $end = min($end, (int)$match[2]); }
    if ($start > $end || $start >= $size) { header("Content-Range: bytes */$size"); media_fail(416, 'نطاق الملف غير صالح.'); }
    $status = 206;
}

http_response_code($status);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) header("Content-Range: bytes $start-$end/$size");
if ($fileId > 0) {
    $safeName = rawurlencode($originalName ?: ('lesson-file.' . $extension));
    header('Content-Disposition: inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . $safeName);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
$handle = fopen($absolutePath, 'rb');
if (!$handle || fseek($handle, $start) !== 0) media_fail(500, 'تعذر قراءة الملف.');
while (!feof($handle) && $start <= $end) {
    $chunk = fread($handle, min(1024 * 1024, $end - $start + 1));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $start += strlen($chunk);
    flush();
}
fclose($handle);
