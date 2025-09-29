<?php
// Test script for company API endpoints
session_start();
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    die("Not authenticated. Please login first.");
}

$currentUser = $auth->getCurrentUser();
$csrfToken = $_SESSION['csrf_token'] ?? '';
$userRole = $currentUser['role'] ?? 'user';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Test Company API</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; }
        .response { background: #f5f5f5; padding: 10px; margin-top: 10px; white-space: pre-wrap; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
        .success { color: green; }
        .error { color: red; }
        .info { background: #e3f2fd; padding: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>Test Company API Endpoints</h1>

    <div class="info">
        <strong>Current User:</strong> <?php echo htmlspecialchars($currentUser['name']); ?><br>
        <strong>Role:</strong> <?php echo htmlspecialchars($userRole); ?><br>
        <strong>CSRF Token:</strong> <?php echo substr($csrfToken, 0, 20); ?>...<br>
        <strong>Is Super Admin:</strong> <?php echo $userRole === 'super_admin' ? 'Yes' : 'No'; ?>
    </div>

    <?php if ($userRole !== 'super_admin'): ?>
        <div class="error">
            <strong>Note:</strong> You need to be a Super Admin to test these APIs. Current role: <?php echo htmlspecialchars($userRole); ?>
        </div>
    <?php endif; ?>

    <div class="test-section">
        <h2>1. List Companies</h2>
        <button onclick="testList()">Test LIST API</button>
        <div id="list-response" class="response"></div>
    </div>

    <div class="test-section">
        <h2>2. Create Company</h2>
        <button onclick="testCreate()">Test CREATE API</button>
        <div id="create-response" class="response"></div>
    </div>

    <div class="test-section">
        <h2>3. Update Company</h2>
        <input type="number" id="update-id" placeholder="Company ID" value="2">
        <button onclick="testUpdate()">Test UPDATE API</button>
        <div id="update-response" class="response"></div>
    </div>

    <div class="test-section">
        <h2>4. Delete Company</h2>
        <input type="number" id="delete-id" placeholder="Company ID" value="3">
        <button onclick="testDelete()">Test DELETE API</button>
        <div id="delete-response" class="response"></div>
    </div>

    <script>
        const csrfToken = '<?php echo $csrfToken; ?>';

        async function testList() {
            const responseDiv = document.getElementById('list-response');
            responseDiv.textContent = 'Loading...';

            try {
                const response = await fetch('/CollaboraNexio/api/companies/list.php?page=1', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const data = await response.json();
                responseDiv.textContent = `Status: ${response.status}\n${JSON.stringify(data, null, 2)}`;
                responseDiv.className = response.ok ? 'response success' : 'response error';
            } catch (error) {
                responseDiv.textContent = `Error: ${error.message}`;
                responseDiv.className = 'response error';
            }
        }

        async function testCreate() {
            const responseDiv = document.getElementById('create-response');
            responseDiv.textContent = 'Loading...';

            const testCompany = {
                denominazione: 'Test Company ' + Date.now(),
                codice_fiscale: 'TSTCMP00A01H501Z',
                partita_iva: '12345678901',
                sede_legale: 'Via Test 123, 00100 Roma (RM)',
                sede_operativa: 'Via Operativa 456, 00100 Roma (RM)',
                settore_merceologico: 'informatica',
                numero_dipendenti: 10,
                telefono: '+39 06 1234567',
                email_aziendale: 'test@company.com',
                pec: 'test@pec.company.com',
                data_costituzione: '2024-01-01',
                capitale_sociale: 10000,
                manager_user_id: 1,
                rappresentante_legale: 'Mario Rossi',
                plan_type: 'starter',
                status: 'active',
                csrf_token: csrfToken
            };

            try {
                const formData = new FormData();
                for (const key in testCompany) {
                    formData.append(key, testCompany[key]);
                }

                const response = await fetch('/CollaboraNexio/api/companies/create.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                responseDiv.textContent = `Status: ${response.status}\n${JSON.stringify(data, null, 2)}`;
                responseDiv.className = response.ok ? 'response success' : 'response error';
            } catch (error) {
                responseDiv.textContent = `Error: ${error.message}`;
                responseDiv.className = 'response error';
            }
        }

        async function testUpdate() {
            const responseDiv = document.getElementById('update-response');
            const companyId = document.getElementById('update-id').value;

            if (!companyId) {
                responseDiv.textContent = 'Please enter a company ID';
                responseDiv.className = 'response error';
                return;
            }

            responseDiv.textContent = 'Loading...';

            const updateData = {
                company_id: companyId,
                denominazione: 'Updated Company ' + Date.now(),
                codice_fiscale: 'UPDCMP00A01H501Z',
                partita_iva: '98765432101',
                sede_legale: 'Via Updated 789, 00100 Roma (RM)',
                sede_operativa: 'Via New Operativa 999, 00100 Roma (RM)',
                settore_merceologico: 'consulenza',
                numero_dipendenti: 25,
                telefono: '+39 06 9876543',
                email_aziendale: 'updated@company.com',
                pec: 'updated@pec.company.com',
                data_costituzione: '2024-01-01',
                capitale_sociale: 50000,
                manager_user_id: 1,
                rappresentante_legale: 'Luigi Verdi',
                plan_type: 'professional',
                status: 'active',
                csrf_token: csrfToken
            };

            try {
                const formData = new FormData();
                for (const key in updateData) {
                    formData.append(key, updateData[key]);
                }

                const response = await fetch('/CollaboraNexio/api/companies/update.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                responseDiv.textContent = `Status: ${response.status}\n${JSON.stringify(data, null, 2)}`;
                responseDiv.className = response.ok ? 'response success' : 'response error';
            } catch (error) {
                responseDiv.textContent = `Error: ${error.message}`;
                responseDiv.className = 'response error';
            }
        }

        async function testDelete() {
            const responseDiv = document.getElementById('delete-response');
            const companyId = document.getElementById('delete-id').value;

            if (!companyId) {
                responseDiv.textContent = 'Please enter a company ID';
                responseDiv.className = 'response error';
                return;
            }

            if (!confirm('Are you sure you want to delete company ID ' + companyId + '?')) {
                return;
            }

            responseDiv.textContent = 'Loading...';

            try {
                const formData = new FormData();
                formData.append('company_id', companyId);
                formData.append('csrf_token', csrfToken);

                const response = await fetch('/CollaboraNexio/api/companies/delete.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                responseDiv.textContent = `Status: ${response.status}\n${JSON.stringify(data, null, 2)}`;
                responseDiv.className = response.ok ? 'response success' : 'response error';
            } catch (error) {
                responseDiv.textContent = `Error: ${error.message}`;
                responseDiv.className = 'response error';
            }
        }
    </script>
</body>
</html>