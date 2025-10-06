<?php
// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Start output buffering to catch any unexpected output
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Initialize response
    $response = ['success' => false, 'message' => '', 'data' => null];

    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato', 'success' => false]));
    }

    // Get current user details from session
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
    $isSuperAdmin = ($userRole === 'super_admin');
    $currentTenantId = $_SESSION['tenant_id'] ?? null;

    // Check if user is super admin
    if (!$isSuperAdmin) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Solo i Super Admin possono modificare le aziende', 'success' => false]));
    }

    // Get input data (support both POST and JSON)
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $input = $_POST;
    }

    // Verify CSRF token from headers or POST data or input
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';

    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido', 'success' => false]));
    }

    // Validate required fields
    if (empty($input['id']) && empty($input['company_id'])) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'ID azienda mancante', 'success' => false]));
    }

    // Get form data
    $companyId = intval($input['id'] ?? $input['company_id'] ?? 0);

    // Required fields validation
    $requiredFields = ['denominazione', 'codice_fiscale', 'partita_iva', 'sede_legale',
                        'settore_merceologico', 'numero_dipendenti', 'telefono',
                        'email_aziendale', 'pec', 'manager_user_id', 'rappresentante_legale',
                        'data_costituzione', 'plan_type', 'status'];

    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        $response['message'] = 'Campi obbligatori mancanti: ' . implode(', ', $missingFields);
        ob_clean();
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Extract all fields
    $denominazione = trim($input['denominazione']);
    $codiceFiscale = strtoupper(trim($input['codice_fiscale']));
    $partitaIva = trim($input['partita_iva']);
    $sedeLegale = trim($input['sede_legale']);
    $sedeOperativa = !empty($input['sede_operativa']) ? trim($input['sede_operativa']) : null;
    $settoreMerceologico = $input['settore_merceologico'];
    $numeroDipendenti = intval($input['numero_dipendenti']);
    $telefono = trim($input['telefono']);
    $emailAziendale = trim($input['email_aziendale']);
    $pec = trim($input['pec']);
    $dataCostituzione = $input['data_costituzione'];
    $capitaleSociale = !empty($input['capitale_sociale']) ? floatval($input['capitale_sociale']) : null;
    $managerUserId = intval($input['manager_user_id']);
    $rappresentanteLegale = trim($input['rappresentante_legale']);
    $planType = $input['plan_type'];
    $status = $input['status'];

    // Code field was removed from the table, no longer needed

    // Validate Codice Fiscale (16 alphanumeric characters)
    if (!preg_match('/^[A-Z0-9]{16}$/', $codiceFiscale)) {
        $response['message'] = 'Codice Fiscale non valido (16 caratteri alfanumerici)';
        ob_clean();
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Validate Partita IVA (11 digits)
    if (!preg_match('/^[0-9]{11}$/', $partitaIva)) {
        $response['message'] = 'Partita IVA non valida (11 cifre)';
        ob_clean();
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Validate email formats
    if (!filter_var($emailAziendale, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Email aziendale non valida';
        ob_clean();
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    if (!filter_var($pec, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'PEC non valida';
        ob_clean();
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Validate plan type
    $validPlans = ['trial', 'starter', 'professional', 'enterprise'];
    if (!in_array($planType, $validPlans)) {
        $response['message'] = 'Piano non valido';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Validate status
    $validStatuses = ['active', 'pending', 'suspended'];
    if (!in_array($status, $validStatuses)) {
        $response['message'] = 'Stato non valido';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if company exists
    $checkStmt = $conn->prepare("SELECT * FROM tenants WHERE id = :id");
    $checkStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
    $checkStmt->execute();
    $existingCompany = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingCompany) {
        $response['message'] = 'Azienda non trovata';
        http_response_code(404);
        echo json_encode($response);
        exit;
    }

    // Check if new codice fiscale or partita IVA are already used by another company
    $duplicateCheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM tenants
                                          WHERE (codice_fiscale = :cf OR partita_iva = :piva OR code = :code)
                                          AND id != :id");
    $duplicateCheckStmt->bindParam(':cf', $codiceFiscale);
    $duplicateCheckStmt->bindParam(':piva', $partitaIva);
    $duplicateCheckStmt->bindParam(':code', $code);
    $duplicateCheckStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
    $duplicateCheckStmt->execute();
    $result = $duplicateCheckStmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        $response['message'] = 'Codice Fiscale, Partita IVA o codice già in uso da un\'altra azienda';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Verify manager exists and has appropriate role
    if ($managerUserId > 0) {
        $checkManager = $conn->prepare("SELECT id, role FROM users WHERE id = :id AND role IN ('admin', 'manager')");
        $checkManager->bindParam(':id', $managerUserId);
        $checkManager->execute();
        if ($checkManager->rowCount() === 0) {
            $response['message'] = 'Manager selezionato non valido o senza permessi adeguati';
            ob_clean();
            http_response_code(400);
            echo json_encode($response);
            exit;
        }
    }

    // Get existing settings and merge with plan_type
    $getSettingsStmt = $conn->prepare("SELECT settings FROM tenants WHERE id = :id");
    $getSettingsStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
    $getSettingsStmt->execute();
    $existingSettings = $getSettingsStmt->fetch(PDO::FETCH_ASSOC);
    $settings = $existingSettings['settings'] ? json_decode($existingSettings['settings'], true) : [];
    $settings['plan_type'] = $planType;
    $settingsJson = json_encode($settings);

    // Update company with all fields (plan_type goes in settings JSON)
    $updateQuery = "UPDATE tenants
                    SET name = :name,
                        denominazione = :denominazione,
                        codice_fiscale = :codice_fiscale,
                        partita_iva = :partita_iva,
                        sede_legale = :sede_legale,
                        sede_operativa = :sede_operativa,
                        settore_merceologico = :settore_merceologico,
                        numero_dipendenti = :numero_dipendenti,
                        telefono = :telefono,
                        email_aziendale = :email_aziendale,
                        pec = :pec,
                        data_costituzione = :data_costituzione,
                        capitale_sociale = :capitale_sociale,
                        manager_user_id = :manager_user_id,
                        rappresentante_legale = :rappresentante_legale,
                        status = :status,
                        settings = :settings,
                        updated_at = NOW()
                    WHERE id = :id";

    $stmt = $conn->prepare($updateQuery);
    $stmt->bindParam(':name', $denominazione); // Use denominazione as name too
    $stmt->bindParam(':denominazione', $denominazione);
    $stmt->bindParam(':codice_fiscale', $codiceFiscale);
    $stmt->bindParam(':partita_iva', $partitaIva);
    $stmt->bindParam(':sede_legale', $sedeLegale);
    $stmt->bindParam(':sede_operativa', $sedeOperativa);
    $stmt->bindParam(':settore_merceologico', $settoreMerceologico);
    $stmt->bindParam(':numero_dipendenti', $numeroDipendenti, PDO::PARAM_INT);
    $stmt->bindParam(':telefono', $telefono);
    $stmt->bindParam(':email_aziendale', $emailAziendale);
    $stmt->bindParam(':pec', $pec);
    $stmt->bindParam(':data_costituzione', $dataCostituzione);
    $stmt->bindParam(':capitale_sociale', $capitaleSociale);
    $stmt->bindParam(':manager_user_id', $managerUserId, PDO::PARAM_INT);
    $stmt->bindParam(':rappresentante_legale', $rappresentanteLegale);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':settings', $settingsJson);
    $stmt->bindParam(':id', $companyId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Prepare change description for audit log
        $changes = [];
        if (($existingCompany['denominazione'] ?? $existingCompany['name']) != $denominazione) {
            $changes[] = "denominazione cambiata";
        }
        if (($existingCompany['codice_fiscale'] ?? '') != $codiceFiscale) {
            $changes[] = "codice fiscale aggiornato";
        }
        if (($existingCompany['partita_iva'] ?? '') != $partitaIva) {
            $changes[] = "partita IVA aggiornata";
        }
        if ($existingCompany['status'] != $status) {
            $changes[] = "stato da '{$existingCompany['status']}' a '{$status}'";
        }

        if (!empty($changes)) {
            // Log the action
            try {
                $logQuery = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, description, ip_address, created_at)
                             VALUES (:user_id, :tenant_id, 'update', 'company', :entity_id, :description, :ip, NOW())";

                $logStmt = $conn->prepare($logQuery);
                $logStmt->bindParam(':user_id', $currentUserId);
                $logStmt->bindParam(':tenant_id', $currentTenantId);
                $logStmt->bindParam(':entity_id', $companyId);
                $description = "Aggiornata azienda {$denominazione}: " . implode(', ', $changes);
                $logStmt->bindParam(':description', $description);
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $logStmt->bindParam(':ip', $ipAddress);
                $logStmt->execute();
            } catch (PDOException $logError) {
                // Log error but don't fail the main operation
                error_log('Audit log error: ' . $logError->getMessage());
            }
        }

        // Clean output buffer
        ob_clean();

        // Return success response
        $response['success'] = true;
        $response['message'] = 'Azienda aggiornata con successo';
        $response['data'] = [
            'id' => $companyId,
            'denominazione' => $denominazione,
            'name' => $denominazione,
            'codice_fiscale' => $codiceFiscale,
            'partita_iva' => $partitaIva,
            'status' => $status,
            'plan_type' => $planType
        ];
        echo json_encode($response);
        exit();
    } else {
        ob_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Errore nell\'aggiornamento dell\'azienda', 'success' => false]);
        exit();
    }

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Companies Update PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database', 'success' => false]);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Companies Update Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'success' => false]);
    exit();
}
?>