<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

nbc_start_session('instructor');

if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] < 1) {
    nbc_json(200, [
        'success' => true,
        'user' => null,
        'csrf_token' => nbc_csrf_token()
    ]);
}

$u = nbc_require_instructor(false);

$details = [];
try {
    $detStmt = $pdo->prepare("SELECT specialization, experience_years, workplace, experience, bio FROM instructor_details WHERE user_id = ?");
    $detStmt->execute([$u['id']]);
    $details = $detStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // instructor_details table might not have been created yet
}

nbc_json(200, [
    'success' => true,
    'csrf_token' => $u['csrf_token'],
    'user' => [
        'id' => (int)$u['id'],
        'name' => $u['full_name'],
        'full_name' => $u['full_name'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'avatar' => $u['avatar'],
        'bio' => !empty($details['bio']) ? $details['bio'] : $u['bio'],
        'specialization' => $details['specialization'] ?? '',
        'experience_years' => isset($details['experience_years']) ? (int)$details['experience_years'] : 0,
        'workplace' => $details['workplace'] ?? '',
        'experience' => $details['experience'] ?? '',
        'role' => 'instructor',
        'roles' => $u['roles'],
        'csrf_token' => $u['csrf_token']
    ]
]);
