<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function get_platform_settings(PDO $pdo): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'academy_name' => 'Edu Nursing',
        'academy_name_ar' => 'أكاديمية إيديو نيرسنج للتعليم الطبي',
        'founder_name' => 'د. خالد عبد الناصر محمد المرسي',
        'founder_title' => 'محاضر دولي معتمد – Certified International Lecturer',
        'contact_phone' => '01289492477',
        'whatsapp_number' => '01289492477',
        'payment_vodafone' => '01061709999',
        'payment_instapay' => '01061709999',
        'currency' => 'EGP',
        'theme_primary' => '#0d9488',
        'theme_navy' => '#0a1f3d',
        'theme_emerald' => '#059669',
        'theme_accent' => '#d4af37',
        'theme_preset' => 'royal-red',
        'theme_font' => 'Cairo',
        'theme_font_en' => 'Inter',
        'theme_mode_default' => 'light',
        'theme_user_choice_enabled' => '1',
        'theme_motion' => 'smooth',
        'theme_button_style' => 'soft',
        'theme_card_style' => 'glass',
        'theme_header_style' => 'solid',
        'theme_sidebar_style' => 'solid',
        'theme_footer_style' => 'dark',
        'theme_layout' => 'wide',
        'theme_custom_enabled' => '0',
        'theme_custom_primary' => '',
        'theme_custom_secondary' => '',
        'theme_custom_surface' => '',
        'theme_custom_background' => '',
        'platform_logo' => '',
        'payment_instructions_ar' => 'قم بتحويل المبلغ عبر Vodafone Cash أو InstaPay على الرقم 01061709999، ثم ارفع صورة الإيصال ورقم المعاملة لإتمام التفعيل بعد المراجعة.',
    ];

    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll();
        foreach ($rows as $r) {
            $defaults[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('[Platform Settings Error] ' . $e->getMessage());
    }

    $cached = $defaults;
    return $cached;
}
