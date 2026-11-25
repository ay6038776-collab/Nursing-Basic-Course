<?php
declare(strict_types=1);

/**
 * Server-side Gemini REST helper. The legacy OpenAI variable is accepted as a
 * migration fallback because some local XAMPP setups already store the Gemini
 * key under that name. The key is sent only in Google's x-goog-api-key header.
 */
function nbc_gemini_api_key(): string
{
    return trim((string)(getenv('NBC_GEMINI_API_KEY') ?: getenv('NBC_OPENAI_API_KEY') ?: ''));
}

function nbc_gemini_model(): string
{
    return trim((string)(getenv('NBC_GEMINI_MODEL') ?: 'gemini-3.5-flash-lite'));
}

/** @return array{ok:bool,status:int,text:string} */
function nbc_gemini_generate(array $contents, string $systemInstruction, array $generationConfig = []): array
{
    $key = nbc_gemini_api_key();
    if ($key === '') {
        return ['ok' => false, 'status' => 503, 'text' => ''];
    }

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
        'contents' => $contents,
    ];
    if ($generationConfig) {
        $payload['generationConfig'] = $generationConfig;
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode(nbc_gemini_model()) . ':generateContent';
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $response = false;
    $status = 0;
    $error = '';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            error_log('[Gemini API] ' . $error);
            return ['ok' => false, 'status' => 502, 'text' => ''];
        }
        if ($status !== 503 || $attempt === 2) {
            break;
        }
        usleep(($attempt + 1) * 1000000);
    }

    if ($status < 200 || $status >= 300) {
        error_log('[Gemini API] Provider HTTP ' . $status . ' after ' . ($attempt + 1) . ' attempt(s)');
        return ['ok' => false, 'status' => $status, 'text' => ''];
    }

    $decoded = json_decode((string)$response, true);
    $text = '';
    foreach (($decoded['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text'])) {
            $text .= (string)$part['text'];
        }
    }

    return ['ok' => trim($text) !== '', 'status' => $status, 'text' => trim($text)];
}
