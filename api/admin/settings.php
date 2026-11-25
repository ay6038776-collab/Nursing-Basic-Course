<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/settings_helper.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $settings = get_platform_settings($pdo);
    admin_json(200, [
        'success' => true,
        'settings' => $settings
    ]);
}

if ($method === 'POST') {
    if (($_POST['action'] ?? '') === 'upload_logo') {
        $file = $_FILES['logo_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            admin_json(422, ['success' => false, 'message' => 'اختر صورة شعار صالحة من جهازك.']);
        }
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
            admin_json(413, ['success' => false, 'message' => 'حجم الشعار يجب ألا يتجاوز 8 ميجابايت.']);
        }
        $imageInfo = @getimagesize($file['tmp_name']);
        $mime = $imageInfo['mime'] ?? '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || ($imageInfo[0] ?? 0) < 1 || ($imageInfo[1] ?? 0) < 1 || ($imageInfo[0] * $imageInfo[1]) > 40000000) {
            admin_json(422, ['success' => false, 'message' => 'استخدم صورة JPG أو PNG أو WEBP للشعار.']);
        }
        $directory = dirname(__DIR__, 2) . '/uploads/platform';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            admin_json(500, ['success' => false, 'message' => 'تعذر تجهيز مجلد الشعار.']);
        }
        $filename = 'logo-' . bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
        $relativePath = 'uploads/platform/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
            admin_json(500, ['success' => false, 'message' => 'تعذر حفظ صورة الشعار.']);
        }
        try {
            $oldStmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key='platform_logo' LIMIT 1");
            $oldStmt->execute();
            $oldLogo = (string)($oldStmt->fetchColumn() ?: '');
            $stmt = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES ('platform_logo', ?, 0, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()");
            $stmt->execute([$relativePath, $currentUser['id']]);
            if (preg_match('#^uploads/platform/logo-[a-f0-9]{40}\.(jpg|png|webp)$#i', $oldLogo) && $oldLogo !== $relativePath) {
                @unlink(dirname(__DIR__, 2) . '/' . $oldLogo);
            }
            admin_json(200, ['success' => true, 'message' => 'تم تحديث شعار المنصة.', 'platform_logo' => $relativePath]);
        } catch (Throwable $e) {
            @unlink($directory . '/' . $filename);
            error_log('[Save platform logo error] ' . $e->getMessage());
            admin_json(500, ['success' => false, 'message' => 'تعذر حفظ الشعار في إعدادات المنصة.']);
        }
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        admin_json(400, ['success' => false, 'message' => 'بيانات الإعدادات غير صالحة.']);
    }

    $allowedKeys = [
        'academy_name', 'academy_name_ar', 'academy_slogan_ar', 'academy_slogan_en',
        'founder_name', 'founder_title', 'founder_bio_ar',
        'contact_phone', 'whatsapp_number', 'contact_email', 'clinic_address_ar',
        'payment_vodafone', 'payment_instapay', 'currency', 'payment_instructions_ar',
        'theme_primary', 'theme_navy', 'theme_emerald', 'theme_accent', 'theme_font', 'theme_preset', 'theme_dark_mode',
        'theme_mode_default', 'theme_user_choice_enabled', 'theme_motion', 'theme_button_style', 'theme_card_style', 'theme_header_style', 'theme_sidebar_style', 'theme_footer_style', 'theme_layout', 'theme_font_en', 'theme_custom_enabled', 'theme_custom_primary', 'theme_custom_secondary', 'theme_custom_surface', 'theme_custom_background',
        'hero_badge_ar'
    ];

    if (isset($input['theme_preset']) && !in_array($input['theme_preset'], ['royal-red', 'royal-gold', 'european-luxury', 'modern-dark'], true)) {
        admin_json(422, ['success' => false, 'message' => 'الثيم الطبي المحدد غير صالح.']);
    }
    $themeOptions = ['theme_mode_default'=>['light','dark'],'theme_user_choice_enabled'=>['0','1'],'theme_motion'=>['off','subtle','smooth','dynamic'],'theme_button_style'=>['soft','pill','square'],'theme_card_style'=>['glass','clean','shadow'],'theme_header_style'=>['solid','glass'],'theme_sidebar_style'=>['solid','glass'],'theme_footer_style'=>['dark','light'],'theme_layout'=>['wide','compact']];
    foreach ($themeOptions as $key => $values) {
        if (isset($input[$key]) && !in_array((string)$input[$key], $values, true)) {
            admin_json(422, ['success' => false, 'message' => 'أحد خيارات تخصيص المظهر غير صالح.']);
        }
    }
    if (isset($input['theme_font']) && !in_array($input['theme_font'], ['Cairo', 'Tajawal', 'Almarai', 'IBM Plex Sans Arabic', 'Alexandria', 'Readex Pro', 'Noto Sans Arabic'], true)) {
        admin_json(422, ['success' => false, 'message' => 'الخط المحدد غير صالح.']);
    }
    if (isset($input['theme_font_en']) && !in_array($input['theme_font_en'], ['Inter','Playfair Display','Arial','Georgia'], true)) {
        admin_json(422, ['success' => false, 'message' => 'الخط الإنجليزي المحدد غير صالح.']);
    }
    if (isset($input['theme_custom_enabled']) && !in_array((string)$input['theme_custom_enabled'], ['0','1'], true)) {
        admin_json(422, ['success' => false, 'message' => 'حالة الألوان المخصصة غير صالحة.']);
    }
    foreach (['theme_custom_primary','theme_custom_secondary','theme_custom_surface','theme_custom_background'] as $colorKey) {
        if (isset($input[$colorKey]) && $input[$colorKey] !== '' && (!is_string($input[$colorKey]) || !preg_match('/^#[0-9a-f]{6}$/i', $input[$colorKey]))) {
            admin_json(422, ['success' => false, 'message' => 'أحد ألوان التخصيص غير صالح.']);
        }
    }
    foreach (['theme_primary', 'theme_navy', 'theme_emerald', 'theme_accent'] as $colorKey) {
        if (isset($input[$colorKey]) && (!is_string($input[$colorKey]) || !preg_match('/^#[0-9a-f]{6}$/i', $input[$colorKey]))) {
            admin_json(422, ['success' => false, 'message' => 'أحد ألوان الهوية غير صالح.']);
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at)
            VALUES (?, ?, 0, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
        ");

        foreach ($input as $k => $v) {
            if (in_array($k, $allowedKeys, true) && is_scalar($v)) {
                $stmt->execute([$k, (string)$v, $currentUser['id']]);
            }
        }

        admin_json(200, [
            'success' => true,
            'message' => 'تم حفظ إعدادات المنصة بنجاح.'
        ]);
    } catch (Throwable $e) {
        error_log('[Save settings error] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'تعذر حفظ الإعدادات.']);
    }
}
