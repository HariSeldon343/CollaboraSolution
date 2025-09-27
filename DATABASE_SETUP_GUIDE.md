# CollaboraNexio Database Setup Guide

## Overview
This guide helps you set up and troubleshoot the database for CollaboraNexio.

## Files Created/Modified

### 1. Database Configuration
- **config.php** - Added missing constants (`DB_PERSISTENT`, `LOG_LEVEL`)

### 2. Database Schema
- **database_schema.sql** - Complete SQL script with:
  - Database creation
  - All required tables (multi-tenant support)
  - Proper indexes for performance
  - Foreign key constraints
  - Demo data with test users

### 3. Setup Scripts
- **setup_database.php** - Automated database initialization script
- **test_db_connection.php** - Database connectivity test tool

### 4. API Response Handler
- **includes/api_response.php** - Standardized JSON response handler
  - Suppresses PHP errors from breaking JSON output
  - Provides consistent error handling
  - Includes CORS headers for development

### 5. Updated API Endpoints
- **api/users/list.php** - Updated to use new response handler
- **api/users/create.php** - Updated with proper error handling
- **api/users/update.php** - Updated with proper error handling
- **api/users/delete.php** - Updated with proper error handling

### 6. Authentication Fix
- **includes/auth_simple.php** - Added `validateCSRFToken` method for compatibility

## Setup Instructions

### Step 1: Test Database Connection
1. Open browser and navigate to:
   ```
   http://localhost:8888/CollaboraNexio/test_db_connection.php
   ```
2. This will show you:
   - Current database configuration
   - Connection status
   - Existing tables (if any)
   - Sample data

### Step 2: Initialize Database
1. If database doesn't exist or is empty, navigate to:
   ```
   http://localhost:8888/CollaboraNexio/setup_database.php
   ```
2. This script will:
   - Create the database if it doesn't exist
   - Create all required tables
   - Insert demo data
   - Generate proper password hashes

### Step 3: Verify Installation
After running setup, you should have:
- Database: `collaboranexio`
- 13+ tables created
- 6 demo users
- 2 demo tenants

## Demo Credentials

After setup, you can login with these credentials:

| Email | Password | Role | Tenant |
|-------|----------|------|--------|
| admin@demo.local | Admin123! | Admin | Demo Company |
| manager@demo.local | Admin123! | Manager | Demo Company |
| user1@demo.local | Admin123! | User | Demo Company |
| user2@demo.local | Admin123! | User | Demo Company |
| admin@test.local | Admin123! | Admin | Test Organization |
| user@test.local | Admin123! | User | Test Organization |

## Testing the User Management Page

1. Login to the system
2. Navigate to: `http://localhost:8888/CollaboraNexio/utenti.php`
3. The page should now load without errors
4. You should see the list of users
5. Try creating, editing, and deleting users

## API Endpoints

All API endpoints now return proper JSON responses even when errors occur:

### User Management APIs
- `GET /api/users/list.php` - List users with pagination
- `POST /api/users/create.php` - Create new user
- `POST /api/users/update.php` - Update existing user
- `POST /api/users/delete.php` - Delete user
- `POST /api/users/toggle-status.php` - Toggle user status

### Response Format
All API responses follow this format:
```json
{
  "success": true|false,
  "timestamp": 1234567890,
  "message": "Operation message",
  "data": { ... } // Optional data
}
```

## Troubleshooting

### If you see "Database connection failed"
1. Make sure XAMPP is running
2. Verify MySQL service is started in XAMPP Control Panel
3. Check that MySQL is on port 3306
4. Verify root user has no password (XAMPP default)

### If you see PHP errors in API responses
1. Clear browser cache
2. Make sure you're using the updated API files
3. Check that `includes/api_response.php` exists
4. Verify all required constants are defined in `config.php`

### If tables are missing
1. Run `setup_database.php` again
2. Check MySQL error log for any issues
3. Verify user has CREATE TABLE privileges

### If login doesn't work
1. Make sure session cookies are enabled
2. Clear browser cookies for localhost
3. Verify the users table has demo data
4. Check that password hashes were properly generated

## Database Structure

### Key Tables
1. **tenants** - Multi-tenant organizations
2. **users** - System users with tenant association
3. **projects** - Project management
4. **tasks** - Task tracking
5. **files/folders** - File management
6. **chat_channels/messages** - Communication
7. **audit_logs** - Activity tracking
8. **notifications** - User notifications

### Multi-Tenancy
All tables include `tenant_id` for data isolation between organizations.

## Security Features
- Password hashing with bcrypt
- CSRF token validation
- SQL injection prevention via prepared statements
- XSS prevention via output escaping
- Session-based authentication
- Audit logging for all actions

## Next Steps
1. Test all user management functions
2. Implement additional features as needed
3. Configure email settings for notifications
4. Set up file upload directories
5. Customize for production deployment