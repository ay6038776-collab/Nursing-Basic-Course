<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

nbc_start_session('student');

if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] < 1) {
    nbc_json(200, [
        'success' => true,
        'user' => null,
        'csrf_token' => nbc_csrf_token()
    ]);
}

$user = nbc_require_student(false);

nbc_json(200, [
    'success' => true,
    'csrf_token' => $user['csrf_token'],
    'user' => [
        'id' => (int)$user['id'],
        'name' => $user['full_name'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'avatar' => $user['avatar'],
        'bio' => $user['bio'],
        'role' => $user['role'],
        'roles' => $user['roles'],
        'csrf_token' => $user['csrf_token']
    ]
]);
