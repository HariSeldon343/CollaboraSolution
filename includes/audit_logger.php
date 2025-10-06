<?php
/**
 * Audit Logger Class
 *
 * Provides a simple interface for logging user actions to the audit_logs table.
 * Follows the multi-tenant architecture of CollaboraNexio.
 *
 * @author Database Architect
 * @version 2025-09-29
 */

require_once __DIR__ . '/../includes/db.php';

class AuditLogger {
    private $db;
    private $conn;

    // Action types constants
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_RESTORE = 'restore';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_LOGIN_FAILED = 'login_failed';
    const ACTION_SESSION_EXPIRED = 'session_expired';
    const ACTION_DOWNLOAD = 'download';
    const ACTION_UPLOAD = 'upload';
    const ACTION_VIEW = 'view';
    const ACTION_EXPORT = 'export';
    const ACTION_IMPORT = 'import';
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_SUBMIT = 'submit';
    const ACTION_CANCEL = 'cancel';
    const ACTION_SHARE = 'share';
    const ACTION_UNSHARE = 'unshare';
    const ACTION_PERMISSION_GRANT = 'permission_grant';
    const ACTION_PERMISSION_REVOKE = 'permission_revoke';
    const ACTION_PASSWORD_CHANGE = 'password_change';
    const ACTION_PASSWORD_RESET = 'password_reset';
    const ACTION_EMAIL_CHANGE = 'email_change';
    const ACTION_TENANT_SWITCH = 'tenant_switch';
    const ACTION_SYSTEM_UPDATE = 'system_update';
    const ACTION_BACKUP = 'backup';
    const ACTION_RESTORE_BACKUP = 'restore_backup';

    // Entity types constants
    const ENTITY_USER = 'user';
    const ENTITY_TENANT = 'tenant';
    const ENTITY_FILE = 'file';
    const ENTITY_FOLDER = 'folder';
    const ENTITY_PROJECT = 'project';
    const ENTITY_TASK = 'task';
    const ENTITY_CALENDAR_EVENT = 'calendar_event';
    const ENTITY_CHAT_MESSAGE = 'chat_message';
    const ENTITY_CHAT_CHANNEL = 'chat_channel';
    const ENTITY_DOCUMENT_APPROVAL = 'document_approval';
    const ENTITY_SYSTEM_SETTING = 'system_setting';
    const ENTITY_NOTIFICATION = 'notification';
    const ENTITY_PERMISSION = 'permission';
    const ENTITY_ROLE = 'role';
    const ENTITY_SESSION = 'session';
    const ENTITY_API_KEY = 'api_key';
    const ENTITY_BACKUP = 'backup';

    // Severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';

    // Status types
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }

    /**
     * Log a user action
     *
     * @param array $params Associative array with logging parameters
     * @return bool Success status
     */
    public function log(array $params): bool {
        try {
            // Extract parameters with defaults
            $tenant_id = $params['tenant_id'] ?? $this->getCurrentTenantId();
            $user_id = $params['user_id'] ?? $this->getCurrentUserId();
            $action = $params['action'] ?? self::ACTION_VIEW;
            $entity_type = $params['entity_type'] ?? self::ENTITY_SYSTEM_SETTING;
            $entity_id = $params['entity_id'] ?? null;
            $old_values = $params['old_values'] ?? null;
            $new_values = $params['new_values'] ?? null;
            $description = $params['description'] ?? null;
            $ip_address = $params['ip_address'] ?? $this->getClientIp();
            $user_agent = $params['user_agent'] ?? $this->getUserAgent();
            $session_id = $params['session_id'] ?? session_id();
            $request_method = $params['request_method'] ?? $_SERVER['REQUEST_METHOD'] ?? null;
            $request_url = $params['request_url'] ?? $this->getCurrentUrl();
            $request_data = $params['request_data'] ?? null;
            $response_code = $params['response_code'] ?? http_response_code();
            $execution_time_ms = $params['execution_time_ms'] ?? null;
            $memory_usage_kb = $params['memory_usage_kb'] ?? $this->getMemoryUsage();
            $severity = $params['severity'] ?? self::SEVERITY_INFO;
            $status = $params['status'] ?? self::STATUS_SUCCESS;

            // Prepare JSON values
            if (is_array($old_values)) {
                $old_values = json_encode($old_values);
            }
            if (is_array($new_values)) {
                $new_values = json_encode($new_values);
            }
            if (is_array($request_data)) {
                // Sanitize sensitive data
                $request_data = $this->sanitizeRequestData($request_data);
                $request_data = json_encode($request_data);
            }

            // Prepare SQL statement
            $sql = "INSERT INTO audit_logs (
                        tenant_id, user_id, action, entity_type, entity_id,
                        old_values, new_values, description,
                        ip_address, user_agent, session_id,
                        request_method, request_url, request_data, response_code,
                        execution_time_ms, memory_usage_kb,
                        severity, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->conn->prepare($sql);

            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }

            $result = $stmt->execute([
                $tenant_id, $user_id, $action, $entity_type, $entity_id,
                $old_values, $new_values, $description,
                $ip_address, $user_agent, $session_id,
                $request_method, $request_url, $request_data, $response_code,
                $execution_time_ms, $memory_usage_kb,
                $severity, $status
            ]);

            return $result;

        } catch (Exception $e) {
            error_log("AuditLogger Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log a simple action with minimal parameters
     */
    public function logSimple(
        string $action,
        string $entity_type,
        ?int $entity_id = null,
        ?string $description = null,
        string $severity = self::SEVERITY_INFO,
        string $status = self::STATUS_SUCCESS
    ): bool {
        return $this->log([
            'action' => $action,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'description' => $description,
            'severity' => $severity,
            'status' => $status
        ]);
    }

    /**
     * Log a login attempt
     */
    public function logLogin(string $email, bool $success = true, ?int $user_id = null): bool {
        return $this->log([
            'user_id' => $user_id,
            'action' => $success ? self::ACTION_LOGIN : self::ACTION_LOGIN_FAILED,
            'entity_type' => self::ENTITY_USER,
            'entity_id' => $user_id,
            'new_values' => ['email' => $email, 'login_time' => date('Y-m-d H:i:s')],
            'description' => $success
                ? "User $email successfully logged in"
                : "Failed login attempt for $email",
            'severity' => $success ? self::SEVERITY_INFO : self::SEVERITY_WARNING,
            'status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILED
        ]);
    }

    /**
     * Log a logout
     */
    public function logLogout(?int $user_id = null): bool {
        return $this->logSimple(
            self::ACTION_LOGOUT,
            self::ENTITY_USER,
            $user_id,
            "User logged out",
            self::SEVERITY_INFO,
            self::STATUS_SUCCESS
        );
    }

    /**
     * Log file operations
     */
    public function logFileOperation(
        string $action,
        int $file_id,
        string $filename,
        array $details = []
    ): bool {
        return $this->log([
            'action' => $action,
            'entity_type' => self::ENTITY_FILE,
            'entity_id' => $file_id,
            'new_values' => array_merge(['filename' => $filename], $details),
            'description' => ucfirst($action) . " file: $filename"
        ]);
    }

    /**
     * Log document approval actions
     */
    public function logApproval(
        int $approval_id,
        string $action,
        string $document_name,
        array $old_status = [],
        array $new_status = []
    ): bool {
        return $this->log([
            'action' => $action,
            'entity_type' => self::ENTITY_DOCUMENT_APPROVAL,
            'entity_id' => $approval_id,
            'old_values' => $old_status,
            'new_values' => $new_status,
            'description' => ucfirst($action) . " document: $document_name",
            'severity' => self::SEVERITY_INFO
        ]);
    }

    /**
     * Log permission changes
     */
    public function logPermissionChange(
        int $user_id,
        string $old_role,
        string $new_role,
        string $user_name
    ): bool {
        return $this->log([
            'action' => self::ACTION_UPDATE,
            'entity_type' => self::ENTITY_USER,
            'entity_id' => $user_id,
            'old_values' => ['role' => $old_role],
            'new_values' => ['role' => $new_role],
            'description' => "Changed role for $user_name from $old_role to $new_role",
            'severity' => self::SEVERITY_WARNING
        ]);
    }

    /**
     * Get audit logs for a specific entity
     */
    public function getEntityLogs(
        string $entity_type,
        int $entity_id,
        int $limit = 50
    ): ?array {
        $sql = "SELECT al.*, u.name as user_name, u.email as user_email
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.entity_type = ? AND al.entity_id = ?
                ORDER BY al.created_at DESC
                LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->execute([$entity_type, $entity_id, $limit]);

        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Decode JSON fields
            if ($row['old_values']) {
                $row['old_values'] = json_decode($row['old_values'], true);
            }
            if ($row['new_values']) {
                $row['new_values'] = json_decode($row['new_values'], true);
            }
            if ($row['request_data']) {
                $row['request_data'] = json_decode($row['request_data'], true);
            }
            $logs[] = $row;
        }

        return $logs;
    }

    /**
     * Get user activity logs
     */
    public function getUserLogs(int $user_id, int $limit = 100): ?array {
        $sql = "SELECT * FROM audit_logs
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->execute([$user_id, $limit]);

        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Decode JSON fields
            if ($row['old_values']) {
                $row['old_values'] = json_decode($row['old_values'], true);
            }
            if ($row['new_values']) {
                $row['new_values'] = json_decode($row['new_values'], true);
            }
            $logs[] = $row;
        }

        return $logs;
    }

    /**
     * Get critical logs for monitoring
     */
    public function getCriticalLogs(int $hours = 24): ?array {
        $sql = "SELECT al.*, u.name as user_name, t.name as tenant_name
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN tenants t ON al.tenant_id = t.id
                WHERE al.severity IN ('error', 'critical')
                   OR al.status = 'failed'
                   OR al.action IN ('delete', 'permission_grant', 'permission_revoke')
                AND al.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY al.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->execute([$hours]);

        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $logs;
    }

    /**
     * Helper: Get current tenant ID from session
     */
    private function getCurrentTenantId(): ?int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return $_SESSION['tenant_id'] ?? null;
    }

    /**
     * Helper: Get current user ID from session
     */
    private function getCurrentUserId(): ?int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Helper: Get client IP address
     */
    private function getClientIp(): string {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = trim($_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP,
                               FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Helper: Get user agent
     */
    private function getUserAgent(): ?string {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Helper: Get current URL
     */
    private function getCurrentUrl(): ?string {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return null;
        }

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return $protocol . '://' . $host . $_SERVER['REQUEST_URI'];
    }

    /**
     * Helper: Get memory usage in KB
     */
    private function getMemoryUsage(): int {
        return round(memory_get_usage() / 1024);
    }

    /**
     * Helper: Sanitize request data to remove sensitive information
     */
    private function sanitizeRequestData(array $data): array {
        $sensitive_keys = [
            'password', 'pwd', 'pass', 'passwd', 'secret',
            'token', 'api_key', 'apikey', 'access_token',
            'private_key', 'credit_card', 'cvv', 'ssn'
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $lower_key = strtolower($key);
            $is_sensitive = false;

            foreach ($sensitive_keys as $sensitive) {
                if (strpos($lower_key, $sensitive) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ($is_sensitive) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeRequestData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Clean old audit logs (for maintenance)
     */
    public function cleanOldLogs(int $days = 90): int {
        $sql = "DELETE FROM audit_logs
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND severity = 'info'";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->execute([$days]);
        $affected = $stmt->rowCount();

        // Log the cleanup action
        $this->logSimple(
            self::ACTION_DELETE,
            self::ENTITY_SYSTEM_SETTING,
            null,
            "Cleaned $affected old audit log entries older than $days days",
            self::SEVERITY_INFO
        );

        return $affected;
    }
}