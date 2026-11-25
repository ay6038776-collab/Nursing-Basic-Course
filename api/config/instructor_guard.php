<?php
declare(strict_types=1);

/**
 * Shared guard for authenticated instructor JSON endpoints.
 * Powered by centralized auth.php with RBAC.
 */

require_once __DIR__ . '/auth.php';

// Authenticate user as instructor in the instructor portal session
$currentInstructor = nbc_require_instructor(true);

// Backward-compatibility alias
if (!function_exists('instructor_json')) {
    function instructor_json(int $status, array $data): never {
        nbc_json($status, $data);
    }
}
