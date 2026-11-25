<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/student_guard.php';

$action = (string)($_GET['action'] ?? 'course_progress');
$me     = (int)$currentStudent['id'];

// Detect lesson_progress columns
$lpCols = $pdo->query("SHOW COLUMNS FROM lesson_progress")->fetchAll(PDO::FETCH_COLUMN);
$hasWatchPct  = in_array('watch_percent', $lpCols);
$hasStatus    = in_array('status', $lpCols);
$hasProgPct   = in_array('progress_percent', $lpCols);
$hasCompAt    = in_array('completed_at', $lpCols);
$hasUpdAt     = in_array('updated_at', $lpCols);

// Adaptive column expressions
$progressExpr  = $hasWatchPct ? 'lp.watch_percent' : ($hasProgPct ? 'lp.progress_percent' : '0');
$completedExpr = $hasStatus   ? "(lp.status='completed')" : ($hasProgPct ? '(lp.progress_percent>=90)' : ($hasWatchPct ? '(lp.watch_percent>=90)' : '0'));
$lastActivityExpr = $hasUpdAt ? 'lp.updated_at' : 'lp.created_at';

// Detect lessons columns
$lCols = $pdo->query("SHOW COLUMNS FROM lessons")->fetchAll(PDO::FETCH_COLUMN);
$lTitleExpr  = in_array('title_ar', $lCols) ? "COALESCE(l.title_ar, l.title)" : "l.title";
$lStatusExpr = in_array('status', $lCols) ? "l.status='published'" : (in_array('is_published', $lCols) ? "l.is_published=1" : "1=1");
$lSortExpr   = in_array('sort_order', $lCols) ? 'l.sort_order, l.id' : 'l.id';

// Detect access_status in enrollments
$enCols      = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN);
$accessCond  = in_array('access_status', $enCols) ? "AND e.access_status='granted'" : '';

/* COURSE PROGRESS */
if ($action === 'course_progress') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if ($courseId <= 0) student_json(400, ['success' => false, 'message' => 'course_id مطلوب']);

    $enr = $pdo->prepare("SELECT * FROM enrollments WHERE student_id=? AND course_id=? AND status IN ('active','completed') $accessCond");
    $enr->execute([$me, $courseId]);
    $enrollment = $enr->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) student_json(403, ['success' => false, 'message' => 'غير مسجل في هذا الكورس']);

    // Lessons with progress
    $lessonStmt = $pdo->prepare("SELECT l.id, $lTitleExpr AS title_ar, l.id AS sort_order,
        $progressExpr AS watch_percent,
        $completedExpr AS is_completed,
        " . ($hasCompAt ? 'lp.completed_at' : ($hasUpdAt ? 'IF('.$completedExpr.', lp.updated_at, NULL)' : 'NULL')) . " AS completed_at
        FROM lessons l
        LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=?
        WHERE l.course_id=? AND ($lStatusExpr)
        ORDER BY $lSortExpr");
    $lessonStmt->execute([$me, $courseId]);
    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalLessons = count($lessons);
    $completedCount = 0;
    $totalPct = 0;
    foreach ($lessons as $l) {
        if ($l['is_completed']) $completedCount++;
        $totalPct += (int)$l['watch_percent'];
    }
    $avgProgress = $totalLessons > 0 ? round($totalPct / $totalLessons, 1) : 0;

    // Quiz results (adaptive)
    $qaCols   = $pdo->query("SHOW COLUMNS FROM quiz_attempts")->fetchAll(PDO::FETCH_COLUMN);
    $qScoreCol = in_array('score_percent', $qaCols) ? 'score_percent' : (in_array('percent_score', $qaCols) ? 'percent_score' : '0');
    $qPassCol  = in_array('passed', $qaCols) ? 'passed' : (in_array('is_passed', $qaCols) ? 'is_passed' : '0');
    $quizCols  = $pdo->query("SHOW COLUMNS FROM quizzes")->fetchAll(PDO::FETCH_COLUMN);
    $qPubCond  = in_array('is_published', $quizCols) ? 'q.is_published=1' : (in_array('status', $quizCols) ? "q.status='published'" : '1=1');
    $qPassScoreCol = in_array('pass_percent', $quizCols) ? 'q.pass_percent' : (in_array('pass_score', $quizCols) ? 'q.pass_score' : '60');
    $qTitleExpr = in_array('title_ar', $quizCols) ? "COALESCE(q.title_ar, q.title)" : 'q.title';

    try {
        $quizStmt = $pdo->prepare("SELECT q.id, $qTitleExpr AS title_ar, $qPassScoreCol AS pass_score,
            MAX($qScoreCol) AS best_score,
            MAX($qPassCol) AS is_passed,
            COUNT(qa.id) AS attempts
            FROM quizzes q
            LEFT JOIN quiz_attempts qa ON qa.quiz_id=q.id AND qa.student_id=?
            WHERE q.course_id=? AND ($qPubCond)
            GROUP BY q.id ORDER BY q.id");
        $quizStmt->execute([$me, $courseId]);
        $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $quizzes = [];
    }

    // Assignments (may not exist)
    $assignments = [];
    try {
        $pdo->query("SELECT 1 FROM assignments LIMIT 1");
        $subCols = $pdo->query("SHOW COLUMNS FROM assignment_submissions")->fetchAll(PDO::FETCH_COLUMN);
        $assignStmt = $pdo->prepare("SELECT a.id, COALESCE(a.title_ar, a.title) AS title_ar, a.due_date,
            sub.id AS submission_id, sub.submitted_at,
            " . (in_array('grade', $subCols) ? 'sub.grade' : 'NULL AS grade') . "
            FROM assignments a
            LEFT JOIN assignment_submissions sub ON sub.assignment_id=a.id AND sub.student_id=?
            WHERE a.course_id=? ORDER BY a.due_date");
        $assignStmt->execute([$me, $courseId]);
        $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    student_json(200, [
        'success'           => true,
        'enrollment'        => [
            'status'      => $enrollment['status'],
            'enrolled_at' => $enrollment['enrolled_at'] ?? $enrollment['created_at'] ?? null,
            'expires_at'  => $enrollment['expires_at'] ?? null,
        ],
        'overall_progress'  => $avgProgress,
        'completed_lessons' => $completedCount,
        'total_lessons'     => $totalLessons,
        'lessons'           => $lessons,
        'quizzes'           => $quizzes,
        'assignments'       => $assignments,
    ]);
}

/* UPDATE LESSON PROGRESS */
if ($action === 'update_lesson') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $lessonId = (int)($input['lesson_id'] ?? 0);
    $percent  = max(0, min(100, (int)($input['progress_percent'] ?? $input['watch_percent'] ?? 0)));

    if ($lessonId <= 0) student_json(400, ['success' => false, 'message' => 'lesson_id مطلوب']);

    $ls = $pdo->prepare('SELECT course_id FROM lessons WHERE id=?');
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) student_json(404, ['success' => false, 'message' => 'الدرس غير موجود']);

    $enr = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND course_id=? AND status IN ('active','completed') $accessCond");
    $enr->execute([$me, $lesson['course_id']]);
    if (!$enr->fetch()) student_json(403, ['success' => false, 'message' => 'غير مسجل في هذا الكورس']);

    $isCompleted = $percent >= 90;
    $newStatus   = $isCompleted ? 'completed' : ($percent > 0 ? 'in_progress' : 'not_started');

    // Build adaptive INSERT
    $cols   = ['student_id', 'course_id', 'lesson_id'];
    $vals   = ['?', '?', '?'];
    $params = [$me, $lesson['course_id'], $lessonId];
    $upd    = [];
    if ($hasWatchPct)  { $cols[] = 'watch_percent';  $vals[] = '?'; $params[] = $percent; $upd[] = 'watch_percent=GREATEST(watch_percent, VALUES(watch_percent))'; }
    if ($hasProgPct)   { $cols[] = 'progress_percent'; $vals[] = '?'; $params[] = $percent; $upd[] = 'progress_percent=GREATEST(progress_percent, VALUES(progress_percent))'; }
    if ($hasStatus)    { $cols[] = 'status'; $vals[] = '?'; $params[] = $newStatus; $upd[] = 'status=IF(status=\'completed\', status, VALUES(status))'; }
    if ($hasCompAt)    { $cols[] = 'completed_at'; $vals[] = $isCompleted ? 'NOW()' : 'NULL'; if ($isCompleted) {} $upd[] = 'completed_at=COALESCE(completed_at, VALUES(completed_at))'; }
    if ($hasUpdAt)     { $cols[] = 'updated_at'; $vals[] = 'NOW()'; $upd[] = 'updated_at=NOW()'; }

    if (empty($upd)) { $upd = ['student_id=student_id']; }

    $colStr = implode(',', $cols);
    $valStr = implode(',', $vals);
    $updStr = implode(',', $upd);

    $pdo->prepare("INSERT INTO lesson_progress ($colStr) VALUES ($valStr) ON DUPLICATE KEY UPDATE $updStr")
        ->execute($params);

    // Auto-complete enrollment if 100%
    if ($isCompleted) {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=? AND ($lStatusExpr)");
        $totalStmt->execute([$lesson['course_id']]);
        $total = (int)$totalStmt->fetchColumn();

        $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress lp
            JOIN lessons l ON l.id=lp.lesson_id
            WHERE lp.student_id=? AND l.course_id=? AND ($completedExpr)");
        $doneStmt->execute([$me, $lesson['course_id']]);
        $done = (int)$doneStmt->fetchColumn();

        if ($total > 0 && $done >= $total) {
            $pdo->prepare("UPDATE enrollments SET status='completed' WHERE student_id=? AND course_id=? AND status='active'")
                ->execute([$me, $lesson['course_id']]);
        }
    }

    student_json(200, ['success' => true, 'watch_percent' => $percent, 'status' => $newStatus]);
}

/* SUMMARY */
if ($action === 'summary') {
    $cCols = $pdo->query("SHOW COLUMNS FROM courses")->fetchAll(PDO::FETCH_COLUMN);
    $cTitleExpr = in_array('title_ar', $cCols) ? "COALESCE(c.title_ar, c.title)" : 'c.title';

    $stmt = $pdo->prepare("SELECT
        c.id AS course_id, $cTitleExpr AS title_ar,
        COUNT(DISTINCT l.id) AS total_lessons,
        COUNT(DISTINCT CASE WHEN ($completedExpr) THEN lp.lesson_id END) AS completed_lessons,
        COALESCE(ROUND(AVG($progressExpr), 0), 0) AS overall_progress,
        e.created_at AS enrolled_at, e.expires_at
        FROM enrollments e
        JOIN courses c ON c.id=e.course_id
        LEFT JOIN lessons l ON l.course_id=c.id AND ($lStatusExpr)
        LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=?
        WHERE e.student_id=? AND e.status IN ('active','completed') $accessCond
        GROUP BY e.id ORDER BY overall_progress DESC");
    $stmt->execute([$me, $me]);
    student_json(200, ['success' => true, 'courses' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

student_json(400, ['success' => false, 'message' => 'action غير معروف']);
