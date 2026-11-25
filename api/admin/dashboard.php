<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    admin_json(405, ['success' => false, 'message' => 'الطريقة غير مسموح بها.']);
}

/* ---------- Filters (all optional, all validated) ---------- */
function dash_date(string $key): ?string
{
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        admin_json(422, ['success' => false, 'message' => 'تاريخ الفلتر غير صالح.']);
    }
    return $v;
}

$to = dash_date('to') ?? date('Y-m-d');
$from = dash_date('from') ?? date('Y-m-d', strtotime($to . ' -29 days'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
    admin_json(422, ['success' => false, 'message' => 'أقصى مدة للفلتر 366 يومًا.']);
}
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

$courseId = (int)($_GET['course_id'] ?? 0);
$payStatus = (string)($_GET['payment_status'] ?? '');
$validPay = ['pending', 'under_review', 'confirmed', 'rejected', 'refunded', 'failed'];
if ($payStatus !== '' && !in_array($payStatus, $validPay, true)) {
    admin_json(422, ['success' => false, 'message' => 'حالة الدفع غير صالحة.']);
}
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 150) {
    admin_json(422, ['success' => false, 'message' => 'عبارة البحث طويلة جدًا.']);
}
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$sinceId = max(0, (int)($_GET['since_id'] ?? 0));

/** Run one dashboard section; a failure in one block must not blank the whole dashboard. */
function dash_safe(callable $fn, mixed $default): mixed
{
    try { return $fn(); }
    catch (Throwable $e) { error_log('[NBC admin dashboard section] ' . $e->getMessage()); return $default; }
}
function dash_val(PDO $pdo, string $sql, array $p = []): mixed
{
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn();
}
function dash_rows(PDO $pdo, string $sql, array $p = []): array
{
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function dash_fill(array $rows, string $from, string $to, bool $float): array
{
    $map = [];
    foreach ($rows as $r) $map[$r['d']] = $float ? (float)$r['v'] : (int)$r['v'];
    $labels = []; $values = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $labels[] = $d; $values[] = $map[$d] ?? 0;
    }
    return ['labels' => $labels, 'values' => $values];
}

try {
    /* ---------- Core counters (original ones kept as-is) ---------- */
    $counts = [];
    $counts['students'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
    $counts['instructors'] = (int)$pdo->query("SELECT COUNT(DISTINCT u.id) FROM users u WHERE (u.role='instructor' OR u.id IN (SELECT ur.user_id FROM user_roles ur WHERE ur.role='instructor') OR u.id IN (SELECT DISTINCT c.instructor_id FROM courses c WHERE c.instructor_id IS NOT NULL))")->fetchColumn();
    $counts['active_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $counts['published_courses'] = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status='published'")->fetchColumn();
    $counts['total_courses'] = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    $counts['active_enrollments'] = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status='active'")->fetchColumn();

    // These tables are created by the additive migration.
    $counts['pending_payments'] = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status IN ('pending','under_review')")->fetchColumn();
    $counts['confirmed_payments_total_egp'] = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND currency='EGP'")->fetchColumn();
    $counts['certificates'] = (int)$pdo->query("SELECT COUNT(*) FROM certificates WHERE revoked_at IS NULL")->fetchColumn();
    $counts['total_books'] = dash_safe(fn() => (int)$pdo->query("SELECT COUNT(*) FROM books WHERE status='published'")->fetchColumn(), 0);
    $counts['total_orders'] = dash_safe(fn() => (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(), 0);
    $counts['pending_orders'] = dash_safe(fn() => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending_review'")->fetchColumn(), 0);

    /* ---------- New counters from the handwritten plan ---------- */
    $counts['new_students_today'] = dash_safe(fn() => (int)dash_val($pdo, "SELECT COUNT(*) FROM users WHERE role='student' AND DATE(created_at)=CURDATE()"), 0);
    $counts['new_students_7d'] = dash_safe(fn() => (int)dash_val($pdo, "SELECT COUNT(*) FROM users WHERE role='student' AND created_at >= (NOW() - INTERVAL 7 DAY)"), 0);
    $counts['new_students_30d'] = dash_safe(fn() => (int)dash_val($pdo, "SELECT COUNT(*) FROM users WHERE role='student' AND created_at >= (NOW() - INTERVAL 30 DAY)"), 0);
    $counts['pending_instructors'] = dash_safe(fn() => (int)dash_val($pdo, "SELECT COUNT(*) FROM users WHERE role='instructor' AND status='pending'"), 0);
    $counts['completed_enrollments'] = dash_safe(fn() => (int)dash_val($pdo, "SELECT COUNT(*) FROM enrollments WHERE status='completed'"), 0);
    $den = $counts['active_enrollments'] + $counts['completed_enrollments'];
    $counts['completion_rate'] = $den > 0 ? round($counts['completed_enrollments'] * 100 / $den, 1) : 0.0;

    $revSql = "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND currency='EGP' AND ";
    $counts['revenue_today_egp'] = dash_safe(fn() => (float)dash_val($pdo, $revSql . "DATE(COALESCE(paid_at,created_at))=CURDATE()"), 0.0);
    $counts['revenue_month_egp'] = dash_safe(fn() => (float)dash_val($pdo, $revSql . "DATE_FORMAT(COALESCE(paid_at,created_at),'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"), 0.0);
    $counts['max_student_id'] = dash_safe(fn() => (int)dash_val($pdo, "SELECT COALESCE(MAX(id),0) FROM users WHERE role='student'"), 0);
    $counts['new_students_since'] = $sinceId > 0
        ? dash_safe(fn() => (int)dash_val($pdo, "SELECT COUNT(*) FROM users WHERE role='student' AND id > ?", [$sinceId]), 0)
        : 0;

    /* ---------- Existing lists ---------- */
    $latestUsers = $pdo->query(
        "SELECT id, full_name, email, role, status, created_at
         FROM users ORDER BY created_at DESC, id DESC LIMIT 8"
    )->fetchAll();

    $pending = $pdo->query(
        "SELECT p.id, p.amount, p.currency, p.payment_method, p.status, p.created_at,
                u.full_name AS student_name, c.title AS course_title
         FROM payments p
         JOIN users u ON u.id = p.student_id
         JOIN courses c ON c.id = p.course_id
         WHERE p.status IN ('pending','under_review')
         ORDER BY p.created_at DESC LIMIT 8"
    )->fetchAll();

    /* ---------- Completion rate per course ---------- */
    $completionByCourse = dash_safe(fn() => dash_rows($pdo,
        "SELECT c.id, c.title,
                SUM(e.status IN ('active','completed')) AS enrolled,
                SUM(e.status = 'completed') AS completed
         FROM courses c
         JOIN enrollments e ON e.course_id = c.id
         " . ($courseId > 0 ? "WHERE c.id = ?" : "") . "
         GROUP BY c.id, c.title
         HAVING enrolled > 0
         ORDER BY enrolled DESC LIMIT 8",
        $courseId > 0 ? [$courseId] : []), []);
    foreach ($completionByCourse as &$row) {
        $row['enrolled'] = (int)$row['enrolled'];
        $row['completed'] = (int)$row['completed'];
        $row['rate'] = $row['enrolled'] > 0 ? round($row['completed'] * 100 / $row['enrolled'], 1) : 0;
    }
    unset($row);

    /* ---------- Charts (follow the date / course / payment-status filters) ---------- */
    $chartPayStatus = $payStatus !== '' ? $payStatus : 'confirmed';
    $revParams = [$chartPayStatus, $fromDt, $toDt];
    $revCourse = '';
    if ($courseId > 0) { $revCourse = ' AND course_id = ?'; $revParams[] = $courseId; }
    $revRows = dash_safe(fn() => dash_rows($pdo,
        "SELECT DATE(COALESCE(paid_at,created_at)) AS d, SUM(amount) AS v
         FROM payments
         WHERE status = ? AND currency='EGP' AND COALESCE(paid_at,created_at) BETWEEN ? AND ?$revCourse
         GROUP BY d", $revParams), []);

    $enrParams = [$fromDt, $toDt];
    $enrCourse = '';
    if ($courseId > 0) { $enrCourse = ' AND course_id = ?'; $enrParams[] = $courseId; }
    $enrRows = dash_safe(fn() => dash_rows($pdo,
        "SELECT DATE(enrolled_at) AS d, COUNT(*) AS v FROM enrollments
         WHERE enrolled_at BETWEEN ? AND ?$enrCourse GROUP BY d", $enrParams), []);

    $stuRows = dash_safe(fn() => dash_rows($pdo,
        "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM users
         WHERE role='student' AND created_at BETWEEN ? AND ? GROUP BY d", [$fromDt, $toDt]), []);

    $charts = [
        'revenue' => dash_fill($revRows, $from, $to, true) + ['status' => $chartPayStatus],
        'enrollments' => dash_fill($enrRows, $from, $to, false),
        'new_students' => dash_fill($stuRows, $from, $to, false),
    ];

    /* ---------- Latest enrollments (filtered + searchable) ---------- */
    $w = ['e.enrolled_at BETWEEN ? AND ?']; $p = [$fromDt, $toDt];
    if ($courseId > 0) { $w[] = 'e.course_id = ?'; $p[] = $courseId; }
    if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ? OR c.title LIKE ?)'; array_push($p, $like, $like, $like); }
    $latestEnrollments = dash_safe(fn() => dash_rows($pdo,
        "SELECT e.id, e.status, e.enrolled_at, u.full_name AS student_name, u.email, c.title AS course_title
         FROM enrollments e
         JOIN users u ON u.id = e.student_id
         JOIN courses c ON c.id = e.course_id
         WHERE " . implode(' AND ', $w) . "
         ORDER BY e.enrolled_at DESC, e.id DESC LIMIT 10", $p), []);

    /* ---------- Latest financial transactions (filtered + searchable) ---------- */
    $w = ['p.created_at BETWEEN ? AND ?']; $p = [$fromDt, $toDt];
    if ($courseId > 0) { $w[] = 'p.course_id = ?'; $p[] = $courseId; }
    if ($payStatus !== '') { $w[] = 'p.status = ?'; $p[] = $payStatus; }
    if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ? OR c.title LIKE ? OR p.transaction_reference LIKE ?)'; array_push($p, $like, $like, $like, $like); }
    $latestTransactions = dash_safe(fn() => dash_rows($pdo,
        "SELECT p.id, p.amount, p.currency, p.payment_method, p.status, p.created_at, p.transaction_reference,
                u.full_name AS student_name, c.title AS course_title
         FROM payments p
         JOIN users u ON u.id = p.student_id
         JOIN courses c ON c.id = p.course_id
         WHERE " . implode(' AND ', $w) . "
         ORDER BY p.created_at DESC, p.id DESC LIMIT 10", $p), []);

    /* ---------- Latest activities (merged feed) ---------- */
    $feed = [];
    $take = function (array $rows) use (&$feed) { foreach ($rows as $r) $feed[] = $r; };
    $qa = $q !== '' ? ' AND ' : '';
    $take(dash_safe(fn() => dash_rows($pdo,
        "SELECT 'audit' AS kind, a.action AS label, COALESCE(u.full_name,'النظام') AS who, a.entity_type AS subject, a.created_at AS at
         FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
         WHERE a.created_at BETWEEN ? AND ?" . ($q !== '' ? " AND (a.action LIKE ? OR u.full_name LIKE ?)" : "") . "
         ORDER BY a.created_at DESC LIMIT 10",
        $q !== '' ? [$fromDt, $toDt, $like, $like] : [$fromDt, $toDt]), []));
    $take(dash_safe(fn() => dash_rows($pdo,
        "SELECT 'registered' AS kind, role AS label, full_name AS who, '' AS subject, created_at AS at
         FROM users WHERE created_at BETWEEN ? AND ?" . ($q !== '' ? " AND (full_name LIKE ? OR email LIKE ?)" : "") . "
         ORDER BY created_at DESC LIMIT 10",
        $q !== '' ? [$fromDt, $toDt, $like, $like] : [$fromDt, $toDt]), []));
    $take(dash_safe(fn() => dash_rows($pdo,
        "SELECT 'enrolled' AS kind, e.status AS label, u.full_name AS who, c.title AS subject, e.enrolled_at AS at
         FROM enrollments e JOIN users u ON u.id=e.student_id JOIN courses c ON c.id=e.course_id
         WHERE e.enrolled_at BETWEEN ? AND ?" . ($courseId > 0 ? " AND e.course_id = ?" : "") . ($q !== '' ? " AND (u.full_name LIKE ? OR c.title LIKE ?)" : "") . "
         ORDER BY e.enrolled_at DESC LIMIT 10",
        array_merge([$fromDt, $toDt], $courseId > 0 ? [$courseId] : [], $q !== '' ? [$like, $like] : [])), []));
    $take(dash_safe(fn() => dash_rows($pdo,
        "SELECT 'payment' AS kind, p.status AS label, u.full_name AS who, c.title AS subject, p.created_at AS at
         FROM payments p JOIN users u ON u.id=p.student_id JOIN courses c ON c.id=p.course_id
         WHERE p.created_at BETWEEN ? AND ?" . ($courseId > 0 ? " AND p.course_id = ?" : "") . ($payStatus !== '' ? " AND p.status = ?" : "") . ($q !== '' ? " AND (u.full_name LIKE ? OR c.title LIKE ?)" : "") . "
         ORDER BY p.created_at DESC LIMIT 10",
        array_merge([$fromDt, $toDt], $courseId > 0 ? [$courseId] : [], $payStatus !== '' ? [$payStatus] : [], $q !== '' ? [$like, $like] : [])), []));
    $take(dash_safe(fn() => dash_rows($pdo,
        "SELECT 'certificate' AS kind, '' AS label, ce.student_name_snapshot AS who, ce.course_title_snapshot AS subject, ce.issued_at AS at
         FROM certificates ce WHERE ce.issued_at BETWEEN ? AND ?" . ($courseId > 0 ? " AND ce.course_id = ?" : "") . ($q !== '' ? " AND (ce.student_name_snapshot LIKE ? OR ce.course_title_snapshot LIKE ?)" : "") . "
         ORDER BY ce.issued_at DESC LIMIT 10",
        array_merge([$fromDt, $toDt], $courseId > 0 ? [$courseId] : [], $q !== '' ? [$like, $like] : [])), []));
    usort($feed, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    $latestActivities = array_slice($feed, 0, 12);

    /* ---------- Course list for the course filter ---------- */
    $courseOptions = dash_safe(fn() => dash_rows($pdo, "SELECT id, title FROM courses ORDER BY title"), []);

    /* ---------- Alerts ---------- */
    $alerts = [];
    if ($counts['new_students_today'] > 0) $alerts[] = ['type' => 'info', 'text' => 'طلاب جدد اليوم: ' . $counts['new_students_today']];
    if ($counts['pending_payments'] > 0) $alerts[] = ['type' => 'warning', 'text' => 'مدفوعات تحتاج مراجعة: ' . $counts['pending_payments']];
    if ($counts['pending_instructors'] > 0) $alerts[] = ['type' => 'warning', 'text' => 'محاضرون في انتظار الموافقة: ' . $counts['pending_instructors']];

    admin_json(200, [
        'success' => true,
        'csrf_token' => $_SESSION['csrf_token'],
        'counts' => $counts,
        'latest_users' => $latestUsers,
        'pending_payments' => $pending,
        'completion_by_course' => $completionByCourse,
        'charts' => $charts,
        'latest_enrollments' => $latestEnrollments,
        'latest_transactions' => $latestTransactions,
        'latest_activities' => $latestActivities,
        'course_options' => $courseOptions,
        'alerts' => $alerts,
        'filters' => ['from' => $from, 'to' => $to, 'course_id' => $courseId, 'payment_status' => $payStatus, 'q' => $q],
        'generated_at' => gmdate('c'),
        'data_source' => 'database'
    ]);
} catch (Throwable $e) {
    error_log('[NBC admin dashboard] ' . $e->getMessage());
    admin_json(503, [
        'success' => false,
        'message' => 'تعذر تحميل بيانات لوحة الإدارة. تأكد من تطبيق ترحيل قاعدة البيانات الجديد.',
    ] + (admin_is_local() ? ['debug' => get_class($e) . ': ' . $e->getMessage()] : []));
}
