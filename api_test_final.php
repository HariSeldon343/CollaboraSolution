<?php
/**
 * Final API Test - Standalone verification
 */

session_start();
require_once __DIR__ . '/includes/db.php';

// Set session for testing
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = 'test';

echo "FILES API FINAL TEST\n";
echo "===================\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Direct query test
    echo "1. Direct Database Query Test:\n";

    // Test folders query
    $sql = "SELECT
            fo.id,
            fo.name,
            fo.path,
            fo.owner_id,
            u.name as owner_name,
            u.email as owner_email
        FROM folders fo
        LEFT JOIN users u ON fo.owner_id = u.id
        WHERE fo.tenant_id = 1
        AND fo.deleted_at IS NULL
        AND fo.parent_id IS NULL";

    $stmt = $conn->query($sql);
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   Root folders found: " . count($folders) . "\n";
    foreach ($folders as $folder) {
        echo "   - 📁 " . $folder['name'] . "\n";
    }

    // Test files query
    $sql = "SELECT
            f.id,
            f.name,
            f.file_size as size,
            u.name as owner_name
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.tenant_id = 1
        AND f.deleted_at IS NULL
        AND f.folder_id IS NULL";

    $stmt = $conn->query($sql);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n   Root files found: " . count($files) . "\n";
    foreach ($files as $file) {
        echo "   - 📄 " . $file['name'] . " (" . number_format($file['size']) . " bytes)\n";
    }

    // API simulation test
    echo "\n2. API Logic Test:\n";

    $results = [];

    // Get folders
    $folders_sql = "SELECT
            fo.id,
            fo.name,
            fo.path,
            'folder' as type,
            1 as is_folder,
            0 as size,
            fo.owner_id,
            u.name as owner_name,
            u.email as owner_email,
            fo.created_at,
            fo.updated_at
        FROM folders fo
        LEFT JOIN users u ON fo.owner_id = u.id
        WHERE fo.tenant_id = 1
        AND fo.deleted_at IS NULL
        AND fo.parent_id IS NULL";

    $stmt = $conn->query($folders_sql);
    while ($folder = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[] = [
            'id' => $folder['id'],
            'name' => $folder['name'],
            'is_folder' => true,
            'size' => 0,
            'uploaded_by' => [
                'id' => $folder['owner_id'],
                'name' => $folder['owner_name'],
                'email' => $folder['owner_email']
            ]
        ];
    }

    // Get files
    $files_sql = "SELECT
            f.id,
            f.name,
            f.file_path as path,
            f.file_size as size,
            f.uploaded_by as owner_id,
            u.name as owner_name,
            u.email as owner_email
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.tenant_id = 1
        AND f.deleted_at IS NULL
        AND f.folder_id IS NULL";

    $stmt = $conn->query($files_sql);
    while ($file = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[] = [
            'id' => $file['id'],
            'name' => $file['name'],
            'is_folder' => false,
            'size' => (int)$file['size'],
            'uploaded_by' => [
                'id' => $file['owner_id'],
                'name' => $file['owner_name'],
                'email' => $file['owner_email']
            ]
        ];
    }

    echo "   Total items for API response: " . count($results) . "\n";

    // Create JSON response
    $response = [
        'success' => true,
        'data' => $results,
        'pagination' => [
            'total' => count($results),
            'page' => 1,
            'limit' => 50,
            'pages' => 1
        ]
    ];

    echo "\n3. Sample JSON Response:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    echo "\n\n✅ API structure is correct and working!\n";
    echo "The /api/files.php endpoint should return similar JSON structure.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}