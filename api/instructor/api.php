<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/instructor_guard.php';
require_once __DIR__ . '/../config/video_upload.php';

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    $data['success'] = $status >= 200 && $status < 300;

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$uid = (int) $currentInstructor['id'];

function q(string $sql, array $params = []): PDOStatement
{
    global $pdo;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt;
}

function ensure_details_table(): void
{
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS instructor_details (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            specialization VARCHAR(150) NULL,
            experience_years TINYINT UNSIGNED NULL,
            workplace VARCHAR(190) NULL,
            experience TEXT NULL,
            bio TEXT NULL,
            permissions_json TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[NBC instructor_details table] ' . $e->getMessage());
    }
}

function owns(int $courseId): bool
{
    global $uid;

    return (bool) q(
        'SELECT 1 FROM courses WHERE id = ? AND (instructor_id = ? OR id IN (SELECT course_id FROM course_instructors WHERE instructor_id = ?))',
        [$courseId, $uid, $uid]
    )->fetchColumn();
}

function cleanText(mixed $value, int $max = 5000): string
{
    $value = trim((string) ($value ?? ''));

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

function coursePayload(array $row): array
{
    return [
        'id'               => (int) $row['id'],
        'title'            => $row['title_ar'] ?? $row['title'],
        'title_ar'         => $row['title_ar'] ?? $row['title'],
        'title_en'         => $row['title_en'] ?? '',
        'description'      => $row['description'] ?? '',
        'desc_ar'          => $row['description'] ?? '',
        'desc_en'          => $row['description_en'] ?? '',
        'thumbnail'        => $row['thumbnail'] ?? null,
        'category'         => $row['category'] ?? null,
        'level'            => $row['level'] ?? 'beginner',
        'price'            => (float) ($row['price'] ?? 0),
        'discounted_price' => isset($row['discounted_price']) ? (float)$row['discounted_price'] : null,
        'status'           => $row['status'] ?? 'draft',
        'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
        'slug'             => $row['slug'] ?? '',
        'created_at'       => $row['created_at'] ?? null,
    ];
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;

$courseId = (int) ($input['course_id'] ?? ($_GET['course_id'] ?? 0));
$action = (string) ($_GET['a'] ?? ($input['action'] ?? ''));

try {
    switch ($action) {
        /*
         * 1) Dashboard statistics
         */
        case 'stats':
            $courses = q(
                'SELECT c.*,
                    (SELECT COUNT(*)
                     FROM lessons l
                     WHERE l.course_id = c.id) AS lessons_count,

                    (SELECT COUNT(*)
                     FROM course_visits v
                     WHERE v.course_id = c.id) AS visits_count,

                    (SELECT COUNT(DISTINCT v.visitor_key)
                     FROM course_visits v
                     WHERE v.course_id = c.id) AS visitors_count,

                    (SELECT COUNT(*)
                     FROM enrollments e
                     WHERE e.course_id = c.id
                       AND e.status IN ("active", "completed")) AS subscribers_count

                 FROM courses c
                 WHERE c.instructor_id = ?
                 ORDER BY c.id DESC',
                [$uid]
            )->fetchAll(PDO::FETCH_ASSOC);

            $visitors = q(
                'SELECT COUNT(DISTINCT v.course_id, v.visitor_key)
                 FROM course_visits v
                 INNER JOIN courses c ON c.id = v.course_id
                 WHERE c.instructor_id = ?',
                [$uid]
            )->fetchColumn();

            $notSubscribed = q(
                'SELECT COUNT(DISTINCT v.course_id, v.visitor_key)
                 FROM course_visits v
                 INNER JOIN courses c ON c.id = v.course_id
                 WHERE c.instructor_id = ?
                   AND (
                       v.user_id IS NULL
                       OR NOT EXISTS (
                           SELECT 1
                           FROM enrollments e
                           WHERE e.course_id = v.course_id
                             AND e.student_id = v.user_id
                             AND e.status IN ("active", "completed")
                       )
                   )',
                [$uid]
            )->fetchColumn();

            $daily = q(
                'SELECT v.visited_on AS d, COUNT(*) AS n
                 FROM course_visits v
                 INNER JOIN courses c ON c.id = v.course_id
                 WHERE c.instructor_id = ?
                   AND v.visited_on >= CURDATE() - INTERVAL 13 DAY
                 GROUP BY v.visited_on
                 ORDER BY v.visited_on',
                [$uid]
            )->fetchAll(PDO::FETCH_ASSOC);

            // Recent 8 enrolled students with progress
            $recentSubscribers = q(
                'SELECT u.id AS student_id, u.full_name, u.email, u.phone, u.avatar,
                        c.id AS course_id, c.title AS course_title, e.enrolled_at,
                        ROUND(COALESCE((SELECT COUNT(*) FROM lesson_progress lp WHERE lp.enrollment_id = e.id AND lp.status = "completed") * 100.0 / NULLIF((SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id), 0), 0)) AS progress_percent,
                        (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.enrollment_id = e.id AND lp.status = "completed") AS completed_lessons,
                        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS total_lessons
                 FROM enrollments e
                 JOIN courses c ON c.id = e.course_id
                 JOIN users u ON u.id = e.student_id
                 WHERE c.instructor_id = ? AND e.status IN ("active", "completed")
                 ORDER BY e.enrolled_at DESC LIMIT 8',
                [$uid]
            )->fetchAll(PDO::FETCH_ASSOC);

            // Reviews stats
            $reviewsCount = 0;
            $avgRating = 5.0;
            $recentReviews = [];
            try {
                $revStmt = q(
                    'SELECT COUNT(*) AS cnt, ROUND(AVG(r.rating), 1) AS avg_score
                     FROM reviews r
                     JOIN courses c ON (r.item_type = "course" AND r.item_id = c.id)
                     WHERE c.instructor_id = ?',
                    [$uid]
                )->fetch(PDO::FETCH_ASSOC);

                $reviewsCount = (int)($revStmt['cnt'] ?? 0);
                $avgRating = (float)($revStmt['avg_score'] ?? 5.0);

                $recentReviews = q(
                    'SELECT r.id, r.rating, r.comment, r.created_at,
                            u.full_name AS student_name, u.avatar AS student_avatar,
                            c.title AS course_title
                     FROM reviews r
                     JOIN courses c ON (r.item_type = "course" AND r.item_id = c.id)
                     JOIN users u ON u.id = r.user_id
                     WHERE c.instructor_id = ?
                     ORDER BY r.id DESC LIMIT 5',
                    [$uid]
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // reviews table might be empty or optional
            }

            // Course completion rates
            $completionRates = [];
            $totalLessons = 0;
            $totalSubscribers = 0;

            foreach ($courses as &$course) {
                $cId = (int)$course['id'];
                $subCnt = (int)$course['subscribers_count'];
                $totalLessons += (int)$course['lessons_count'];
                $totalSubscribers += $subCnt;

                if ($subCnt > 0) {
                    $avgPct = (float)q("
                        SELECT ROUND(AVG(COALESCE((SELECT COUNT(*) FROM lesson_progress lp WHERE lp.enrollment_id = e.id AND lp.status = 'completed') * 100.0 / NULLIF((SELECT COUNT(*) FROM lessons l WHERE l.course_id = e.course_id), 0), 0)), 1)
                        FROM enrollments e
                        WHERE e.course_id = ? AND e.status IN ('active', 'completed')
                    ", [$cId])->fetchColumn();
                    $completedStudents = (int)q("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND status = 'completed'", [$cId])->fetchColumn();
                    $completionRates[] = [
                        'course_id' => $cId,
                        'title' => $course['title_ar'] ?? $course['title'],
                        'subscribers' => $subCnt,
                        'avg_progress' => round($avgPct, 1),
                        'completed_students' => $completedStudents
                    ];
                }

                $course = coursePayload($course) + [
                    'lessons' => (int) $course['lessons_count'],
                    'visits' => (int) $course['visits_count'],
                    'visitors' => (int) $course['visitors_count'],
                    'subscribers' => (int) $course['subscribers_count'],
                ];
            }
            unset($course);

            // Upcoming live meetings
            $upcomingMeetings = q("
                SELECT m.*, m.starts_at AS scheduled_at, c.title AS course_title
                FROM live_sessions m
                JOIN courses c ON c.id = m.course_id
                WHERE (c.instructor_id = ? OR m.created_by = ?)
                  AND m.starts_at >= NOW() - INTERVAL 2 HOUR
                ORDER BY m.starts_at ASC LIMIT 4
            ", [$uid, $uid])->fetchAll(PDO::FETCH_ASSOC);

            out([
                'courses' => $courses,
                'daily' => $daily,
                'recent_subscribers' => $recentSubscribers,
                'recent_reviews' => $recentReviews,
                'completion_rates' => $completionRates,
                'upcoming_meetings' => $upcomingMeetings,
                'totals' => [
                    'courses' => count($courses),
                    'lessons' => $totalLessons,
                    'subscribers' => $totalSubscribers,
                    'visitors' => (int) $visitors,
                    'not_subscribed' => (int) $notSubscribed,
                    'reviews_count' => $reviewsCount,
                    'avg_rating' => $avgRating ?: 5.0
                ],
            ]);

        /*
         * 2) List courses
         */
        case 'courses':
            $rows = q(
                'SELECT c.*,
                    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons_count,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status IN ("active", "completed")) AS subscribers_count
                 FROM courses c
                 WHERE c.instructor_id = ?
                 ORDER BY c.id DESC',
                [$uid]
            )->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($rows as $r) {
                $formatted[] = coursePayload($r) + [
                    'lessons' => (int)$r['lessons_count'],
                    'subscribers' => (int)$r['subscribers_count']
                ];
            }

            out(['courses' => $formatted]);

        /*
         * 3) Create or update course
         */
        case 'save_course':
            $title = cleanText($input['title'] ?? ($input['title_ar'] ?? ''), 200);
            $titleEn = cleanText($input['title_en'] ?? '', 200);
            $description = cleanText($input['description'] ?? ($input['desc_ar'] ?? ''), 20000);
            $category = cleanText($input['category'] ?? '', 120);
            $level = (string) ($input['level'] ?? 'beginner');
            $status = (string) ($input['status'] ?? 'published');
            $durationMinutes = max(0, (int)($input['duration_minutes'] ?? 0));
            $price = filter_var($input['price'] ?? 0, FILTER_VALIDATE_FLOAT);
            $discountPrice = isset($input['discounted_price']) && $input['discounted_price'] !== '' ? filter_var($input['discounted_price'], FILTER_VALIDATE_FLOAT) : null;
            // Thumbnails are uploaded through course_thumbnail_upload; never accept an arbitrary URL here.
            $thumbnail = '';

            if ($title === '') {
                out(['message' => 'اكتب عنوان الكورس.'], 422);
            }

            if (!in_array($level, ['beginner', 'intermediate', 'advanced'], true)) {
                $level = 'beginner';
            }

            if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                $status = 'published';
            }

            if ($price === false || $price < 0) {
                $price = 0.00;
            }

            if ($courseId > 0) {
                if (!owns($courseId)) {
                    out(['message' => 'لا يمكنك تعديل هذا الكورس.'], 403);
                }

                q(
                    'UPDATE courses
                     SET title = ?,
                         title_ar = ?,
                         title_en = ?,
                         description = ?,
                         category = ?,
                         level = ?,
                         price = ?,
                         discounted_price = ?,
                         duration_minutes = ?,
                         thumbnail = CASE WHEN ? != "" THEN ? ELSE thumbnail END,
                         status = ?,
                         published_at = CASE
                             WHEN ? = "published" AND published_at IS NULL
                             THEN NOW()
                             ELSE published_at
                         END
                     WHERE id = ? AND instructor_id = ?',
                    [
                        $title,
                        $title,
                        $titleEn,
                        $description,
                        $category !== '' ? $category : null,
                        $level,
                        $price,
                        $discountPrice,
                        $durationMinutes,
                        $thumbnail,
                        $thumbnail,
                        $status,
                        $status,
                        $courseId,
                        $uid,
                    ]
                );

                nbc_log_audit($uid, 'update_course', 'course', (string)$courseId, ['title' => $title]);

                out([
                    'message' => 'تم تعديل الكورس بنجاح.',
                    'course_id' => $courseId,
                ]);
            }

            $slugBase = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
            if ($slugBase === '') {
                $slugBase = 'course';
            }
            $slug = $slugBase . '-' . bin2hex(random_bytes(4));

            q(
                'INSERT INTO courses
                    (instructor_id, title, title_ar, title_en, slug, description,
                     category, level, price, discounted_price, duration_minutes, thumbnail, status, published_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         CASE WHEN ? = "published" THEN NOW() ELSE NULL END, NOW())',
                [
                    $uid,
                    $title,
                    $title,
                    $titleEn,
                    $slug,
                    $description !== '' ? $description : null,
                    $category !== '' ? $category : null,
                    $level,
                    $price,
                    $discountPrice,
                    $durationMinutes,
                    $thumbnail !== '' ? $thumbnail : null,
                    $status,
                    $status,
                ]
            );

            $newCourseId = (int)$pdo->lastInsertId();

            // Link in course_instructors mapping
            q('INSERT IGNORE INTO course_instructors (course_id, instructor_id, is_primary) VALUES (?, ?, 1)', [$newCourseId, $uid]);

            nbc_log_audit($uid, 'create_course', 'course', (string)$newCourseId, ['title' => $title]);

            out([
                'message' => 'تم إنشاء الكورس بنجاح.',
                'course_id' => $newCourseId,
            ]);

        /*
         * 4) Delete course
         */
        case 'delete_course':
            if ($courseId <= 0 || !owns($courseId)) {
                out(['message' => 'الكورس غير موجود أو لا تملك صلاحية حذفه.'], 403);
            }

            $pdo->beginTransaction();
            q('DELETE FROM lessons WHERE course_id = ?', [$courseId]);
            q('DELETE FROM enrollments WHERE course_id = ?', [$courseId]);
            q('DELETE FROM course_visits WHERE course_id = ?', [$courseId]);
            q('DELETE FROM course_instructors WHERE course_id = ?', [$courseId]);
            q('DELETE FROM courses WHERE id = ? AND instructor_id = ?', [$courseId, $uid]);
            $pdo->commit();

            nbc_log_audit($uid, 'delete_course', 'course', (string)$courseId);

            out(['message' => 'تم حذف الكورس والدروس المرتبطة به بنجاح.']);

        /*
         * 5) List lessons for a course
         */
        case 'lessons':
            if ($courseId <= 0 || !owns($courseId)) {
                out(['message' => 'الكورس غير موجود أو لا تملك صلاحية الوصول إليه.'], 403);
            }

            $lessons = q(
                'SELECT id, course_id, title, description, video_url, duration_minutes, sort_order, is_preview, status
                 FROM lessons
                 WHERE course_id = ?
                 ORDER BY sort_order, id',
                [$courseId]
            )->fetchAll(PDO::FETCH_ASSOC);

            out(['lessons' => $lessons]);

        /*
         * 6) Add or update lesson
         */
        case 'add_lesson':
        case 'save_lesson':
            $lessonId = (int)($input['lesson_id'] ?? 0);
            $sectionId = !empty($input['section_id']) ? (int)$input['section_id'] : null;
            $title = cleanText($input['title'] ?? '', 200);
            $description = cleanText($input['description'] ?? '', 5000);
            // New lesson videos are attached through lesson_video_upload. Preserve existing legacy media on edits.
            $videoUrl = '';
            $duration = max(0, (int)($input['duration_minutes'] ?? 0));
            $isPreview = !empty($input['is_preview']) ? 1 : 0;
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : null;

            if ($courseId <= 0 || !owns($courseId)) {
                out(['message' => 'الكورس غير موجود أو لا تملك صلاحية التعديل عليه.'], 403);
            }

            if ($title === '') {
                out(['message' => 'اكتب عنوان الدرس.'], 422);
            }

            if ($lessonId > 0) {
                // Update existing lesson
                $existingVideo = (string)(q('SELECT video_url FROM lessons WHERE id = ? AND course_id = ?', [$lessonId, $courseId])->fetchColumn() ?: '');
                q(
                    'UPDATE lessons 
                     SET title = ?, section_id = ?, description = ?, video_url = ?, duration_minutes = ?, is_preview = ?,
                         sort_order = COALESCE(?, sort_order), status = "published", updated_at = NOW()
                     WHERE id = ? AND course_id = ?',
                    [
                        $title,
                        $sectionId,
                        $description !== '' ? $description : null,
                        $existingVideo !== '' ? $existingVideo : null,
                        $duration,
                        $isPreview,
                        $sortOrder,
                        $lessonId,
                        $courseId
                    ]
                );

                nbc_log_audit($uid, 'update_lesson', 'lesson', (string)$lessonId, ['title' => $title]);

                out([
                    'message' => 'تم تعديل الدرس بنجاح.',
                    'lesson_id' => $lessonId,
                ]);
            } else {
                // Insert new lesson
                if ($sortOrder === null) {
                    $sortOrder = (int) q(
                        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE course_id = ?',
                        [$courseId]
                    )->fetchColumn();
                }

                q(
                    'INSERT INTO lessons (course_id, section_id, title, description, video_url, duration_minutes, sort_order, is_preview, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "published", NOW())',
                    [
                        $courseId,
                        $sectionId,
                        $title,
                        $description !== '' ? $description : null,
                        null,
                        $duration,
                        $sortOrder,
                        $isPreview,
                    ]
                );

                $newLessonId = (int)$pdo->lastInsertId();
                nbc_log_audit($uid, 'create_lesson', 'lesson', (string)$newLessonId, ['title' => $title]);

                out([
                    'message' => 'تمت إضافة الدرس بنجاح.',
                    'lesson_id' => $newLessonId,
                ]);
            }

        case 'course_thumbnail_upload':
        case 'lesson_video_upload':
            if (!nbc_verify_csrf()) out(['message' => 'رمز الحماية غير صالح. حدّث الصفحة وحاول مرة أخرى.'], 419);
            $isVideo = $action === 'lesson_video_upload';
            $courseId = (int)($_POST['course_id'] ?? 0);
            $lessonId = (int)($_POST['lesson_id'] ?? 0);
            if ($courseId < 1 || !owns($courseId)) out(['message' => 'الكورس غير موجود أو لا تملك صلاحية التعديل عليه.'], 403);
            if ($isVideo && ($lessonId < 1 || !q('SELECT 1 FROM lessons WHERE id=? AND course_id=?', [$lessonId, $courseId])->fetchColumn())) out(['message' => 'الدرس غير موجود.'], 404);
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) out(['message' => 'اختر ملفًا صالحًا من جهازك.'], 422);
            $max = $isVideo ? 10 * 1024 * 1024 * 1024 : 8 * 1024 * 1024;
            if ((int)$file['size'] < 1 || (int)$file['size'] > $max) out(['message' => $isVideo ? 'حجم الفيديو يتجاوز 10 جيجابايت.' : 'حجم الصورة يتجاوز 8 ميجابايت.'], 422);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if ($isVideo) {
                if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'mp4' || !nbc_is_mp4_file((string)$file['tmp_name'])) out(['message' => 'ارفع فيديو MP4 صالحًا.'], 422);
                $extension = 'mp4';
            } else {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) out(['message' => 'صيغة الصورة غير مدعومة.'], 422);
                $extension = $allowed[$mime];
            }
            $folder = $isVideo ? 'course-content/videos' : 'course-covers';
            $dir = __DIR__ . '/../../uploads/' . $folder;
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) out(['message' => 'تعذر تجهيز مجلد الرفع.'], 500);
            $name = ($isVideo ? bin2hex(random_bytes(20)) : 'course' . $courseId . '_' . bin2hex(random_bytes(12))) . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) out(['message' => 'تعذر حفظ الملف المرفوع.'], 500);
            $path = 'uploads/' . $folder . '/' . $name;
            if ($isVideo) q('UPDATE lessons SET video_url=?, updated_at=NOW() WHERE id=? AND course_id=?', [$path, $lessonId, $courseId]);
            else q('UPDATE courses SET thumbnail=? WHERE id=?', [$path, $courseId]);
            out(['message' => 'تم رفع الملف من جهازك بنجاح.', 'path' => $path]);

        /*
         * 6b) Sections & Curriculum Modules
         */
        case 'sections':
            if ($courseId <= 0 || !owns($courseId)) {
                out(['message' => 'الكورس غير موجود أو لا تملك صلاحية الوصول إليه.'], 403);
            }

            $sections = q(
                'SELECT s.id, s.course_id, s.title, s.description, s.sort_order, s.status,
                        (SELECT COUNT(*) FROM lessons l WHERE l.section_id = s.id) AS lessons_count
                 FROM course_sections s
                 WHERE s.course_id = ?
                 ORDER BY s.sort_order ASC, s.id ASC',
                [$courseId]
            )->fetchAll(PDO::FETCH_ASSOC);

            out(['sections' => $sections]);

        case 'save_section':
            $secId = (int)($input['section_id'] ?? 0);
            $secCourseId = (int)($input['course_id'] ?? $courseId);
            $secTitle = cleanText($input['title'] ?? '', 200);
            $secDesc = cleanText($input['description'] ?? '', 2000);
            $secSort = isset($input['sort_order']) ? (int)$input['sort_order'] : null;

            if ($secCourseId <= 0 || !owns($secCourseId)) {
                out(['message' => 'الكورس غير موجود أو لا تملك صلاحية الوصول إليه.'], 403);
            }
            if ($secTitle === '') {
                out(['message' => 'يرجى كتابة عنوان الوحدة/المنهج.'], 422);
            }

            if ($secId > 0) {
                q(
                    'UPDATE course_sections
                     SET title = ?, description = ?, sort_order = COALESCE(?, sort_order), updated_at = NOW()
                     WHERE id = ? AND course_id = ?',
                    [$secTitle, $secDesc !== '' ? $secDesc : null, $secSort, $secId, $secCourseId]
                );
                out(['message' => 'تم تحديث الوحدة/المنهج بنجاح.', 'section_id' => $secId]);
            } else {
                if ($secSort === null) {
                    $secSort = (int) q(
                        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM course_sections WHERE course_id = ?',
                        [$secCourseId]
                    )->fetchColumn();
                }
                q(
                    'INSERT INTO course_sections (course_id, title, description, sort_order, status, created_at)
                     VALUES (?, ?, ?, ?, "published", NOW())',
                    [$secCourseId, $secTitle, $secDesc !== '' ? $secDesc : null, $secSort]
                );
                out(['message' => 'تم إضافة الوحدة/المنهج الدراسي بنجاح.', 'section_id' => (int)$pdo->lastInsertId()]);
            }

        case 'delete_section':
            $secId = (int)($input['section_id'] ?? 0);
            if ($secId <= 0) {
                out(['message' => 'معرف الوحدة غير صحيح.'], 422);
            }
            $sec = q('SELECT s.id, s.course_id FROM course_sections s JOIN courses c ON c.id = s.course_id WHERE s.id = ? AND c.instructor_id = ?', [$secId, $uid])->fetch(PDO::FETCH_ASSOC);
            if (!$sec) {
                out(['message' => 'الوحدة غير موجودة أو لا تملك صلاحية حذفها.'], 404);
            }
            q('UPDATE lessons SET section_id = NULL WHERE section_id = ?', [$secId]);
            q('DELETE FROM course_sections WHERE id = ?', [$secId]);
            out(['message' => 'تم حذف الوحدة الدراسية بنجاح.']);

        case 'delete_lesson':
            $lessonId = (int) ($input['lesson_id'] ?? 0);

            $deleted = q(
                'DELETE l
                 FROM lessons l
                 INNER JOIN courses c ON c.id = l.course_id
                 WHERE l.id = ? AND c.instructor_id = ?',
                [$lessonId, $uid]
            );

            if ($deleted->rowCount() === 0) {
                out(['message' => 'الدرس غير موجود أو لا تملك صلاحية حذفه.'], 404);
            }

            nbc_log_audit($uid, 'delete_lesson', 'lesson', (string)$lessonId);

            out(['message' => 'تم حذف الدرس بنجاح.']);

        /*
         * 8) Students and Prospective Visitors (Leads)
         */
        case 'students':
            $subscribers = q(
                'SELECT
                    u.id AS student_id,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.avatar,
                    c.id AS course_id,
                    c.title AS course,
                    e.id AS enrollment_id,
                    e.enrolled_at AS at,
                    ROUND(COALESCE((SELECT COUNT(*) FROM lesson_progress lp WHERE lp.enrollment_id = e.id AND lp.status = "completed") * 100.0 / NULLIF((SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id), 0), 0)) AS progress_percent,
                    (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.enrollment_id = e.id AND lp.status = "completed") AS completed_lessons,
                    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS total_lessons
                 FROM enrollments e
                 INNER JOIN courses c ON c.id = e.course_id
                 INNER JOIN users u ON u.id = e.student_id
                 WHERE c.instructor_id = ?
                   AND e.status IN ("active", "completed")
                 ORDER BY e.enrolled_at DESC',
                [$uid]
            )->fetchAll(PDO::FETCH_ASSOC);

            $visitors = q(
                'SELECT
                    u.id AS student_id,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.avatar,
                    c.id AS course_id,
                    c.title AS course,
                    MAX(v.visited_on) AS at
                 FROM course_visits v
                 INNER JOIN courses c ON c.id = v.course_id
                 INNER JOIN users u ON u.id = v.user_id
                 WHERE c.instructor_id = ?
                   AND v.user_id IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1
                       FROM enrollments e
                       WHERE e.course_id = v.course_id
                         AND e.student_id = v.user_id
                         AND e.status IN ("active", "completed")
                   )
                 GROUP BY
                    u.id, u.full_name, u.email, u.phone, u.avatar,
                    c.id, c.title
                 ORDER BY at DESC',
                [$uid]
            )->fetchAll(PDO::FETCH_ASSOC);

            out([
                'subscribers' => $subscribers,
                'visitors' => $visitors,
            ]);

        /*
         * 9) Live Meetings / Zoom Sessions
         */
        case 'meetings':
            $meetings = q("
                SELECT m.*, m.starts_at AS scheduled_at, c.title AS course_title
                FROM live_sessions m
                JOIN courses c ON c.id = m.course_id
                WHERE c.instructor_id = ? OR m.created_by = ?
                ORDER BY m.starts_at DESC
            ", [$uid, $uid])->fetchAll(PDO::FETCH_ASSOC);

            out(['meetings' => $meetings]);

        case 'save_meeting':
            $mCourseId = (int)($input['course_id'] ?? 0);
            $title = cleanText($input['title'] ?? '', 200);
            $desc = cleanText($input['description'] ?? '', 2000);
            $url = trim((string)($input['meeting_url'] ?? ''));
            $startsAt = trim((string)($input['starts_at'] ?? ($input['scheduled_at'] ?? '')));
            $duration = max(15, (int)($input['duration_minutes'] ?? 60));

            if (!owns($mCourseId)) {
                out(['message' => 'اختر كورساً تملكه لجدولة المحاضرة.'], 403);
            }

            if ($title === '' || $url === '' || $startsAt === '') {
                out(['message' => 'عنوان المحاضرة ورابط البث وموعد البدء مطلوبة.'], 422);
            }

            $endsAt = date('Y-m-d H:i:s', strtotime($startsAt . ' +' . $duration . ' minutes'));

            $pdo->beginTransaction();

            q("
                INSERT INTO live_sessions (course_id, title, description, starts_at, ends_at, meeting_url, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
            ", [$mCourseId, $title, $desc, $startsAt, $endsAt, $url, $uid]);

            $newMeetingId = (int)$pdo->lastInsertId();

            // Broadcast notification to all enrolled students
            $students = q("SELECT student_id FROM enrollments WHERE course_id = ? AND status = 'active'", [$mCourseId])->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($students)) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, action_url, created_at)
                    VALUES (?, ?, ?, 'meeting', ?, NOW())
                ");
                $notifTitle = "محاضرة مباشرة جديدة Zoom: " . $title;
                $notifMsg = "تمت جدولة محاضرة زوم مباشرة لمادة: {$title} في تاريخ {$startsAt}. يرجى التواجد بالموعد.";
                foreach ($students as $stId) {
                    $notifStmt->execute([$stId, $notifTitle, $notifMsg, $url]);
                }
            }

            $pdo->commit();

            nbc_log_audit($uid, 'create_meeting', 'live_session', (string)$newMeetingId, ['title' => $title]);

            out([
                'message' => 'تمت جدولة المحاضرة المباشرة وإشعار جميع الطلاب المشتركين بنجاح.',
                'meeting_id' => $newMeetingId
            ]);

        case 'delete_meeting':
            $meetingId = (int)($input['meeting_id'] ?? 0);
            $deleted = q("
                DELETE m FROM live_sessions m
                JOIN courses c ON c.id = m.course_id
                WHERE m.id = ? AND (c.instructor_id = ? OR m.created_by = ?)
            ", [$meetingId, $uid, $uid]);

            if ($deleted->rowCount() === 0) {
                out(['message' => 'المحاضرة غير موجودة أو لا تملك صلاحية حذفها.'], 404);
            }

            nbc_log_audit($uid, 'delete_meeting', 'live_session', (string)$meetingId);

            out(['message' => 'تم حذف المحاضرة المباشرة بنجاح.']);

        /*
         * 10) Announcements & Student Broadcasts
         */
        case 'announcements':
            $announcements = q("
                SELECT a.*, c.title AS course_title
                FROM announcements a
                LEFT JOIN courses c ON c.id = a.course_id
                WHERE a.created_by = ? OR (a.course_id IS NOT NULL AND a.course_id IN (SELECT id FROM courses WHERE instructor_id = ?))
                ORDER BY a.id DESC
            ", [$uid, $uid])->fetchAll(PDO::FETCH_ASSOC);

            out(['announcements' => $announcements]);

        case 'save_announcement':
            $aCourseId = !empty($input['course_id']) ? (int)$input['course_id'] : null;
            $title = cleanText($input['title'] ?? '', 200);
            $body = cleanText($input['body'] ?? '', 5000);

            if ($title === '' || $body === '') {
                out(['message' => 'عنوان الإعلان ونصه مطلوبان.'], 422);
            }

            if ($aCourseId !== null && !owns($aCourseId)) {
                out(['message' => 'الكورس المحدد غير متاح لك.'], 403);
            }

            $pdo->beginTransaction();

            q("
                INSERT INTO announcements (title, body, target_role, course_id, status, created_by, created_at)
                VALUES (?, ?, 'student', ?, 'published', ?, NOW())
            ", [$title, $body, $aCourseId, $uid]);

            $annId = (int)$pdo->lastInsertId();

            // Notify students
            if ($aCourseId) {
                $students = q("SELECT student_id FROM enrollments WHERE course_id = ? AND status = 'active'", [$aCourseId])->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $students = q("SELECT DISTINCT student_id FROM enrollments WHERE course_id IN (SELECT id FROM courses WHERE instructor_id = ? OR id IN (SELECT course_id FROM course_instructors WHERE instructor_id = ?)) AND status = 'active'", [$uid, $uid])->fetchAll(PDO::FETCH_COLUMN);
            }

            if (!empty($students)) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, announcement_id, title, message, type, created_at)
                    VALUES (?, ?, ?, ?, 'announcement', NOW())
                ");
                foreach ($students as $stId) {
                    $notifStmt->execute([$stId, $annId, $title, $body]);
                }
            }

            $pdo->commit();

            nbc_log_audit($uid, 'create_announcement', 'announcement', (string)$annId, ['title' => $title]);

            out([
                'message' => 'تم نشر الإعلان وإرسال إشعار فوري للطلاب.',
                'announcement_id' => $annId
            ]);

        case 'delete_announcement':
            $annId = (int)($input['announcement_id'] ?? 0);
            $deleted = q("DELETE FROM announcements WHERE id = ? AND created_by = ?", [$annId, $uid]);
            if ($deleted->rowCount() === 0) {
                out(['message' => 'الإعلان غير موجود أو لا تملك صلاحية حذفه.'], 404);
            }

            nbc_log_audit($uid, 'delete_announcement', 'announcement', (string)$annId);

            out(['message' => 'تم حذف الإعلان بنجاح.']);

        /*
         * 11) Student Reviews & Feedback
         */
        case 'reviews':
            $cFilter = (int)($input['course_id'] ?? ($_GET['course_id'] ?? 0));
            $sql = 'SELECT r.id, r.rating, r.comment, r.status, r.created_at,
                           u.full_name AS student_name, u.avatar AS student_avatar, u.email AS student_email,
                           c.id AS course_id, c.title AS course_title
                    FROM reviews r
                    JOIN courses c ON (r.item_type = "course" AND r.item_id = c.id)
                    JOIN users u ON u.id = r.user_id
                    WHERE c.instructor_id = ?';
            $params = [$uid];
            if ($cFilter > 0 && owns($cFilter)) {
                $sql .= ' AND c.id = ?';
                $params[] = $cFilter;
            }
            $sql .= ' ORDER BY r.id DESC';
            $reviews = q($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
            out(['reviews' => $reviews]);

        /*
         * 12) Quizzes & Exams Management
         */
        case 'quizzes':
            $cFilter = (int)($input['course_id'] ?? ($_GET['course_id'] ?? 0));
            $sql = "SELECT q.*, c.title AS course_title,
                           (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.id) AS questions_count,
                           (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempts_count,
                           (SELECT ROUND(AVG(qa2.percent_score), 1) FROM quiz_attempts qa2 WHERE qa2.quiz_id = q.id AND qa2.status = 'submitted') AS avg_score
                    FROM quizzes q
                    JOIN courses c ON c.id = q.course_id
                    WHERE (c.instructor_id = ? OR q.created_by = ?)";
            $params = [$uid, $uid];
            if ($cFilter > 0 && owns($cFilter)) {
                $sql .= " AND q.course_id = ?";
                $params[] = $cFilter;
            }
            $sql .= " ORDER BY q.id DESC";
            $quizzes = q($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
            out(['quizzes' => $quizzes]);

        case 'save_quiz':
            $quizId = (int)($input['quiz_id'] ?? 0);
            $qCourseId = (int)($input['course_id'] ?? 0);
            $title = cleanText($input['title'] ?? '', 200);
            $desc = cleanText($input['description'] ?? '', 2000);
            $timeLimit = max(0, min(300, (int)($input['time_limit_minutes'] ?? 30)));
            $passPercent = max(10, min(100, (float)($input['pass_percent'] ?? 60.0)));
            $maxAttempts = max(1, min(10, (int)($input['max_attempts'] ?? 3)));
            $status = in_array($input['status'] ?? '', ['draft', 'published', 'archived'], true) ? $input['status'] : 'published';

            if (!owns($qCourseId)) {
                out(['message' => 'اختر كورساً تملكه لإنشاء أو تعديل الاختبار.'], 403);
            }
            if ($title === '') {
                out(['message' => 'عنوان الاختبار مطلوب.'], 422);
            }

            if ($quizId > 0) {
                // Update
                q("UPDATE quizzes SET course_id=?, title=?, description=?, time_limit_minutes=?, pass_percent=?, max_attempts=?, status=?, updated_at=NOW()
                   WHERE id=? AND (created_by=? OR course_id IN (SELECT id FROM courses WHERE instructor_id=?))",
                   [$qCourseId, $title, $desc, $timeLimit, $passPercent, $maxAttempts, $status, $quizId, $uid, $uid]);
                nbc_log_audit($uid, 'update_quiz', 'quiz', (string)$quizId, ['title' => $title]);
                out(['message' => 'تم تحديث الاختبار بنجاح.', 'quiz_id' => $quizId]);
            } else {
                // Insert
                q("INSERT INTO quizzes (course_id, title, description, time_limit_minutes, pass_percent, max_attempts, status, created_by, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                   [$qCourseId, $title, $desc, $timeLimit, $passPercent, $maxAttempts, $status, $uid]);
                $newQuizId = (int)$pdo->lastInsertId();
                nbc_log_audit($uid, 'create_quiz', 'quiz', (string)$newQuizId, ['title' => $title]);
                out(['message' => 'تم إنشاء الاختبار بنجاح.', 'quiz_id' => $newQuizId]);
            }

        case 'delete_quiz':
            $quizId = (int)($input['quiz_id'] ?? 0);
            $deleted = q("DELETE q FROM quizzes q
                          JOIN courses c ON c.id = q.course_id
                          WHERE q.id = ? AND (q.created_by = ? OR c.instructor_id = ?)",
                          [$quizId, $uid, $uid]);
            if ($deleted->rowCount() === 0) {
                out(['message' => 'الاختبار غير موجود أو لا تملك صلاحية حذفه.'], 404);
            }
            nbc_log_audit($uid, 'delete_quiz', 'quiz', (string)$quizId);
            out(['message' => 'تم حذف الاختبار بنجاح.']);

        case 'quiz_attempts':
            $quizId = (int)($input['quiz_id'] ?? ($_GET['quiz_id'] ?? 0));
            $attempts = q("
                SELECT qa.*, u.full_name AS student_name, u.email AS student_email, u.phone AS student_phone, u.avatar AS student_avatar
                FROM quiz_attempts qa
                JOIN quizzes q ON q.id = qa.quiz_id
                JOIN courses c ON c.id = q.course_id
                JOIN users u ON u.id = qa.student_id
                WHERE qa.quiz_id = ? AND (q.created_by = ? OR c.instructor_id = ?)
                ORDER BY qa.submitted_at DESC, qa.id DESC
            ", [$quizId, $uid, $uid])->fetchAll(PDO::FETCH_ASSOC);
            out(['attempts' => $attempts]);

        case 'upload_avatar':
            $dataUri = (string)($input['image_data'] ?? '');
            if ($dataUri !== '' && preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/', $dataUri, $matches)) {
                $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                $dir = __DIR__ . '/../../uploads/avatars';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'ins_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                file_put_contents($dir . '/' . $filename, base64_decode($matches[2]));
                $avatarUrl = 'uploads/avatars/' . $filename;
                q("UPDATE users SET avatar = ? WHERE id = ?", [$avatarUrl, $uid]);
                try { q("UPDATE profiles SET avatar = ? WHERE user_id = ?", [$avatarUrl, $uid]); } catch (Throwable $e) {}
                out(['message' => 'تم تحديث الصورة الشخصية بنجاح.', 'avatar_url' => $avatarUrl]);
            }
            out(['message' => 'بيانات الصورة غير صالحة.'], 422);

        /*
         * 12) Profile Update & Security
         */
        case 'update_profile':
            ensure_details_table();

            $fullName = cleanText($input['full_name'] ?? '', 150);
            $phone = cleanText($input['phone'] ?? '', 30);
            $specialization = cleanText($input['specialization'] ?? '', 150);
            $experienceYears = max(0, min(60, (int)($input['experience_years'] ?? 0)));
            $workplace = cleanText($input['workplace'] ?? '', 190);
            $experience = cleanText($input['experience'] ?? '', 5000);
            $bio = cleanText($input['bio'] ?? '', 5000);

            if ($fullName === '') {
                out(['message' => 'الاسم مطلوب.'], 422);
            }

            q("UPDATE users SET full_name = ?, phone = ?, bio = ? WHERE id = ?", [
                $fullName,
                $phone !== '' ? $phone : null,
                $bio !== '' ? $bio : null,
                $uid
            ]);

            // Sync with profiles table
            try {
                q("UPDATE profiles SET display_name = ? WHERE user_id = ?", [$fullName, $uid]);
            } catch (Throwable $e) {}

            // Sync with instructor_details table
            try {
                q("INSERT INTO instructor_details (user_id, specialization, experience_years, workplace, experience, bio)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                       specialization = VALUES(specialization),
                       experience_years = VALUES(experience_years),
                       workplace = VALUES(workplace),
                       experience = VALUES(experience),
                       bio = VALUES(bio)", [
                    $uid,
                    $specialization !== '' ? $specialization : null,
                    $experienceYears,
                    $workplace !== '' ? $workplace : null,
                    $experience !== '' ? $experience : null,
                    $bio !== '' ? $bio : null
                ]);
            } catch (Throwable $e) {
                error_log('[NBC update_profile instructor_details] ' . $e->getMessage());
            }

            // Update session name
            $_SESSION['user_name'] = $fullName;

            nbc_log_audit($uid, 'update_profile', 'user', (string)$uid);

            out(['message' => 'تم حفظ بيانات الملف الشخصي والمهني بنجاح.']);

        case 'change_password':
            $oldPassword = (string)($input['old_password'] ?? '');
            $newPassword = (string)($input['new_password'] ?? '');

            if ($oldPassword === '' || $newPassword === '') {
                out(['message' => 'أدخل كلمة المرور الحالية والجديدة.'], 422);
            }

            if (strlen($newPassword) < 8) {
                out(['message' => 'كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف.'], 422);
            }

            $currHash = q("SELECT password_hash FROM users WHERE id = ?", [$uid])->fetchColumn();
            if (!$currHash || !password_verify($oldPassword, $currHash)) {
                out(['message' => 'كلمة المرور الحالية غير صحيحة.'], 400);
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            q("UPDATE users SET password_hash = ? WHERE id = ?", [$newHash, $uid]);

            nbc_log_audit($uid, 'change_password', 'user', (string)$uid);

            out(['message' => 'تم تغيير كلمة المرور بنجاح.']);

        default:
            out(['message' => 'الطلب غير معروف.'], 400);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[Instructor API] ' . get_class($e) . ': ' . $e->getMessage());

    out([
        'message' => 'حدث خطأ أثناء تنفيذ الطلب. راجع سجل أخطاء PHP لمعرفة التفاصيل.',
    ], 500);
}
