<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $search = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $status = (string)($_GET['status'] ?? '');
    $offset = ($page - 1) * $perPage;

    if (mb_strlen($search) > 150) admin_json(422, ['success'=>false,'message'=>'عبارة البحث طويلة جدًا.']);
    if (!in_array($status, ['', 'active', 'inactive', 'suspended'], true)) admin_json(422, ['success'=>false,'message'=>'فلتر الحالة غير صالح.']);

    $where = ["u.role = 'student'"];
    $params = [];
    if ($search !== '') {
        $where[] = '(u.full_name LIKE :q1 OR u.email LIKE :q2 OR u.phone LIKE :q3)';
        $like = '%' . $search . '%';
        $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
    }
    if ($status !== '') { $where[] = 'u.status = :status'; $params['status'] = $status; }
    $whereSql = implode(' AND ', $where);

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.phone, u.age, u.gender, u.extra_info, u.verification_code, u.is_verified, u.avatar AS avatar_url, u.status, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = u.id AND e.status IN ('active', 'completed')) AS enrolled_count
             FROM users u
             WHERE $whereSql
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($students as &$s) {
            $s['qr_token'] = 'EDU-STU-' . $s['id'] . '-' . substr(hash('sha256', 'nbc_student_salt_' . $s['id']), 0, 8);
            if (empty($s['avatar_url'])) $s['avatar_url'] = '';
        }
        unset($s);

        admin_json(200, [
            'success' => true,
            'csrf_token' => $_SESSION['csrf_token'],
            'data' => $students,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int)ceil($total / $perPage)
            ]
        ]);
    } catch (Throwable $e) {
        error_log('[NBC admin students] ' . $e->getMessage());
        admin_json(500, ['success'=>false,'message'=>'تعذر تحميل قائمة الطلاب.']);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    admin_json(405, ['success'=>false,'message'=>'الطريقة غير مسموح بها.']);
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = (string)($input['action'] ?? '');

try {
    if ($action === 'status') {
        $id = (int)($input['id'] ?? 0);
        $status = (string)($input['status'] ?? '');
        if ($id < 1 || !in_array($status, ['active','inactive','suspended'], true)) {
            admin_json(422, ['success'=>false,'message'=>'المستخدم أو الحالة غير صالحين.']);
        }
        $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='student'");
        $stmt->execute([$status,$id]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student'"); $check->execute([$id]);
            if (!$check->fetchColumn()) admin_json(404, ['success'=>false,'message'=>'الطالب غير موجود.']);
        }
        $log = $pdo->prepare("INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?)");
        $log->execute([(int)$_SESSION['user_id'],'student.status_changed','user',(string)$id,json_encode(['status'=>$status])]);
        admin_json(200, ['success'=>true,'message'=>'تم تحديث حالة الطالب.']);
    }

    if ($action === 'create') {
        $name = trim((string)($input['full_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $phone = trim((string)($input['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) {
            admin_json(422, ['success'=>false,'message'=>'الاسم أو البريد الإلكتروني غير صالح.']);
        }
        if (!is_string($password) || strlen($password)<10 || strlen($password)>4096) {
            admin_json(422, ['success'=>false,'message'=>'كلمة المرور يجب أن تكون 10 أحرف على الأقل.']);
        }
        if ($phone !== '' && strlen($phone)>30) admin_json(422, ['success'=>false,'message'=>'رقم الهاتف طويل جدًا.']);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,phone,role,status) VALUES (?,?,?,?, 'student','active')");
        $stmt->execute([$name,$email,$hash,$phone ?: null]);
        $id = (int)$pdo->lastInsertId();
        $roleStmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id,role,assigned_by) VALUES (?,'student',?)");
        $roleStmt->execute([$id,(int)$_SESSION['user_id']]);
        $profileStmt = $pdo->prepare("INSERT IGNORE INTO profiles (user_id,display_name) VALUES (?,?)");
        $profileStmt->execute([$id,$name]);
        $log = $pdo->prepare("INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?)");
        $log->execute([(int)$_SESSION['user_id'],'student.created','user',(string)$id,json_encode(['email'=>$email])]);
        admin_json(201, ['success'=>true,'message'=>'تم إنشاء حساب الطالب.','id'=>$id]);
    }
    admin_json(422, ['success'=>false,'message'=>'الإجراء المطلوب غير معروف.']);
} catch (PDOException $e) {
    error_log('[NBC admin students mutation] ' . $e->getMessage());
    if ((string)$e->getCode() === '23000') admin_json(409, ['success'=>false,'message'=>'البريد الإلكتروني مسجل بالفعل.']);
    admin_json(500, ['success'=>false,'message'=>'تعذر تنفيذ العملية.']);
} catch (Throwable $e) {
    error_log('[NBC admin students mutation] ' . $e->getMessage());
    admin_json(500, ['success'=>false,'message'=>'حدث خطأ غير متوقع.']);
}
