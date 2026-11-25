<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    nbc_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$rawInput = file_get_contents('php://input');
$input = !empty($rawInput) ? json_decode($rawInput, true) : null;
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

if (!is_array($input)) {
    nbc_json(400, [
        'success' => false,
        'message' => 'بيانات الطلب غير صالحة.'
    ]);
}

$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($email === '' || $password === '') {
    nbc_json(422, [
        'success' => false,
        'message' => 'أدخل البريد الإلكتروني وكلمة المرور.'
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) > 4096) {
    nbc_json(422, [
        'success' => false,
        'message' => 'بيانات تسجيل الدخول غير صالحة.'
    ]);
}

$retryAfter = nbc_rate_limit('login', 10, 900, true);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    nbc_json(429, ['success'=>false, 'message'=>'محاولات تسجيل الدخول كثيرة. انتظر قليلًا ثم حاول مجددًا.']);
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, password_hash, role, status
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        nbc_json(401, [
            'success' => false,
            'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'
        ]);
    }

    nbc_rate_limit('login', 10, 900, false, true);

    if ($user['status'] === 'pending') {
        nbc_json(403, [
            'success' => false,
            'message' => 'حسابك قيد المراجعة والاعتماد من الإدارة.'
        ]);
    }

    if ($user['status'] !== 'active') {
        nbc_json(403, [
            'success' => false,
            'message' => 'هذا الحساب غير نشط أو معلق. تواصل مع الإدارة.'
        ]);
    }

    // Determine roles & primary portal
    $roles = nbc_get_user_roles((int)$user['id'], (string)$user['role']);

    // Target portal determination
    // Priority: admin -> instructor -> student
    $targetPortal = 'student';
    $redirectUrl = 'student.html';

    if (in_array('admin', $roles, true) || $user['role'] === 'admin') {
        $targetPortal = 'admin';
        $redirectUrl = 'admin.html';
    } elseif (in_array('instructor', $roles, true) || $user['role'] === 'instructor') {
        $targetPortal = 'instructor';
        $redirectUrl = 'instructor.html';
    } elseif (in_array('student', $roles, true) || $user['role'] === 'student') {
        $targetPortal = 'student';
        $redirectUrl = 'student.html';
    }

    // Rehash password if standard cost or algorithm updated
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upHash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upHash->execute([$newHash, $user['id']]);
    }

    // Initialize session for target portal
    nbc_start_session($targetPortal);
    session_regenerate_id(true);

    $csrfToken = nbc_csrf_token();
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $targetPortal;
    $_SESSION['csrf_token'] = $csrfToken;

    $update = $pdo->prepare(
        'UPDATE users SET last_login_at = NOW() WHERE id = :id'
    );
    $update->execute(['id' => $user['id']]);

    // Record in audit log
    nbc_log_audit((int)$user['id'], $targetPortal . '_login', 'user', (string)$user['id'], [
        'email' => $user['email'],
        'portal' => $targetPortal
    ]);

    nbc_json(200, [
        'success' => true,
        'message' => 'تم تسجيل الدخول بنجاح.',
        'redirect_url' => $redirectUrl,
        'portal' => $targetPortal,
        'csrf_token' => $csrfToken,
        'user' => [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $targetPortal,
            'roles' => $roles,
            'csrf_token' => $csrfToken
        ]
    ]);

} catch (PDOException $e) {
    error_log('[Unified login error] ' . $e->getMessage());
    nbc_json(500, [
        'success' => false,
        'message' => 'حدث خطأ أثناء تسجيل الدخول. حاول مرة أخرى.'
    ]);
}
