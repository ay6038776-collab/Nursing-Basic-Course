<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

// Public/student endpoint with optional auth
nbc_start_session('student');

header('Content-Type: application/json; charset=utf-8');

function track_done(string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(['success' => $status < 300, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    track_done('طريقة الطلب غير مسموح بها.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$courseId = (int)($input['course_id'] ?? 0);
$lessonId = (int)($input['lesson_id'] ?? 0);
$lessonSource = (string)($input['lesson_source'] ?? 'lessons');
if (!in_array($lessonSource, ['lessons', 'course_lessons'], true)) $lessonSource = 'lessons';
$posSec = max(0, (int)($input['position_seconds'] ?? 0));
$isCompleted = !empty($input['is_completed']);
$type = (string)($input['type'] ?? 'visit');

$userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

try {
    // 1. Lesson Progress Tracking
    if ($lessonId > 0 && $userId) {
        // Find course_id for this lesson
        $lessonTable = $lessonSource === 'course_lessons' ? 'course_lessons' : 'lessons';
        $lStmt = $pdo->prepare("SELECT course_id FROM {$lessonTable} WHERE id = ? LIMIT 1");
        $lStmt->execute([$lessonId]);
        $cId = (int)($lStmt->fetchColumn() ?: 0);

        if ($cId > 0) {
            // Find enrollment
            $enrStmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ? AND status = 'active' LIMIT 1");
            $enrStmt->execute([$userId, $cId]);
            $enrollmentId = $enrStmt->fetchColumn();

            if ($enrollmentId) {
                $statusVal = $isCompleted ? 'completed' : 'in_progress';
                $pct = $isCompleted ? 100.00 : 50.00;

                // Check if progress row exists
                $progStmt = $pdo->prepare('SELECT id FROM lesson_progress WHERE enrollment_id = ? AND lesson_id = ? LIMIT 1');
                $progStmt->execute([$enrollmentId, $lessonId]);
                $progId = $progStmt->fetchColumn();

                if ($progId) {
                    $upProg = $pdo->prepare("
                        UPDATE lesson_progress 
                        SET last_position_seconds = ?,
                            status = CASE WHEN status = 'completed' THEN 'completed' ELSE ? END,
                            progress_percent = GREATEST(progress_percent, ?),
                            completed_at = CASE WHEN ? = 'completed' AND completed_at IS NULL THEN NOW() ELSE completed_at END,
                            last_accessed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $upProg->execute([$posSec, $statusVal, $pct, $statusVal, $progId]);
                } else {
                    $insProg = $pdo->prepare("
                        INSERT INTO lesson_progress 
                        (student_id, course_id, enrollment_id, lesson_id, status, progress_percent, started_at, completed_at, last_accessed_at, last_position_seconds, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END, NOW(), ?, NOW())
                    ");
                    $insProg->execute([$userId, $cId, $enrollmentId, $lessonId, $statusVal, $pct, $statusVal, $posSec]);
                }
                track_done('تم تسجيل تقدم الدرس بنجاح.', 200);
            }
        }
    }

    // 2. Course Visit / Free Enroll Tracking
    if ($courseId > 0) {
        $cCheck = $pdo->prepare('SELECT id, price FROM courses WHERE id = ? LIMIT 1');
        $cCheck->execute([$courseId]);
        $crs = $cCheck->fetch();
        if (!$crs) {
            track_done('الكورس غير موجود.', 404);
        }

        if ($type === 'enroll') {
            if (!$userId) {
                track_done('سجّل الدخول كطالب أولاً.', 401);
            }
            $pdo->prepare("INSERT IGNORE INTO enrollments (course_id, student_id, status, enrolled_at) VALUES (?, ?, 'active', NOW())")
                ->execute([$courseId, $userId]);
            track_done('تم الاشتراك في الكورس بنجاح.', 200);
        }

        // Record course visit
        $key = $_COOKIE['nbc_vk'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            setcookie('nbc_vk', $key, time() + 31536000, '/', '', false, true);
        }
        $pdo->prepare('
            INSERT INTO course_visits (course_id, user_id, visitor_key, visited_on) 
            VALUES (?, ?, ?, CURDATE()) 
            ON DUPLICATE KEY UPDATE user_id = COALESCE(user_id, VALUES(user_id))
        ')->execute([$courseId, $userId, $key]);

        track_done('تم تسجيل الزيارة بنجاح.', 200);
    }

    track_done('طلب غير صالح.', 400);

} catch (Throwable $e) {
    error_log('[Track error] ' . $e->getMessage());
    track_done('حدث خطأ أثناء معالجة الطلب.', 500);
}
