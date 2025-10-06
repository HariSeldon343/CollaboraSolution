-- Safe Installation Script for Chat System
-- This script checks and creates tables safely
-- Version: 2025-01-22

USE collabora;

-- ============================================
-- DROP EXISTING CHAT TABLES (if they exist)
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
-- VERIFY BASE TABLES EXIST
-- ============================================
-- Check if required tables exist
SELECT
    CASE WHEN COUNT(*) = 4 THEN 'OK: All base tables exist'
         ELSE CONCAT('ERROR: Missing tables. Found only ', COUNT(*), ' of 4 required tables')
    END as status
FROM information_schema.tables
WHERE table_schema = 'collabora'
AND table_name IN ('tenants', 'users', 'teams', 'files');

-- ============================================
-- CREATE CHAT TABLES (without foreign keys first)
-- ============================================

-- Chat Channels (create without foreign keys)
CREATE TABLE IF NOT EXISTS chat_channels (
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

-- Channel Members (create without foreign keys)
CREATE TABLE IF NOT EXISTS channel_members (
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

-- Chat Messages (create without foreign keys)
CREATE TABLE IF NOT EXISTS chat_messages (
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
    sequence_id BIGINT UNSIGNED NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chat_messages AUTO_INCREMENT = 1000000;

-- Message Edit History
CREATE TABLE IF NOT EXISTS message_edits (
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
CREATE TABLE IF NOT EXISTS chat_presence (
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
CREATE TABLE IF NOT EXISTS chat_typing (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_typing (channel_id, user_id),
    INDEX idx_typing_expires (expires_at),
    INDEX idx_typing_channel (channel_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Reactions
CREATE TABLE IF NOT EXISTS message_reactions (
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
CREATE TABLE IF NOT EXISTS message_attachments (
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
CREATE TABLE IF NOT EXISTS message_mentions (
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
CREATE TABLE IF NOT EXISTS message_read_receipts (
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
-- NOW ADD FOREIGN KEYS (if base tables exist)
-- ============================================

-- Add foreign keys to chat_channels
ALTER TABLE chat_channels
    ADD CONSTRAINT fk_channels_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_channels_team
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_channels_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT;

-- Add foreign keys to channel_members
ALTER TABLE channel_members
    ADD CONSTRAINT fk_members_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_members_channel
        FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_members_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys to chat_messages
ALTER TABLE chat_messages
    ADD CONSTRAINT fk_messages_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_channel
        FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_messages_parent
        FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE;

-- Add foreign keys to message_edits
ALTER TABLE message_edits
    ADD CONSTRAINT fk_edits_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_edits_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_edits_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys to chat_presence
ALTER TABLE chat_presence
    ADD CONSTRAINT fk_presence_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_presence_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_presence_channel
        FOREIGN KEY (current_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL;

-- Add foreign keys to chat_typing
ALTER TABLE chat_typing
    ADD CONSTRAINT fk_typing_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_typing_channel
        FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_typing_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys to message_reactions
ALTER TABLE message_reactions
    ADD CONSTRAINT fk_reactions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_reactions_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_reactions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys to message_attachments
ALTER TABLE message_attachments
    ADD CONSTRAINT fk_attachments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_attachments_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_attachments_file
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL;

-- Add foreign keys to message_mentions
ALTER TABLE message_mentions
    ADD CONSTRAINT fk_mentions_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_mentions_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_mentions_user
        FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys to message_read_receipts
ALTER TABLE message_read_receipts
    ADD CONSTRAINT fk_receipts_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_receipts_message
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_receipts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT
    'Chat tables created' as status,
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = 'collabora'
     AND table_name LIKE 'chat_%' OR table_name LIKE 'channel_%' OR table_name LIKE 'message_%') as chat_tables_count,
    NOW() as execution_time;