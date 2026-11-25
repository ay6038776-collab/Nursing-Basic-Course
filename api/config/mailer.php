<?php
declare(strict_types=1);

/**
 * Platform Mail Helper
 * Dispatches emails via standard mail() and logs them into mail_logs or notifications table
 * so emails can be previewed locally during development and delivered in production.
 */

function nbc_send_email(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool
{
    global $pdo;

    // Headers for HTML mail
    $fromName = 'Edu Nursing Academy';
    $fromEmail = 'no-reply@edunursing.com';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($fromName), $fromEmail),
        'Reply-To: support@edunursing.com',
        'X-Mailer: EduNursing/1.0 PHP/' . phpversion()
    ];

    $headerStr = implode("\r\n", $headers);

    // Attempt mail() delivery safely
    $mailSent = false;
    try {
        $mailSent = @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headerStr);
    } catch (Throwable $e) {
        error_log('[Mail Error] ' . $e->getMessage());
    }

    // Always record in notifications or audit log so it's visible in Admin Panel & local tests
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'mail_logs'")->fetch();
            if (!$checkTable) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS mail_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    recipient_email VARCHAR(190) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    body_html LONGTEXT NOT NULL,
                    status VARCHAR(50) NOT NULL DEFAULT 'sent',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $stmt = $pdo->prepare("INSERT INTO mail_logs (recipient_email, subject, body_html, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$toEmail, $subject, $htmlBody, $mailSent ? 'sent' : 'logged_local']);
        } catch (Throwable $e) {
            error_log('[Mail Log Error] ' . $e->getMessage());
        }
    }

    return true;
}
