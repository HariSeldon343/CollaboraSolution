-- Complete Chat System Installation
-- Version: 2025-01-22
-- This script works with existing base tables

USE collabora;

-- ============================================
-- IMPORTANT: Disable all checks
-- ============================================
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

-- ============================================
-- CLEAN UP OLD CHAT TABLES ONLY
-- ============================================
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
-- CREATE CHAT SYSTEM TABLES
-- ============================================

-- Chat Channels
CREATE TABLE chat_channels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
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
    KEY idx_channels_tenant (tenant_id),
    KEY idx_channels_team (team_id),
    KEY idx_channels_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Channel Members
CREATE TABLE channel_members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
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
    KEY idx_members_tenant (tenant_id),
    KEY idx_members_channel (channel_id),
    KEY idx_members_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Messages
CREATE TABLE chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
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
    KEY idx_messages_tenant (tenant_id),
    KEY idx_messages_channel (channel_id),
    KEY idx_messages_user (user_id),
    KEY idx_messages_parent (parent_message_id),
    KEY idx_messages_sequence (tenant_id, sequence_id),
    FULLTEXT KEY idx_messages_content (content_plain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1000000;

-- Message Edit History
CREATE TABLE message_edits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    previous_content TEXT NOT NULL,
    new_content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_edits_tenant (tenant_id),
    KEY idx_edits_message (message_id),
    KEY idx_edits_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Presence
CREATE TABLE chat_presence (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
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
    KEY idx_presence_tenant (tenant_id),
    KEY idx_presence_user (user_id),
    KEY idx_presence_channel (current_channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typing Indicators
CREATE TABLE chat_typing (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 10 SECOND),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_typing (channel_id, user_id),
    KEY idx_typing_tenant (tenant_id),
    KEY idx_typing_channel (channel_id),
    KEY idx_typing_user (user_id),
    KEY idx_typing_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Reactions
CREATE TABLE message_reactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(32) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reaction (message_id, user_id, emoji),
    KEY idx_reactions_tenant (tenant_id),
    KEY idx_reactions_message (message_id),
    KEY idx_reactions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Attachments
CREATE TABLE message_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
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
    KEY idx_attachments_tenant (tenant_id),
    KEY idx_attachments_message (message_id),
    KEY idx_attachments_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Mentions
CREATE TABLE message_mentions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    mentioned_user_id INT UNSIGNED NULL,
    mention_type ENUM('user', 'channel', 'everyone') DEFAULT 'user',
    notified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mentions_tenant (tenant_id),
    KEY idx_mentions_message (message_id),
    KEY idx_mentions_user (mentioned_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Read Receipts
CREATE TABLE message_read_receipts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_read_receipt (message_id, user_id),
    KEY idx_receipts_tenant (tenant_id),
    KEY idx_receipts_message (message_id),
    KEY idx_receipts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADD FOREIGN KEYS (ONLY IF BASE TABLES EXIST)
-- ============================================

-- Add foreign keys for chat_channels
ALTER TABLE chat_channels
    ADD CONSTRAINT fk_chat_channels_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE chat_channels
    ADD CONSTRAINT fk_chat_channels_team
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE;

ALTER TABLE chat_channels
    ADD CONSTRAINT fk_chat_channels_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT;

-- Add foreign keys for channel_members
ALTER TABLE channel_members
    ADD CONSTRAINT fk_channel_members_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE channel_members
    ADD CONSTRAINT fk_channel_members_channel
        FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE;

ALTER TABLE channel_members
    ADD CONSTRAINT fk_channel_members_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for chat_messages
ALTER TABLE chat_messages
    ADD CONSTRAINT fk_chat_messages_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE chat_messages
    ADD CONSTRAINT fk_chat_messages_channel
        FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE;

ALTER TABLE chat_messages
    ADD CONSTRAINT fk_chat_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE chat_messages
    ADD CONSTRAINT fk_chat_messages_parent
        FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

-- Add foreign keys for message_edits
ALTER TABLE message_edits
    ADD CONSTRAINT fk_message_edits_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE message_edits
    ADD CONSTRAINT fk_message_edits_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

ALTER TABLE message_edits
    ADD CONSTRAINT fk_message_edits_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for chat_presence
ALTER TABLE chat_presence
    ADD CONSTRAINT fk_chat_presence_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE chat_presence
    ADD CONSTRAINT fk_chat_presence_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE chat_presence
    ADD CONSTRAINT fk_chat_presence_channel
        FOREIGN KEY (current_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL;

-- Add foreign keys for chat_typing
ALTER TABLE chat_typing
    ADD CONSTRAINT fk_chat_typing_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE chat_typing
    ADD CONSTRAINT fk_chat_typing_channel
        FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE;

ALTER TABLE chat_typing
    ADD CONSTRAINT fk_chat_typing_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for message_reactions
ALTER TABLE message_reactions
    ADD CONSTRAINT fk_message_reactions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE message_reactions
    ADD CONSTRAINT fk_message_reactions_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

ALTER TABLE message_reactions
    ADD CONSTRAINT fk_message_reactions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for message_attachments
ALTER TABLE message_attachments
    ADD CONSTRAINT fk_message_attachments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE message_attachments
    ADD CONSTRAINT fk_message_attachments_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

ALTER TABLE message_attachments
    ADD CONSTRAINT fk_message_attachments_file
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL;

-- Add foreign keys for message_mentions
ALTER TABLE message_mentions
    ADD CONSTRAINT fk_message_mentions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE message_mentions
    ADD CONSTRAINT fk_message_mentions_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

ALTER TABLE message_mentions
    ADD CONSTRAINT fk_message_mentions_user
        FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for message_read_receipts
ALTER TABLE message_read_receipts
    ADD CONSTRAINT fk_message_read_receipts_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE message_read_receipts
    ADD CONSTRAINT fk_message_read_receipts_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

ALTER TABLE message_read_receipts
    ADD CONSTRAINT fk_message_read_receipts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ============================================
-- RESTORE SETTINGS
-- ============================================
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;

-- ============================================
-- CREATE TRIGGERS
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

    IF NEW.parent_message_id IS NOT NULL THEN
        UPDATE chat_messages
        SET reply_count = reply_count + 1
        WHERE id = NEW.parent_message_id;
    END IF;
END//

-- Clean up typing indicators
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
-- INSERT SAMPLE DATA
-- ============================================

-- Sample channels
INSERT IGNORE INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
    (1, 'public', 'general', 'General discussion for all team members', 1, 1),
    (1, 'public', 'random', 'Random topics and casual chat', 1, 1),
    (1, 'private', 'project-alpha', 'Project Alpha team discussion', 1, 2),
    (1, 'direct', NULL, 'Direct message between users', NULL, 1);

-- Get channel IDs
SET @general_id = (SELECT id FROM chat_channels WHERE name = 'general' LIMIT 1);
SET @random_id = (SELECT id FROM chat_channels WHERE name = 'random' LIMIT 1);
SET @project_id = (SELECT id FROM chat_channels WHERE name = 'project-alpha' LIMIT 1);
SET @dm_id = (SELECT id FROM chat_channels WHERE channel_type = 'direct' LIMIT 1);

-- Sample channel members
INSERT IGNORE INTO channel_members (tenant_id, channel_id, user_id, role) VALUES
    (1, @general_id, 1, 'admin'),
    (1, @general_id, 2, 'member'),
    (1, @general_id, 3, 'member'),
    (1, @random_id, 1, 'member'),
    (1, @random_id, 2, 'member'),
    (1, @project_id, 1, 'owner'),
    (1, @project_id, 2, 'admin'),
    (1, @dm_id, 1, 'member'),
    (1, @dm_id, 2, 'member');

-- Sample messages
INSERT IGNORE INTO chat_messages (tenant_id, channel_id, user_id, message_type, content, content_plain) VALUES
    (1, @general_id, 1, 'text', '🎉 Welcome to CollaboraNexio Chat System!', 'Welcome to CollaboraNexio Chat System!'),
    (1, @general_id, 2, 'text', 'Hello everyone! The chat is working great!', 'Hello everyone! The chat is working great!'),
    (1, @general_id, 3, 'text', 'Excellent! Let\'s test all the features.', 'Excellent! Let\'s test all the features.'),
    (1, @random_id, 1, 'text', 'Anyone tried the new emoji reactions? 😊', 'Anyone tried the new emoji reactions?'),
    (1, @project_id, 2, 'text', 'Project Alpha meeting scheduled for tomorrow at 10 AM', 'Project Alpha meeting scheduled for tomorrow at 10 AM');

-- Sample presence
INSERT IGNORE INTO chat_presence (tenant_id, user_id, status, status_message, current_channel_id) VALUES
    (1, 1, 'online', 'Available', @general_id),
    (1, 2, 'busy', 'In a meeting', @general_id),
    (1, 3, 'away', 'Be right back', NULL);

-- Update channel statistics
UPDATE chat_channels c
SET message_count = (SELECT COUNT(*) FROM chat_messages WHERE channel_id = c.id),
    member_count = (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id),
    last_message_at = (SELECT MAX(created_at) FROM chat_messages WHERE channel_id = c.id);

-- ============================================
-- FINAL VERIFICATION
-- ============================================
SELECT '✅ Chat system installation complete!' as Status;

-- Show all chat tables
SELECT
    GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ', ') as chat_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
AND (table_name LIKE 'chat_%' OR table_name LIKE 'channel_%' OR table_name LIKE 'message_%');

-- Count records
SELECT 'Summary:' as Info,
    (SELECT COUNT(*) FROM chat_channels) as Channels,
    (SELECT COUNT(*) FROM channel_members) as Members,
    (SELECT COUNT(*) FROM chat_messages) as Messages,
    (SELECT COUNT(*) FROM chat_presence) as OnlineUsers;