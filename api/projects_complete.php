<?php declare(strict_types=1);

/**
 * CollaboraNexio - API Progetti Completo
 * Gestione progetti, membri, milestones e risorse
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Headers CORS e JSON
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Gestione preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verifica autenticazione
$auth = new Auth();
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$tenant_id = $user['tenant_id'];
$user_id = $user['id'];
$user_role = $user['role'];
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

/**
 * Router principale
 */
try {
    $response = match($method) {
        'GET' => handleGet($path),
        'POST' => handlePost($path),
        'PUT' => handlePut($path),
        'DELETE' => handleDelete($path),
        default => throw new Exception('Metodo non supportato', 405)
    };

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * GET - Lista progetti, dettagli, statistiche
 */
function handleGet(string $path): array {
    $parts = explode('/', trim($path, '/'));
    $action = $parts[0] ?? 'list';

    return match($action) {
        'list' => getProjects(),
        'detail' => getProjectDetail((int)($parts[1] ?? 0)),
        'members' => getProjectMembers((int)($parts[1] ?? 0)),
        'tasks' => getProjectTasks((int)($parts[1] ?? 0)),
        'files' => getProjectFiles((int)($parts[1] ?? 0)),
        'timeline' => getProjectTimeline((int)($parts[1] ?? 0)),
        'statistics' => getProjectStatistics((int)($parts[1] ?? 0)),
        'my' => getMyProjects(),
        'archived' => getArchivedProjects(),
        default => throw new Exception('Azione non valida', 400)
    };
}

/**
 * POST - Crea progetto, aggiungi membri, milestone
 */
function handlePost(string $path): array {
    $parts = explode('/', trim($path, '/'));
    $action = $parts[0] ?? 'create';
    $data = json_decode(file_get_contents('php://input'), true);

    return match($action) {
        'create' => createProject($data),
        'member' => addProjectMember($data),
        'milestone' => createMilestone($data),
        'comment' => addProjectComment($data),
        'duplicate' => duplicateProject((int)($parts[1] ?? 0)),
        default => throw new Exception('Azione non valida', 400)
    };
}

/**
 * PUT - Aggiorna progetto, membri, stato
 */
function handlePut(string $path): array {
    $parts = explode('/', trim($path, '/'));
    $project_id = (int)($parts[0] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($parts[1]) && $parts[1] === 'member') {
        return updateProjectMember($project_id, (int)($parts[2] ?? 0), $data);
    }

    return updateProject($project_id, $data);
}

/**
 * DELETE - Elimina progetto, rimuovi membri
 */
function handleDelete(string $path): array {
    $parts = explode('/', trim($path, '/'));
    $project_id = (int)($parts[0] ?? 0);

    if (isset($parts[1]) && $parts[1] === 'member') {
        return removeProjectMember($project_id, (int)($parts[2] ?? 0));
    }

    return deleteProject($project_id);
}

/**
 * Lista progetti
 */
function getProjects(): array {
    global $pdo, $tenant_id, $user_id;

    $status = $_GET['status'] ?? null;
    $priority = $_GET['priority'] ?? null;
    $search = $_GET['search'] ?? '';
    $sort = $_GET['sort'] ?? 'updated_at';
    $order = $_GET['order'] ?? 'desc';
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);

    $sql = "
        SELECT
            p.*,
            u.display_name as owner_name,
            u.avatar_url as owner_avatar,
            (SELECT COUNT(*) FROM project_members WHERE project_id = p.id) as member_count,
            (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count,
            (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = 'done') as completed_tasks,
            (SELECT COUNT(*) FROM files WHERE folder_id IN (
                SELECT id FROM folders WHERE name = CONCAT('Project_', p.id)
            )) as file_count,
            EXISTS(SELECT 1 FROM project_members
                   WHERE project_id = p.id AND user_id = ?) as is_member
        FROM projects p
        LEFT JOIN users u ON p.owner_id = u.id
        WHERE p.tenant_id = ?
        AND p.deleted_at IS NULL
    ";

    $params = [$user_id, $tenant_id];

    // Filtri
    if ($status) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }

    if ($priority) {
        $sql .= " AND p.priority = ?";
        $params[] = $priority;
    }

    if ($search) {
        $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }

    // Ordinamento
    $order_column = match($sort) {
        'name' => 'p.name',
        'start_date' => 'p.start_date',
        'end_date' => 'p.end_date',
        'progress' => 'p.progress_percentage',
        'priority' => 'p.priority',
        default => 'p.updated_at'
    };

    $sql .= " ORDER BY $order_column " . ($order === 'desc' ? 'DESC' : 'ASC');
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggiungi informazioni aggiuntive
    foreach ($projects as &$project) {
        $project['completion_rate'] = $project['task_count'] > 0
            ? round(($project['completed_tasks'] / $project['task_count']) * 100, 1)
            : 0;

        $project['days_left'] = null;
        if ($project['end_date']) {
            $end = new DateTime($project['end_date']);
            $now = new DateTime();
            if ($end > $now) {
                $project['days_left'] = $end->diff($now)->days;
            }
        }

        $project['status_color'] = match($project['status']) {
            'active' => 'success',
            'planning' => 'info',
            'on_hold' => 'warning',
            'completed' => 'secondary',
            'cancelled' => 'danger',
            default => 'light'
        };

        $project['priority_badge'] = match($project['priority']) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'secondary',
            default => 'light'
        };
    }

    // Statistiche generali
    $stats_sql = "
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'on_hold' THEN 1 END) as on_hold,
            AVG(progress_percentage) as avg_progress
        FROM projects
        WHERE tenant_id = ? AND deleted_at IS NULL
    ";

    $stmt = $pdo->prepare($stats_sql);
    $stmt->execute([$tenant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'projects' => $projects,
        'statistics' => [
            'total' => (int)$stats['total'],
            'active' => (int)$stats['active'],
            'completed' => (int)$stats['completed'],
            'on_hold' => (int)$stats['on_hold'],
            'avg_progress' => round((float)$stats['avg_progress'], 1)
        ],
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total' => (int)$stats['total']
        ]
    ];
}

/**
 * Dettaglio progetto
 */
function getProjectDetail(int $project_id): array {
    global $pdo, $tenant_id, $user_id;

    // Progetto principale
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            u.display_name as owner_name,
            u.email as owner_email,
            u.avatar_url as owner_avatar
        FROM projects p
        LEFT JOIN users u ON p.owner_id = u.id
        WHERE p.id = ? AND p.tenant_id = ?
    ");
    $stmt->execute([$project_id, $tenant_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        throw new Exception('Progetto non trovato', 404);
    }

    // Verifica accesso
    $stmt = $pdo->prepare("
        SELECT role FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $member_role = $stmt->fetchColumn();

    if (!$member_role && $project['owner_id'] != $user_id && $user['role'] != 'admin') {
        throw new Exception('Accesso negato al progetto', 403);
    }

    // Membri del team
    $stmt = $pdo->prepare("
        SELECT
            pm.*,
            u.display_name,
            u.email,
            u.avatar_url,
            u.position,
            u.department
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ?
        ORDER BY pm.role DESC, u.display_name
    ");
    $stmt->execute([$project_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistiche tasks
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'todo' THEN 1 END) as todo,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
            COUNT(CASE WHEN status = 'done' THEN 1 END) as done,
            COUNT(CASE WHEN priority = 'critical' THEN 1 END) as critical,
            COUNT(CASE WHEN priority = 'high' THEN 1 END) as high,
            COUNT(CASE WHEN due_date < NOW() AND status != 'done' THEN 1 END) as overdue
        FROM tasks
        WHERE project_id = ?
    ");
    $stmt->execute([$project_id]);
    $task_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Milestones
    $stmt = $pdo->prepare("
        SELECT * FROM project_milestones
        WHERE project_id = ?
        ORDER BY due_date ASC
    ");
    $stmt->execute([$project_id]);
    $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attività recenti
    $stmt = $pdo->prepare("
        SELECT
            al.*,
            u.display_name as user_name
        FROM audit_logs al
        JOIN users u ON al.user_id = u.id
        WHERE al.resource_type IN ('project', 'task')
        AND (al.resource_id = ? OR al.metadata LIKE ?)
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$project_id, '%"project_id":' . $project_id . '%']);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Budget utilizzato (se ci sono spese registrate)
    $budget_used = 0; // Da implementare con tabella expenses

    return [
        'project' => $project,
        'user_role' => $member_role ?: ($project['owner_id'] == $user_id ? 'owner' : 'viewer'),
        'members' => $members,
        'task_statistics' => $task_stats,
        'milestones' => $milestones,
        'recent_activity' => $recent_activity,
        'budget' => [
            'total' => (float)$project['budget'],
            'used' => $budget_used,
            'remaining' => (float)$project['budget'] - $budget_used
        ]
    ];
}

/**
 * Crea nuovo progetto
 */
function createProject(array $data): array {
    global $pdo, $tenant_id, $user_id;

    // Validazione
    $name = trim($data['name'] ?? '');
    if (empty($name)) {
        throw new Exception('Nome progetto richiesto', 400);
    }

    $description = $data['description'] ?? '';
    $status = $data['status'] ?? 'planning';
    $priority = $data['priority'] ?? 'medium';
    $start_date = $data['start_date'] ?? date('Y-m-d');
    $end_date = $data['end_date'] ?? null;
    $budget = (float)($data['budget'] ?? 0);
    $tags = $data['tags'] ?? '';

    // Verifica unicità nome
    $stmt = $pdo->prepare("
        SELECT id FROM projects
        WHERE tenant_id = ? AND name = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$tenant_id, $name]);

    if ($stmt->fetch()) {
        throw new Exception('Esiste già un progetto con questo nome', 409);
    }

    // Inizia transazione
    $pdo->beginTransaction();

    try {
        // Crea progetto
        $stmt = $pdo->prepare("
            INSERT INTO projects (
                tenant_id, name, description, owner_id,
                status, priority, start_date, end_date,
                budget, tags, progress_percentage
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $stmt->execute([
            $tenant_id,
            $name,
            $description,
            $user_id,
            $status,
            $priority,
            $start_date,
            $end_date,
            $budget,
            $tags
        ]);

        $project_id = $pdo->lastInsertId();

        // Aggiungi il creatore come owner del team
        $stmt = $pdo->prepare("
            INSERT INTO project_members (
                tenant_id, project_id, user_id, role, added_by
            ) VALUES (?, ?, ?, 'owner', ?)
        ");
        $stmt->execute([$tenant_id, $project_id, $user_id, $user_id]);

        // Crea cartella per i file del progetto
        $stmt = $pdo->prepare("
            INSERT INTO folders (
                tenant_id, name, path, owner_id
            ) VALUES (?, ?, ?, ?)
        ");
        $folder_name = 'Project_' . $project_id;
        $stmt->execute([
            $tenant_id,
            $folder_name,
            '/' . $folder_name,
            $user_id
        ]);

        // Aggiungi membri se specificati
        if (isset($data['members']) && is_array($data['members'])) {
            foreach ($data['members'] as $member_id) {
                if ($member_id != $user_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO project_members (
                            tenant_id, project_id, user_id, role, added_by
                        ) VALUES (?, ?, ?, 'member', ?)
                    ");
                    $stmt->execute([$tenant_id, $project_id, $member_id, $user_id]);

                    // Notifica
                    sendNotification($member_id, 'project_added',
                        'Aggiunto a progetto',
                        "Sei stato aggiunto al progetto '$name'",
                        ['project_id' => $project_id]
                    );
                }
            }
        }

        // Log attività
        logActivity('project_create', 'project', $project_id, [
            'name' => $name,
            'status' => $status,
            'priority' => $priority
        ]);

        $pdo->commit();

        return [
            'success' => true,
            'project_id' => $project_id,
            'name' => $name,
            'message' => 'Progetto creato con successo'
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Aggiorna progetto
 */
function updateProject(int $project_id, array $data): array {
    global $pdo, $tenant_id, $user_id;

    // Verifica permessi
    $stmt = $pdo->prepare("
        SELECT p.*, pm.role
        FROM projects p
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
        WHERE p.id = ? AND p.tenant_id = ?
    ");
    $stmt->execute([$user_id, $project_id, $tenant_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        throw new Exception('Progetto non trovato', 404);
    }

    if ($project['owner_id'] != $user_id && $project['role'] != 'manager' && $user['role'] != 'admin') {
        throw new Exception('Non autorizzato a modificare il progetto', 403);
    }

    // Costruisci UPDATE dinamico
    $updates = [];
    $params = [];

    $allowed_fields = [
        'name', 'description', 'status', 'priority',
        'start_date', 'end_date', 'budget', 'tags',
        'progress_percentage'
    ];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($updates)) {
        throw new Exception('Nessun campo da aggiornare', 400);
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $project_id;
    $params[] = $tenant_id;

    $sql = "UPDATE projects SET " . implode(', ', $updates) . " WHERE id = ? AND tenant_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log modifiche
    $changes = array_intersect_key($data, array_flip($allowed_fields));
    logActivity('project_update', 'project', $project_id, [
        'changes' => $changes
    ]);

    // Notifica membri se lo stato cambia
    if (isset($data['status']) && $data['status'] != $project['status']) {
        $members_sql = "SELECT user_id FROM project_members WHERE project_id = ? AND user_id != ?";
        $stmt = $pdo->prepare($members_sql);
        $stmt->execute([$project_id, $user_id]);

        while ($member = $stmt->fetch(PDO::FETCH_ASSOC)) {
            sendNotification($member['user_id'], 'project_status',
                'Stato progetto cambiato',
                "Il progetto '{$project['name']}' è ora '{$data['status']}'",
                ['project_id' => $project_id, 'new_status' => $data['status']]
            );
        }
    }

    return [
        'success' => true,
        'message' => 'Progetto aggiornato con successo'
    ];
}

/**
 * Aggiungi membro al progetto
 */
function addProjectMember(array $data): array {
    global $pdo, $tenant_id, $user_id;

    $project_id = (int)($data['project_id'] ?? 0);
    $new_user_id = (int)($data['user_id'] ?? 0);
    $role = $data['role'] ?? 'member';

    // Verifica permessi
    $stmt = $pdo->prepare("
        SELECT p.*, pm.role as my_role
        FROM projects p
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
        WHERE p.id = ? AND p.tenant_id = ?
    ");
    $stmt->execute([$user_id, $project_id, $tenant_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        throw new Exception('Progetto non trovato', 404);
    }

    if ($project['owner_id'] != $user_id && $project['my_role'] != 'manager') {
        throw new Exception('Solo owner e manager possono aggiungere membri', 403);
    }

    // Verifica che l'utente esista nel tenant
    $stmt = $pdo->prepare("
        SELECT id, display_name, email FROM users
        WHERE id = ? AND tenant_id = ?
    ");
    $stmt->execute([$new_user_id, $tenant_id]);
    $new_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$new_user) {
        throw new Exception('Utente non trovato', 404);
    }

    // Verifica se già membro
    $stmt = $pdo->prepare("
        SELECT id FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $new_user_id]);

    if ($stmt->fetch()) {
        throw new Exception('Utente già membro del progetto', 409);
    }

    // Aggiungi membro
    $stmt = $pdo->prepare("
        INSERT INTO project_members (
            tenant_id, project_id, user_id, role, added_by
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tenant_id, $project_id, $new_user_id, $role, $user_id]);

    // Notifica
    sendNotification($new_user_id, 'project_added',
        'Aggiunto a progetto',
        "Sei stato aggiunto al progetto '{$project['name']}' come $role",
        ['project_id' => $project_id, 'role' => $role]
    );

    // Log
    logActivity('project_member_add', 'project', $project_id, [
        'user_added' => $new_user['display_name'],
        'role' => $role
    ]);

    return [
        'success' => true,
        'message' => "Utente {$new_user['display_name']} aggiunto al progetto"
    ];
}

/**
 * Helper: Log attività
 */
function logActivity(string $action, string $resource_type, int $resource_id, array $metadata = []): void {
    global $pdo, $tenant_id, $user_id;

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
            tenant_id, user_id, action, resource_type,
            resource_id, ip_address, user_agent, metadata
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tenant_id,
        $user_id,
        $action,
        $resource_type,
        $resource_id,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        json_encode($metadata)
    ]);
}

/**
 * Helper: Invia notifica
 */
function sendNotification(int $to_user_id, string $type, string $title, string $message, array $data = []): void {
    global $pdo, $tenant_id;

    $stmt = $pdo->prepare("
        INSERT INTO notifications (tenant_id, user_id, type, title, message, data)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tenant_id,
        $to_user_id,
        $type,
        $title,
        $message,
        json_encode($data)
    ]);
}

// Funzioni stub per completezza
function getMyProjects(): array {
    global $pdo, $tenant_id, $user_id;

    $stmt = $pdo->prepare("
        SELECT p.*, u.display_name as owner_name, pm.role as my_role
        FROM project_members pm
        JOIN projects p ON pm.project_id = p.id
        LEFT JOIN users u ON p.owner_id = u.id
        WHERE pm.user_id = ? AND p.tenant_id = ?
        AND p.deleted_at IS NULL
        ORDER BY p.updated_at DESC
    ");
    $stmt->execute([$user_id, $tenant_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProjectMembers(int $project_id): array {
    global $pdo, $tenant_id;

    $stmt = $pdo->prepare("
        SELECT pm.*, u.display_name, u.email, u.avatar_url
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ? AND pm.tenant_id = ?
    ");
    $stmt->execute([$project_id, $tenant_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProjectTasks(int $project_id): array {
    global $pdo, $tenant_id;

    $stmt = $pdo->prepare("
        SELECT t.*, u.display_name as assigned_to_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.project_id = ? AND t.tenant_id = ?
        ORDER BY t.priority DESC, t.due_date ASC
    ");
    $stmt->execute([$project_id, $tenant_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}