CollaboraNexio Database Management Scripts
==========================================
Version: 2025-09-25
Author: Database Architect

OVERVIEW
--------
This directory contains all database management scripts for CollaboraNexio.
The scripts handle database structure checking, safe resets, schema initialization,
and demo data loading.

FILES
-----
1. 01_check_structure.sql    - Analyzes current database structure and foreign keys
2. 02_safe_reset.sql         - Safely drops all tables (handles foreign key constraints)
3. 03_complete_schema.sql    - Complete database schema with all tables
4. 04_demo_data.sql          - Comprehensive demo data for testing
5. manage_database.php       - PHP script for automated database management

QUICK START
-----------
For a complete database setup from scratch:

Option 1: Using PHP Script (Recommended)
-----------------------------------------
Via Web Browser:
1. Navigate to: http://localhost/CollaboraNexio/database/manage_database.php
2. Click "Full Setup" button

Via Command Line:
1. cd C:\xampp\htdocs\CollaboraNexio\database
2. php manage_database.php full

Option 2: Manual SQL Execution
-------------------------------
1. mysql -u root
2. source C:\xampp\htdocs\CollaboraNexio\database\02_safe_reset.sql
3. source C:\xampp\htdocs\CollaboraNexio\database\03_complete_schema.sql
4. source C:\xampp\htdocs\CollaboraNexio\database\04_demo_data.sql

INDIVIDUAL OPERATIONS
--------------------

Check Current Database Structure:
  php manage_database.php check
  - Shows all tables and foreign key relationships
  - Lists tables with/without tenant_id support
  - Provides drop order for safe table removal

Reset Database (Remove All Data):
  php manage_database.php reset
  - Disables foreign key checks
  - Drops all tables safely
  - Re-enables foreign key checks

Initialize Schema Only:
  php manage_database.php init
  - Creates database if not exists
  - Creates all tables with proper structure
  - Sets up indexes and constraints

Load Demo Data:
  php manage_database.php demo
  - Inserts sample tenants
  - Creates demo users with hashed passwords
  - Adds projects, tasks, files, etc.

DEMO USER CREDENTIALS
--------------------
After loading demo data, use these credentials:

Tenant: Demo Company
- admin@demo.local / Admin123!     (Admin role)
- manager@demo.local / Admin123!   (Manager role)
- user1@demo.local / Admin123!     (User role)
- user2@demo.local / Admin123!     (User role)
- designer@demo.local / Admin123!  (User role)
- tester@demo.local / Admin123!    (User role)

Tenant: Test Organization
- admin@test.local / Admin123!     (Admin role)
- user@test.local / Admin123!      (User role)

DATABASE FEATURES
----------------
- Multi-tenant architecture (all tables have tenant_id)
- Comprehensive foreign key constraints
- Optimized indexes for performance
- Audit logging support
- Soft delete capability (deleted_at columns)
- JSON fields for flexible data storage
- UTC timestamp handling

TROUBLESHOOTING
--------------

Error: Cannot delete or update a parent row
  Solution: Run the reset script which disables foreign key checks

Error: Database 'collaboranexio' doesn't exist
  Solution: Run 'php manage_database.php init' to create it

Error: Access denied for user 'root'
  Solution: Update DB_USER and DB_PASS in manage_database.php

Error: Table already exists
  Solution: Run reset first, then initialize

TABLES INCLUDED
--------------
Core:
- tenants (multi-tenancy)
- users (authentication)
- user_sessions
- user_permissions
- password_resets

Project Management:
- projects
- project_members
- tasks
- task_assignments
- task_comments

File Management:
- folders
- files
- file_versions
- file_shares

Communication:
- chat_channels
- chat_channel_members
- chat_messages
- chat_message_reads

Calendar:
- calendar_events
- calendar_shares

System:
- notifications
- audit_logs

MAINTENANCE
----------
Regular maintenance tasks:

1. Check for orphaned records:
   mysql -u root collaboranexio < 01_check_structure.sql

2. Backup before major changes:
   mysqldump -u root collaboranexio > backup_$(date +%Y%m%d).sql

3. Optimize tables monthly:
   mysqlcheck -u root -o collaboranexio

SECURITY NOTES
-------------
- All passwords are hashed using bcrypt
- Demo data uses 'Admin123!' as the default password
- Change all passwords immediately in production
- Enable SSL/TLS for database connections in production
- Review and adjust user permissions as needed

SUPPORT
-------
For issues or questions:
1. Check the troubleshooting section above
2. Review error messages in PHP error log
3. Ensure XAMPP services are running
4. Verify MySQL is accessible on port 3306