<?php
declare(strict_types=1);

/** Start an isolated session for one portal: student, instructor, or admin. */
function nbc_start_session(string $portal): void
{
    $names = [
        'student' => 'NBC_STUDENT_SESSID',
        'instructor' => 'NBC_INSTRUCTOR_SESSID',
        'admin' => 'NBC_ADMIN_SESSID',
    ];
    if (!isset($names[$portal])) {
        throw new InvalidArgumentException('Unknown portal session.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== $names[$portal]) {
            // Close existing session and switch to target portal
            session_write_close();
        } else {
            return;
        }
    }
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name($names[$portal]);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
