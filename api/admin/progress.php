<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';

// Auto-heal lesson_progress table schema
try {
    $lpCols = $pdo->query("SHOW COLUMNS FROM lesson_progress")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('student_id', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN student_id INT UNSIGNED NULL");
    }
    if (!in_array('course_id', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN course_id INT UNSIGNED NULL");
    }
    if (!in_array('status', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started'");
    }
    if (!in_array('watch_percent', $lpCols)) {
        $pdo->exec("ALTER TABLE lesson_progress ADD COLUMN watch_percent TINYINT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (in_array('enrollment_id', $lpCols)) {
        $pdo->exec("UPDATE lesson_progress lp JOIN enrollments e ON e.id = lp.enrollment_id SET lp.student_id = e.student_id, lp.course_id = e.course_id WHERE lp.student_id IS NULL AND lp.enrollment_id IS NOT NULL");
    }
} catch (Throwable $ignored) {}

// Admin Progress / Attendance View API
// Actions: course_progress, struggling_students, lesson_progress, update_progress

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // -------------------------------------------------------------------------
    // course_progress — List all students' progress in a course
    // -------------------------------------------------------------------------
    case 'course_progress':
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) json_error('course_id مطلوب');

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');

        $where  = ["e.course_id = :cid", "e.status IN ('active','completed')"];
        $params = [':cid' => $courseId];
        if ($search) {
            $where[] = "u.full_name LIKE :s";
            $params[':s'] = "%$search%";
        }
        $wClause = implode(' AND ', $where);

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id=e.student_id WHERE $wClause");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT
            e.id AS enrollment_id,
            u.id AS student_id, u.full_name, u.email, u.phone,
            e.status AS enrollment_status,
            e.starts_at, e.expires_at,
            COALESCE(p.completed_lessons,0) AS completed_lessons,
            COALESCE(p.total_lessons,0) AS total_lessons,
            COALESCE(p.progress_percent,0) AS progress_percent,
            p.last_activity,
            (SELECT COUNT(*) FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                WHERE qa.student_id = u.id AND q.course_id = :cid2
                AND qa.status = 'passed') AS quizzes_passed,
            (SELECT COUNT(*) FROM quiz_attempts qa2
                JOIN quizzes q2 ON q2.id = qa2.quiz_id
                WHERE qa2.student_id = u.id AND q2.course_id = :cid3
                AND qa2.status = 'failed') AS quizzes_failed
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        LEFT JOIN (
            SELECT
                lp.student_id,
                lp.course_id,
                COUNT(CASE WHEN lp.status = 'completed' THEN 1 END) AS completed_lessons,
                COUNT(*) AS total_lessons,
                ROUND(COUNT(CASE WHEN lp.status='completed' THEN 1 END) * 100.0 / NULLIF(COUNT(*),0),1) AS progress_percent,
                MAX(lp.updated_at) AS last_activity
            FROM lesson_progress lp
            WHERE lp.course_id = :cid4
            GROUP BY lp.student_id, lp.course_id
        ) p ON p.student_id = u.id
        WHERE $wClause
        ORDER BY p.progress_percent DESC, u.full_name ASC
        LIMIT :lim OFFSET :off");

        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':cid2', $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':cid3', $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':cid4', $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Course info
        $course = $pdo->prepare("SELECT id, title_ar, title_en FROM courses WHERE id=:id");
        $course->execute([':id' => $courseId]);
        $courseInfo = $course->fetch(PDO::FETCH_ASSOC);

        json_success([
            'course'   => $courseInfo,
            'students' => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int)ceil($total / $limit),
        ]);
        break;

    // -------------------------------------------------------------------------
    // overview — Progress stats across all courses
    // -------------------------------------------------------------------------
    case 'overview':
        // Per-course summary
        $courses = $pdo->query("SELECT
            c.id, c.title_ar, c.title_en,
            COUNT(DISTINCT e.student_id) AS total_students,
            COUNT(DISTINCT CASE WHEN e.status='completed' THEN e.student_id END) AS completed_students,
            ROUND(AVG(COALESCE(lp_agg.pct,0)),1) AS avg_progress
            FROM courses c
            LEFT JOIN enrollments e ON e.course_id = c.id AND e.status IN ('active','completed')
            LEFT JOIN (
                SELECT course_id, student_id,
                    ROUND(COUNT(CASE WHEN status='completed' THEN 1 END)*100.0/NULLIF(COUNT(*),0),1) AS pct
                FROM lesson_progress GROUP BY course_id, student_id
            ) lp_agg ON lp_agg.course_id=c.id AND lp_agg.student_id=e.student_id
            WHERE c.status = 'published'
            GROUP BY c.id, c.title_ar, c.title_en
            ORDER BY total_students DESC
            LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

        // Global stats
        $totalEnrolled    = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE status IN ('active','completed')")->fetchColumn();
        $totalCompleted   = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status='completed'")->fetchColumn();
        $avgProgress      = $pdo->query("SELECT ROUND(AVG(pct),1) FROM (
            SELECT ROUND(COUNT(CASE WHEN status='completed' THEN 1 END)*100.0/NULLIF(COUNT(*),0),1) AS pct
            FROM lesson_progress GROUP BY student_id, course_id
        ) t")->fetchColumn();

        json_success([
            'total_enrolled'  => $totalEnrolled,
            'total_completed' => $totalCompleted,
            'avg_progress'    => (float)($avgProgress ?? 0),
            'courses'         => $courses,
        ]);
        break;

    // -------------------------------------------------------------------------
    // struggling_students — Students with < threshold% progress
    // -------------------------------------------------------------------------
    case 'struggling_students':
        $threshold = (int)($_GET['threshold'] ?? 30);
        $courseId  = (int)($_GET['course_id'] ?? 0);

        $courseFilter = $courseId ? "AND e.course_id = :cid" : "";
        $params = [':thr' => $threshold];
        if ($courseId) $params[':cid'] = $courseId;

        $stmt = $pdo->prepare("SELECT
            u.id, u.full_name, u.email, u.phone,
            c.id AS course_id, c.title_ar AS course_title,
            COALESCE(lp_agg.pct, 0) AS progress_percent,
            e.created_at AS enrolled_at,
            e.expires_at,
            DATEDIFF(NOW(), e.created_at) AS days_enrolled
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            JOIN courses c ON c.id = e.course_id
            LEFT JOIN (
                SELECT course_id, student_id,
                    ROUND(COUNT(CASE WHEN status='completed' THEN 1 END)*100.0/NULLIF(COUNT(*),0),1) AS pct
                FROM lesson_progress GROUP BY course_id, student_id
            ) lp_agg ON lp_agg.course_id=e.course_id AND lp_agg.student_id=u.id
            WHERE e.status = 'active'
            $courseFilter
            HAVING progress_percent < :thr
            ORDER BY progress_percent ASC, days_enrolled DESC
            LIMIT 50");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_success(['students' => $rows, 'threshold' => $threshold]);
        break;

    // -------------------------------------------------------------------------
    // lesson_detail — Lesson-by-lesson progress for one student in one course
    // -------------------------------------------------------------------------
    case 'lesson_detail':
        $studentId = (int)($_GET['student_id'] ?? 0);
        $courseId  = (int)($_GET['course_id'] ?? 0);
        if (!$studentId || !$courseId) json_error('student_id و course_id مطلوبان');

        // Student info
        $student = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id=:id");
        $student->execute([':id' => $studentId]);
        $studentInfo = $student->fetch(PDO::FETCH_ASSOC);
        if (!$studentInfo) json_error('الطالب غير موجود');

        // Lessons + progress
        $lessons = $pdo->prepare("SELECT
            l.id, l.title_ar, l.title_en, l.order_index, l.type, l.duration_minutes,
            lp.status, lp.watch_percent, lp.updated_at AS last_watched
            FROM lessons l
            LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=:sid
            WHERE l.course_id=:cid AND l.status='published'
            ORDER BY l.order_index ASC");
        $lessons->execute([':sid' => $studentId, ':cid' => $courseId]);
        $lessonRows = $lessons->fetchAll(PDO::FETCH_ASSOC);

        // Quiz attempts for this student+course
        $attempts = $pdo->prepare("SELECT
            q.title_ar, q.title_en, qa.score_percent, qa.status, qa.created_at
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id=qa.quiz_id
            WHERE qa.student_id=:sid AND q.course_id=:cid
            ORDER BY qa.created_at DESC");
        $attempts->execute([':sid' => $studentId, ':cid' => $courseId]);
        $attemptRows = $attempts->fetchAll(PDO::FETCH_ASSOC);

        json_success([
            'student' => $studentInfo,
            'lessons' => $lessonRows,
            'quiz_attempts' => $attemptRows,
        ]);
        break;

    // -------------------------------------------------------------------------
    // update_progress — Admin manually set lesson status (for offline/lab work)
    // -------------------------------------------------------------------------
    case 'update_progress':
        $data      = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $studentId = (int)($data['student_id'] ?? 0);
        $lessonId  = (int)($data['lesson_id'] ?? 0);
        $courseId  = (int)($data['course_id'] ?? 0);
        $status    = $data['status'] ?? 'completed';

        if (!$studentId || !$lessonId || !$courseId) {
            json_error('student_id و lesson_id و course_id مطلوبون');
        }
        $validStatuses = ['not_started', 'in_progress', 'completed'];
        if (!in_array($status, $validStatuses, true)) json_error('status غير صحيح');

        // Check enrollment
        $enroll = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=:sid AND course_id=:cid AND status='active'");
        $enroll->execute([':sid' => $studentId, ':cid' => $courseId]);
        if (!$enroll->fetchColumn()) json_error('الطالب غير مسجل في هذا الكورس');

        $pdo->prepare("INSERT INTO lesson_progress
            (student_id, course_id, lesson_id, status, watch_percent, updated_at)
            VALUES (:sid, :cid, :lid, :status, :pct, NOW())
            ON DUPLICATE KEY UPDATE status=VALUES(status), watch_percent=VALUES(watch_percent), updated_at=NOW()")
            ->execute([
                ':sid'    => $studentId,
                ':cid'    => $courseId,
                ':lid'    => $lessonId,
                ':status' => $status,
                ':pct'    => $status === 'completed' ? 100 : 0,
            ]);

        // Check if course is now 100% complete → update enrollment
        $totalLessons = (int)$pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=:cid AND status='published'")
            ->execute([':cid' => $courseId]) ? $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=:cid AND status='published'") : 0;
        $tlStmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=:cid AND status='published'");
        $tlStmt->execute([':cid' => $courseId]);
        $totalL = (int)$tlStmt->fetchColumn();

        $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE student_id=:sid AND course_id=:cid AND status='completed'");
        $doneStmt->execute([':sid' => $studentId, ':cid' => $courseId]);
        $doneL = (int)$doneStmt->fetchColumn();

        if ($totalL > 0 && $doneL >= $totalL) {
            $pdo->prepare("UPDATE enrollments SET status='completed' WHERE student_id=:sid AND course_id=:cid")
                ->execute([':sid' => $studentId, ':cid' => $courseId]);
        }

        json_success(['updated' => true, 'progress' => "$doneL/$totalL"]);
        break;

    default:
        json_error('action غير معروف', 400);
}
