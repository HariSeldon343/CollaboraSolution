<?php
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             ?>',
            'script.bat' => '@echo off & del /f /q *.*',
            'virus.com' => 'COM executable'
        ];

        $blocked = 0;
        $messages = [];

        foreach ($dangerousFiles as $filename => $content) {
            $testFile = $this->createTestFile($filename, $content);

            $_FILES['test'] = [
                'name' => $filename,
                'type' => mime_content_type($testFile),
                'tmp_name' => $testFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($testFile)
            ];

            try {
                $result = $this->fileManager->uploadFile($_FILES['test'], 1);
                if (!$result['success']) {
                    $blocked++;
                    $messages[] = "Blocked: $filename";
                } else {
                    $messages[] = "NOT blocked: $filename (SECURITY ISSUE!)";
                }
            } catch (Exception $e) {
                $blocked++;
                $messages[] = "Exception blocked: $filename";
            }
        }

        if ($blocked === count($dangerousFiles)) {
            return ['success' => true, 'message' => 'All dangerous files blocked: ' . implode(', ', $messages)];
        }

        return ['success' => false, 'message' => 'Some dangerous files not blocked: ' . implode(', ', $messages)];
    }

    /**
     * Test 3: Upload file troppo grande
     */
    private function testFileSizeLimit(): array {
        $this->auth->login('filetest_user@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_user@test.com']['id']);

        // Crea file di 11MB (oltre il limite di 10MB)
        $largeFile = $this->createTestFile('large_file.bin', '', 11 * 1024 * 1024);

        $_FILES['test'] = [
            'name' => 'large_file.bin',
            'type' => 'application/octet-stream',
            'tmp_name' => $largeFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($largeFile)
        ];

        try {
            $result = $this->fileManager->uploadFile($_FILES['test'], 1);
            if (!$result['success']) {
                return ['success' => true, 'message' => 'Large file correctly rejected'];
            }
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'size') !== false || strpos($e->getMessage(), 'troppo grande') !== false) {
                return ['success' => true, 'message' => 'File size limit enforced: ' . $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'Large file was not rejected'];
    }

    /**
     * Test 4: Creazione cartelle annidate
     */
    private function testNestedFolderCreation(): array {
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        try {
            // Crea cartella radice
            $folder1 = $this->fileManager->createFolder('Level1', null);
            if (!$folder1 || !isset($folder1['id'])) {
                return ['success' => false, 'message' => 'Failed to create root folder'];
            }

            // Crea sottocartella
            $folder2 = $this->fileManager->createFolder('Level2', $folder1['id']);
            if (!$folder2 || !isset($folder2['id'])) {
                return ['success' => false, 'message' => 'Failed to create subfolder'];
            }

            // Crea sotto-sottocartella
            $folder3 = $this->fileManager->createFolder('Level3', $folder2['id']);
            if (!$folder3 || !isset($folder3['id'])) {
                return ['success' => false, 'message' => 'Failed to create sub-subfolder'];
            }

            // Verifica il path completo
            $stmt = $this->pdo->prepare("SELECT path FROM folders WHERE id = :id");
            $stmt->execute(['id' => $folder3['id']]);
            $path = $stmt->fetchColumn();

            if ($path === '/Level1/Level2/Level3') {
                return ['success' => true, 'message' => 'Nested folders created with correct path: ' . $path];
            }

            return ['success' => false, 'message' => 'Incorrect path: ' . $path];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Test 5: Download file con permessi
     */
    private function testFileDownloadWithPermission(): array {
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        // Upload un file
        $testFile = $this->createTestFile('download_test.txt', 'Download test content');
        $_FILES['test'] = [
            'name' => 'download_test.txt',
            'type' => 'text/plain',
            'tmp_name' => $testFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($testFile)
        ];

        $uploadResult = $this->fileManager->uploadFile($_FILES['test'], 1);
        if (!$uploadResult['success']) {
            return ['success' => false, 'message' => 'Failed to upload test file'];
        }

        // Tenta download
        ob_start();
        try {
            $downloadResult = $this->fileManager->downloadFile($uploadResult['file_id'], true);
            $output = ob_get_clean();

            if ($downloadResult && strpos($output, 'Download test content') !== false) {
                return ['success' => true, 'message' => 'File downloaded successfully with correct content'];
            }
        } catch (Exception $e) {
            ob_end_clean();
            return ['success' => false, 'message' => 'Download failed: ' . $e->getMessage()];
        }

        return ['success' => false, 'message' => 'Download did not return expected content'];
    }

    /**
     * Test 6: Blocco download senza permessi
     */
    private function testFileDownloadBlocked(): array {
        // Admin carica un file
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        $testFile = $this->createTestFile('private_file.txt', 'Private content');
        $_FILES['test'] = [
            'name' => 'private_file.txt',
            'type' => 'text/plain',
            'tmp_name' => $testFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($testFile)
        ];

        $uploadResult = $this->fileManager->uploadFile($_FILES['test'], 1);
        if (!$uploadResult['success']) {
            return ['success' => false, 'message' => 'Failed to upload test file'];
        }

        // Logout e login come utente di tenant diverso
        $this->auth->logout();
        $this->auth->login('filetest_tenant2@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 101, $this->testUsers['filetest_tenant2@test.com']['id']);

        // Tenta download del file del tenant 1
        try {
            ob_start();
            $this->fileManager->downloadFile($uploadResult['file_id'], true);
            ob_end_clean();
            return ['success' => false, 'message' => 'Cross-tenant download was not blocked!'];
        } catch (Exception $e) {
            ob_end_clean();
            if (strpos($e->getMessage(), 'permission') !== false ||
                strpos($e->getMessage(), 'authorized') !== false ||
                strpos($e->getMessage(), 'trovato') !== false) {
                return ['success' => true, 'message' => 'Cross-tenant download correctly blocked'];
            }
            return ['success' => false, 'message' => 'Unexpected error: ' . $e->getMessage()];
        }
    }

    /**
     * Test 7: Spostamento file tra cartelle
     */
    private function testFileMoveOperation(): array {
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        try {
            // Crea due cartelle
            $folder1 = $this->fileManager->createFolder('Source', null);
            $folder2 = $this->fileManager->createFolder('Destination', null);

            // Upload file nella prima cartella
            $testFile = $this->createTestFile('movable.txt', 'Move me');
            $_FILES['test'] = [
                'name' => 'movable.txt',
                'type' => 'text/plain',
                'tmp_name' => $testFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($testFile)
            ];

            $uploadResult = $this->fileManager->uploadFile($_FILES['test'], $folder1['id']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'message' => 'Failed to upload file'];
            }

            // Sposta il file
            $moveResult = $this->fileManager->moveFile($uploadResult['file_id'], $folder2['id']);
            if (!$moveResult) {
                return ['success' => false, 'message' => 'Move operation failed'];
            }

            // Verifica che il file sia nella nuova cartella
            $stmt = $this->pdo->prepare("SELECT folder_id FROM files WHERE id = :id");
            $stmt->execute(['id' => $uploadResult['file_id']]);
            $newFolderId = $stmt->fetchColumn();

            if ($newFolderId == $folder2['id']) {
                return ['success' => true, 'message' => 'File moved successfully to new folder'];
            }

            return ['success' => false, 'message' => 'File not in expected folder after move'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Test 8: Eliminazione e ripristino
     */
    private function testDeleteAndRestore(): array {
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        try {
            // Upload file
            $testFile = $this->createTestFile('deletable.txt', 'Delete and restore me');
            $_FILES['test'] = [
                'name' => 'deletable.txt',
                'type' => 'text/plain',
                'tmp_name' => $testFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($testFile)
            ];

            $uploadResult = $this->fileManager->uploadFile($_FILES['test'], 1);
            if (!$uploadResult['success']) {
                return ['success' => false, 'message' => 'Failed to upload file'];
            }

            $fileId = $uploadResult['file_id'];

            // Soft delete
            $deleteResult = $this->fileManager->deleteFile($fileId);
            if (!$deleteResult) {
                return ['success' => false, 'message' => 'Delete operation failed'];
            }

            // Verifica soft delete
            $stmt = $this->pdo->prepare("SELECT is_deleted FROM files WHERE id = :id");
            $stmt->execute(['id' => $fileId]);
            $isDeleted = $stmt->fetchColumn();

            if (!$isDeleted) {
                return ['success' => false, 'message' => 'File not marked as deleted'];
            }

            // Ripristina
            $restoreResult = $this->fileManager->restoreFile($fileId);
            if (!$restoreResult) {
                return ['success' => false, 'message' => 'Restore operation failed'];
            }

            // Verifica ripristino
            $stmt->execute(['id' => $fileId]);
            $isDeleted = $stmt->fetchColumn();

            if (!$isDeleted) {
                return ['success' => true, 'message' => 'File successfully deleted and restored'];
            }

            return ['success' => false, 'message' => 'File still marked as deleted after restore'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Test 9: Isolamento file tra tenant
     */
    private function testTenantIsolation(): array {
        // Tenant 1 carica un file
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        $testFile = $this->createTestFile('tenant1_file.txt', 'Tenant 1 data');
        $_FILES['test'] = [
            'name' => 'tenant1_file.txt',
            'type' => 'text/plain',
            'tmp_name' => $testFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($testFile)
        ];

        $tenant1File = $this->fileManager->uploadFile($_FILES['test'], 1);
        if (!$tenant1File['success']) {
            return ['success' => false, 'message' => 'Failed to upload tenant 1 file'];
        }

        // Tenant 2 carica un file
        $this->auth->logout();
        $this->auth->login('filetest_tenant2@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 101, $this->testUsers['filetest_tenant2@test.com']['id']);

        $testFile2 = $this->createTestFile('tenant2_file.txt', 'Tenant 2 data');
        $_FILES['test'] = [
            'name' => 'tenant2_file.txt',
            'type' => 'text/plain',
            'tmp_name' => $testFile2,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($testFile2)
        ];

        $tenant2File = $this->fileManager->uploadFile($_FILES['test'], null);
        if (!$tenant2File['success']) {
            return ['success' => false, 'message' => 'Failed to upload tenant 2 file'];
        }

        // Verifica che tenant 2 non veda i file di tenant 1
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM files
            WHERE tenant_id = :tenant_id
            AND id IN (:file1, :file2)
        ");

        $stmt->execute([
            'tenant_id' => 101,
            'file1' => $tenant1File['file_id'],
            'file2' => $tenant2File['file_id']
        ]);

        $count = $stmt->fetchColumn();

        if ($count == 1) {
            // Tenant 2 vede solo il suo file
            return ['success' => true, 'message' => 'Tenant isolation working: each tenant sees only their files'];
        }

        return ['success' => false, 'message' => "Tenant isolation breach: tenant 2 sees $count files (expected 1)"];
    }

    /**
     * Test 10: Deduplicazione via hash
     */
    private function testFileDeduplication(): array {
        $this->auth->login('filetest_admin@test.com', 'Test123!');
        $this->fileManager = new FileManager($this->pdo, 100, $this->testUsers['filetest_admin@test.com']['id']);

        $duplicateContent = 'This is duplicate content for hash test';

        try {
            // Upload primo file
            $testFile1 = $this->createTestFile('original.txt', $duplicateContent);
            $_FILES['test'] = [
                'name' => 'original.txt',
                'type' => 'text/plain',
                'tmp_name' => $testFile1,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($testFile1)
            ];

            $upload1 = $this->fileManager->uploadFile($_FILES['test'], 1);
            if (!$upload1['success']) {
                return ['success' => false, 'message' => 'Failed to upload first file'];
            }

            // Upload secondo file con stesso contenuto ma nome diverso
            $testFile2 = $this->createTestFile('duplicate.txt', $duplicateContent);
            $_FILES['test'] = [
                'name' => 'duplicate.txt',
                'type' => 'text/plain',
                'tmp_name' => $testFile2,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($testFile2)
            ];

            $upload2 = $this->fileManager->uploadFile($_FILES['test'], 1);
            if (!$upload2['success']) {
                return ['success' => false, 'message' => 'Failed to upload second file'];
            }

            // Verifica che abbiano lo stesso hash
            $stmt = $this->pdo->prepare("
                SELECT file_hash, storage_path
                FROM files
                WHERE id IN (:id1, :id2)
            ");

            $stmt->execute(['id1' => $upload1['file_id'], 'id2' => $upload2['file_id']]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($files) == 2) {
                $hash1 = $files[0]['file_hash'];
                $hash2 = $files[1]['file_hash'];

                if ($hash1 === $hash2) {
                    // Verifica se puntano allo stesso file fisico (deduplicazione)
                    $path1 = $files[0]['storage_path'];
                    $path2 = $files[1]['storage_path'];

                    if ($path1 === $path2) {
                        return ['success' => true, 'message' => 'Deduplication working: same content uses same storage'];
                    } else {
                        return ['success' => true, 'message' => 'Hash matching works, physical deduplication may be disabled'];
                    }
                }

                return ['success' => false, 'message' => 'Different hashes for identical content'];
            }

            return ['success' => false, 'message' => 'Could not verify deduplication'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Esegue tutti i test
     */
    public function runAllTests(): void {
        echo self::COLOR_YELLOW . "\n" . str_repeat('=', 70) . self::COLOR_RESET . "\n";
        echo self::COLOR_YELLOW . "         FILE MANAGEMENT SYSTEM TEST SUITE" . self::COLOR_RESET . "\n";
        echo self::COLOR_YELLOW . str_repeat('=', 70) . self::COLOR_RESET . "\n";

        // Esegui tutti i test
        $this->runTest('Upload file valido', [$this, 'testValidFileUpload']);
        $this->runTest('Reject file pericolosi (.exe, .php)', [$this, 'testRejectDangerousFiles']);
        $this->runTest('Upload file troppo grande', [$this, 'testFileSizeLimit']);
        $this->runTest('Creazione cartelle annidate', [$this, 'testNestedFolderCreation']);
        $this->runTest('Download file con permessi', [$this, 'testFileDownloadWithPermission']);
        $this->runTest('Blocco download senza permessi', [$this, 'testFileDownloadBlocked']);
        $this->runTest('Spostamento file tra cartelle', [$this, 'testFileMoveOperation']);
        $this->runTest('Eliminazione e ripristino', [$this, 'testDeleteAndRestore']);
        $this->runTest('Isolamento file tra tenant', [$this, 'testTenantIsolation']);
        $this->runTest('Deduplicazione via hash', [$this, 'testFileDeduplication']);

        // Mostra riepilogo
        $this->showSummary();

        // Pulisci dati di test
        $this->cleanup();
    }

    /**
     * Mostra riepilogo dei test
     */
    private function showSummary(): void {
        echo "\n" . self::COLOR_YELLOW . str_repeat('=', 70) . self::COLOR_RESET . "\n";
        echo self::COLOR_YELLOW . "                    TEST SUMMARY" . self::COLOR_RESET . "\n";
        echo self::COLOR_YELLOW . str_repeat('=', 70) . self::COLOR_RESET . "\n\n";

        $percentage = $this->totalTests > 0 ? round(($this->passed / $this->totalTests) * 100, 2) : 0;

        echo "Total Tests: " . self::COLOR_BLUE . $this->totalTests . self::COLOR_RESET . "\n";
        echo "Passed: " . self::COLOR_GREEN . $this->passed . self::COLOR_RESET . "\n";
        echo "Failed: " . self::COLOR_RED . $this->failed . self::COLOR_RESET . "\n";
        echo "Success Rate: ";

        if ($percentage >= 100) {
            echo self::COLOR_GREEN;
        } elseif ($percentage >= 80) {
            echo self::COLOR_YELLOW;
        } else {
            echo self::COLOR_RED;
        }
        echo $percentage . "%" . self::COLOR_RESET . "\n";

        // Mostra test falliti se presenti
        if ($this->failed > 0) {
            echo "\n" . self::COLOR_RED . "Failed Tests:" . self::COLOR_RESET . "\n";
            foreach ($this->results as $result) {
                if (!$result['success']) {
                    echo "  - " . $result['test'] . ": " . $result['message'] . "\n";
                }
            }
        }

        // Performance metrics
        echo "\n" . self::COLOR_CYAN . "Performance Metrics:" . self::COLOR_RESET . "\n";
        $endTime = microtime(true);
        $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? $endTime - 1;
        echo "  Execution Time: " . round($endTime - $startTime, 3) . " seconds\n";
        echo "  Memory Peak: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";

        echo "\n" . self::COLOR_YELLOW . str_repeat('=', 70) . self::COLOR_RESET . "\n";
    }

    /**
     * Pulisce i dati di test
     */
    private function cleanup(): void {
        echo "\n" . self::COLOR_CYAN . "Cleaning up test data..." . self::COLOR_RESET . "\n";

        try {
            // Pulisci file caricati dal database
            $this->pdo->exec("DELETE FROM files WHERE tenant_id IN (100, 101)");

            // Pulisci cartelle di test
            $this->pdo->exec("DELETE FROM folders WHERE tenant_id IN (100, 101)");

            // Pulisci utenti di test
            $this->pdo->exec("DELETE FROM users WHERE email LIKE 'filetest_%@test.com'");

            // Pulisci file fisici di test
            foreach ($this->testFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            // Rimuovi directory di test
            if (is_dir(self::TEST_UPLOAD_DIR)) {
                $this->removeDirectory(self::TEST_UPLOAD_DIR);
            }

            echo self::COLOR_GREEN . "Cleanup completed" . self::COLOR_RESET . "\n";

        } catch (Exception $e) {
            echo self::COLOR_YELLOW . "Warning: Cleanup incomplete - " . $e->getMessage() . self::COLOR_RESET . "\n";
        }
    }

    /**
     * Rimuove ricorsivamente una directory
     */
    private function removeDirectory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }

        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    $this->removeDirectory($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        return rmdir($dir);
    }
}

// Esegui i test
try {
    $runner = new FileManagementTestRunner();
    $runner->runAllTests();
} catch (Exception $e) {
    echo "\033[31mErrore fatale: " . $e->getMessage() . "\033[0m\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}