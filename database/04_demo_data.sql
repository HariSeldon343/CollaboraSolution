-- Module: Demo Data for CollaboraNexio
-- Version: 2025-09-25
-- Author: Database Architect
-- Description: Comprehensive demo data following COLLABORA specifications with realistic test scenarios

USE collaboranexio;

-- ============================================
-- DEMO DATA - TENANTS
-- ============================================
INSERT INTO tenants (id, name, domain, status, max_users, max_storage_gb, settings) VALUES
    (1, 'Demo Company', 'demo.local', 'active', 50, 500, JSON_OBJECT(
        'theme', 'default',
        'locale', 'en-US',
        'features', JSON_ARRAY('chat', 'calendar', 'tasks', 'files')
    )),
    (2, 'Test Organization', 'test.local', 'active', 20, 200, JSON_OBJECT(
        'theme', 'dark',
        'locale', 'en-US',
        'features', JSON_ARRAY('chat', 'tasks')
    ))
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    settings = VALUES(settings);

-- ============================================
-- DEMO DATA - USERS
-- ============================================
-- Password for all users: Admin123!
-- Hash generated with password_hash('Admin123!', PASSWORD_BCRYPT)
SET @password_hash = '$2y$10$YourHashHere'; -- This will be replaced by PHP script

INSERT INTO users (
    tenant_id, email, password_hash, first_name, last_name, display_name,
    role, status, email_verified_at, department, position
) VALUES
    -- Demo Company Users (Tenant 1)
    (1, 'admin@demo.local', @password_hash, 'Admin', 'User', 'System Admin',
     'admin', 'active', NOW(), 'IT', 'System Administrator'),

    (1, 'manager@demo.local', @password_hash, 'John', 'Manager', 'John Manager',
     'manager', 'active', NOW(), 'Management', 'Project Manager'),

    (1, 'user1@demo.local', @password_hash, 'Alice', 'Johnson', 'Alice Johnson',
     'user', 'active', NOW(), 'Development', 'Senior Developer'),

    (1, 'user2@demo.local', @password_hash, 'Bob', 'Smith', 'Bob Smith',
     'user', 'active', NOW(), 'Development', 'Developer'),

    (1, 'designer@demo.local', @password_hash, 'Carol', 'White', 'Carol White',
     'user', 'active', NOW(), 'Design', 'UX Designer'),

    (1, 'tester@demo.local', @password_hash, 'David', 'Brown', 'David Brown',
     'user', 'active', NOW(), 'QA', 'QA Engineer'),

    -- Test Organization Users (Tenant 2)
    (2, 'admin@test.local', @password_hash, 'Test', 'Admin', 'Test Admin',
     'admin', 'active', NOW(), 'IT', 'Administrator'),

    (2, 'user@test.local', @password_hash, 'Test', 'User', 'Test User',
     'user', 'active', NOW(), 'General', 'Employee')
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    status = VALUES(status);

-- ============================================
-- DEMO DATA - PROJECTS
-- ============================================
INSERT INTO projects (
    tenant_id, name, description, owner_id, status, priority,
    start_date, end_date, budget, progress_percentage
) VALUES
    -- Demo Company Projects
    (1, 'Website Redesign',
     'Complete redesign of company website with modern UI/UX',
     2, 'active', 'high', '2025-01-01', '2025-06-30', 50000.00, 35),

    (1, 'Mobile App Development',
     'Native mobile application for iOS and Android platforms',
     2, 'planning', 'critical', '2025-02-01', '2025-08-31', 120000.00, 0),

    (1, 'Database Migration',
     'Migrate legacy database to new cloud infrastructure',
     1, 'active', 'high', '2025-01-15', '2025-03-15', 25000.00, 60),

    -- Test Organization Projects
    (2, 'Internal Tool Development',
     'Build internal productivity tools',
     7, 'active', 'medium', '2025-01-10', '2025-04-10', 15000.00, 45)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    status = VALUES(status),
    progress_percentage = VALUES(progress_percentage);

-- Get project IDs for reference
SET @project_website = (SELECT id FROM projects WHERE name = 'Website Redesign' LIMIT 1);
SET @project_mobile = (SELECT id FROM projects WHERE name = 'Mobile App Development' LIMIT 1);
SET @project_database = (SELECT id FROM projects WHERE name = 'Database Migration' LIMIT 1);
SET @project_internal = (SELECT id FROM projects WHERE name = 'Internal Tool Development' LIMIT 1);

-- ============================================
-- DEMO DATA - PROJECT MEMBERS
-- ============================================
-- Using ON DUPLICATE KEY UPDATE to avoid duplicate entry errors
INSERT INTO project_members (tenant_id, project_id, user_id, role, added_by) VALUES
    -- Website Redesign Team
    (1, @project_website, 2, 'manager', 1),
    (1, @project_website, 3, 'member', 2),
    (1, @project_website, 5, 'member', 2),

    -- Mobile App Team
    (1, @project_mobile, 2, 'manager', 1),
    (1, @project_mobile, 3, 'member', 2),
    (1, @project_mobile, 4, 'member', 2),
    (1, @project_mobile, 6, 'member', 2),

    -- Database Migration Team
    (1, @project_database, 1, 'owner', 1),
    (1, @project_database, 3, 'member', 1),
    (1, @project_database, 4, 'member', 1),

    -- Internal Tool Team
    (2, @project_internal, 7, 'owner', 7),
    (2, @project_internal, 8, 'member', 7)
ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    updated_at = NOW();

-- ============================================
-- DEMO DATA - TASKS
-- ============================================
INSERT INTO tasks (
    tenant_id, project_id, title, description, assigned_to, created_by,
    status, priority, due_date, estimated_hours, progress_percentage
) VALUES
    -- Website Redesign Tasks
    (1, @project_website, 'Create wireframes',
     'Design wireframes for all main pages', 5, 2,
     'done', 'high', '2025-01-15 17:00:00', 16.0, 100),

    (1, @project_website, 'Develop homepage',
     'Implement responsive homepage with new design', 3, 2,
     'in_progress', 'high', '2025-02-01 17:00:00', 24.0, 60),

    (1, @project_website, 'Setup CI/CD pipeline',
     'Configure automated deployment pipeline', 4, 2,
     'todo', 'medium', '2025-02-15 17:00:00', 8.0, 0),

    -- Database Migration Tasks
    (1, @project_database, 'Analyze current schema',
     'Document existing database structure and dependencies', 3, 1,
     'done', 'critical', '2025-01-20 17:00:00', 12.0, 100),

    (1, @project_database, 'Design new schema',
     'Create optimized schema for cloud deployment', 3, 1,
     'in_progress', 'critical', '2025-02-05 17:00:00', 20.0, 75),

    (1, @project_database, 'Write migration scripts',
     'Develop scripts for data migration and validation', 4, 1,
     'in_progress', 'high', '2025-02-20 17:00:00', 30.0, 40),

    -- Mobile App Tasks
    (1, @project_mobile, 'UI/UX Design',
     'Create mobile app designs and user flow', 5, 2,
     'todo', 'high', '2025-02-10 17:00:00', 40.0, 0),

    (1, @project_mobile, 'Setup development environment',
     'Configure React Native environment and dependencies', 3, 2,
     'todo', 'high', '2025-02-05 17:00:00', 8.0, 0),

    -- Internal Tool Tasks
    (2, @project_internal, 'Requirements gathering',
     'Collect and document all requirements', 8, 7,
     'done', 'high', '2025-01-20 17:00:00', 12.0, 100),

    (2, @project_internal, 'Backend API development',
     'Develop REST API endpoints', 8, 7,
     'in_progress', 'high', '2025-02-28 17:00:00', 40.0, 30);

-- ============================================
-- DEMO DATA - FOLDERS
-- ============================================
INSERT INTO folders (tenant_id, name, path, owner_id, parent_id) VALUES
    -- Demo Company Folders
    (1, 'Documents', '/Documents', 1, NULL),
    (1, 'Projects', '/Projects', 1, NULL),
    (1, 'Shared', '/Shared', 1, NULL),

    -- Test Organization Folders
    (2, 'Files', '/Files', 7, NULL),
    (2, 'Resources', '/Resources', 7, NULL);

SET @folder_documents = (SELECT id FROM folders WHERE tenant_id = 1 AND name = 'Documents' LIMIT 1);
SET @folder_projects = (SELECT id FROM folders WHERE tenant_id = 1 AND name = 'Projects' LIMIT 1);
SET @folder_shared = (SELECT id FROM folders WHERE tenant_id = 1 AND name = 'Shared' LIMIT 1);

-- Add subfolders
INSERT INTO folders (tenant_id, name, path, owner_id, parent_id) VALUES
    (1, 'Policies', '/Documents/Policies', 1, @folder_documents),
    (1, 'Templates', '/Documents/Templates', 1, @folder_documents),
    (1, 'Website Redesign', '/Projects/Website Redesign', 2, @folder_projects),
    (1, 'Mobile App', '/Projects/Mobile App', 2, @folder_projects);

-- ============================================
-- DEMO DATA - FILES
-- Updated to use ACTUAL schema column names:
-- file_size (not size_bytes), file_path (not storage_path), uploaded_by (not owner_id)
-- ============================================
INSERT INTO files (
    tenant_id, folder_id, name, original_name, mime_type,
    file_size, file_path, uploaded_by, status
) VALUES
    (1, @folder_documents, 'company_policy_2025.pdf', 'Company Policy 2025.pdf',
     'application/pdf', 2457600, '/storage/tenant_1/documents/policy_2025.pdf', 1, 'approvato'),

    (1, @folder_documents, 'employee_handbook.pdf', 'Employee Handbook.pdf',
     'application/pdf', 1843200, '/storage/tenant_1/documents/handbook.pdf', 1, 'approvato'),

    (1, @folder_shared, 'meeting_notes_jan.docx', 'Meeting Notes January.docx',
     'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
     524288, '/storage/tenant_1/shared/meeting_jan.docx', 2, 'in_approvazione'),

    (1, @folder_projects, 'project_timeline.xlsx', 'Project Timeline.xlsx',
     'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
     786432, '/storage/tenant_1/projects/timeline.xlsx', 2, 'approvato')
ON DUPLICATE KEY UPDATE
    file_size = VALUES(file_size),
    status = VALUES(status);

-- ============================================
-- DEMO DATA - CHAT CHANNELS
-- ============================================
INSERT INTO chat_channels (tenant_id, name, description, type, owner_id) VALUES
    -- Demo Company Channels
    (1, 'General', 'General discussion channel for all team members', 'public', 1),
    (1, 'Development Team', 'Channel for development team discussions', 'private', 3),
    (1, 'Project Updates', 'Important project updates and announcements', 'public', 2),
    (1, 'Random', 'Off-topic discussions and team bonding', 'public', 1),

    -- Test Organization Channels
    (2, 'Main Channel', 'Primary communication channel', 'public', 7),
    (2, 'Announcements', 'Company announcements', 'public', 7);

SET @channel_general = (SELECT id FROM chat_channels WHERE tenant_id = 1 AND name = 'General' LIMIT 1);
SET @channel_dev = (SELECT id FROM chat_channels WHERE tenant_id = 1 AND name = 'Development Team' LIMIT 1);
SET @channel_project = (SELECT id FROM chat_channels WHERE tenant_id = 1 AND name = 'Project Updates' LIMIT 1);

-- ============================================
-- DEMO DATA - CHAT CHANNEL MEMBERS
-- ============================================
-- Add all Demo Company users to General channel
INSERT INTO chat_channel_members (tenant_id, channel_id, user_id, role) VALUES
    (1, @channel_general, 1, 'admin'),
    (1, @channel_general, 2, 'member'),
    (1, @channel_general, 3, 'member'),
    (1, @channel_general, 4, 'member'),
    (1, @channel_general, 5, 'member'),
    (1, @channel_general, 6, 'member'),

    -- Development Team channel members
    (1, @channel_dev, 3, 'admin'),
    (1, @channel_dev, 4, 'member'),
    (1, @channel_dev, 6, 'member'),

    -- Project Updates channel members
    (1, @channel_project, 2, 'admin'),
    (1, @channel_project, 1, 'member'),
    (1, @channel_project, 3, 'member'),
    (1, @channel_project, 4, 'member'),
    (1, @channel_project, 5, 'member');

-- ============================================
-- DEMO DATA - CHAT MESSAGES
-- ============================================
INSERT INTO chat_messages (tenant_id, channel_id, user_id, content, message_type) VALUES
    (1, @channel_general, 1, 'Welcome to CollaboraNexio! This is your general discussion channel.', 'text'),
    (1, @channel_general, 2, 'Good morning team! Ready for today''s sprint planning?', 'text'),
    (1, @channel_general, 3, 'Morning! Yes, I''ve updated all my task estimates.', 'text'),
    (1, @channel_general, 4, 'Good morning everyone! Looking forward to the meeting.', 'text'),

    (1, @channel_dev, 3, 'Team, please review the latest PR for the authentication module.', 'text'),
    (1, @channel_dev, 4, 'I''ll take a look right after the standup.', 'text'),
    (1, @channel_dev, 6, 'Found a few edge cases in testing. Will add comments to the PR.', 'text'),

    (1, @channel_project, 2, 'Website redesign is now at 35% completion. Great progress team!', 'text'),
    (1, @channel_project, 1, 'Excellent work! Let''s keep this momentum going.', 'text');

-- ============================================
-- DEMO DATA - CALENDAR EVENTS
-- ============================================
INSERT INTO calendar_events (
    tenant_id, title, description, organizer_id,
    start_datetime, end_datetime, event_type, status, location
) VALUES
    (1, 'Sprint Planning Meeting',
     'Weekly sprint planning and task assignment', 2,
     '2025-01-27 10:00:00', '2025-01-27 11:30:00', 'meeting', 'confirmed', 'Conference Room A'),

    (1, 'Team Standup',
     'Daily standup meeting', 2,
     '2025-01-27 09:00:00', '2025-01-27 09:15:00', 'meeting', 'confirmed', 'Virtual - Teams'),

    (1, 'Code Review Session',
     'Review pull requests and code quality', 3,
     '2025-01-28 14:00:00', '2025-01-28 15:00:00', 'meeting', 'confirmed', 'Dev Room'),

    (1, 'Project Deadline: Website Homepage',
     'Homepage implementation deadline', 2,
     '2025-02-01 17:00:00', '2025-02-01 17:00:00', 'task', 'confirmed', NULL),

    (1, 'Company All-Hands',
     'Monthly company-wide meeting', 1,
     '2025-02-01 15:00:00', '2025-02-01 16:00:00', 'meeting', 'confirmed', 'Main Hall');

-- ============================================
-- DEMO DATA - NOTIFICATIONS
-- ============================================
INSERT INTO notifications (tenant_id, user_id, type, title, message, data) VALUES
    (1, 2, 'task_assigned', 'New Task Assigned',
     'You have been assigned to "Develop homepage"',
     JSON_OBJECT('task_id', 2, 'project', 'Website Redesign')),

    (1, 3, 'mention', 'You were mentioned',
     'John Manager mentioned you in Project Updates channel',
     JSON_OBJECT('channel_id', @channel_project, 'message_id', 8)),

    (1, 4, 'task_due', 'Task Due Soon',
     'Task "Setup CI/CD pipeline" is due in 2 days',
     JSON_OBJECT('task_id', 3, 'due_date', '2025-02-15')),

    (1, 1, 'system', 'System Update',
     'System maintenance scheduled for this weekend',
     JSON_OBJECT('maintenance_start', '2025-02-01 22:00:00'));

-- ============================================
-- DEMO DATA - AUDIT LOGS
-- ============================================
INSERT INTO audit_logs (
    tenant_id, user_id, action, resource_type, resource_id,
    ip_address, user_agent, metadata
) VALUES
    (1, 1, 'login', 'user', '1',
     '192.168.1.100', 'Mozilla/5.0 Chrome/120.0.0.0',
     JSON_OBJECT('method', 'password', 'success', true)),

    (1, 2, 'create', 'project', @project_website,
     '192.168.1.101', 'Mozilla/5.0 Chrome/120.0.0.0',
     JSON_OBJECT('project_name', 'Website Redesign')),

    (1, 3, 'update', 'task', '2',
     '192.168.1.102', 'Mozilla/5.0 Firefox/121.0',
     JSON_OBJECT('field', 'status', 'old_value', 'todo', 'new_value', 'in_progress')),

    (1, 1, 'upload', 'file', '1',
     '192.168.1.100', 'Mozilla/5.0 Chrome/120.0.0.0',
     JSON_OBJECT('filename', 'company_policy_2025.pdf', 'size_bytes', 2457600)),

    (1, 2, 'create', 'chat_message', '2',
     '192.168.1.101', 'Mozilla/5.0 Chrome/120.0.0.0',
     JSON_OBJECT('channel', 'General', 'message_length', 47));

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Demo data loaded successfully' as Status, NOW() as CompletedAt;

-- Show summary of loaded data
SELECT 'Data Summary' as Report;
SELECT 'Tenants' as Entity, COUNT(*) as Count FROM tenants;
SELECT 'Users' as Entity, COUNT(*) as Count FROM users;
SELECT 'Projects' as Entity, COUNT(*) as Count FROM projects;
SELECT 'Tasks' as Entity, COUNT(*) as Count FROM tasks;
SELECT 'Folders' as Entity, COUNT(*) as Count FROM folders;
SELECT 'Files' as Entity, COUNT(*) as Count FROM files;
SELECT 'Chat Channels' as Entity, COUNT(*) as Count FROM chat_channels;
SELECT 'Chat Messages' as Entity, COUNT(*) as Count FROM chat_messages;
SELECT 'Calendar Events' as Entity, COUNT(*) as Count FROM calendar_events;
SELECT 'Notifications' as Entity, COUNT(*) as Count FROM notifications;
SELECT 'Audit Logs' as Entity, COUNT(*) as Count FROM audit_logs;