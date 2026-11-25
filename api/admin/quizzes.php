<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

/* ─── LIST QUIZZES ─── */
if ($action === 'list') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    $where = ['1=1']; $p = [];
    if ($courseId > 0) { $where[] = 'q.course_id = ?'; $p[] = $courseId; }

    $stmt = $pdo->prepare("
        SELECT q.*, c.title_ar AS course_title,
               (SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) AS question_count,
               (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=q.id) AS attempt_count
        FROM quizzes q
        LEFT JOIN courses c ON c.id = q.course_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY q.id DESC
    ");
    $stmt->execute($p);
    admin_json(200, ['success' => true, 'quizzes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ─── GET QUIZ DETAIL (with questions) ─── */
if ($action === 'get') {
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    if ($quizId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الاختبار مطلوب.']);

    $quiz = $pdo->prepare('SELECT * FROM quizzes WHERE id=?');
    $quiz->execute([$quizId]);
    $q = $quiz->fetch();
    if (!$q) admin_json(404, ['success' => false, 'message' => 'الاختبار غير موجود.']);

    // Questions with options (admin sees all including correct)
    $qStmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id=? ORDER BY sort_order, id');
    $qStmt->execute([$quizId]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $optStmt = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id=? ORDER BY sort_order, id');
    foreach ($questions as &$question) {
        $optStmt->execute([$question['id']]);
        $question['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($question);

    $q['questions'] = $questions;
    admin_json(200, ['success' => true, 'quiz' => $q]);
}

/* ─── CREATE QUIZ ─── */
if ($action === 'create_quiz') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $courseId       = (int)($input['course_id'] ?? 0);
    $titleAr        = trim((string)($input['title_ar'] ?? ''));
    $titleEn        = trim((string)($input['title_en'] ?? ''));
    $descAr         = trim((string)($input['description_ar'] ?? ''));
    $timeLimitMins  = (int)($input['time_limit_minutes'] ?? 0);
    $maxAttempts    = (int)($input['max_attempts'] ?? 1);
    $passScore      = (float)($input['pass_score'] ?? 60);
    $shuffle        = (int)(bool)($input['shuffle_questions'] ?? 0);
    $shuffleOpts    = (int)(bool)($input['shuffle_options'] ?? 0);
    $showAnswers    = (int)(bool)($input['show_answers_after_submit'] ?? 0);
    $isPublished    = (int)(bool)($input['is_published'] ?? 0);

    if (!$titleAr || $courseId <= 0) admin_json(400, ['success' => false, 'message' => 'عنوان الاختبار والكورس مطلوبان.']);

    $ins = $pdo->prepare("INSERT INTO quizzes
        (course_id, title_ar, title_en, description_ar, time_limit_minutes, max_attempts,
         pass_score, shuffle_questions, shuffle_options, show_answers_after_submit,
         is_published, created_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $ins->execute([$courseId, $titleAr, $titleEn, $descAr, $timeLimitMins ?: null, $maxAttempts,
        $passScore, $shuffle, $shuffleOpts, $showAnswers, $isPublished, $currentUser['id']]);

    admin_json(200, ['success' => true, 'quiz_id' => (int)$pdo->lastInsertId(), 'message' => 'تم إنشاء الاختبار.']);
}

/* ─── UPDATE QUIZ ─── */
if ($action === 'update_quiz') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $quizId = (int)($input['quiz_id'] ?? 0);
    if ($quizId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الاختبار مطلوب.']);

    $pdo->prepare("UPDATE quizzes SET
        title_ar=?, title_en=?, description_ar=?, time_limit_minutes=?, max_attempts=?,
        pass_score=?, shuffle_questions=?, shuffle_options=?, show_answers_after_submit=?,
        is_published=?, updated_at=NOW() WHERE id=?")
        ->execute([
            trim((string)($input['title_ar'] ?? '')),
            trim((string)($input['title_en'] ?? '')),
            trim((string)($input['description_ar'] ?? '')),
            (int)($input['time_limit_minutes'] ?? 0) ?: null,
            (int)($input['max_attempts'] ?? 1),
            (float)($input['pass_score'] ?? 60),
            (int)(bool)($input['shuffle_questions'] ?? 0),
            (int)(bool)($input['shuffle_options'] ?? 0),
            (int)(bool)($input['show_answers_after_submit'] ?? 0),
            (int)(bool)($input['is_published'] ?? 0),
            $quizId
        ]);

    admin_json(200, ['success' => true, 'message' => 'تم تحديث الاختبار.']);
}

/* ─── DELETE QUIZ ─── */
if ($action === 'delete_quiz') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $quizId = (int)($input['quiz_id'] ?? 0);
    if ($quizId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الاختبار مطلوب.']);

    // Check no attempts
    $check = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=?');
    $check->execute([$quizId]);
    if ((int)$check->fetchColumn() > 0) {
        admin_json(409, ['success' => false, 'message' => 'لا يمكن حذف اختبار له محاولات مسجلة.']);
    }

    $pdo->prepare('DELETE FROM quiz_options WHERE question_id IN (SELECT id FROM questions WHERE quiz_id=?)')->execute([$quizId]);
    $pdo->prepare('DELETE FROM questions WHERE quiz_id=?')->execute([$quizId]);
    $pdo->prepare('DELETE FROM quizzes WHERE id=?')->execute([$quizId]);

    admin_json(200, ['success' => true, 'message' => 'تم حذف الاختبار.']);
}

/* ─── ADD QUESTION ─── */
if ($action === 'add_question') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $quizId = (int)($input['quiz_id'] ?? 0);
    $text   = trim((string)($input['question_text'] ?? ''));
    $type   = trim((string)($input['question_type'] ?? 'mcq'));
    $points = (float)($input['points'] ?? 1);
    $expl   = trim((string)($input['explanation'] ?? ''));
    $opts   = (array)($input['options'] ?? []);

    if ($quizId <= 0 || !$text) admin_json(400, ['success' => false, 'message' => 'بيانات السؤال ناقصة.']);
    if (!in_array($type, ['mcq', 'true_false', 'short_answer'], true)) admin_json(400, ['success' => false, 'message' => 'نوع السؤال غير صالح.']);

    // Get max sort_order
    $maxSort = $pdo->prepare('SELECT MAX(sort_order) FROM questions WHERE quiz_id=?');
    $maxSort->execute([$quizId]);
    $sortOrder = ((int)$maxSort->fetchColumn()) + 1;

    $ins = $pdo->prepare("INSERT INTO questions (quiz_id, question_text, question_type, points, explanation, sort_order, created_at)
        VALUES (?,?,?,?,?,?,NOW())");
    $ins->execute([$quizId, $text, $type, $points, $expl, $sortOrder]);
    $questionId = (int)$pdo->lastInsertId();

    // Add options (for mcq and true_false)
    if (in_array($type, ['mcq', 'true_false'], true) && !empty($opts)) {
        $optIns = $pdo->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?,?,?,?)");
        $sortO = 1;
        foreach ($opts as $opt) {
            $optText    = trim((string)($opt['text'] ?? ''));
            $isCorrect  = (int)(bool)($opt['is_correct'] ?? false);
            if ($optText !== '') {
                $optIns->execute([$questionId, $optText, $isCorrect, $sortO++]);
            }
        }
    }

    // Update quiz total points
    $pdo->prepare("UPDATE quizzes SET total_points=(SELECT SUM(points) FROM questions WHERE quiz_id=?), updated_at=NOW() WHERE id=?")
        ->execute([$quizId, $quizId]);

    admin_json(200, ['success' => true, 'question_id' => $questionId, 'message' => 'تم إضافة السؤال.']);
}

/* ─── UPDATE QUESTION ─── */
if ($action === 'update_question') {
    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $questionId = (int)($input['question_id'] ?? 0);
    $opts       = (array)($input['options'] ?? []);

    if ($questionId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف السؤال مطلوب.']);

    $pdo->prepare("UPDATE questions SET question_text=?, points=?, explanation=?, updated_at=NOW() WHERE id=?")
        ->execute([trim((string)($input['question_text'] ?? '')), (float)($input['points'] ?? 1), trim((string)($input['explanation'] ?? '')), $questionId]);

    // Replace options
    if (!empty($opts)) {
        $pdo->prepare('DELETE FROM quiz_options WHERE question_id=?')->execute([$questionId]);
        $optIns = $pdo->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?,?,?,?)");
        $s = 1;
        foreach ($opts as $opt) {
            $t = trim((string)($opt['text'] ?? ''));
            if ($t !== '') $optIns->execute([$questionId, $t, (int)(bool)($opt['is_correct'] ?? false), $s++]);
        }
    }

    // Update quiz total_points
    $q = $pdo->prepare('SELECT quiz_id FROM questions WHERE id=?');
    $q->execute([$questionId]);
    $row = $q->fetch();
    if ($row) {
        $pdo->prepare("UPDATE quizzes SET total_points=(SELECT SUM(points) FROM questions WHERE quiz_id=?), updated_at=NOW() WHERE id=?")
            ->execute([$row['quiz_id'], $row['quiz_id']]);
    }

    admin_json(200, ['success' => true, 'message' => 'تم تحديث السؤال.']);
}

/* ─── DELETE QUESTION ─── */
if ($action === 'delete_question') {
    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $questionId = (int)($input['question_id'] ?? 0);
    if ($questionId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف السؤال مطلوب.']);

    $q = $pdo->prepare('SELECT quiz_id FROM questions WHERE id=?');
    $q->execute([$questionId]);
    $row = $q->fetch();

    $pdo->prepare('DELETE FROM quiz_options WHERE question_id=?')->execute([$questionId]);
    $pdo->prepare('DELETE FROM questions WHERE id=?')->execute([$questionId]);

    if ($row) {
        $pdo->prepare("UPDATE quizzes SET total_points=(SELECT COALESCE(SUM(points),0) FROM questions WHERE quiz_id=?), updated_at=NOW() WHERE id=?")
            ->execute([$row['quiz_id'], $row['quiz_id']]);
    }

    admin_json(200, ['success' => true, 'message' => 'تم حذف السؤال.']);
}

/* ─── LIST ATTEMPTS ─── */
if ($action === 'list_attempts') {
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20; $offset = ($page - 1) * $limit;
    if ($quizId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الاختبار مطلوب.']);

    $count = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=?');
    $count->execute([$quizId]);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT qa.*, u.full_name AS student_name, u.email AS student_email
        FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id
        WHERE qa.quiz_id=? ORDER BY qa.id DESC LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$quizId]);

    admin_json(200, ['success' => true, 'attempts' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => ['total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $limit)]]);
}

/* ─── QUIZ REPORT ─── */
if ($action === 'report') {
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    if ($quizId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الاختبار مطلوب.']);

    $quiz = $pdo->prepare('SELECT * FROM quizzes WHERE id=?');
    $quiz->execute([$quizId]);
    $q = $quiz->fetch();
    if (!$q) admin_json(404, ['success' => false, 'message' => 'الاختبار غير موجود.']);

    $stats = $pdo->prepare("
        SELECT COUNT(*) AS total_attempts,
               SUM(CASE WHEN is_passed=1 THEN 1 ELSE 0 END) AS passed,
               ROUND(AVG(score),2) AS avg_score,
               MAX(score) AS max_score, MIN(score) AS min_score
        FROM quiz_attempts WHERE quiz_id=? AND status='completed'
    ");
    $stats->execute([$quizId]);
    $report = $stats->fetch();
    $report['pass_rate'] = $report['total_attempts'] > 0
        ? round($report['passed'] / $report['total_attempts'] * 100, 1) : 0;

    admin_json(200, ['success' => true, 'quiz' => $q, 'report' => $report]);
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير معروف.']);
