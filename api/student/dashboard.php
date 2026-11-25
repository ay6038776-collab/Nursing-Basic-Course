<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';
require_once __DIR__ . '/../config/settings_helper.php';

$uid = (int) $currentStudent['id'];

// Auto-heal missing DB columns on the fly
try {
    $lpCols = $pdo->query("SHOW COLUMNS FROM lesson_progress")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('student_id', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN student_id INT UNSIGNED NULL");
        if (in_array('enrollment_id', $lpCols)) {
            $pdo->exec("UPDATE lesson_progress lp JOIN enrollments e ON e.id = lp.enrollment_id SET lp.student_id = e.student_id");
        }
    }
    if (!in_array('course_id', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN course_id INT UNSIGNED NULL");
        if (in_array('enrollment_id', $lpCols)) {
            $pdo->exec("UPDATE lesson_progress lp JOIN enrollments e ON e.id = lp.enrollment_id SET lp.course_id = e.course_id");
        }
    }
    if (!in_array('status', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started'");
    }
    if (!in_array('watch_percent', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN watch_percent TINYINT UNSIGNED NOT NULL DEFAULT 0");
    }
} catch (Throwable $ignored) {}

try {
    $eCols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('created_at', $eCols)) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('enrolled_at', $eCols)) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN enrolled_at TIMESTAMP NULL DEFAULT NULL");
        $pdo->exec("UPDATE enrollments SET enrolled_at = created_at WHERE enrolled_at IS NULL");
    }
    if (!in_array('access_status', $eCols)) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN access_status ENUM('granted','restricted','blocked') NOT NULL DEFAULT 'granted'");
    }
} catch (Throwable $ignored) {}

try {
    $nCols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_read', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
        if (in_array('read_at', $nCols)) {
            $pdo->exec("UPDATE notifications SET is_read = IF(read_at IS NOT NULL, 1, 0) WHERE 1");
        }
    }
    if (!in_array('body', $nCols) && in_array('message', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN body TEXT NULL");
        $pdo->exec("UPDATE notifications SET body = message WHERE body IS NULL");
    }
} catch (Throwable $ignored) {}

try {
    // 1) Student Profile & Digital Student QR Identifier
    $qrToken = 'EDU-STU-' . $uid . '-' . substr(hash('sha256', (string)$uid . 'EduNursingSalt2026'), 0, 10);

    $studentInfo = [
        'id'         => $uid,
        'full_name'  => $currentStudent['full_name'],
        'email'      => $currentStudent['email'],
        'phone'      => $currentStudent['phone'] ?? '',
        'avatar'     => $currentStudent['avatar'] ?? null,
        'bio'        => $currentStudent['bio'] ?? '',
        'role'       => 'student',
        'qr_token'   => $qrToken,
        'created_at' => $currentStudent['created_at'] ?? date('Y-m-d H:i:s')
    ];

    // 2) Enrolled Courses (Only ACTIVE or COMPLETED enrollments)
    $enrolledCourses = [];
    try {
        $lpCols       = $pdo->query("SHOW COLUMNS FROM lesson_progress")->fetchAll(PDO::FETCH_COLUMN);
        $lpHasWatch   = in_array('watch_percent', $lpCols);
        $lpHasEnrId   = in_array('enrollment_id', $lpCols);
        $lpHasStuId   = in_array('student_id', $lpCols);
        $lCols        = $pdo->query("SHOW COLUMNS FROM course_lessons")->fetchAll(PDO::FETCH_COLUMN);
        $lStatusField = in_array('status', $lCols) ? "l.status='published'" : (in_array('is_published', $lCols) ? "l.is_published=1" : "1=1");
        $lpJoinField  = $lpHasStuId ? 'lp.student_id = e.student_id' : ($lpHasEnrId ? 'lp.enrollment_id = e.id' : '1=0');
        $lpDoneExpr   = in_array('status', $lpCols) ? "lp.status='completed'" : ($lpHasWatch ? 'lp.watch_percent>=90' : '0');
        $lpUpdField   = in_array('updated_at', $lpCols) ? 'lp.updated_at' : 'lp.id';

        $enrCols        = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN);
        $enrHasCreated  = in_array('created_at', $enrCols);
        $enrHasEnrolled = in_array('enrolled_at', $enrCols);
        $enrDateExpr    = $enrHasEnrolled ? 'e.enrolled_at' : ($enrHasCreated ? 'e.created_at' : 'NOW()');
        $enrOrderExpr   = $enrHasCreated ? 'e.created_at' : ($enrHasEnrolled ? 'e.enrolled_at' : 'e.id');

        $lpJoin1 = $lpHasStuId ? 'lp.student_id = e.student_id' : ($lpHasEnrId ? 'lp.enrollment_id = e.id' : '1=0');
        $lpJoin2 = in_array('student_id', $lpCols) ? 'lp2.student_id = e.student_id' : ($lpHasEnrId ? 'lp2.enrollment_id = e.id' : '1=0');
        $lpJoin3 = in_array('student_id', $lpCols) ? 'lp3.student_id = e.student_id' : ($lpHasEnrId ? 'lp3.enrollment_id = e.id' : '1=0');

        $enrolledStmt = $pdo->prepare("
            SELECT c.id, COALESCE(c.title_ar, c.title) AS title, c.title_ar, c.title_en, c.slug, c.thumbnail,
                   c.category, c.level, c.duration_minutes,
                   e.id AS enrollment_id, {$enrDateExpr} AS enrolled_at,
                   e.status AS enrollment_status,
                   u.full_name AS instructor_name,
                   (SELECT COUNT(*) FROM course_lessons l WHERE l.course_id = c.id AND ($lStatusField)) AS total_lessons,
                   (SELECT COUNT(*) FROM lesson_progress lp JOIN course_lessons l ON l.id=lp.lesson_id
                    WHERE $lpJoin1 AND l.course_id = c.id AND ($lpDoneExpr)) AS completed_lessons,
                   (SELECT l2.id FROM lesson_progress lp2 JOIN course_lessons l2 ON l2.id = lp2.lesson_id
                    WHERE $lpJoin2 AND l2.course_id = c.id ORDER BY $lpUpdField DESC LIMIT 1) AS last_lesson_id,
                   (SELECT l3.title FROM lesson_progress lp3 JOIN course_lessons l3 ON l3.id = lp3.lesson_id
                    WHERE $lpJoin3 AND l3.course_id = c.id ORDER BY $lpUpdField DESC LIMIT 1) AS last_lesson_title
            FROM enrollments e
            JOIN courses c ON c.id = e.course_id
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE e.student_id = ? AND e.status IN ('active', 'completed')
            ORDER BY {$enrOrderExpr} DESC
        ");
        $enrolledStmt->execute([$uid]);
        $enrolledCourses = $enrolledStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($enrolledCourses as &$ec) {
            $tot  = max(1, (int)$ec['total_lessons']);
            $comp = (int)$ec['completed_lessons'];
            $ec['progress_percent'] = min(100, (int)round(($comp / $tot) * 100));

            // If no lesson accessed yet, fallback to the first published lesson
            if (empty($ec['last_lesson_id'])) {
                try {
                    $firstLStmt = $pdo->prepare("SELECT id, COALESCE(title_ar, title) AS title FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                    $firstLStmt->execute([$ec['id']]);
                    $firstL = $firstLStmt->fetch(PDO::FETCH_ASSOC);
                    if ($firstL) {
                        $ec['last_lesson_id']    = $firstL['id'];
                        $ec['last_lesson_title'] = $firstL['title'];
                    }
                } catch (PDOException $e) {
                    error_log('[Dashboard first lesson] ' . $e->getMessage());
                }
            }

            // Check if student has submitted a review for this course
            try {
                $revStmt = $pdo->prepare("SELECT rating, comment, status FROM reviews WHERE user_id = ? AND item_type = 'course' AND item_id = ? LIMIT 1");
                $revStmt->execute([$uid, $ec['id']]);
                $rev = $revStmt->fetch(PDO::FETCH_ASSOC);
                $ec['user_review'] = $rev ?: null;
            } catch (PDOException $e) {
                $ec['user_review'] = null;
            }
        }
        unset($ec);
    } catch (PDOException $e) {
        error_log('[Dashboard enrolled courses] ' . $e->getMessage());
    }

    // 3) Available Courses to purchase (Published & NOT yet enrolled)
    $availableCourses = [];
    try {
        $cCols = $pdo->query("SHOW COLUMNS FROM courses")->fetchAll(PDO::FETCH_COLUMN);
        $lCols = $pdo->query("SHOW COLUMNS FROM lessons")->fetchAll(PDO::FETCH_COLUMN);
        $lStatusField = in_array('status', $lCols) ? "l.status='published'" : (in_array('is_published', $lCols) ? "l.is_published=1" : "1=1");
        $cStatusField = in_array('status', $cCols) ? "(c.status = 'published')" : (in_array('is_published', $cCols) ? "(c.is_published = 1)" : "1=1");

        $availStmt = $pdo->prepare("
            SELECT c.id, c.title, COALESCE(c.title_ar, c.title) AS title_ar, c.title_en, c.slug, c.thumbnail, c.description, c.category, c.level, c.duration_minutes, c.price, c.discounted_price,
                   u.full_name AS instructor_name,
                   (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND ($lStatusField)) AS total_lessons
            FROM courses c
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE {$cStatusField}
              AND c.id NOT IN (
                  SELECT course_id FROM enrollments WHERE student_id = ? AND status IN ('active', 'completed')
              )
            ORDER BY c.id DESC LIMIT 12
        ");
        $availStmt->execute([$uid]);
        $availableCourses = $availStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard available courses] ' . $e->getMessage());
    }

    // 4) Upcoming Live Lectures & Zoom Meetings (for enrolled courses)
    $upcomingMeetings = [];
    try {
        $meetingsStmt = $pdo->prepare("
            SELECT m.id, m.course_id, m.title, m.description, m.starts_at, m.ends_at, m.meeting_url, m.status,
                   COALESCE(c.title_ar, c.title) AS course_title, u.full_name AS instructor_name
            FROM live_sessions m
            JOIN courses c ON c.id = m.course_id
            JOIN enrollments e ON e.course_id = c.id
            LEFT JOIN users u ON u.id = m.created_by
            WHERE e.student_id = ? AND e.status IN ('active', 'completed')
              AND m.status IN ('scheduled', 'live')
              AND m.starts_at >= NOW() - INTERVAL 4 HOUR
            ORDER BY m.starts_at ASC LIMIT 6
        ");
        $meetingsStmt->execute([$uid]);
        $upcomingMeetings = $meetingsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard live sessions] ' . $e->getMessage());
    }

    // 5) Available Quizzes & Tests — adaptive column detection
    $availableQuizzes = [];
    try {
        $_qaCols   = $pdo->query("SHOW COLUMNS FROM quiz_attempts")->fetchAll(PDO::FETCH_COLUMN);
        $_scoreCol = in_array('score_percent', $_qaCols)   ? 'score_percent'
                   : (in_array('percent_score', $_qaCols)  ? 'percent_score' : 'NULL');
        $_passExpr = in_array('passed', $_qaCols)          ? 'passed'
                   : (in_array('is_passed', $_qaCols)      ? 'is_passed'
                   : (in_array('status', $_qaCols)         ? "(qa3.status='passed')" : 'NULL'));
        $quizCols = $pdo->query("SHOW COLUMNS FROM quizzes")->fetchAll(PDO::FETCH_COLUMN);
        $qzPubCond = in_array('status', $quizCols) ? "q.status = 'published'"
                   : (in_array('is_published', $quizCols) ? "q.is_published = 1" : "1=1");
        $qzPassCol = in_array('pass_score', $quizCols) ? 'q.pass_score'
                   : (in_array('pass_percent', $quizCols) ? 'q.pass_percent' : '60');
        $qzTimeCol = in_array('time_limit_minutes', $quizCols) ? 'q.time_limit_minutes'
                   : (in_array('duration_minutes', $quizCols) ? 'q.duration_minutes' : '0');

        $quizzesStmt = $pdo->prepare("
            SELECT q.id, q.course_id, COALESCE(q.title_ar, q.title) AS title, q.description,
                   {$qzTimeCol} AS time_limit_minutes, {$qzPassCol} AS pass_percent, q.max_attempts,
                   COALESCE(c.title_ar, c.title) AS course_title,
                   (SELECT COUNT(*) FROM questions qst WHERE qst.quiz_id = q.id) AS questions_count,
                   (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ?) AS attempts_count,
                   (SELECT MAX(qa2.{$_scoreCol}) FROM quiz_attempts qa2 WHERE qa2.quiz_id = q.id AND qa2.student_id = ?) AS best_score,
                   (SELECT {$_passExpr} FROM quiz_attempts qa3 WHERE qa3.quiz_id = q.id AND qa3.student_id = ? ORDER BY qa3.id DESC LIMIT 1) AS last_passed
            FROM quizzes q
            JOIN courses c ON c.id = q.course_id
            JOIN enrollments e ON e.course_id = c.id
            WHERE e.student_id = ? AND e.status IN ('active', 'completed')
              AND {$qzPubCond}
            ORDER BY q.id DESC
        ");
        $quizzesStmt->execute([$uid, $uid, $uid, $uid]);
        $availableQuizzes = $quizzesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard quizzes] ' . $e->getMessage());
    }

    // 6) Quiz Results & Grades History — adaptive columns
    $quizResults = [];
    try {
        if (!isset($_qaCols)) {
            $_qaCols   = $pdo->query("SHOW COLUMNS FROM quiz_attempts")->fetchAll(PDO::FETCH_COLUMN);
            $_scoreCol = in_array('score_percent', $_qaCols)   ? 'score_percent'
                       : (in_array('percent_score', $_qaCols)  ? 'percent_score' : 'NULL');
            $_passExpr = in_array('passed', $_qaCols)          ? 'passed'
                       : (in_array('is_passed', $_qaCols)      ? 'is_passed'
                       : (in_array('status', $_qaCols)         ? "(qa.status='passed')" : 'NULL'));
        }
        $_hasStatus  = in_array('status', $_qaCols);
        $statusWhere = $_hasStatus ? "AND qa.status IN ('submitted','graded')" : '';
        $statusSel   = $_hasStatus ? "qa.status," : "'graded' AS status,";

        $resultsStmt = $pdo->prepare("
            SELECT qa.id, qa.quiz_id, qa.attempt_number, qa.score, qa.max_score,
                   qa.{$_scoreCol} AS percent_score,
                   qa.{$_passExpr} AS passed,
                   {$statusSel}
                   qa.submitted_at,
                   COALESCE(q.title_ar, q.title) AS quiz_title,
                   COALESCE(c.title_ar, c.title) AS course_title
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            JOIN courses c ON c.id = q.course_id
            WHERE qa.student_id = ? {$statusWhere}
            ORDER BY qa.id DESC LIMIT 15
        ");
        $resultsStmt->execute([$uid]);
        $quizResults = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard quiz results] ' . $e->getMessage());
    }

    // 7) Downloadable Study Notes & Digital Books
    $studyNotes = [];
    try {
        $bCols = $pdo->query("SHOW COLUMNS FROM books")->fetchAll(PDO::FETCH_COLUMN);
        $bTitleExpr = in_array('title_ar', $bCols) ? (in_array('title', $bCols) ? 'COALESCE(b.title_ar, b.title)' : 'b.title_ar') : (in_array('title', $bCols) ? 'b.title' : "'كتاب'");
        $bTitleEnExpr = in_array('title_en', $bCols) ? 'b.title_en' : "''";
        $bAuthorExpr = in_array('author', $bCols) ? 'b.author' : "''";
        $bCoverExpr = in_array('cover_image', $bCols) ? 'b.cover_image' : "NULL";
        $bPagesExpr = in_array('pages_count', $bCols) ? 'b.pages_count' : '0';
        $bPriceExpr = in_array('price', $bCols) ? 'b.price' : '0';
        $bDownExpr = in_array('is_downloadable', $bCols) ? 'b.is_downloadable' : '1';
        $bPdfExpr = in_array('pdf_path', $bCols) ? 'b.pdf_path' : "NULL";
        $bStatusCond = in_array('status', $bCols) ? "b.status = 'published'" : "1=1";

        $booksStmt = $pdo->prepare("
            SELECT b.id, {$bTitleExpr} AS title_ar, {$bTitleEnExpr} AS title_en, {$bAuthorExpr} AS author,
                   {$bCoverExpr} AS cover_image, {$bPagesExpr} AS pages_count, {$bPriceExpr} AS price,
                   {$bDownExpr} AS is_downloadable, {$bPdfExpr} AS pdf_path,
                   CASE WHEN ba.id IS NOT NULL OR {$bPriceExpr} = 0 OR {$bPriceExpr} IS NULL THEN 1 ELSE 0 END AS has_access,
                   ba.granted_at
            FROM books b
            LEFT JOIN book_access ba ON ba.book_id = b.id AND ba.student_id = ? AND ba.status = 'active'
            WHERE {$bStatusCond}
            ORDER BY has_access DESC, b.id ASC
        ");
        $booksStmt->execute([$uid]);
        $studyNotes = $booksStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard books] ' . $e->getMessage());
    }

    // 8) Earned Certificates
    $certificates = [];
    try {
        $certsStmt = $pdo->prepare("
            SELECT cert.id, cert.certificate_number, cert.issued_at,
                   cert.student_name_snapshot, cert.course_title_snapshot,
                   c.id AS course_id,
                   COALESCE(c.title_ar, c.title) AS course_title,
                   c.thumbnail AS course_thumb
            FROM certificates cert
            JOIN courses c ON c.id = cert.course_id
            WHERE cert.student_id = ? AND cert.revoked_at IS NULL
            ORDER BY cert.issued_at DESC
        ");
        $certsStmt->execute([$uid]);
        $certificates = $certsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard certificates] ' . $e->getMessage());
    }

    // 9) Notifications
    $notifications = [];
    $unreadCount   = 0;
    try {
        $nCols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
        $nBodyExpr = in_array('body', $nCols) ? 'COALESCE(body, message, "")' : 'message';
        $nCreatedExpr = in_array('created_at', $nCols) ? 'created_at' : 'NOW()';

        $notifStmt = $pdo->prepare("
            SELECT id, type, title, {$nBodyExpr} AS message, read_at, {$nCreatedExpr} AS created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY id DESC LIMIT 20
        ");
        $notifStmt->execute([$uid]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notifications as $n) {
            if (empty($n['read_at'])) $unreadCount++;
        }
    } catch (PDOException $e) {
        error_log('[Dashboard notifications] ' . $e->getMessage());
    }

    // 10) Orders & Payment History
    $orders   = [];
    $payments = [];
    try {
        $ordCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $ordProofExpr = in_array('payment_proof_path', $ordCols) ? 'payment_proof_path' : (in_array('proof_path', $ordCols) ? 'proof_path' : 'NULL');
        $ordTxExpr = in_array('transaction_reference', $ordCols) ? 'transaction_reference' : 'NULL';
        $ordCreatedExpr = in_array('created_at', $ordCols) ? 'created_at' : 'NOW()';

        $ordersStmt = $pdo->prepare("
            SELECT id, order_number, total_amount, currency, payment_method, status,
                   {$ordProofExpr} AS proof_path, {$ordTxExpr} AS transaction_reference, {$ordCreatedExpr} AS created_at
            FROM orders
            WHERE student_id = ?
            ORDER BY id DESC LIMIT 10
        ");
        $ordersStmt->execute([$uid]);
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard orders] ' . $e->getMessage());
    }

    try {
        $payCols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
        $payTxExpr = in_array('transaction_reference', $payCols) ? 'p.transaction_reference' : 'NULL';
        $payCreatedExpr = in_array('created_at', $payCols) ? 'p.created_at' : 'NOW()';

        $paymentsStmt = $pdo->prepare("
            SELECT p.id, p.amount, p.currency, p.payment_method, p.status,
                   {$payTxExpr} AS transaction_reference, {$payCreatedExpr} AS created_at,
                   COALESCE(c.title_ar, c.title) AS course_title
            FROM payments p
            LEFT JOIN courses c ON c.id = p.course_id
            WHERE p.student_id = ?
            ORDER BY p.id DESC LIMIT 10
        ");
        $paymentsStmt->execute([$uid]);
        $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard payments] ' . $e->getMessage());
    }

    // 11) Support Tickets
    $tickets = [];
    try {
        $stCols       = $pdo->query("SHOW COLUMNS FROM support_tickets")->fetchAll(PDO::FETCH_COLUMN);
        $stUserField  = in_array('user_id', $stCols)    ? 'st.user_id'    : 'st.student_id';
        $stStuField   = in_array('student_id', $stCols) ? 'st.student_id' : 'st.user_id';
        $ticketsStmt  = $pdo->prepare("
            SELECT st.id, st.ticket_number, st.subject, st.category, st.priority, st.status,
                   st.created_at, st.updated_at,
                   (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id) AS messages_count
            FROM support_tickets st
            WHERE ({$stUserField} = ? OR {$stStuField} = ?)
            ORDER BY st.updated_at DESC LIMIT 10
        ");
        $ticketsStmt->execute([$uid, $uid]);
        $tickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard tickets] ' . $e->getMessage());
    }

    // 12) Platform Support & Payment Settings
    $settings = get_platform_settings($pdo);

    $supportContact = [
        'whatsapp'     => $settings['whatsapp_number']          ?? '01289492477',
        'phone'        => $settings['contact_phone']             ?? '01289492477',
        'vodafone_cash'=> $settings['payment_vodafone']          ?? '01061709999',
        'instapay'     => $settings['payment_instapay']          ?? '01061709999',
        'instructions' => $settings['payment_instructions_ar']   ?? 'حول عبر فودافون كاش أو إنستاباي، ثم ارفع صورة الإشعار.',
        'founder'      => $settings['founder_name']              ?? 'د. خالد عبد الناصر محمد المرسي',
        'founder_title'=> $settings['founder_title']             ?? 'محاضر دولي معتمد – Certified International Lecturer'
    ];

    student_json(200, [
        'success'                   => true,
        'student'                   => $studentInfo,
        'enrolled_courses'          => $enrolledCourses,
        'available_courses'         => $availableCourses,
        'upcoming_meetings'         => $upcomingMeetings,
        'available_quizzes'         => $availableQuizzes,
        'quiz_results'              => $quizResults,
        'study_notes'               => $studyNotes,
        'certificates'              => $certificates,
        'notifications'             => $notifications,
        'unread_notifications_count'=> $unreadCount,
        'orders'                    => $orders,
        'payments'                  => $payments,
        'support_tickets'           => $tickets,
        'support_contact'           => $supportContact,
        'csrf_token'                => $currentStudent['csrf_token']
    ]);

} catch (Throwable $e) {
    error_log('[Student Dashboard API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    student_json(500, [
        'success' => false,
        'message' => 'حدث خطأ أثناء تحميل بيانات لوحة الطالب: ' . $e->getMessage()
    ]);
}
