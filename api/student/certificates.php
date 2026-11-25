<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/student_guard.php';

$action = (string)($_GET['action'] ?? 'list');
$me     = (int)$currentStudent['id'];

/* LIST MY CERTIFICATES */
if ($action === 'list') {
    // Try different column combinations for course title
    try {
        $stmt = $pdo->prepare("
            SELECT cert.id, cert.certificate_number, cert.issued_at, cert.pdf_path,
                   COALESCE(cert.course_title_snapshot, c.title_ar, c.title) AS course_title
            FROM certificates cert
            JOIN courses c ON c.id = cert.course_id
            WHERE cert.student_id=? AND (cert.revoked_at IS NULL AND (cert.status IS NULL OR cert.status != 'revoked'))
            ORDER BY cert.issued_at DESC
        ");
        $stmt->execute([$me]);
        $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback simpler query
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE student_id=? ORDER BY issued_at DESC");
        $stmt->execute([$me]);
        $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    student_json(200, ['success' => true, 'certificates' => $certs]);
}

/* CHECK ELIGIBILITY */
if ($action === 'check_eligibility') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if ($courseId <= 0) student_json(400, ['success' => false, 'message' => 'course_id مطلوب']);

    $enr = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND course_id=? AND status IN ('active','completed')");
    $enr->execute([$me, $courseId]);
    if (!$enr->fetch()) student_json(403, ['success' => false, 'message' => 'غير مسجل في هذا الكورس']);

    // Count total vs completed lessons
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=? AND (status='published' OR is_published=1)");
    $totalStmt->execute([$courseId]);
    $totalLessons = (int)$totalStmt->fetchColumn();

    $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress lp
        JOIN lessons l ON l.id=lp.lesson_id
        WHERE lp.student_id=? AND l.course_id=? AND lp.status='completed'");
    $doneStmt->execute([$me, $courseId]);
    $doneLessons = (int)$doneStmt->fetchColumn();

    $progress = $totalLessons > 0 ? (int)round($doneLessons / $totalLessons * 100) : 0;

    // Check quizzes — using whatever column exists
    $cols = $pdo->query("SHOW COLUMNS FROM quiz_attempts")->fetchAll(PDO::FETCH_COLUMN);
    $passedCol = in_array('passed', $cols) ? 'qa.passed' : (in_array('status', $cols) ? "(qa.status = 'passed')" : '1');

    try {
        $qStmt = $pdo->prepare("SELECT q.id, MAX($passedCol) AS best_pass
            FROM quizzes q
            LEFT JOIN quiz_attempts qa ON qa.quiz_id=q.id AND qa.student_id=?
            WHERE q.course_id=? AND (q.is_published=1 OR q.status='published')
            GROUP BY q.id");
        $qStmt->execute([$me, $courseId]);
        $quizResults = $qStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $quizResults = [];
    }

    $allPassed = !empty($quizResults);
    foreach ($quizResults as $qr) {
        if (!$qr['best_pass']) { $allPassed = false; break; }
    }

    // Already have a cert?
    $certCheck = $pdo->prepare("SELECT certificate_number FROM certificates WHERE student_id=? AND course_id=? LIMIT 1");
    $certCheck->execute([$me, $courseId]);
    $existingCert = $certCheck->fetchColumn();

    $eligible = $progress >= 80 && ($allPassed || empty($quizResults));

    student_json(200, [
        'success'           => true,
        'eligible'          => $eligible,
        'progress'          => $progress,
        'total_lessons'     => $totalLessons,
        'done_lessons'      => $doneLessons,
        'quizzes_all_passed'=> $allPassed,
        'has_certificate'   => !empty($existingCert),
        'certificate_number'=> $existingCert ?: null,
    ]);
}

student_json(400, ['success' => false, 'message' => 'action غير معروف']);
