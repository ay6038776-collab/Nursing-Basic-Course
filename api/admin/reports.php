<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action   = (string)($_GET['action'] ?? 'summary');
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo   = trim((string)($_GET['date_to']   ?? date('Y-m-d')));
$courseId = (int)($_GET['course_id'] ?? 0);
$instructorId = (int)($_GET['instructor_id'] ?? 0);
$format   = trim((string)($_GET['format'] ?? 'json'));

/* ─── helper: CSV output ─── */
function outputCsv(string $filename, array $headers, array $rows): never {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF"; // BOM for Excel UTF-8
    $f = fopen('php://output', 'w');
    fputcsv($f, $headers);
    foreach ($rows as $row) fputcsv($f, array_values($row));
    fclose($f);
    exit;
}

/* ─── SUMMARY DASHBOARD ─── */
if ($action === 'summary') {
    $data = [];

    // Enrollments by period
    $enrStmt = $pdo->prepare("SELECT DATE(enrolled_at) AS day, COUNT(*) AS cnt FROM enrollments WHERE DATE(enrolled_at) BETWEEN ? AND ? GROUP BY day ORDER BY day");
    $enrStmt->execute([$dateFrom, $dateTo]);
    $data['enrollments_by_day'] = $enrStmt->fetchAll(PDO::FETCH_ASSOC);

    // Top courses
    $topStmt = $pdo->prepare("SELECT c.id, COALESCE(c.title_ar, c.title, 'كورس') AS title_ar,
        COUNT(DISTINCT e.id) AS enrollment_count,
        COALESCE(SUM(CASE WHEN o.status='paid' THEN oi.subtotal ELSE 0 END),0) AS revenue
        FROM courses c LEFT JOIN enrollments e ON e.course_id=c.id LEFT JOIN order_items oi ON oi.item_type='course' AND oi.item_id=c.id
        LEFT JOIN orders o ON o.id=oi.order_id AND o.status='paid'
        GROUP BY c.id ORDER BY enrollment_count DESC LIMIT 10");
    $topStmt->execute();
    $data['top_courses'] = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    // Revenue summary
    $revStmt = $pdo->prepare("SELECT
        SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END) AS confirmed_revenue,
        SUM(CASE WHEN status IN ('pending_payment','pending_review') THEN total_amount ELSE 0 END) AS pending_revenue,
        0 AS refunded,
        COUNT(CASE WHEN status='paid' THEN 1 END) AS paid_count,
        COUNT(CASE WHEN status IN ('pending_payment','pending_review') THEN 1 END) AS pending_count
        FROM orders WHERE DATE(created_at) BETWEEN ? AND ?");
    $revStmt->execute([$dateFrom, $dateTo]);
    $data['revenue'] = $revStmt->fetch(PDO::FETCH_ASSOC);

    // New students
    $newStuStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND DATE(created_at) BETWEEN ? AND ?");
    $newStuStmt->execute([$dateFrom, $dateTo]);
    $data['new_students'] = (int)$newStuStmt->fetchColumn();

    // Completion rates
    $compStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM enrollments WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status");
    $compStmt->execute([$dateFrom, $dateTo]);
    $data['enrollment_by_status'] = $compStmt->fetchAll(PDO::FETCH_ASSOC);

    admin_json(200, ['success' => true, 'report' => $data, 'period' => ['from' => $dateFrom, 'to' => $dateTo]]);
}

/* ─── ENROLLMENTS BY PERIOD ─── */
if ($action === 'enrollments_by_period') {
    $groupBy = in_array($_GET['group'] ?? '', ['week', 'month'], true) ? ($_GET['group'] === 'month' ? '%Y-%m' : '%Y-%u') : '%Y-%m-%d';

    $where = ['DATE(e.enrolled_at) BETWEEN ? AND ?']; $p = [$dateFrom, $dateTo];
    if ($courseId > 0) { $where[] = 'e.course_id=?'; $p[] = $courseId; }

    $stmt = $pdo->prepare("SELECT DATE_FORMAT(e.enrolled_at, '$groupBy') AS period, COUNT(*) AS cnt, c.title_ar AS course
        FROM enrollments e JOIN courses c ON c.id=e.course_id
        WHERE " . implode(' AND ', $where) . " GROUP BY period ORDER BY period");
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') outputCsv('enrollments.csv', ['الفترة', 'عدد التسجيلات', 'الكورس'], $rows);
    admin_json(200, ['success' => true, 'rows' => $rows]);
}

/* ─── REVENUE REPORT ─── */
if ($action === 'revenue') {
    $where = ['DATE(o.created_at) BETWEEN ? AND ?']; $p = [$dateFrom, $dateTo];
    if ($courseId > 0) { $where[] = 'oi.item_id=? AND oi.item_type=\'course\''; $p[] = $courseId; }
    $w = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.total_amount, o.status, o.payment_method,
               o.created_at, u.full_name AS student_name, c.title_ar AS course_title
        FROM orders o JOIN users u ON u.id=o.student_id
        LEFT JOIN order_items oi ON oi.order_id=o.id
        LEFT JOIN courses c ON c.id=oi.item_id AND oi.item_type='course'
        WHERE $w ORDER BY o.created_at DESC
    ");
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') outputCsv('revenue.csv', ['رقم الطلب', 'المبلغ', 'الحالة', 'طريقة الدفع', 'التاريخ', 'الطالب', 'الكورس'], $rows);
    admin_json(200, ['success' => true, 'rows' => $rows]);
}

/* ─── QUIZ PERFORMANCE ─── */
if ($action === 'quiz_performance') {
    $where = ['1=1']; $p = [];
    if ($courseId > 0) { $where[] = 'q.course_id=?'; $p[] = $courseId; }

    $stmt = $pdo->prepare("
        SELECT q.id, COALESCE(q.title_ar, 'اختبار') AS title_ar, q.pass_percent AS pass_score,
               COUNT(qa.id) AS total_attempts,
               SUM(CASE WHEN qa.passed=1 THEN 1 ELSE 0 END) AS passed,
               ROUND(AVG(COALESCE(qa.percent_score, qa.score)),1) AS avg_score
        FROM quizzes q LEFT JOIN quiz_attempts qa ON qa.quiz_id=q.id AND qa.status IN ('submitted','graded')
        WHERE " . implode(' AND ', $where) . " GROUP BY q.id ORDER BY q.id
    ");
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['pass_rate'] = $r['total_attempts'] > 0 ? round($r['passed'] / $r['total_attempts'] * 100, 1) : 0;
    }
    unset($r);

    if ($format === 'csv') outputCsv('quiz_performance.csv', ['الاختبار', 'درجة النجاح', 'المحاولات', 'الناجحون', 'نسبة النجاح%', 'متوسط الدرجة'], $rows);
    admin_json(200, ['success' => true, 'rows' => $rows]);
}

/* ─── INSTRUCTOR ACTIVITY ─── */
if ($action === 'instructor_activity') {
    $stmt = $pdo->query("
        SELECT u.id, u.full_name, u.email,
               COUNT(DISTINCT c.id) AS course_count,
               COUNT(DISTINCT e.id) AS student_count,
               ROUND(AVG(r.rating),2) AS avg_rating
        FROM users u
        LEFT JOIN courses c ON c.instructor_id=u.id AND c.status='published'
        LEFT JOIN enrollments e ON e.course_id=c.id AND e.status='active'
        LEFT JOIN reviews r ON (r.item_type='course' AND r.item_id=c.id)
        WHERE u.role='instructor'
        GROUP BY u.id ORDER BY student_count DESC
    ");
    admin_json(200, ['success' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ─── NEW STUDENTS ─── */
if ($action === 'new_students') {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, u.created_at, u.phone,
               COUNT(e.id) AS enrollment_count
        FROM users u
        LEFT JOIN enrollments e ON e.student_id=u.id
        WHERE u.role='student' AND DATE(u.created_at) BETWEEN ? AND ?
        GROUP BY u.id ORDER BY u.created_at DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') outputCsv('new_students.csv', ['الاسم', 'البريد', 'تاريخ التسجيل', 'عدد الكورسات'], $rows);
    admin_json(200, ['success' => true, 'rows' => $rows]);
}

/* ─── STRUGGLING STUDENTS ─── */
if ($action === 'struggling_students') {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, c.title_ar AS course_title,
               COALESCE(ROUND(AVG(lp.progress_percent),0),0) AS avg_progress,
               COUNT(DISTINCT CASE WHEN qa.passed=0 THEN qa.id END) AS failed_quizzes,
               e.enrolled_at
        FROM enrollments e
        JOIN users u ON u.id=e.student_id
        JOIN courses c ON c.id=e.course_id
        LEFT JOIN lesson_progress lp ON lp.student_id=e.student_id
            AND lp.lesson_id IN (SELECT id FROM lessons WHERE course_id=e.course_id)
        LEFT JOIN quiz_attempts qa ON qa.student_id=e.student_id AND qa.status IN ('submitted','graded')
            AND qa.quiz_id IN (SELECT id FROM quizzes WHERE course_id=e.course_id)
        WHERE e.status='active' AND DATE(e.enrolled_at) BETWEEN ? AND ?
        GROUP BY e.id,u.id,c.id,e.enrolled_at
        HAVING avg_progress < 30 OR failed_quizzes >= 2
        ORDER BY avg_progress ASC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    admin_json(200, ['success' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

admin_json(400, ['success' => false, 'message' => 'تقرير غير معروف.']);
