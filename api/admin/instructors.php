<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';

const INSTRUCTOR_PERMISSIONS = [
    'courses_view', 'lessons_manage', 'students_view',
    'progress_view', 'quizzes_manage', 'announcements',
];

const AUDIT_LABELS = [
    'instructor.created'        => 'إنشاء حساب المحاضر',
    'instructor.updated'        => 'تعديل بيانات المحاضر',
    'instructor.status_changed' => 'تغيير حالة الحساب',
    'instructor.permissions'    => 'تعديل الصلاحيات',
];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** ينشئ جدول البيانات الإضافية إن لم يكن موجودًا (أو شغّل sql/instructor_details.sql يدويًا). */
function ensure_details_table(PDO $pdo): void
{
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
        error_log('[NBC instructor_details create] ' . $e->getMessage());
    }
}

function audit(PDO $pdo, string $action, int $id, array $details = []): void
{
    $pdo->prepare("INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?)")
        ->execute([(int)$_SESSION['user_id'], $action, 'user', (string)$id, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

function require_instructor(PDO $pdo, int $id): void
{
    $check = $pdo->prepare("SELECT id FROM users WHERE id=? AND (role='instructor' OR id IN (SELECT user_id FROM user_roles WHERE role='instructor') OR id IN (SELECT instructor_id FROM courses WHERE instructor_id IS NOT NULL))");
    $check->execute([$id]);
    if (!$check->fetchColumn()) admin_json(404, ['success' => false, 'message' => 'المحاضر غير موجود.']);
}

/* =========================================================
   GET: قائمة المحاضرين / ملف محاضر
========================================================= */
if ($method === 'GET') {
    ensure_details_table($pdo);

    try {
        $id = (int)($_GET['id'] ?? 0);

        /* ---------- ملف محاضر ---------- */
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT id, full_name, email, phone, age, gender, specialization, experience_years, workplace, experience, cv_url, bio, extra_info, avatar, status, created_at, last_login_at
                                   FROM users WHERE id=? AND (role='instructor' OR id IN (SELECT user_id FROM user_roles WHERE role='instructor') OR id IN (SELECT instructor_id FROM courses WHERE instructor_id IS NOT NULL))");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) admin_json(404, ['success' => false, 'message' => 'المحاضر غير موجود.']);

            $warnings = [];
            $section = function (string $name, callable $fn, $fallback) use (&$warnings) {
                try { return $fn(); }
                catch (Throwable $e) {
                    error_log("[NBC admin instructor detail:$name] " . $e->getMessage());
                    $warnings[] = $name;
                    return $fallback;
                }
            };

            // الصورة من جدول users أو profiles
            $avatar = $section('avatar', function () use ($pdo, $id, $user) {
                if (!empty($user['avatar'])) return (string)$user['avatar'];
                $s = $pdo->prepare("SELECT * FROM profiles WHERE user_id=? LIMIT 1");
                $s->execute([$id]);
                $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach (['avatar', 'avatar_url', 'avatar_path', 'photo', 'photo_url', 'image'] as $k) {
                    if (!empty($row[$k])) return (string)$row[$k];
                }
                return null;
            }, null);

            $details = $section('details', function () use ($pdo, $id) {
                $s = $pdo->prepare("SELECT specialization, experience_years, workplace, experience, bio, permissions_json
                                    FROM instructor_details WHERE user_id=?");
                $s->execute([$id]);
                return $s->fetch(PDO::FETCH_ASSOC) ?: [];
            }, []);

            $permissions = INSTRUCTOR_PERMISSIONS; // الافتراضي: كلها
            if (!empty($details['permissions_json'])) {
                $decoded = json_decode((string)$details['permissions_json'], true);
                if (is_array($decoded)) {
                    $permissions = array_values(array_intersect(INSTRUCTOR_PERMISSIONS, $decoded));
                }
            }
            unset($details['permissions_json']);

            $courses = $section('courses', function () use ($pdo, $id) {
                $s = $pdo->prepare(
                    "SELECT c.id, c.title, c.status,
                            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status='active') AS active_students,
                            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id) AS total_students
                     FROM courses c WHERE c.instructor_id=? ORDER BY c.id DESC"
                );
                $s->execute([$id]);
                return $s->fetchAll(PDO::FETCH_ASSOC);
            }, []);

            $lessons = $section('lessons', function () use ($pdo, $id) {
                // نقرأ أعمدة الجدول الفعلية بدل افتراض أسمائها
                $cols = $pdo->query("SHOW COLUMNS FROM lessons")->fetchAll(PDO::FETCH_COLUMN);
                $pick = static function (array $candidates) use ($cols): ?string {
                    foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
                    return null;
                };
                $title   = $pick(['title', 'title_ar', 'name', 'lesson_title']);
                $status  = $pick(['status', 'is_published', 'published']);
                $created = $pick(['created_at', 'added_at', 'created', 'date_added']);

                $sql = 'SELECT l.id, '
                     . ($title   ? "l.`$title`"   : "CONCAT('درس #', l.id)") . ' AS title, '
                     . ($status  ? "l.`$status`"  : 'NULL') . ' AS status, '
                     . ($created ? "l.`$created`" : 'NULL') . ' AS created_at, '
                     . 'c.title AS course_title '
                     . 'FROM lessons l JOIN courses c ON c.id=l.course_id '
                     . 'WHERE c.instructor_id=? ORDER BY l.id DESC LIMIT 20';
                $s = $pdo->prepare($sql);
                $s->execute([$id]);
                return $s->fetchAll(PDO::FETCH_ASSOC);
            }, []);

            $lessonsCount = $section('lessons_count', function () use ($pdo, $id) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM lessons l JOIN courses c ON c.id=l.course_id WHERE c.instructor_id=?");
                $s->execute([$id]);
                return (int)$s->fetchColumn();
            }, count($lessons));

            $activity = $section('activity', function () use ($pdo, $id) {
                $s = $pdo->prepare(
                    "SELECT action, created_at AS at FROM audit_logs
                     WHERE actor_user_id=? OR (entity_type='user' AND entity_id=?)
                     ORDER BY id DESC LIMIT 15"
                );
                $s->execute([$id, (string)$id]);
                return array_map(static fn($r) => [
                    'text' => AUDIT_LABELS[$r['action']] ?? (string)$r['action'],
                    'at'   => $r['at'],
                ], $s->fetchAll(PDO::FETCH_ASSOC));
            }, []);

            admin_json(200, [
                'success'    => true,
                'csrf_token' => $_SESSION['csrf_token'],
                'warnings'   => $warnings,
                'data'       => [
                    'profile' => array_merge($user, [
                        'avatar_url'       => $avatar,
                        'age'              => $user['age'] ?? '',
                        'gender'           => $user['gender'] ?? '',
                        'cv_url'           => $user['cv_url'] ?? '',
                        'specialization'   => !empty($user['specialization']) ? $user['specialization'] : ($details['specialization'] ?? ''),
                        'experience_years' => !empty($user['experience_years']) ? $user['experience_years'] : ($details['experience_years'] ?? ''),
                        'workplace'        => !empty($user['workplace']) ? $user['workplace'] : ($details['workplace'] ?? ''),
                        'experience'       => !empty($user['experience']) ? $user['experience'] : (!empty($user['extra_info']) ? $user['extra_info'] : ($details['experience'] ?? '')),
                        'bio'              => !empty($user['bio']) ? $user['bio'] : ($details['bio'] ?? ''),
                    ]),
                    'stats' => [
                        'courses'          => count($courses),
                        'lessons'          => $lessonsCount,
                        'students'         => array_sum(array_map(static fn($c) => (int)$c['active_students'], $courses)),
                        'last_activity_at' => $activity[0]['at'] ?? $user['last_login_at'],
                    ],
                    'courses'     => $courses,
                    'lessons'     => $lessons,
                    'activity'    => $activity,
                    'permissions' => $permissions,
                ],
            ]);
        }

        /* ---------- القائمة ---------- */
        $q = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');

        $where = "(u.role='instructor' OR u.id IN (SELECT ur.user_id FROM user_roles ur WHERE ur.role='instructor') OR u.id IN (SELECT DISTINCT c.instructor_id FROM courses c WHERE c.instructor_id IS NOT NULL) OR u.id IN (SELECT DISTINCT ci.instructor_id FROM course_instructors ci WHERE ci.instructor_id IS NOT NULL))";
        $params = [];
        if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $where .= ' AND u.status=?';
            $params[] = $status;
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $full = "SELECT u.id, u.full_name, u.email, u.phone, u.age, u.gender, u.cv_url, u.avatar AS avatar_url, u.status, u.created_at,
                        COALESCE(u.specialization, d.specialization) AS specialization,
                        COALESCE(u.workplace, d.workplace) AS workplace,
                        COALESCE(u.experience_years, d.experience_years) AS experience_years,
                        (SELECT COUNT(*) FROM courses c WHERE c.instructor_id=u.id) AS courses_count,
                        (SELECT COUNT(*) FROM lessons l JOIN courses c ON c.id=l.course_id WHERE c.instructor_id=u.id) AS lessons_count,
                        (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id=e.course_id
                          WHERE c.instructor_id=u.id AND e.status='active') AS students_count
                 FROM users u LEFT JOIN instructor_details d ON d.user_id=u.id
                 WHERE $where"
              . ($q !== '' ? ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR d.specialization LIKE ?)' : '')
              . ' ORDER BY u.created_at DESC, u.id DESC LIMIT 100';
        $fullParams = $q !== '' ? array_merge($params, [$like, $like, $like, $like]) : $params;

        $basic = "SELECT u.id, u.full_name, u.email, u.phone, u.avatar AS avatar_url, u.status, u.created_at,
                         NULL AS specialization, 0 AS courses_count, 0 AS lessons_count, 0 AS students_count
                  FROM users u WHERE $where"
               . ($q !== '' ? ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)' : '')
               . ' ORDER BY u.created_at DESC, u.id DESC LIMIT 100';
        $basicParams = $q !== '' ? array_merge($params, [$like, $like, $like]) : $params;

        try {
            $stmt = $pdo->prepare($full);
            $stmt->execute($fullParams);
        } catch (Throwable $e) {
            // جدول/عمود غير مطابق: نرجع القائمة الأساسية بدل الفشل الكامل
            error_log('[NBC admin instructors list full] ' . $e->getMessage());
            $stmt = $pdo->prepare($basic);
            $stmt->execute($basicParams);
        }

        admin_json(200, [
            'success'    => true,
            'csrf_token' => $_SESSION['csrf_token'],
            'data'       => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch (Throwable $e) {
        error_log('[NBC admin instructors] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'تعذر تحميل بيانات المحاضرين.']);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    admin_json(405, ['success' => false, 'message' => 'الطريقة غير مسموح بها.']);
}

/* =========================================================
   POST
========================================================= */
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = (string)($input['action'] ?? '');

/** يقرأ الحقول الإضافية ويتحقق منها */
function read_details(array $input): array
{
    $years = $input['experience_years'] ?? null;
    if ($years === '' || $years === null) {
        $years = null;
    } elseif (!is_numeric($years) || (int)$years < 0 || (int)$years > 60) {
        admin_json(422, ['success' => false, 'message' => 'سنوات الخبرة غير صالحة.']);
    } else {
        $years = (int)$years;
    }

    $limits = ['specialization' => 150, 'workplace' => 190, 'experience' => 2000, 'bio' => 4000];
    $out = ['experience_years' => $years];
    foreach ($limits as $key => $max) {
        $val = trim((string)($input[$key] ?? ''));
        if (mb_strlen($val) > $max) {
            admin_json(422, ['success' => false, 'message' => "الحقل ($key) أطول من المسموح."]);
        }
        $out[$key] = $val === '' ? null : $val;
    }
    return $out;
}

function save_details(PDO $pdo, int $id, array $d): void
{
    ensure_details_table($pdo);
    $pdo->prepare(
        "INSERT INTO instructor_details (user_id, specialization, experience_years, workplace, experience, bio)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE specialization=VALUES(specialization), experience_years=VALUES(experience_years),
            workplace=VALUES(workplace), experience=VALUES(experience), bio=VALUES(bio)"
    )->execute([$id, $d['specialization'], $d['experience_years'], $d['workplace'], $d['experience'], $d['bio']]);
}

try {
    /* ---------- تفعيل / تعطيل ---------- */
    if ($action === 'status') {
        $id = (int)($input['id'] ?? 0);
        $status = (string)($input['status'] ?? '');
        if ($id < 1 || !in_array($status, ['active', 'inactive', 'suspended'], true)) {
            admin_json(422, ['success' => false, 'message' => 'المحاضر أو الحالة غير صالحين.']);
        }
        require_instructor($pdo, $id);
        $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $id]);
        audit($pdo, 'instructor.status_changed', $id, ['status' => $status]);
        admin_json(200, ['success' => true, 'message' => 'تم تحديث حالة المحاضر.']);
    }

    /* ---------- رفع / تحديث الصورة ---------- */
    if ($action === 'avatar') {
        $id = (int)($input['id'] ?? 0);
        $dataUri = (string)($input['image_data'] ?? '');
        if (preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $dataUri, $matches)) {
            $bytes = base64_decode($matches[2], true);
            if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) admin_json(422, ['success'=>false, 'message'=>'حجم الصورة غير صالح أو يتجاوز 2 ميجابايت.']);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) admin_json(422, ['success'=>false, 'message'=>'محتوى الصورة غير صالح.']);
            $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
            $dir = __DIR__ . '/../../uploads/avatars';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'ins_' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            file_put_contents($dir . '/' . $filename, $bytes);
            $avatarUrl = 'uploads/avatars/' . $filename;
        } else {
            admin_json(422, ['success' => false, 'message' => 'بيانات الصورة غير صالحة.']);
        }
        if ($id < 1) admin_json(422, ['success' => false, 'message' => 'بيانات الصورة غير صالحة.']);
        require_instructor($pdo, $id);
        $pdo->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$avatarUrl, $id]);
        try {
            $pdo->prepare("UPDATE profiles SET avatar=? WHERE user_id=?")->execute([$avatarUrl, $id]);
        } catch (Throwable $e) {}
        audit($pdo, 'instructor.avatar_updated', $id);
        admin_json(200, ['success' => true, 'message' => 'تم تحديث صورة المحاضر بنجاح.', 'avatar_url' => $avatarUrl]);
    }

    /* ---------- إضافة محاضر ---------- */
    if ($action === 'create') {
        $name = trim((string)($input['full_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $phone = trim((string)($input['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            admin_json(422, ['success' => false, 'message' => 'الاسم أو البريد الإلكتروني غير صالح.']);
        }
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 4096) {
            admin_json(422, ['success' => false, 'message' => 'كلمة المرور يجب أن تكون 10 أحرف على الأقل.']);
        }
        if ($phone !== '' && strlen($phone) > 30) admin_json(422, ['success' => false, 'message' => 'رقم الهاتف طويل جدًا.']);
        $details = read_details($input);

        $stmt = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,phone,role,status) VALUES (?,?,?,?, 'instructor','active')");
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $phone ?: null]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO user_roles (user_id,role,assigned_by) VALUES (?,'instructor',?)")->execute([$id, (int)$_SESSION['user_id']]);
        $pdo->prepare("INSERT IGNORE INTO profiles (user_id,display_name) VALUES (?,?)")->execute([$id, $name]);
        if ($details['specialization'] !== null || $details['experience_years'] !== null) {
            save_details($pdo, $id, $details);
        }
        audit($pdo, 'instructor.created', $id, ['email' => $email]);
        admin_json(201, ['success' => true, 'message' => 'تم إنشاء حساب المحاضر.', 'id' => $id]);
    }

    /* ---------- تعديل البيانات ---------- */
    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['full_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        if ($id < 1) admin_json(422, ['success' => false, 'message' => 'المحاضر غير صالح.']);
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            admin_json(422, ['success' => false, 'message' => 'الاسم أو البريد الإلكتروني غير صالح.']);
        }
        if ($phone !== '' && strlen($phone) > 30) admin_json(422, ['success' => false, 'message' => 'رقم الهاتف طويل جدًا.']);
        require_instructor($pdo, $id);
        $details = read_details($input);

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
            ->execute([$name, $email, $phone ?: null, $id]);
        try {
            $pdo->prepare("UPDATE profiles SET display_name=? WHERE user_id=?")->execute([$name, $id]);
        } catch (Throwable $e) {
            error_log('[NBC admin instructors profile name] ' . $e->getMessage());
        }
        save_details($pdo, $id, $details);
        $pdo->commit();

        audit($pdo, 'instructor.updated', $id);
        admin_json(200, ['success' => true, 'message' => 'تم حفظ بيانات المحاضر.']);
    }

    /* ---------- الصلاحيات ---------- */
    if ($action === 'permissions') {
        $id = (int)($input['id'] ?? 0);
        $perms = $input['permissions'] ?? null;
        if ($id < 1 || !is_array($perms)) {
            admin_json(422, ['success' => false, 'message' => 'بيانات الصلاحيات غير صالحة.']);
        }
        $clean = array_values(array_intersect(INSTRUCTOR_PERMISSIONS, array_map('strval', $perms)));
        require_instructor($pdo, $id);
        ensure_details_table($pdo);
        $pdo->prepare(
            "INSERT INTO instructor_details (user_id, permissions_json) VALUES (?,?)
             ON DUPLICATE KEY UPDATE permissions_json=VALUES(permissions_json)"
        )->execute([$id, json_encode($clean)]);
        audit($pdo, 'instructor.permissions', $id, ['permissions' => $clean]);
        admin_json(200, ['success' => true, 'message' => 'تم حفظ الصلاحيات.']);
    }

    admin_json(422, ['success' => false, 'message' => 'الإجراء المطلوب غير معروف.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[NBC admin instructors mutation] ' . $e->getMessage());
    if ((string)$e->getCode() === '23000') admin_json(409, ['success' => false, 'message' => 'البريد الإلكتروني مسجل بالفعل.']);
    admin_json(500, ['success' => false, 'message' => 'تعذر تنفيذ العملية.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[NBC admin instructors mutation] ' . $e->getMessage());
    admin_json(500, ['success' => false, 'message' => 'حدث خطأ غير متوقع.']);
}
