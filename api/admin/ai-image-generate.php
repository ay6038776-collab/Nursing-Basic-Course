<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/gemini.php';
@set_time_limit(180);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    admin_json(405, ['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$prompt = trim((string)($input['prompt'] ?? ''));
$aspectRatio = (string)($input['aspect_ratio'] ?? '1:1');
$allowedRatios = ['1:1', '3:2', '2:3', '4:3', '3:4', '16:9', '9:16'];
if (mb_strlen($prompt) < 8 || mb_strlen($prompt) > 2000) {
    admin_json(422, ['success' => false, 'message' => 'اكتب وصفًا للصورة من 8 إلى 2000 حرف.']);
}
if (!in_array($aspectRatio, $allowedRatios, true)) {
    admin_json(422, ['success' => false, 'message' => 'نسبة أبعاد الصورة غير مدعومة.']);
}

$key = nbc_gemini_api_key();
if ($key === '') {
    admin_json(503, ['success' => false, 'message' => 'مفتاح Gemini غير مضبوط في بيئة Apache.']);
}

$payload = [
    'model' => 'gemini-3.1-flash-image',
    'input' => [[
        'type' => 'text',
        'text' => "Create one polished, high-quality image for a professional nursing education platform. The image may be used as a course cover, book cover, or educational website image. Follow this brief: " . $prompt . "\nUse a premium, clinically appropriate visual style. Do not add text, logos, labels, watermarks, or invented medical claims unless the brief explicitly requests visible text.",
    ]],
    'response_format' => [
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'aspect_ratio' => $aspectRatio,
        'image_size' => '1K',
    ],
];

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key, 'Content-Type: application/json', 'Api-Revision: 2026-05-20'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 150,
]);
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($response === false) {
    error_log('[Nano Banana 2] ' . $curlError);
    admin_json(502, ['success' => false, 'message' => 'تعذر الاتصال بخدمة توليد الصور. حاول مرة أخرى.']);
}
if ($status < 200 || $status >= 300) {
    $providerError = json_decode((string)$response, true);
    $providerMessage = trim((string)($providerError['error']['message'] ?? ''));
    error_log('[Nano Banana 2] Provider HTTP ' . $status . ($providerMessage !== '' ? ': ' . mb_substr($providerMessage, 0, 500) : ''));
    $message = match ($status) {
        400 => 'Google رفض طلب التوليد: ' . ($providerMessage !== '' ? mb_substr($providerMessage, 0, 350) : 'راجع إعدادات الموديل والطلب.'),
        401, 403 => 'Google رفض المفتاح أو صلاحية توليد الصور. تأكد أن المفتاح من Google AI Studio وأن Gemini API مفعّلة للمشروع.',
        429 => 'وصل المشروع إلى حد استخدام توليد الصور. راجع خطة الفوترة وحدود الموديل في Google AI Studio.',
        503 => 'موديل Nano Banana 2 مشغول مؤقتًا. حاول مرة أخرى بعد قليل.',
        default => 'تعذر توليد الصورة من Gemini. حاول مرة أخرى.',
    };
    admin_json($status === 429 || $status === 503 ? 503 : 502, ['success' => false, 'message' => $message]);
}

$decoded = json_decode((string)$response, true);
$image = $decoded['output_image'] ?? null;
if (is_array($image) && !empty($image['data'])) {
    $mime = (string)($image['mime_type'] ?? 'image/png');
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        admin_json(200, ['success' => true, 'mime_type' => $mime, 'image_base64' => (string)$image['data']]);
    }
}

admin_json(502, ['success' => false, 'message' => 'لم يرجع الموديل صورة. جرّب وصفًا مختلفًا.']);
