<?php
declare(strict_types=1);

/**
 * =========================================================================
 * Edu Nursing – Central Authentication & RBAC Guard System (auth.php)
 * =========================================================================
 * Provides:
 * 1. Portal-isolated session initialization (student, instructor, admin)
 * 2. Database-backed identity and status check
 * 3. Robust Role-Based Access Control (RBAC) inspecting users.role + user_roles
 * 4. CSRF protection for mutating HTTP methods (POST, PUT, PATCH, DELETE)
 * 5. Automatic Audit Logging via audit_logs table
 * 6. Standardized JSON response helpers with secure headers
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/rate_limit.php';

// Helper: send standard JSON response and exit
if (!function_exists('nbc_json')) {
    function nbc_json(int $status, array $data): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

// Helper: check if request is local
if (!function_exists('nbc_is_local')) {
    function nbc_is_local(): bool {
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}

// Helper: get client IP address safely
if (!function_exists('nbc_get_client_ip')) {
    function nbc_get_client_ip(): string {
        // Proxy headers are attacker-controlled unless a trusted-proxy allowlist is configured.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }
}

// Helper: get or generate session CSRF token
if (!function_exists('nbc_csrf_token')) {
    function nbc_csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Helper: verify CSRF token from header or body
if (!function_exists('nbc_verify_csrf')) {
    function nbc_verify_csrf(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['csrf_token'])) {
            return false;
        }
        $sessionToken = (string)$_SESSION['csrf_token'];
        
        // 1. Check HTTP header X-CSRF-Token or X-XSRF-Token
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null;
        if (is_string($provided) && $provided !== '' && hash_equals($sessionToken, $provided)) {
            return true;
        }

        // 2. Check $_POST
        if (!empty($_POST['csrf_token']) && is_string($_POST['csrf_token']) && hash_equals($sessionToken, $_POST['csrf_token'])) {
            return true;
        }

        // 3. Check JSON payload if applicable
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
                if (hash_equals($sessionToken, $decoded['csrf_token'])) {
                    return true;
                }
            }
        }

        return false;
    }
}

// Helper: audit log recorder
if (!function_exists('nbc_log_audit')) {
    function nbc_log_audit(?int $userId, string $action, string $entityType, ?string $entityId = null, ?array $details = null): void {
        global $pdo;
        if (!isset($pdo) || !$pdo instanceof PDO) {
            return;
        }
        try {
            $ip = inet_pton(nbc_get_client_ip()) ?: null;
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
            $stmt = $pdo->prepare('
                INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, user_agent, details_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId !== null ? (string)$entityId : null,
                $ip,
                $ua,
                $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
            ]);
        } catch (Throwable $e) {
            error_log('[Audit Log Error] ' . $e->getMessage());
        }
    }
}

// Helper: fetch all roles assigned to user from users table and user_roles table
if (!function_exists('nbc_get_user_roles')) {
    function nbc_get_user_roles(int $userId, string $primaryRole): array {
        global $pdo;
        $roles = [$primaryRole];
        if (!isset($pdo) || !$pdo instanceof PDO) {
            return array_unique($roles);
        }
        try {
            $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = ?');
            $stmt->execute([$userId]);
            $extra = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($extra)) {
                $roles = array_merge($roles, $extra);
            }
        } catch (Throwable $e) {
            error_log('[User Roles Fetch Error] ' . $e->getMessage());
        }
        return array_values(array_unique(array_filter($roles)));
    }
}

// Core Authenticate function with RBAC
if (!function_exists('nbc_authenticate')) {
    function nbc_authenticate(string $portal, array|string|null $allowedRoles = null, bool $enforceCsrfOnMutations = false): array {
        global $pdo;
        
        // 1. Start portal-isolated session
        nbc_start_session($portal);

        // 2. Check session user_id
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            nbc_json(401, [
                'success' => false,
                'message' => 'سجّل الدخول أولاً للوصول إلى هذا المحتوى.'
            ]);
        }

        // 3. Verify user in database
        try {
            $stmt = $pdo->prepare('SELECT id, full_name, email, role, phone, avatar, bio, status FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[Auth User Fetch Error] ' . $e->getMessage());
            nbc_json(500, [
                'success' => false,
                'message' => 'تعذر التحقق من بيانات المستخدم.'
            ]);
        }

        if (!$user) {
            $_SESSION = [];
            session_destroy();
            nbc_json(401, [
                'success' => false,
                'message' => 'المستخدم غير موجود. سجّل الدخول مجدداً.'
            ]);
        }

        if ($user['status'] !== 'active') {
            $_SESSION = [];
            session_destroy();
            nbc_json(403, [
                'success' => false,
                'message' => 'هذا الحساب غير نشط أو معلق. تواصل مع الإدارة.'
            ]);
        }

        // 4. Fetch all user roles (from users.role + user_roles table)
        $userRoles = nbc_get_user_roles((int)$user['id'], (string)$user['role']);
        $user['roles'] = $userRoles;

        // 5. RBAC check
        if ($allowedRoles !== null) {
            $required = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
            $hasAccess = false;
            foreach ($required as $reqRole) {
                if (in_array($reqRole, $userRoles, true)) {
                    $hasAccess = true;
                    break;
                }
            }
            if (!$hasAccess) {
                nbc_json(403, [
                    'success' => false,
                    'message' => 'ليس لديك صلاحية الوصول لهذه الصفحة أو العملية (RBAC Unauthorized).'
                ]);
            }
        }

        // 6. Ensure CSRF token is initialized in session
        $csrfToken = nbc_csrf_token();
        $user['csrf_token'] = $csrfToken;

        // 7. Enforce CSRF on mutating requests if required
        if ($enforceCsrfOnMutations) {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                if (!nbc_verify_csrf()) {
                    nbc_json(419, [
                        'success' => false,
                        'message' => 'رمز الحماية (CSRF) غير صالح أو انتهت صلاحيته. حدّث الصفحة وحاول مجدداً.'
                    ]);
                }
            }
        }

        return $user;
    }
}

// Admin guard: strictly requires role 'admin', separate session 'admin', and CSRF on mutations
if (!function_exists('nbc_require_admin')) {
    function nbc_require_admin(bool $enforceCsrf = true): array {
        return nbc_authenticate('admin', ['admin'], $enforceCsrf);
    }
}

// Instructor guard: strictly requires role 'instructor' or 'admin', separate session 'instructor'
if (!function_exists('nbc_require_instructor')) {
    function nbc_require_instructor(bool $enforceCsrf = false): array {
        return nbc_authenticate('instructor', ['instructor', 'admin'], $enforceCsrf);
    }
}

// Student guard: requires role 'student' or 'admin', separate session 'student'
if (!function_exists('nbc_require_student')) {
    function nbc_require_student(bool $enforceCsrf = false): array {
        return nbc_authenticate('student', ['student', 'admin'], $enforceCsrf);
    }
}
