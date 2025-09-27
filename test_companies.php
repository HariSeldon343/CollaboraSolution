<?php
session_start();

// Set super_admin role for testing
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'super_admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Companies API - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table th, table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        table th {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏢 Test Companies Management API</h1>

        <div class="info">
            <strong>Session Status:</strong><br>
            User ID: <?php echo $_SESSION['user_id'] ?? 'Not set'; ?><br>
            Role: <?php echo $_SESSION['user_role'] ?? 'Not set'; ?><br>
            CSRF Token: <?php echo isset($_SESSION['csrf_token']) ? '✅ Present' : '❌ Missing'; ?>
        </div>

        <div style="margin: 20px 0;">
            <h3>Test Operations:</h3>
            <button onclick="testList()">📋 List Companies</button>
            <button class="btn-success" onclick="testCreate()">➕ Create Test Company</button>
            <button onclick="testUpdate()">✏️ Update Company</button>
            <button class="btn-danger" onclick="testDelete()">🗑️ Delete Company</button>
            <br><br>
            <a href="aziende.php" target="_blank">
                <button style="background: #6c757d;">Open Companies Page →</button>
            </a>
        </div>

        <div id="companies-list"></div>
        <div id="result"></div>
    </div>

    <script>
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

        async function testList() {
            const resultDiv = document.getElementById('result');
            const listDiv = document.getElementById('companies-list');

            resultDiv.innerHTML = '<div class="info">Loading companies...</div>';

            try {
                const response = await fetch('api/companies/list.php?page=1&search=', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const data = await response.json();

                if (data.success && data.data && data.data.companies) {
                    let html = '<h3>Companies List:</h3><table><thead><tr>';
                    html += '<th>ID</th><th>Name</th><th>Code</th><th>Plan</th><th>Status</th>';
                    html += '</tr></thead><tbody>';

                    data.data.companies.forEach(company => {
                        html += `<tr>
                            <td>${company.id}</td>
                            <td>${company.name}</td>
                            <td>${company.code || 'N/A'}</td>
                            <td>${company.plan_type || 'basic'}</td>
                            <td>${company.is_active ? '✅ Active' : '❌ Inactive'}</td>
                        </tr>`;
                    });

                    html += '</tbody></table>';
                    listDiv.innerHTML = html;

                    resultDiv.innerHTML = `
                        <div class="success">
                            ✅ List loaded successfully!<br>
                            Found ${data.data.companies.length} companies
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Failed to load companies<br>
                            ${data.error || 'Unknown error'}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Network error: ' + error.message + '</div>';
            }
        }

        async function testCreate() {
            const resultDiv = document.getElementById('result');

            const testCompany = {
                name: 'Test Company ' + Date.now(),
                code: 'TEST' + Math.floor(Math.random() * 1000),
                plan_type: 'premium',
                max_users: 50,
                is_active: true
            };

            resultDiv.innerHTML = '<div class="info">Creating test company...</div>';

            try {
                const response = await fetch('api/companies/create.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(testCompany)
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="success">
                            ✅ Company created successfully!<br>
                            ID: ${data.company_id}<br>
                            Name: ${testCompany.name}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                    // Reload list
                    testList();
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Failed to create company<br>
                            ${data.error}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Network error: ' + error.message + '</div>';
            }
        }

        async function testUpdate() {
            const resultDiv = document.getElementById('result');
            const companyId = prompt('Enter company ID to update:');

            if (!companyId) return;

            const updates = {
                id: companyId,
                name: 'Updated Company ' + Date.now(),
                plan_type: 'enterprise',
                max_users: 100
            };

            resultDiv.innerHTML = '<div class="info">Updating company...</div>';

            try {
                const response = await fetch('api/companies/update.php', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(updates)
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="success">
                            ✅ Company updated successfully!<br>
                            ${data.message}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                    // Reload list
                    testList();
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Failed to update company<br>
                            ${data.error}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Network error: ' + error.message + '</div>';
            }
        }

        async function testDelete() {
            const resultDiv = document.getElementById('result');
            const companyId = prompt('Enter company ID to delete (WARNING: Cannot delete company with users):');

            if (!companyId) return;

            if (!confirm('Are you sure you want to delete this company?')) return;

            resultDiv.innerHTML = '<div class="info">Deleting company...</div>';

            try {
                const response = await fetch('api/companies/delete.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ id: companyId })
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="success">
                            ✅ Company deleted successfully!<br>
                            ${data.message}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                    // Reload list
                    testList();
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Failed to delete company<br>
                            ${data.error}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Network error: ' + error.message + '</div>';
            }
        }

        // Load companies on page load
        window.onload = function() {
            testList();
        }
    </script>
</body>
</html>