<?php
/**
 * Password Hash Generator
 * Use this to generate password hashes for SQL INSERT statements
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hash Generator</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }
        button {
            background: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #0056b3;
        }
        .result {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-top: 20px;
            border-left: 4px solid #28a745;
        }
        .result h3 {
            margin-top: 0;
            color: #28a745;
        }
        .hash-output {
            background: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            word-break: break-all;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .sql-output {
            background: #282c34;
            color: #61dafb;
            padding: 15px;
            border-radius: 4px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
        }
        .copy-btn {
            background: #28a745;
            font-size: 14px;
            padding: 8px 15px;
            margin-top: 10px;
        }
        .copy-btn:hover {
            background: #218838;
        }
        .info {
            background: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #007bff;
            margin-bottom: 20px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Password Hash Generator</h1>

        <div class="info">
            <strong>Purpose:</strong> Generate secure password hashes for CollaboraNexio users.
            Use the generated hash in SQL INSERT or UPDATE statements.
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="email">Email Address:</label>
                <input type="text" id="email" name="email" placeholder="admin@test.com" required>
            </div>

            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required>
            </div>

            <div class="form-group">
                <label for="name">Full Name:</label>
                <input type="text" id="name" name="name" placeholder="Admin User" required>
            </div>

            <div class="form-group">
                <label for="role">Role:</label>
                <select id="role" name="role" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="super_admin">Super Admin (full access, no tenant required)</option>
                    <option value="admin">Admin (requires tenant)</option>
                    <option value="manager">Manager (requires tenant)</option>
                    <option value="user">User (requires tenant)</option>
                </select>
            </div>

            <button type="submit">Generate Hash & SQL</button>
        </form>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $name = $_POST['name'] ?? '';
            $role = $_POST['role'] ?? 'user';

            if (!empty($email) && !empty($password) && !empty($name)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                echo '<div class="result">';
                echo '<h3>Generated Password Hash</h3>';
                echo '<div class="hash-output">' . htmlspecialchars($hash) . '</div>';
                echo '<button class="copy-btn" onclick="copyToClipboard(\'' . htmlspecialchars($hash) . '\')">Copy Hash</button>';

                echo '<h3 style="margin-top: 20px;">SQL INSERT Statement</h3>';
                echo '<div class="sql-output">';
                echo "INSERT INTO users (email, password_hash, name, role, is_active, created_at)\n";
                echo "VALUES (\n";
                echo "  '" . htmlspecialchars($email) . "',\n";
                echo "  '" . htmlspecialchars($hash) . "',\n";
                echo "  '" . htmlspecialchars($name) . "',\n";
                echo "  '" . htmlspecialchars($role) . "',\n";
                echo "  1,\n";
                echo "  NOW()\n";
                echo ");";
                echo '</div>';
                echo '<button class="copy-btn" onclick="copySQL()">Copy SQL</button>';

                echo '<h3 style="margin-top: 20px;">Login Credentials</h3>';
                echo '<div style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">';
                echo '<strong>Email:</strong> ' . htmlspecialchars($email) . '<br>';
                echo '<strong>Password:</strong> ' . htmlspecialchars($password) . '<br>';
                echo '<strong>Role:</strong> ' . htmlspecialchars($role) . '<br>';
                echo '</div>';

                echo '</div>';
            }
        }
        ?>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Hash copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }

        function copySQL() {
            const sqlDiv = document.querySelector('.sql-output');
            const text = sqlDiv.innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('SQL copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }
    </script>
</body>
</html>
