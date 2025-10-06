<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Test Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 400px;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #666;
        }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background: #5a67d8;
        }
        .info {
            background: #f7f7f7;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .error {
            color: red;
            margin-bottom: 10px;
        }
        .success {
            color: green;
            margin-bottom: 10px;
        }
        .links {
            margin-top: 20px;
            text-align: center;
        }
        .links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>🚀 CollaboraNexio</h1>

        <div id="message"></div>

        <form id="loginForm" action="auth_api.php" method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="admin@demo.com" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" value="Admin123!" required>
            </div>

            <button type="submit">Accedi</button>
        </form>

        <div class="info">
            <strong>Demo Credentials:</strong><br>
            Email: admin@demo.com<br>
            Password: Admin123!
        </div>

        <div class="links">
            <a href="test_db.php">Test Database</a> |
            <a href="test_8888.php">PHP Info</a> |
            <a href="dashboard_direct.php">Dashboard Direct</a> |
            <a href="dashboard.php">Dashboard Main</a>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = {
                email: document.getElementById('email').value,
                password: document.getElementById('password').value
            };

            try {
                const response = await fetch('auth_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();
                const messageDiv = document.getElementById('message');

                if (data.success) {
                    messageDiv.innerHTML = '<p class="success">Login successful! Redirecting...</p>';
                    setTimeout(() => {
                        window.location.href = data.redirect || 'dashboard.php';
                    }, 1000);
                } else {
                    messageDiv.innerHTML = '<p class="error">Error: ' + (data.message || 'Login failed') + '</p>';
                }
            } catch (error) {
                document.getElementById('message').innerHTML = '<p class="error">Connection error: ' + error.message + '</p>';
            }
        });
    </script>
</body>
</html>