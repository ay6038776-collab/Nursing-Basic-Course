<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

/* ─── LIST ─── */
if ($action === 'list') {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;
    $status  = trim((string)($_GET['status'] ?? ''));
    $search  = trim((string)($_GET['search'] ?? ''));
    $courseId = (int)($_GET['course_id'] ?? 0);

    $where = ['1=1']; $p = [];
    if ($status !== '') { $where[] = 'e.status = ?'; $p[] = $status; }
    if ($courseId > 0) { $where[] = 'e.course_id = ?'; $p[] = $courseId; }
    if ($search !== '') {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR c.title_ar LIKE ?)';
        $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%";
    }
    $w = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id=e.student_id JOIN courses c ON c.id=e.course_id WHERE $w");
    $countStmt->execute($p);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT e.*, u.full_name AS student_name, u.email AS student_email,
               c.title_ar AS course_title, c.title_en AS course_title_en,
               creator.full_name AS created_by_name
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        JOIN courses c ON c.id = e.course_id
        LEFT JOIN users creator ON creator.id = e.created_by
        WHERE $w ORDER BY e.id DESC LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    admin_json(200, ['success' => true, 'enrollments' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $limit)]]);
}

/* ─── CREATE (manual admin enrollment) ─── */
if ($action === 'create') {
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $studentId = (int)($input['student_id'] ?? 0);
    $courseId  = (int)($input['course_id']  ?? 0);
    $startsAt  = trim((string)($input['starts_at'] ?? ''));
    $expiresAt = trim((string)($input['expires_at'] ?? ''));
    $note      = trim((string)($input['note'] ?? ''));

    if ($studentId <= 0 || $courseId <= 0) {
        admin_json(400, ['success' => false, 'message' => 'معرف الطالب والكورس مطلوبان.']);
    }

    // Check student exists
    $stu = $pdo->prepare('SELECT id, full_name FROM users WHERE id=? AND role=?');
    $stu->execute([$studentId, 'student']);
    if (!$stu->fetch()) admin_json(404, ['success' => false, 'message' => 'الطالب غير موجود.']);

    // Check course seats
    $crs = $pdo->prepare('SELECT id, title_ar, max_students FROM courses WHERE id=?');
    $crs->execute([$courseId]);
    $course = $crs->fetch();
    if (!$course) admin_json(404, ['success' => false, 'message' => 'الكورس غير موجود.']);

    if (!empty($course['max_students'])) {
        $used = (int)$pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=? AND status IN (?,?)')
            ->execute([$courseId,'active','pending']) ?: 0;
        // re-execute properly
        $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=? AND status IN (?,?)');
        $usedStmt->execute([$courseId,'active','pending']);
        $used = (int)$usedStmt->fetchColumn();
        if ($used >= (int)$course['max_students']) {
            admin_json(409, ['success' => false, 'message' => 'لا توجد مقاعد متاحة في هذا الكورس.']);
        }
    }

    try {
        $pdo->beginTransaction();

        // Check duplicate
        $dup = $pdo->prepare('SELECT id, status FROM enrollments WHERE student_id=? AND course_id=?');
        $dup->execute([$studentId, $courseId]);
        $existing = $dup->fetch();

        if ($existing && in_array($existing['status'], ['active', 'pending'], true)) {
            $pdo->rollBack();
            admin_json(409, ['success' => false, 'message' => 'الطالب مسجل بالفعل في هذا الكورس.']);
        }

        $nowDate  = date('Y-m-d H:i:s');
        $starts   = $startsAt ?: date('Y-m-d');
        $expires  = $expiresAt ?: null;

        if ($existing) {
            // Reactivate
            $pdo->prepare("UPDATE enrollments SET status='active', access_status='granted',
                starts_at=?, expires_at=?, updated_at=NOW(), created_by=? WHERE id=?")
                ->execute([$starts, $expires, $currentUser['id'], $existing['id']]);
            $enrollId = $existing['id'];
        } else {
            $ins = $pdo->prepare("INSERT INTO enrollments
                (student_id, course_id, status, access_status, enrolled_at, starts_at, expires_at, created_by, created_at, updated_at)
                VALUES (?,?,'active','granted',NOW(),?,?,?,NOW(),NOW())");
            $ins->execute([$studentId, $courseId, $starts, $expires, $currentUser['id']]);
            $enrollId = (int)$pdo->lastInsertId();
        }

        // Log event
        logEnrollmentEvent($pdo, $enrollId, 'active', 'pending', $currentUser['id'], 'تسجيل يدوي من المدير' . ($note ? ": $note" : ''));

        // Notify student
        $pdo->prepare("INSERT INTO notifications (user_id,title,message,type,created_at) VALUES (?,?,?,'enrollment',NOW())")
            ->execute([$studentId, 'تم تسجيلك في كورس جديد', "تم تسجيلك في كورس «{$course['title_ar']}» بنجاح. يمكنك الوصول للمحتوى الآن."]);

        $pdo->commit();
        admin_json(200, ['success' => true, 'message' => 'تم التسجيل اليدوي بنجاح.', 'enrollment_id' => $enrollId]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Enrollment create] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'تعذر إنشاء التسجيل.']);
    }
}

/* ─── UPDATE STATUS ─── */
if ($action === 'update_status') {
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $enrollId  = (int)($input['enrollment_id'] ?? 0);
    $newStatus = trim((string)($input['status'] ?? ''));
    $newAccess = trim((string)($input['access_status'] ?? ''));
    $reason    = trim((string)($input['reason'] ?? ''));

    $validStatuses = ['active', 'pending', 'completed', 'cancelled', 'suspended'];
    $validAccess   = ['granted', 'blocked', 'restricted'];

    if ($enrollId <= 0 || !in_array($newStatus, $validStatuses, true)) {
        admin_json(400, ['success' => false, 'message' => 'بيانات غير صالحة.']);
    }

    $enr = $pdo->prepare('SELECT * FROM enrollments WHERE id=?');
    $enr->execute([$enrollId]);
    $enrollment = $enr->fetch();
    if (!$enrollment) admin_json(404, ['success' => false, 'message' => 'التسجيل غير موجود.']);

    $oldStatus = $enrollment['status'];
    $accessVal = in_array($newAccess, $validAccess, true) ? $newAccess : ($newStatus === 'active' ? 'granted' : 'blocked');

    $pdo->prepare("UPDATE enrollments SET status=?, access_status=?, updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $accessVal, $enrollId]);

    logEnrollmentEvent($pdo, $enrollId, $newStatus, $oldStatus, $currentUser['id'], $reason);

    admin_json(200, ['success' => true, 'message' => 'تم تحديث حالة التسجيل.']);
}

/* ─── EXTEND ─── */
if ($action === 'extend') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enrollId = (int)($input['enrollment_id'] ?? 0);
    $newDate  = trim((string)($input['expires_at'] ?? ''));

    if ($enrollId <= 0 || !$newDate) admin_json(400, ['success' => false, 'message' => 'بيانات غير كافية.']);

    $enr = $pdo->prepare('SELECT * FROM enrollments WHERE id=?');
    $enr->execute([$enrollId]);
    $enrollment = $enr->fetch();
    if (!$enrollment) admin_json(404, ['success' => false, 'message' => 'التسجيل غير موجود.']);

    $pdo->prepare("UPDATE enrollments SET expires_at=?, updated_at=NOW() WHERE id=?")->execute([$newDate, $enrollId]);

    logEnrollmentEvent($pdo, $enrollId, $enrollment['status'], $enrollment['status'], $currentUser['id'],
        'تمديد الاشتراك حتى: ' . $newDate);

    admin_json(200, ['success' => true, 'message' => 'تم تمديد الاشتراك.']);
}

/* ─── CANCEL ─── */
if ($action === 'cancel') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enrollId = (int)($input['enrollment_id'] ?? 0);
    $reason   = trim((string)($input['reason'] ?? 'إلغاء من المدير'));

    if ($enrollId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف التسجيل مطلوب.']);

    $enr = $pdo->prepare('SELECT * FROM enrollments WHERE id=?');
    $enr->execute([$enrollId]);
    $enrollment = $enr->fetch();
    if (!$enrollment) admin_json(404, ['success' => false, 'message' => 'التسجيل غير موجود.']);

    $pdo->prepare("UPDATE enrollments SET status='cancelled', access_status='blocked', updated_at=NOW() WHERE id=?")
        ->execute([$enrollId]);

    logEnrollmentEvent($pdo, $enrollId, 'cancelled', $enrollment['status'], $currentUser['id'], $reason);

    admin_json(200, ['success' => true, 'message' => 'تم إلغاء التسجيل.']);
}

/* ─── HISTORY ─── */
if ($action === 'history') {
    $enrollId = (int)($_GET['enrollment_id'] ?? 0);
    if ($enrollId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف التسجيل مطلوب.']);

    $stmt = $pdo->prepare("
        SELECT ee.*, u.full_name AS actor_name
        FROM enrollment_events ee
        LEFT JOIN users u ON u.id = ee.actor_user_id
        WHERE ee.enrollment_id = ? ORDER BY ee.id DESC
    ");
    $stmt->execute([$enrollId]);
    admin_json(200, ['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ─── SEATS ─── */
if ($action === 'seats') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if ($courseId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الكورس مطلوب.']);

    $crs = $pdo->prepare('SELECT id, title_ar, max_students FROM courses WHERE id=?');
    $crs->execute([$courseId]);
    $course = $crs->fetch();
    if (!$course) admin_json(404, ['success' => false, 'message' => 'الكورس غير موجود.']);

    $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=? AND status IN (?,?)');
    $usedStmt->execute([$courseId, 'active', 'pending']);
    $used = (int)$usedStmt->fetchColumn();

    admin_json(200, [
        'success'       => true,
        'course_id'     => $courseId,
        'max_students'  => $course['max_students'],
        'used_seats'    => $used,
        'available'     => $course['max_students'] ? max(0, (int)$course['max_students'] - $used) : null
    ]);
}

/* ─── UPDATE SEATS ─── */
if ($action === 'update_seats') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $courseId = (int)($input['course_id'] ?? 0);
    $maxStudents = $input['max_students'] === null ? null : (int)$input['max_students'];

    if ($courseId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الكورس مطلوب.']);

    $pdo->prepare('UPDATE courses SET max_students=?, updated_at=NOW() WHERE id=?')
        ->execute([$maxStudents, $courseId]);

    admin_json(200, ['success' => true, 'message' => 'تم تحديث عدد المقاعد.']);
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير معروف.']);

/* ─── HELPER ─── */
function logEnrollmentEvent(PDO $pdo, int $enrollId, string $newStatus, string $oldStatus, int $actorId, string $note = ''): void {
    try {
        $pdo->prepare("INSERT INTO enrollment_events (enrollment_id, new_status, old_status, actor_user_id, note, created_at)
                       VALUES (?,?,?,?,?,NOW())")
            ->execute([$enrollId, $newStatus, $oldStatus, $actorId, $note]);
    } catch (Throwable) {}
}
