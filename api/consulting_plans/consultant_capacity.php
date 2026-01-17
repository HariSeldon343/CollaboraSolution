<?php
// Consulting Planning: consultant default capacity (available days) (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($method !== 'GET') {
    verifyApiCsrfToken(true);
}

/**
 * Determine if the consultant_capacities table exists (schema drift safe).
 */
function cnx_consultant_capacity_storage_available(Database $db): bool {
    try {
        return (bool)$db->fetchOne("SHOW TABLES LIKE 'consultant_capacities'");
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * List S.CO consultants (users with access to tenant 28) with optional capacity info.
 * Mirrors selection logic from api/consulting_plans/consultants.php.
 *
 * @return array<int, array{id:int,name:string,email:string,role:string,available_days:?float,notes:?string,can_edit:bool}>
 */
function cnx_list_consultants_with_capacity(Database $db, array $userInfo, bool $storageAvailable): array {
    $utaHasDeletedAt = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_tenant_access'
           AND COLUMN_NAME = 'deleted_at'
         LIMIT 1"
    );
    $utaWhere = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";

    $role = (string)($userInfo['role'] ?? 'user');
    $currentUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $isAdmin = ($role === 'super_admin' || $role === 'admin');

    $selectCapacity = $storageAvailable
        ? ", cc.available_days AS available_days, cc.notes AS notes"
        : ", NULL AS available_days, NULL AS notes";
    $joinCapacity = $storageAvailable
        ? " LEFT JOIN consultant_capacities cc ON cc.user_id = u.id "
        : "";

    $rows = $db->fetchAll(
        "SELECT DISTINCT u.id, u.name, u.email, u.role{$selectCapacity}
         FROM users u
         LEFT JOIN user_tenant_access uta
           ON uta.user_id = u.id
          AND uta.tenant_id = ?" . $utaWhere . "
         {$joinCapacity}
         WHERE u.deleted_at IS NULL
           AND u.is_active = 1
           AND (
                u.tenant_id = ?
                OR uta.user_id IS NOT NULL
           )
         ORDER BY u.name ASC",
        [CNX_VENDOR_TENANT_ID, CNX_VENDOR_TENANT_ID]
    ) ?: [];

    return array_map(static function ($r) use ($isAdmin, $currentUserId) {
        $uid = (int)($r['id'] ?? 0);
        $canEdit = $isAdmin || ($currentUserId > 0 && $uid === $currentUserId);
        $days = $r['available_days'] ?? null;
        $days = ($days === null || $days === '') ? null : (float)$days;
        return [
            'id' => $uid,
            'name' => (string)($r['name'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'role' => (string)($r['role'] ?? ''),
            'available_days' => $days,
            'notes' => ($r['notes'] ?? null) !== null ? (string)$r['notes'] : null,
            'can_edit' => (bool)$canEdit,
        ];
    }, $rows);
}

try {
    $storageAvailable = cnx_consultant_capacity_storage_available($db);

    if ($method === 'GET') {
        if ($action !== 'list') {
            api_error('Azione non valida', 400);
        }

        $consultants = cnx_list_consultants_with_capacity($db, $userInfo, $storageAvailable);

        api_success([
            'storage_available' => $storageAvailable,
            'consultants' => $consultants,
        ]);
    }

    // POST
    if ($action !== 'save') {
        api_error('Azione non valida', 400);
    }

    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) {
        // Fallback for non-JSON bodies (best-effort)
        $payload = $_POST;
        if (!is_array($payload)) {
            api_error('Dati non validi', 400);
        }
        if (isset($payload['entries']) && is_string($payload['entries'])) {
            $maybe = json_decode($payload['entries'], true);
            if (is_array($maybe)) {
                $payload['entries'] = $maybe;
            }
        }
    }
    $entries = $payload['entries'] ?? null;
    if (!is_array($entries) || empty($entries)) api_error('entries obbligatorio', 400);

    if (!$storageAvailable) {
        api_success([
            'storage_available' => false,
            'saved' => 0,
        ], 'Storage non disponibile (migrazione 37 non applicata)');
    }

    $role = (string)($userInfo['role'] ?? 'user');
    $currentUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $isAdmin = ($role === 'super_admin' || $role === 'admin');

    $clean = [];
    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        $uid = (int)($e['user_id'] ?? 0);
        if ($uid <= 0) continue;
        if (!$isAdmin && $uid !== $currentUserId) {
            api_error('Accesso negato: puoi aggiornare solo la tua disponibilità', 403);
        }
        $daysRaw = $e['available_days'] ?? null;
        $days = $daysRaw === null || $daysRaw === '' ? 0.0 : (float)$daysRaw;
        if (!is_finite($days) || $days < 0) $days = 0.0;
        if ($days > 9999.99) $days = 9999.99;
        $notes = isset($e['notes']) ? trim((string)$e['notes']) : null;
        if ($notes === '') $notes = null;
        if ($notes !== null && mb_strlen($notes) > 255) {
            $notes = mb_substr($notes, 0, 255);
        }
        $clean[] = ['user_id' => $uid, 'available_days' => $days, 'notes' => $notes];
    }
    if (empty($clean)) api_error('Nessun dato valido da salvare', 400);

    // Upsert each entry (simple + safe)
    $saved = 0;
    $db->beginTransaction();
    try {
        foreach ($clean as $e) {
            $db->query(
                "INSERT INTO consultant_capacities (user_id, available_days, notes, updated_by)
                 VALUES (:user_id, :available_days, :notes, :updated_by)
                 ON DUPLICATE KEY UPDATE
                   available_days = VALUES(available_days),
                   notes = VALUES(notes),
                   updated_by = VALUES(updated_by),
                   updated_at = CURRENT_TIMESTAMP",
                [
                    ':user_id' => (int)$e['user_id'],
                    ':available_days' => (float)$e['available_days'],
                    ':notes' => $e['notes'],
                    ':updated_by' => $currentUserId > 0 ? $currentUserId : null,
                ]
            );
            $saved++;
        }
        $db->commit();
    } catch (Throwable $tx) {
        $db->rollback();
        throw $tx;
    }

    api_success([
        'storage_available' => true,
        'saved' => $saved,
    ], 'Disponibilità aggiornata');
} catch (Throwable $e) {
    error_log('[CONSULTING_CONSULTANT_CAPACITY] ' . $e->getMessage());
    api_error('Errore gestione disponibilità consulenti', 500);
}

