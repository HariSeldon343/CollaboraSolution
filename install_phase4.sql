-- Module: Real-Time Chat System
-- Version: 2025-01-22
-- Author: Database Architect
-- Description: Complete chat system schema with long-polling support for CollaboraNexio Phase 4

USE collabora;

-- ============================================
-- CLEANUP (Development only - Comment out in production)
-- ============================================
-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS message_read_receipts;
DROP TABLE IF EXISTS message_mentions;
DROP TABLE IF EXISTS message_attachments;
DROP TABLE IF EXISTS message_reactions;
DROP TABLE IF EXISTS message_edits;
DROP TABLE IF EXISTS chat_typing;
DROP TABLE IF EXISTS chat_presence;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS channel_members;
DROP TABLE IF EXISTS chat_channels;

-- ============================================
-- TABLE DEFINITIONS
-- ============================================

-- Chat Channels: Support for public/private channels and direct messages
CREATE TABLE IF NOT EXISTS chat_channels (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    channel_type ENUM('public', 'private', 'direct') NOT NULL DEFAULT 'public',
    name VARCHAR(255) NULL, -- NULL for direct messages
    description TEXT NULL,
    team_id INT UNSIGNED NULL, -- Link to teams table for team channels
    created_by INT UNSIGNED NOT NULL,

    -- Settings
    is_archived BOOLEAN DEFAULT FALSE,
    allow_threading BOOLEAN DEFAULT TRUE,
    max_members INT UNSIGNED NULL, -- NULL = unlimited

    -- Metadata
    last_message_at TIMESTAMP NULL,
    message_count INT UNSIGNED DEFAULT 0,
    member_count INT UNSIGNED DEFAULT 0,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_channels_tenant_type (tenant_id, channel_type),
    INDEX idx_channels_team (team_id),
    INDEX idx_channels_last_message (tenant_id, last_message_at DESC),
    INDEX idx_channels_archived (tenant_id, is_archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Channel Members: Track who belongs to each channel
CREATE TABLE IF NOT EXISTS channel_members (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'admin', 'member', 'guest') DEFAULT 'member',

    -- Notification settings
    muted_until TIMESTAMP NULL,
    notification_level ENUM('all', 'mentions', 'none') DEFAULT 'all',

    -- Tracking
    last_read_message_id INT UNSIGNED NULL,
    last_read_at TIMESTAMP NULL,
    unread_count INT UNSIGNED DEFAULT 0,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uniq_channel_member (channel_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_members_tenant_user (tenant_id, user_id),
    INDEX idx_members_unread (tenant_id, user_id, unread_count),
    INDEX idx_members_last_read (channel_id, last_read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Messages: Core message storage with threading support
CREATE TABLE IF NOT EXISTS chat_messages (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    parent_message_id INT UNSIGNED NULL, -- For threading

    -- Message content
    message_type ENUM('text', 'file', 'system', 'code', 'poll') DEFAULT 'text',
    content TEXT NOT NULL, -- Supports Markdown
    content_plain TEXT NULL, -- Plain text version for search

    -- Status flags
    is_edited BOOLEAN DEFAULT FALSE,
    is_deleted BOOLEAN DEFAULT FALSE,
    is_pinned BOOLEAN DEFAULT FALSE,

    -- Metadata
    edit_count INT UNSIGNED DEFAULT 0,
    reaction_count INT UNSIGNED DEFAULT 0,
    reply_count INT UNSIGNED DEFAULT 0,

    -- For long-polling efficiency
    sequence_id BIGINT UNSIGNED NOT NULL, -- Global sequence for polling

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    INDEX idx_messages_channel_time (channel_id, created_at DESC),
    INDEX idx_messages_tenant_sequence (tenant_id, sequence_id),
    INDEX idx_messages_parent_thread (parent_message_id),
    INDEX idx_messages_user (user_id),
    INDEX idx_messages_pinned (channel_id, is_pinned, created_at DESC),
    FULLTEXT idx_messages_content (content_plain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add auto-increment for sequence_id
ALTER TABLE chat_messages AUTO_INCREMENT = 1000000;

-- Message Edit History
CREATE TABLE IF NOT EXISTS message_edits (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    previous_content TEXT NOT NULL,
    new_content TEXT NOT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_edits_message (message_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Presence: Track online/offline status
CREATE TABLE IF NOT EXISTS chat_presence (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
    status_message VARCHAR(255) NULL,

    -- Tracking
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_poll_at TIMESTAMP NULL, -- For long-polling tracking
    current_channel_id INT UNSIGNED NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uniq_presence_user (tenant_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (current_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL,
    INDEX idx_presence_status (tenant_id, status, last_active_at),
    INDEX idx_presence_poll (tenant_id, last_poll_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typing Indicators
CREATE TABLE IF NOT EXISTS chat_typing (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Expiry for cleanup
    expires_at TIMESTAMP NOT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uniq_typing (channel_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_typing_expires (expires_at),
    INDEX idx_typing_channel (channel_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Reactions
CREATE TABLE IF NOT EXISTS message_reactions (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(32) NOT NULL, -- Unicode emoji or :shortcode:

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reaction (message_id, user_id, emoji),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reactions_message (message_id),
    INDEX idx_reactions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Attachments
CREATE TABLE IF NOT EXISTS message_attachments (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    message_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NULL, -- Link to files table if exists

    -- File info (store redundantly for performance)
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    file_url TEXT NOT NULL,

    -- Metadata
    mime_type VARCHAR(100) NULL,
    thumbnail_url TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL,
    INDEX idx_attachments_message (message_id),
    INDEX idx_attachments_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Mentions
CREATE TABLE IF NOT EXISTS message_mentions (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    message_id INT UNSIGNED NOT NULL,
    mentioned_user_id INT UNSIGNED NULL, -- NULL for @channel or @everyone
    mention_type ENUM('user', 'channel', 'everyone') DEFAULT 'user',

    -- Notification tracking
    notified BOOLEAN DEFAULT FALSE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mentions_user (mentioned_user_id, notified),
    INDEX idx_mentions_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Read Receipts
CREATE TABLE IF NOT EXISTS message_read_receipts (
    -- Multi-tenancy support (REQUIRED)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Audit fields
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uniq_read_receipt (message_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_receipts_user (user_id, read_at DESC),
    INDEX idx_receipts_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STORED PROCEDURES FOR PERFORMANCE
-- ============================================

DELIMITER //

-- Get new messages for long-polling
CREATE PROCEDURE IF NOT EXISTS GetNewMessages(
    IN p_tenant_id INT,
    IN p_user_id INT,
    IN p_last_sequence_id BIGINT,
    IN p_limit INT
)
BEGIN
    SELECT
        m.id,
        m.channel_id,
        m.user_id,
        m.parent_message_id,
        m.message_type,
        m.content,
        m.is_edited,
        m.is_deleted,
        m.is_pinned,
        m.sequence_id,
        m.created_at,
        u.name as user_name,
        c.name as channel_name
    FROM chat_messages m
    INNER JOIN channel_members cm ON cm.channel_id = m.channel_id
        AND cm.user_id = p_user_id
    INNER JOIN users u ON u.id = m.user_id
    INNER JOIN chat_channels c ON c.id = m.channel_id
    WHERE m.tenant_id = p_tenant_id
        AND m.sequence_id > p_last_sequence_id
        AND m.is_deleted = FALSE
    ORDER BY m.sequence_id ASC
    LIMIT p_limit;
END//

-- Update unread counts
CREATE PROCEDURE IF NOT EXISTS UpdateUnreadCount(
    IN p_channel_id INT,
    IN p_user_id INT
)
BEGIN
    DECLARE v_last_read_id INT;
    DECLARE v_unread_count INT;

    SELECT last_read_message_id INTO v_last_read_id
    FROM channel_members
    WHERE channel_id = p_channel_id AND user_id = p_user_id;

    SELECT COUNT(*) INTO v_unread_count
    FROM chat_messages
    WHERE channel_id = p_channel_id
        AND id > IFNULL(v_last_read_id, 0)
        AND is_deleted = FALSE;

    UPDATE channel_members
    SET unread_count = v_unread_count
    WHERE channel_id = p_channel_id AND user_id = p_user_id;
END//

DELIMITER ;

-- ============================================
-- TRIGGERS FOR AUTO-UPDATES
-- ============================================

DELIMITER //

-- Auto-generate sequence_id for messages
CREATE TRIGGER IF NOT EXISTS before_message_insert
BEFORE INSERT ON chat_messages
FOR EACH ROW
BEGIN
    SET NEW.sequence_id = UNIX_TIMESTAMP(NOW(6)) * 1000000 + MICROSECOND(NOW(6));
END//

-- Update channel last_message_at
CREATE TRIGGER IF NOT EXISTS after_message_insert
AFTER INSERT ON chat_messages
FOR EACH ROW
BEGIN
    UPDATE chat_channels
    SET last_message_at = NEW.created_at,
        message_count = message_count + 1
    WHERE id = NEW.channel_id;

    -- Update reply count if it's a thread reply
    IF NEW.parent_message_id IS NOT NULL THEN
        UPDATE chat_messages
        SET reply_count = reply_count + 1
        WHERE id = NEW.parent_message_id;
    END IF;
END//

-- Clean up typing indicators on message send
CREATE TRIGGER IF NOT EXISTS after_message_cleanup_typing
AFTER INSERT ON chat_messages
FOR EACH ROW
BEGIN
    DELETE FROM chat_typing
    WHERE channel_id = NEW.channel_id AND user_id = NEW.user_id;
END//

DELIMITER ;

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample tenants (if not exists)
INSERT INTO tenants (id, name) VALUES
    (1, 'Demo Company A'),
    (2, 'Demo Company B')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample users (if not exists)
INSERT INTO users (id, tenant_id, name, email, password) VALUES
    (1, 1, 'Alice Johnson', 'alice@demo.com', '$2y$10$YourHashedPasswordHere'),
    (2, 1, 'Bob Smith', 'bob@demo.com', '$2y$10$YourHashedPasswordHere'),
    (3, 1, 'Charlie Brown', 'charlie@demo.com', '$2y$10$YourHashedPasswordHere'),
    (4, 2, 'Diana Prince', 'diana@demo.com', '$2y$10$YourHashedPasswordHere')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample teams (if not exists)
INSERT INTO teams (id, tenant_id, name, description) VALUES
    (1, 1, 'Engineering', 'Engineering team'),
    (2, 1, 'Marketing', 'Marketing team')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample channels
INSERT INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
    (1, 'public', 'general', 'General discussion', 1, 1),
    (1, 'public', 'random', 'Random topics', 1, 1),
    (1, 'private', 'project-alpha', 'Project Alpha discussion', 1, 2),
    (1, 'direct', NULL, NULL, NULL, 1); -- Direct message channel

-- Sample channel members
INSERT INTO channel_members (tenant_id, channel_id, user_id, role) VALUES
    (1, 1, 1, 'admin'),
    (1, 1, 2, 'member'),
    (1, 1, 3, 'member'),
    (1, 2, 1, 'member'),
    (1, 2, 2, 'member'),
    (1, 3, 1, 'owner'),
    (1, 3, 2, 'admin'),
    (1, 4, 1, 'member'),
    (1, 4, 2, 'member');

-- Sample messages with realistic content
INSERT INTO chat_messages (tenant_id, channel_id, user_id, parent_message_id, message_type, content, content_plain, sequence_id) VALUES
    (1, 1, 1, NULL, 'text', 'Good morning team! 🌅', 'Good morning team!', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 1),
    (1, 1, 2, NULL, 'text', 'Morning Alice! Ready for the standup?', 'Morning Alice! Ready for the standup?', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 2),
    (1, 1, 3, 2, 'text', 'Yes, I have my updates ready', 'Yes, I have my updates ready', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 3),
    (1, 1, 1, NULL, 'code', '```javascript\nconst greeting = "Hello World";\nconsole.log(greeting);\n```', 'const greeting = "Hello World"; console.log(greeting);', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 4),
    (1, 2, 2, NULL, 'text', 'Anyone up for coffee? ☕', 'Anyone up for coffee?', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 5),
    (1, 3, 1, NULL, 'text', 'Project Alpha update: We are on track for the Friday deadline', 'Project Alpha update: We are on track for the Friday deadline', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 6),
    (1, 3, 2, 6, 'text', 'Great news! I will update the stakeholders', 'Great news! I will update the stakeholders', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 7),
    (1, 4, 1, NULL, 'text', 'Hey Bob, can you review my PR?', 'Hey Bob, can you review my PR?', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 8),
    (1, 4, 2, NULL, 'text', 'Sure, sending feedback in 10 minutes', 'Sure, sending feedback in 10 minutes', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 9);

-- Sample reactions
INSERT INTO message_reactions (tenant_id, message_id, user_id, emoji) VALUES
    (1, 1, 2, '👍'),
    (1, 1, 3, '🌅'),
    (1, 5, 1, '☕'),
    (1, 6, 2, '🎯'),
    (1, 6, 3, '👏');

-- Sample mentions
INSERT INTO message_mentions (tenant_id, message_id, mentioned_user_id, mention_type) VALUES
    (1, 8, 2, 'user'),
    (1, 6, NULL, 'channel');

-- Sample presence
INSERT INTO chat_presence (tenant_id, user_id, status, status_message, current_channel_id) VALUES
    (1, 1, 'online', 'In a meeting', 1),
    (1, 2, 'online', NULL, 1),
    (1, 3, 'away', 'Lunch break', NULL);

-- Update message counts
UPDATE chat_channels c
SET message_count = (SELECT COUNT(*) FROM chat_messages WHERE channel_id = c.id),
    member_count = (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id);

-- ============================================
-- USEFUL QUERIES FOR LONG-POLLING
-- ============================================

-- Query 1: Get messages since last poll (for long-polling)
-- Usage: SELECT * FROM chat_messages WHERE tenant_id = ? AND sequence_id > ? ORDER BY sequence_id LIMIT 100;

-- Query 2: Get unread counts for a user
-- SELECT cm.channel_id, cm.unread_count, c.name
-- FROM channel_members cm
-- JOIN chat_channels c ON c.id = cm.channel_id
-- WHERE cm.tenant_id = ? AND cm.user_id = ? AND cm.unread_count > 0;

-- Query 3: Search messages
-- SELECT * FROM chat_messages
-- WHERE tenant_id = ? AND MATCH(content_plain) AGAINST(? IN NATURAL LANGUAGE MODE)
-- ORDER BY created_at DESC LIMIT 50;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Chat system tables created successfully' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collabora'
        AND table_name IN ('chat_channels', 'channel_members', 'chat_messages',
                          'chat_presence', 'chat_typing', 'message_reactions',
                          'message_attachments', 'message_mentions', 'message_read_receipts',
                          'message_edits')) as tables_created,
       (SELECT COUNT(*) FROM chat_messages) as sample_messages,
       (SELECT COUNT(*) FROM chat_channels) as sample_channels,
       NOW() as execution_time;

-- Performance check
SELECT
    'Index Statistics' as metric,
    COUNT(*) as total_indexes
FROM information_schema.statistics
WHERE table_schema = 'collabora'
AND table_name LIKE 'chat_%' OR table_name LIKE 'channel_%' OR table_name LIKE 'message_%';

-- Sample long-polling query performance check
EXPLAIN SELECT * FROM chat_messages
WHERE tenant_id = 1 AND sequence_id > 1000000
ORDER BY sequence_id ASC LIMIT 100;