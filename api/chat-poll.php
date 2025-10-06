<?php
/**
 * Long-Polling Endpoint for Real-time Chat Updates
 *
 * Endpoint:
 * - GET /api/chat-poll.php?last_sequence_id=X&channels=1,2,3
 *
 * Returns:
 * - New messages since last_sequence_id
 * - Presence updates (users coming online/offline)
 * - Typing indicators
 * - Channel updates (member changes, settings)
 * - Notifications
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
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Ensure this is a GET request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'data' => null,
        'message' => 'Method not allowed. Only GET requests are accepted',
        'metadata' => [
            'timestamp' => date('c')
        ]
    ]));
}

require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Rate limiting headers
header('X-RateLimit-Limit: 1000');
header('X-RateLimit-Remaining: 999');
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

// Validate parameters
$last_sequence_id = isset($_GET['last_sequence_id']) ? (int)$_GET['last_sequence_id'] : 0;
$channels = isset($_GET['channels']) ? $_GET['channels'] : null;
$timeout = isset($_GET['timeout']) ? min((int)$_GET['timeout'], 30) : 25; // Max 30 seconds

// Parse channel IDs
$channel_ids = [];
if ($channels) {
    $channel_ids = array_map('intval', explode(',', $channels));
    if (count($channel_ids) > 100) {
        http_response_code(400);
        die(json_encode([
            'success' => false,
            'data' => null,
            'message' => 'Maximum 100 channels can be monitored at once',
            'metadata' => [
                'timestamp' => date('c')
            ]
        ]));
    }
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Update user's last seen timestamp
    updateUserPresence($pdo, $tenant_id, $user_id);

    // Start long polling
    $start_time = time();
    $poll_interval = 1; // Check every 1 second
    $updates = [];

    while (time() - $start_time < $timeout) {
        // Check for new messages
        $messages = getNewMessages($pdo, $tenant_id, $user_id, $last_sequence_id, $channel_ids);

        // Check for presence updates
        $presence = getPresenceUpdates($pdo, $tenant_id, $user_id, $channel_ids);

        // Check for typing indicators
        $typing = getTypingIndicators($pdo, $tenant_id, $user_id, $channel_ids);

        // Check for notifications
        $notifications = getNewNotifications($pdo, $tenant_id, $user_id, $last_sequence_id);

        // Check for channel updates
        $channel_updates = getChannelUpdates($pdo, $tenant_id, $user_id, $channel_ids, $last_sequence_id);

        // If we have any updates, return them
        if (!empty($messages) || !empty($presence) || !empty($typing) || !empty($notifications) || !empty($channel_updates)) {
            $updates = [
                'messages' => $messages,
                'presence' => $presence,
                'typing' => $typing,
                'notifications' => $notifications,
                'channel_updates' => $channel_updates
            ];
            break;
        }

        // Sleep for a bit before checking again
        sleep($poll_interval);

        // Send keep-alive to prevent timeout
        if ((time() - $start_time) % 10 == 0) {
            echo " "; // Send a space character to keep connection alive
            ob_flush();
            flush();
        }
    }

    // Determine the new last_sequence_id
    $new_last_sequence_id = $last_sequence_id;
    if (!empty($messages)) {
        $last_message = end($messages);
        $new_last_sequence_id = max($new_last_sequence_id, $last_message['sequence_id']);
    }

    // Return response
    echo json_encode([
        'success' => true,
        'data' => $updates ?: [
            'messages' => [],
            'presence' => [],
            'typing' => [],
            'notifications' => [],
            'channel_updates' => []
        ],
        'message' => empty($updates) ? 'No new updates' : 'Updates retrieved successfully',
        'metadata' => [
            'last_sequence_id' => $new_last_sequence_id,
            'poll_duration' => time() - $start_time,
            'timestamp' => date('c')
        ]
    ]);

} catch (Exception $e) {
    error_log("Chat Poll Error: " . $e->getMessage());
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
 * Update user's presence status
 */
function updateUserPresence($pdo, $tenant_id, $user_id) {
    $sql = "UPDATE users
            SET is_online = 1,
                last_seen = NOW(),
                last_activity = NOW()
            WHERE id = :user_id
            AND tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $user_id,
        ':tenant_id' => $tenant_id
    ]);

    // Update presence in Redis or cache if available
    // This would be more efficient with Redis for real-time systems
}

/**
 * Get new messages since last_sequence_id
 */
function getNewMessages($pdo, $tenant_id, $user_id, $last_sequence_id, $channel_ids) {
    // Build query based on whether specific channels are requested
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
                c.name AS channel_name,
                c.channel_type,
                -- Get reactions summary
                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'emoji', r.emoji,
                        'count', r.count,
                        'has_reacted', r.has_reacted
                    )
                ) FROM (
                    SELECT
                        emoji,
                        COUNT(*) as count,
                        MAX(CASE WHEN user_id = :current_user THEN 1 ELSE 0 END) as has_reacted
                    FROM message_reactions
                    WHERE message_id = m.id
                    AND tenant_id = m.tenant_id
                    GROUP BY emoji
                ) r) AS reactions,
                -- Get mentions for current user
                (SELECT COUNT(*)
                FROM message_mentions
                WHERE message_id = m.id
                AND mentioned_user_id = :current_user2
                AND tenant_id = m.tenant_id) AS mentions_me,
                -- Get attachments
                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'id', a.id,
                        'file_name', a.file_name,
                        'file_type', a.file_type,
                        'file_size', a.file_size,
                        'file_url', a.file_url
                    )
                ) FROM message_attachments a
                WHERE a.message_id = m.id
                AND a.tenant_id = m.tenant_id) AS attachments
            FROM chat_messages m
            INNER JOIN users u ON m.user_id = u.id
            INNER JOIN chat_channels c ON m.channel_id = c.id
            INNER JOIN channel_members cm ON c.id = cm.channel_id AND cm.user_id = :user_id
            WHERE m.tenant_id = :tenant_id
            AND cm.tenant_id = :tenant_id2
            AND m.sequence_id > :last_sequence_id
            AND m.is_deleted = 0";

    $params = [
        ':tenant_id' => $tenant_id,
        ':tenant_id2' => $tenant_id,
        ':user_id' => $user_id,
        ':current_user' => $user_id,
        ':current_user2' => $user_id,
        ':last_sequence_id' => $last_sequence_id
    ];

    // Filter by specific channels if provided
    if (!empty($channel_ids)) {
        $placeholders = array_map(function($i) { return ":channel_$i"; }, range(0, count($channel_ids) - 1));
        $sql .= " AND m.channel_id IN (" . implode(',', $placeholders) . ")";
        foreach ($channel_ids as $i => $channel_id) {
            $params[":channel_$i"] = $channel_id;
        }
    }

    $sql .= " ORDER BY m.sequence_id ASC LIMIT 100"; // Limit to 100 messages per poll

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process JSON fields and format data
    foreach ($messages as &$message) {
        $message['reactions'] = json_decode($message['reactions'], true) ?: [];
        $message['attachments'] = json_decode($message['attachments'], true) ?: [];
        $message['mentions_me'] = (bool)$message['mentions_me'];
        $message['user_online'] = (bool)$message['user_online'];
        $message['is_edited'] = (bool)$message['is_edited'];
        $message['is_pinned'] = (bool)$message['is_pinned'];

        // Mark message as delivered
        markMessageDelivered($pdo, $tenant_id, $user_id, $message['id'], $message['channel_id']);
    }

    return $messages;
}

/**
 * Mark message as delivered for user
 */
function markMessageDelivered($pdo, $tenant_id, $user_id, $message_id, $channel_id) {
    // Update last read message for user in channel
    $sql = "UPDATE channel_members
            SET last_read_message_id = GREATEST(last_read_message_id, :message_id),
                unread_count = GREATEST(0, unread_count - 1)
            WHERE channel_id = :channel_id
            AND user_id = :user_id
            AND tenant_id = :tenant_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':message_id' => $message_id,
        ':channel_id' => $channel_id,
        ':user_id' => $user_id,
        ':tenant_id' => $tenant_id
    ]);
}

/**
 * Get presence updates for users in channels
 */
function getPresenceUpdates($pdo, $tenant_id, $user_id, $channel_ids) {
    $sql = "SELECT DISTINCT
                u.id,
                u.nome AS name,
                u.email,
                u.avatar_url,
                u.is_online,
                u.last_seen,
                u.status_message,
                CASE
                    WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) THEN 'online'
                    WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'away'
                    ELSE 'offline'
                END AS status
            FROM users u
            INNER JOIN channel_members cm ON u.id = cm.user_id
            WHERE cm.tenant_id = :tenant_id
            AND u.id != :user_id
            AND (
                u.last_activity >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                OR u.presence_updated >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            )";

    $params = [
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ];

    // Filter by specific channels if provided
    if (!empty($channel_ids)) {
        $placeholders = array_map(function($i) { return ":channel_$i"; }, range(0, count($channel_ids) - 1));
        $sql .= " AND cm.channel_id IN (" . implode(',', $placeholders) . ")";
        foreach ($channel_ids as $i => $channel_id) {
            $params[":channel_$i"] = $channel_id;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $presence_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($presence_updates as &$update) {
        $update['is_online'] = (bool)$update['is_online'];
    }

    return $presence_updates;
}

/**
 * Get typing indicators for channels
 */
function getTypingIndicators($pdo, $tenant_id, $user_id, $channel_ids) {
    $sql = "SELECT
                ti.channel_id,
                ti.user_id,
                u.nome AS user_name,
                u.avatar_url,
                ti.started_at
            FROM typing_indicators ti
            INNER JOIN users u ON ti.user_id = u.id
            WHERE ti.tenant_id = :tenant_id
            AND ti.user_id != :user_id
            AND ti.started_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)"; // Typing indicators expire after 10 seconds

    $params = [
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ];

    // Filter by specific channels if provided
    if (!empty($channel_ids)) {
        $placeholders = array_map(function($i) { return ":channel_$i"; }, range(0, count($channel_ids) - 1));
        $sql .= " AND ti.channel_id IN (" . implode(',', $placeholders) . ")";
        foreach ($channel_ids as $i => $channel_id) {
            $params[":channel_$i"] = $channel_id;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $typing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by channel
    $typing_by_channel = [];
    foreach ($typing as $indicator) {
        $channel_id = $indicator['channel_id'];
        if (!isset($typing_by_channel[$channel_id])) {
            $typing_by_channel[$channel_id] = [
                'channel_id' => $channel_id,
                'users' => []
            ];
        }
        $typing_by_channel[$channel_id]['users'][] = [
            'user_id' => $indicator['user_id'],
            'user_name' => $indicator['user_name'],
            'avatar_url' => $indicator['avatar_url']
        ];
    }

    return array_values($typing_by_channel);
}

/**
 * Get new notifications for user
 */
function getNewNotifications($pdo, $tenant_id, $user_id, $last_sequence_id) {
    $sql = "SELECT
                n.id,
                n.type,
                n.title,
                n.message,
                n.data,
                n.is_read,
                n.created_at
            FROM notifications n
            WHERE n.tenant_id = :tenant_id
            AND n.user_id = :user_id
            AND n.id > :last_notification_id
            AND n.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY n.id DESC
            LIMIT 20";

    // We're using the same sequence ID concept for notifications
    // In production, you might want a separate tracking mechanism
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id,
        ':last_notification_id' => $last_sequence_id
    ]);

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$notification) {
        $notification['data'] = json_decode($notification['data'], true);
        $notification['is_read'] = (bool)$notification['is_read'];
    }

    return $notifications;
}

/**
 * Get channel updates (member changes, settings changes, etc.)
 */
function getChannelUpdates($pdo, $tenant_id, $user_id, $channel_ids, $last_sequence_id) {
    $updates = [];

    // Get member changes
    $sql = "SELECT
                cl.id,
                cl.channel_id,
                cl.action,
                cl.user_id AS affected_user_id,
                cl.details,
                cl.created_at,
                c.name AS channel_name,
                u.nome AS affected_user_name
            FROM channel_activity_logs cl
            INNER JOIN chat_channels c ON cl.channel_id = c.id
            LEFT JOIN users u ON cl.user_id = u.id
            INNER JOIN channel_members cm ON c.id = cm.channel_id AND cm.user_id = :user_id
            WHERE cl.tenant_id = :tenant_id
            AND cm.tenant_id = :tenant_id2
            AND cl.id > :last_log_id
            AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";

    $params = [
        ':tenant_id' => $tenant_id,
        ':tenant_id2' => $tenant_id,
        ':user_id' => $user_id,
        ':last_log_id' => $last_sequence_id
    ];

    // Filter by specific channels if provided
    if (!empty($channel_ids)) {
        $placeholders = array_map(function($i) { return ":channel_$i"; }, range(0, count($channel_ids) - 1));
        $sql .= " AND cl.channel_id IN (" . implode(',', $placeholders) . ")";
        foreach ($channel_ids as $i => $channel_id) {
            $params[":channel_$i"] = $channel_id;
        }
    }

    $sql .= " ORDER BY cl.id DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($activity_logs as &$log) {
        $log['details'] = json_decode($log['details'], true);
    }

    // Get unread counts for channels
    $sql = "SELECT
                cm.channel_id,
                cm.unread_count,
                c.name AS channel_name,
                c.last_message_at
            FROM channel_members cm
            INNER JOIN chat_channels c ON cm.channel_id = c.id
            WHERE cm.tenant_id = :tenant_id
            AND cm.user_id = :user_id
            AND cm.unread_count > 0";

    $params = [
        ':tenant_id' => $tenant_id,
        ':user_id' => $user_id
    ];

    // Filter by specific channels if provided
    if (!empty($channel_ids)) {
        $placeholders = array_map(function($i) { return ":channel_$i"; }, range(0, count($channel_ids) - 1));
        $sql .= " AND cm.channel_id IN (" . implode(',', $placeholders) . ")";
        foreach ($channel_ids as $i => $channel_id) {
            $params[":channel_$i"] = $channel_id;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $unread_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'activity' => $activity_logs,
        'unread_counts' => $unread_counts
    ];
}