<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

nbc_start_session('admin');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] < 1) {
        nbc_json(200, ['success' => true, 'user' => null, 'csrf_token' => nbc_csrf_token()]);
    }

    $currentUser = nbc_require_admin(false);

    // Fetch avatar and phone from database
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.role, u.avatar AS avatar_url, u.bio
        FROM users u
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$currentUser['id']]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);

    nbc_json(200, [
        'success' => true,
        'csrf_token' => $currentUser['csrf_token'],
        'user' => [
            'id' => (int)$currentUser['id'],
            'full_name' => $adminData['full_name'] ?? $currentUser['full_name'],
            'name' => $adminData['full_name'] ?? $currentUser['full_name'],
            'email' => $adminData['email'] ?? $currentUser['email'],
            'phone' => $adminData['phone'] ?? '',
            'avatar_url' => $adminData['avatar_url'] ?? '',
            'bio' => $adminData['bio'] ?? '',
            'role' => 'admin',
            'roles' => $currentUser['roles'],
            'csrf_token' => $currentUser['csrf_token']
        ]
    ]);
}

if ($method === 'POST') {
    $currentUser = nbc_require_admin(true);

    if (($_POST['action'] ?? '') === 'upload_avatar') {
        if (!nbc_verify_csrf()) nbc_json(419, ['success'=>false, 'message'=>'رمز الحماية غير صالح. حدّث الصفحة وحاول مرة أخرى.']);
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] < 1 || (int)$file['size'] > 8 * 1024 * 1024) {
            nbc_json(422, ['success' => false, 'message' => 'اختر صورة صالحة لا يتجاوز حجمها 8 ميجابايت.']);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($types[$mime])) nbc_json(422, ['success' => false, 'message' => 'صيغة الصورة غير مدعومة.']);
        $dir = __DIR__ . '/../../uploads/avatars';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) nbc_json(500, ['success' => false, 'message' => 'تعذر تجهيز مجلد الصور.']);
        $name = 'admin_' . (int)$currentUser['id'] . '_' . bin2hex(random_bytes(10)) . '.' . $types[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) nbc_json(500, ['success' => false, 'message' => 'تعذر حفظ الصورة.']);
        $path = 'uploads/avatars/' . $name;
        $pdo->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$path, (int)$currentUser['id']]);
        nbc_json(200, ['success' => true, 'message' => 'تم رفع الصورة من جهازك بنجاح.', 'avatar_url' => $path]);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        nbc_json(400, ['success' => false, 'message' => 'بيانات الطلب غير صالحة.']);
    }

    $action = (string)($input['action'] ?? 'update_profile');

    try {
        if ($action === 'update_profile') {
            $fullName = trim((string)($input['full_name'] ?? ''));
            $email = trim((string)($input['email'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));

            if ($fullName === '' || mb_strlen($fullName) > 150) {
                nbc_json(422, ['success' => false, 'message' => 'الاسم الكامل مطلوب وألا يتجاوز 150 حرفًا.']);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                nbc_json(422, ['success' => false, 'message' => 'البريد الإلكتروني غير صالح.']);
            }

            // Check email uniqueness if changed
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, (int)$currentUser['id']]);
            if ($check->fetchColumn()) {
                nbc_json(422, ['success' => false, 'message' => 'هذا البريد الإلكتروني مسجل مسبقًا لحساب آخر.']);
            }

            $updateUser = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
            $updateUser->execute([$fullName, $email, $phone ?: null, (int)$currentUser['id']]);

            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $email;

            nbc_json(200, [
                'success' => true,
                'message' => 'تم حفظ وتحديث بيانات حساب الإدارة بنجاح.',
                'user' => [
                    'id' => (int)$currentUser['id'],
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'avatar_url' => ''
                ]
            ]);
        }

        if ($action === 'change_password') {
            $currentPassword = (string)($input['current_password'] ?? '');
            $newPassword = (string)($input['new_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '') {
                nbc_json(422, ['success' => false, 'message' => 'يرجى إدخال كلمة المرور الحالية وكلمة المرور الجديدة.']);
            }
            if (strlen($newPassword) < 10) {
                nbc_json(422, ['success' => false, 'message' => 'كلمة المرور الجديدة يجب ألا تقل عن 10 خانات.']);
            }

            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$currentUser['id']]);
            $hash = (string)$stmt->fetchColumn();

            if (!password_verify($currentPassword, $hash)) {
                nbc_json(401, ['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة.']);
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $up->execute([$newHash, (int)$currentUser['id']]);

            nbc_json(200, [
                'success' => true,
                'message' => 'تم تغيير كلمة المرور بنجاح.'
            ]);
        }

        nbc_json(422, ['success' => false, 'message' => 'الإجراء غير مدعوم.']);
    } catch (Throwable $e) {
        error_log('[Admin profile error] ' . $e->getMessage());
        nbc_json(500, ['success' => false, 'message' => 'حدث خطأ أثناء حفظ البيانات.']);
    }
}

header('Allow: GET, POST');
nbc_json(405, ['success' => false, 'message' => 'الطريقة غير مسموح بها.']);
