<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

nbc_start_session('student');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    nbc_json(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) && !empty($_POST)) {
    $input = $_POST;
}

$email = trim((string)($input['email'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

if ($email === '' || $code === '') {
    nbc_json(422, ['success' => false, 'message' => 'يرجى إدخال البريد الإلكتروني وكود التحقق.']);
}

try {
    $stmt = $pdo->prepare('SELECT id, full_name, email, role, verification_code, is_verified, status FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        nbc_json(404, ['success' => false, 'message' => 'الحساب غير موجود.']);
    }

    if ($user['verification_code'] !== $code) {
        nbc_json(400, ['success' => false, 'message' => 'كود التحقق غير صحيح. يرجى التأكد وإعادة المحاولة.']);
    }

    // Activate the student account
    $up = $pdo->prepare('UPDATE users SET status = "active", is_verified = 1, email_verified_at = NOW(), verification_code = NULL WHERE id = :id');
    $up->execute(['id' => $user['id']]);

    // Automatically initialize student session
    session_regenerate_id(true);
    $csrfToken = nbc_csrf_token();
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = 'student';
    $_SESSION['csrf_token'] = $csrfToken;

    nbc_log_audit((int)$user['id'], 'student_verify_code', 'user', (string)$user['id'], [
        'email' => $email
    ]);

    nbc_json(200, [
        'success' => true,
        'message' => 'تم التحقق من الحساب وتفعيله بنجاح! جارٍ تحويلك إلى لوحة الطالب...',
        'redirect_url' => 'student.html'
    ]);

} catch (Throwable $e) {
    error_log('[Verify code error] ' . $e->getMessage());
    nbc_json(500, ['success' => false, 'message' => 'حدث خطأ أثناء تأكيد الكود.']);
}
