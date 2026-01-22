<?php
/**
 * Audit Log Export API
 *
 * Exports audit logs in multiple formats (CSV, PDF, Excel)
 * Applies current filters from frontend
 *
 * @author CollaboraNexio Team
 * @date 2025-10-29
 * @module Audit Log
 * @priority CRITICAL
 */

// BUG-043 Pattern: ALWAYS include CSRF token in ALL fetch() calls
// This endpoint handles file downloads with proper security

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/audit_integrity.php';
require_once __DIR__ . '/../../includes/page_visibility_helper.php';

// Initialize API environment
initializeApiEnvironment();

// Force no-cache headers (BUG-040 pattern - prevents stale responses)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Verify authentication (CRITICAL - BUG-011 pattern: call IMMEDIATELY)
verifyApiAuthentication();

// Get user info and verify CSRF token
$userInfo = getApiUserInfo();
verifyApiCsrfToken();

// Verify authorization (super_admin + manager only)
if (!in_array($userInfo['role'], ['super_admin', 'manager'], true)) {
    api_error('Accesso negato.', 403);
}

// Enforce Page Visibility setting (configurazioni.php -> Visibilità Pagine)
$tenantId = isset($userInfo['tenant_id']) ? (int)$userInfo['tenant_id'] : null;
if (!isPageVisibleForRole('audit_log', (string)($userInfo['role'] ?? 'user'), $tenantId)) {
    api_error('Accesso negato (pagina non abilitata per il tuo ruolo).', 403);
}

// Get database instance
$db = Database::getInstance();

try {
    // Get export format (GET parameter for file downloads)
    $format = $_GET['format'] ?? null;

    // Validate format
    $allowedFormats = ['csv', 'excel', 'pdf'];
    if (!$format || !in_array($format, $allowedFormats)) {
        api_error('Formato non valido. Formati supportati: csv, excel, pdf', 400);
    }

    // Get filters (same as list.php - consistent filtering)
    $tenant_id = ($userInfo['role'] === 'super_admin') ? ($_GET['tenant_id'] ?? null) : ($userInfo['tenant_id'] ?? null);
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $user_id = $_GET['user'] ?? null;
    $action = $_GET['action'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $search = $_GET['search'] ?? null;

    // Build query (NO pagination for export - get ALL matching records)
    $query = "SELECT
                a.tenant_id,
                a.id,
                a.created_at,
                a.user_id,
                u.name as user_name,
                u.email as user_email,
                u.role as user_role,
                t.name as tenant_name,
                t.denominazione as tenant_denominazione,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                a.old_values,
                a.new_values,
                a.metadata,
                a.ip_address,
                a.user_agent,
                a.session_id,
                a.request_method,
                a.request_url,
                a.request_data,
                a.response_code,
                a.execution_time_ms,
                a.memory_usage_kb,
                a.severity,
                a.status,
                a.integrity_algo,
                a.integrity_key_id,
                a.integrity_prev_hash,
                a.integrity_hash,
                a.integrity_signed_at
              FROM audit_logs a
              LEFT JOIN users u ON a.user_id = u.id
              LEFT JOIN tenants t ON a.tenant_id = t.id
              WHERE a.deleted_at IS NULL";

    $params = [];

    // Multi-tenant filter
    if ($tenant_id !== null) {
        $query .= " AND a.tenant_id = ?";
        $params[] = $tenant_id;
    }

    // Date range filter
    if ($date_from) {
        $query .= " AND a.created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $query .= " AND a.created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }

    // User filter
    if ($user_id) {
        $query .= " AND a.user_id = ?";
        $params[] = $user_id;
    }

    // Action filter (supports comma-separated list)
    if ($action) {
        $rawActions = array_values(array_filter(array_map('trim', explode(',', (string)$action))));
        if (count($rawActions) <= 1) {
            $query .= " AND a.action = ?";
            $params[] = (string)$action;
        } else {
            $valid = [];
            foreach ($rawActions as $a) {
                if ($a === '') continue;
                if (strlen($a) > 64) continue;
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $a)) continue;
                $valid[] = $a;
            }
            if (empty($valid)) {
                api_error('Filtro action non valido', 400);
            }
            $in = implode(',', array_fill(0, count($valid), '?'));
            $query .= " AND a.action IN ($in)";
            $params = array_merge($params, $valid);
        }
    }

    // Severity filter
    if ($severity) {
        $query .= " AND a.severity = ?";
        $params[] = $severity;
    }

    // Search filter (description or entity_type)
    if ($search) {
        $query .= " AND (a.description LIKE ? OR a.entity_type LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $query .= " ORDER BY a.tenant_id ASC, a.created_at ASC, a.id ASC";

    // Execute query
    $logs = $db->fetchAll($query, $params);

    if (empty($logs)) {
        api_error('Nessun log trovato per i filtri selezionati', 404);
    }

    // Compute integrity verification for export (best-effort).
    // Note: for large exports this may be expensive; we keep it simple and consistent with detail view.
    $pdo = $db->getConnection();
    foreach ($logs as &$log) {
        try {
            $ver = audit_integrity_verifyLog($pdo, $log);
            $log['integrity_verification_status'] = $ver['status'] ?? '';
            $log['integrity_verification_errors'] = isset($ver['errors']) && is_array($ver['errors']) ? implode(',', $ver['errors']) : '';
        } catch (Throwable $e) {
            $log['integrity_verification_status'] = 'verify_error';
            $log['integrity_verification_errors'] = 'verify_exception';
        }
    }
    unset($log);

    // Generate filename with timestamp
    $timestamp = date('Y-m-d_His');
    $filename = "audit_logs_{$timestamp}";

    // Export based on format
    switch ($format) {
        case 'csv':
            exportCSV($logs, $filename);
            break;
        case 'excel':
            exportExcel($logs, $filename);
            break;
        case 'pdf':
            exportPDF($logs, $filename);
            break;
    }

} catch (Exception $e) {
    error_log('[AUDIT_EXPORT] Error: ' . $e->getMessage());
    error_log('[AUDIT_EXPORT] Stack: ' . $e->getTraceAsString());
    api_error('Errore durante l\'esportazione: ' . $e->getMessage(), 500);
}

// ============================================
// EXPORT FUNCTIONS
// ============================================

/**
 * Export logs as CSV
 *
 * @param array $logs Array of log records
 * @param string $filename Base filename (without extension)
 */
function exportCSV($logs, $filename) {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add BOM for Excel UTF-8 support
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // CSV Headers (legal/forensic detail)
    $headers = [
        'Tenant ID',
        'Tenant',
        'ID',
        'Data/Ora',
        'User ID',
        'Utente',
        'Email',
        'Ruolo',
        'Azione',
        'Tipo Entità',
        'ID Entità',
        'Descrizione',
        'Indirizzo IP',
        'User Agent',
        'Session ID',
        'Severità',
        'Stato',
        'Metodo HTTP',
        'URL Richiesta',
        'Request Data',
        'Response Code',
        'Execution Time (ms)',
        'Memory (KB)',
        'Old Values',
        'New Values',
        'Metadata',
        'Integrity Algo',
        'Integrity Key ID',
        'Integrity Prev Hash',
        'Integrity Hash',
        'Integrity Signed At',
        'Integrity Verification',
        'Integrity Errors'
    ];

    fputcsv($output, $headers);

    // Data rows
    foreach ($logs as $log) {
        $row = [
            $log['tenant_id'] ?? '',
            $log['tenant_denominazione'] ?? ($log['tenant_name'] ?? 'N/A'),
            $log['id'],
            $log['created_at'],
            $log['user_id'] ?? '',
            $log['user_name'] ?? 'Sistema',
            $log['user_email'] ?? 'N/A',
            $log['user_role'] ?? 'N/A',
            translateAction($log['action']),
            translateEntityType($log['entity_type']),
            $log['entity_id'] ?? 'N/A',
            $log['description'] ?? '',
            $log['ip_address'] ?? 'N/A',
            $log['user_agent'] ?? 'N/A',
            $log['session_id'] ?? 'N/A',
            translateSeverity($log['severity']),
            translateStatus($log['status']),
            $log['request_method'] ?? 'N/A',
            $log['request_url'] ?? 'N/A',
            $log['request_data'] ?? null,
            $log['response_code'] ?? null,
            $log['execution_time_ms'] ?? null,
            $log['memory_usage_kb'] ?? null,
            $log['old_values'] ?? null,
            $log['new_values'] ?? null,
            $log['metadata'] ?? null,
            $log['integrity_algo'] ?? null,
            $log['integrity_key_id'] ?? null,
            $log['integrity_prev_hash'] ?? null,
            $log['integrity_hash'] ?? null,
            $log['integrity_signed_at'] ?? null,
            $log['integrity_verification_status'] ?? null,
            $log['integrity_verification_errors'] ?? null
        ];

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * Export logs as Excel (XML SpreadsheetML format)
 *
 * @param array $logs Array of log records
 * @param string $filename Base filename (without extension)
 */
function exportExcel($logs, $filename) {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Start XML document
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Worksheet ss:Name="Audit Logs">' . "\n";
    echo '<Table>' . "\n";

    // Header row (bold)
    echo '<Row ss:StyleID="Header">' . "\n";
    $headers = [
        'Tenant ID','Tenant','ID','Data/Ora','User ID','Utente','Email','Ruolo','Azione','Tipo Entità','ID Entità',
        'Descrizione','Indirizzo IP','User Agent','Session ID','Severità','Stato','Metodo HTTP','URL Richiesta',
        'Request Data','Response Code','Execution Time (ms)','Memory (KB)','Old Values','New Values','Metadata',
        'Integrity Algo','Integrity Key ID','Integrity Prev Hash','Integrity Hash','Integrity Signed At',
        'Integrity Verification','Integrity Errors'
    ];
    foreach ($headers as $header) {
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
    }
    echo '</Row>' . "\n";

    // Data rows
    foreach ($logs as $log) {
        echo '<Row>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['tenant_id'] ?? '') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['tenant_denominazione'] ?? ($log['tenant_name'] ?? 'N/A')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="Number">' . $log['id'] . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['created_at']) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['user_id'] ?? '') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['user_name'] ?? 'Sistema') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['user_email'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['user_role'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars(translateAction($log['action'])) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars(translateEntityType($log['entity_type'])) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['entity_id'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['description'] ?? '') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['ip_address'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['user_agent'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['session_id'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars(translateSeverity($log['severity'])) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars(translateStatus($log['status'])) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['request_method'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($log['request_url'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['request_data'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['response_code'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['execution_time_ms'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['memory_usage_kb'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['old_values'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['new_values'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['metadata'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_algo'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_key_id'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_prev_hash'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_hash'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_signed_at'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_verification_status'] ?? '')) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)($log['integrity_verification_errors'] ?? '')) . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>' . "\n";
    exit;
}

/**
 * Export logs as PDF (HTML to PDF simple conversion)
 *
 * @param array $logs Array of log records
 * @param string $filename Base filename (without extension)
 */
function exportPDF($logs, $filename) {
    // NOTE: No PDF renderer bundled. We ship a print-friendly HTML (user can "Stampa > Salva in PDF").
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf.html"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Simple HTML to PDF conversion (works without external libraries)
    // For production, consider using TCPDF or mPDF

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Audit Logs Export</title>
        <style>
            @media print { @page { size: A4 landscape; margin: 10mm; } }
            body { font-family: Arial, sans-serif; font-size: 10px; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th { background-color: #4a5568; color: white; padding: 8px; text-align: left; font-weight: bold; }
            td { border: 1px solid #e2e8f0; padding: 6px; }
            tr:nth-child(even) { background-color: #f7fafc; }
            h1 { color: #2d3748; font-size: 18px; margin-bottom: 10px; }
            .footer { margin-top: 20px; font-size: 8px; color: #718096; text-align: center; }
            .note { font-size: 10px; color: #111827; background: #FEF3C7; padding: 10px; border-radius: 8px; }
        </style>
    </head>
    <body>
        <h1>CollaboraNexio - Audit Logs Export</h1>
        <p><strong>Generato:</strong> ' . date('d/m/Y H:i:s') . '</p>
        <p><strong>Totale Record:</strong> ' . count($logs) . '</p>
        <div class="note"><strong>Nota:</strong> Questo file è HTML stampabile. Apri e usa “Stampa” per generare un PDF firmabile/archiviabile.</div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Data/Ora</th>
                    <th>Utente</th>
                    <th>Azione</th>
                    <th>Entità</th>
                    <th>Descrizione</th>
                    <th>IP</th>
                    <th>Metodo</th>
                    <th>URL</th>
                    <th>Severità</th>
                    <th>Esito</th>
                    <th>Hash</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($logs as $log) {
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($log['id']) . '</td>
                    <td>' . htmlspecialchars($log['created_at']) . '</td>
                    <td>' . htmlspecialchars($log['user_name'] ?? 'Sistema') . '</td>
                    <td>' . htmlspecialchars(translateAction($log['action'])) . '</td>
                    <td>' . htmlspecialchars(translateEntityType($log['entity_type'])) . '</td>
                    <td>' . htmlspecialchars(substr($log['description'] ?? '', 0, 50)) . '</td>
                    <td>' . htmlspecialchars($log['ip_address'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($log['request_method'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars(substr($log['request_url'] ?? 'N/A', 0, 60)) . '</td>
                    <td>' . htmlspecialchars(translateSeverity($log['severity'])) . '</td>
                    <td>' . htmlspecialchars(translateStatus($log['status'])) . '</td>
                    <td>' . htmlspecialchars($log['integrity_hash'] ?? 'N/A') . '</td>
                </tr>';
    }

    $html .= '
            </tbody>
        </table>

        <div class="footer">
            CollaboraNexio Audit Logs - CONFIDENTIAL
        </div>
    </body>
    </html>';

    // Print-friendly HTML
    echo $html;
    exit;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function translateAction($action) {
    $translations = [
        'login' => 'Accesso',
        'logout' => 'Disconnessione',
        'create' => 'Creazione',
        'update' => 'Modifica',
        'delete' => 'Eliminazione',
        'download' => 'Download',
        'upload' => 'Upload',
        'view' => 'Visualizzazione',
        'access' => 'Accesso Pagina',
        'assign' => 'Assegnazione',
        'complete' => 'Completamento',
        'close' => 'Chiusura'
    ];

    return $translations[$action] ?? ucfirst($action);
}

function translateEntityType($type) {
    $translations = [
        'user' => 'Utente',
        'file' => 'File',
        'folder' => 'Cartella',
        'task' => 'Attività',
        'ticket' => 'Ticket',
        'document' => 'Documento',
        'page' => 'Pagina',
        'audit_log' => 'Log Audit'
    ];

    return $translations[$type] ?? ucfirst($type);
}

function translateSeverity($severity) {
    $translations = [
        'info' => 'Informativo',
        'warning' => 'Avviso',
        'error' => 'Errore',
        'critical' => 'Critico'
    ];

    return $translations[$severity] ?? ucfirst($severity);
}

function translateStatus($status) {
    $translations = [
        'success' => 'Successo',
        'failed' => 'Fallito',
        'pending' => 'In Attesa'
    ];

    return $translations[$status] ?? ucfirst($status);
}
