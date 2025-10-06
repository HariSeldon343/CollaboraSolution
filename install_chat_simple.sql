-- Simple Chat Installation Script for CollaboraNexio
-- Version: 2025-01-22
-- No information_schema queries

USE collabora;

-- ============================================
-- STEP 1: DROP OLD CHAT TABLES (if exist)
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- STEP 2: CREATE ALL CHAT TABLES
-- ============================================

-- Chat Channels
CREATE TABLE chat_channels (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_type ENUM('public', 'private', 'direct') NOT NULL DEFAULT 'public',
    name VARCHAR(255) NULL,
    description TEXT NULL,
    team_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    is_archived BOOLEAN DEFAULT FALSE,
    allow_threading BOOLEAN DEFAULT TRUE,
    max_members INT UNSIGNED NULL,
    last_message_at TIMESTAMP NULL,
    message_count INT UNSIGNED DEFAULT 0,
    member_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_channels_tenant_type (tenant_id, channel_type),
    INDEX idx_channels_team (team_id),
    INDEX idx_channels_last_message (tenant_id, last_message_at DESC),
    INDEX idx_channels_archived (tenant_id, is_archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Channel Members
CREATE TABLE channel_members (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'admin', 'member', 'guest') DEFAULT 'member',
    muted_until TIMESTAMP NULL,
    notification_level ENUM('all', 'mentions', 'none') DEFAULT 'all',
    last_read_message_id INT UNSIGNED NULL,
    last_read_at TIMESTAMP NULL,
    unread_count INT UNSIGNED DEFAULT 0,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_channel_member (channel_id, user_id),
    INDEX idx_members_tenant_user (tenant_id, user_id),
    INDEX idx_members_unread (tenant_id, user_id, unread_count),
    INDEX idx_members_last_read (channel_id, last_read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Messages
CREATE TABLE chat_messages (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    parent_message_id INT UNSIGNED NULL,
    message_type ENUM('text', 'file', 'system', 'code', 'poll') DEFAULT 'text',
    content TEXT NOT NULL,
    content_plain TEXT NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    is_deleted BOOLEAN DEFAULT FALSE,
    is_pinned BOOLEAN DEFAULT FALSE,
    edit_count INT UNSIGNED DEFAULT 0,
    reaction_count INT UNSIGNED DEFAULT 0,
    reply_count INT UNSIGNED DEFAULT 0,
    sequence_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX idx_messages_channel_time (channel_id, created_at DESC),
    INDEX idx_messages_tenant_sequence (tenant_id, sequence_id),
    INDEX idx_messages_parent_thread (parent_message_id),
    INDEX idx_messages_user (user_id),
    INDEX idx_messages_pinned (channel_id, is_pinned, created_at DESC),
    FULLTEXT idx_messages_content (content_plain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1000000;

-- Message Edit History
CREATE TABLE message_edits (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    previous_content TEXT NOT NULL,
    new_content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_edits_message (message_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Presence
CREATE TABLE chat_presence (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
    status_message VARCHAR(255) NULL,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_poll_at TIMESTAMP NULL,
    current_channel_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_presence_user (tenant_id, user_id),
    INDEX idx_presence_status (tenant_id, status, last_active_at),
    INDEX idx_presence_poll (tenant_id, last_poll_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typing Indicators
CREATE TABLE chat_typing (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 10 SECOND),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_typing (channel_id, user_id),
    INDEX idx_typing_expires (expires_at),
    INDEX idx_typing_channel (channel_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Reactions
CREATE TABLE message_reactions (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(32) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reaction (message_id, user_id, emoji),
    INDEX idx_reactions_message (message_id),
    INDEX idx_reactions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Attachments
CREATE TABLE message_attachments (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    file_url TEXT NOT NULL,
    mime_type VARCHAR(100) NULL,
    thumbnail_url TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_attachments_message (message_id),
    INDEX idx_attachments_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Mentions
CREATE TABLE message_mentions (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    mentioned_user_id INT UNSIGNED NULL,
    mention_type ENUM('user', 'channel', 'everyone') DEFAULT 'user',
    notified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_mentions_user (mentioned_user_id, notified),
    INDEX idx_mentions_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Read Receipts
CREATE TABLE message_read_receipts (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_read_receipt (message_id, user_id),
    INDEX idx_receipts_user (user_id, read_at DESC),
    INDEX idx_receipts_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STEP 3: ADD FOREIGN KEYS
-- ============================================

-- Temporarily disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Chat Channels foreign keys
ALTER TABLE chat_channels
    ADD CONSTRAINT fk_channels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_channels_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_channels_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT;

-- Channel Members foreign keys
ALTER TABLE channel_members
    ADD CONSTRAINT fk_members_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_members_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Chat Messages foreign keys
ALTER TABLE chat_messages
    ADD CONSTRAINT fk_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_parent FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

-- Message Edits foreign keys
ALTER TABLE message_edits
    ADD CONSTRAINT fk_edits_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_edits_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_edits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Chat Presence foreign keys
ALTER TABLE chat_presence
    ADD CONSTRAINT fk_presence_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_presence_channel FOREIGN KEY (current_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL;

-- Chat Typing foreign keys
ALTER TABLE chat_typing
    ADD CONSTRAINT fk_typing_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_typing_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_typing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Message Reactions foreign keys
ALTER TABLE message_reactions
    ADD CONSTRAINT fk_reactions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_reactions_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Message Attachments foreign keys
ALTER TABLE message_attachments
    ADD CONSTRAINT fk_attachments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_attachments_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL;

-- Message Mentions foreign keys
ALTER TABLE message_mentions
    ADD CONSTRAINT fk_mentions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_mentions_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_mentions_user FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Message Read Receipts foreign keys
ALTER TABLE message_read_receipts
    ADD CONSTRAINT fk_receipts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_receipts_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_receipts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- STEP 4: CREATE STORED PROCEDURES
-- ============================================

DELIMITER //

-- Get new messages for long-polling
DROP PROCEDURE IF EXISTS GetNewMessages//
CREATE PROCEDURE GetNewMessages(
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
DROP PROCEDURE IF EXISTS UpdateUnreadCount//
CREATE PROCEDURE UpdateUnreadCount(
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
-- STEP 5: CREATE TRIGGERS
-- ============================================

DELIMITER //

-- Auto-generate sequence_id for messages
DROP TRIGGER IF EXISTS before_message_insert//
CREATE TRIGGER before_message_insert
BEFORE INSERT ON chat_messages
FOR EACH ROW
BEGIN
    SET NEW.sequence_id = UNIX_TIMESTAMP(NOW(6)) * 1000000 + MICROSECOND(NOW(6));
END//

-- Update channel last_message_at
DROP TRIGGER IF EXISTS after_message_insert//
CREATE TRIGGER after_message_insert
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
DROP TRIGGER IF EXISTS after_message_cleanup_typing//
CREATE TRIGGER after_message_cleanup_typing
AFTER INSERT ON chat_messages
FOR EACH ROW
BEGIN
    DELETE FROM chat_typing
    WHERE channel_id = NEW.channel_id AND user_id = NEW.user_id;
END//

DELIMITER ;

-- ============================================
-- STEP 6: INSERT SAMPLE DATA
-- ============================================

-- Sample data for testing
INSERT INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
    (1, 'public', 'general', 'General discussion', 1, 1),
    (1, 'public', 'random', 'Random topics', 1, 1),
    (1, 'private', 'project-alpha', 'Project Alpha discussion', 1, 2),
    (1, 'direct', NULL, NULL, NULL, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO channel_members (tenant_id, channel_id, user_id, role) VALUES
    (1, 1, 1, 'admin'),
    (1, 1, 2, 'member'),
    (1, 1, 3, 'member'),
    (1, 2, 1, 'member'),
    (1, 2, 2, 'member'),
    (1, 3, 1, 'owner'),
    (1, 3, 2, 'admin'),
    (1, 4, 1, 'member'),
    (1, 4, 2, 'member')
ON DUPLICATE KEY UPDATE role=VALUES(role);

-- Sample messages
INSERT INTO chat_messages (tenant_id, channel_id, user_id, message_type, content, content_plain) VALUES
    (1, 1, 1, 'text', 'Welcome to the general channel!', 'Welcome to the general channel!'),
    (1, 1, 2, 'text', 'Hello everyone!', 'Hello everyone!'),
    (1, 2, 1, 'text', 'This is the random channel', 'This is the random channel'),
    (1, 3, 2, 'text', 'Project Alpha kickoff meeting tomorrow', 'Project Alpha kickoff meeting tomorrow'),
    (1, 4, 1, 'text', 'Hey, check out the new features', 'Hey, check out the new features');

-- ============================================
-- FINAL VERIFICATION
-- ============================================

SELECT 'Chat system installed successfully!' as Status;

-- Show created tables
SHOW TABLES LIKE '%chat%';
SHOW TABLES LIKE '%channel%';
SHOW TABLES LIKE '%message%';

-- Count records
SELECT 'chat_channels' as table_name, COUNT(*) as count FROM chat_channels
UNION ALL
SELECT 'channel_members', COUNT(*) FROM channel_members
UNION ALL
SELECT 'chat_messages', COUNT(*) FROM chat_messages;