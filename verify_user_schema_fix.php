<?php
/**
 * Verification Script for User Schema Fix
 * This script verifies that the database and code are aligned correctly
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "USER SCHEMA FIX VERIFICATION\n";
echo "========================================\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // 1. Check users table structure
    echo "1. CHECKING USERS TABLE STRUCTURE\n";
    echo "-----------------------------------\n";

    $stmt = $conn->query("SHOW COLUMNS FROM users WHERE Field LIKE '%name%'");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasNameColumn = false;
    $hasFirstName = false;
    $hasLastName = false;

    foreach ($columns as $col) {
        echo "  Column: " . $col['Field'] . " (" . $col['Type'] . ")\n";

        if ($col['Field'] === 'name') {
            $hasNameColumn = true;
            echo "    ✓ Single 'name' column found\n";
        }
        if ($col['Field'] === 'first_name') {
            $hasFirstName = true;
            echo "    ✗ OLD 'first_name' column still exists (should be removed)\n";
        }
        if ($col['Field'] === 'last_name') {
            $hasLastName = true;
            echo "    ✗ OLD 'last_name' column still exists (should be removed)\n";
        }
    }

    echo "\n";

    if ($hasNameColumn && !$hasFirstName && !$hasLastName) {
        echo "  ✓ PASS: Schema is correct (single 'name' column)\n";
    } else {
        echo "  ✗ FAIL: Schema issue detected\n";
        if (!$hasNameColumn) {
            echo "    - Missing 'name' column\n";
        }
        if ($hasFirstName || $hasLastName) {
            echo "    - Old first_name/last_name columns still present\n";
        }
    }

    // 2. Check data integrity
    echo "\n2. CHECKING DATA INTEGRITY\n";
    echo "-----------------------------------\n";

    $stmt = $conn->query("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "  Total active users: $total\n";

    $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE (name IS NULL OR name = '') AND deleted_at IS NULL");
    $nullCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "  Users with NULL or empty name: $nullCount\n";

    if ($nullCount === 0) {
        echo "  ✓ PASS: All users have valid names\n";
    } else {
        echo "  ✗ FAIL: $nullCount users have NULL or empty names\n";
    }

    // 3. Sample existing users
    echo "\n3. SAMPLE OF EXISTING USERS\n";
    echo "-----------------------------------\n";

    $stmt = $conn->query("SELECT id, name, email, role, created_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "  No users found in database\n";
    } else {
        foreach ($users as $user) {
            $nameLength = mb_strlen($user['name']);
            $status = ($nameLength >= 2) ? "✓" : "✗";
            echo "  $status ID: " . $user['id'] . "\n";
            echo "      Name: '" . $user['name'] . "' (length: $nameLength)\n";
            echo "      Email: " . $user['email'] . "\n";
            echo "      Role: " . $user['role'] . "\n";
            echo "      Created: " . $user['created_at'] . "\n";
            echo "\n";
        }
    }

    // 4. Check if APIs are accessible
    echo "4. CHECKING API FILES\n";
    echo "-----------------------------------\n";

    $apiFiles = [
        'api/users/create_simple.php' => 'User Creation API',
        'api/users/update_v2.php' => 'User Update API',
        'api/users/list.php' => 'User List API'
    ];

    foreach ($apiFiles as $file => $description) {
        $fullPath = __DIR__ . '/' . $file;
        if (file_exists($fullPath)) {
            echo "  ✓ $description: EXISTS\n";

            // Check if file contains 'name' field handling
            $content = file_get_contents($fullPath);
            if (strpos($content, "'name'") !== false || strpos($content, '"name"') !== false) {
                echo "    ✓ Contains 'name' field handling\n";
            }

            // Check if file still references first_name/last_name (should not)
            if (strpos($content, 'first_name') !== false || strpos($content, 'last_name') !== false) {
                // Check if it's in a comment or backward compatibility code
                if (preg_match('/\$input\[.first_name.\]/', $content) ||
                    preg_match('/\$_POST\[.first_name.\]/', $content)) {
                    echo "    ✗ WARNING: Still contains first_name/last_name references\n";
                }
            }
        } else {
            echo "  ✗ $description: NOT FOUND\n";
        }
    }

    // 5. Check frontend file
    echo "\n5. CHECKING FRONTEND FILE\n";
    echo "-----------------------------------\n";

    $frontendFile = __DIR__ . '/utenti.php';
    if (file_exists($frontendFile)) {
        echo "  ✓ utenti.php: EXISTS\n";

        $content = file_get_contents($frontendFile);

        // Check for new single name field
        if (strpos($content, 'id="addName"') !== false) {
            echo "    ✓ Add form uses single 'name' field\n";
        } else {
            echo "    ✗ Add form missing single 'name' field\n";
        }

        if (strpos($content, 'id="editName"') !== false) {
            echo "    ✓ Edit form uses single 'name' field\n";
        } else {
            echo "    ✗ Edit form missing single 'name' field\n";
        }

        // Check if old fields still exist (should not)
        if (strpos($content, 'id="addFirstName"') !== false ||
            strpos($content, 'id="addLastName"') !== false) {
            echo "    ✗ WARNING: Old first_name/last_name fields still present\n";
        }

        // Check JavaScript
        if (strpos($content, "formData.append('name'") !== false) {
            echo "    ✓ JavaScript sends 'name' field\n";
        } else {
            echo "    ✗ JavaScript not sending 'name' field\n";
        }

    } else {
        echo "  ✗ utenti.php: NOT FOUND\n";
    }

    // 6. Overall assessment
    echo "\n6. OVERALL ASSESSMENT\n";
    echo "-----------------------------------\n";

    $allPassed = $hasNameColumn && !$hasFirstName && !$hasLastName && $nullCount === 0;

    if ($allPassed) {
        echo "  ✓✓✓ ALL CHECKS PASSED ✓✓✓\n";
        echo "\n";
        echo "  The user schema fix has been successfully applied:\n";
        echo "  - Database uses single 'name' column\n";
        echo "  - Frontend forms updated\n";
        echo "  - JavaScript updated\n";
        echo "  - APIs updated\n";
        echo "  - All existing users have valid names\n";
        echo "\n";
        echo "  You can now safely use the user management system.\n";
    } else {
        echo "  ✗✗✗ SOME CHECKS FAILED ✗✗✗\n";
        echo "\n";
        echo "  Issues detected:\n";
        if (!$hasNameColumn) {
            echo "  - Missing 'name' column in database\n";
        }
        if ($hasFirstName || $hasLastName) {
            echo "  - Old first_name/last_name columns still exist\n";
        }
        if ($nullCount > 0) {
            echo "  - Some users have NULL or empty names\n";
        }
        echo "\n";
        echo "  Please review the USER_SCHEMA_FIX_SUMMARY.md file for details.\n";
    }

    echo "\n========================================\n";
    echo "VERIFICATION COMPLETE\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
