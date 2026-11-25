<?php
declare(strict_types=1);

/**
 * Shared guard for administrative JSON endpoints.
 * Powered by centralized auth.php with RBAC and CSRF protection.
 */

require_once __DIR__ . '/auth.php';

// Authenticate user strictly as admin in the admin portal session with CSRF enforced on mutations
$currentUser = nbc_require_admin(true);

// Backward-compatibility aliases
if (!function_exists('admin_json')) {
    function admin_json(int $status, array $data): never {
        nbc_json($status, $data);
    }
}

if (!function_exists('json_success')) {
    function json_success(array $data = [], int $status = 200): never {
        nbc_json($status, array_merge(['success' => true], $data));
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, int $status = 400): never {
        nbc_json($status, ['success' => false, 'message' => $message]);
    }
}

if (!function_exists('admin_is_local')) {
    function admin_is_local(): bool {
        return nbc_is_local();
    }
}
