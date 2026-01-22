<?php
/**
 * Audit Log Detail API Endpoint
 *
 * Returns detailed information about a single audit log entry
 *
 * Method: GET
 * Auth: Required (admin, super_admin)
 *
 * Query Parameters:
 * - id (int, required): Audit log ID
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "log": {
 *       "id": 123,
 *       "user_name": "...",
 *       "user_email": "...",
 *       "tenant_name": "...",
 *       "old_values": {...},
 *       "new_values": {...},
 *       "metadata": {...},
 *       ...
 *     }
 *   }
 * }
 */

declare(strict_types=1);

// Load required dependencies
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/audit_integrity.php';
require_once __DIR__ . '/../../includes/page_visibility_helper.php';

// 1. Initialize API environment
initializeApiEnvironment();

// 2. IMMEDIATELY verify authentication (BEFORE any operations!)
verifyApiAuthentication();

// 3. Get user info
$userInfo = getApiUserInfo();

// 4. Role-based access control: super_admin + manager only
if (!in_array($userInfo['role'], ['super_admin', 'manager'], true)) {
    api_error('Accesso negato.', 403);
}

// 5. Enforce Page Visibility setting (configurazioni.php -> Visibilità Pagine)
$tenantId = isset($userInfo['tenant_id']) ? (int)$userInfo['tenant_id'] : null;
if (!isPageVisibleForRole('audit_log', (string)($userInfo['role'] ?? 'user'), $tenantId)) {
    api_error('Accesso negato (pagina non abilitata per il tuo ruolo).', 403);
}

// 5. Validate required parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    api_error('Parametro "id" obbligatorio e deve essere un numero', 400);
}

$log_id = (int)$_GET['id'];

// 6. Get database instance
$db = Database::getInstance();

try {
    // Build query with tenant isolation
    $where_conditions = ['al.id = ?', 'al.deleted_at IS NULL'];
    $params = [$log_id];

    // Tenant isolation (unless super_admin)
    if ($userInfo['role'] !== 'super_admin') {
        $where_conditions[] = 'al.tenant_id = ?';
        $params[] = $userInfo['tenant_id'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Fetch log with complete details
    $query = "
        SELECT
            al.id,
            al.tenant_id,
            al.user_id,
            al.action,
            al.entity_type,
            al.entity_id,
            al.description,
            al.old_values,
            al.new_values,
            al.ip_address,
            al.user_agent,
            al.metadata,
            al.severity,
            al.status,
            al.created_at,
            al.deleted_at,
            al.session_id,
            al.request_method,
            al.request_url,
            al.request_data,
            al.response_code,
            al.execution_time_ms,
            al.memory_usage_kb,
            al.integrity_algo,
            al.integrity_key_id,
            al.integrity_prev_hash,
            al.integrity_hash,
            al.integrity_signed_at,
            u.name as user_name,
            u.email as user_email,
            u.role as user_role,
            t.name as tenant_name,
            t.denominazione as tenant_denominazione
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN tenants t ON al.tenant_id = t.id
        WHERE {$where_clause}
    ";

    $log = $db->fetchOne($query, $params);

    if (!$log) {
        api_error('Log non trovato o accesso negato', 404);
    }

    // Integrity verification (tamper-evident). Use RAW DB fields (before JSON decoding).
    $integrityVerification = audit_integrity_verifyLog($db->getConnection(), $log);

    // Parse JSON fields
    $formatted_log = [
        'id' => (int)$log['id'],
        'tenant_id' => (int)$log['tenant_id'],
        'tenant_name' => $log['tenant_name'],
        'tenant_denominazione' => $log['tenant_denominazione'],
        'user_id' => $log['user_id'] ? (int)$log['user_id'] : null,
        'user_name' => $log['user_name'],
        'user_email' => $log['user_email'],
        'user_role' => $log['user_role'],
        'action' => $log['action'],
        'entity_type' => $log['entity_type'],
        'entity_id' => $log['entity_id'] ? (int)$log['entity_id'] : null,
        'description' => $log['description'],
        'old_values' => $log['old_values'] ? json_decode($log['old_values'], true) : null,
        'new_values' => $log['new_values'] ? json_decode($log['new_values'], true) : null,
        'metadata' => $log['metadata'] ? json_decode($log['metadata'], true) : null,
        'ip_address' => $log['ip_address'],
        'user_agent' => $log['user_agent'],
        'session_id' => $log['session_id'],
        'request_method' => $log['request_method'],
        'request_url' => $log['request_url'],
        'request_data' => $log['request_data'] ? json_decode($log['request_data'], true) : null,
        'request_data_raw' => $log['request_data'],
        'response_code' => $log['response_code'] !== null ? (int)$log['response_code'] : null,
        'execution_time_ms' => $log['execution_time_ms'] !== null ? (int)$log['execution_time_ms'] : null,
        'memory_usage_kb' => $log['memory_usage_kb'] !== null ? (int)$log['memory_usage_kb'] : null,
        'severity' => $log['severity'],
        'status' => $log['status'],
        'created_at' => $log['created_at'],
        'deleted_at' => $log['deleted_at'],
        'integrity' => [
            'algo' => $log['integrity_algo'],
            'key_id' => $log['integrity_key_id'],
            'prev_hash' => $log['integrity_prev_hash'],
            'hash' => $log['integrity_hash'],
            'signed_at' => $log['integrity_signed_at'],
            'verification' => $integrityVerification
        ],
    ];

    // Additional context: Get entity details if possible
    if ($log['entity_id'] && $log['entity_type']) {
        $formatted_log['entity_context'] = getEntityContext(
            $db,
            $log['entity_type'],
            (int)$log['entity_id']
        );
    }

    // Success response
    api_success(['log' => $formatted_log], 'Dettaglio log recuperato con successo');

} catch (PDOException $e) {
    // Database error
    error_log('Audit Log Detail Error: ' . $e->getMessage());
    api_error('Errore durante il recupero del log: ' . $e->getMessage(), 500);

} catch (Exception $e) {
    // Generic error
    error_log('Audit Log Detail Error: ' . $e->getMessage());
    api_error('Errore imprevisto: ' . $e->getMessage(), 500);
}

/**
 * Helper function to get additional context about the entity
 *
 * @param Database $db Database instance
 * @param string $entity_type Type of entity
 * @param int $entity_id Entity ID
 * @return array|null Entity context or null
 */
function getEntityContext(Database $db, string $entity_type, int $entity_id): ?array {
    try {
        switch ($entity_type) {
            case 'file':
                $entity = $db->fetchOne(
                    'SELECT id, name, file_type FROM files WHERE id = ? AND deleted_at IS NULL',
                    [$entity_id]
                );
                return $entity ? [
                    'id' => (int)$entity['id'],
                    'name' => $entity['name'],
                    'type' => $entity['file_type']
                ] : null;

            case 'user':
                $entity = $db->fetchOne(
                    'SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL',
                    [$entity_id]
                );
                return $entity ? [
                    'id' => (int)$entity['id'],
                    'name' => $entity['name'],
                    'email' => $entity['email']
                ] : null;

            case 'tenant':
                $entity = $db->fetchOne(
                    'SELECT id, name, denominazione FROM tenants WHERE id = ? AND deleted_at IS NULL',
                    [$entity_id]
                );
                return $entity ? [
                    'id' => (int)$entity['id'],
                    'name' => $entity['name'],
                    'denominazione' => $entity['denominazione']
                ] : null;

            case 'task':
                $entity = $db->fetchOne(
                    'SELECT id, title, status FROM tasks WHERE id = ? AND deleted_at IS NULL',
                    [$entity_id]
                );
                return $entity ? [
                    'id' => (int)$entity['id'],
                    'title' => $entity['title'],
                    'status' => $entity['status']
                ] : null;

            default:
                // Generic entity lookup (if exists in audit_logs context)
                return ['entity_type' => $entity_type, 'entity_id' => $entity_id];
        }
    } catch (Exception $e) {
        // If entity context fetch fails, just return null
        error_log("Failed to fetch entity context for {$entity_type}:{$entity_id}: " . $e->getMessage());
        return null;
    }
}
