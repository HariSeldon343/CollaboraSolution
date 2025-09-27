<?php
session_start();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Test - CollaboraNexio</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
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
        h1 {
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .status {
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .logged-in {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .not-logged-in {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .button-group {
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin-right: 10px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #5a67d8;
        }
        .btn-danger {
            background: #ef4444;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Session Test</h1>

        <?php if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in'])): ?>
            <div class="status logged-in">
                ✅ <strong>Sei loggato!</strong>
            </div>

            <h2>Informazioni Sessione:</h2>
            <table>
                <tr>
                    <th>Chiave</th>
                    <th>Valore</th>
                </tr>
                <?php foreach ($_SESSION as $key => $value): ?>
                <tr>
                    <td><?php echo htmlspecialchars($key); ?></td>
                    <td><?php echo htmlspecialchars(is_array($value) ? json_encode($value) : (string)$value); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>

            <div class="button-group">
                <a href="dashboard_direct.php" class="btn">Vai alla Dashboard</a>
                <a href="logout.php" class="btn btn-danger">Logout</a>
            </div>

        <?php else: ?>
            <div class="status not-logged-in">
                ❌ <strong>Non sei loggato!</strong>
            </div>

            <p>La sessione è vuota o non contiene dati di login.</p>

            <h3>Contenuto $_SESSION attuale:</h3>
            <pre><?php print_r($_SESSION); ?></pre>

            <div class="button-group">
                <a href="index_simple.php" class="btn">Vai al Login</a>
            </div>
        <?php endif; ?>

        <h2>Informazioni Tecniche:</h2>
        <table>
            <tr>
                <td><strong>Session ID:</strong></td>
                <td><?php echo session_id(); ?></td>
            </tr>
            <tr>
                <td><strong>Session Name:</strong></td>
                <td><?php echo session_name(); ?></td>
            </tr>
            <tr>
                <td><strong>Session Status:</strong></td>
                <td><?php
                    $status = session_status();
                    echo $status == PHP_SESSION_DISABLED ? 'DISABLED' :
                         ($status == PHP_SESSION_NONE ? 'NONE' : 'ACTIVE');
                ?></td>
            </tr>
            <tr>
                <td><strong>Cookie Params:</strong></td>
                <td><pre><?php print_r(session_get_cookie_params()); ?></pre></td>
            </tr>
        </table>

        <h2>Test Login API:</h2>
        <button onclick="testLogin()" class="btn">Test Login via API</button>
        <div id="api-result"></div>

        <script>
            async function testLogin() {
                try {
                    const response = await fetch('auth_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            email: 'admin@demo.com',
                            password: 'Admin123!'
                        })
                    });

                    const data = await response.json();
                    document.getElementById('api-result').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

                    if (data.success) {
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    }
                } catch (error) {
                    document.getElementById('api-result').innerHTML = '<pre>Error: ' + error.message + '</pre>';
                }
            }
        </script>
    </div>
</body>
</html>