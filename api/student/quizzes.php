<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/student_guard.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
$me     = (int)$currentStudent['id'];

// Detect DB column names adaptively
static $qaCols = null, $quizCols = null, $qCols = null, $optCols = null;
if (!$qaCols) {
    $qaCols   = $pdo->query("SHOW COLUMNS FROM quiz_attempts")->fetchAll(PDO::FETCH_COLUMN);
    $quizCols = $pdo->query("SHOW COLUMNS FROM quizzes")->fetchAll(PDO::FETCH_COLUMN);
    $qCols    = $pdo->query("SHOW COLUMNS FROM questions")->fetchAll(PDO::FETCH_COLUMN);
    $optCols  = $pdo->query("SHOW COLUMNS FROM quiz_options")->fetchAll(PDO::FETCH_COLUMN);
}

// Adaptive column helpers
$scoreCol  = in_array('score_percent', $qaCols) ? 'score_percent' : (in_array('percent_score', $qaCols) ? 'percent_score' : '0');
$passedCol = in_array('passed', $qaCols) ? 'passed' : (in_array('is_passed', $qaCols) ? 'is_passed' : '0');
$statusCol = in_array('status', $qaCols) ? "qa.status" : "'submitted'";
$quizPublished = in_array('is_published', $quizCols) ? "q.is_published=1" : (in_array('status', $quizCols) ? "q.status='published'" : "1=1");
$coursePubCol  = in_array('title_ar', array_keys($pdo->query("SHOW COLUMNS FROM courses")->fetchAll(PDO::FETCH_ASSOC))) ? 'c.title_ar' : 'COALESCE(c.title_ar, c.title)';
$quizTitleCol  = in_array('title_ar', $quizCols) ? "COALESCE(q.title_ar, q.title)" : "q.title";
$qTimeCol      = in_array('time_limit_minutes', $quizCols) ? 'q.time_limit_minutes' : (in_array('duration_minutes', $quizCols) ? 'q.duration_minutes' : '0');
$qPassCol      = in_array('pass_percent', $quizCols) ? 'q.pass_percent' : (in_array('pass_score', $quizCols) ? 'q.pass_score' : '60');
$qMaxAtt       = in_array('max_attempts', $quizCols) ? 'q.max_attempts' : '3';
$qTotalPts     = in_array('total_points', $quizCols) ? 'q.total_points' : '0';
$qTextCol      = in_array('text_ar', $qCols) ? "COALESCE(q2.text_ar, q2.question_text, q2.question)" :
                 (in_array('question_text', $qCols) ? 'q2.question_text' : 'q2.question');
$qTypeCol      = in_array('type', $qCols) ? 'q2.type' : (in_array('question_type', $qCols) ? 'q2.question_type' : "'mcq'");
$optTextCol    = in_array('text_ar', $optCols) ? "COALESCE(opt.text_ar, opt.option_text, opt.text)" :
                 (in_array('option_text', $optCols) ? 'opt.option_text' : 'opt.text');
$accessCol     = in_array('access_status', $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN))
                 ? "AND (e.access_status='granted' OR e.access_status IS NULL)" : '';

/* LIST QUIZZES */
if ($action === 'list') {
    // Get enrolled course IDs
    $enrCols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN);
    $accFilter = in_array('access_status', $enrCols) ? "AND (access_status='granted' OR access_status IS NULL)" : "";
    $enrolled = $pdo->prepare("SELECT course_id FROM enrollments WHERE student_id=? AND status IN ('active','completed') $accFilter");
    $enrolled->execute([$me]);
    $courseIds = $enrolled->fetchAll(PDO::FETCH_COLUMN);

    if (empty($courseIds)) {
        student_json(200, ['success' => true, 'quizzes' => []]);
    }

    $inPlaces = implode(',', array_fill(0, count($courseIds), '?'));

    $stmt = $pdo->prepare("SELECT
        q.id,
        COALESCE(q.title_ar, q.title) AS title_ar,
        COALESCE(q.title_en, q.title) AS title_en,
        $qTimeCol AS duration_minutes,
        $qPassCol AS pass_percent,
        $qMaxAtt AS max_attempts,
        $qTotalPts AS total_points,
        q.course_id,
        COALESCE(c.title_ar, c.title) AS course_title,
        (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=?) AS my_attempts,
        (SELECT MAX($scoreCol) FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=?) AS my_best_score,
        (SELECT MAX($passedCol) FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=?) AS my_best_pass
        FROM quizzes q
        JOIN courses c ON c.id=q.course_id
        WHERE q.course_id IN ($inPlaces) AND ($quizPublished)
        ORDER BY q.id DESC");

    $stmt->execute(array_merge([$me, $me, $me], $courseIds));
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add attempts_remaining
    foreach ($quizzes as &$qz) {
        $max = (int)$qz['max_attempts'];
        $done = (int)$qz['my_attempts'];
        $qz['attempts_remaining'] = $max > 0 ? max(0, $max - $done) : null;
    }
    unset($qz);

    student_json(200, ['success' => true, 'quizzes' => $quizzes]);
}

/* START QUIZ */
if ($action === 'start') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? $_GET;
    $quizId = (int)($input['quiz_id'] ?? 0);
    if ($quizId <= 0) student_json(400, ['success' => false, 'message' => 'quiz_id مطلوب']);

    $quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id=? AND ($quizPublished)");
    $quiz->execute([$quizId]);
    $q = $quiz->fetch(PDO::FETCH_ASSOC);
    if (!$q) student_json(404, ['success' => false, 'message' => 'الاختبار غير موجود']);

    // Check enrollment
    $enr = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND course_id=? AND status IN ('active','completed') $accessCol");
    $enr->execute([$me, $q['course_id']]);
    if (!$enr->fetch()) student_json(403, ['success' => false, 'message' => 'غير مسجل في هذا الكورس']);

    // Check attempts
    $maxAtt = (int)($q['max_attempts'] ?? 3);
    if ($maxAtt > 0) {
        $attCheck = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE student_id=? AND quiz_id=? AND $statusCol IN ('completed','submitted','graded','passed','failed')");
        $attCheck->execute([$me, $quizId]);
        if ((int)$attCheck->fetchColumn() >= $maxAtt) {
            student_json(403, ['success' => false, 'message' => 'استنفدت جميع المحاولات المسموحة']);
        }
    }

    // Resume or create attempt
    $inProg = $pdo->prepare("SELECT * FROM quiz_attempts WHERE student_id=? AND quiz_id=? AND $statusCol='in_progress' ORDER BY id DESC LIMIT 1");
    $inProg->execute([$me, $quizId]);
    $existingAttempt = $inProg->fetch(PDO::FETCH_ASSOC);

    if ($existingAttempt) {
        $attemptId = $existingAttempt['id'];
        $startedAt = $existingAttempt['started_at'] ?? date('Y-m-d H:i:s');
    } else {
        $ins = $pdo->prepare("INSERT INTO quiz_attempts (student_id, quiz_id, status, started_at, created_at) VALUES (?,?,'in_progress',NOW(),NOW())");
        $ins->execute([$me, $quizId]);
        $attemptId = (int)$pdo->lastInsertId();
        $startedAt = date('Y-m-d H:i:s');
    }

    // Get questions - NEVER return is_correct
    $shuffle = !empty($q['shuffle_questions']) ? 'RAND()' : (in_array('sort_order', $qCols) ? 'sort_order, id' : 'id');
    $qStmt = $pdo->prepare("SELECT id,
        $qTextCol AS text_ar,
        $qTypeCol AS type,
        " . (in_array('points', $qCols) ? 'points' : '1') . " AS points
        FROM questions WHERE quiz_id=? ORDER BY $shuffle");
    $qStmt->execute([$quizId]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $optShuffle = !empty($q['shuffle_options']) ? 'RAND()' : (in_array('sort_order', $optCols) ? 'sort_order, id' : 'id');
    foreach ($questions as &$question) {
        $qType = $question['type'] ?? 'mcq';
        if (in_array($qType, ['mcq', 'true_false', 'multiple_choice'], true)) {
            $optStmt = $pdo->prepare("SELECT id, $optTextCol AS text_ar FROM quiz_options WHERE question_id=? ORDER BY $optShuffle");
            $optStmt->execute([$question['id']]);
            $question['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
            // is_correct intentionally NOT returned
        }
    }
    unset($question);

    $timeLimitMins = (int)($q['time_limit_minutes'] ?? $q['duration_minutes'] ?? 0);
    $timeRemaining = $timeLimitMins > 0
        ? max(0, $timeLimitMins * 60 - (time() - strtotime($startedAt)))
        : null;

    student_json(200, [
        'success'    => true,
        'attempt_id' => $attemptId,
        'quiz' => [
            'id'               => $q['id'],
            'title_ar'         => $q['title_ar'] ?? $q['title'] ?? '',
            'title_en'         => $q['title_en'] ?? $q['title'] ?? '',
            'duration_minutes' => $timeLimitMins,
            'pass_percent'     => $q['pass_percent'] ?? $q['pass_score'] ?? 60,
            'attempt_id'       => $attemptId,
            'questions'        => $questions,
        ],
        'time_remaining_secs' => $timeRemaining,
        'started_at'          => $startedAt,
    ]);
}

/* SUBMIT QUIZ */
if ($action === 'submit') {
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $attemptId = (int)($input['attempt_id'] ?? 0);
    $answers   = (array)($input['answers'] ?? []);

    if ($attemptId <= 0) student_json(400, ['success' => false, 'message' => 'attempt_id مطلوب']);

    $att = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id=? AND student_id=? AND $statusCol='in_progress'");
    $att->execute([$attemptId, $me]);
    $attempt = $att->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) student_json(404, ['success' => false, 'message' => 'المحاولة غير موجودة أو منتهية']);

    $quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id=?");
    $quiz->execute([$attempt['quiz_id']]);
    $q = $quiz->fetch(PDO::FETCH_ASSOC);

    // Grade
    $allQStmt = $pdo->prepare("SELECT id, " . (in_array('points', $qCols) ? 'points' : '1 AS points') . ",
        $qTypeCol AS type FROM questions WHERE quiz_id=?");
    $allQStmt->execute([$attempt['quiz_id']]);
    $allQuestions = $allQStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalScore  = 0.0;
    $totalPoints = 0.0;

    $pdo->beginTransaction();
    try {
        // Check quiz_answers columns
        $aaCols = $pdo->query("SHOW COLUMNS FROM quiz_answers")->fetchAll(PDO::FETCH_COLUMN);
        $hasAnswerText = in_array('answer_text', $aaCols);
        $hasSelOpt     = in_array('selected_option_id', $aaCols);
        $hasIsCorrect  = in_array('is_correct', $aaCols);
        $hasPoints     = in_array('points_earned', $aaCols);

        foreach ($allQuestions as $question) {
            $qId     = (int)$question['id'];
            $qType   = $question['type'] ?? 'mcq';
            $qPoints = (float)($question['points'] ?? 1);
            $totalPoints += $qPoints;

            $studentAnswer = null;
            foreach ($answers as $a) {
                if ((int)($a['question_id'] ?? 0) === $qId) {
                    $studentAnswer = $a['answer'] ?? null;
                    break;
                }
            }

            $isCorrect    = 0;
            $pointsEarned = 0.0;
            $selectedOpt  = null;
            $answerText   = null;

            if (in_array($qType, ['mcq', 'true_false', 'multiple_choice'], true)) {
                $selectedOpt = (int)($studentAnswer ?? 0);
                if ($selectedOpt > 0) {
                    $optCheck = $pdo->prepare("SELECT is_correct FROM quiz_options WHERE id=? AND question_id=?");
                    $optCheck->execute([$selectedOpt, $qId]);
                    $opt = $optCheck->fetch(PDO::FETCH_ASSOC);
                    if ($opt && $opt['is_correct']) {
                        $isCorrect    = 1;
                        $pointsEarned = $qPoints;
                    }
                }
            } else {
                $answerText = trim((string)($studentAnswer ?? ''));
                // Short answer: manual grading
            }

            $totalScore += $pointsEarned;

            // Build dynamic INSERT
            $cols   = ['attempt_id', 'question_id'];
            $vals   = ['?', '?'];
            $params = [$attemptId, $qId];
            if ($hasSelOpt)   { $cols[] = 'selected_option_id'; $vals[] = '?'; $params[] = $selectedOpt ?: null; }
            if ($hasAnswerText && $answerText !== null) { $cols[] = 'answer_text'; $vals[] = '?'; $params[] = $answerText; }
            if ($hasIsCorrect) { $cols[] = 'is_correct'; $vals[] = '?'; $params[] = $isCorrect; }
            if ($hasPoints)    { $cols[] = 'points_earned'; $vals[] = '?'; $params[] = $pointsEarned; }
            $cols[] = 'created_at'; $vals[] = 'NOW()';

            $colStr = implode(',', $cols);
            $valStr = implode(',', $vals);
            $upd    = $hasIsCorrect ? "is_correct=VALUES(is_correct)" : "attempt_id=attempt_id";
            if ($hasPoints) $upd .= ", points_earned=VALUES(points_earned)";

            $pdo->prepare("INSERT INTO quiz_answers ($colStr) VALUES ($valStr) ON DUPLICATE KEY UPDATE $upd")
                ->execute($params);
        }

        $scorePercent = $totalPoints > 0 ? round($totalScore / $totalPoints * 100, 2) : 0;
        $passThreshold = (float)($q['pass_percent'] ?? $q['pass_score'] ?? 60);
        $isPassed      = $scorePercent >= $passThreshold ? 1 : 0;
        $newStatus     = $isPassed ? 'passed' : 'failed';

        // Build dynamic UPDATE for quiz_attempts
        $updCols   = ["status='$newStatus'", "submitted_at=NOW()", "updated_at=NOW()"];
        $updParams = [];
        if (in_array('score', $qaCols))         { $updCols[] = 'score=?';          $updParams[] = $totalScore; }
        if (in_array('score_percent', $qaCols)) { $updCols[] = 'score_percent=?';   $updParams[] = $scorePercent; }
        if (in_array('percent_score', $qaCols)) { $updCols[] = 'percent_score=?';   $updParams[] = $scorePercent; }
        if (in_array('passed', $qaCols))        { $updCols[] = 'passed=?';          $updParams[] = $isPassed; }
        if (in_array('is_passed', $qaCols))     { $updCols[] = 'is_passed=?';       $updParams[] = $isPassed; }
        if (in_array('total_points', $qaCols))  { $updCols[] = 'total_points=?';    $updParams[] = $totalPoints; }
        $updParams[] = $attemptId;

        $pdo->prepare("UPDATE quiz_attempts SET " . implode(',', $updCols) . " WHERE id=?")
            ->execute($updParams);

        // Try to send notification
        try {
            $notifTitle = $isPassed ? 'نجحت في الاختبار! 🎉' : 'نتيجة الاختبار';
            $notifBody  = ($q['title_ar'] ?? $q['title'] ?? 'الاختبار') . " — نتيجتك: {$scorePercent}%";
            $notifCols  = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
            $bodyField  = in_array('body', $notifCols) ? 'body' : 'message';
            $pdo->prepare("INSERT INTO notifications (user_id, title, {$bodyField}, type, created_at) VALUES (?,?,?,'info',NOW())")
                ->execute([$me, $notifTitle, $notifBody]);
        } catch (Exception $e) {}

        $pdo->commit();
        student_json(200, [
            'success'       => true,
            'score'         => $totalScore,
            'score_percent' => $scorePercent,
            'total_points'  => $totalPoints,
            'passed'        => (bool)$isPassed,
            'pass_threshold'=> $passThreshold,
            'message'       => $isPassed ? 'تهانينا! اجتزت الاختبار بنجاح.' : 'لم تجتز الاختبار. يمكنك المحاولة مرة أخرى.',
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Quiz submit] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        student_json(500, ['success' => false, 'message' => 'خطأ في الخادم: ' . $e->getMessage()]);
    }
}

/* MY ATTEMPTS */
if ($action === 'my_attempts') {
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    $where  = ['qa.student_id=?'];
    $params = [$me];
    if ($quizId > 0) { $where[] = 'qa.quiz_id=?'; $params[] = $quizId; }
    $wClause = implode(' AND ', $where);

    $qaDateCols = in_array('started_at', $qaCols) ? 'qa.started_at' : (in_array('created_at', $qaCols) ? 'qa.created_at' : 'NOW() AS started_at');
    $stmt = $pdo->prepare("SELECT
        qa.id, qa.quiz_id, {$qaDateCols},
        $scoreCol AS score_percent,
        $passedCol AS passed,
        $statusCol AS status,
        $quizTitleCol AS quiz_title,
        COALESCE(c.title_ar, c.title) AS course_title,
        $qTotalPts AS total_points
        FROM quiz_attempts qa
        JOIN quizzes q ON q.id=qa.quiz_id
        JOIN courses c ON c.id=q.course_id
        WHERE $wClause
        ORDER BY qa.id DESC
        LIMIT 50");
    $stmt->execute($params);
    student_json(200, ['success' => true, 'attempts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

student_json(400, ['success' => false, 'message' => 'action غير معروف']);
