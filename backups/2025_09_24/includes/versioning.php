<?php
declare(strict_types=1);

/**
 * VersionControl Class for CollaboraNexio
 *
 * Comprehensive file versioning system with efficient storage and version management
 * Supports delta storage, compression, branching, and enterprise-grade version control
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

require_once __DIR__ . '/db.php';

class VersionControl {
    private PDO $pdo;
    private int $tenantId;
    private int $userId;

    // Configuration
    private array $config = [
        'base_path' => '/var/versions',
        'temp_path' => '/var/tmp/versions',
        'chunk_size' => 2097152, // 2MB chunks
        'max_diff_size' => 10485760, // 10MB max for diff storage
        'compression_age' => 2592000, // 30 days in seconds
        'retention_policy' => [
            'keep_all_days' => 7,
            'keep_daily_days' => 30,
            'keep_weekly_days' => 90,
            'keep_monthly_days' => 365,
            'keep_yearly' => true
        ],
        'text_mime_types' => [
            'text/plain',
            'text/html',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/json',
            'application/xml',
            'text/xml',
            'application/x-httpd-php',
            'text/x-python',
            'text/x-java',
            'text/markdown'
        ],
        'image_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml'
        ],
        's3_config' => [
            'bucket' => null,
            'region' => 'us-east-1',
            'prefix' => 'versions/'
        ]
    ];

    // Cache for frequently accessed version metadata
    private array $metadataCache = [];
    private int $maxCacheSize = 100;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param int $tenantId Tenant ID for multi-tenancy
     * @param int $userId User ID for audit trail
     */
    public function __construct(PDO $pdo, int $tenantId, int $userId) {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
        $this->userId = $userId;

        // Ensure version directories exist
        $this->initializeDirectories();
    }

    /**
     * Initialize required directories
     */
    private function initializeDirectories(): void {
        $paths = [
            $this->config['base_path'],
            $this->config['temp_path'],
            $this->config['base_path'] . '/' . $this->tenantId,
            $this->config['base_path'] . '/' . $this->tenantId . '/deltas',
            $this->config['base_path'] . '/' . $this->tenantId . '/compressed',
            $this->config['base_path'] . '/' . $this->tenantId . '/chunks'
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Create a new version when file is modified
     *
     * @param int $fileId File ID from files table
     * @param array $options Optional parameters (branch, tag, summary, etc.)
     * @return array Version information
     * @throws Exception If versioning fails
     */
    public function createVersion(int $fileId, array $options = []): array {
        $this->pdo->beginTransaction();

        try {
            // Get current file information
            $currentFile = $this->getFileInfo($fileId);
            if (!$currentFile) {
                throw new Exception("File not found: $fileId");
            }

            // Verify tenant access
            if ($currentFile['tenant_id'] != $this->tenantId) {
                throw new Exception("Access denied to file");
            }

            // Get the latest version number
            $latestVersion = $this->getLatestVersionNumber($fileId);
            $newVersionNumber = $latestVersion + 1;

            // Read current file content
            $currentContent = file_get_contents($currentFile['file_path']);
            if ($currentContent === false) {
                throw new Exception("Failed to read file content");
            }

            // Calculate file hash
            $fileHash = hash('sha256', $currentContent);

            // Check if content actually changed from last version
            if ($latestVersion > 0) {
                $lastVersionHash = $this->getVersionHash($fileId, $latestVersion);
                if ($lastVersionHash === $fileHash && empty($options['force'])) {
                    $this->pdo->rollback();
                    return ['status' => 'unchanged', 'message' => 'File content has not changed'];
                }
            }

            // Determine storage strategy based on file type and size
            $storageStrategy = $this->determineStorageStrategy(
                $currentFile['mime_type'],
                strlen($currentContent),
                $latestVersion
            );

            // Store version content using appropriate strategy
            $storagePath = $this->storeVersionContent(
                $fileId,
                $newVersionNumber,
                $currentContent,
                $storageStrategy,
                $latestVersion
            );

            // Prepare version metadata
            $versionData = [
                'tenant_id' => $this->tenantId,
                'file_id' => $fileId,
                'version_number' => $newVersionNumber,
                'file_name' => $currentFile['file_name'],
                'file_size' => strlen($currentContent),
                'mime_type' => $currentFile['mime_type'],
                'file_hash' => $fileHash,
                'storage_path' => $storagePath,
                'storage_type' => $storageStrategy['type'],
                'created_by' => $this->userId,
                'modification_type' => $options['type'] ?? 'update',
                'change_summary' => $options['summary'] ?? null,
                'branch' => $options['branch'] ?? 'main',
                'tag' => $options['tag'] ?? null,
                'is_compressed' => $storageStrategy['compressed'] ?? false,
                'is_delta' => $storageStrategy['is_delta'] ?? false,
                'base_version' => $storageStrategy['base_version'] ?? null,
                'metadata' => json_encode($options['metadata'] ?? [])
            ];

            // Insert version record
            $sql = "INSERT INTO file_versions (
                        tenant_id, file_id, version_number, file_name, file_size,
                        mime_type, file_hash, storage_path, storage_type, created_by,
                        modification_type, change_summary, branch, tag, is_compressed,
                        is_delta, base_version, metadata, created_at
                    ) VALUES (
                        :tenant_id, :file_id, :version_number, :file_name, :file_size,
                        :mime_type, :file_hash, :storage_path, :storage_type, :created_by,
                        :modification_type, :change_summary, :branch, :tag, :is_compressed,
                        :is_delta, :base_version, :metadata, NOW()
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($versionData);
            $versionId = $this->pdo->lastInsertId();

            // Update file's current version reference
            $this->updateFileCurrentVersion($fileId, $newVersionNumber);

            // Trigger asynchronous operations
            if ($currentFile['file_size'] > 10485760) { // > 10MB
                $this->scheduleAsyncProcessing($versionId, $fileId);
            }

            // Auto-prune old versions if enabled
            if (!empty($options['auto_prune'])) {
                $this->pruneOldVersions($fileId);
            }

            $this->pdo->commit();

            // Clear cache for this file
            $this->clearFileCache($fileId);

            return [
                'status' => 'success',
                'version_id' => $versionId,
                'version_number' => $newVersionNumber,
                'file_hash' => $fileHash,
                'storage_type' => $storageStrategy['type'],
                'size' => strlen($currentContent)
            ];

        } catch (Exception $e) {
            $this->pdo->rollback();
            $this->logError('createVersion', $e->getMessage(), ['file_id' => $fileId]);
            throw $e;
        }
    }

    /**
     * Get complete version history for a file
     *
     * @param int $fileId File ID
     * @param array $filters Optional filters (branch, date_range, author, etc.)
     * @return array Version history with metadata
     */
    public function getVersionHistory(int $fileId, array $filters = []): array {
        // Check cache first
        $cacheKey = "history_$fileId_" . md5(json_encode($filters));
        if (isset($this->metadataCache[$cacheKey])) {
            return $this->metadataCache[$cacheKey];
        }

        $sql = "SELECT
                    v.*,
                    u.name as author_name,
                    u.email as author_email,
                    CASE
                        WHEN v.is_delta = 1 THEN 'delta'
                        WHEN v.is_compressed = 1 THEN 'compressed'
                        ELSE 'full'
                    END as storage_method,
                    (SELECT COUNT(*) FROM file_version_locks WHERE version_id = v.id) as is_locked
                FROM file_versions v
                LEFT JOIN users u ON v.created_by = u.id
                WHERE v.file_id = :file_id
                    AND v.tenant_id = :tenant_id";

        $params = [
            'file_id' => $fileId,
            'tenant_id' => $this->tenantId
        ];

        // Apply filters
        if (!empty($filters['branch'])) {
            $sql .= " AND v.branch = :branch";
            $params['branch'] = $filters['branch'];
        }

        if (!empty($filters['author_id'])) {
            $sql .= " AND v.created_by = :author_id";
            $params['author_id'] = $filters['author_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND v.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND v.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['tag'])) {
            $sql .= " AND v.tag = :tag";
            $params['tag'] = $filters['tag'];
        }

        $sql .= " ORDER BY v.version_number DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enhance version data with additional information
        foreach ($versions as &$version) {
            $version['metadata'] = json_decode($version['metadata'], true) ?? [];
            $version['size_formatted'] = $this->formatFileSize($version['file_size']);
            $version['age_days'] = floor((time() - strtotime($version['created_at'])) / 86400);

            // Add diff preview for text files
            if ($this->isTextFile($version['mime_type']) && $version['version_number'] > 1) {
                $version['has_diff'] = true;
                $version['diff_lines'] = $this->getDiffSummary(
                    $fileId,
                    $version['version_number'] - 1,
                    $version['version_number']
                );
            }
        }

        // Cache the result
        $this->cacheMetadata($cacheKey, $versions);

        return $versions;
    }

    /**
     * Restore a specific version as the current version
     *
     * @param int $versionId Version ID to restore
     * @param string $reason Reason for restoration
     * @return array Restoration result
     * @throws Exception If restoration fails
     */
    public function restoreVersion(int $versionId, string $reason = ''): array {
        $this->pdo->beginTransaction();

        try {
            // Get version information
            $version = $this->getVersionInfo($versionId);
            if (!$version) {
                throw new Exception("Version not found: $versionId");
            }

            // Verify tenant access
            if ($version['tenant_id'] != $this->tenantId) {
                throw new Exception("Access denied to version");
            }

            // Check if version is locked
            if ($this->isVersionLocked($versionId)) {
                throw new Exception("Cannot restore locked version");
            }

            // Get version content
            $content = $this->getVersionContent($versionId);
            if ($content === false) {
                throw new Exception("Failed to retrieve version content");
            }

            // Get current file info
            $fileInfo = $this->getFileInfo($version['file_id']);

            // Create backup of current version before restoration
            $this->createVersion($version['file_id'], [
                'type' => 'pre_restore_backup',
                'summary' => "Backup before restoring version {$version['version_number']}"
            ]);

            // Write restored content to file
            if (file_put_contents($fileInfo['file_path'], $content) === false) {
                throw new Exception("Failed to write restored content");
            }

            // Create new version record for the restoration
            $restoredVersion = $this->createVersion($version['file_id'], [
                'type' => 'restore',
                'summary' => "Restored from version {$version['version_number']}: $reason",
                'metadata' => [
                    'restored_from_version' => $version['version_number'],
                    'restored_by' => $this->userId,
                    'restoration_reason' => $reason
                ]
            ]);

            // Log restoration event
            $this->logVersionEvent('restore', $versionId, [
                'restored_to_version' => $restoredVersion['version_number'],
                'reason' => $reason
            ]);

            $this->pdo->commit();

            return [
                'status' => 'success',
                'restored_from' => $version['version_number'],
                'new_version' => $restoredVersion['version_number'],
                'file_id' => $version['file_id']
            ];

        } catch (Exception $e) {
            $this->pdo->rollback();
            $this->logError('restoreVersion', $e->getMessage(), ['version_id' => $versionId]);
            throw $e;
        }
    }

    /**
     * Compare two versions and generate diff
     *
     * @param int $versionId1 First version ID
     * @param int $versionId2 Second version ID
     * @param string $format Diff format (unified, side_by_side, inline)
     * @return array Comparison result with diff
     */
    public function compareVersions(int $versionId1, int $versionId2, string $format = 'unified'): array {
        // Get version information
        $v1 = $this->getVersionInfo($versionId1);
        $v2 = $this->getVersionInfo($versionId2);

        if (!$v1 || !$v2) {
            throw new Exception("One or both versions not found");
        }

        // Verify same file and tenant
        if ($v1['file_id'] !== $v2['file_id']) {
            throw new Exception("Versions belong to different files");
        }

        if ($v1['tenant_id'] != $this->tenantId || $v2['tenant_id'] != $this->tenantId) {
            throw new Exception("Access denied to versions");
        }

        // Get content for both versions
        $content1 = $this->getVersionContent($versionId1);
        $content2 = $this->getVersionContent($versionId2);

        $result = [
            'version1' => [
                'id' => $versionId1,
                'number' => $v1['version_number'],
                'date' => $v1['created_at'],
                'author' => $v1['created_by'],
                'size' => $v1['file_size']
            ],
            'version2' => [
                'id' => $versionId2,
                'number' => $v2['version_number'],
                'date' => $v2['created_at'],
                'author' => $v2['created_by'],
                'size' => $v2['file_size']
            ],
            'file_id' => $v1['file_id'],
            'mime_type' => $v1['mime_type']
        ];

        // Generate diff based on file type
        if ($this->isTextFile($v1['mime_type'])) {
            $diff = $this->generateTextDiff($content1, $content2, $format);
            $result['diff'] = $diff['content'];
            $result['statistics'] = $diff['stats'];
            $result['format'] = $format;
        } elseif ($this->isImageFile($v1['mime_type'])) {
            // For images, provide metadata comparison
            $result['diff'] = $this->compareImageMetadata($content1, $content2);
            $result['format'] = 'metadata';
        } else {
            // For binary files, just compare sizes and hashes
            $result['diff'] = [
                'size_difference' => $v2['file_size'] - $v1['file_size'],
                'hash1' => $v1['file_hash'],
                'hash2' => $v2['file_hash'],
                'identical' => $v1['file_hash'] === $v2['file_hash']
            ];
            $result['format'] = 'binary';
        }

        return $result;
    }

    /**
     * Prune old versions based on retention policy
     *
     * @param int|null $fileId Optional specific file ID, null for all files
     * @return array Pruning results
     */
    public function pruneOldVersions(?int $fileId = null): array {
        $results = [
            'deleted_versions' => 0,
            'freed_space' => 0,
            'errors' => []
        ];

        try {
            // Get files to prune
            $sql = "SELECT DISTINCT file_id FROM file_versions WHERE tenant_id = :tenant_id";
            $params = ['tenant_id' => $this->tenantId];

            if ($fileId !== null) {
                $sql .= " AND file_id = :file_id";
                $params['file_id'] = $fileId;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $fileIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($fileIds as $fid) {
                $pruneResult = $this->pruneFileVersions($fid);
                $results['deleted_versions'] += $pruneResult['deleted'];
                $results['freed_space'] += $pruneResult['freed_space'];

                if (!empty($pruneResult['error'])) {
                    $results['errors'][] = $pruneResult['error'];
                }
            }

            // Clean up orphaned storage files
            $this->cleanOrphanedFiles();

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            $this->logError('pruneOldVersions', $e->getMessage());
        }

        return $results;
    }

    /**
     * Prune versions for a specific file based on retention policy
     *
     * @param int $fileId File ID
     * @return array Pruning result
     */
    private function pruneFileVersions(int $fileId): array {
        $result = [
            'deleted' => 0,
            'freed_space' => 0,
            'error' => null
        ];

        try {
            // Get all versions for the file
            $sql = "SELECT * FROM file_versions
                    WHERE file_id = :file_id AND tenant_id = :tenant_id
                    ORDER BY version_number DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['file_id' => $fileId, 'tenant_id' => $this->tenantId]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($versions) <= 1) {
                return $result; // Keep at least one version
            }

            $now = time();
            $policy = $this->config['retention_policy'];
            $versionsToKeep = [];
            $versionsToDelete = [];

            // Always keep the latest version
            $versionsToKeep[] = $versions[0]['id'];

            // Apply retention policy
            foreach ($versions as $version) {
                if (in_array($version['id'], $versionsToKeep)) {
                    continue;
                }

                // Check if version is locked
                if ($this->isVersionLocked($version['id'])) {
                    $versionsToKeep[] = $version['id'];
                    continue;
                }

                // Check if version has a tag (keep tagged versions)
                if (!empty($version['tag'])) {
                    $versionsToKeep[] = $version['id'];
                    continue;
                }

                $versionAge = $now - strtotime($version['created_at']);
                $ageDays = floor($versionAge / 86400);

                // Keep all recent versions
                if ($ageDays <= $policy['keep_all_days']) {
                    $versionsToKeep[] = $version['id'];
                }
                // Keep daily versions for specified period
                elseif ($ageDays <= $policy['keep_daily_days']) {
                    if ($this->shouldKeepDaily($version, $versions)) {
                        $versionsToKeep[] = $version['id'];
                    } else {
                        $versionsToDelete[] = $version['id'];
                    }
                }
                // Keep weekly versions for specified period
                elseif ($ageDays <= $policy['keep_weekly_days']) {
                    if ($this->shouldKeepWeekly($version, $versions)) {
                        $versionsToKeep[] = $version['id'];
                    } else {
                        $versionsToDelete[] = $version['id'];
                    }
                }
                // Keep monthly versions for specified period
                elseif ($ageDays <= $policy['keep_monthly_days']) {
                    if ($this->shouldKeepMonthly($version, $versions)) {
                        $versionsToKeep[] = $version['id'];
                    } else {
                        $versionsToDelete[] = $version['id'];
                    }
                }
                // Keep yearly versions if configured
                elseif ($policy['keep_yearly']) {
                    if ($this->shouldKeepYearly($version, $versions)) {
                        $versionsToKeep[] = $version['id'];
                    } else {
                        $versionsToDelete[] = $version['id'];
                    }
                } else {
                    $versionsToDelete[] = $version['id'];
                }
            }

            // Delete versions marked for deletion
            foreach ($versionsToDelete as $versionId) {
                $deletedSize = $this->deleteVersion($versionId);
                if ($deletedSize !== false) {
                    $result['deleted']++;
                    $result['freed_space'] += $deletedSize;
                }
            }

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Delete a specific version
     *
     * @param int $versionId Version ID
     * @return int|false Size of deleted files or false on error
     */
    private function deleteVersion(int $versionId): int|false {
        try {
            // Get version info before deletion
            $version = $this->getVersionInfo($versionId);
            if (!$version) {
                return false;
            }

            // Delete physical file
            $deletedSize = 0;
            if (file_exists($version['storage_path'])) {
                $deletedSize = filesize($version['storage_path']);
                unlink($version['storage_path']);
            }

            // Delete database record
            $stmt = $this->pdo->prepare(
                "DELETE FROM file_versions WHERE id = :id AND tenant_id = :tenant_id"
            );
            $stmt->execute(['id' => $versionId, 'tenant_id' => $this->tenantId]);

            // Log deletion
            $this->logVersionEvent('delete', $versionId, ['size' => $deletedSize]);

            return $deletedSize;

        } catch (Exception $e) {
            $this->logError('deleteVersion', $e->getMessage(), ['version_id' => $versionId]);
            return false;
        }
    }

    /**
     * Store version content using appropriate strategy
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param string $content File content
     * @param array $strategy Storage strategy
     * @param int $previousVersion Previous version number
     * @return string Storage path
     */
    private function storeVersionContent(
        int $fileId,
        int $versionNumber,
        string $content,
        array $strategy,
        int $previousVersion
    ): string {
        $basePath = $this->config['base_path'] . '/' . $this->tenantId;

        switch ($strategy['type']) {
            case 'delta':
                return $this->storeDeltaVersion($fileId, $versionNumber, $content, $previousVersion);

            case 'compressed':
                return $this->storeCompressedVersion($fileId, $versionNumber, $content);

            case 'chunked':
                return $this->storeChunkedVersion($fileId, $versionNumber, $content);

            case 's3':
                return $this->storeS3Version($fileId, $versionNumber, $content);

            default: // 'full'
                return $this->storeFullVersion($fileId, $versionNumber, $content);
        }
    }

    /**
     * Store delta/diff version for text files
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param string $content New content
     * @param int $previousVersion Previous version number
     * @return string Storage path
     */
    private function storeDeltaVersion(
        int $fileId,
        int $versionNumber,
        string $content,
        int $previousVersion
    ): string {
        if ($previousVersion === 0) {
            // First version, store full content
            return $this->storeFullVersion($fileId, $versionNumber, $content);
        }

        // Get previous version content
        $prevVersionId = $this->getVersionId($fileId, $previousVersion);
        $prevContent = $this->getVersionContent($prevVersionId);

        // Generate diff using xdiff or similar
        $diff = $this->generateDiff($prevContent, $content);

        // Store diff
        $deltaPath = $this->config['base_path'] . '/' . $this->tenantId .
                     '/deltas/' . $fileId . '_v' . $versionNumber . '.diff';

        file_put_contents($deltaPath, $diff);

        // Store base version reference in metadata
        $this->storeVersionMetadata($fileId, $versionNumber, [
            'base_version' => $previousVersion,
            'diff_algorithm' => 'unified',
            'diff_size' => strlen($diff)
        ]);

        return $deltaPath;
    }

    /**
     * Store compressed version
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param string $content Content to compress
     * @return string Storage path
     */
    private function storeCompressedVersion(int $fileId, int $versionNumber, string $content): string {
        $compressedPath = $this->config['base_path'] . '/' . $this->tenantId .
                         '/compressed/' . $fileId . '_v' . $versionNumber . '.gz';

        $compressed = gzencode($content, 9);
        file_put_contents($compressedPath, $compressed);

        return $compressedPath;
    }

    /**
     * Store large files in chunks
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param string $content Content to chunk
     * @return string Storage manifest path
     */
    private function storeChunkedVersion(int $fileId, int $versionNumber, string $content): string {
        $chunkSize = $this->config['chunk_size'];
        $chunks = str_split($content, $chunkSize);
        $manifest = [];

        $chunkDir = $this->config['base_path'] . '/' . $this->tenantId .
                   '/chunks/' . $fileId . '_v' . $versionNumber;
        mkdir($chunkDir, 0755, true);

        foreach ($chunks as $index => $chunk) {
            $chunkHash = hash('sha256', $chunk);
            $chunkPath = $chunkDir . '/chunk_' . $index . '_' . $chunkHash;

            // Deduplicate chunks
            if (!file_exists($chunkPath)) {
                file_put_contents($chunkPath, $chunk);
            }

            $manifest[] = [
                'index' => $index,
                'hash' => $chunkHash,
                'size' => strlen($chunk),
                'path' => $chunkPath
            ];
        }

        // Store manifest
        $manifestPath = $chunkDir . '/manifest.json';
        file_put_contents($manifestPath, json_encode($manifest));

        return $manifestPath;
    }

    /**
     * Store version in S3 or external storage
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param string $content Content to store
     * @return string S3 path
     */
    private function storeS3Version(int $fileId, int $versionNumber, string $content): string {
        // This would integrate with AWS SDK
        // For now, fallback to local storage
        return $this->storeFullVersion($fileId, $versionNumber, $content);
    }

    /**
     * Store full version content
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param string $content Content to store
     * @return string Storage path
     */
    private function storeFullVersion(int $fileId, int $versionNumber, string $content): string {
        $versionPath = $this->config['base_path'] . '/' . $this->tenantId .
                      '/' . $fileId . '_v' . $versionNumber;

        file_put_contents($versionPath, $content);

        return $versionPath;
    }

    /**
     * Get version content from storage
     *
     * @param int $versionId Version ID
     * @return string|false Version content or false on error
     */
    public function getVersionContent(int $versionId): string|false {
        try {
            $version = $this->getVersionInfo($versionId);
            if (!$version) {
                return false;
            }

            // Check storage type and retrieve accordingly
            if ($version['is_delta']) {
                return $this->reconstructFromDelta($version);
            } elseif ($version['is_compressed']) {
                return $this->decompressVersion($version);
            } elseif ($version['storage_type'] === 'chunked') {
                return $this->reconstructFromChunks($version);
            } elseif ($version['storage_type'] === 's3') {
                return $this->retrieveFromS3($version);
            } else {
                // Full version stored locally
                return file_get_contents($version['storage_path']);
            }

        } catch (Exception $e) {
            $this->logError('getVersionContent', $e->getMessage(), ['version_id' => $versionId]);
            return false;
        }
    }

    /**
     * Reconstruct content from delta/diff
     *
     * @param array $version Version information
     * @return string Reconstructed content
     */
    private function reconstructFromDelta(array $version): string {
        // Get base version content
        $baseVersionId = $this->getVersionId($version['file_id'], $version['base_version']);
        $baseContent = $this->getVersionContent($baseVersionId);

        // Apply diff
        $diff = file_get_contents($version['storage_path']);

        // Use xdiff_string_patch or custom patch function
        return $this->applyDiff($baseContent, $diff);
    }

    /**
     * Decompress version content
     *
     * @param array $version Version information
     * @return string Decompressed content
     */
    private function decompressVersion(array $version): string {
        $compressed = file_get_contents($version['storage_path']);
        return gzdecode($compressed);
    }

    /**
     * Reconstruct content from chunks
     *
     * @param array $version Version information
     * @return string Reconstructed content
     */
    private function reconstructFromChunks(array $version): string {
        $manifest = json_decode(file_get_contents($version['storage_path']), true);
        $content = '';

        foreach ($manifest as $chunk) {
            $content .= file_get_contents($chunk['path']);
        }

        return $content;
    }

    /**
     * Retrieve content from S3
     *
     * @param array $version Version information
     * @return string Content from S3
     */
    private function retrieveFromS3(array $version): string {
        // This would use AWS SDK to retrieve from S3
        // For now, fallback to local storage
        return file_get_contents($version['storage_path']);
    }

    /**
     * Determine storage strategy based on file characteristics
     *
     * @param string $mimeType File MIME type
     * @param int $fileSize File size in bytes
     * @param int $versionCount Number of existing versions
     * @return array Storage strategy
     */
    private function determineStorageStrategy(
        string $mimeType,
        int $fileSize,
        int $versionCount
    ): array {
        // For text files under 10MB, use delta storage after first version
        if ($this->isTextFile($mimeType) && $fileSize < $this->config['max_diff_size'] && $versionCount > 0) {
            return [
                'type' => 'delta',
                'is_delta' => true,
                'base_version' => $versionCount
            ];
        }

        // For large files, use chunked storage
        if ($fileSize > 52428800) { // > 50MB
            return [
                'type' => 'chunked',
                'compressed' => false
            ];
        }

        // Compress old versions and large text files
        if ($fileSize > 1048576 || $versionCount > 10) { // > 1MB or many versions
            return [
                'type' => 'compressed',
                'compressed' => true
            ];
        }

        // Use S3 for very large files if configured
        if ($fileSize > 104857600 && !empty($this->config['s3_config']['bucket'])) { // > 100MB
            return [
                'type' => 's3',
                'compressed' => false
            ];
        }

        // Default to full local storage
        return [
            'type' => 'full',
            'compressed' => false
        ];
    }

    /**
     * Check if file is text-based
     *
     * @param string $mimeType MIME type
     * @return bool
     */
    private function isTextFile(string $mimeType): bool {
        return in_array($mimeType, $this->config['text_mime_types']) ||
               str_starts_with($mimeType, 'text/');
    }

    /**
     * Check if file is an image
     *
     * @param string $mimeType MIME type
     * @return bool
     */
    private function isImageFile(string $mimeType): bool {
        return in_array($mimeType, $this->config['image_mime_types']);
    }

    /**
     * Generate diff between two text contents
     *
     * @param string $old Old content
     * @param string $new New content
     * @return string Diff output
     */
    private function generateDiff(string $old, string $new): string {
        // Use xdiff extension if available
        if (function_exists('xdiff_string_diff')) {
            return xdiff_string_diff($old, $new, 3); // Unified diff with 3 lines context
        }

        // Fallback to custom implementation
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        // Simple line-by-line diff
        $diff = [];
        $maxLines = max(count($oldLines), count($newLines));

        for ($i = 0; $i < $maxLines; $i++) {
            $oldLine = $oldLines[$i] ?? '';
            $newLine = $newLines[$i] ?? '';

            if ($oldLine !== $newLine) {
                if (isset($oldLines[$i])) {
                    $diff[] = "- " . $oldLine;
                }
                if (isset($newLines[$i])) {
                    $diff[] = "+ " . $newLine;
                }
            }
        }

        return implode("\n", $diff);
    }

    /**
     * Apply diff to content
     *
     * @param string $content Original content
     * @param string $diff Diff to apply
     * @return string Patched content
     */
    private function applyDiff(string $content, string $diff): string {
        // Use xdiff extension if available
        if (function_exists('xdiff_string_patch')) {
            $result = xdiff_string_patch($content, $diff, XDIFF_PATCH_NORMAL);
            return $result !== false ? $result : $content;
        }

        // Fallback to simple implementation
        // This is a simplified version, production would need proper diff parsing
        $lines = explode("\n", $content);
        $diffLines = explode("\n", $diff);
        $result = [];

        foreach ($diffLines as $diffLine) {
            if (str_starts_with($diffLine, '+')) {
                $result[] = substr($diffLine, 2);
            } elseif (!str_starts_with($diffLine, '-')) {
                $result[] = $diffLine;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Generate text diff with statistics
     *
     * @param string $content1 First content
     * @param string $content2 Second content
     * @param string $format Output format
     * @return array Diff with statistics
     */
    private function generateTextDiff(string $content1, string $content2, string $format): array {
        $lines1 = explode("\n", $content1);
        $lines2 = explode("\n", $content2);

        $diff = [
            'content' => '',
            'stats' => [
                'additions' => 0,
                'deletions' => 0,
                'changes' => 0,
                'total_lines' => max(count($lines1), count($lines2))
            ]
        ];

        if ($format === 'unified') {
            $diff['content'] = $this->generateDiff($content1, $content2);
        } elseif ($format === 'side_by_side') {
            $diff['content'] = $this->generateSideBySideDiff($lines1, $lines2);
        }

        // Calculate statistics
        foreach (explode("\n", $diff['content']) as $line) {
            if (str_starts_with($line, '+')) {
                $diff['stats']['additions']++;
            } elseif (str_starts_with($line, '-')) {
                $diff['stats']['deletions']++;
            }
        }

        $diff['stats']['changes'] = $diff['stats']['additions'] + $diff['stats']['deletions'];

        return $diff;
    }

    /**
     * Generate side-by-side diff
     *
     * @param array $lines1 First content lines
     * @param array $lines2 Second content lines
     * @return string Side-by-side diff
     */
    private function generateSideBySideDiff(array $lines1, array $lines2): string {
        $maxLines = max(count($lines1), count($lines2));
        $result = [];

        for ($i = 0; $i < $maxLines; $i++) {
            $line1 = $lines1[$i] ?? '';
            $line2 = $lines2[$i] ?? '';

            $status = ($line1 === $line2) ? '=' : '≠';
            $result[] = sprintf("%-50s %s %-50s",
                substr($line1, 0, 50),
                $status,
                substr($line2, 0, 50)
            );
        }

        return implode("\n", $result);
    }

    /**
     * Get file information
     *
     * @param int $fileId File ID
     * @return array|null File information
     */
    private function getFileInfo(int $fileId): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM files WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['id' => $fileId, 'tenant_id' => $this->tenantId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get version information
     *
     * @param int $versionId Version ID
     * @return array|null Version information
     */
    private function getVersionInfo(int $versionId): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM file_versions WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['id' => $versionId, 'tenant_id' => $this->tenantId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get latest version number for a file
     *
     * @param int $fileId File ID
     * @return int Latest version number
     */
    private function getLatestVersionNumber(int $fileId): int {
        $stmt = $this->pdo->prepare(
            "SELECT MAX(version_number) FROM file_versions
             WHERE file_id = :file_id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['file_id' => $fileId, 'tenant_id' => $this->tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get version hash
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @return string|null File hash
     */
    private function getVersionHash(int $fileId, int $versionNumber): ?string {
        $stmt = $this->pdo->prepare(
            "SELECT file_hash FROM file_versions
             WHERE file_id = :file_id AND version_number = :version_number
             AND tenant_id = :tenant_id"
        );
        $stmt->execute([
            'file_id' => $fileId,
            'version_number' => $versionNumber,
            'tenant_id' => $this->tenantId
        ]);

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Get version ID by file and version number
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @return int|null Version ID
     */
    private function getVersionId(int $fileId, int $versionNumber): ?int {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM file_versions
             WHERE file_id = :file_id AND version_number = :version_number
             AND tenant_id = :tenant_id"
        );
        $stmt->execute([
            'file_id' => $fileId,
            'version_number' => $versionNumber,
            'tenant_id' => $this->tenantId
        ]);

        $result = $stmt->fetchColumn();
        return $result ? (int) $result : null;
    }

    /**
     * Update file's current version reference
     *
     * @param int $fileId File ID
     * @param int $versionNumber New current version number
     */
    private function updateFileCurrentVersion(int $fileId, int $versionNumber): void {
        $stmt = $this->pdo->prepare(
            "UPDATE files SET current_version = :version, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute([
            'version' => $versionNumber,
            'id' => $fileId,
            'tenant_id' => $this->tenantId
        ]);
    }

    /**
     * Check if version is locked
     *
     * @param int $versionId Version ID
     * @return bool
     */
    private function isVersionLocked(int $versionId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM file_version_locks
             WHERE version_id = :version_id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['version_id' => $versionId, 'tenant_id' => $this->tenantId]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Lock a version to prevent deletion
     *
     * @param int $versionId Version ID
     * @param string $reason Lock reason
     * @return bool Success
     */
    public function lockVersion(int $versionId, string $reason = ''): bool {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO file_version_locks
                 (tenant_id, version_id, locked_by, reason, locked_at)
                 VALUES (:tenant_id, :version_id, :user_id, :reason, NOW())"
            );

            return $stmt->execute([
                'tenant_id' => $this->tenantId,
                'version_id' => $versionId,
                'user_id' => $this->userId,
                'reason' => $reason
            ]);

        } catch (Exception $e) {
            $this->logError('lockVersion', $e->getMessage(), ['version_id' => $versionId]);
            return false;
        }
    }

    /**
     * Unlock a version
     *
     * @param int $versionId Version ID
     * @return bool Success
     */
    public function unlockVersion(int $versionId): bool {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM file_version_locks
                 WHERE version_id = :version_id AND tenant_id = :tenant_id"
            );

            return $stmt->execute([
                'version_id' => $versionId,
                'tenant_id' => $this->tenantId
            ]);

        } catch (Exception $e) {
            $this->logError('unlockVersion', $e->getMessage(), ['version_id' => $versionId]);
            return false;
        }
    }

    /**
     * Tag a version
     *
     * @param int $versionId Version ID
     * @param string $tag Tag name
     * @param string $description Tag description
     * @return bool Success
     */
    public function tagVersion(int $versionId, string $tag, string $description = ''): bool {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE file_versions
                 SET tag = :tag, tag_description = :description
                 WHERE id = :id AND tenant_id = :tenant_id"
            );

            return $stmt->execute([
                'tag' => $tag,
                'description' => $description,
                'id' => $versionId,
                'tenant_id' => $this->tenantId
            ]);

        } catch (Exception $e) {
            $this->logError('tagVersion', $e->getMessage(), ['version_id' => $versionId]);
            return false;
        }
    }

    /**
     * Create a branch from a version
     *
     * @param int $versionId Source version ID
     * @param string $branchName Branch name
     * @return array Branch information
     */
    public function createBranch(int $versionId, string $branchName): array {
        try {
            // Get source version
            $sourceVersion = $this->getVersionInfo($versionId);
            if (!$sourceVersion) {
                throw new Exception("Source version not found");
            }

            // Create branch by copying the version with new branch name
            $branchVersion = $this->createVersion($sourceVersion['file_id'], [
                'type' => 'branch',
                'branch' => $branchName,
                'summary' => "Branched from version {$sourceVersion['version_number']}",
                'metadata' => [
                    'parent_branch' => $sourceVersion['branch'],
                    'parent_version' => $sourceVersion['version_number']
                ]
            ]);

            return [
                'status' => 'success',
                'branch' => $branchName,
                'version_id' => $branchVersion['version_id'],
                'parent_version' => $sourceVersion['version_number']
            ];

        } catch (Exception $e) {
            $this->logError('createBranch', $e->getMessage(), ['version_id' => $versionId]);
            throw $e;
        }
    }

    /**
     * Merge branches
     *
     * @param int $sourceVersionId Source branch version
     * @param int $targetVersionId Target branch version
     * @param string $strategy Merge strategy (ours, theirs, manual)
     * @return array Merge result
     */
    public function mergeBranches(
        int $sourceVersionId,
        int $targetVersionId,
        string $strategy = 'manual'
    ): array {
        try {
            $sourceVersion = $this->getVersionInfo($sourceVersionId);
            $targetVersion = $this->getVersionInfo($targetVersionId);

            if (!$sourceVersion || !$targetVersion) {
                throw new Exception("Invalid versions for merge");
            }

            // Get content from both versions
            $sourceContent = $this->getVersionContent($sourceVersionId);
            $targetContent = $this->getVersionContent($targetVersionId);

            // Find common ancestor
            $ancestorId = $this->findCommonAncestor($sourceVersionId, $targetVersionId);
            $ancestorContent = $ancestorId ? $this->getVersionContent($ancestorId) : '';

            // Perform three-way merge for text files
            if ($this->isTextFile($sourceVersion['mime_type'])) {
                $mergedContent = $this->performThreeWayMerge(
                    $ancestorContent,
                    $sourceContent,
                    $targetContent,
                    $strategy
                );

                if ($mergedContent['conflicts']) {
                    return [
                        'status' => 'conflict',
                        'conflicts' => $mergedContent['conflicts'],
                        'merged_content' => $mergedContent['content']
                    ];
                }

                // Create merged version
                $mergedVersion = $this->createVersion($targetVersion['file_id'], [
                    'type' => 'merge',
                    'branch' => $targetVersion['branch'],
                    'summary' => "Merged {$sourceVersion['branch']} into {$targetVersion['branch']}",
                    'metadata' => [
                        'source_version' => $sourceVersionId,
                        'target_version' => $targetVersionId,
                        'merge_strategy' => $strategy
                    ]
                ]);

                return [
                    'status' => 'success',
                    'merged_version' => $mergedVersion['version_id']
                ];
            } else {
                // For binary files, use strategy to decide which version to keep
                $keepContent = $strategy === 'theirs' ? $sourceContent : $targetContent;

                // Create new version with chosen content
                $mergedVersion = $this->createVersion($targetVersion['file_id'], [
                    'type' => 'merge',
                    'branch' => $targetVersion['branch'],
                    'summary' => "Binary merge: kept " . ($strategy === 'theirs' ? 'source' : 'target'),
                    'metadata' => [
                        'source_version' => $sourceVersionId,
                        'target_version' => $targetVersionId,
                        'merge_strategy' => $strategy,
                        'binary_merge' => true
                    ]
                ]);

                return [
                    'status' => 'success',
                    'merged_version' => $mergedVersion['version_id'],
                    'binary_merge' => true
                ];
            }

        } catch (Exception $e) {
            $this->logError('mergeBranches', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Perform three-way merge
     *
     * @param string $ancestor Ancestor content
     * @param string $source Source content
     * @param string $target Target content
     * @param string $strategy Merge strategy
     * @return array Merge result
     */
    private function performThreeWayMerge(
        string $ancestor,
        string $source,
        string $target,
        string $strategy
    ): array {
        // This is a simplified implementation
        // Production would use proper merge algorithms

        if ($strategy === 'ours') {
            return ['content' => $target, 'conflicts' => false];
        }

        if ($strategy === 'theirs') {
            return ['content' => $source, 'conflicts' => false];
        }

        // Manual merge - detect conflicts
        $ancestorLines = explode("\n", $ancestor);
        $sourceLines = explode("\n", $source);
        $targetLines = explode("\n", $target);

        $merged = [];
        $conflicts = [];

        // Simple line-by-line comparison
        $maxLines = max(count($sourceLines), count($targetLines));

        for ($i = 0; $i < $maxLines; $i++) {
            $sourceLine = $sourceLines[$i] ?? '';
            $targetLine = $targetLines[$i] ?? '';
            $ancestorLine = $ancestorLines[$i] ?? '';

            if ($sourceLine === $targetLine) {
                $merged[] = $sourceLine;
            } elseif ($sourceLine === $ancestorLine) {
                $merged[] = $targetLine;
            } elseif ($targetLine === $ancestorLine) {
                $merged[] = $sourceLine;
            } else {
                // Conflict
                $conflicts[] = [
                    'line' => $i + 1,
                    'ancestor' => $ancestorLine,
                    'source' => $sourceLine,
                    'target' => $targetLine
                ];

                // Include conflict markers
                $merged[] = '<<<<<<< SOURCE';
                $merged[] = $sourceLine;
                $merged[] = '=======';
                $merged[] = $targetLine;
                $merged[] = '>>>>>>> TARGET';
            }
        }

        return [
            'content' => implode("\n", $merged),
            'conflicts' => count($conflicts) > 0 ? $conflicts : false
        ];
    }

    /**
     * Find common ancestor of two versions
     *
     * @param int $version1Id First version ID
     * @param int $version2Id Second version ID
     * @return int|null Common ancestor version ID
     */
    private function findCommonAncestor(int $version1Id, int $version2Id): ?int {
        // Get ancestry chains for both versions
        $ancestors1 = $this->getAncestors($version1Id);
        $ancestors2 = $this->getAncestors($version2Id);

        // Find first common ancestor
        foreach ($ancestors1 as $ancestor1) {
            if (in_array($ancestor1, $ancestors2)) {
                return $ancestor1;
            }
        }

        return null;
    }

    /**
     * Get ancestor chain for a version
     *
     * @param int $versionId Version ID
     * @return array Ancestor version IDs
     */
    private function getAncestors(int $versionId): array {
        $ancestors = [];
        $currentId = $versionId;

        while ($currentId) {
            $version = $this->getVersionInfo($currentId);
            if (!$version) {
                break;
            }

            $ancestors[] = $currentId;

            // Get previous version ID
            $stmt = $this->pdo->prepare(
                "SELECT id FROM file_versions
                 WHERE file_id = :file_id
                 AND version_number = :version_number - 1
                 AND branch = :branch
                 AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                'file_id' => $version['file_id'],
                'version_number' => $version['version_number'],
                'branch' => $version['branch'],
                'tenant_id' => $this->tenantId
            ]);

            $currentId = $stmt->fetchColumn() ?: null;
        }

        return $ancestors;
    }

    /**
     * Compare image metadata
     *
     * @param string $content1 First image content
     * @param string $content2 Second image content
     * @return array Metadata comparison
     */
    private function compareImageMetadata(string $content1, string $content2): array {
        $comparison = [];

        // Get image info for both
        $info1 = @getimagesizefromstring($content1);
        $info2 = @getimagesizefromstring($content2);

        if ($info1 && $info2) {
            $comparison = [
                'dimensions' => [
                    'version1' => ['width' => $info1[0], 'height' => $info1[1]],
                    'version2' => ['width' => $info2[0], 'height' => $info2[1]],
                    'changed' => ($info1[0] !== $info2[0] || $info1[1] !== $info2[1])
                ],
                'mime_type' => [
                    'version1' => $info1['mime'],
                    'version2' => $info2['mime'],
                    'changed' => $info1['mime'] !== $info2['mime']
                ],
                'size' => [
                    'version1' => strlen($content1),
                    'version2' => strlen($content2),
                    'difference' => strlen($content2) - strlen($content1)
                ]
            ];
        }

        return $comparison;
    }

    /**
     * Get diff summary between versions
     *
     * @param int $fileId File ID
     * @param int $version1 First version number
     * @param int $version2 Second version number
     * @return array Diff summary
     */
    private function getDiffSummary(int $fileId, int $version1, int $version2): array {
        $v1Id = $this->getVersionId($fileId, $version1);
        $v2Id = $this->getVersionId($fileId, $version2);

        if (!$v1Id || !$v2Id) {
            return ['error' => 'Version not found'];
        }

        $content1 = $this->getVersionContent($v1Id);
        $content2 = $this->getVersionContent($v2Id);

        $lines1 = substr_count($content1, "\n");
        $lines2 = substr_count($content2, "\n");

        return [
            'additions' => max(0, $lines2 - $lines1),
            'deletions' => max(0, $lines1 - $lines2),
            'total_changes' => abs($lines2 - $lines1)
        ];
    }

    /**
     * Schedule asynchronous processing for large files
     *
     * @param int $versionId Version ID
     * @param int $fileId File ID
     */
    private function scheduleAsyncProcessing(int $versionId, int $fileId): void {
        // Queue job for async processing
        $stmt = $this->pdo->prepare(
            "INSERT INTO version_processing_queue
             (tenant_id, version_id, file_id, status, created_at)
             VALUES (:tenant_id, :version_id, :file_id, 'pending', NOW())"
        );

        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'version_id' => $versionId,
            'file_id' => $fileId
        ]);
    }

    /**
     * Clean orphaned storage files
     */
    private function cleanOrphanedFiles(): void {
        $basePath = $this->config['base_path'] . '/' . $this->tenantId;

        // Get all storage paths from database
        $stmt = $this->pdo->prepare(
            "SELECT storage_path FROM file_versions WHERE tenant_id = :tenant_id"
        );
        $stmt->execute(['tenant_id' => $this->tenantId]);
        $dbPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Scan storage directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getPathname();

                // Check if file is in database
                if (!in_array($filePath, $dbPaths)) {
                    // File is orphaned, delete it
                    unlink($filePath);
                    $this->logVersionEvent('cleanup', 0, ['deleted_file' => $filePath]);
                }
            }
        }
    }

    /**
     * Store version metadata
     *
     * @param int $fileId File ID
     * @param int $versionNumber Version number
     * @param array $metadata Metadata to store
     */
    private function storeVersionMetadata(int $fileId, int $versionNumber, array $metadata): void {
        $metadataPath = $this->config['base_path'] . '/' . $this->tenantId .
                       '/metadata/' . $fileId . '_v' . $versionNumber . '.json';

        $dir = dirname($metadataPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($metadataPath, json_encode($metadata));
    }

    /**
     * Cache metadata for performance
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     */
    private function cacheMetadata(string $key, mixed $data): void {
        // Implement LRU cache
        if (count($this->metadataCache) >= $this->maxCacheSize) {
            // Remove oldest entry
            array_shift($this->metadataCache);
        }

        $this->metadataCache[$key] = $data;
    }

    /**
     * Clear cache for a specific file
     *
     * @param int $fileId File ID
     */
    private function clearFileCache(int $fileId): void {
        foreach (array_keys($this->metadataCache) as $key) {
            if (str_contains($key, "_{$fileId}_")) {
                unset($this->metadataCache[$key]);
            }
        }
    }

    /**
     * Check if version should be kept (daily retention)
     *
     * @param array $version Version info
     * @param array $allVersions All versions
     * @return bool
     */
    private function shouldKeepDaily(array $version, array $allVersions): bool {
        $versionDate = date('Y-m-d', strtotime($version['created_at']));

        // Keep if it's the last version of the day
        foreach ($allVersions as $v) {
            if ($v['id'] !== $version['id'] &&
                date('Y-m-d', strtotime($v['created_at'])) === $versionDate &&
                $v['version_number'] > $version['version_number']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if version should be kept (weekly retention)
     *
     * @param array $version Version info
     * @param array $allVersions All versions
     * @return bool
     */
    private function shouldKeepWeekly(array $version, array $allVersions): bool {
        $versionWeek = date('Y-W', strtotime($version['created_at']));

        // Keep if it's the last version of the week
        foreach ($allVersions as $v) {
            if ($v['id'] !== $version['id'] &&
                date('Y-W', strtotime($v['created_at'])) === $versionWeek &&
                $v['version_number'] > $version['version_number']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if version should be kept (monthly retention)
     *
     * @param array $version Version info
     * @param array $allVersions All versions
     * @return bool
     */
    private function shouldKeepMonthly(array $version, array $allVersions): bool {
        $versionMonth = date('Y-m', strtotime($version['created_at']));

        // Keep if it's the last version of the month
        foreach ($allVersions as $v) {
            if ($v['id'] !== $version['id'] &&
                date('Y-m', strtotime($v['created_at'])) === $versionMonth &&
                $v['version_number'] > $version['version_number']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if version should be kept (yearly retention)
     *
     * @param array $version Version info
     * @param array $allVersions All versions
     * @return bool
     */
    private function shouldKeepYearly(array $version, array $allVersions): bool {
        $versionYear = date('Y', strtotime($version['created_at']));

        // Keep if it's the last version of the year
        foreach ($allVersions as $v) {
            if ($v['id'] !== $version['id'] &&
                date('Y', strtotime($v['created_at'])) === $versionYear &&
                $v['version_number'] > $version['version_number']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format file size for display
     *
     * @param int $bytes Size in bytes
     * @return string Formatted size
     */
    private function formatFileSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);

        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Log version event
     *
     * @param string $action Action performed
     * @param int $versionId Version ID
     * @param array $details Additional details
     */
    private function logVersionEvent(string $action, int $versionId, array $details = []): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO version_audit_log
                 (tenant_id, version_id, action, user_id, details, created_at)
                 VALUES (:tenant_id, :version_id, :action, :user_id, :details, NOW())"
            );

            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'version_id' => $versionId,
                'action' => $action,
                'user_id' => $this->userId,
                'details' => json_encode($details)
            ]);
        } catch (Exception $e) {
            // Log to file if database logging fails
            error_log("Version event logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log error
     *
     * @param string $method Method where error occurred
     * @param string $message Error message
     * @param array $context Additional context
     */
    private function logError(string $method, string $message, array $context = []): void {
        $errorLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'method' => $method,
            'message' => $message,
            'context' => $context
        ];

        error_log(json_encode($errorLog), 3, '/var/log/versioning_errors.log');
    }
}