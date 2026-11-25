<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

function respond(array $data, int $status = 200): never
{
    student_json($status, $data + ['success' => $status >= 200 && $status < 300]);
}

$userId = (int) $currentStudent['id'];

try {
    $stmt = $pdo->prepare(
        'SELECT id, avatar FROM users WHERE id = ? AND role = ?'
    );
    $stmt->execute([$userId, 'student']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        respond(['message' => 'الحساب غير موجود.'], 404);
    }

    $uploadDir = dirname(__DIR__, 2) . '/uploads/avatars/';

    if (!is_dir($uploadDir) &&
        !mkdir($uploadDir, 0755, true) &&
        !is_dir($uploadDir)) {
        respond(['message' => 'تعذر إنشاء مجلد الصور.'], 500);
    }

    $oldAvatar = $user['avatar'];

    // حذف الصورة الحالية
    if (($_POST['remove'] ?? '') === '1') {
        $stmt = $pdo->prepare(
            'UPDATE users SET avatar = NULL WHERE id = ? AND role = ?'
        );
        $stmt->execute([$userId, 'student']);

        if (is_string($oldAvatar) &&
            preg_match('#^uploads/avatars/[a-f0-9]{32}\.(jpg|png|webp)$#', $oldAvatar)) {
            $oldFile = dirname(__DIR__, 2) . '/' . $oldAvatar;

            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }

        respond([
            'message' => 'تم حذف الصورة الشخصية.',
            'avatar' => null,
        ]);
    }

    if (!isset($_FILES['avatar']) ||
        $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        respond(['message' => 'اختر صورة صالحة للرفع.'], 422);
    }

    $file = $_FILES['avatar'];

    if ($file['size'] > 2 * 1024 * 1024) {
        respond(['message' => 'حجم الصورة يجب ألا يتجاوز 2MB.'], 422);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        respond(['message' => 'ملف الرفع غير صالح.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        respond(['message' => 'استخدم صورة JPG أو PNG أو WebP فقط.'], 422);
    }

    $imageInfo = @getimagesize($file['tmp_name']);

    if ($imageInfo === false ||
        $imageInfo['mime'] !== $mime ||
        $imageInfo[0] > 5000 ||
        $imageInfo[1] > 5000) {
        respond(['message' => 'الصورة غير صالحة أو أبعادها كبيرة جدًا.'], 422);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        respond(['message' => 'تعذر حفظ الصورة.'], 500);
    }

    $relativePath = 'uploads/avatars/' . $filename;

    try {
        $stmt = $pdo->prepare(
            'UPDATE users SET avatar = ? WHERE id = ? AND role = ?'
        );
        $stmt->execute([$relativePath, $userId, 'student']);
    } catch (Throwable $e) {
        @unlink($destination);
        throw $e;
    }

    // حذف الصورة القديمة بعد نجاح تحديث قاعدة البيانات
    if (is_string($oldAvatar) &&
        preg_match('#^uploads/avatars/[a-f0-9]{32}\.(jpg|png|webp)$#', $oldAvatar)) {
        $oldFile = dirname(__DIR__, 2) . '/' . $oldAvatar;

        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    respond([
        'message' => 'تم تحديث الصورة الشخصية بنجاح.',
        'avatar' => $relativePath,
    ]);
} catch (Throwable $e) {
    error_log('[Student Avatar] ' . $e->getMessage());

    respond(['message' => 'حدث خطأ أثناء تحديث الصورة.'], 500);
}

