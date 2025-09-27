<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Delete User - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #c82333;
        }
        input {
            padding: 8px;
            margin: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ Test Delete User API</h1>

        <div class="info">
            <strong>Your Session:</strong><br>
            User ID: <?php echo $_SESSION['user_id'] ?? 'Not set'; ?><br>
            Role: <?php echo $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Not set'; ?><br>
            CSRF Token: <?php echo isset($_SESSION['csrf_token']) ? '✅ Present' : '❌ Missing'; ?>
        </div>

        <div style="margin: 20px 0;">
            <h3>Test Delete User:</h3>
            <input type="number" id="userId" placeholder="User ID to delete" value="2">
            <button onclick="testDelete()">Test Delete</button>
            <button style="background: #28a745;" onclick="loadUsers()">Load Users List</button>
        </div>

        <div id="users-list"></div>
        <div id="result"></div>
    </div>

    <script>
        const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';

        async function loadUsers() {
            const listDiv = document.getElementById('users-list');
            listDiv.innerHTML = '<div class="info">Loading users...</div>';

            try {
                const response = await fetch('api/users/list.php?page=1&search=', {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });

                const data = await response.json();

                if (data.success && data.data && data.data.users) {
                    let html = '<div class="info"><h3>Available Users:</h3><ul>';
                    data.data.users.forEach(user => {
                        html += `<li>ID: ${user.id} - ${user.name} (${user.email}) - Role: ${user.role}</li>`;
                    });
                    html += '</ul></div>';
                    listDiv.innerHTML = html;
                } else {
                    listDiv.innerHTML = '<div class="error">Failed to load users</div>';
                }
            } catch (error) {
                listDiv.innerHTML = '<div class="error">Error: ' + error.message + '</div>';
            }
        }

        async function testDelete() {
            const userId = document.getElementById('userId').value;
            const resultDiv = document.getElementById('result');

            if (!userId) {
                resultDiv.innerHTML = '<div class="error">Please enter a user ID</div>';
                return;
            }

            resultDiv.innerHTML = '<div class="info">Testing delete for user ID: ' + userId + '...</div>';

            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('api/users/delete.php', {
                    method: 'POST',
                    body: formData
                });

                const responseText = await response.text();
                console.log('Response:', responseText);

                try {
                    const data = JSON.parse(responseText);

                    if (data.success) {
                        resultDiv.innerHTML = `
                            <div class="success">
                                ✅ Delete successful!<br>
                                ${data.message}<br>
                                Deleted: ${data.deleted_user.name} (${data.deleted_user.email})
                            </div>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        `;
                        // Reload users list
                        loadUsers();
                    } else {
                        resultDiv.innerHTML = `
                            <div class="error">
                                ❌ Delete failed!<br>
                                ${data.error}
                            </div>
                            <pre>${JSON.stringify(data, null, 2)}</pre>
                        `;
                    }
                } catch (e) {
                    resultDiv.innerHTML = `
                        <div class="error">
                            ❌ Invalid JSON response!<br>
                            This usually means PHP error.<br>
                            Response Status: ${response.status}
                        </div>
                        <pre>${responseText}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">Network error: ' + error.message + '</div>';
            }
        }

        // Load users on page load
        window.onload = function() {
            loadUsers();
        }
    </script>
</body>
</html>