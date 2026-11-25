<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings_helper.php';

try {
    $settings = get_platform_settings($pdo);
    $allowedFonts = ['Cairo','Tajawal','Almarai','IBM Plex Sans Arabic','Alexandria','Readex Pro','Noto Sans Arabic'];
    $allowedEnglishFonts = ['Inter','Playfair Display','Arial','Georgia'];
    $aliases = ['clinical-blue'=>'royal-gold','teal-care'=>'royal-red','emerald-clinic'=>'royal-gold','violet-academy'=>'modern-dark','arctic-care'=>'european-luxury','custom'=>'royal-red','royal-teal'=>'royal-red','royal-sapphire'=>'royal-gold','royal-jade'=>'royal-gold','royal-amethyst'=>'modern-dark','royal-cyan'=>'european-luxury'];
    $preset = (string)($settings['theme_preset'] ?? 'royal-teal');
    $preset = $aliases[$preset] ?? $preset;
    $allowedThemes = ['royal-red','royal-gold','european-luxury','modern-dark'];
    $mode = ($settings['theme_mode_default'] ?? 'light') === 'dark' ? 'dark' : 'light';
    echo json_encode([
        'success' => true,
        'theme_preset' => in_array($preset, $allowedThemes, true) ? $preset : 'royal-red',
        'theme_font' => in_array($settings['theme_font'] ?? '', $allowedFonts, true) ? $settings['theme_font'] : 'Cairo',
        'theme_font_en' => in_array($settings['theme_font_en'] ?? '', $allowedEnglishFonts, true) ? $settings['theme_font_en'] : 'Inter',
        'theme_mode_default' => $mode,
        'theme_user_choice_enabled' => (string)($settings['theme_user_choice_enabled'] ?? '1') === '1',
        'theme_motion' => in_array($settings['theme_motion'] ?? '', ['off','subtle','smooth','dynamic'], true) ? $settings['theme_motion'] : 'smooth',
        'theme_button_style' => in_array($settings['theme_button_style'] ?? '', ['soft','pill','square'], true) ? $settings['theme_button_style'] : 'soft',
        'theme_card_style' => in_array($settings['theme_card_style'] ?? '', ['glass','clean','shadow'], true) ? $settings['theme_card_style'] : 'glass',
        'theme_header_style' => in_array($settings['theme_header_style'] ?? '', ['solid','glass'], true) ? $settings['theme_header_style'] : 'solid',
        'theme_sidebar_style' => in_array($settings['theme_sidebar_style'] ?? '', ['solid','glass'], true) ? $settings['theme_sidebar_style'] : 'solid',
        'theme_footer_style' => in_array($settings['theme_footer_style'] ?? '', ['dark','light'], true) ? $settings['theme_footer_style'] : 'dark',
        'theme_layout' => in_array($settings['theme_layout'] ?? '', ['wide','compact'], true) ? $settings['theme_layout'] : 'wide',
        'theme_custom_enabled' => (string)($settings['theme_custom_enabled'] ?? '0') === '1',
        'theme_custom_primary' => preg_match('/^#[0-9a-f]{6}$/i', (string)($settings['theme_custom_primary'] ?? '')) ? $settings['theme_custom_primary'] : '',
        'theme_custom_secondary' => preg_match('/^#[0-9a-f]{6}$/i', (string)($settings['theme_custom_secondary'] ?? '')) ? $settings['theme_custom_secondary'] : '',
        'theme_custom_surface' => preg_match('/^#[0-9a-f]{6}$/i', (string)($settings['theme_custom_surface'] ?? '')) ? $settings['theme_custom_surface'] : '',
        'theme_custom_background' => preg_match('/^#[0-9a-f]{6}$/i', (string)($settings['theme_custom_background'] ?? '')) ? $settings['theme_custom_background'] : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[Public theme] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false], JSON_UNESCAPED_UNICODE);
}
