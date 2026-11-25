<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/mailer.php';

// Start isolated student session
nbc_start_session('student');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    nbc_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    nbc_json(400, [
        'success' => false,
        'message' => 'Invalid JSON data.'
    ]);
}

$fullName = trim((string)($input['full_name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$phone = trim((string)($input['phone'] ?? ''));
$age = !empty($input['age']) ? (int)$input['age'] : null;
$gender = trim((string)($input['gender'] ?? ''));
$extraInfo = trim((string)($input['extra_info'] ?? ''));

if ($fullName === '' || $email === '' || $password === '') {
    nbc_json(422, [
        'success' => false,
        'message' => 'الاسم، البريد الإلكتروني، وكلمة المرور مطلوبة.'
    ]);
}

if (mb_strlen($fullName) > 150) {
    nbc_json(422, [
        'success' => false,
        'message' => 'الاسم يجب ألا يتجاوز 150 حرفاً.'
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    nbc_json(422, [
        'success' => false,
        'message' => 'يرجى إدخال بريد إلكتروني صالح.'
    ]);
}

if (strlen($password) < 8) {
    nbc_json(422, [
        'success' => false,
        'message' => 'كلمة المرور يجب أن تتكون من 8 أحرف على الأقل.'
    ]);
}

$retryAfter = nbc_rate_limit('register', 6, 3600, true);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    nbc_json(429, ['success'=>false, 'message'=>'تم تجاوز عدد محاولات إنشاء الحساب من هذا الاتصال. حاول لاحقًا.']);
}

if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
    $gender = null;
}

if ($age !== null && ($age < 14 || $age > 100)) {
    nbc_json(422, [
        'success' => false,
        'message' => 'يرجى إدخال عمر صحيح بين 14 و 100 عام.'
    ]);
}

$cleanPhone = ($phone !== '' && strlen($phone) <= 30) ? $phone : null;

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $email]);

    if ($check->fetch()) {
        nbc_json(409, [
            'success' => false,
            'message' => 'يوجد حساب مسجل بهذا البريد الإلكتروني بالفعل.'
        ]);
    }

    $pdo->beginTransaction();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Generate a 6-digit verification code
    $verificationCode = (string)random_int(100000, 999999);

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, phone, age, gender, extra_info, verification_code, is_verified, role, status)
         VALUES (:full_name, :email, :password_hash, :phone, :age, :gender, :extra_info, :verification_code, 0, :role, :status)'
    );

    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => $passwordHash,
        'phone' => $cleanPhone,
        'age' => $age,
        'gender' => $gender ?: null,
        'extra_info' => $extraInfo ?: null,
        'verification_code' => $verificationCode,
        'role' => 'student',
        'status' => 'pending' // pending until email code verified
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    // Sync with user_roles table
    $roleStmt = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)');
    $roleStmt->execute([$newUserId, 'student']);

    $profStmt = $pdo->prepare('INSERT IGNORE INTO profiles (user_id, display_name, locale) VALUES (?, ?, ?)');
    $profStmt->execute([$newUserId, $fullName, 'ar']);

    $pdo->commit();

    // Send email with code
    $subject = 'كود التحقق من حسابك — أكاديمية التمريض Edu Nursing';
    $emailHtml = "
    <div dir='rtl' style='font-family:Arial,sans-serif;padding:24px;background:#f8fafc;color:#1e293b;border-radius:12px;max-width:600px;margin:auto;'>
        <div style='text-align:center;margin-bottom:20px;'>
            <h2 style='color:#0d9488;margin:0;'>Edu Nursing Academy</h2>
            <p style='color:#64748b;margin:4px 0 0;'>أكاديمية التعليم والتدريب الطبي والتمريضي</p>
        </div>
        <div style='background:#ffffff;padding:24px;border-radius:10px;border:1px solid #e2e8f0;'>
            <h3 style='margin-top:0;'>أهلاً بك يا دكتور {$fullName}،</h3>
            <p>شكراً لتسجيلك في منصة Edu Nursing. لتأكيد حسابك وتفعيله، استخدم كود التحقق السريع التالي:</p>
            <div style='background:#f1f5f9;border:2px dashed #0d9488;padding:16px;text-align:center;border-radius:8px;margin:20px 0;'>
                <span style='font-size:32px;font-weight:bold;letter-spacing:6px;color:#0d9488;'>{$verificationCode}</span>
            </div>
            <p style='font-size:13px;color:#64748b;'>الكود صالح للاستخدام فوراً. إذا لم تكن قد طلبت التسجيل، يمكنك تجاهل هذه الرسالة بأمان.</p>
        </div>
    </div>";

    nbc_send_email($email, $subject, $emailHtml);

    // Audit log
    nbc_log_audit($newUserId, 'student_register', 'user', (string)$newUserId, [
        'email' => $email,
        'verification_code' => $verificationCode
    ]);

    nbc_json(201, [
        'success' => true,
        'message' => 'تم إنشاء الحساب بنجاح! تم إرسال كود التحقق.',
        'user_id' => $newUserId,
        'email' => $email,
        'requires_verification' => true,
        'auto_code' => $verificationCode // Provided directly so the user/UI gets it automatically
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Register error] ' . $e->getMessage());
    nbc_json(500, [
        'success' => false,
        'message' => 'تعذر إتمام التسجيل حالياً. حاول مرة أخرى.'
    ]);
}
