<?php
/**
 * Navigation Test Page
 * Tests all navigation links and redirects
 */

session_start();

// Function to check if a page exists and is accessible
function checkPage($url) {
    $headers = @get_headers($url);
    if (!$headers) {
        return ['status' => 'error', 'message' => 'Cannot connect'];
    }
    $status = substr($headers[0], 9, 3);
    return [
        'status' => ($status == '200' || $status == '302') ? 'ok' : 'error',
        'code' => $status,
        'header' => $headers[0]
    ];
}

$base_url = "http://localhost:8888/CollaboraNexio/";

// Pages to test
$pages = [
    'index.php' => 'Login Page',
    'dashboard.php' => 'Dashboard',
    'files.php' => 'File Manager',
    'calendar.php' => 'Calendar',
    'tasks.php' => 'Tasks',
    'chat.php' => 'Chat',
    'logout.php' => 'Logout Handler',
    'auth_api.php' => 'Authentication API'
];

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navigation Test - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
        }
        .test-grid {
            display: grid;
            gap: 15px;
        }
        .test-item {
            display: grid;
            grid-template-columns: 200px 150px 1fr auto;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .test-item.success {
            background: #d4edda;
            border-color: #c3e6cb;
        }
        .test-item.error {
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        .test-item.redirect {
            background: #fff3cd;
            border-color: #ffeaa7;
        }
        .page-name {
            font-weight: 600;
            color: #495057;
        }
        .page-description {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .status {
            font-weight: 500;
        }
        .status.ok {
            color: #28a745;
        }
        .status.error {
            color: #dc3545;
        }
        .status.redirect {
            color: #ffc107;
        }
        .link-btn {
            padding: 8px 16px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.875rem;
            transition: background 0.3s;
        }
        .link-btn:hover {
            background: #0056b3;
        }
        .session-info {
            margin-top: 30px;
            padding: 20px;
            background: #e9ecef;
            border-radius: 8px;
        }
        .session-info h3 {
            margin-top: 0;
            color: #495057;
        }
        .session-data {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .action-buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.875rem;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Navigation Test - CollaboraNexio</h1>

        <div class="test-grid">
            <?php foreach ($pages as $page => $description): ?>
                <?php
                $url = $base_url . $page;
                $result = checkPage($url);
                $statusClass = '';
                $statusText = '';

                if ($result['status'] == 'ok') {
                    if ($result['code'] == '200') {
                        $statusClass = 'success';
                        $statusText = 'OK (200)';
                    } else if ($result['code'] == '302') {
                        $statusClass = 'redirect';
                        $statusText = 'Redirect (302)';
                    }
                } else {
                    $statusClass = 'error';
                    $statusText = 'Error (' . $result['code'] . ')';
                }
                ?>
                <div class="test-item <?php echo $statusClass; ?>">
                    <div class="page-name"><?php echo $page; ?></div>
                    <div class="page-description"><?php echo $description; ?></div>
                    <div class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></div>
                    <a href="<?php echo $url; ?>" target="_blank" class="link-btn">Apri →</a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="session-info">
            <h3>Session Information</h3>
            <div class="session-data">
                <strong>Session ID:</strong> <?php echo session_id(); ?><br>
                <strong>Session Status:</strong> <?php echo session_status() == PHP_SESSION_ACTIVE ? 'Active' : 'Inactive'; ?><br>
                <strong>Logged In:</strong> <?php echo isset($_SESSION['user_id']) ? 'Yes (User ID: ' . $_SESSION['user_id'] . ')' : 'No'; ?><br>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <strong>User Name:</strong> <?php echo $_SESSION['user_name'] ?? 'N/A'; ?><br>
                    <strong>User Email:</strong> <?php echo $_SESSION['user_email'] ?? 'N/A'; ?><br>
                    <strong>Tenant ID:</strong> <?php echo $_SESSION['tenant_id'] ?? 'N/A'; ?><br>
                <?php endif; ?>
            </div>
        </div>

        <div class="action-buttons">
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="index.php" class="btn btn-primary">Go to Login</a>
                <a href="index_simple.php" class="btn btn-success">Simple Login</a>
            <?php else: ?>
                <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                <a href="logout.php" class="btn btn-danger">Logout</a>
            <?php endif; ?>
            <a href="test_navigation.php" class="btn btn-success">Refresh Test</a>
        </div>
    </div>
</body>
</html>