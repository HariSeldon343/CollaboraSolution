<?php
/**
 * REST API for Channels Resource
 *
 * Endpoints:
 * - GET    /api/channels.php                              - List user's channels
 * - POST   /api/channels.php                              - Create new channel
 * - GET    /api/channels.php?id=X                        - Get channel details
 * - PUT    /api/channels.php?id=X                        - Update channel
 * - DELETE /api/channels.php?id=X                        - Archive channel
 * - POST   /api/channels.php?id=X&action=members         - Add members
 * - DELETE /api/channels.php?id=X&action=members&user_id=Y - Remove member
 *
 * @author CollaboraNexio Development Team
 * @version 2.0.0
 */

// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../includes/session_init.php';
// CORS headers for web clients
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Rate limiting headers
header('X-RateLimit-Limit: 100');
header('X-RateLimit-Remaining: 99');
header('X-RateLimit-Reset: ' . (time() + 3600));

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        'success' => false,
        'data' => null,
        'message' => 'Authentication required',
        'metadata' => [
            'timestamp' => date('c')
        ]
    ]));
}

$tenant_id = $_SESSION['tenant_id'];
$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Route based on HTTP method and action
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                handleGetChannelDetails($pdo, $tenant_id, $user_id);
            } else {
                handleListChannels($pdo, $tenant_id, $user_id);
            }
            break;

        case 'POST':
            $action = $_GET['action'] ?? 'create';
            if ($action === 'members' && isset($_GET['id'])) {
                handleAddMembers($pdo, $tenant_id, $user_id, $input);
            } else {
                handleCreateChannel($pdo, $tenant_id, $user_id, $input);
            }
            break;

        case 'PUT':
            handleUpdateChannel($pdo, $tenant_id, $user_id, $input);
            break;

        case 'DELETE':
            $action = $_GET['action'] ?? 'archive';
            if ($action === 'members' && isset($_GET['user_id'])) {
                handleRemoveMember($pdo, $tenant_id, $user_id);
            } else {
                handleArchiveChannel($pdo, $tenant_id, $user_id);
            }
            break;

        default:
            http_response_code(405);
            die(json_encode([
                'success' => false,
                'data' => null,
                'message' => 'Method not allowed',
                'metadata' => [
                    'timestamp' => date('c')
                ]
            ]));
    }

} catch (Exception $e) {
    error_log("Channels API Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'data' => null,
        'message' => 'Internal server error',
        'metadata' => [
            'timestamp' => date('c'),
            'error' => DEBUG_MODE ? $e->getMessage() : null
        ]
    ]));
}

/**
 * GET /api/channels.php
 * List all channels the user has access to
 */
function handleListChannels($pdo, $tenant_id, $user_id) {
    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $type_filter = isset($_GET['type']) ? $_GET['type'] : null;
    $archived = isset($_GET['archived']) ? filter_var($_GET['archived'], FILTER_VALIDATE_BOOLEAN) : false;

    // Build base query
    $sql = "SELECT
                c.id,
                c.name,
                c.description,
                c.channel_type,
                c.is_private,
                c.is_archived,
                c.created_by,
                c.message_count,
                c.member_count,
                c.last_message_at,
                c.created_at,
                c.updated_at,
                cm.role AS user_role,
                cm.joined_at AS user_joined_at,
                cm.last_read_message_id,
                cm.unread_count,
                cm.is_muted,
                cm.notification_level,
                cu.nome AS creator_name,
                cu.email AS creator_email,
                -- Get last message preview
                (SELECT JSON_OBJECT(
                    'id', lm.id,
                    'content', SUBSTRING(lm.content, 1, 100),
                    'user_name', lu.nome,
                    'created_at', lm.created_at
                ) FROM chat_messages lm
                LEFT JOIN users lu ON lm.user_id = lu.id
                WHERE lm.channel_id = c.id
                AND lm.tenant_id = c.tenant_id
                AND lm.is_deleted = 0
                ORDER BY lm.sequence_id DESC
                LIMIT 1) AS last_message,
                -- Get pinned messages count
                (SELECT COUNT(*)
                FROM chat_messages
                WHERE channel_id = c.id
                AND tenant_id = c.tenant_id
                AND is_pinned = 1
                AND is_deleted = 0) AS pinned_count,
                -- Get active members (online in last 5 minutes)
                (SELECT COUNT(DISTINCT cm2.user_id)
                FROM channel_members cm2
                INNER JOIN users u2 ON cm2.user_id = u2.id
                WHERE cm2.channel_id = c.id
                AND cm2.tenant_id = c.tenant_id
                AND u2.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS active_members
            FROM chat_channels c
            INNER JOIN channel_members cm ON c.id = cm.channel_id AND c.tenant_id = cm.tenant_id
            LEFT JOIN users cu ON c.created_by = cu.id
            WHERE c.tenant_id = :tenant_id
            AND cm.user_id = :user_id
            AND c.is_archived = :archived";

    $params = [
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id,
        ':archived' => $archived ? 1 : 0
    ];

    // Add search filter
    if (!empty($search)) {
        $sql .= " AND (c.name LIKE :search OR c.description LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    // Add type filter
    if ($type_filter && in_array($type_filter, ['public', 'private', 'direct', 'group'])) {
        $sql .= " AND c.channel_type = :type";
        $params[':type'] = $type_filter;
    }

    // Get total count
    $count_sql = "SELECT COUNT(*) FROM ($sql) AS subquery";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_count = $stmt->fetchColumn();

    // Add ordering and pagination
    $sql .= " ORDER BY
              CASE
                WHEN cm.unread_count > 0 THEN 0
                ELSE 1
              END,
              c.last_message_at DESC
              LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process JSON fields
    foreach ($channels as &$channel) {
        $channel['last_message'] = json_decode($channel['last_message'], true);
        $channel['is_private'] = (bool)$channel['is_private'];
        $channel['is_archived'] = (bool)$channel['is_archived'];
        $channel['is_muted'] = (bool)$channel['is_muted'];
    }

    echo json_encode([
        'success' => true,
        'data' => $channels,
        'message' => 'Channels retrieved successfully',
        'metadata' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total_count,
            'has_more' => ($offset + $limit) < $total_count,
            'timestamp' => date('c')
        ]
    ]);
}

/**
 * GET /api/channels.php?id=X
 * Get detailed information about a specific channel
 */
function handleGetChannelDetails($pdo, $tenant_id, $user_id) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel id is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$_GET['id'];

    // Verify user has access to channel
    $sql = "SELECT
                c.id,
                c.name,
                c.description,
                c.channel_type,
                c.is_private,
                c.is_archived,
                c.created_by,
                c.message_count,
                c.member_count,
                c.last_message_at,
                c.created_at,
                c.updated_at,
                c.settings,
                cm.role AS user_role,
                cm.joined_at AS user_joined_at,
                cm.last_read_message_id,
                cm.unread_count,
                cm.is_muted,
                cm.notification_level,
                cu.nome AS creator_name,
                cu.email AS creator_email,
                cu.avatar_url AS creator_avatar
            FROM chat_channels c
            INNER JOIN channel_members cm ON c.id = cm.channel_id AND c.tenant_id = cm.tenant_id
            LEFT JOIN users cu ON c.created_by = cu.id
            WHERE c.id = :channel_id
            AND c.tenant_id = :tenant_id
            AND cm.user_id = :user_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ]);

    $channel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$channel) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel not found or access denied',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Get channel members
    $sql = "SELECT
                u.id,
                u.nome AS name,
                u.email,
                u.avatar_url,
                u.ruolo AS system_role,
                u.is_online,
                u.last_seen,
                cm.role AS channel_role,
                cm.joined_at,
                cm.is_muted,
                cm.notification_level
            FROM channel_members cm
            INNER JOIN users u ON cm.user_id = u.id
            WHERE cm.channel_id = :channel_id
            AND cm.tenant_id = :tenant_id
            ORDER BY
                CASE cm.role
                    WHEN 'owner' THEN 1
                    WHEN 'admin' THEN 2
                    WHEN 'moderator' THEN 3
                    ELSE 4
                END,
                u.nome ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id
    ]);

    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get pinned messages
    $sql = "SELECT
                m.id,
                m.content,
                m.created_at,
                u.nome AS user_name
            FROM chat_messages m
            INNER JOIN users u ON m.user_id = u.id
            WHERE m.channel_id = :channel_id
            AND m.tenant_id = :tenant_id
            AND m.is_pinned = 1
            AND m.is_deleted = 0
            ORDER BY m.created_at DESC
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id
    ]);

    $pinned_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get channel statistics
    $sql = "SELECT
                COUNT(DISTINCT DATE(created_at)) AS active_days,
                COUNT(DISTINCT user_id) AS unique_contributors,
                AVG(LENGTH(content)) AS avg_message_length,
                MAX(created_at) AS last_activity
            FROM chat_messages
            WHERE channel_id = :channel_id
            AND tenant_id = :tenant_id
            AND is_deleted = 0
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id
    ]);

    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Process data
    $channel['is_private'] = (bool)$channel['is_private'];
    $channel['is_archived'] = (bool)$channel['is_archived'];
    $channel['is_muted'] = (bool)$channel['is_muted'];
    $channel['settings'] = json_decode($channel['settings'], true) ?: [];

    foreach ($members as &$member) {
        $member['is_online'] = (bool)$member['is_online'];
        $member['is_muted'] = (bool)$member['is_muted'];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'channel' => $channel,
            'members' => $members,
            'pinned_messages' => $pinned_messages,
            'statistics' => $stats
        ],
        'message' => 'Channel details retrieved successfully',
        'metadata' => [
            'timestamp' => date('c')
        ]
    ]);
}

/**
 * POST /api/channels.php
 * Create a new channel
 */
function handleCreateChannel($pdo, $tenant_id, $user_id, $input) {
    // Validate input
    if (!isset($input['name']) || empty(trim($input['name']))) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel name is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $name = trim($input['name']);
    $description = isset($input['description']) ? trim($input['description']) : '';
    $channel_type = isset($input['channel_type']) ? $input['channel_type'] : 'public';
    $is_private = isset($input['is_private']) ? (bool)$input['is_private'] : false;
    $members = isset($input['members']) ? $input['members'] : [];
    $settings = isset($input['settings']) ? $input['settings'] : [];

    // Validate channel type
    $valid_types = ['public', 'private', 'direct', 'group'];
    if (!in_array($channel_type, $valid_types)) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Invalid channel type. Must be one of: ' . implode(', ', $valid_types),
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Validate channel name length
    if (strlen($name) < 1 || strlen($name) > 100) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel name must be between 1 and 100 characters',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Check for duplicate channel name
    $sql = "SELECT COUNT(*) FROM chat_channels
            WHERE tenant_id = :tenant_id
            AND name = :name
            AND is_archived = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':name' => $name
    ]);

    if ($stmt->fetchColumn() > 0) {
        http_response_code(409);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'A channel with this name already exists',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $pdo->beginTransaction();

    try {
        // Create channel
        $sql = "INSERT INTO chat_channels (
                    tenant_id,
                    name,
                    description,
                    channel_type,
                    is_private,
                    created_by,
                    settings,
                    created_at,
                    updated_at
                ) VALUES (
                    :tenant_id,
                    :name,
                    :description,
                    :channel_type,
                    :is_private,
                    :created_by,
                    :settings,
                    NOW(),
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':name' => $name,
            ':description' => $description,
            ':channel_type' => $channel_type,
            ':is_private' => $is_private ? 1 : 0,
            ':created_by' => $user_id,
            ':settings' => json_encode($settings)
        ]);

        $channel_id = $pdo->lastInsertId();

        // Add creator as owner
        $sql = "INSERT INTO channel_members (
                    tenant_id,
                    channel_id,
                    user_id,
                    role,
                    joined_at
                ) VALUES (
                    :tenant_id,
                    :channel_id,
                    :user_id,
                    'owner',
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':channel_id' => $channel_id,
            ':user_id' => $user_id
        ]);

        // Add additional members
        $added_members = [$user_id];
        foreach ($members as $member) {
            if (is_array($member)) {
                $member_id = $member['user_id'];
                $role = isset($member['role']) ? $member['role'] : 'member';
            } else {
                $member_id = $member;
                $role = 'member';
            }

            // Skip if already added or invalid
            if (in_array($member_id, $added_members)) {
                continue;
            }

            // Verify member exists in tenant
            $sql = "SELECT COUNT(*) FROM users
                    WHERE id = :user_id
                    AND tenant_id = :tenant_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $member_id,
                ':tenant_id' => $tenant_id
            ]);

            if ($stmt->fetchColumn() == 0) {
                continue;
            }

            // Add member
            $sql = "INSERT INTO channel_members (
                        tenant_id,
                        channel_id,
                        user_id,
                        role,
                        joined_at
                    ) VALUES (
                        :tenant_id,
                        :channel_id,
                        :user_id,
                        :role,
                        NOW()
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':channel_id' => $channel_id,
                ':user_id' => $member_id,
                ':role' => $role
            ]);

            $added_members[] = $member_id;

            // Send notification to new member
            $sql = "INSERT INTO notifications (
                        tenant_id,
                        user_id,
                        type,
                        title,
                        message,
                        data,
                        created_at
                    ) VALUES (
                        :tenant_id,
                        :user_id,
                        'channel_invite',
                        'Added to channel',
                        :message,
                        :data,
                        NOW()
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':user_id' => $member_id,
                ':message' => "You've been added to the channel: $name",
                ':data' => json_encode(['channel_id' => $channel_id])
            ]);
        }

        // Update member count
        $sql = "UPDATE chat_channels
                SET member_count = :count
                WHERE id = :channel_id
                AND tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':count' => count($added_members),
            ':channel_id' => $channel_id,
            ':tenant_id' => $tenant_id
        ]);

        // Create system message for channel creation
        $sql = "INSERT INTO chat_messages (
                    tenant_id,
                    channel_id,
                    user_id,
                    message_type,
                    content,
                    content_plain,
                    sequence_id,
                    created_at
                ) VALUES (
                    :tenant_id,
                    :channel_id,
                    :user_id,
                    'system',
                    :content,
                    :content_plain,
                    1,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':channel_id' => $channel_id,
            ':user_id' => $user_id,
            ':content' => "Channel created",
            ':content_plain' => "Channel created"
        ]);

        $pdo->commit();

        // Retrieve created channel
        $sql = "SELECT
                    c.id,
                    c.name,
                    c.description,
                    c.channel_type,
                    c.is_private,
                    c.created_by,
                    c.member_count,
                    c.created_at
                FROM chat_channels c
                WHERE c.id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $channel_id]);
        $channel = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data' => $channel,
            'message' => 'Channel created successfully',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * PUT /api/channels.php?id=X
 * Update channel settings
 */
function handleUpdateChannel($pdo, $tenant_id, $user_id, $input) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel id is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$_GET['id'];

    // Verify user has admin/owner privileges
    $sql = "SELECT cm.role, c.name
            FROM channel_members cm
            INNER JOIN chat_channels c ON cm.channel_id = c.id
            WHERE cm.channel_id = :channel_id
            AND cm.tenant_id = :tenant_id
            AND cm.user_id = :user_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ]);

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel not found or access denied',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    if (!in_array($membership['role'], ['owner', 'admin'])) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Only channel owners and admins can update channel settings',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Build update query dynamically
    $updates = [];
    $params = [
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id
    ];

    if (isset($input['name'])) {
        $name = trim($input['name']);
        if (strlen($name) < 1 || strlen($name) > 100) {
            http_response_code(400);
            die(json_encode([
                'success' => false,
                'data' => null,
                'message' => 'Channel name must be between 1 and 100 characters',
                'metadata' => [
                    'timestamp' => date('c')
                ]
            ]));
        }
        $updates[] = "name = :name";
        $params[':name'] = $name;
    }

    if (isset($input['description'])) {
        $updates[] = "description = :description";
        $params[':description'] = trim($input['description']);
    }

    if (isset($input['is_private'])) {
        $updates[] = "is_private = :is_private";
        $params[':is_private'] = $input['is_private'] ? 1 : 0;
    }

    if (isset($input['settings'])) {
        $updates[] = "settings = :settings";
        $params[':settings'] = json_encode($input['settings']);
    }

    if (empty($updates)) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'No valid fields to update',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Add updated_at
    $updates[] = "updated_at = NOW()";

    // Execute update
    $sql = "UPDATE chat_channels SET " . implode(', ', $updates) . "
            WHERE id = :channel_id
            AND tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log the update
    $sql = "INSERT INTO audit_logs (
                tenant_id,
                user_id,
                action,
                resource_type,
                resource_id,
                details,
                created_at
            ) VALUES (
                :tenant_id,
                :user_id,
                'update',
                'channel',
                :channel_id,
                :details,
                NOW()
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id,
        ':channel_id' => $channel_id,
        ':details' => json_encode($input)
    ]);

    // Retrieve updated channel
    $sql = "SELECT
                id,
                name,
                description,
                channel_type,
                is_private,
                settings,
                updated_at
            FROM chat_channels
            WHERE id = :channel_id
            AND tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id
    ]);

    $channel = $stmt->fetch(PDO::FETCH_ASSOC);
    $channel['is_private'] = (bool)$channel['is_private'];
    $channel['settings'] = json_decode($channel['settings'], true);

    echo json_encode([
        'success' => true,
        'data' => $channel,
        'message' => 'Channel updated successfully',
        'metadata' => [
            'timestamp' => date('c')
        ]
    ]);
}

/**
 * DELETE /api/channels.php?id=X
 * Archive (soft delete) a channel
 */
function handleArchiveChannel($pdo, $tenant_id, $user_id) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel id is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$_GET['id'];

    // Verify user has owner privileges
    $sql = "SELECT cm.role, c.name
            FROM channel_members cm
            INNER JOIN chat_channels c ON cm.channel_id = c.id
            WHERE cm.channel_id = :channel_id
            AND cm.tenant_id = :tenant_id
            AND cm.user_id = :user_id
            AND c.is_archived = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ]);

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel not found or already archived',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Check if user is owner or system admin
    $sql = "SELECT ruolo FROM users WHERE id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $user_id]);
    $user_role = $stmt->fetchColumn();

    if ($membership['role'] !== 'owner' && !in_array($user_role, ['admin', 'super_admin'])) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Only channel owners and system administrators can archive channels',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $pdo->beginTransaction();

    try {
        // Archive the channel
        $sql = "UPDATE chat_channels
                SET is_archived = 1,
                    archived_at = NOW(),
                    archived_by = :archived_by
                WHERE id = :channel_id
                AND tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':channel_id' => $channel_id,
            ':tenant_id' => $tenant_id,
            ':archived_by' => $user_id
        ]);

        // Create system message
        $sql = "INSERT INTO chat_messages (
                    tenant_id,
                    channel_id,
                    user_id,
                    message_type,
                    content,
                    content_plain,
                    sequence_id,
                    created_at
                ) VALUES (
                    :tenant_id,
                    :channel_id,
                    :user_id,
                    'system',
                    :content,
                    :content_plain,
                    (SELECT COALESCE(MAX(sequence_id), 0) + 1 FROM chat_messages WHERE tenant_id = :tenant_id2),
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':tenant_id2' => $tenant_id,
            ':channel_id' => $channel_id,
            ':user_id' => $user_id,
            ':content' => "Channel archived",
            ':content_plain' => "Channel archived"
        ]);

        // Notify all members
        $sql = "INSERT INTO notifications (tenant_id, user_id, type, title, message, data, created_at)
                SELECT
                    :tenant_id,
                    user_id,
                    'channel_archived',
                    'Channel archived',
                    :message,
                    :data,
                    NOW()
                FROM channel_members
                WHERE channel_id = :channel_id
                AND tenant_id = :tenant_id2
                AND user_id != :archiver_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':tenant_id2' => $tenant_id,
            ':channel_id' => $channel_id,
            ':message' => "The channel '{$membership['name']}' has been archived",
            ':data' => json_encode(['channel_id' => $channel_id]),
            ':archiver_id' => $user_id
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'data' => ['channel_id' => $channel_id],
            'message' => 'Channel archived successfully',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * POST /api/channels.php?id=X&action=members
 * Add members to a channel
 */
function handleAddMembers($pdo, $tenant_id, $user_id, $input) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel id is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    if (!isset($input['members']) || !is_array($input['members']) || empty($input['members'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Members array is required and cannot be empty',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$_GET['id'];
    $new_members = $input['members'];

    // Verify user has permission to add members
    $sql = "SELECT cm.role, c.name, c.is_private
            FROM channel_members cm
            INNER JOIN chat_channels c ON cm.channel_id = c.id
            WHERE cm.channel_id = :channel_id
            AND cm.tenant_id = :tenant_id
            AND cm.user_id = :user_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ]);

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel not found or access denied',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Check permissions based on channel privacy
    if ($membership['is_private'] && !in_array($membership['role'], ['owner', 'admin', 'moderator'])) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Only channel moderators and above can add members to private channels',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $pdo->beginTransaction();

    try {
        $added_users = [];
        $already_members = [];
        $invalid_users = [];

        foreach ($new_members as $member) {
            if (is_array($member)) {
                $member_id = $member['user_id'];
                $role = isset($member['role']) && in_array($member['role'], ['member', 'moderator']) ? $member['role'] : 'member';
            } else {
                $member_id = $member;
                $role = 'member';
            }

            // Verify user exists in tenant
            $sql = "SELECT nome FROM users
                    WHERE id = :user_id
                    AND tenant_id = :tenant_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $member_id,
                ':tenant_id' => $tenant_id
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $invalid_users[] = $member_id;
                continue;
            }

            // Check if already a member
            $sql = "SELECT COUNT(*) FROM channel_members
                    WHERE channel_id = :channel_id
                    AND tenant_id = :tenant_id
                    AND user_id = :user_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':channel_id' => $channel_id,
                ':tenant_id' => $tenant_id,
                ':user_id' => $member_id
            ]);

            if ($stmt->fetchColumn() > 0) {
                $already_members[] = $member_id;
                continue;
            }

            // Add member
            $sql = "INSERT INTO channel_members (
                        tenant_id,
                        channel_id,
                        user_id,
                        role,
                        joined_at
                    ) VALUES (
                        :tenant_id,
                        :channel_id,
                        :user_id,
                        :role,
                        NOW()
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':channel_id' => $channel_id,
                ':user_id' => $member_id,
                ':role' => $role
            ]);

            $added_users[] = [
                'user_id' => $member_id,
                'name' => $user['nome'],
                'role' => $role
            ];

            // Send notification
            $sql = "INSERT INTO notifications (
                        tenant_id,
                        user_id,
                        type,
                        title,
                        message,
                        data,
                        created_at
                    ) VALUES (
                        :tenant_id,
                        :user_id,
                        'channel_invite',
                        'Added to channel',
                        :message,
                        :data,
                        NOW()
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':user_id' => $member_id,
                ':message' => "You've been added to the channel: {$membership['name']}",
                ':data' => json_encode(['channel_id' => $channel_id])
            ]);
        }

        // Update member count
        $sql = "UPDATE chat_channels
                SET member_count = (
                    SELECT COUNT(*)
                    FROM channel_members
                    WHERE channel_id = :channel_id
                    AND tenant_id = :tenant_id
                )
                WHERE id = :channel_id2
                AND tenant_id = :tenant_id2";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':channel_id' => $channel_id,
            ':channel_id2' => $channel_id,
            ':tenant_id' => $tenant_id,
            ':tenant_id2' => $tenant_id
        ]);

        $pdo->commit();

        $response_data = [
            'added' => $added_users,
            'already_members' => $already_members,
            'invalid_users' => $invalid_users
        ];

        $message = count($added_users) > 0
            ? count($added_users) . ' member(s) added successfully'
            : 'No new members were added';

        echo json_encode([
            'success' => true,
            'data' => $response_data,
            'message' => $message,
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * DELETE /api/channels.php?id=X&action=members&user_id=Y
 * Remove a member from a channel
 */
function handleRemoveMember($pdo, $tenant_id, $user_id) {
    if (!isset($_GET['id']) || !isset($_GET['user_id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel id and user_id are required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$_GET['id'];
    $target_user_id = (int)$_GET['user_id'];

    // Get channel and membership info
    $sql = "SELECT
                c.name,
                cm.role AS requester_role,
                cm_target.role AS target_role
            FROM chat_channels c
            INNER JOIN channel_members cm ON c.id = cm.channel_id
            LEFT JOIN channel_members cm_target ON c.id = cm_target.channel_id AND cm_target.user_id = :target_user_id
            WHERE c.id = :channel_id
            AND c.tenant_id = :tenant_id
            AND cm.user_id = :user_id
            AND cm.tenant_id = :tenant_id2";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':channel_id' => $channel_id,
        ':tenant_id' => $tenant_id,
        ':tenant_id2' => $tenant_id,
        ':user_id' => $user_id,
        ':target_user_id' => $target_user_id
    ]);

    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$info) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Channel not found or access denied',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    if (!$info['target_role']) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'User is not a member of this channel',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Check permissions
    $can_remove = false;

    // Users can remove themselves
    if ($target_user_id === $user_id && $info['target_role'] !== 'owner') {
        $can_remove = true;
    }
    // Owners can remove anyone except other owners
    elseif ($info['requester_role'] === 'owner' && $info['target_role'] !== 'owner') {
        $can_remove = true;
    }
    // Admins can remove members and moderators
    elseif ($info['requester_role'] === 'admin' && in_array($info['target_role'], ['member', 'moderator'])) {
        $can_remove = true;
    }
    // Moderators can remove members
    elseif ($info['requester_role'] === 'moderator' && $info['target_role'] === 'member') {
        $can_remove = true;
    }

    if (!$can_remove) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'You do not have permission to remove this member',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $pdo->beginTransaction();

    try {
        // Remove member
        $sql = "DELETE FROM channel_members
                WHERE channel_id = :channel_id
                AND tenant_id = :tenant_id
                AND user_id = :user_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':channel_id' => $channel_id,
            ':tenant_id' => $tenant_id,
            ':user_id' => $target_user_id
        ]);

        // Update member count
        $sql = "UPDATE chat_channels
                SET member_count = member_count - 1
                WHERE id = :channel_id
                AND tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':channel_id' => $channel_id,
            ':tenant_id' => $tenant_id
        ]);

        // Send notification if user was removed by someone else
        if ($target_user_id !== $user_id) {
            $sql = "INSERT INTO notifications (
                        tenant_id,
                        user_id,
                        type,
                        title,
                        message,
                        data,
                        created_at
                    ) VALUES (
                        :tenant_id,
                        :user_id,
                        'channel_removed',
                        'Removed from channel',
                        :message,
                        :data,
                        NOW()
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':user_id' => $target_user_id,
                ':message' => "You've been removed from the channel: {$info['name']}",
                ':data' => json_encode(['channel_id' => $channel_id])
            ]);
        }

        $pdo->commit();

        $message = $target_user_id === $user_id
            ? 'You have left the channel'
            : 'Member removed successfully';

        echo json_encode([
            'success' => true,
            'data' => [
                'channel_id' => $channel_id,
                'removed_user_id' => $target_user_id
            ],
            'message' => $message,
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}