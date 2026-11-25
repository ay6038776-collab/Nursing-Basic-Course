<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/mailer.php';

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
$specialization = trim((string)($input['specialization'] ?? ''));
$experienceYears = !empty($input['experience_years']) ? (int)$input['experience_years'] : null;
$workplace = trim((string)($input['workplace'] ?? ''));
$experience = trim((string)($input['experience'] ?? ''));
$cvUrl = trim((string)($input['cv_url'] ?? ''));
$bio = trim((string)($input['bio'] ?? ''));
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

$retryAfter = nbc_rate_limit('register_instructor', 4, 3600, true);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    nbc_json(429, ['success'=>false, 'message'=>'تم تجاوز عدد طلبات التسجيل كمحاضر. حاول لاحقًا.']);
}

if (strlen($password) < 8) {
    nbc_json(422, [
        'success' => false,
        'message' => 'كلمة المرور يجب أن تتكون من 8 أحرف على الأقل.'
    ]);
}

if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
    $gender = null;
}

if ($age !== null && ($age < 18 || $age > 100)) {
    nbc_json(422, [
        'success' => false,
        'message' => 'يرجى إدخال عمر صحيح للمحاضر (18 سنة فأكثر).'
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

    // Insert user with status pending
    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, phone, age, gender, specialization, experience_years, workplace, experience, cv_url, bio, extra_info, role, status)
         VALUES (:full_name, :email, :password_hash, :phone, :age, :gender, :specialization, :experience_years, :workplace, :experience, :cv_url, :bio, :extra_info, :role, :status)'
    );

    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => $passwordHash,
        'phone' => $cleanPhone,
        'age' => $age,
        'gender' => $gender ?: null,
        'specialization' => $specialization ?: null,
        'experience_years' => $experienceYears,
        'workplace' => $workplace ?: null,
        'experience' => $experience ?: null,
        'cv_url' => $cvUrl ?: null,
        'bio' => $bio ?: null,
        'extra_info' => $extraInfo ?: null,
        'role' => 'instructor',
        'status' => 'pending'
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    $roleStmt = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)');
    $roleStmt->execute([$newUserId, 'instructor']);

    $profStmt = $pdo->prepare('INSERT IGNORE INTO profiles (user_id, display_name, locale) VALUES (?, ?, ?)');
    $profStmt->execute([$newUserId, $fullName, 'ar']);

    // Ensure instructor_details table record exists
    try {
        $instDet = $pdo->prepare('INSERT INTO instructor_details (user_id, specialization, experience_years, workplace, experience, bio) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE specialization=VALUES(specialization), experience_years=VALUES(experience_years), workplace=VALUES(workplace), experience=VALUES(experience), bio=VALUES(bio)');
        $instDet->execute([$newUserId, $specialization, $experienceYears, $workplace, $experience, $bio]);
    } catch (Throwable $e) {}

    $pdo->commit();

    // Send confirmation email to instructor
    $subject = 'تم استلام طلب انضمامك كمحاضر — Edu Nursing Academy';
    $emailHtml = "
    <div dir='rtl' style='font-family:Arial,sans-serif;padding:24px;background:#f8fafc;color:#1e293b;border-radius:12px;max-width:600px;margin:auto;'>
        <div style='text-align:center;margin-bottom:20px;'>
            <h2 style='color:#0d9488;margin:0;'>Edu Nursing Academy</h2>
            <p style='color:#64748b;margin:4px 0 0;'>بوابة المحاضرين والخبراء</p>
        </div>
        <div style='background:#ffffff;padding:24px;border-radius:10px;border:1px solid #e2e8f0;'>
            <h3 style='margin-top:0;'>مرحباً دكتور {$fullName}،</h3>
            <p>يسعدنا اهتمامك بالانضمام إلى الهيئة التدريسية لأكاديمية <strong>Edu Nursing</strong>.</p>
            <p>تم استلام طلبك وتفاصيل سيرتك الذاتية في تخصص (<strong>" . htmlspecialchars($specialization ?: 'التمريض العام') . "</strong>) بنجاح، وطلبك حالياً قيد المراجعة والاعتماد من قبل إدارة المنصة.</p>
            <div style='background:#ecfdf5;border-right:4px solid #10b981;padding:14px;border-radius:6px;margin:18px 0;'>
                <strong>حالة الحساب:</strong> قيد المراجعة والاعتماد ⏳<br>
                سيصلك إشعار بالبريد الإلكتروني فور تفعيل حسابك لتتمكن من تسجيل الدخول وإدارة كورساتك ومحاضراتك.
            </div>
            <p style='font-size:13px;color:#64748b;'>شكراً لثقتكم بانضمامكم لمسيرتنا التعليمية التمريضية.</p>
        </div>
    </div>";

    nbc_send_email($email, $subject, $emailHtml);

    nbc_log_audit($newUserId, 'instructor_register', 'user', (string)$newUserId, [
        'email' => $email,
        'specialization' => $specialization
    ]);

    nbc_json(201, [
        'success' => true,
        'message' => 'تم استلام طلبك وسيرتك الذاتية بنجاح! تم إرسال رسالة تأكيد إلى بريدك الإلكتروني وسيتم تفعيل حسابك بعد مراجعة الإدارة.'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Register instructor error] ' . $e->getMessage());
    nbc_json(500, [
        'success' => false,
        'message' => 'تعذر إرسال طلب التسجيل حالياً. حاول مرة أخرى.'
    ]);
}
