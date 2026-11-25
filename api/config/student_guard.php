<?php
declare(strict_types=1);

/**
 * Shared guard for authenticated student JSON endpoints.
 * Powered by centralized auth.php with RBAC.
 */

require_once __DIR__ . '/auth.php';

// Authenticate user as student in the student portal session
$currentStudent = nbc_require_student(true);

// Backward-compatibility alias
if (!function_exists('student_json')) {
    function student_json(int $status, array $data): never {
        nbc_json($status, $data);
    }
}
