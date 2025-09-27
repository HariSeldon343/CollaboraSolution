-- Module: Safe Database Reset
-- Version: 2025-09-25
-- Author: Database Architect
-- Description: Safely drops all tables in correct order, handling foreign key constraints

-- ============================================
-- SAFETY WARNINGS
-- ============================================
-- WARNING: This script will DROP ALL TABLES in the collaboranexio database!
-- Make sure to backup your data before running this script
-- To execute: mysql -u root < 02_safe_reset.sql

USE collaboranexio;

-- ============================================
-- DISABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;
SELECT 'Foreign key checks disabled' as Status;

-- ============================================
-- DROP ALL TABLES (Alphabetically for clarity)
-- ============================================
-- Since foreign key checks are disabled, we can drop in any order

-- Activity and Audit tables
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS audit_logs;

-- Authentication and rate limiting
DROP TABLE IF EXISTS api_keys;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS rate_limits;

-- Calendar and Events
DROP TABLE IF EXISTS calendar_events;
DROP TABLE IF EXISTS calendar_shares;
DROP TABLE IF EXISTS event_attendees;
DROP TABLE IF EXISTS event_reminders;

-- Chat and Messaging
DROP TABLE IF EXISTS chat_channel_members;
DROP TABLE IF EXISTS chat_channels;
DROP TABLE IF EXISTS chat_message_reads;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS chat_attachments;

-- Comments (generic)
DROP TABLE IF EXISTS comments;

-- Configuration
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS user_preferences;

-- Email
DROP TABLE IF EXISTS email_logs;
DROP TABLE IF EXISTS email_templates;

-- Files and Documents
DROP TABLE IF EXISTS file_shares;
DROP TABLE IF EXISTS file_versions;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS folders;
DROP TABLE IF EXISTS document_collaborators;

-- Notifications
DROP TABLE IF EXISTS notification_preferences;
DROP TABLE IF EXISTS notifications;

-- Projects and Tasks
DROP TABLE IF EXISTS milestones;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS task_assignments;
DROP TABLE IF EXISTS task_attachments;
DROP TABLE IF EXISTS task_comments;
DROP TABLE IF EXISTS task_dependencies;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS time_entries;

-- Tags and Labels
DROP TABLE IF EXISTS labels;
DROP TABLE IF EXISTS taggables;
DROP TABLE IF EXISTS tags;

-- Teams and Organizations
DROP TABLE IF EXISTS team_members;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS departments;

-- Users and Authentication
DROP TABLE IF EXISTS user_activities;
DROP TABLE IF EXISTS user_devices;
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS user_settings;
DROP TABLE IF EXISTS users;

-- Wiki and Knowledge Base
DROP TABLE IF EXISTS wiki_pages;
DROP TABLE IF EXISTS wiki_revisions;

-- Workflows
DROP TABLE IF EXISTS workflow_instances;
DROP TABLE IF EXISTS workflow_steps;
DROP TABLE IF EXISTS workflows;

-- Core Multi-tenant table (drop last)
DROP TABLE IF EXISTS tenants;

-- Any other miscellaneous tables
DROP TABLE IF EXISTS migrations;
DROP TABLE IF EXISTS cache;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS failed_jobs;

SELECT 'All tables dropped successfully' as Status;

-- ============================================
-- RE-ENABLE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;
SELECT 'Foreign key checks re-enabled' as Status;

-- ============================================
-- VERIFY CLEANUP
-- ============================================
SELECT
    'Cleanup Verification' as Status,
    COUNT(*) as RemainingTables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio';

-- ============================================
-- SHOW REMAINING TABLES (if any)
-- ============================================
SELECT
    TABLE_NAME as RemainingTable,
    TABLE_TYPE as Type
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio'
ORDER BY TABLE_NAME;

-- ============================================
-- FINAL STATUS
-- ============================================
SELECT
    'Reset Complete' as Status,
    'Database is now empty and ready for fresh installation' as Message,
    NOW() as CompletedAt;