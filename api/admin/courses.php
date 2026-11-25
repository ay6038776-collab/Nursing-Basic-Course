<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/video_upload.php';

const CRS_STATUSES  = ['draft', 'published', 'archived'];
const CRS_LEVELS    = ['beginner', 'intermediate', 'advanced'];
const CRS_COVER_URL = 'uploads/courses/';
const CRS_COVER_MAX = 3 * 1024 * 1024;
// الأعمدة التي تُحفظ مع الكورس (cover_image و published_at لهما معالجة منفصلة)
const CRS_COLS = [
    'instructor_id', 'title', 'title_ar', 'title_en', 'slug', 'description', 'category', 'level',
    'price', 'discounted_price', 'duration_minutes', 'prerequisites', 'learning_outcomes',
    'completion_rules_json', 'starts_at', 'ends_at', 'max_seats', 'status',
];

/* ---------------------------------------------------------------
   دوال مساعدة
---------------------------------------------------------------- */
function crs_fail(int $code, string $message)
{
    admin_json($code, ['success' => false, 'message' => $message]);
    exit;
}

// رقم اختياري: null إن كان فارغًا، false إن كان غير صالح
function crs_opt_num($v, float $min, float $max)
{
    if ($v === null || $v === '') return null;
    $n = filter_var($v, FILTER_VALIDATE_FLOAT);
    if ($n === false || $n < $min || $n > $max) return false;
    return (float)$n;
}

function crs_opt_int($v, int $min, int $max)
{
    if ($v === null || $v === '') return null;
    $n = filter_var($v, FILTER_VALIDATE_INT);
    if ($n === false || $n < $min || $n > $max) return false;
    return (int)$n;
}

function crs_opt_date($v)
{
    if ($v === null || $v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', (string)$v);
    return ($d && $d->format('Y-m-d') === (string)$v) ? (string)$v : false;
}

function crs_opt_text($v, int $max)
{
    $s = trim((string)($v ?? ''));
    if ($s === '') return null;
    return mb_strlen($s) > $max ? false : $s;
}

function crs_audit(PDO $pdo, string $event, int $id, array $details): void
{
    $pdo->prepare("INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?)")
        ->execute([(int)$_SESSION['user_id'], $event, 'course', (string)$id, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

function crs_cover_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads/courses';
}

function crs_cover_delete(?string $rel): void
{
    if (!$rel || strpos($rel, CRS_COVER_URL) !== 0) return;
    $file = crs_cover_dir() . '/' . basename($rel);
    if (is_file($file)) @unlink($file);
}

function crs_cover_prepare_dir(): void
{
    $dir = crs_cover_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        crs_fail(500, 'تعذر إنشاء مجلد صور الكورسات.');
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        // منع تنفيذ أي سكربت داخل مجلد الرفع
        @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\n  Require all denied\n</FilesMatch>\n");
    }
}

function crs_lesson_upload_dir(string $kind): string
{
    return dirname(__DIR__, 2) . '/uploads/course-content/' . $kind;
}

function crs_lesson_upload(array $file, string $kind): array
{
    $isVideo = $kind === 'videos';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        crs_fail(422, 'تعذر استلام الملف المرفوع.');
    }
    $limit = $isVideo ? 10 * 1024 * 1024 * 1024 : 35 * 1024 * 1024;
    if ((int)$file['size'] < 1 || (int)$file['size'] > $limit) crs_fail(422, $isVideo ? 'حجم فيديو الدرس يجب ألا يتجاوز 10 جيجابايت.' : 'حجم الملف يجب ألا يتجاوز 35 ميجابايت وفق إعدادات الخادم الحالية.');
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']));
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($isVideo) {
        if ($extension !== 'mp4' || !nbc_is_mp4_file((string)$file['tmp_name'])) crs_fail(422, 'ارفع فيديو MP4 صالحًا.');
    } else {
        $types = ['application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'text/plain' => 'txt'];
        if (!isset($types[$mime]) || $types[$mime] !== $extension) crs_fail(422, 'صيغة الملف غير مدعومة. الصيغ المتاحة PDF وWord وPowerPoint وExcel وTXT.');
    }
    $dir = crs_lesson_upload_dir($kind);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) crs_fail(500, 'تعذر تجهيز مجلد الملفات.');
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    $name = bin2hex(random_bytes(20)) . '.' . $extension;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) crs_fail(500, 'تعذر حفظ الملف المرفوع.');
    return ['path' => 'uploads/course-content/' . $kind . '/' . $name, 'absolute' => $dir . '/' . $name, 'mime' => $mime, 'size' => (int)$file['size'], 'original' => mb_substr(basename((string)$file['name']), 0, 255)];
}

function crs_lesson_upload_delete(?string $path, string $kind): void
{
    if (!$path || !preg_match('#^uploads/course-content/' . preg_quote($kind, '#') . '/[a-f0-9]{40}\\.[a-z0-9]{1,8}$#', $path)) return;
    $file = crs_lesson_upload_dir($kind) . '/' . basename($path);
    if (is_file($file)) @unlink($file);
}

function crs_registered(PDO $pdo, int $courseId): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE course_id=? AND status IN ('active','completed')");
    $q->execute([$courseId]);
    return (int)$q->fetchColumn();
}

/** التحقق من بيانات الكورس وتجهيزها. تُرجع ['data'=>..., 'instructors'=>[...]] */
function crs_collect(PDO $pdo, array $in, int $id): array
{
    $title = trim((string)($in['title'] ?? ''));
    $slug  = trim((string)($in['slug'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200 || $slug === '' || mb_strlen($slug) > 190
        || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        crs_fail(422, 'أدخل اسم الكورس وSlug إنجليزي صالحًا مثل nursing-basics.');
    }
    $titleEn = crs_opt_text($in['title_en'] ?? null, 200);
    if ($titleEn === false) crs_fail(422, 'الاسم الإنجليزي طويل جدًا.');

    $level  = (string)($in['level'] ?? 'beginner');
    $status = (string)($in['status'] ?? 'draft');
    if (!in_array($level, CRS_LEVELS, true) || !in_array($status, CRS_STATUSES, true)) {
        crs_fail(422, 'المستوى أو حالة النشر غير صالحين.');
    }

    $price = crs_opt_num($in['price'] ?? 0, 0, 99999999);
    if ($price === false || $price === null) crs_fail(422, 'السعر غير صالح.');
    $sale = crs_opt_num($in['sale_price'] ?? null, 0, 99999999);
    if ($sale === false) crs_fail(422, 'السعر بعد الخصم غير صالح.');
    if ($sale !== null && $sale >= $price) crs_fail(422, 'السعر بعد الخصم يجب أن يكون أقل من السعر الأصلي.');

    $duration = crs_opt_int($in['duration_minutes'] ?? null, 0, 10000000);
    $seats    = crs_opt_int($in['seats_limit'] ?? null, 1, 1000000);
    if ($duration === false) crs_fail(422, 'مدة الكورس غير صالحة.');
    if ($seats === false)    crs_fail(422, 'عدد المقاعد يجب أن يكون رقمًا صحيحًا أكبر من صفر.');
    if ($id > 0 && $seats !== null && crs_registered($pdo, $id) > $seats) {
        crs_fail(422, 'عدد المقاعد أقل من عدد الطلاب المسجلين حاليًا.');
    }

    $start = crs_opt_date($in['start_date'] ?? null);
    $end   = crs_opt_date($in['end_date'] ?? null);
    if ($start === false || $end === false) crs_fail(422, 'تاريخ البداية أو النهاية غير صالح.');
    if ($start !== null && $end !== null && $end < $start) crs_fail(422, 'تاريخ النهاية قبل تاريخ البداية.');

    $progress = crs_opt_int($in['completion_min_progress'] ?? 100, 1, 100);
    if ($progress === false || $progress === null) crs_fail(422, 'نسبة إتمام الكورس يجب أن تكون بين 1 و100.');

    $description = crs_opt_text($in['description'] ?? null, 10000);
    $category    = crs_opt_text($in['category'] ?? null, 120);
    $prereq      = crs_opt_text($in['prerequisites'] ?? null, 5000);
    $outcomes    = crs_opt_text($in['learning_outcomes'] ?? null, 5000);
    $rules       = crs_opt_text($in['completion_rules'] ?? null, 5000);
    foreach ([$description, $category, $prereq, $outcomes, $rules] as $t) {
        if ($t === false) crs_fail(422, 'أحد الحقول النصية أطول من المسموح.');
    }

    // شروط الإتمام تُحفظ في completion_rules_json مع الحفاظ على أي مفاتيح أخرى موجودة
    $json = [];
    if ($id > 0) {
        $q = $pdo->prepare('SELECT completion_rules_json FROM courses WHERE id=?');
        $q->execute([$id]);
        $old = json_decode((string)$q->fetchColumn(), true);
        if (is_array($old)) $json = $old;
    }
    $json['min_progress'] = $progress;
    $json['rules'] = $rules;

    // المحاضرون: الرئيسي + المشاركون
    $primary = (int)($in['instructor_id'] ?? 0);
    if ($primary < 1) crs_fail(422, 'اختَر المحاضر الرئيسي.');
    $ids = [$primary];
    $extra = $in['instructor_ids'] ?? [];
    foreach (is_array($extra) ? $extra : [] as $x) {
        $x = (int)$x;
        if ($x > 0 && !in_array($x, $ids, true)) $ids[] = $x;
    }
    if (count($ids) > 10) crs_fail(422, 'الحد الأقصى 10 محاضرين للكورس.');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (role='instructor' OR id IN (SELECT user_id FROM user_roles WHERE role='instructor') OR id IN (SELECT instructor_id FROM courses WHERE instructor_id IS NOT NULL)) AND status='active' AND id IN ($ph)");
    $q->execute($ids);
    if ((int)$q->fetchColumn() !== count($ids)) crs_fail(422, 'اختَر حسابات محاضرين نشطة فقط.');

    return [
        'data' => [
            'instructor_id' => $primary, 'title' => $title, 'title_ar' => $title, 'title_en' => $titleEn,
            'slug' => $slug, 'description' => $description, 'category' => $category, 'level' => $level,
            'price' => $price, 'discounted_price' => $sale, 'duration_minutes' => $duration,
            'prerequisites' => $prereq, 'learning_outcomes' => $outcomes,
            'completion_rules_json' => json_encode($json, JSON_UNESCAPED_UNICODE),
            'starts_at' => $start ? $start . ' 00:00:00' : null,
            'ends_at'   => $end ? $end . ' 23:59:59' : null,
            'max_seats' => $seats, 'status' => $status,
        ],
        'instructors' => $ids,
    ];
}

/** إدراج/تحديث الكورس (المستدعي مسؤول عن المعاملة) */
function crs_write(PDO $pdo, int $id, array $d, array $instructors): int
{
    if ($id === 0) {
        $cols = array_keys($d);
        $sql = 'INSERT INTO courses (' . implode(',', $cols) . ',published_at) VALUES ('
             . implode(',', array_fill(0, count($cols), '?')) . ',?)';
        $pdo->prepare($sql)->execute([...array_values($d), $d['status'] === 'published' ? date('Y-m-d H:i:s') : null]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $set = implode(',', array_map(fn($c) => "$c=?", array_keys($d)));
        $sql = "UPDATE courses SET $set,
                published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE published_at END
                WHERE id=?";
        $pdo->prepare($sql)->execute([...array_values($d), $d['status'], $id]);
    }

    // مزامنة المحاضرين مع الحفاظ على صفوف course_instructors الموجودة
    $ph = implode(',', array_fill(0, count($instructors), '?'));
    $pdo->prepare("DELETE FROM course_instructors WHERE course_id=? AND instructor_id NOT IN ($ph)")
        ->execute([$id, ...$instructors]);
    $has = $pdo->prepare('SELECT id FROM course_instructors WHERE course_id=? AND instructor_id=? LIMIT 1');
    $upd = $pdo->prepare('UPDATE course_instructors SET is_primary=? WHERE id=?');
    $ins = $pdo->prepare('INSERT INTO course_instructors (course_id,instructor_id,is_primary,assigned_by) VALUES (?,?,?,?)');
    $actor = (int)($_SESSION['user_id'] ?? 0) ?: null;
    foreach ($instructors as $uid) {
        $isPrimary = $uid === (int)$d['instructor_id'] ? 1 : 0;
        $has->execute([$id, $uid]);
        $rowId = $has->fetchColumn();
        if ($rowId) $upd->execute([$isPrimary, $rowId]);
        else $ins->execute([$id, $uid, $isPrimary, $actor]);
    }
    return $id;
}

/* ---------------------------------------------------------------
   المنهج: وحدات + دروس
---------------------------------------------------------------- */
function crs_curriculum_load(PDO $pdo, int $courseId): array
{
    $u = $pdo->prepare('SELECT id,title FROM course_sections WHERE course_id=? ORDER BY sort_order,id');
    $u->execute([$courseId]);
    $units = $u->fetchAll();
    $l = $pdo->prepare("SELECT sl.section_id,l.id,l.title,l.duration_minutes,l.video_url,l.is_preview AS is_free_preview
                        FROM course_section_lessons sl
                        JOIN course_sections s ON s.id=sl.section_id
                        JOIN course_lessons l ON l.id=sl.lesson_id
                        WHERE s.course_id=? AND sl.lesson_source='course_lessons'
                        ORDER BY sl.sort_order,sl.id");
    $l->execute([$courseId]);
    $byUnit = [];
    foreach ($l->fetchAll() as $row) {
        $row['is_free_preview'] = (int)$row['is_free_preview'];
        $byUnit[$row['section_id']][] = $row;
    }
    $fileStmt = $pdo->prepare('SELECT id,lesson_id,original_name,file_path,mime_type,size_bytes FROM course_lesson_files WHERE course_id=? ORDER BY id');
    $fileStmt->execute([$courseId]);
    $filesByLesson = [];
    foreach ($fileStmt->fetchAll() as $file) $filesByLesson[$file['lesson_id']][] = $file;
    foreach ($byUnit as &$unitLessons) foreach ($unitLessons as &$lesson) $lesson['files'] = $filesByLesson[$lesson['id']] ?? [];
    unset($unitLessons, $lesson);
    foreach ($units as &$unit) $unit['lessons'] = $byUnit[$unit['id']] ?? [];
    return $units;
}

function crs_curriculum_save(PDO $pdo, int $courseId, $units): void
{
    if (!is_array($units) || count($units) > 100) crs_fail(422, 'المنهج غير صالح (الحد الأقصى 100 وحدة).');
    $clean = [];
    $lessonTotal = 0;
    foreach ($units as $u) {
        $t = trim((string)($u['title'] ?? ''));
        if ($t === '' || mb_strlen($t) > 200) crs_fail(422, 'عنوان كل وحدة مطلوب ولا يزيد عن 200 حرف.');
        $ls = [];
        foreach ((is_array($u['lessons'] ?? null) ? $u['lessons'] : []) as $l) {
            $lt = trim((string)($l['title'] ?? ''));
            if ($lt === '' || mb_strlen($lt) > 200) crs_fail(422, 'عنوان كل درس مطلوب ولا يزيد عن 200 حرف.');
            $dur = crs_opt_int($l['duration_minutes'] ?? null, 0, 10000);
            if ($dur === false) crs_fail(422, 'مدة الدرس غير صالحة.');
            $url = trim((string)($l['video_url'] ?? ''));
            $isManagedVideo = preg_match('#^uploads/course-content/videos/[a-f0-9]{40}\\.(mp4|webm|mov)$#i', $url) === 1;
            if ($url !== '' && !$isManagedVideo) {
                // Keep previously saved external media intact, but do not allow new URL-based video entries.
                $legacy = false;
                if ((int)($l['id'] ?? 0) > 0) {
                    $checkLegacy = $pdo->prepare('SELECT 1 FROM course_lessons WHERE id=? AND course_id=? AND video_url=? LIMIT 1');
                    $checkLegacy->execute([(int)$l['id'], $courseId, $url]);
                    $legacy = (bool)$checkLegacy->fetchColumn();
                }
                if (!$legacy) crs_fail(422, 'ارفع فيديو الدرس من جهازك بدل إدخال رابط.');
            }
            $ls[] = ['id' => (int)($l['id'] ?? 0), 'title' => $lt, 'dur' => $dur,
                     'url' => $url !== '' ? $url : null, 'free' => !empty($l['is_free_preview']) ? 1 : 0];
            $lessonTotal++;
        }
        $clean[] = ['id' => (int)($u['id'] ?? 0), 'title' => $t, 'lessons' => $ls];
    }
    if ($lessonTotal > 1000) crs_fail(422, 'الحد الأقصى 1000 درس للكورس.');

    $exU = $pdo->prepare('SELECT id FROM course_sections WHERE course_id=?');
    $exU->execute([$courseId]);
    $existingU = array_flip(array_map('intval', $exU->fetchAll(PDO::FETCH_COLUMN)));
    $exL = $pdo->prepare("SELECT sl.lesson_id FROM course_section_lessons sl
                          JOIN course_sections s ON s.id=sl.section_id
                          WHERE s.course_id=? AND sl.lesson_source='course_lessons'");
    $exL->execute([$courseId]);
    $existingL = array_flip(array_map('intval', $exL->fetchAll(PDO::FETCH_COLUMN)));

    // لا نحذف وحدة تحتوي دروسًا من المصدر الآخر (جدول lessons)
    $keepIds = [];
    foreach ($clean as $u) if ($u['id'] > 0) $keepIds[$u['id']] = true;
    $ext = $pdo->prepare("SELECT COUNT(*) FROM course_section_lessons WHERE section_id=? AND lesson_source<>'course_lessons'");
    foreach (array_keys($existingU) as $sid) {
        if (isset($keepIds[$sid])) continue;
        $ext->execute([$sid]);
        if ((int)$ext->fetchColumn() > 0) crs_fail(422, 'لا يمكن حذف وحدة تحتوي دروسًا مرتبطة من مصدر آخر.');
    }

    $updU = $pdo->prepare('UPDATE course_sections SET title=?,sort_order=? WHERE id=? AND course_id=?');
    $insU = $pdo->prepare("INSERT INTO course_sections (course_id,title,sort_order,status) VALUES (?,?,?,'published')");
    $updL = $pdo->prepare('UPDATE course_lessons SET title=?,duration_minutes=?,video_url=?,is_preview=?,sort_order=? WHERE id=? AND course_id=?');
    $insL = $pdo->prepare("INSERT INTO course_lessons (course_id,title,duration_minutes,video_url,is_preview,sort_order,status) VALUES (?,?,?,?,?,?,'published')");
    $updK = $pdo->prepare("UPDATE course_section_lessons SET section_id=?,sort_order=? WHERE lesson_source='course_lessons' AND lesson_id=?");
    $insK = $pdo->prepare("INSERT INTO course_section_lessons (section_id,lesson_source,lesson_id,sort_order) VALUES (?,'course_lessons',?,?)");

    $pdo->beginTransaction();
    $keepU = [];
    $keepL = [];
    foreach ($clean as $ui => $u) {
        if ($u['id'] > 0 && isset($existingU[$u['id']])) {
            $updU->execute([$u['title'], $ui + 1, $u['id'], $courseId]);
            $sid = $u['id'];
        } else {
            $insU->execute([$courseId, $u['title'], $ui + 1]);
            $sid = (int)$pdo->lastInsertId();
        }
        $keepU[$sid] = true;
        foreach ($u['lessons'] as $li => $l) {
            if ($l['id'] > 0 && isset($existingL[$l['id']])) {
                $updL->execute([$l['title'], $l['dur'], $l['url'], $l['free'], $li + 1, $l['id'], $courseId]);
                $updK->execute([$sid, $li + 1, $l['id']]);
                $keepL[$l['id']] = true;
            } else {
                $insL->execute([$courseId, $l['title'], $l['dur'], $l['url'], $l['free'], $li + 1]);
                $lid = (int)$pdo->lastInsertId();
                $insK->execute([$sid, $lid, $li + 1]);
                $keepL[$lid] = true;
            }
        }
    }
    $delK = $pdo->prepare("DELETE FROM course_section_lessons WHERE lesson_source='course_lessons' AND lesson_id=?");
    $delL = $pdo->prepare('DELETE FROM course_lessons WHERE id=? AND course_id=?');
    foreach (array_keys($existingL) as $lid) {
        if (!isset($keepL[$lid])) { $delK->execute([$lid]); $delL->execute([$lid, $courseId]); }
    }
    $delU = $pdo->prepare('DELETE FROM course_sections WHERE id=? AND course_id=?');
    foreach (array_keys($existingU) as $sid) if (!isset($keepU[$sid])) $delU->execute([$sid, $courseId]);
    $pdo->commit();
}

/* ---------------------------------------------------------------
   بداية المعالجة
---------------------------------------------------------------- */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0;
if ($isMultipart) {
    $input = $_POST;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
}

try {
    /* ============================ GET ============================ */
    if ($method === 'GET') {
        if (($_GET['instructors'] ?? '') === '1') {
            $instructors = $pdo->query("SELECT DISTINCT u.id, u.full_name FROM users u WHERE (u.role='instructor' OR u.id IN (SELECT ur.user_id FROM user_roles ur WHERE ur.role='instructor') OR u.id IN (SELECT DISTINCT c.instructor_id FROM courses c WHERE c.instructor_id IS NOT NULL)) AND u.status='active' ORDER BY u.full_name")->fetchAll();
            admin_json(200, ['success' => true, 'csrf_token' => $_SESSION['csrf_token'], 'data' => $instructors]);
        }

        if (isset($_GET['curriculum'])) {
            $cid = (int)$_GET['curriculum'];
            admin_json(200, ['success' => true, 'csrf_token' => $_SESSION['csrf_token'], 'data' => crs_curriculum_load($pdo, $cid)]);
        }

        if (isset($_GET['coupons'])) {
            $q = $pdo->prepare("SELECT id,code,discount_type,discount_value,max_redemptions AS max_uses,redeemed_count AS used_count,
                                       starts_at,expires_at,IF(is_active=1,'active','disabled') AS status,created_at
                                FROM course_coupons WHERE course_id=? ORDER BY id DESC");
            $q->execute([(int)$_GET['coupons']]);
            admin_json(200, ['success' => true, 'csrf_token' => $_SESSION['csrf_token'], 'data' => $q->fetchAll()]);
        }

        $search = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');
        $sql = "SELECT c.id,c.instructor_id,c.title,c.title_en,c.slug,c.description,c.category,c.level,c.price,
                       c.discounted_price AS sale_price,c.thumbnail AS cover_image,c.duration_minutes,
                       c.prerequisites,c.learning_outcomes,c.completion_rules_json,
                       DATE(c.starts_at) AS start_date,DATE(c.ends_at) AS end_date,c.max_seats AS seats_limit,
                       c.status,c.created_at,
                       u.full_name AS instructor_name,
                       (SELECT GROUP_CONCAT(ci.instructor_id) FROM course_instructors ci WHERE ci.course_id=c.id) AS instructor_ids,
                       (SELECT GROUP_CONCAT(u2.full_name ORDER BY u2.full_name SEPARATOR '، ')
                          FROM course_instructors ci2 JOIN users u2 ON u2.id=ci2.instructor_id
                         WHERE ci2.course_id=c.id AND ci2.instructor_id<>c.instructor_id) AS co_instructor_names,
                       (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status='active') AS active_students,
                       (SELECT COUNT(*) FROM enrollments e2 WHERE e2.course_id=c.id AND e2.status IN ('active','completed')) AS registered_students,
                       (SELECT COUNT(*) FROM course_sections cu WHERE cu.course_id=c.id) AS units_count,
                       (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id=c.id) AS lessons_count,
                       (SELECT COUNT(*) FROM course_lessons cl2 WHERE cl2.course_id=c.id) AS lectures_total
                FROM courses c LEFT JOIN users u ON u.id=c.instructor_id";
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(c.title LIKE :q1 OR c.slug LIKE :q2 OR c.category LIKE :q3 OR c.title_en LIKE :q4)';
            $like = '%' . $search . '%';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like; $params['q4'] = $like;
        }
        if (in_array($status, CRS_STATUSES, true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.created_at DESC, c.id DESC LIMIT 100';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $j = json_decode((string)($r['completion_rules_json'] ?? ''), true);
            $r['completion_min_progress'] = is_array($j) && isset($j['min_progress']) ? (int)$j['min_progress'] : 100;
            $r['completion_rules'] = is_array($j) ? ($j['rules'] ?? '') : '';
            unset($r['completion_rules_json']);
        }
        unset($r);
        admin_json(200, ['success' => true, 'csrf_token' => $_SESSION['csrf_token'], 'data' => $rows]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        crs_fail(405, 'الطريقة غير مسموح بها.');
    }

    $action = (string)($input['action'] ?? '');

    if ($action === 'lesson_video_upload' || $action === 'lesson_file_upload') {
        $courseId = (int)($input['course_id'] ?? 0);
        $lessonId = (int)($input['lesson_id'] ?? 0);
        $owned = $pdo->prepare('SELECT video_url FROM course_lessons WHERE id=? AND course_id=? LIMIT 1');
        $owned->execute([$lessonId, $courseId]);
        $lesson = $owned->fetch();
        if (!$lesson) crs_fail(404, 'الدرس غير موجود في هذا الكورس.');
        $isVideo = $action === 'lesson_video_upload';
        if ($isVideo) {
            $saved = crs_lesson_upload($_FILES['video_file'] ?? [], 'videos');
            $pdo->prepare('UPDATE course_lessons SET video_url=? WHERE id=? AND course_id=?')->execute([$saved['path'], $lessonId, $courseId]);
            crs_lesson_upload_delete((string)($lesson['video_url'] ?? ''), 'videos');
        } else {
            $files = $_FILES['lesson_files'] ?? null;
            if (!$files || !isset($files['name']) || !is_array($files['name']) || count($files['name']) < 1 || count($files['name']) > 10) crs_fail(422, 'اختر من ملف واحد إلى 10 ملفات.');
            $normalized = [];
            for ($i = 0; $i < count($files['name']); $i++) {
                $normalized[$i] = ['name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
                $temp = $normalized[$i]['tmp_name'];
                if ($normalized[$i]['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($temp) || (int)$normalized[$i]['size'] < 1 || (int)$normalized[$i]['size'] > 35 * 1024 * 1024) crs_fail(422, 'تحقق من الملفات المختارة، وحجم كل ملف لا يتجاوز 35 ميجابايت.');
                $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($temp));
                $ext = strtolower(pathinfo((string)$normalized[$i]['name'], PATHINFO_EXTENSION));
                $allowedFiles = ['application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.ms-powerpoint'=>'ppt','application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','text/plain'=>'txt'];
                if (!isset($allowedFiles[$mime]) || $allowedFiles[$mime] !== $ext) crs_fail(422, 'صيغة أحد الملفات غير مدعومة.');
            }
            $savedFiles = [];
            try {
                $pdo->beginTransaction();
                foreach ($normalized as $file) {
                    $saved = crs_lesson_upload($file, 'files');
                    $savedFiles[] = $saved;
                    $pdo->prepare('INSERT INTO course_lesson_files (course_id,lesson_id,original_name,file_path,mime_type,size_bytes) VALUES (?,?,?,?,?,?)')
                        ->execute([$courseId, $lessonId, $saved['original'], $saved['path'], $saved['mime'], $saved['size']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($savedFiles as $saved) @unlink($saved['absolute']);
                throw $e;
            }
        }
        admin_json(200, ['success' => true, 'message' => $isVideo ? 'تم رفع الفيديو من الجهاز.' : 'تم رفع الملفات من الجهاز.']);
    }

    if ($action === 'lesson_video_remove') {
        $courseId = (int)($input['course_id'] ?? 0);
        $lessonId = (int)($input['lesson_id'] ?? 0);
        $q = $pdo->prepare('SELECT video_url FROM course_lessons WHERE id=? AND course_id=? LIMIT 1');
        $q->execute([$lessonId, $courseId]);
        $path = $q->fetchColumn();
        if ($path === false) crs_fail(404, 'الدرس غير موجود في هذا الكورس.');
        $pdo->prepare('UPDATE course_lessons SET video_url=NULL WHERE id=? AND course_id=?')->execute([$lessonId, $courseId]);
        crs_lesson_upload_delete((string)$path, 'videos');
        admin_json(200, ['success' => true, 'message' => 'تم حذف فيديو الدرس.']);
    }

    if ($action === 'lesson_file_delete') {
        $fileId = (int)($input['file_id'] ?? 0);
        $q = $pdo->prepare('SELECT file_path FROM course_lesson_files WHERE id=?');
        $q->execute([$fileId]);
        $path = $q->fetchColumn();
        if (!$path) crs_fail(404, 'الملف غير موجود.');
        $pdo->prepare('DELETE FROM course_lesson_files WHERE id=?')->execute([$fileId]);
        crs_lesson_upload_delete((string)$path, 'files');
        admin_json(200, ['success' => true, 'message' => 'تم حذف الملف.']);
    }

    /* ===================== إنشاء / تعديل ===================== */
    if ($action === 'create' || $action === 'update') {
        $id = (int)($input['id'] ?? 0);
        if ($action === 'update') {
            if ($id < 1) crs_fail(422, 'معرّف الكورس غير صالح.');
            $check = $pdo->prepare('SELECT id FROM courses WHERE id=?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) crs_fail(404, 'الكورس غير موجود.');
        } else {
            $id = 0;
        }
        $c = crs_collect($pdo, $input, $id);

        $pdo->beginTransaction();
        $id = crs_write($pdo, $id, $c['data'], $c['instructors']);
        $pdo->commit();

        crs_audit($pdo, $action === 'create' ? 'course.created' : 'course.updated', $id,
            ['title' => $c['data']['title'], 'status' => $c['data']['status']]);
        admin_json(200, ['success' => true, 'message' => $action === 'create' ? 'تم إنشاء الكورس.' : 'تم تحديث الكورس.', 'id' => $id]);
    }

    /* ===================== نشر / إلغاء نشر ===================== */
    if ($action === 'status') {
        $id = (int)($input['id'] ?? 0);
        $status = (string)($input['status'] ?? '');
        if ($id < 1 || !in_array($status, CRS_STATUSES, true)) crs_fail(422, 'معرّف الكورس أو الحالة غير صالحين.');
        $check = $pdo->prepare('SELECT id FROM courses WHERE id=?');
        $check->execute([$id]);
        if (!$check->fetchColumn()) crs_fail(404, 'الكورس غير موجود.');
        $pdo->prepare("UPDATE courses SET status=?, published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE published_at END WHERE id=?")
            ->execute([$status, $status, $id]);
        crs_audit($pdo, 'course.status_changed', $id, ['status' => $status]);
        admin_json(200, ['success' => true, 'message' => 'تم تحديث حالة الكورس.']);
    }

    /* ===================== نسخ كورس ===================== */
    if ($action === 'duplicate') {
        $srcId = (int)($input['id'] ?? 0);
        $s = $pdo->prepare('SELECT * FROM courses WHERE id=?');
        $s->execute([$srcId]);
        $src = $s->fetch();
        if (!$src) crs_fail(404, 'الكورس غير موجود.');

        $d = [];
        foreach (CRS_COLS as $col) $d[$col] = $src[$col] ?? null;
        $d['title']  = mb_substr((string)$src['title'], 0, 185) . ' (نسخة)';
        $d['status'] = 'draft';
        $base = substr((string)$src['slug'], 0, 80) . '-copy';
        $slug = $base;
        $exists = $pdo->prepare('SELECT 1 FROM courses WHERE slug=?');
        for ($n = 2; $n < 100; $n++) {
            $exists->execute([$slug]);
            if (!$exists->fetchColumn()) break;
            $slug = $base . '-' . $n;
        }
        $d['slug'] = $slug;

        $ci = $pdo->prepare('SELECT instructor_id FROM course_instructors WHERE course_id=?');
        $ci->execute([$srcId]);
        $ids = array_map('intval', $ci->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array((int)$src['instructor_id'], $ids, true)) array_unshift($ids, (int)$src['instructor_id']);

        $pdo->beginTransaction();
        $newId = crs_write($pdo, 0, $d, $ids);

        // نسخ المنهج (الوحدات والدروس) — بدون الطلاب أو الكوبونات
        $secs = $pdo->prepare('SELECT * FROM course_sections WHERE course_id=? ORDER BY sort_order,id');
        $secs->execute([$srcId]);
        $iu = $pdo->prepare('INSERT INTO course_sections (course_id,title,description,sort_order,status) VALUES (?,?,?,?,?)');
        $ls = $pdo->prepare("SELECT l.*,sl.sort_order AS link_order FROM course_section_lessons sl
                             JOIN course_lessons l ON l.id=sl.lesson_id
                             WHERE sl.section_id=? AND sl.lesson_source='course_lessons' ORDER BY sl.sort_order,sl.id");
        $il = $pdo->prepare('INSERT INTO course_lessons (course_id,title,description,video_url,content,sort_order,duration_minutes,is_preview,status) VALUES (?,?,?,?,?,?,?,?,?)');
        $ik = $pdo->prepare("INSERT INTO course_section_lessons (section_id,lesson_source,lesson_id,sort_order) VALUES (?,'course_lessons',?,?)");
        foreach ($secs->fetchAll() as $sec) {
            $iu->execute([$newId, $sec['title'], $sec['description'], $sec['sort_order'], $sec['status']]);
            $nsid = (int)$pdo->lastInsertId();
            $ls->execute([$sec['id']]);
            foreach ($ls->fetchAll() as $l) {
                $il->execute([$newId, $l['title'], $l['description'], $l['video_url'], $l['content'], $l['sort_order'], $l['duration_minutes'], $l['is_preview'], $l['status']]);
                $ik->execute([$nsid, (int)$pdo->lastInsertId(), $l['link_order']]);
            }
        }

        // نسخ صورة الغلاف فعليًا حتى لا يتأثر أحد الكورسين بحذف الآخر
        $cover = (string)($src['thumbnail'] ?? '');
        if ($cover !== '' && strpos($cover, CRS_COVER_URL) === 0) {
            $from = crs_cover_dir() . '/' . basename($cover);
            if (is_file($from)) {
                $ext = pathinfo($from, PATHINFO_EXTENSION);
                $name = 'course-' . $newId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (@copy($from, crs_cover_dir() . '/' . $name)) {
                    $pdo->prepare('UPDATE courses SET thumbnail=? WHERE id=?')->execute([CRS_COVER_URL . $name, $newId]);
                }
            }
        }
        $pdo->commit();

        crs_audit($pdo, 'course.duplicated', $newId, ['source_id' => $srcId]);
        admin_json(200, ['success' => true, 'message' => 'تم نسخ الكورس كمسودة جديدة.', 'id' => $newId]);
    }

    /* ===================== صورة الغلاف ===================== */
    if ($action === 'cover_upload') {
        $id = (int)($input['id'] ?? 0);
        $s = $pdo->prepare('SELECT thumbnail FROM courses WHERE id=?');
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) crs_fail(404, 'الكورس غير موجود.');

        $f = $_FILES['cover'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) crs_fail(422, 'تعذر رفع الصورة.');
        if ($f['size'] > CRS_COVER_MAX) crs_fail(422, 'حجم الصورة أكبر من 3 ميجا.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($exts[$mime]) || !@getimagesize($f['tmp_name'])) crs_fail(422, 'الصيغة المسموحة: JPG أو PNG أو WEBP.');

        crs_cover_prepare_dir();
        $name = 'course-' . $id . '-' . bin2hex(random_bytes(8)) . '.' . $exts[$mime];
        if (!move_uploaded_file($f['tmp_name'], crs_cover_dir() . '/' . $name)) crs_fail(500, 'تعذر حفظ الصورة.');
        $rel = CRS_COVER_URL . $name;
        $pdo->prepare('UPDATE courses SET thumbnail=? WHERE id=?')->execute([$rel, $id]);
        crs_cover_delete($row['thumbnail'] ?? null);
        crs_audit($pdo, 'course.cover_changed', $id, []);
        admin_json(200, ['success' => true, 'message' => 'تم رفع صورة الغلاف.', 'cover_image' => $rel]);
    }

    if ($action === 'cover_remove') {
        $id = (int)($input['id'] ?? 0);
        $s = $pdo->prepare('SELECT thumbnail FROM courses WHERE id=?');
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) crs_fail(404, 'الكورس غير موجود.');
        $pdo->prepare('UPDATE courses SET thumbnail=NULL WHERE id=?')->execute([$id]);
        crs_cover_delete($row['thumbnail'] ?? null);
        admin_json(200, ['success' => true, 'message' => 'تمت إزالة صورة الغلاف.']);
    }

    /* ===================== المنهج ===================== */
    if ($action === 'curriculum_save') {
        $cid = (int)($input['course_id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM courses WHERE id=?');
        $check->execute([$cid]);
        if (!$check->fetchColumn()) crs_fail(404, 'الكورس غير موجود.');
        crs_curriculum_save($pdo, $cid, $input['units'] ?? []);
        crs_audit($pdo, 'course.curriculum_saved', $cid, []);
        admin_json(200, ['success' => true, 'message' => 'تم حفظ المنهج.', 'data' => crs_curriculum_load($pdo, $cid)]);
    }

    /* ===================== الكوبونات ===================== */
    if ($action === 'coupon_create') {
        $cid = (int)($input['course_id'] ?? 0);
        $s = $pdo->prepare('SELECT price FROM courses WHERE id=?');
        $s->execute([$cid]);
        $price = $s->fetchColumn();
        if ($price === false) crs_fail(404, 'الكورس غير موجود.');

        $code = strtoupper(trim((string)($input['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{3,64}$/', $code)) crs_fail(422, 'الكود: حروف إنجليزية وأرقام فقط (من 3 إلى 64).');
        $type = (string)($input['discount_type'] ?? '');
        if (!in_array($type, ['percent', 'fixed'], true)) crs_fail(422, 'نوع الخصم غير صالح.');
        $value = crs_opt_num($input['discount_value'] ?? null, 0.01, 99999999);
        if ($value === false || $value === null) crs_fail(422, 'قيمة الخصم غير صالحة.');
        if ($type === 'percent' && $value > 100) crs_fail(422, 'نسبة الخصم لا تزيد عن 100٪.');
        if ($type === 'fixed' && (float)$price > 0 && $value > (float)$price) crs_fail(422, 'قيمة الخصم أكبر من سعر الكورس.');
        $max = crs_opt_int($input['max_uses'] ?? null, 1, 10000000);
        if ($max === false) crs_fail(422, 'عدد مرات الاستخدام غير صالح.');
        $from = crs_opt_date($input['starts_at'] ?? null);
        $to   = crs_opt_date($input['expires_at'] ?? null);
        if ($from === false || $to === false) crs_fail(422, 'تاريخ الكوبون غير صالح.');
        if ($from !== null && $to !== null && $to < $from) crs_fail(422, 'تاريخ انتهاء الكوبون قبل بدايته.');

        try {
            $pdo->prepare("INSERT INTO course_coupons (course_id,code,discount_type,discount_value,max_redemptions,starts_at,expires_at,created_by)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$cid, $code, $type, $value, $max, $from ? $from . ' 00:00:00' : null, $to ? $to . ' 23:59:59' : null, (int)$_SESSION['user_id']]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') crs_fail(409, 'كود الكوبون مستخدم بالفعل.');
            throw $e;
        }
        crs_audit($pdo, 'course.coupon_created', $cid, ['code' => $code]);
        admin_json(200, ['success' => true, 'message' => 'تم إضافة الكوبون.']);
    }

    if ($action === 'coupon_toggle') {
        $couponId = (int)($input['id'] ?? 0);
        $st = (string)($input['status'] ?? '');
        if ($couponId < 1 || !in_array($st, ['active', 'disabled'], true)) crs_fail(422, 'بيانات الكوبون غير صالحة.');
        $pdo->prepare('UPDATE course_coupons SET is_active=? WHERE id=?')->execute([$st === 'active' ? 1 : 0, $couponId]);
        admin_json(200, ['success' => true, 'message' => 'تم تحديث الكوبون.']);
    }

    crs_fail(422, 'الإجراء المطلوب غير معروف.');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[NBC admin courses] ' . $e->getMessage());
    if ((string)$e->getCode() === '23000') {
        crs_fail(409, 'الـ Slug مستخدم بالفعل أو توجد علاقة تمنع العملية.');
    }
    crs_fail(500, 'تعذر تنفيذ عملية الكورس.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[NBC admin courses] ' . $e->getMessage());
    crs_fail(500, 'حدث خطأ غير متوقع.');
}
