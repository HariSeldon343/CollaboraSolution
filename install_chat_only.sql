-- Chat System Installation (Tables Only)
-- Version: 2025-01-22
-- Run this AFTER base tables are properly created

USE collabora;

-- Disable foreign key checks during creation
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- DROP OLD CHAT TABLES
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
-- CREATE CHAT TABLES
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
    INDEX idx_channels_tenant (tenant_id),
    INDEX idx_channels_tenant_type (tenant_id, channel_type),
    INDEX idx_channels_team (team_id),
    INDEX idx_channels_created_by (created_by),
    INDEX idx_channels_last_message (tenant_id, last_message_at DESC),
    INDEX idx_channels_archived (tenant_id, is_archived),
    CONSTRAINT fk_chat_channels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_channels_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_channels_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
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
    INDEX idx_members_tenant (tenant_id),
    INDEX idx_members_tenant_user (tenant_id, user_id),
    INDEX idx_members_channel (channel_id),
    INDEX idx_members_user (user_id),
    INDEX idx_members_unread (tenant_id, user_id, unread_count),
    INDEX idx_members_last_read (channel_id, last_read_at),
    CONSTRAINT fk_channel_members_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_members_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    INDEX idx_messages_tenant (tenant_id),
    INDEX idx_messages_channel (channel_id),
    INDEX idx_messages_user (user_id),
    INDEX idx_messages_parent (parent_message_id),
    INDEX idx_messages_channel_time (channel_id, created_at DESC),
    INDEX idx_messages_tenant_sequence (tenant_id, sequence_id),
    INDEX idx_messages_parent_thread (parent_message_id),
    INDEX idx_messages_pinned (channel_id, is_pinned, created_at DESC),
    FULLTEXT idx_messages_content (content_plain),
    CONSTRAINT fk_chat_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_parent FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
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
    INDEX idx_edits_tenant (tenant_id),
    INDEX idx_edits_message (message_id),
    INDEX idx_edits_user (user_id),
    INDEX idx_edits_message_time (message_id, created_at DESC),
    CONSTRAINT fk_message_edits_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_edits_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_edits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    INDEX idx_presence_tenant (tenant_id),
    INDEX idx_presence_user (user_id),
    INDEX idx_presence_channel (current_channel_id),
    INDEX idx_presence_status (tenant_id, status, last_active_at),
    INDEX idx_presence_poll (tenant_id, last_poll_at),
    CONSTRAINT fk_chat_presence_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_presence_channel FOREIGN KEY (current_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL
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
    INDEX idx_typing_tenant (tenant_id),
    INDEX idx_typing_channel (channel_id),
    INDEX idx_typing_user (user_id),
    INDEX idx_typing_expires (expires_at),
    INDEX idx_typing_channel_expires (channel_id, expires_at),
    CONSTRAINT fk_chat_typing_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_typing_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_typing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    INDEX idx_reactions_tenant (tenant_id),
    INDEX idx_reactions_message (message_id),
    INDEX idx_reactions_user (user_id),
    CONSTRAINT fk_message_reactions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_reactions_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    INDEX idx_attachments_tenant (tenant_id),
    INDEX idx_attachments_message (message_id),
    INDEX idx_attachments_file (file_id),
    CONSTRAINT fk_message_attachments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_attachments_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
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
    INDEX idx_mentions_tenant (tenant_id),
    INDEX idx_mentions_message (message_id),
    INDEX idx_mentions_user (mentioned_user_id, notified),
    CONSTRAINT fk_message_mentions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_mentions_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_mentions_user FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE
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
    INDEX idx_receipts_tenant (tenant_id),
    INDEX idx_receipts_message (message_id),
    INDEX idx_receipts_user (user_id, read_at DESC),
    CONSTRAINT fk_message_read_receipts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_read_receipts_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_read_receipts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- INSERT SAMPLE DATA
-- ============================================

-- Sample channels
INSERT INTO chat_channels (tenant_id, channel_type, name, description, team_id, created_by) VALUES
    (1, 'public', 'general', 'General discussion', 1, 1),
    (1, 'public', 'random', 'Random topics', 1, 1),
    (1, 'private', 'project-alpha', 'Project Alpha discussion', 1, 2),
    (1, 'direct', NULL, NULL, NULL, 1);

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

-- Sample messages
INSERT INTO chat_messages (tenant_id, channel_id, user_id, message_type, content, content_plain, sequence_id) VALUES
    (1, 1, 1, 'text', 'Welcome to CollaboraNexio Chat! 🎉', 'Welcome to CollaboraNexio Chat!', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 1),
    (1, 1, 2, 'text', 'Hello everyone! Excited to be here!', 'Hello everyone! Excited to be here!', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 2),
    (1, 1, 3, 'text', 'Great to see the chat system working!', 'Great to see the chat system working!', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 3),
    (1, 2, 1, 'text', 'This is the random channel for casual discussions', 'This is the random channel for casual discussions', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 4),
    (1, 3, 2, 'text', 'Project Alpha meeting tomorrow at 10 AM', 'Project Alpha meeting tomorrow at 10 AM', UNIX_TIMESTAMP(NOW(6)) * 1000000 + 5);

-- Sample reactions
INSERT INTO message_reactions (tenant_id, message_id, user_id, emoji) VALUES
    (1, 1, 2, '👍'),
    (1, 1, 3, '🎉'),
    (1, 5, 1, '✅');

-- Sample presence
INSERT INTO chat_presence (tenant_id, user_id, status, status_message, current_channel_id) VALUES
    (1, 1, 'online', 'Available', 1),
    (1, 2, 'online', NULL, 1),
    (1, 3, 'away', 'In a meeting', NULL);

-- Update channel statistics
UPDATE chat_channels c
SET message_count = (SELECT COUNT(*) FROM chat_messages WHERE channel_id = c.id),
    member_count = (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id),
    last_message_at = (SELECT MAX(created_at) FROM chat_messages WHERE channel_id = c.id);

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Chat system installed successfully!' as Status;

-- Show created tables
SELECT COUNT(*) as chat_tables_count FROM (
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = DATABASE()
    AND (table_name LIKE 'chat_%' OR table_name LIKE 'channel_%' OR table_name LIKE 'message_%')
) as t;

-- Count records
SELECT 'chat_channels' as table_name, COUNT(*) as records FROM chat_channels
UNION ALL
SELECT 'channel_members', COUNT(*) FROM channel_members
UNION ALL
SELECT 'chat_messages', COUNT(*) FROM chat_messages
UNION ALL
SELECT 'chat_presence', COUNT(*) FROM chat_presence;