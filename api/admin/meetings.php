<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT m.*, m.starts_at AS scheduled_at, c.title AS course_title, u.full_name AS instructor_name
        FROM live_sessions m
        LEFT JOIN courses c ON c.id = m.course_id
        LEFT JOIN users u ON u.id = m.created_by
        ORDER BY m.starts_at DESC
    ");
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    admin_json(200, ['success' => true, 'meetings' => $meetings]);
}

if ($action === 'create') {
    $input = json_decode(file_get_contents('php://input'), true);
    $courseId = (int)($input['course_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $desc = trim((string)($input['description'] ?? ''));
    $url = trim((string)($input['meeting_url'] ?? ''));
    $startsAt = trim((string)($input['starts_at'] ?? ($input['scheduled_at'] ?? '')));
    $duration = (int)($input['duration_minutes'] ?? 60);

    if ($title === '' || $url === '' || $startsAt === '') {
        admin_json(400, ['success' => false, 'message' => 'يرجى إدخال عنوان الاجتماع ورابط البث المباشر وموعد البدء.']);
    }

    // If no course selected, pick the first published course or require a course
    if ($courseId <= 0) {
        $cStmt = $pdo->query("SELECT id FROM courses LIMIT 1");
        $courseId = (int)($cStmt->fetchColumn() ?: 101);
    }

    try {
        $pdo->beginTransaction();

        $endsAt = date('Y-m-d H:i:s', strtotime($startsAt . ' +' . $duration . ' minutes'));

        $stmt = $pdo->prepare("
            INSERT INTO live_sessions (
                course_id, title, description, starts_at, ends_at, meeting_url, status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
        ");
        $stmt->execute([
            $courseId,
            $title,
            $desc,
            $startsAt,
            $endsAt,
            $url,
            $currentUser['id']
        ]);
        $newMeetingId = (int)$pdo->lastInsertId();

        // Notify enrolled students of the course
        $enr = $pdo->prepare("SELECT student_id FROM enrollments WHERE course_id = ? AND status = 'active'");
        $enr->execute([$courseId]);
        $students = $enr->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($students)) {
            $notif = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, action_url, created_at)
                VALUES (?, 'موعد محاضرة مباشرة Zoom جديدة', ?, 'meeting', ?, NOW())
            ");
            $msg = "تم تحديد موعد محاضرة زوم: {$title} في تاريخ {$startsAt}. يرجى التواجد بالموعد.";
            foreach ($students as $sId) {
                $notif->execute([$sId, $msg, $url]);
            }
        }

        $pdo->commit();

        nbc_log_audit((int)$currentUser['id'], 'create_meeting', 'live_session', (string)$newMeetingId, [
            'title' => $title,
            'course_id' => $courseId
        ]);

        admin_json(201, [
            'success' => true,
            'message' => 'تم إنشاء الاجتماع بنجاح وإشعار الطلاب المسجلين.',
            'meeting_id' => $newMeetingId
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Create meeting error] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'تعذر إنشاء الاجتماع.']);
    }
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الاجتماع غير صالح.']);

    try {
        $pdo->prepare('DELETE FROM live_sessions WHERE id = ?')->execute([$id]);
        nbc_log_audit((int)$currentUser['id'], 'delete_meeting', 'live_session', (string)$id);
        admin_json(200, ['success' => true, 'message' => 'تم حذف الاجتماع بنجاح.']);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر حذف الاجتماع.']);
    }
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير مدعوم.']);
