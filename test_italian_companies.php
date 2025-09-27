<?php
session_start();

// Set super_admin for testing
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'super_admin';
$_SESSION['tenant_id'] = 1;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Italian Companies System - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1400px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #28a745;
        }
        .error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #dc3545;
        }
        .info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #17a2b8;
        }
        .warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
            color: #856404;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #ffc107;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            cursor: pointer;
            margin: 5px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            transition: all 0.3s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(102, 126, 234, 0.5);
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.4);
        }
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        table tr:hover {
            background: #f8f9fa;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            border: 1px solid #dee2e6;
        }
        .test-section {
            margin: 30px 0;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .test-title {
            font-size: 18px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🇮🇹 Sistema Gestione Aziende Italiane</h1>
        <p class="subtitle">Test completo del nuovo sistema con campi business italiani</p>

        <div class="info">
            <strong>📊 Sessione di Test:</strong><br>
            User ID: <?php echo $_SESSION['user_id'] ?? 'Not set'; ?><br>
            Ruolo: <?php echo $_SESSION['user_role'] ?? 'Not set'; ?><br>
            Tenant ID: <?php echo $_SESSION['tenant_id'] ?? 'Not set'; ?><br>
            CSRF Token: <?php echo isset($_SESSION['csrf_token']) ? '✅ Presente' : '❌ Mancante'; ?>
        </div>

        <!-- Test Operations -->
        <div class="test-section">
            <h3 class="test-title">🧪 Operazioni di Test</h3>
            <div style="text-align: center;">
                <button onclick="testList()">📋 Lista Aziende</button>
                <button class="btn-success" onclick="testCreateItalianCompany()">➕ Crea Azienda Italiana</button>
                <button class="btn-warning" onclick="testUpdate()">✏️ Modifica Azienda</button>
                <button class="btn-danger" onclick="testDelete()">🗑️ Elimina Azienda</button>
                <button onclick="loadManagers()">👥 Carica Manager</button>
                <br><br>
                <a href="aziende.php" target="_blank">
                    <button style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
                        Apri Gestione Aziende →
                    </button>
                </a>
            </div>
        </div>

        <div id="stats" class="grid"></div>
        <div id="companies-list"></div>
        <div id="result"></div>
    </div>

    <script>
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';

        async function testList() {
            const resultDiv = document.getElementById('result');
            const listDiv = document.getElementById('companies-list');

            resultDiv.innerHTML = '<div class="info">Caricamento aziende in corso...</div>';

            try {
                const response = await fetch('api/companies/list.php?page=1&search=', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const data = await response.json();
                console.log('Response:', data);

                if (data.success && data.data && data.data.companies) {
                    const companies = data.data.companies;

                    // Statistics
                    let statsHtml = '';
                    const totalCompanies = companies.length;
                    const activeCompanies = companies.filter(c => c.status === 'active').length;
                    const totalEmployees = companies.reduce((sum, c) => sum + (c.numero_dipendenti || 0), 0);
                    const sectors = [...new Set(companies.map(c => c.settore_merceologico).filter(Boolean))].length;

                    document.getElementById('stats').innerHTML = `
                        <div class="stat-card">
                            <div class="stat-number">${totalCompanies}</div>
                            <div class="stat-label">Aziende Totali</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${activeCompanies}</div>
                            <div class="stat-label">Aziende Attive</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${totalEmployees}</div>
                            <div class="stat-label">Dipendenti Totali</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${sectors}</div>
                            <div class="stat-label">Settori</div>
                        </div>
                    `;

                    // Company table
                    let html = '<h3 style="margin-top: 30px;">📋 Elenco Aziende:</h3><table><thead><tr>';
                    html += '<th>ID</th><th>Denominazione</th><th>Codice Fiscale</th><th>Partita IVA</th>';
                    html += '<th>Settore</th><th>Dipendenti</th><th>Manager</th><th>Stato</th>';
                    html += '</tr></thead><tbody>';

                    companies.forEach(company => {
                        const statusBadge = company.status === 'active' ?
                            '<span class="badge badge-success">Attivo</span>' :
                            company.status === 'suspended' ?
                            '<span class="badge badge-danger">Sospeso</span>' :
                            '<span class="badge badge-info">In attesa</span>';

                        html += `<tr>
                            <td><strong>${company.id}</strong></td>
                            <td>
                                <strong>${company.denominazione || company.name || 'N/A'}</strong><br>
                                <small style="color: #6c757d;">${company.sede_legale || ''}</small>
                            </td>
                            <td><code style="font-size: 11px;">${company.codice_fiscale || 'N/A'}</code></td>
                            <td><code>${company.partita_iva || 'N/A'}</code></td>
                            <td>${company.settore_merceologico || '-'}</td>
                            <td style="text-align: center;"><strong>${company.numero_dipendenti || 0}</strong></td>
                            <td>${company.manager_name || '-'}</td>
                            <td>${statusBadge}</td>
                        </tr>`;
                    });

                    html += '</tbody></table>';
                    listDiv.innerHTML = html;

                    resultDiv.innerHTML = `
                        <div class="success">
                            ✅ Lista caricata con successo!<br>
                            Trovate ${companies.length} aziende
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Errore nel caricamento aziende<br>
                            ${data.error || 'Errore sconosciuto'}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Errore di rete: ' + error.message + '</div>';
            }
        }

        async function testCreateItalianCompany() {
            const resultDiv = document.getElementById('result');

            const testCompany = {
                denominazione: 'TechSolutions Italia S.r.l.',
                codice_fiscale: '12345678901ABCDE',
                partita_iva: '12345678901',
                sede_legale: 'Via Roma 123, 20121 Milano (MI)',
                sede_operativa: 'Via Torino 45, 20123 Milano (MI)',
                settore_merceologico: 'informatica',
                numero_dipendenti: 25,
                telefono: '+39 02 12345678',
                email_aziendale: 'info@techsolutions.it',
                pec: 'techsolutions@pec.it',
                data_costituzione: '2020-01-15',
                capitale_sociale: 50000.00,
                manager_user_id: 1,
                rappresentante_legale: 'Mario Rossi',
                plan_type: 'professional',
                status: 'active'
            };

            resultDiv.innerHTML = '<div class="info">Creazione azienda italiana di test...</div>';

            try {
                const response = await fetch('api/companies/create.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(testCompany)
                });

                const responseText = await response.text();
                console.log('Raw response:', responseText);

                try {
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        resultDiv.innerHTML = `
                            <div class="success">
                                ✅ Azienda italiana creata con successo!<br>
                                <strong>ID:</strong> ${data.data?.id || 'N/A'}<br>
                                <strong>Denominazione:</strong> ${testCompany.denominazione}<br>
                                <strong>Codice Fiscale:</strong> ${testCompany.codice_fiscale}<br>
                                <strong>Partita IVA:</strong> ${testCompany.partita_iva}
                            </div>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        `;
                        // Ricarica lista
                        testList();
                    } else {
                        resultDiv.innerHTML = `
                            <div class="error">
                                ❌ Errore nella creazione<br>
                                ${data.message || data.error || 'Errore sconosciuto'}
                            </div>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        `;
                    }
                } catch (e) {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Errore parsing JSON!<br>
                            ${e.message}
                        </div>
                        <pre>${responseText}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Errore di rete: ' + error.message + '</div>';
            }
        }

        async function testUpdate() {
            const resultDiv = document.getElementById('result');
            const companyId = prompt('Inserisci l\'ID dell\'azienda da modificare:');

            if (!companyId) return;

            const updates = {
                id: companyId,
                denominazione: 'Azienda Aggiornata ' + Date.now(),
                codice_fiscale: '98765432109ZYXWV',
                partita_iva: '98765432109',
                sede_legale: 'Via Nuova 999, 00100 Roma (RM)',
                settore_merceologico: 'consulenza',
                numero_dipendenti: 100,
                telefono: '+39 06 98765432',
                email_aziendale: 'updated@azienda.it',
                pec: 'updated@pec.it',
                data_costituzione: '2018-06-01',
                capitale_sociale: 100000,
                manager_user_id: 1,
                rappresentante_legale: 'Luigi Bianchi',
                plan_type: 'enterprise',
                status: 'active'
            };

            resultDiv.innerHTML = '<div class="info">Aggiornamento azienda...</div>';

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
                            ✅ Azienda aggiornata con successo!<br>
                            ${data.message}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                    testList();
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Errore nell'aggiornamento<br>
                            ${data.error || data.message}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Errore di rete: ' + error.message + '</div>';
            }
        }

        async function testDelete() {
            const resultDiv = document.getElementById('result');
            const companyId = prompt('Inserisci l\'ID dell\'azienda da eliminare (ATTENZIONE: non può essere eliminata se ha utenti):');

            if (!companyId) return;

            if (!confirm('Sei sicuro di voler eliminare questa azienda?')) return;

            resultDiv.innerHTML = '<div class="info">Eliminazione azienda...</div>';

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
                            ✅ Azienda eliminata con successo!<br>
                            ${data.message}
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                    testList();
                } else {
                    resultDiv.innerHTML = `
                        <div class="warning">
                            ⚠️ Eliminazione non riuscita<br>
                            ${data.error || data.message}<br>
                            <small>Nota: Non è possibile eliminare aziende con utenti associati</small>
                        </div>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Errore di rete: ' + error.message + '</div>';
            }
        }

        async function loadManagers() {
            const resultDiv = document.getElementById('result');
            resultDiv.innerHTML = '<div class="info">Caricamento manager disponibili...</div>';

            try {
                const response = await fetch('api/users/list.php?role=manager,admin', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const data = await response.json();

                if (data.success && data.data) {
                    const managers = data.data.users || [];
                    let html = '<div class="info"><h3>Manager Disponibili:</h3><ul>';
                    managers.forEach(manager => {
                        html += `<li>ID: ${manager.id} - ${manager.name} (${manager.email}) - Ruolo: ${manager.role}</li>`;
                    });
                    html += '</ul></div>';
                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = '<div class="error">Errore nel caricamento manager</div>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Errore: ' + error.message + '</div>';
            }
        }

        // Carica aziende all'avvio
        window.onload = function() {
            testList();
        }
    </script>
</body>
</html>