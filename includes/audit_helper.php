<?php
/**
 * CollaboraNexio - Centralized Audit Logging Helper
 *
 * Provides a unified interface for logging all user actions across the platform.
 * All methods are static for easy integration without instantiation.
 *
 * @version 1.0.0
 * @date 2025-10-27
 *
 * USAGE:
 * require_once __DIR__ . '/audit_helper.php';
 * AuditLogger::logLogin($userId, $tenantId, true);
 * AuditLogger::logCreate($userId, $tenantId, 'user', $newUserId, 'Created new user', $newValues);
 *
 * STANDARDS:
 * - BUG-029 Pattern: Separate try-catch, non-blocking, explicit error logging
 * - Multi-tenant isolation: ALWAYS require tenant_id
 * - JSON data structure for old_values/new_values/metadata
 * - Severity mapping: info (normal), warning (security), critical (destructive)
 */

class AuditLogger
{
    /**
     * Database instance (singleton)
     * @var Database|null
     */
    private static $db = null;

    /**
     * Get database instance
     * @return Database
     */
    private static function getDb()
    {
        if (self::$db === null) {
            require_once __DIR__ . '/db.php';
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    /**
     * Core logging function - all other methods call this
     *
     * @param int $userId User performing the action
     * @param int $tenantId Tenant context (MANDATORY for multi-tenant isolation)
     * @param string $action Action performed (e.g., 'create', 'update', 'delete', 'login', 'logout')
     * @param string $entityType Type of entity affected (e.g., 'user', 'file', 'task', 'tenant')
     * @param int|null $entityId ID of affected entity
     * @param string $description Human-readable description
     * @param array|null $oldValues Previous values (for updates/deletes)
     * @param array|null $newValues New values (for creates/updates)
     * @param array|null $metadata Additional context data
     * @param string $severity Severity level: 'info', 'warning', 'error', 'critical'
     * @param string $status Status: 'success', 'failed', 'pending'
     * @return bool True if logged successfully, false otherwise
     */
    private static function logAction(
        $userId,
        $tenantId,
        $action,
        $entityType,
        $entityId = null,
        $description = '',
        $oldValues = null,
        $newValues = null,
        $metadata = null,
        $severity = 'info',
        $status = 'success'
    ) {
        // Validate required parameters
        if (empty($tenantId)) {
            error_log("[AUDIT LOG ERROR] tenant_id is required for multi-tenant isolation");
            return false;
        }

        // BUG-029 Pattern: Audit logging in separate try-catch, non-blocking
        try {
            $db = self::getDb();

            // Prepare audit data
            $auditData = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'old_values' => $oldValues ? json_encode($oldValues) : null,
                'new_values' => $newValues ? json_encode($newValues) : null,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'session_id' => session_id() ?: null,
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'request_url' => $_SERVER['REQUEST_URI'] ?? null,
                'severity' => $severity,
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Insert audit log
            $auditInsertId = $db->insert('audit_logs', $auditData);

            if (!$auditInsertId) {
                error_log("[AUDIT LOG WARNING] Insert returned invalid ID");
                error_log("[AUDIT LOG WARNING] Context: User ID: $userId, Tenant ID: $tenantId, Action: $action, Entity: $entityType");
                return false;
            }

            return true;

        } catch (Exception $auditException) {
            // Explicit error logging with full context
            error_log("[AUDIT LOG FAILURE] Error: " . $auditException->getMessage());
            error_log("[AUDIT LOG FAILURE] File: " . $auditException->getFile() . " Line: " . $auditException->getLine());
            error_log("[AUDIT LOG FAILURE] Context: User ID: $userId, Tenant ID: $tenantId, Action: $action, Entity Type: $entityType, Entity ID: " . ($entityId ?? 'null'));
            error_log("[AUDIT LOG FAILURE] Audit data: " . json_encode($auditData ?? []));
            error_log("[AUDIT LOG FAILURE] ------------------------");

            // DO NOT throw - operation should succeed even if audit fails
            return false;
        }
    }

    /**
     * Log user login (successful or failed)
     *
     * @param int $userId User ID attempting login
     * @param int $tenantId Tenant ID
     * @param bool $success True if login succeeded, false if failed
     * @param string|null $failureReason Reason for failure (e.g., "Invalid password", "User not found")
     * @return bool
     */
    public static function logLogin($userId, $tenantId, $success = true, $failureReason = null)
    {
        $description = $success ? 'User logged in successfully' : "Login failed: $failureReason";
        $metadata = [
            'success' => $success,
            'failure_reason' => $failureReason,
            'login_time' => date('Y-m-d H:i:s')
        ];

        return self::logAction(
            $userId,
            $tenantId,
            'login',
            'user',
            $userId,
            $description,
            null,
            null,
            $metadata,
            $success ? 'info' : 'warning',
            $success ? 'success' : 'failed'
        );
    }

    /**
     * Log user logout
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @return bool
     */
    public static function logLogout($userId, $tenantId)
    {
        $metadata = [
            'logout_time' => date('Y-m-d H:i:s')
        ];

        return self::logAction(
            $userId,
            $tenantId,
            'logout',
            'user',
            $userId,
            'User logged out',
            null,
            null,
            $metadata,
            'info',
            'success'
        );
    }

    /**
     * Log page access (for tracking user activity)
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param string $pageName Page accessed (e.g., 'dashboard', 'files', 'tasks')
     * @return bool
     */
    public static function logPageAccess($userId, $tenantId, $pageName)
    {
        $metadata = [
            'page_name' => $pageName,
            'access_time' => date('Y-m-d H:i:s'),
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null
        ];

        return self::logAction(
            $userId,
            $tenantId,
            'access',
            'page',
            null,
            "Accessed page: $pageName",
            null,
            null,
            $metadata,
            'info',
            'success'
        );
    }

    /**
     * Log entity creation
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param string $entityType Entity type (e.g., 'user', 'file', 'task')
     * @param int $entityId Created entity ID
     * @param string $description Human-readable description
     * @param array $newValues New values as associative array
     * @return bool
     */
    public static function logCreate($userId, $tenantId, $entityType, $entityId, $description, $newValues = [])
    {
        return self::logAction(
            $userId,
            $tenantId,
            'create',
            $entityType,
            $entityId,
            $description,
            null,
            $newValues,
            null,
            'info',
            'success'
        );
    }

    /**
     * Log entity update/modification
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param string $entityType Entity type
     * @param int $entityId Updated entity ID
     * @param string $description Human-readable description
     * @param array $oldValues Previous values
     * @param array $newValues New values
     * @param string $severity Severity: 'info' (normal), 'warning' (security-sensitive)
     * @return bool
     */
    public static function logUpdate($userId, $tenantId, $entityType, $entityId, $description, $oldValues = [], $newValues = [], $severity = 'info')
    {
        return self::logAction(
            $userId,
            $tenantId,
            'update',
            $entityType,
            $entityId,
            $description,
            $oldValues,
            $newValues,
            null,
            $severity,
            'success'
        );
    }

    /**
     * Log entity deletion (soft or permanent)
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param string $entityType Entity type
     * @param int $entityId Deleted entity ID
     * @param string $description Human-readable description
     * @param array $oldValues Entity data before deletion
     * @param bool $isPermanent True if permanent delete, false if soft delete
     * @return bool
     */
    public static function logDelete($userId, $tenantId, $entityType, $entityId, $description, $oldValues = [], $isPermanent = false)
    {
        $metadata = [
            'delete_type' => $isPermanent ? 'permanent' : 'soft',
            'deleted_at' => date('Y-m-d H:i:s')
        ];

        return self::logAction(
            $userId,
            $tenantId,
            'delete',
            $entityType,
            $entityId,
            $description,
            $oldValues,
            null,
            $metadata,
            'critical', // Deletions are always critical
            'success'
        );
    }

    /**
     * Log password change
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param int $targetUserId User whose password was changed
     * @param bool $isSelfChange True if user changed own password, false if admin changed it
     * @return bool
     */
    public static function logPasswordChange($userId, $tenantId, $targetUserId, $isSelfChange = true)
    {
        $description = $isSelfChange
            ? 'User changed own password'
            : "Admin changed password for user ID: $targetUserId";

        $metadata = [
            'target_user_id' => $targetUserId,
            'self_change' => $isSelfChange,
            'changed_at' => date('Y-m-d H:i:s')
        ];

        return self::logAction(
            $userId,
            $tenantId,
            'update',
            'user',
            $targetUserId,
            $description,
            ['password' => '[REDACTED]'], // Never log actual passwords
            ['password' => '[CHANGED]'],
            $metadata,
            'warning', // Password changes are security-sensitive
            'success'
        );
    }

    /**
     * Log file download
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param int $fileId File ID
     * @param string $fileName File name
     * @param int $fileSize File size in bytes
     * @return bool
     */
    public static function logFileDownload($userId, $tenantId, $fileId, $fileName, $fileSize)
    {
        $metadata = [
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'download_time' => date('Y-m-d H:i:s')
        ];

        return self::logAction(
            $userId,
            $tenantId,
            'download',
            'file',
            $fileId,
            "Downloaded file: $fileName",
            null,
            null,
            $metadata,
            'info',
            'success'
        );
    }

    /**
     * Log generic action (for special cases)
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param string $action Action name
     * @param string $entityType Entity type
     * @param int|null $entityId Entity ID
     * @param string $description Description
     * @param array|null $oldValues Old values
     * @param array|null $newValues New values
     * @param array|null $metadata Metadata
     * @param string $severity Severity level
     * @param string $status Status
     * @return bool
     */
    public static function logGeneric(
        $userId,
        $tenantId,
        $action,
        $entityType,
        $entityId = null,
        $description = '',
        $oldValues = null,
        $newValues = null,
        $metadata = null,
        $severity = 'info',
        $status = 'success'
    ) {
        return self::logAction(
            $userId,
            $tenantId,
            $action,
            $entityType,
            $entityId,
            $description,
            $oldValues,
            $newValues,
            $metadata,
            $severity,
            $status
        );
    }
}
