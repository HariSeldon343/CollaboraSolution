<?php
/**
 * API Endpoint: Create Company (Tenant)
 * Creates a new company/tenant with all required information
 *
 * @version 2.0.0 - Refactored to use centralized api_auth.php
 */

// Include centralized API authentication
require_once '../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info
    $userInfo = getApiUserInfo();

    // Require super_admin role for creating companies
    requireApiRole('super_admin');

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get input data (support both POST and JSON)
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $input = $_POST;
    }

    // Validate required fields (aligned with actual DB schema)
    // Schema uses: email (not email_aziendale), manager_id (not manager_user_id),
    // sede_legale_indirizzo (not sede_legale), sedi_operative (not sede_operativa)
    $requiredFields = ['denominazione', 'codice_fiscale', 'partita_iva', 'sede_legale_indirizzo',
                        'settore_merceologico', 'numero_dipendenti',
                        'email', 'rappresentante_legale',
                        'plan_type', 'status'];

    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        apiError('Campi obbligatori mancanti: ' . implode(', ', $missingFields), 400);
    }

    // Get form data (aligned with actual database column names)
    $denominazione = trim($input['denominazione']);
    $codiceFiscale = strtoupper(trim($input['codice_fiscale']));
    $partitaIva = trim($input['partita_iva']);
    $sedeLegaleIndirizzo = trim($input['sede_legale_indirizzo']);
    $sedeLegaleComune = !empty($input['sede_legale_comune']) ? trim($input['sede_legale_comune']) : null;
    $sedeLegaleProvincia = !empty($input['sede_legale_provincia']) ? trim($input['sede_legale_provincia']) : null;
    $sediOperative = !empty($input['sedi_operative']) ? trim($input['sedi_operative']) : null;
    $settoreMerceologico = $input['settore_merceologico'];
    $numeroDipendenti = intval($input['numero_dipendenti']);

    // Optional fields - set to NULL if not provided
    $telefono = !empty($input['telefono']) ? trim($input['telefono']) : null;
    $email = trim($input['email']); // DB column is 'email', not 'email_aziendale'
    $pec = !empty($input['pec']) ? trim($input['pec']) : null;
    $capitaleSociale = !empty($input['capitale_sociale']) ? floatval($input['capitale_sociale']) : null;
    $managerId = !empty($input['manager_id']) ? intval($input['manager_id']) : null; // DB column is 'manager_id'
    $rappresentanteLegale = trim($input['rappresentante_legale']);
    $planType = $input['plan_type'];
    $status = $input['status'];

    // Code field was removed from the table, no longer needed

    // Validate Codice Fiscale (16 alphanumeric characters)
    if (!preg_match('/^[A-Z0-9]{16}$/', $codiceFiscale)) {
        apiError('Codice Fiscale non valido (16 caratteri alfanumerici)', 400);
    }

    // Validate Partita IVA (11 digits)
    if (!preg_match('/^[0-9]{11}$/', $partitaIva)) {
        apiError('Partita IVA non valida (11 cifre)', 400);
    }

    // Validate email formats
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError('Email aziendale non valida', 400);
    }

    // Validate PEC only if provided
    if ($pec !== null && !filter_var($pec, FILTER_VALIDATE_EMAIL)) {
        apiError('PEC non valida', 400);
    }

    // Validate provincia (2 characters if provided)
    if ($sedeLegaleProvincia !== null && !preg_match('/^[A-Z]{2}$/', strtoupper($sedeLegaleProvincia))) {
        apiError('Provincia non valida (2 caratteri)', 400);
    }
    if ($sedeLegaleProvincia !== null) {
        $sedeLegaleProvincia = strtoupper($sedeLegaleProvincia);
    }

    // Validate plan type
    $validPlans = ['trial', 'starter', 'professional', 'enterprise'];
    if (!in_array($planType, $validPlans)) {
        apiError('Piano non valido', 400);
    }

    // Validate status
    $validStatuses = ['active', 'pending', 'suspended'];
    if (!in_array($status, $validStatuses)) {
        apiError('Stato non valido', 400);
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if codice fiscale or partita IVA already exist
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM tenants
                                  WHERE codice_fiscale = :cf OR partita_iva = :piva");
    $checkStmt->bindParam(':cf', $codiceFiscale);
    $checkStmt->bindParam(':piva', $partitaIva);
    $checkStmt->execute();
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        apiError('Codice Fiscale o Partita IVA già esistenti', 400);
    }

    // Verify manager exists and has appropriate role (only if provided)
    if ($managerId !== null && $managerId > 0) {
        $checkManager = $conn->prepare("SELECT id, role FROM users WHERE id = :id AND role IN ('admin', 'manager') AND deleted_at IS NULL");
        $checkManager->bindParam(':id', $managerId, PDO::PARAM_INT);
        $checkManager->execute();
        if ($checkManager->rowCount() === 0) {
            apiError('Manager selezionato non valido o senza permessi adeguati', 400);
        }
    } else {
        // If manager not provided or is 0, set to NULL
        $managerId = null;
    }

    // Prepare settings JSON with plan_type
    $settings = json_encode(['plan_type' => $planType]);

    // Insert new company with CORRECT column names matching actual database schema
    // CRITICAL: Using actual DB columns - email (not email_aziendale), manager_id (not manager_user_id),
    // sede_legale_indirizzo/comune/provincia (not sede_legale), sedi_operative (not sede_operativa)
    // REMOVED: data_costituzione (does not exist in DB)
    $insertQuery = "INSERT INTO tenants (name, denominazione, codice_fiscale, partita_iva,
                    sede_legale_indirizzo, sede_legale_comune, sede_legale_provincia,
                    sedi_operative, settore_merceologico, numero_dipendenti,
                    telefono, email, pec, capitale_sociale,
                    manager_id, rappresentante_legale, status, settings,
                    created_at, updated_at)
                    VALUES (:name, :denominazione, :codice_fiscale, :partita_iva,
                    :sede_legale_indirizzo, :sede_legale_comune, :sede_legale_provincia,
                    :sedi_operative, :settore_merceologico, :numero_dipendenti,
                    :telefono, :email, :pec, :capitale_sociale,
                    :manager_id, :rappresentante_legale, :status, :settings,
                    NOW(), NOW())";

    $stmt = $conn->prepare($insertQuery);
    $stmt->bindParam(':name', $denominazione); // Use denominazione as name too
    $stmt->bindParam(':denominazione', $denominazione);
    $stmt->bindParam(':codice_fiscale', $codiceFiscale);
    $stmt->bindParam(':partita_iva', $partitaIva);
    $stmt->bindParam(':sede_legale_indirizzo', $sedeLegaleIndirizzo);
    $stmt->bindParam(':sede_legale_comune', $sedeLegaleComune);
    $stmt->bindParam(':sede_legale_provincia', $sedeLegaleProvincia);
    $stmt->bindParam(':sedi_operative', $sediOperative);
    $stmt->bindParam(':settore_merceologico', $settoreMerceologico);
    $stmt->bindParam(':numero_dipendenti', $numeroDipendenti, PDO::PARAM_INT);
    $stmt->bindParam(':telefono', $telefono, $telefono === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindParam(':email', $email); // CORRECT: 'email' not 'email_aziendale'
    $stmt->bindParam(':pec', $pec, $pec === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindParam(':capitale_sociale', $capitaleSociale);
    $stmt->bindParam(':manager_id', $managerId, $managerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT); // CORRECT: 'manager_id'
    $stmt->bindParam(':rappresentante_legale', $rappresentanteLegale);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':settings', $settings);

    if ($stmt->execute()) {
        $companyId = $conn->lastInsertId();

        // Log the action
        try {
            $logQuery = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, description, ip_address, created_at)
                         VALUES (:user_id, :tenant_id, 'create', 'company', :entity_id, :description, :ip, NOW())";

            $logStmt = $conn->prepare($logQuery);
            $logStmt->bindParam(':user_id', $userInfo['user_id']);
            $logStmt->bindParam(':tenant_id', $userInfo['tenant_id']);
            $logStmt->bindParam(':entity_id', $companyId);
            $description = "Creata nuova azienda: {$denominazione} (CF: {$codiceFiscale})";
            $logStmt->bindParam(':description', $description);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $logStmt->bindParam(':ip', $ipAddress);
            $logStmt->execute();
        } catch (PDOException $logError) {
            // Log error but don't fail the main operation
            error_log('Audit log error: ' . $logError->getMessage());
        }

        // Return success response
        apiSuccess([
            'id' => $companyId,
            'denominazione' => $denominazione,
            'name' => $denominazione,
            'codice_fiscale' => $codiceFiscale,
            'partita_iva' => $partitaIva,
            'status' => $status,
            'plan_type' => $planType
        ], 'Azienda creata con successo');
    } else {
        apiError('Errore nella creazione dell\'azienda', 500);
    }

} catch (PDOException $e) {
    logApiError('Companies Create PDO', $e);
    apiError('Errore database', 500);

} catch (Exception $e) {
    logApiError('Companies Create', $e);
    apiError($e->getMessage(), 500);
}