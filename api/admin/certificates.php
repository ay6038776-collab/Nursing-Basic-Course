<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

/* ─── LIST CERTIFICATES ─── */
if ($action === 'list') {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20; $offset = ($page - 1) * $limit;
    $search = trim((string)($_GET['search'] ?? ''));
    $courseId = (int)($_GET['course_id'] ?? 0);

    $where = ['1=1']; $p = [];
    if ($courseId > 0) { $where[] = 'cert.course_id=?'; $p[] = $courseId; }
    if ($search !== '') {
        $where[] = '(u.full_name LIKE ? OR cert.certificate_number LIKE ? OR c.title_ar LIKE ?)';
        $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%";
    }
    $w = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM certificates cert JOIN users u ON u.id=cert.student_id JOIN courses c ON c.id=cert.course_id WHERE $w");
    $countStmt->execute($p);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT cert.*, u.full_name AS student_name, u.email AS student_email,
               c.title_ar AS course_title, c.title_en AS course_title_en
        FROM certificates cert
        JOIN users u ON u.id = cert.student_id
        JOIN courses c ON c.id = cert.course_id
        WHERE $w ORDER BY cert.id DESC LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($p);

    admin_json(200, ['success' => true, 'certificates' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => ['total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $limit)]]);
}

/* ─── ISSUE CERTIFICATE (manual or auto) ─── */
if ($action === 'issue') {
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $studentId = (int)($input['student_id'] ?? 0);
    $courseId  = (int)($input['course_id']  ?? 0);
    $force     = (bool)($input['force'] ?? false); // admin override

    if ($studentId <= 0 || $courseId <= 0) admin_json(400, ['success' => false, 'message' => 'بيانات ناقصة.']);

    // Check enrollment
    $enr = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND course_id=? AND status IN ('active','completed')");
    $enr->execute([$studentId, $courseId]);
    if (!$enr->fetch()) admin_json(403, ['success' => false, 'message' => 'الطالب غير مسجل في هذا الكورس.']);

    // Check already issued
    $dup = $pdo->prepare("SELECT id, certificate_number FROM certificates WHERE student_id=? AND course_id=? AND status='issued'");
    $dup->execute([$studentId, $courseId]);
    $existing = $dup->fetch();
    if ($existing) {
        admin_json(409, ['success' => false, 'message' => 'تم إصدار شهادة لهذا الطالب بالفعل.', 'certificate_number' => $existing['certificate_number']]);
    }

    // Completion check (unless forced)
    if (!$force) {
        $eligible = checkCertificateEligibility($pdo, $studentId, $courseId);
        if (!$eligible['eligible']) {
            admin_json(422, ['success' => false, 'message' => 'الطالب لم يستوفِ متطلبات إتمام الكورس بعد.', 'details' => $eligible]);
        }
    }

    // Get student and course info
    $stu = $pdo->prepare('SELECT full_name FROM users WHERE id=?');
    $stu->execute([$studentId]);
    $student = $stu->fetch();

    $crs = $pdo->prepare('SELECT title_ar, title_en FROM courses WHERE id=?');
    $crs->execute([$courseId]);
    $course = $crs->fetch();

    // Get platform name from settings
    $settStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='platform_name'");
    $settStmt->execute();
    $platformName = $settStmt->fetchColumn() ?: 'Nursing Basic Course';

    // Generate unique cert number
    $certNum = generateCertNumber($pdo, $courseId, $studentId);
    $qrData  = 'http://localhost/NursingBasicCourse/verify-certificate.php?cert=' . $certNum;

    $ins = $pdo->prepare("INSERT INTO certificates
        (student_id, course_id, certificate_number, issued_by, issued_at, issuer_name, status, qr_data, created_at)
        VALUES (?,?,?,?,NOW(),?,'issued',?,NOW())");
    $ins->execute([$studentId, $courseId, $certNum, $currentUser['id'], $platformName, $qrData]);
    $certId = (int)$pdo->lastInsertId();

    // Update enrollment status
    $pdo->prepare("UPDATE enrollments SET status='completed', updated_at=NOW() WHERE student_id=? AND course_id=?")
        ->execute([$studentId, $courseId]);

    // Notify student
    $pdo->prepare("INSERT INTO notifications (user_id,title,message,type,created_at) VALUES (?,?,?,'certificate',NOW())")
        ->execute([$studentId, '🎓 تهانينا! تم إصدار شهادتك', "تم إصدار شهادة إتمام كورس «{$course['title_ar']}». رقم الشهادة: $certNum. يمكنك تحميلها من لوحة التحكم."]);

    admin_json(200, ['success' => true, 'message' => 'تم إصدار الشهادة بنجاح.', 'certificate_id' => $certId, 'certificate_number' => $certNum]);
}

/* ─── REVOKE CERTIFICATE ─── */
if ($action === 'revoke') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $certId = (int)($input['certificate_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? 'سُحبت بقرار إداري'));

    if ($certId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الشهادة مطلوب.']);

    $pdo->prepare("UPDATE certificates SET status='revoked', revoke_reason=?, revoked_at=NOW(), updated_at=NOW() WHERE id=?")
        ->execute([$reason, $certId]);

    admin_json(200, ['success' => true, 'message' => 'تم سحب الشهادة.']);
}

/* ─── CHECK ELIGIBILITY ─── */
if ($action === 'check_eligibility') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    $courseId  = (int)($_GET['course_id']  ?? 0);
    if ($studentId <= 0 || $courseId <= 0) admin_json(400, ['success' => false, 'message' => 'بيانات ناقصة.']);

    admin_json(200, ['success' => true, 'eligibility' => checkCertificateEligibility($pdo, $studentId, $courseId)]);
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير معروف.']);

/* ─── HELPERS ─── */
function generateCertNumber(PDO $pdo, int $courseId, int $studentId): string {
    $year   = date('Y');
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $num    = "NBC-{$year}-C{$courseId}-S{$studentId}-{$random}";

    // Ensure unique
    $check = $pdo->prepare('SELECT id FROM certificates WHERE certificate_number=?');
    $check->execute([$num]);
    if ($check->fetch()) {
        return generateCertNumber($pdo, $courseId, $studentId);
    }
    return $num;
}

function checkCertificateEligibility(PDO $pdo, int $studentId, int $courseId): array {
    $result = ['eligible' => false, 'progress' => 0, 'quiz_passed' => true, 'details' => []];

    // Check lesson progress
    $progStmt = $pdo->prepare("
        SELECT ROUND(AVG(progress_percent),0) AS avg_progress
        FROM lesson_progress lp
        JOIN lessons l ON l.id=lp.lesson_id
        WHERE lp.student_id=? AND l.course_id=?
    ");
    $progStmt->execute([$studentId, $courseId]);
    $prog = $progStmt->fetch();
    $result['progress'] = (int)($prog['avg_progress'] ?? 0);

    // Check required quizzes passed
    $quizStmt = $pdo->prepare("
        SELECT q.id, q.title_ar, q.pass_score,
               (SELECT MAX(score) FROM quiz_attempts WHERE student_id=? AND quiz_id=q.id AND is_passed=1) AS best_pass
        FROM quizzes q WHERE q.course_id=? AND q.is_published=1
    ");
    $quizStmt->execute([$studentId, $courseId]);
    $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($quizzes as $qz) {
        if ($qz['best_pass'] === null) {
            $result['quiz_passed'] = false;
            $result['details'][] = "لم يجتز: {$qz['title_ar']}";
        }
    }

    $result['eligible'] = ($result['progress'] >= 80 && $result['quiz_passed']);
    return $result;
}
