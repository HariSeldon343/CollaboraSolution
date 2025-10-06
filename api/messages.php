<?php
/**
 * REST API for Messages Resource
 *
 * Endpoints:
 * - GET    /api/messages.php?channel_id=X&last_id=Y&limit=50  - Get paginated messages
 * - POST   /api/messages.php                                  - Send new message
 * - PUT    /api/messages.php?id=X                            - Edit existing message
 * - DELETE /api/messages.php?id=X                            - Soft delete message
 * - POST   /api/messages.php?id=X&action=reaction            - Add emoji reaction
 * - DELETE /api/messages.php?id=X&action=reaction&emoji=Y    - Remove reaction
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
header('X-RateLimit-Remaining: 99'); // In production, calculate from actual usage
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

    // Route based on HTTP method
    switch ($method) {
        case 'GET':
            handleGetMessages($pdo, $tenant_id, $user_id);
            break;

        case 'POST':
            $action = $_GET['action'] ?? 'send';
            if ($action === 'reaction') {
                handleAddReaction($pdo, $tenant_id, $user_id, $input);
            } else {
                handleSendMessage($pdo, $tenant_id, $user_id, $input);
            }
            break;

        case 'PUT':
            handleEditMessage($pdo, $tenant_id, $user_id, $input);
            break;

        case 'DELETE':
            $action = $_GET['action'] ?? 'delete';
            if ($action === 'reaction') {
                handleRemoveReaction($pdo, $tenant_id, $user_id);
            } else {
                handleDeleteMessage($pdo, $tenant_id, $user_id);
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
    error_log("Messages API Error: " . $e->getMessage());
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
 * GET /api/messages.php?channel_id=X&last_id=Y&limit=50
 * Retrieve paginated messages from a channel
 */
function handleGetMessages($pdo, $tenant_id, $user_id) {
    // Validate required parameters
    if (!isset($_GET['channel_id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'channel_id parameter is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$_GET['channel_id'];
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;

    // Verify channel access
    $sql = "SELECT COUNT(*) FROM channel_members
            WHERE tenant_id = :tenant_id
            AND user_id = :user_id
            AND channel_id = :channel_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id,
        ':channel_id' => $channel_id
    ]);

    if ($stmt->fetchColumn() == 0) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Access to channel denied',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Get total message count
    $sql = "SELECT COUNT(*) FROM chat_messages
            WHERE tenant_id = :tenant_id
            AND channel_id = :channel_id
            AND is_deleted = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':channel_id' => $channel_id
    ]);
    $total_count = $stmt->fetchColumn();

    // Build query with pagination
    $sql = "SELECT
                m.id,
                m.sequence_id,
                m.channel_id,
                m.user_id,
                m.parent_message_id,
                m.message_type,
                m.content,
                m.is_edited,
                m.edit_count,
                m.is_pinned,
                m.reply_count,
                m.reaction_count,
                m.created_at,
                m.updated_at,
                u.nome AS user_name,
                u.email AS user_email,
                u.avatar_url AS user_avatar,
                u.is_online AS user_online,
                u.last_seen AS user_last_seen,
                -- Get reactions
                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'emoji', r.emoji,
                        'count', r.count,
                        'users', r.users
                    )
                ) FROM (
                    SELECT
                        emoji,
                        COUNT(*) as count,
                        JSON_ARRAYAGG(user_id) as users
                    FROM message_reactions
                    WHERE message_id = m.id
                    AND tenant_id = m.tenant_id
                    GROUP BY emoji
                ) r) AS reactions,
                -- Get parent message if this is a reply
                IF(m.parent_message_id IS NOT NULL,
                    (SELECT JSON_OBJECT(
                        'id', p.id,
                        'user_id', p.user_id,
                        'user_name', pu.nome,
                        'content', SUBSTRING(p.content, 1, 100)
                    ) FROM chat_messages p
                    LEFT JOIN users pu ON p.user_id = pu.id
                    WHERE p.id = m.parent_message_id
                    AND p.tenant_id = m.tenant_id
                    AND p.is_deleted = 0),
                    NULL
                ) AS parent_message,
                -- Get mentions
                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'user_id', mm.mentioned_user_id,
                        'user_name', mu.nome,
                        'position', mm.position
                    )
                ) FROM message_mentions mm
                LEFT JOIN users mu ON mm.mentioned_user_id = mu.id
                WHERE mm.message_id = m.id
                AND mm.tenant_id = m.tenant_id) AS mentions
            FROM chat_messages m
            INNER JOIN users u ON m.user_id = u.id
            WHERE m.tenant_id = :tenant_id
            AND m.channel_id = :channel_id
            AND m.is_deleted = 0";

    // Add last_id condition for cursor pagination
    if ($last_id > 0) {
        $sql .= " AND m.sequence_id > :last_id";
    }

    $sql .= " ORDER BY m.sequence_id ASC
              LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':tenant_id', $tenant_id, PDO::PARAM_INT);
    $stmt->bindValue(':channel_id', $channel_id, PDO::PARAM_INT);
    if ($last_id > 0) {
        $stmt->bindValue(':last_id', $last_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process JSON fields
    foreach ($messages as &$message) {
        $message['reactions'] = json_decode($message['reactions'], true) ?: [];
        $message['parent_message'] = json_decode($message['parent_message'], true);
        $message['mentions'] = json_decode($message['mentions'], true) ?: [];
    }

    // Determine if there are more messages
    $has_more = count($messages) === $limit;
    $last_sequence_id = !empty($messages) ? $messages[count($messages) - 1]['sequence_id'] : 0;

    echo json_encode([
        'success' => true,
        'data' => $messages,
        'message' => 'Messages retrieved successfully',
        'metadata' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total_count,
            'has_more' => $has_more,
            'last_sequence_id' => $last_sequence_id,
            'timestamp' => date('c')
        ]
    ]);
}

/**
 * POST /api/messages.php
 * Send a new message with @mentions and markdown support
 */
function handleSendMessage($pdo, $tenant_id, $user_id, $input) {
    // Validate input
    if (!isset($input['channel_id']) || !isset($input['content'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'channel_id and content are required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $channel_id = (int)$input['channel_id'];
    $content = trim($input['content']);
    $parent_message_id = isset($input['parent_message_id']) ? (int)$input['parent_message_id'] : null;
    $message_type = isset($input['message_type']) ? $input['message_type'] : 'text';
    $attachments = isset($input['attachments']) ? $input['attachments'] : [];

    // Validate message type
    $valid_types = ['text', 'file', 'image', 'code', 'poll', 'system'];
    if (!in_array($message_type, $valid_types)) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Invalid message type. Must be one of: ' . implode(', ', $valid_types),
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Validate content
    if (empty($content)) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message content cannot be empty',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    if (strlen($content) > 10000) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message content exceeds maximum length of 10000 characters',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Verify channel membership
    $sql = "SELECT role FROM channel_members
            WHERE tenant_id = :tenant_id
            AND user_id = :user_id
            AND channel_id = :channel_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id,
        ':channel_id' => $channel_id
    ]);

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$membership) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'You are not a member of this channel',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Get next sequence ID
        $sql = "SELECT COALESCE(MAX(sequence_id), 0) + 1 as next_seq
                FROM chat_messages
                WHERE tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tenant_id' => $tenant_id]);
        $next_sequence = $stmt->fetch(PDO::FETCH_ASSOC)['next_seq'];

        // Parse mentions from content
        $mentions = [];
        if (preg_match_all('/@(\w+)/', $content, $matches)) {
            foreach ($matches[1] as $position => $username) {
                $sql = "SELECT id FROM users
                        WHERE nome = :username
                        AND tenant_id = :tenant_id";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':username' => $username,
                    ':tenant_id' => $tenant_id
                ]);

                $mentioned_user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($mentioned_user) {
                    $mentions[] = [
                        'user_id' => $mentioned_user['id'],
                        'position' => strpos($content, '@' . $username)
                    ];
                }
            }
        }

        // Insert message
        $sql = "INSERT INTO chat_messages (
                    tenant_id,
                    channel_id,
                    user_id,
                    parent_message_id,
                    message_type,
                    content,
                    content_plain,
                    sequence_id,
                    created_at
                ) VALUES (
                    :tenant_id,
                    :channel_id,
                    :user_id,
                    :parent_message_id,
                    :message_type,
                    :content,
                    :content_plain,
                    :sequence_id,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':channel_id' => $channel_id,
            ':user_id' => $user_id,
            ':parent_message_id' => $parent_message_id,
            ':message_type' => $message_type,
            ':content' => $content,
            ':content_plain' => strip_tags($content),
            ':sequence_id' => $next_sequence
        ]);

        $message_id = $pdo->lastInsertId();

        // Insert mentions
        foreach ($mentions as $mention) {
            $sql = "INSERT INTO message_mentions (
                        tenant_id,
                        message_id,
                        mentioned_user_id,
                        position
                    ) VALUES (
                        :tenant_id,
                        :message_id,
                        :mentioned_user_id,
                        :position
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':message_id' => $message_id,
                ':mentioned_user_id' => $mention['user_id'],
                ':position' => $mention['position']
            ]);

            // Create notification for mentioned user
            $sql = "INSERT INTO notifications (
                        tenant_id,
                        user_id,
                        type,
                        title,
                        message,
                        data
                    ) VALUES (
                        :tenant_id,
                        :user_id,
                        'mention',
                        'You were mentioned',
                        :message,
                        :data
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':user_id' => $mention['user_id'],
                ':message' => substr($content, 0, 100),
                ':data' => json_encode([
                    'channel_id' => $channel_id,
                    'message_id' => $message_id
                ])
            ]);
        }

        // Handle attachments
        foreach ($attachments as $attachment) {
            $sql = "INSERT INTO message_attachments (
                        tenant_id,
                        message_id,
                        file_name,
                        file_type,
                        file_size,
                        file_url
                    ) VALUES (
                        :tenant_id,
                        :message_id,
                        :file_name,
                        :file_type,
                        :file_size,
                        :file_url
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':message_id' => $message_id,
                ':file_name' => $attachment['name'],
                ':file_type' => $attachment['type'],
                ':file_size' => $attachment['size'],
                ':file_url' => $attachment['url']
            ]);
        }

        // Update channel statistics
        $sql = "UPDATE chat_channels
                SET last_message_at = NOW(),
                    message_count = message_count + 1
                WHERE id = :channel_id
                AND tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':channel_id' => $channel_id,
            ':tenant_id' => $tenant_id
        ]);

        // Update parent message reply count if this is a reply
        if ($parent_message_id) {
            $sql = "UPDATE chat_messages
                    SET reply_count = reply_count + 1
                    WHERE id = :parent_id
                    AND tenant_id = :tenant_id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':parent_id' => $parent_message_id,
                ':tenant_id' => $tenant_id
            ]);
        }

        $pdo->commit();

        // Retrieve the complete message
        $sql = "SELECT
                    m.id,
                    m.sequence_id,
                    m.channel_id,
                    m.user_id,
                    m.parent_message_id,
                    m.message_type,
                    m.content,
                    m.created_at,
                    u.nome AS user_name,
                    u.email AS user_email,
                    u.avatar_url AS user_avatar
                FROM chat_messages m
                INNER JOIN users u ON m.user_id = u.id
                WHERE m.id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data' => $message,
            'message' => 'Message sent successfully',
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
 * PUT /api/messages.php?id=X
 * Edit an existing message
 */
function handleEditMessage($pdo, $tenant_id, $user_id, $input) {
    if (!isset($_GET['id']) || !isset($input['content'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message id and content are required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $message_id = (int)$_GET['id'];
    $new_content = trim($input['content']);

    if (empty($new_content)) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message content cannot be empty',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Verify ownership
    $sql = "SELECT content, user_id
            FROM chat_messages
            WHERE id = :id
            AND tenant_id = :tenant_id
            AND is_deleted = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $message_id,
        ':tenant_id' => $tenant_id
    ]);

    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message not found',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    if ($message['user_id'] != $user_id) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'You can only edit your own messages',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $pdo->beginTransaction();

    try {
        // Save edit history
        $sql = "INSERT INTO message_edits (
                    tenant_id,
                    message_id,
                    user_id,
                    previous_content,
                    new_content,
                    edited_at
                ) VALUES (
                    :tenant_id,
                    :message_id,
                    :user_id,
                    :previous_content,
                    :new_content,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':message_id' => $message_id,
            ':user_id' => $user_id,
            ':previous_content' => $message['content'],
            ':new_content' => $new_content
        ]);

        // Update message
        $sql = "UPDATE chat_messages
                SET content = :content,
                    content_plain = :content_plain,
                    is_edited = 1,
                    edit_count = edit_count + 1,
                    updated_at = NOW()
                WHERE id = :id
                AND tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':content' => $new_content,
            ':content_plain' => strip_tags($new_content),
            ':id' => $message_id,
            ':tenant_id' => $tenant_id
        ]);

        $pdo->commit();

        // Retrieve updated message
        $sql = "SELECT
                    m.id,
                    m.content,
                    m.is_edited,
                    m.edit_count,
                    m.updated_at
                FROM chat_messages m
                WHERE m.id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $message_id]);
        $updated_message = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $updated_message,
            'message' => 'Message updated successfully',
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
 * DELETE /api/messages.php?id=X
 * Soft delete a message
 */
function handleDeleteMessage($pdo, $tenant_id, $user_id) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message id is required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $message_id = (int)$_GET['id'];

    // Verify ownership or admin privileges
    $sql = "SELECT m.user_id, u.ruolo
            FROM chat_messages m
            INNER JOIN users u ON u.id = :user_id
            WHERE m.id = :message_id
            AND m.tenant_id = :tenant_id
            AND m.is_deleted = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':message_id' => $message_id,
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message not found',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $canDelete = $result['user_id'] == $user_id ||
                 in_array($result['ruolo'], ['admin', 'super_admin']);

    if (!$canDelete) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'You do not have permission to delete this message',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Soft delete the message
    $sql = "UPDATE chat_messages
            SET is_deleted = 1,
                deleted_at = NOW(),
                deleted_by = :deleted_by
            WHERE id = :id
            AND tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $message_id,
        ':tenant_id' => $tenant_id,
        ':deleted_by' => $user_id
    ]);

    echo json_encode([
        'success' => true,
        'data' => ['message_id' => $message_id],
        'message' => 'Message deleted successfully',
        'metadata' => [
            'timestamp' => date('c')
        ]
    ]);
}

/**
 * POST /api/messages.php?id=X&action=reaction
 * Add an emoji reaction to a message
 */
function handleAddReaction($pdo, $tenant_id, $user_id, $input) {
    if (!isset($_GET['id']) || !isset($input['emoji'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message id and emoji are required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $message_id = (int)$_GET['id'];
    $emoji = $input['emoji'];

    // Validate emoji (basic validation - you might want to expand this)
    $allowed_emojis = ['👍', '❤️', '😂', '😮', '😢', '🎉', '🤔', '👎'];
    if (!in_array($emoji, $allowed_emojis)) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Invalid emoji. Allowed emojis: ' . implode(' ', $allowed_emojis),
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Verify message exists and user has access
    $sql = "SELECT m.id
            FROM chat_messages m
            INNER JOIN channel_members cm ON cm.channel_id = m.channel_id
            WHERE m.id = :message_id
            AND m.tenant_id = :tenant_id
            AND m.is_deleted = 0
            AND cm.user_id = :user_id
            AND cm.tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':message_id' => $message_id,
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ]);

    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message not found or access denied',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $pdo->beginTransaction();

    try {
        // Check if reaction already exists
        $sql = "SELECT id FROM message_reactions
                WHERE tenant_id = :tenant_id
                AND message_id = :message_id
                AND user_id = :user_id
                AND emoji = :emoji";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':message_id' => $message_id,
            ':user_id' => $user_id,
            ':emoji' => $emoji
        ]);

        if ($stmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            die(json_encode([
                'success' => false,
                'data' => null,
                'message' => 'You have already added this reaction',
                'metadata' => [
                    'timestamp' => date('c')
                ]
            ]));
        }

        // Add reaction
        $sql = "INSERT INTO message_reactions (
                    tenant_id,
                    message_id,
                    user_id,
                    emoji,
                    created_at
                ) VALUES (
                    :tenant_id,
                    :message_id,
                    :user_id,
                    :emoji,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':message_id' => $message_id,
            ':user_id' => $user_id,
            ':emoji' => $emoji
        ]);

        // Update reaction count
        $sql = "UPDATE chat_messages
                SET reaction_count = (
                    SELECT COUNT(DISTINCT user_id)
                    FROM message_reactions
                    WHERE message_id = :message_id
                    AND tenant_id = :tenant_id
                )
                WHERE id = :message_id
                AND tenant_id = :tenant_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':message_id' => $message_id,
            ':tenant_id' => $tenant_id
        ]);

        $pdo->commit();

        // Get updated reaction counts
        $sql = "SELECT
                    emoji,
                    COUNT(*) as count,
                    JSON_ARRAYAGG(user_id) as users
                FROM message_reactions
                WHERE message_id = :message_id
                AND tenant_id = :tenant_id
                GROUP BY emoji";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':message_id' => $message_id,
            ':tenant_id' => $tenant_id
        ]);

        $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reactions as &$reaction) {
            $reaction['users'] = json_decode($reaction['users']);
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data' => [
                'message_id' => $message_id,
                'reactions' => $reactions
            ],
            'message' => 'Reaction added successfully',
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
 * DELETE /api/messages.php?id=X&action=reaction&emoji=Y
 * Remove a reaction from a message
 */
function handleRemoveReaction($pdo, $tenant_id, $user_id) {
    if (!isset($_GET['id']) || !isset($_GET['emoji'])) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Message id and emoji are required',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    $message_id = (int)$_GET['id'];
    $emoji = $_GET['emoji'];

    // Delete the reaction
    $sql = "DELETE FROM message_reactions
            WHERE tenant_id = :tenant_id
            AND message_id = :message_id
            AND user_id = :user_id
            AND emoji = :emoji";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':message_id' => $message_id,
        ':user_id' => $user_id,
        ':emoji' => $emoji
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Reaction not found',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }

    // Update reaction count
    $sql = "UPDATE chat_messages
            SET reaction_count = (
                SELECT COUNT(DISTINCT user_id)
                FROM message_reactions
                WHERE message_id = :message_id
                AND tenant_id = :tenant_id
            )
            WHERE id = :message_id
            AND tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':message_id' => $message_id,
        ':tenant_id' => $tenant_id
    ]);

    echo json_encode([
        'success' => true,
        'data' => [
            'message_id' => $message_id,
            'emoji' => $emoji
        ],
        'message' => 'Reaction removed successfully',
        'metadata' => [
            'timestamp' => date('c')
        ]
    ]);
}