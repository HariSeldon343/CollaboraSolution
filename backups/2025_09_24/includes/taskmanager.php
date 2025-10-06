<?php
declare(strict_types=1);

/**
 * TaskManager - Sistema completo di gestione task con supporto Kanban
 *
 * Features principali:
 * - Kanban board con colonne personalizzabili
 * - Gestione gerarchica task e subtask
 * - Time tracking e progress monitoring
 * - Assegnazione multipla e watchers
 * - Dipendenze e critical path
 * - Automazioni e notifiche
 * - Multi-tenant con isolamento completo
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

class TaskManager {
    private PDO $pdo;
    private int $tenant_id;
    private int $user_id;
    private array $cache = [];
    private const CACHE_TTL = 300; // 5 minuti

    // Costanti per priorità
    private const PRIORITY_URGENT = 4;
    private const PRIORITY_HIGH = 3;
    private const PRIORITY_MEDIUM = 2;
    private const PRIORITY_LOW = 1;

    // Costanti per stati task
    private const STATUS_PENDING = 'pending';
    private const STATUS_IN_PROGRESS = 'in_progress';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CANCELLED = 'cancelled';
    private const STATUS_ON_HOLD = 'on_hold';

    // Costanti per tipi di dipendenza
    private const DEP_FINISH_TO_START = 'FS'; // Deve finire prima che l'altro inizi
    private const DEP_START_TO_START = 'SS';   // Devono iniziare insieme
    private const DEP_FINISH_TO_FINISH = 'FF'; // Devono finire insieme
    private const DEP_START_TO_FINISH = 'SF';   // Deve iniziare prima che l'altro finisca

    public function __construct(PDO $pdo, int $tenant_id, int $user_id) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    // ========================================
    // KANBAN BOARD LOGIC
    // ========================================

    /**
     * Crea una nuova board Kanban con colonne personalizzate
     */
    public function createBoard(string $name, array $columns, ?string $description = null): int {
        $this->pdo->beginTransaction();

        try {
            // Crea la board
            $stmt = $this->pdo->prepare("
                INSERT INTO task_boards (tenant_id, name, description, created_by, created_at)
                VALUES (:tenant_id, :name, :description, :user_id, NOW())
            ");

            $stmt->execute([
                ':tenant_id' => $this->tenant_id,
                ':name' => $name,
                ':description' => $description,
                ':user_id' => $this->user_id
            ]);

            $boardId = (int)$this->pdo->lastInsertId();

            // Crea le colonne
            $position = 0;
            foreach ($columns as $column) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO board_columns (board_id, tenant_id, name, position, color, wip_limit)
                    VALUES (:board_id, :tenant_id, :name, :position, :color, :wip_limit)
                ");

                $stmt->execute([
                    ':board_id' => $boardId,
                    ':tenant_id' => $this->tenant_id,
                    ':name' => $column['name'],
                    ':position' => $position++,
                    ':color' => $column['color'] ?? '#6B7280',
                    ':wip_limit' => $column['wip_limit'] ?? null
                ]);
            }

            $this->pdo->commit();
            $this->logActivity('board_created', $boardId, ['name' => $name]);

            return $boardId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore creazione board: " . $e->getMessage());
        }
    }

    /**
     * Sposta un task in una colonna diversa con validazione
     */
    public function moveTaskToColumn(int $taskId, int $columnId): bool {
        $this->pdo->beginTransaction();

        try {
            // Verifica permessi e esistenza
            if (!$this->canEditTask($taskId)) {
                throw new Exception("Permessi insufficienti");
            }

            // Verifica WIP limit della colonna di destinazione
            $stmt = $this->pdo->prepare("
                SELECT c.wip_limit, COUNT(t.id) as current_count
                FROM board_columns c
                LEFT JOIN tasks t ON t.column_id = c.id AND t.tenant_id = :tenant_id
                WHERE c.id = :column_id AND c.tenant_id = :tenant_id
                GROUP BY c.id
            ");

            $stmt->execute([
                ':column_id' => $columnId,
                ':tenant_id' => $this->tenant_id
            ]);

            $column = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($column['wip_limit'] && $column['current_count'] >= $column['wip_limit']) {
                throw new Exception("WIP limit raggiunto per questa colonna");
            }

            // Ottieni info task corrente
            $oldColumn = $this->getTaskColumn($taskId);

            // Sposta il task
            $stmt = $this->pdo->prepare("
                UPDATE tasks
                SET column_id = :column_id,
                    position = (SELECT COALESCE(MAX(position), 0) + 1 FROM tasks WHERE column_id = :column_id),
                    updated_at = NOW(),
                    updated_by = :user_id
                WHERE id = :task_id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':column_id' => $columnId,
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $this->user_id
            ]);

            // Triggera workflow automation se configurato
            $this->triggerWorkflowAutomation($taskId, $oldColumn, $columnId);

            // Notifica watchers
            $this->notifyWatchers($taskId, 'column_changed', [
                'old_column' => $oldColumn,
                'new_column' => $columnId
            ]);

            $this->pdo->commit();
            $this->invalidateCache('board_' . $this->getTaskBoard($taskId));

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore spostamento task: " . $e->getMessage());
        }
    }

    /**
     * Riordina i task all'interno di una colonna
     */
    public function reorderTasksInColumn(int $columnId, array $taskIds): bool {
        $this->pdo->beginTransaction();

        try {
            $position = 0;
            foreach ($taskIds as $taskId) {
                $stmt = $this->pdo->prepare("
                    UPDATE tasks
                    SET position = :position
                    WHERE id = :task_id
                    AND column_id = :column_id
                    AND tenant_id = :tenant_id
                ");

                $stmt->execute([
                    ':position' => $position++,
                    ':task_id' => $taskId,
                    ':column_id' => $columnId,
                    ':tenant_id' => $this->tenant_id
                ]);
            }

            $this->pdo->commit();
            $this->invalidateCache('column_' . $columnId);

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore riordinamento task: " . $e->getMessage());
        }
    }

    /**
     * Ottiene una board con tutte le colonne e i task
     */
    public function getBoard(int $boardId): array {
        $cacheKey = "board_{$boardId}_{$this->tenant_id}";

        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        // Board info
        $stmt = $this->pdo->prepare("
            SELECT b.*, u.name as creator_name
            FROM task_boards b
            LEFT JOIN users u ON b.created_by = u.id
            WHERE b.id = :board_id AND b.tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':tenant_id' => $this->tenant_id
        ]);

        $board = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$board) {
            throw new Exception("Board non trovata");
        }

        // Colonne e task
        $stmt = $this->pdo->prepare("
            SELECT c.*,
                   t.id as task_id, t.title, t.description, t.priority,
                   t.due_date, t.status, t.position as task_position,
                   t.estimated_hours, t.progress_percentage,
                   GROUP_CONCAT(DISTINCT u.name) as assignees
            FROM board_columns c
            LEFT JOIN tasks t ON t.column_id = c.id AND t.tenant_id = :tenant_id
            LEFT JOIN task_assignees ta ON ta.task_id = t.id
            LEFT JOIN users u ON ta.user_id = u.id
            WHERE c.board_id = :board_id AND c.tenant_id = :tenant_id
            GROUP BY c.id, t.id
            ORDER BY c.position, t.position
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':tenant_id' => $this->tenant_id
        ]);

        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columnId = $row['id'];

            if (!isset($columns[$columnId])) {
                $columns[$columnId] = [
                    'id' => $columnId,
                    'name' => $row['name'],
                    'position' => $row['position'],
                    'color' => $row['color'],
                    'wip_limit' => $row['wip_limit'],
                    'tasks' => []
                ];
            }

            if ($row['task_id']) {
                $columns[$columnId]['tasks'][] = [
                    'id' => $row['task_id'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'priority' => $row['priority'],
                    'due_date' => $row['due_date'],
                    'status' => $row['status'],
                    'position' => $row['task_position'],
                    'estimated_hours' => $row['estimated_hours'],
                    'progress' => $row['progress_percentage'],
                    'assignees' => $row['assignees'] ? explode(',', $row['assignees']) : []
                ];
            }
        }

        $board['columns'] = array_values($columns);

        $this->setCache($cacheKey, $board);

        return $board;
    }

    /**
     * Personalizza il workflow definendo transizioni tra colonne
     */
    public function customizeWorkflow(int $boardId, array $workflow): bool {
        $this->pdo->beginTransaction();

        try {
            // Rimuovi workflow esistente
            $stmt = $this->pdo->prepare("
                DELETE FROM board_workflows
                WHERE board_id = :board_id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':board_id' => $boardId,
                ':tenant_id' => $this->tenant_id
            ]);

            // Inserisci nuovo workflow
            foreach ($workflow as $rule) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO board_workflows
                    (board_id, tenant_id, from_column_id, to_column_id,
                     allowed_roles, auto_assign, conditions)
                    VALUES
                    (:board_id, :tenant_id, :from_column, :to_column,
                     :allowed_roles, :auto_assign, :conditions)
                ");

                $stmt->execute([
                    ':board_id' => $boardId,
                    ':tenant_id' => $this->tenant_id,
                    ':from_column' => $rule['from_column'],
                    ':to_column' => $rule['to_column'],
                    ':allowed_roles' => json_encode($rule['allowed_roles'] ?? []),
                    ':auto_assign' => $rule['auto_assign'] ?? false,
                    ':conditions' => json_encode($rule['conditions'] ?? [])
                ]);
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore configurazione workflow: " . $e->getMessage());
        }
    }

    // ========================================
    // TASK MANAGEMENT
    // ========================================

    /**
     * Crea un nuovo task con validazione
     */
    public function createTask(array $data): int {
        $this->pdo->beginTransaction();

        try {
            // Validazione input
            $this->validateTaskData($data);

            // Inserisci task
            $stmt = $this->pdo->prepare("
                INSERT INTO tasks
                (tenant_id, board_id, column_id, title, description,
                 priority, due_date, estimated_hours, created_by,
                 parent_task_id, status, created_at)
                VALUES
                (:tenant_id, :board_id, :column_id, :title, :description,
                 :priority, :due_date, :estimated_hours, :created_by,
                 :parent_id, :status, NOW())
            ");

            $stmt->execute([
                ':tenant_id' => $this->tenant_id,
                ':board_id' => $data['board_id'],
                ':column_id' => $data['column_id'],
                ':title' => $data['title'],
                ':description' => $data['description'] ?? null,
                ':priority' => $data['priority'] ?? self::PRIORITY_MEDIUM,
                ':due_date' => $data['due_date'] ?? null,
                ':estimated_hours' => $data['estimated_hours'] ?? null,
                ':created_by' => $this->user_id,
                ':parent_id' => $data['parent_task_id'] ?? null,
                ':status' => self::STATUS_PENDING
            ]);

            $taskId = (int)$this->pdo->lastInsertId();

            // Assegna utenti se specificati
            if (!empty($data['assignees'])) {
                $this->assignToUsers($taskId, $data['assignees']);
            }

            // Aggiungi tag se specificati
            if (!empty($data['tags'])) {
                $this->addTags($taskId, $data['tags']);
            }

            // Applica auto-assignment rules se configurate
            if ($data['auto_assign'] ?? false) {
                $this->autoAssignTask($taskId);
            }

            // Notifica creazione
            $this->notifyTaskCreation($taskId);

            $this->pdo->commit();
            $this->logActivity('task_created', $taskId, $data);

            return $taskId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore creazione task: " . $e->getMessage());
        }
    }

    /**
     * Aggiorna un task con tracking delle modifiche
     */
    public function updateTask(int $taskId, array $data): bool {
        $this->pdo->beginTransaction();

        try {
            if (!$this->canEditTask($taskId)) {
                throw new Exception("Permessi insufficienti per modificare il task");
            }

            // Ottieni stato precedente per confronto
            $oldTask = $this->getTask($taskId);

            // Costruisci query dinamica
            $updates = [];
            $params = [':task_id' => $taskId, ':tenant_id' => $this->tenant_id];

            $allowedFields = [
                'title', 'description', 'priority', 'due_date',
                'estimated_hours', 'status', 'progress_percentage'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($updates)) {
                return true; // Nessuna modifica
            }

            $updates[] = "updated_at = NOW()";
            $updates[] = "updated_by = :updated_by";
            $params[':updated_by'] = $this->user_id;

            $sql = "UPDATE tasks SET " . implode(', ', $updates) .
                   " WHERE id = :task_id AND tenant_id = :tenant_id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            // Traccia le modifiche
            $this->trackChanges($taskId, $oldTask, $data);

            // Notifica watchers delle modifiche
            $this->notifyWatchers($taskId, 'task_updated', [
                'changes' => array_diff_assoc($data, $oldTask)
            ]);

            $this->pdo->commit();
            $this->invalidateCache('task_' . $taskId);

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore aggiornamento task: " . $e->getMessage());
        }
    }

    /**
     * Elimina un task (soft delete) gestendo i subtask
     */
    public function deleteTask(int $taskId): bool {
        $this->pdo->beginTransaction();

        try {
            if (!$this->canDeleteTask($taskId)) {
                throw new Exception("Permessi insufficienti per eliminare il task");
            }

            // Verifica dipendenze
            if ($this->hasActiveDependencies($taskId)) {
                throw new Exception("Impossibile eliminare: il task ha dipendenze attive");
            }

            // Gestisci subtask
            $stmt = $this->pdo->prepare("
                UPDATE tasks
                SET parent_task_id = NULL
                WHERE parent_task_id = :task_id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id
            ]);

            // Soft delete
            $stmt = $this->pdo->prepare("
                UPDATE tasks
                SET deleted_at = NOW(), deleted_by = :user_id
                WHERE id = :task_id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $this->user_id
            ]);

            $this->pdo->commit();
            $this->logActivity('task_deleted', $taskId);

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore eliminazione task: " . $e->getMessage());
        }
    }

    /**
     * Crea un subtask gerarchico
     */
    public function createSubtask(int $parentId, array $data): int {
        // Verifica che il parent esista
        if (!$this->taskExists($parentId)) {
            throw new Exception("Task padre non trovato");
        }

        // Eredita alcune proprietà dal parent
        $parent = $this->getTask($parentId);
        $data['parent_task_id'] = $parentId;
        $data['board_id'] = $parent['board_id'];
        $data['column_id'] = $parent['column_id'];

        // Priorità non può essere superiore al parent
        if (($data['priority'] ?? 0) > $parent['priority']) {
            $data['priority'] = $parent['priority'];
        }

        return $this->createTask($data);
    }

    /**
     * Ottiene l'albero completo di un task con tutti i subtask ricorsivamente
     */
    public function getTaskTree(int $taskId, int $maxDepth = 5): array {
        $task = $this->getTask($taskId);

        if (!$task) {
            throw new Exception("Task non trovato");
        }

        $task['subtasks'] = $this->getSubtasksRecursive($taskId, 1, $maxDepth);
        $task['total_subtasks'] = $this->countSubtasks($taskId);
        $task['completed_subtasks'] = $this->countCompletedSubtasks($taskId);

        return $task;
    }

    // ========================================
    // PRIORITY & SCHEDULING
    // ========================================

    /**
     * Imposta la priorità di un task
     */
    public function setPriority(int $taskId, int $priority): bool {
        if ($priority < 1 || $priority > 4) {
            throw new Exception("Priorità non valida (1-4)");
        }

        $stmt = $this->pdo->prepare("
            UPDATE tasks
            SET priority = :priority, updated_at = NOW()
            WHERE id = :task_id AND tenant_id = :tenant_id
        ");

        $success = $stmt->execute([
            ':priority' => $priority,
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        if ($success && $priority == self::PRIORITY_URGENT) {
            $this->notifyUrgentTask($taskId);
        }

        return $success;
    }

    /**
     * Imposta la data di scadenza con gestione timezone
     */
    public function setDueDate(int $taskId, string $dueDate, string $timezone = 'UTC'): bool {
        try {
            $dt = new DateTime($dueDate, new DateTimeZone($timezone));
            $dt->setTimezone(new DateTimeZone('UTC'));

            $stmt = $this->pdo->prepare("
                UPDATE tasks
                SET due_date = :due_date, updated_at = NOW()
                WHERE id = :task_id AND tenant_id = :tenant_id
            ");

            $success = $stmt->execute([
                ':due_date' => $dt->format('Y-m-d H:i:s'),
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id
            ]);

            // Programma reminder se vicino alla scadenza
            $this->scheduleDeadlineReminder($taskId, $dt);

            return $success;

        } catch (Exception $e) {
            throw new Exception("Data non valida: " . $e->getMessage());
        }
    }

    /**
     * Ottiene tutti i task scaduti
     */
    public function getOverdueTasks(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   GROUP_CONCAT(DISTINCT u.name) as assignees,
                   DATEDIFF(NOW(), t.due_date) as days_overdue
            FROM tasks t
            LEFT JOIN task_assignees ta ON ta.task_id = t.id
            LEFT JOIN users u ON ta.user_id = u.id
            WHERE t.tenant_id = :tenant_id
            AND t.due_date < NOW()
            AND t.status NOT IN ('completed', 'cancelled')
            AND t.deleted_at IS NULL
            GROUP BY t.id
            ORDER BY t.priority DESC, days_overdue DESC
        ");

        $stmt->execute([':tenant_id' => $this->tenant_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filtra task per priorità
     */
    public function getTasksByPriority(int $priority): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, b.name as board_name, c.name as column_name
            FROM tasks t
            LEFT JOIN task_boards b ON t.board_id = b.id
            LEFT JOIN board_columns c ON t.column_id = c.id
            WHERE t.tenant_id = :tenant_id
            AND t.priority = :priority
            AND t.deleted_at IS NULL
            ORDER BY t.due_date ASC NULLS LAST
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':priority' => $priority
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stima il tempo di completamento basandosi su dati storici
     */
    public function estimateCompletion(int $taskId): array {
        $task = $this->getTask($taskId);

        // Ottieni metriche storiche per task simili
        $stmt = $this->pdo->prepare("
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_hours,
                   STD(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as std_hours
            FROM tasks
            WHERE tenant_id = :tenant_id
            AND priority = :priority
            AND status = 'completed'
            AND completed_at IS NOT NULL
            AND estimated_hours BETWEEN :est_min AND :est_max
        ");

        $estimatedHours = $task['estimated_hours'] ?? 8;

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':priority' => $task['priority'],
            ':est_min' => $estimatedHours * 0.5,
            ':est_max' => $estimatedHours * 1.5
        ]);

        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $estimate = [
            'optimistic' => $estimatedHours * 0.8,
            'realistic' => $stats['avg_hours'] ?? $estimatedHours,
            'pessimistic' => ($stats['avg_hours'] ?? $estimatedHours) + ($stats['std_hours'] ?? 0),
            'confidence' => $stats['avg_hours'] ? 'high' : 'low',
            'based_on' => $stats['avg_hours'] ? 'historical_data' : 'estimation'
        ];

        // Calcola data di completamento prevista
        $hoursPerDay = 8;
        $daysNeeded = ceil($estimate['realistic'] / $hoursPerDay);
        $estimate['expected_completion'] = date('Y-m-d', strtotime("+$daysNeeded days"));

        return $estimate;
    }

    // ========================================
    // ASSIGNMENT & WATCHERS
    // ========================================

    /**
     * Assegna un task a più utenti
     */
    public function assignToUsers(int $taskId, array $userIds): bool {
        $this->pdo->beginTransaction();

        try {
            // Rimuovi assegnazioni esistenti
            $stmt = $this->pdo->prepare("
                DELETE FROM task_assignees
                WHERE task_id = :task_id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id
            ]);

            // Aggiungi nuove assegnazioni
            $stmt = $this->pdo->prepare("
                INSERT INTO task_assignees (task_id, user_id, tenant_id, assigned_at, assigned_by)
                VALUES (:task_id, :user_id, :tenant_id, NOW(), :assigned_by)
            ");

            foreach ($userIds as $userId) {
                // Verifica che l'utente appartenga al tenant
                if (!$this->userBelongsToTenant($userId)) {
                    continue;
                }

                $stmt->execute([
                    ':task_id' => $taskId,
                    ':user_id' => $userId,
                    ':tenant_id' => $this->tenant_id,
                    ':assigned_by' => $this->user_id
                ]);

                // Notifica l'utente assegnato
                $this->notifyAssignment($taskId, $userId);
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore assegnazione utenti: " . $e->getMessage());
        }
    }

    /**
     * Aggiunge un watcher (follower) a un task
     */
    public function addWatcher(int $taskId, int $userId, string $reason = ''): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO task_watchers (task_id, user_id, tenant_id, reason, added_at)
            VALUES (:task_id, :user_id, :tenant_id, :reason, NOW())
            ON DUPLICATE KEY UPDATE reason = :reason, added_at = NOW()
        ");

        return $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id,
            ':reason' => $reason
        ]);
    }

    /**
     * Rimuove un assegnatario con notifica
     */
    public function removeAssignee(int $taskId, int $userId): bool {
        $stmt = $this->pdo->prepare("
            DELETE FROM task_assignees
            WHERE task_id = :task_id
            AND user_id = :user_id
            AND tenant_id = :tenant_id
        ");

        $success = $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        if ($success) {
            $this->notifyUnassignment($taskId, $userId);
        }

        return $success;
    }

    /**
     * Ottiene i task assegnati a un utente
     */
    public function getMyTasks(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, b.name as board_name, c.name as column_name,
                   GROUP_CONCAT(DISTINCT u.name) as other_assignees
            FROM tasks t
            INNER JOIN task_assignees ta ON ta.task_id = t.id
            LEFT JOIN task_boards b ON t.board_id = b.id
            LEFT JOIN board_columns c ON t.column_id = c.id
            LEFT JOIN task_assignees ta2 ON ta2.task_id = t.id AND ta2.user_id != :user_id
            LEFT JOIN users u ON ta2.user_id = u.id
            WHERE ta.user_id = :user_id
            AND t.tenant_id = :tenant_id
            AND t.deleted_at IS NULL
            AND t.status NOT IN ('completed', 'cancelled')
            GROUP BY t.id
            ORDER BY t.priority DESC, t.due_date ASC
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ottiene i task che un utente sta seguendo
     */
    public function getWatchedTasks(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, w.reason as watch_reason, b.name as board_name
            FROM tasks t
            INNER JOIN task_watchers w ON w.task_id = t.id
            LEFT JOIN task_boards b ON t.board_id = b.id
            WHERE w.user_id = :user_id
            AND t.tenant_id = :tenant_id
            AND t.deleted_at IS NULL
            ORDER BY t.updated_at DESC
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ========================================
    // TIME TRACKING
    // ========================================

    /**
     * Avvia il timer per un task
     */
    public function startTimer(int $taskId, int $userId): int {
        // Verifica timer attivi
        $stmt = $this->pdo->prepare("
            SELECT id FROM time_entries
            WHERE user_id = :user_id
            AND tenant_id = :tenant_id
            AND end_time IS NULL
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        if ($stmt->fetch()) {
            throw new Exception("Hai già un timer attivo");
        }

        // Avvia nuovo timer
        $stmt = $this->pdo->prepare("
            INSERT INTO time_entries (task_id, user_id, tenant_id, start_time)
            VALUES (:task_id, :user_id, :tenant_id, NOW())
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Ferma il timer e registra il tempo
     */
    public function stopTimer(int $taskId, int $userId): array {
        $stmt = $this->pdo->prepare("
            UPDATE time_entries
            SET end_time = NOW(),
                duration_minutes = TIMESTAMPDIFF(MINUTE, start_time, NOW())
            WHERE task_id = :task_id
            AND user_id = :user_id
            AND tenant_id = :tenant_id
            AND end_time IS NULL
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        // Ritorna il tempo registrato
        $stmt = $this->pdo->prepare("
            SELECT duration_minutes, start_time, end_time
            FROM time_entries
            WHERE task_id = :task_id
            AND user_id = :user_id
            AND tenant_id = :tenant_id
            ORDER BY end_time DESC
            LIMIT 1
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Registra tempo manualmente
     */
    public function logTime(int $taskId, float $hours, string $description = ''): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO time_entries
            (task_id, user_id, tenant_id, start_time, end_time,
             duration_minutes, description, is_manual)
            VALUES
            (:task_id, :user_id, :tenant_id, NOW(), NOW(),
             :minutes, :description, 1)
        ");

        return $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $this->user_id,
            ':tenant_id' => $this->tenant_id,
            ':minutes' => $hours * 60,
            ':description' => $description
        ]);
    }

    /**
     * Ottiene tutte le registrazioni di tempo per un task
     */
    public function getTimeEntries(int $taskId): array {
        $stmt = $this->pdo->prepare("
            SELECT te.*, u.name as user_name
            FROM time_entries te
            LEFT JOIN users u ON te.user_id = u.id
            WHERE te.task_id = :task_id
            AND te.tenant_id = :tenant_id
            ORDER BY te.start_time DESC
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calcola il tempo totale includendo i subtask
     */
    public function calculateTotalTime(int $taskId, bool $includeSubtasks = true): array {
        $sql = "
            SELECT
                SUM(duration_minutes) as total_minutes,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(*) as entry_count,
                MIN(start_time) as first_entry,
                MAX(end_time) as last_entry
            FROM time_entries
            WHERE tenant_id = :tenant_id
        ";

        if ($includeSubtasks) {
            $sql .= " AND task_id IN (
                WITH RECURSIVE task_tree AS (
                    SELECT id FROM tasks WHERE id = :task_id
                    UNION ALL
                    SELECT t.id FROM tasks t
                    JOIN task_tree tt ON t.parent_task_id = tt.id
                )
                SELECT id FROM task_tree
            )";
        } else {
            $sql .= " AND task_id = :task_id";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_hours' => round(($result['total_minutes'] ?? 0) / 60, 2),
            'total_minutes' => $result['total_minutes'] ?? 0,
            'unique_users' => $result['unique_users'] ?? 0,
            'entry_count' => $result['entry_count'] ?? 0,
            'first_entry' => $result['first_entry'],
            'last_entry' => $result['last_entry']
        ];
    }

    // ========================================
    // PROGRESS MANAGEMENT
    // ========================================

    /**
     * Aggiorna manualmente la percentuale di progresso
     */
    public function updateProgress(int $taskId, int $percentage): bool {
        if ($percentage < 0 || $percentage > 100) {
            throw new Exception("Percentuale non valida (0-100)");
        }

        $stmt = $this->pdo->prepare("
            UPDATE tasks
            SET progress_percentage = :percentage,
                status = CASE
                    WHEN :percentage = 100 THEN 'completed'
                    WHEN :percentage > 0 THEN 'in_progress'
                    ELSE status
                END,
                completed_at = CASE
                    WHEN :percentage = 100 THEN NOW()
                    ELSE NULL
                END,
                updated_at = NOW()
            WHERE id = :task_id AND tenant_id = :tenant_id
        ");

        $success = $stmt->execute([
            ':percentage' => $percentage,
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        // Aggiorna il progresso del parent se necessario
        $this->updateParentProgress($taskId);

        return $success;
    }

    /**
     * Calcola automaticamente il progresso dai subtask
     */
    public function calculateProgressFromSubtasks(int $taskId): int {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                AVG(progress_percentage) as avg_progress
            FROM tasks
            WHERE parent_task_id = :task_id
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['total'] == 0) {
            return 0;
        }

        // Usa una media ponderata tra completati e progresso medio
        $completionRate = ($result['completed'] / $result['total']) * 100;
        $avgProgress = $result['avg_progress'] ?? 0;

        return (int)round(($completionRate + $avgProgress) / 2);
    }

    /**
     * Marca un task come completato con validazione
     */
    public function markAsComplete(int $taskId): bool {
        $this->pdo->beginTransaction();

        try {
            // Verifica che tutti i subtask siano completati
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as incomplete
                FROM tasks
                WHERE parent_task_id = :task_id
                AND tenant_id = :tenant_id
                AND status != 'completed'
                AND deleted_at IS NULL
            ");

            $stmt->execute([
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['incomplete'] > 0) {
                throw new Exception("Impossibile completare: ci sono subtask non completati");
            }

            // Completa il task
            $stmt = $this->pdo->prepare("
                UPDATE tasks
                SET status = 'completed',
                    progress_percentage = 100,
                    completed_at = NOW(),
                    completed_by = :user_id,
                    updated_at = NOW()
                WHERE id = :task_id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':task_id' => $taskId,
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $this->user_id
            ]);

            // Notifica completamento
            $this->notifyCompletion($taskId);

            // Triggera task dipendenti
            $this->triggerDependentTasks($taskId);

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Errore completamento task: " . $e->getMessage());
        }
    }

    /**
     * Riapre un task completato
     */
    public function reopenTask(int $taskId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE tasks
            SET status = 'in_progress',
                progress_percentage = 50,
                completed_at = NULL,
                completed_by = NULL,
                updated_at = NOW()
            WHERE id = :task_id
            AND tenant_id = :tenant_id
            AND status = 'completed'
        ");

        $success = $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        if ($success) {
            $this->notifyWatchers($taskId, 'task_reopened', []);
        }

        return $success;
    }

    // ========================================
    // TASK DEPENDENCIES
    // ========================================

    /**
     * Aggiunge una dipendenza tra task
     */
    public function addDependency(int $taskId, int $dependsOn, string $type = self::DEP_FINISH_TO_START): bool {
        // Verifica cicli di dipendenza
        if ($this->wouldCreateCycle($taskId, $dependsOn)) {
            throw new Exception("Impossibile creare dipendenza: creerebbe un ciclo");
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO task_dependencies
            (task_id, depends_on_id, tenant_id, dependency_type, created_at)
            VALUES
            (:task_id, :depends_on, :tenant_id, :type, NOW())
        ");

        return $stmt->execute([
            ':task_id' => $taskId,
            ':depends_on' => $dependsOn,
            ':tenant_id' => $this->tenant_id,
            ':type' => $type
        ]);
    }

    /**
     * Rimuove una dipendenza
     */
    public function removeDependency(int $taskId, int $dependencyId): bool {
        $stmt = $this->pdo->prepare("
            DELETE FROM task_dependencies
            WHERE task_id = :task_id
            AND id = :dep_id
            AND tenant_id = :tenant_id
        ");

        return $stmt->execute([
            ':task_id' => $taskId,
            ':dep_id' => $dependencyId,
            ':tenant_id' => $this->tenant_id
        ]);
    }

    /**
     * Verifica se un task può iniziare basandosi sulle dipendenze
     */
    public function canStartTask(int $taskId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                d.*,
                t.title as depends_on_title,
                t.status as depends_on_status,
                t.progress_percentage
            FROM task_dependencies d
            JOIN tasks t ON d.depends_on_id = t.id
            WHERE d.task_id = :task_id
            AND d.tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        $blockers = [];
        $canStart = true;

        while ($dep = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $satisfied = false;

            switch ($dep['dependency_type']) {
                case self::DEP_FINISH_TO_START:
                    $satisfied = ($dep['depends_on_status'] == 'completed');
                    break;

                case self::DEP_START_TO_START:
                    $satisfied = ($dep['depends_on_status'] != 'pending');
                    break;

                case self::DEP_FINISH_TO_FINISH:
                    // Può iniziare ma non può finire prima
                    $satisfied = true;
                    break;

                case self::DEP_START_TO_FINISH:
                    $satisfied = ($dep['depends_on_status'] != 'pending');
                    break;
            }

            if (!$satisfied) {
                $canStart = false;
                $blockers[] = [
                    'task_id' => $dep['depends_on_id'],
                    'title' => $dep['depends_on_title'],
                    'type' => $dep['dependency_type'],
                    'status' => $dep['depends_on_status']
                ];
            }
        }

        return [
            'can_start' => $canStart,
            'blockers' => $blockers
        ];
    }

    /**
     * Calcola il percorso critico di un progetto
     */
    public function getCriticalPath(int $projectId): array {
        // Implementazione semplificata del Critical Path Method (CPM)
        $tasks = $this->getProjectTasks($projectId);
        $dependencies = $this->getProjectDependencies($projectId);

        // Calcola Early Start (ES) e Early Finish (EF)
        $forward = [];
        foreach ($tasks as $task) {
            $es = 0;
            foreach ($dependencies[$task['id']] ?? [] as $dep) {
                if (isset($forward[$dep['depends_on_id']])) {
                    $es = max($es, $forward[$dep['depends_on_id']]['ef']);
                }
            }

            $forward[$task['id']] = [
                'es' => $es,
                'ef' => $es + ($task['estimated_hours'] ?? 0)
            ];
        }

        // Calcola Late Start (LS) e Late Finish (LF)
        $projectEnd = max(array_column($forward, 'ef'));
        $backward = [];

        foreach (array_reverse($tasks) as $task) {
            $lf = $projectEnd;

            // Trova task che dipendono da questo
            foreach ($dependencies as $depTaskId => $deps) {
                foreach ($deps as $dep) {
                    if ($dep['depends_on_id'] == $task['id'] && isset($backward[$depTaskId])) {
                        $lf = min($lf, $backward[$depTaskId]['ls']);
                    }
                }
            }

            $backward[$task['id']] = [
                'lf' => $lf,
                'ls' => $lf - ($task['estimated_hours'] ?? 0)
            ];
        }

        // Identifica il percorso critico (slack = 0)
        $criticalPath = [];
        foreach ($tasks as $task) {
            $slack = $backward[$task['id']]['ls'] - $forward[$task['id']]['es'];

            if (abs($slack) < 0.01) { // Tolleranza per floating point
                $criticalPath[] = [
                    'task_id' => $task['id'],
                    'title' => $task['title'],
                    'duration' => $task['estimated_hours'] ?? 0,
                    'early_start' => $forward[$task['id']]['es'],
                    'early_finish' => $forward[$task['id']]['ef'],
                    'late_start' => $backward[$task['id']]['ls'],
                    'late_finish' => $backward[$task['id']]['lf'],
                    'slack' => $slack
                ];
            }
        }

        return [
            'critical_path' => $criticalPath,
            'project_duration' => $projectEnd,
            'total_tasks' => count($tasks),
            'critical_tasks' => count($criticalPath)
        ];
    }

    /**
     * Ottiene la catena completa di dipendenze
     */
    public function getDependencyChain(int $taskId): array {
        $visited = [];
        $chain = [];

        $this->traceDependencies($taskId, $chain, $visited);

        return $chain;
    }

    // ========================================
    // AUTOMATION FEATURES
    // ========================================

    /**
     * Definisce una regola di assegnazione automatica
     */
    public function defineAssignmentRule(array $rule): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO assignment_rules
            (tenant_id, name, conditions, actions, priority, is_active, created_by)
            VALUES
            (:tenant_id, :name, :conditions, :actions, :priority, 1, :user_id)
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':name' => $rule['name'],
            ':conditions' => json_encode($rule['conditions']),
            ':actions' => json_encode($rule['actions']),
            ':priority' => $rule['priority'] ?? 1,
            ':user_id' => $this->user_id
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Applica regole di assegnazione automatica a un task
     */
    public function autoAssignTask(int $taskId): bool {
        $task = $this->getTask($taskId);

        // Ottieni regole applicabili
        $stmt = $this->pdo->prepare("
            SELECT * FROM assignment_rules
            WHERE tenant_id = :tenant_id AND is_active = 1
            ORDER BY priority DESC
        ");

        $stmt->execute([':tenant_id' => $this->tenant_id]);

        while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $conditions = json_decode($rule['conditions'], true);
            $actions = json_decode($rule['actions'], true);

            // Valuta condizioni
            if ($this->evaluateConditions($task, $conditions)) {
                // Esegui azioni
                $this->executeActions($taskId, $actions);
                return true;
            }
        }

        // Fallback: assegnazione round-robin
        return $this->roundRobinAssign($task['board_id']);
    }

    /**
     * Bilancia il carico di lavoro del team
     */
    public function balanceWorkload(int $teamId): array {
        // Ottieni membri del team e loro carico attuale
        $stmt = $this->pdo->prepare("
            SELECT
                u.id, u.name,
                COUNT(ta.task_id) as assigned_tasks,
                SUM(t.estimated_hours) as total_hours,
                AVG(t.priority) as avg_priority
            FROM users u
            LEFT JOIN task_assignees ta ON ta.user_id = u.id
            LEFT JOIN tasks t ON ta.task_id = t.id AND t.status NOT IN ('completed', 'cancelled')
            WHERE u.team_id = :team_id AND u.tenant_id = :tenant_id
            GROUP BY u.id
            ORDER BY total_hours ASC
        ");

        $stmt->execute([
            ':team_id' => $teamId,
            ':tenant_id' => $this->tenant_id
        ]);

        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcola carico medio
        $totalHours = array_sum(array_column($members, 'total_hours'));
        $avgHours = $totalHours / count($members);

        $reassignments = [];

        // Identifica membri sovraccarichi e sottoutilizzati
        foreach ($members as $member) {
            $deviation = $member['total_hours'] - $avgHours;

            if (abs($deviation) > 8) { // Più di una giornata di differenza
                $reassignments[] = [
                    'user_id' => $member['id'],
                    'user_name' => $member['name'],
                    'current_hours' => $member['total_hours'],
                    'target_hours' => $avgHours,
                    'action' => $deviation > 0 ? 'reduce' : 'increase'
                ];
            }
        }

        return [
            'team_size' => count($members),
            'total_hours' => $totalHours,
            'average_hours' => $avgHours,
            'reassignments_needed' => $reassignments
        ];
    }

    /**
     * Assegna task basandosi sulle competenze
     */
    public function assignBySkills(int $taskId, array $requiredSkills): ?int {
        $skillsPlaceholders = implode(',', array_fill(0, count($requiredSkills), '?'));

        $stmt = $this->pdo->prepare("
            SELECT
                u.id,
                COUNT(DISTINCT us.skill_id) as matching_skills,
                COUNT(DISTINCT ta.task_id) as current_tasks
            FROM users u
            JOIN user_skills us ON us.user_id = u.id
            JOIN skills s ON us.skill_id = s.id
            LEFT JOIN task_assignees ta ON ta.user_id = u.id
            LEFT JOIN tasks t ON ta.task_id = t.id AND t.status NOT IN ('completed', 'cancelled')
            WHERE u.tenant_id = :tenant_id
            AND u.is_active = 1
            AND s.name IN ($skillsPlaceholders)
            GROUP BY u.id
            ORDER BY matching_skills DESC, current_tasks ASC
            LIMIT 1
        ");

        $params = array_merge([$this->tenant_id], $requiredSkills);
        $stmt->execute($params);

        $bestMatch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bestMatch) {
            $this->assignToUsers($taskId, [$bestMatch['id']]);
            return $bestMatch['id'];
        }

        return null;
    }

    /**
     * Assegnazione round-robin per distribuire equamente
     */
    public function roundRobinAssign(int $boardId): bool {
        // Ottieni l'ultimo utente assegnato per questa board
        $stmt = $this->pdo->prepare("
            SELECT last_assigned_user_id
            FROM board_assignment_state
            WHERE board_id = :board_id AND tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':tenant_id' => $this->tenant_id
        ]);

        $lastUserId = $stmt->fetchColumn();

        // Ottieni prossimo utente disponibile
        $stmt = $this->pdo->prepare("
            SELECT u.id
            FROM users u
            JOIN board_members bm ON bm.user_id = u.id
            WHERE bm.board_id = :board_id
            AND u.tenant_id = :tenant_id
            AND u.is_active = 1
            AND u.id > :last_id
            ORDER BY u.id ASC
            LIMIT 1
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':tenant_id' => $this->tenant_id,
            ':last_id' => $lastUserId ?? 0
        ]);

        $nextUser = $stmt->fetchColumn();

        if (!$nextUser) {
            // Ricomincia dal primo
            $stmt = $this->pdo->prepare("
                SELECT MIN(u.id)
                FROM users u
                JOIN board_members bm ON bm.user_id = u.id
                WHERE bm.board_id = :board_id
                AND u.tenant_id = :tenant_id
                AND u.is_active = 1
            ");

            $stmt->execute([
                ':board_id' => $boardId,
                ':tenant_id' => $this->tenant_id
            ]);

            $nextUser = $stmt->fetchColumn();
        }

        if ($nextUser) {
            // Aggiorna stato
            $stmt = $this->pdo->prepare("
                INSERT INTO board_assignment_state (board_id, tenant_id, last_assigned_user_id)
                VALUES (:board_id, :tenant_id, :user_id)
                ON DUPLICATE KEY UPDATE last_assigned_user_id = :user_id
            ");

            $stmt->execute([
                ':board_id' => $boardId,
                ':tenant_id' => $this->tenant_id,
                ':user_id' => $nextUser
            ]);

            return true;
        }

        return false;
    }

    // ========================================
    // NOTIFICATIONS & ESCALATION
    // ========================================

    /**
     * Controlla scadenze imminenti (per cron job)
     */
    public function checkUpcomingDeadlines(): array {
        $notifications = [];

        // Task con scadenza entro 24 ore
        $stmt = $this->pdo->prepare("
            SELECT t.*, GROUP_CONCAT(u.email) as assignee_emails
            FROM tasks t
            LEFT JOIN task_assignees ta ON ta.task_id = t.id
            LEFT JOIN users u ON ta.user_id = u.id
            WHERE t.tenant_id = :tenant_id
            AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
            AND t.status NOT IN ('completed', 'cancelled')
            AND t.deadline_reminder_sent = 0
            GROUP BY t.id
        ");

        $stmt->execute([':tenant_id' => $this->tenant_id]);

        while ($task = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->sendDeadlineReminder($task['id'], 1);
            $notifications[] = [
                'task_id' => $task['id'],
                'title' => $task['title'],
                'type' => '24h_reminder',
                'recipients' => $task['assignee_emails']
            ];
        }

        // Task scaduti
        $overdueNotifications = $this->checkAndEscalateOverdue();
        $notifications = array_merge($notifications, $overdueNotifications);

        return $notifications;
    }

    /**
     * Invia reminder per scadenza
     */
    public function sendDeadlineReminder(int $taskId, int $daysBefore): bool {
        $task = $this->getTask($taskId);

        // Prepara notifica
        $notification = [
            'type' => 'deadline_reminder',
            'task_id' => $taskId,
            'task_title' => $task['title'],
            'due_date' => $task['due_date'],
            'days_before' => $daysBefore,
            'priority' => $task['priority']
        ];

        // Invia a tutti gli assegnatari e watchers
        $recipients = array_merge(
            $this->getTaskAssignees($taskId),
            $this->getTaskWatchers($taskId)
        );

        foreach ($recipients as $recipient) {
            $this->sendNotification($recipient['user_id'], $notification);
        }

        // Marca come inviato
        $stmt = $this->pdo->prepare("
            UPDATE tasks
            SET deadline_reminder_sent = 1
            WHERE id = :task_id
        ");

        $stmt->execute([':task_id' => $taskId]);

        return true;
    }

    /**
     * Escalation per task scaduti
     */
    public function escalateOverdue(int $taskId): bool {
        $task = $this->getTask($taskId);

        // Trova manager del team
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT u.manager_id
            FROM task_assignees ta
            JOIN users u ON ta.user_id = u.id
            WHERE ta.task_id = :task_id AND ta.tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        $managers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Invia notifica di escalation
        $notification = [
            'type' => 'overdue_escalation',
            'task_id' => $taskId,
            'task_title' => $task['title'],
            'days_overdue' => $this->calculateDaysOverdue($task['due_date']),
            'assignees' => $this->getTaskAssignees($taskId)
        ];

        foreach ($managers as $managerId) {
            $this->sendNotification($managerId, $notification);
        }

        // Log escalation
        $this->logActivity('task_escalated', $taskId, ['reason' => 'overdue']);

        return true;
    }

    /**
     * Notifica cambi di stato
     */
    public function notifyStatusChange(int $taskId, string $oldStatus, string $newStatus): void {
        $notification = [
            'type' => 'status_change',
            'task_id' => $taskId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $this->user_id,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Notifica watchers
        $this->notifyWatchers($taskId, 'status_change', $notification);

        // Trigger automazioni basate su stato
        $this->triggerStatusAutomations($taskId, $newStatus);
    }

    /**
     * Genera digest di notifiche per utente
     */
    public function digestNotification(int $userId, string $frequency = 'daily'): array {
        $interval = $frequency === 'weekly' ? '7 DAY' : '1 DAY';

        $stmt = $this->pdo->prepare("
            SELECT
                'assigned' as type,
                COUNT(*) as count,
                GROUP_CONCAT(t.title SEPARATOR ', ') as items
            FROM task_assignees ta
            JOIN tasks t ON ta.task_id = t.id
            WHERE ta.user_id = :user_id
            AND ta.tenant_id = :tenant_id
            AND ta.assigned_at > DATE_SUB(NOW(), INTERVAL $interval)

            UNION ALL

            SELECT
                'completed' as type,
                COUNT(*) as count,
                GROUP_CONCAT(t.title SEPARATOR ', ') as items
            FROM tasks t
            JOIN task_assignees ta ON ta.task_id = t.id
            WHERE ta.user_id = :user_id
            AND t.tenant_id = :tenant_id
            AND t.completed_at > DATE_SUB(NOW(), INTERVAL $interval)

            UNION ALL

            SELECT
                'overdue' as type,
                COUNT(*) as count,
                GROUP_CONCAT(t.title SEPARATOR ', ') as items
            FROM tasks t
            JOIN task_assignees ta ON ta.task_id = t.id
            WHERE ta.user_id = :user_id
            AND t.tenant_id = :tenant_id
            AND t.due_date < NOW()
            AND t.status NOT IN ('completed', 'cancelled')
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        $digest = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $digest[$row['type']] = [
                'count' => $row['count'],
                'items' => explode(', ', $row['items'])
            ];
        }

        return $digest;
    }

    // ========================================
    // SMART FEATURES
    // ========================================

    /**
     * Suggerisce il prossimo task basandosi su priorità e dipendenze
     */
    public function suggestNextTask(int $userId): ?array {
        // Task assegnati non completati ordinati per priorità e scadenza
        $stmt = $this->pdo->prepare("
            WITH task_scores AS (
                SELECT
                    t.*,
                    (t.priority * 10) +
                    (CASE
                        WHEN t.due_date < NOW() THEN 50
                        WHEN t.due_date < DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 30
                        WHEN t.due_date < DATE_ADD(NOW(), INTERVAL 3 DAY) THEN 10
                        ELSE 0
                    END) +
                    (100 - COALESCE(t.progress_percentage, 0)) / 10 as score
                FROM tasks t
                JOIN task_assignees ta ON ta.task_id = t.id
                WHERE ta.user_id = :user_id
                AND t.tenant_id = :tenant_id
                AND t.status IN ('pending', 'in_progress')
                AND NOT EXISTS (
                    SELECT 1 FROM task_dependencies td
                    JOIN tasks dt ON td.depends_on_id = dt.id
                    WHERE td.task_id = t.id
                    AND dt.status != 'completed'
                )
            )
            SELECT * FROM task_scores
            ORDER BY score DESC
            LIMIT 1
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Predice potenziali ritardi
     */
    public function predictDelay(int $taskId): array {
        $task = $this->getTask($taskId);
        $timeSpent = $this->calculateTotalTime($taskId)['total_hours'];
        $progress = $task['progress_percentage'] ?? 0;

        // Calcola velocità attuale
        if ($progress > 0) {
            $hoursPerPercent = $timeSpent / $progress;
            $remainingHours = $hoursPerPercent * (100 - $progress);

            // Confronta con stima originale
            $estimatedRemaining = ($task['estimated_hours'] ?? 0) * ((100 - $progress) / 100);
            $delayHours = $remainingHours - $estimatedRemaining;

            return [
                'likely_delay' => $delayHours > 0,
                'delay_hours' => max(0, $delayHours),
                'predicted_completion' => $this->addWorkingHours(new DateTime(), $remainingHours),
                'confidence' => $progress > 20 ? 'high' : 'low',
                'current_velocity' => $hoursPerPercent,
                'time_spent' => $timeSpent,
                'progress' => $progress
            ];
        }

        return [
            'likely_delay' => false,
            'delay_hours' => 0,
            'predicted_completion' => $task['due_date'],
            'confidence' => 'no_data'
        ];
    }

    /**
     * Ottimizza l'ordine dei task in una board
     */
    public function optimizeSchedule(int $boardId): array {
        $tasks = $this->getBoardTasks($boardId);
        $optimized = [];

        // Algoritmo di ottimizzazione basato su:
        // 1. Dipendenze
        // 2. Priorità
        // 3. Scadenze
        // 4. Risorse disponibili

        usort($tasks, function($a, $b) {
            // Prima i task senza dipendenze
            $aDeps = $this->countPendingDependencies($a['id']);
            $bDeps = $this->countPendingDependencies($b['id']);

            if ($aDeps != $bDeps) {
                return $aDeps - $bDeps;
            }

            // Poi per priorità
            if ($a['priority'] != $b['priority']) {
                return $b['priority'] - $a['priority'];
            }

            // Infine per scadenza
            return strcmp($a['due_date'] ?? '9999-12-31', $b['due_date'] ?? '9999-12-31');
        });

        return [
            'original_order' => array_column($tasks, 'id'),
            'optimized_order' => array_column($optimized, 'id'),
            'improvements' => $this->calculateScheduleImprovements($tasks, $optimized)
        ];
    }

    /**
     * Identifica potenziali blockers
     */
    public function detectBlockers(int $taskId): array {
        $blockers = [];

        // Dipendenze non soddisfatte
        $dependencies = $this->canStartTask($taskId);
        if (!$dependencies['can_start']) {
            $blockers[] = [
                'type' => 'dependencies',
                'severity' => 'high',
                'details' => $dependencies['blockers']
            ];
        }

        // Risorse non disponibili
        $assignees = $this->getTaskAssignees($taskId);
        foreach ($assignees as $assignee) {
            if ($this->isUserOverloaded($assignee['user_id'])) {
                $blockers[] = [
                    'type' => 'resource_overload',
                    'severity' => 'medium',
                    'details' => ['user_id' => $assignee['user_id'], 'name' => $assignee['name']]
                ];
            }
        }

        // Task padre non completato
        $task = $this->getTask($taskId);
        if ($task['parent_task_id']) {
            $parent = $this->getTask($task['parent_task_id']);
            if ($parent['status'] != 'completed') {
                $blockers[] = [
                    'type' => 'parent_incomplete',
                    'severity' => 'high',
                    'details' => ['parent_id' => $parent['id'], 'parent_title' => $parent['title']]
                ];
            }
        }

        return $blockers;
    }

    /**
     * Raccomanda risorse utili per un task
     */
    public function recommendResources(int $taskId): array {
        $task = $this->getTask($taskId);
        $recommendations = [];

        // Trova task simili completati
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   GROUP_CONCAT(DISTINCT f.file_name) as attachments,
                   GROUP_CONCAT(DISTINCT c.content SEPARATOR '\n') as useful_comments
            FROM tasks t
            LEFT JOIN task_attachments ta ON ta.task_id = t.id
            LEFT JOIN files f ON ta.file_id = f.id
            LEFT JOIN task_comments c ON c.task_id = t.id AND c.is_helpful = 1
            WHERE t.tenant_id = :tenant_id
            AND t.status = 'completed'
            AND t.id != :task_id
            AND (
                MATCH(t.title, t.description) AGAINST(:search_terms IN NATURAL LANGUAGE MODE)
                OR t.board_id = :board_id
            )
            GROUP BY t.id
            ORDER BY t.completed_at DESC
            LIMIT 5
        ");

        $searchTerms = $task['title'] . ' ' . ($task['description'] ?? '');

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':task_id' => $taskId,
            ':search_terms' => $searchTerms,
            ':board_id' => $task['board_id']
        ]);

        while ($similar = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recommendations[] = [
                'type' => 'similar_task',
                'task_id' => $similar['id'],
                'title' => $similar['title'],
                'attachments' => $similar['attachments'] ? explode(',', $similar['attachments']) : [],
                'helpful_info' => $similar['useful_comments']
            ];
        }

        // Suggerisci esperti basandosi su chi ha completato task simili
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.name, COUNT(*) as completed_similar
            FROM users u
            JOIN tasks t ON t.completed_by = u.id
            WHERE t.tenant_id = :tenant_id
            AND t.board_id = :board_id
            AND t.status = 'completed'
            GROUP BY u.id
            ORDER BY completed_similar DESC
            LIMIT 3
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':board_id' => $task['board_id']
        ]);

        while ($expert = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recommendations[] = [
                'type' => 'expert',
                'user_id' => $expert['id'],
                'name' => $expert['name'],
                'experience' => $expert['completed_similar'] . ' task simili completati'
            ];
        }

        return $recommendations;
    }

    // ========================================
    // ADDITIONAL FEATURES
    // ========================================

    /**
     * Aggiunge un commento con menzioni
     */
    public function addComment(int $taskId, string $comment, array $mentions = []): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO task_comments
            (task_id, user_id, tenant_id, content, created_at)
            VALUES
            (:task_id, :user_id, :tenant_id, :content, NOW())
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $this->user_id,
            ':tenant_id' => $this->tenant_id,
            ':content' => $comment
        ]);

        $commentId = (int)$this->pdo->lastInsertId();

        // Gestisci menzioni
        foreach ($mentions as $userId) {
            $this->addMention($commentId, $userId);
            $this->notifyMention($taskId, $userId, $comment);
        }

        return $commentId;
    }

    /**
     * Crea un task ricorrente
     */
    public function createRecurringTask(array $data, array $pattern): int {
        // Crea il template del task ricorrente
        $stmt = $this->pdo->prepare("
            INSERT INTO recurring_tasks
            (tenant_id, template_data, recurrence_pattern, next_due_date, is_active, created_by)
            VALUES
            (:tenant_id, :template, :pattern, :next_due, 1, :user_id)
        ");

        $nextDue = $this->calculateNextOccurrence($pattern);

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':template' => json_encode($data),
            ':pattern' => json_encode($pattern),
            ':next_due' => $nextDue,
            ':user_id' => $this->user_id
        ]);

        $recurringId = (int)$this->pdo->lastInsertId();

        // Crea la prima istanza
        $data['recurring_task_id'] = $recurringId;
        $data['due_date'] = $nextDue;
        $this->createTask($data);

        return $recurringId;
    }

    /**
     * Report e Analytics
     */
    public function getTaskMetrics(int $boardId, array $dateRange): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN due_date < NOW() AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue,
                AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_completion_hours,
                AVG(progress_percentage) as avg_progress
            FROM tasks
            WHERE board_id = :board_id
            AND tenant_id = :tenant_id
            AND created_at BETWEEN :start_date AND :end_date
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':tenant_id' => $this->tenant_id,
            ':start_date' => $dateRange['start'],
            ':end_date' => $dateRange['end']
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    private function validateTaskData(array $data): void {
        if (empty($data['title'])) {
            throw new Exception("Il titolo del task è obbligatorio");
        }

        if (strlen($data['title']) > 255) {
            throw new Exception("Il titolo non può superare i 255 caratteri");
        }

        if (isset($data['priority']) && ($data['priority'] < 1 || $data['priority'] > 4)) {
            throw new Exception("Priorità non valida");
        }

        if (isset($data['due_date']) && !strtotime($data['due_date'])) {
            throw new Exception("Data di scadenza non valida");
        }
    }

    private function getTask(int $taskId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM tasks
            WHERE id = :task_id
            AND tenant_id = :tenant_id
            AND deleted_at IS NULL
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function taskExists(int $taskId): bool {
        return $this->getTask($taskId) !== null;
    }

    private function canEditTask(int $taskId): bool {
        // Implementa logica di permessi
        // Per ora, tutti gli utenti del tenant possono modificare
        return true;
    }

    private function canDeleteTask(int $taskId): bool {
        // Solo creatore o admin possono eliminare
        $task = $this->getTask($taskId);
        return $task && ($task['created_by'] == $this->user_id || $this->isAdmin());
    }

    private function isAdmin(): bool {
        // Implementa verifica ruolo admin
        return false; // Placeholder
    }

    private function userBelongsToTenant(int $userId): bool {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM users
            WHERE id = :user_id
            AND tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $this->tenant_id
        ]);

        return (bool)$stmt->fetch();
    }

    private function getFromCache(string $key) {
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            if (time() - $cached['time'] < self::CACHE_TTL) {
                return $cached['data'];
            }
        }
        return null;
    }

    private function setCache(string $key, $data): void {
        $this->cache[$key] = [
            'data' => $data,
            'time' => time()
        ];
    }

    private function invalidateCache(string $pattern): void {
        foreach (array_keys($this->cache) as $key) {
            if (strpos($key, $pattern) !== false) {
                unset($this->cache[$key]);
            }
        }
    }

    private function logActivity(string $action, int $entityId, array $data = []): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO activity_logs
            (tenant_id, user_id, action, entity_type, entity_id, data, created_at)
            VALUES
            (:tenant_id, :user_id, :action, 'task', :entity_id, :data, NOW())
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':user_id' => $this->user_id,
            ':action' => $action,
            ':entity_id' => $entityId,
            ':data' => json_encode($data)
        ]);
    }

    private function sendNotification(int $userId, array $notification): void {
        // Implementa invio notifica (email, websocket, etc)
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications
            (tenant_id, user_id, type, data, created_at)
            VALUES
            (:tenant_id, :user_id, :type, :data, NOW())
        ");

        $stmt->execute([
            ':tenant_id' => $this->tenant_id,
            ':user_id' => $userId,
            ':type' => $notification['type'],
            ':data' => json_encode($notification)
        ]);
    }

    private function notifyWatchers(int $taskId, string $event, array $data): void {
        $watchers = $this->getTaskWatchers($taskId);
        foreach ($watchers as $watcher) {
            $this->sendNotification($watcher['user_id'], array_merge($data, [
                'event' => $event,
                'task_id' => $taskId
            ]));
        }
    }

    private function getTaskWatchers(int $taskId): array {
        $stmt = $this->pdo->prepare("
            SELECT user_id FROM task_watchers
            WHERE task_id = :task_id
            AND tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTaskAssignees(int $taskId): array {
        $stmt = $this->pdo->prepare("
            SELECT u.id as user_id, u.name, u.email
            FROM task_assignees ta
            JOIN users u ON ta.user_id = u.id
            WHERE ta.task_id = :task_id
            AND ta.tenant_id = :tenant_id
        ");

        $stmt->execute([
            ':task_id' => $taskId,
            ':tenant_id' => $this->tenant_id
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}