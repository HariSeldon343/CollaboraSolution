# CollaboraNexio

## Overview

CollaboraNexio is a comprehensive enterprise-grade multi-tenant collaboration platform built with vanilla PHP 8.3. The platform provides organizations with a complete suite of collaboration tools including file management, task tracking, calendar systems, real-time chat, and advanced analytics - all within a secure, isolated multi-tenant architecture.

### Key Features

- **Multi-Tenant Architecture**: Complete data isolation between organizations
- **File Management System**: Secure file upload, sharing, and version control with SHA256 verification
- **Calendar & Events**: Shared calendars with event management and notifications
- **Task Management**: Project and task tracking with assignments and deadlines
- **Real-Time Chat**: Instant messaging with channels and direct messages
- **External Collaboration**: Secure file sharing with external partners via unique links
- **Dashboard Analytics**: Real-time metrics and usage analytics
- **Enterprise Security**: CSRF protection, rate limiting, and comprehensive audit logging
- **Workflow Automation**: Customizable approval workflows and business processes

## System Requirements

### Minimum Requirements

- **PHP**: 8.3 or higher
- **MySQL**: 8.0 or higher
- **Web Server**: Apache 2.4+ with mod_rewrite or Nginx 1.18+
- **RAM**: 2GB minimum (4GB recommended)
- **Storage**: 10GB minimum for application and uploads
- **Browser**: Chrome 90+, Firefox 88+, Safari 14+, Edge 90+

### PHP Extensions Required

```bash
# Required PHP extensions
php8.3-mysql
php8.3-json
php8.3-mbstring
php8.3-gd
php8.3-curl
php8.3-zip
php8.3-xml
php8.3-opcache
```

### Recommended Configuration

```ini
# php.ini recommended settings
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
max_file_uploads = 20
session.gc_maxlifetime = 7200
opcache.enable = 1
opcache.memory_consumption = 128
```

## Installation Guide

### Step 1: Clone or Download

```bash
# Clone the repository (if using Git)
git clone https://github.com/your-org/collaboranexio.git /var/www/collaboranexio

# Or download and extract the archive
wget https://your-domain.com/collaboranexio.zip
unzip collaboranexio.zip -d /var/www/collaboranexio

# For Windows XAMPP users
# Place files in: C:\xampp\htdocs\CollaboraNexio\
```

### Step 2: Create Directory Structure

**Windows (XAMPP):**
```batch
cd C:\xampp\htdocs\CollaboraNexio
create_structure.bat
```

**Linux/Mac:**
```bash
# Set proper ownership
chown -R www-data:www-data /var/www/collaboranexio

# Create required directories
mkdir -p api/v1/{auth,files,users,chat,calendar,tasks,workflows}
mkdir -p includes/{classes,functions,middleware,validators}
mkdir -p uploads/{avatars,documents,temp}
mkdir -p assets/{css,js,icons,fonts,images}
mkdir -p temp/{exports,imports}
mkdir -p test/{unit,integration,fixtures}
mkdir -p public/{downloads,shared,shares}
mkdir -p logs/{error,access,audit}
mkdir -p database/{migrations,seeds}
mkdir -p cron

# Set directory permissions
chmod 755 /var/www/collaboranexio
chmod -R 755 /var/www/collaboranexio/api
chmod -R 755 /var/www/collaboranexio/includes
chmod -R 775 /var/www/collaboranexio/uploads
chmod -R 775 /var/www/collaboranexio/temp
chmod -R 775 /var/www/collaboranexio/logs
chmod -R 775 /var/www/collaboranexio/public/shares
```

### Step 3: Create Database

```sql
-- Create database
CREATE DATABASE collabora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user (replace 'password' with a strong password)
CREATE USER 'collabora_user'@'localhost' IDENTIFIED BY 'your_strong_password';

-- Grant privileges
GRANT ALL PRIVILEGES ON collabora.* TO 'collabora_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step 4: Configure Application

```bash
# Copy configuration template
cp config.php.template config.php

# Edit configuration file
nano config.php
```

Update the following settings in `config.php`:

```php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'collabora');
define('DB_USER', 'collabora_user');
define('DB_PASS', 'your_strong_password');

// Application settings
define('APP_URL', 'https://your-domain.com');
define('APP_NAME', 'CollaboraNexio');
define('APP_ENV', 'production'); // Use 'development' for testing
define('DEBUG_MODE', false); // Set to true for development

// Security settings
define('ENCRYPTION_KEY', 'your-32-character-encryption-key');
define('JWT_SECRET', 'your-jwt-secret-key');

// Timezone
date_default_timezone_set('Europe/Rome');
```

### Step 5: Run Database Installation

**Option 1: Command Line**
```bash
# Navigate to the application directory
cd /var/www/collaboranexio

# Run the complete installation
php clean_install_db.php

# Or run phase by phase
php install_database.php
```

**Option 2: Web Installer**
```
Navigate to: https://your-domain.com/setup_db.php
```

**Option 3: Manual Installation**
```bash
# Import SQL files in order
mysql -u collabora_user -p collabora < database_schema.sql
mysql -u collabora_user -p collabora < install_phase1.sql
mysql -u collabora_user -p collabora < install_phase2_fixed.sql
mysql -u collabora_user -p collabora < install_phase3_fixed.sql
mysql -u collabora_user -p collabora < install_phase4.sql
mysql -u collabora_user -p collabora < install_phase5_fixed.sql
mysql -u collabora_user -p collabora < install_phase6.sql
```

### Step 6: Configure Web Server

#### Apache Configuration

Create a virtual host file: `/etc/apache2/sites-available/collaboranexio.conf`

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/collaboranexio

    <Directory /var/www/collaboranexio>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Security headers
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-Content-Type-Options "nosniff"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';"

    # Redirect to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/collaboranexio_error.log
    CustomLog ${APACHE_LOG_DIR}/collaboranexio_access.log combined
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/collaboranexio

    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
    SSLCertificateChainFile /path/to/chain.crt

    <Directory /var/www/collaboranexio>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Same security headers as above
</VirtualHost>
```

Enable the site:
```bash
a2ensite collaboranexio
a2enmod rewrite headers ssl
systemctl restart apache2
```

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/collaboranexio;
    index index.php index.html;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_index index.php;
    }

    location ~ /\.(ht|git|env) {
        deny all;
    }

    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    client_max_body_size 100M;
}
```

### Step 7: Create Initial Admin User

```bash
# Run the setup script
php setup_password.php
```

Or manually via SQL:
```sql
-- Create initial tenant
INSERT INTO tenants (name, domain, status, created_at)
VALUES ('Main Organization', 'your-domain.com', 'active', NOW());

-- Create admin user (use password_hash('your_password', PASSWORD_DEFAULT) in PHP to get hash)
INSERT INTO users (tenant_id, email, password, name, role, created_at)
VALUES (1, 'admin@yourdomain.com', '$2y$10$...hash...', 'System Admin', 'admin', NOW());
```

### Step 8: Set Up Cron Jobs

```bash
# Edit crontab
crontab -e

# Add the following jobs
# Clean temporary files every hour
0 * * * * php /var/www/collaboranexio/cron/clean_temp.php

# Process email notifications every 5 minutes
*/5 * * * * php /var/www/collaboranexio/cron/process_notifications.php

# Generate daily reports at 2 AM
0 2 * * * php /var/www/collaboranexio/cron/daily_reports.php

# Clean expired sessions daily
0 3 * * * php /var/www/collaboranexio/cron/clean_sessions.php

# Clean expired share links weekly
0 4 * * 0 php /var/www/collaboranexio/cron/clean_shares.php
```

### Step 9: Verify Installation

```bash
# Test database connection
php test_connection.php

# Verify database structure
php verify_database.php

# Check file permissions
php test_final.php
```

## Directory Structure

```
CollaboraNexio/
├── api/                      # API endpoints
│   ├── auth.php             # Authentication endpoints
│   ├── files.php            # File management API
│   ├── folders.php          # Folder operations
│   ├── tasks.php            # Task management API
│   ├── events.php           # Calendar events API
│   ├── chat_messages.php    # Chat messaging API
│   ├── channels.php         # Chat channels API
│   ├── chat-poll.php        # Long polling for chat
│   ├── dashboard.php        # Dashboard analytics API
│   ├── messages.php         # Message operations
│   ├── polling.php          # General polling endpoint
│   └── setup_rate_limits.php # Rate limiting setup
├── assets/                   # Static assets
│   ├── css/                 # Stylesheets
│   ├── js/                  # JavaScript files
│   └── images/              # Images and icons
├── includes/                 # Core includes
│   ├── auth.php             # Authentication functions
│   ├── db.php               # Database connection
│   ├── functions.php        # Utility functions
│   ├── security.php         # Security functions
│   ├── validation.php       # Input validation
│   └── middleware/          # Request middleware
├── database/                 # Database scripts
│   ├── migrations/          # Database migrations
│   └── seeds/               # Seed data
├── uploads/                  # User uploads (secured)
│   └── [tenant_id]/         # Tenant-specific folders
├── public/                   # Publicly accessible files
│   └── shares/              # External share links
├── logs/                     # Application logs
│   ├── error/               # Error logs
│   ├── access/              # Access logs
│   └── audit/               # Audit logs
├── temp/                     # Temporary files
├── cron/                     # Cron job scripts
├── test/                     # Test scripts
├── config.php               # Main configuration
├── index.php                # Application entry point
├── dashboard.php            # Dashboard interface
├── chat.php                 # Chat interface
└── README.md                # This file
```

## API Documentation

### Authentication

All API endpoints require authentication except login and public share endpoints. Authentication is session-based with CSRF token protection.

#### Login
```http
POST /api/auth.php?action=login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123"
}

Response:
{
    "success": true,
    "data": {
        "user_id": 1,
        "name": "John Doe",
        "role": "user",
        "tenant_id": 1,
        "session_token": "session_id_here"
    }
}
```

#### Logout
```http
POST /api/auth.php?action=logout

Response:
{
    "success": true,
    "message": "Logout effettuato con successo"
}
```

#### Check Session
```http
GET /api/auth.php?action=check

Response:
{
    "success": true,
    "data": {
        "authenticated": true,
        "user": {...}
    }
}
```

### File Management

#### Upload File
```http
POST /api/files.php?action=upload
Content-Type: multipart/form-data

Form Data:
- file: (binary)
- folder_id: 123 (optional)
- description: "File description" (optional)

Response:
{
    "success": true,
    "data": {
        "file_id": 456,
        "filename": "document.pdf",
        "size": 1048576,
        "mime_type": "application/pdf",
        "hash": "sha256_hash_here"
    }
}
```

#### List Files
```http
GET /api/files.php?action=list&folder_id=123&page=1&limit=20

Response:
{
    "success": true,
    "data": [
        {
            "id": 456,
            "filename": "document.pdf",
            "size": 1048576,
            "mime_type": "application/pdf",
            "created_at": "2024-01-15 10:30:00",
            "created_by": "John Doe"
        }
    ],
    "total": 15,
    "page": 1
}
```

#### Download File
```http
GET /api/files.php?action=download&id=456

Response: Binary file stream with appropriate headers
```

#### Share File Externally
```http
POST /api/files.php?action=share_external
Content-Type: application/json

{
    "file_id": 456,
    "expires_at": "2024-02-01",
    "password": "optional_password",
    "max_downloads": 10
}

Response:
{
    "success": true,
    "data": {
        "share_link": "https://domain.com/public/share/abc123def456",
        "expires_at": "2024-02-01 00:00:00",
        "share_code": "abc123def456"
    }
}
```

#### Delete File
```http
DELETE /api/files.php?action=delete
Content-Type: application/json

{
    "file_id": 456
}

Response:
{
    "success": true,
    "message": "File eliminato con successo"
}
```

### Folder Management

#### Create Folder
```http
POST /api/folders.php?action=create
Content-Type: application/json

{
    "name": "Project Documents",
    "parent_id": null,
    "description": "Project related files"
}

Response:
{
    "success": true,
    "data": {
        "folder_id": 789,
        "path": "/Project Documents"
    }
}
```

#### List Folders
```http
GET /api/folders.php?action=list&parent_id=0

Response:
{
    "success": true,
    "data": [
        {
            "id": 789,
            "name": "Project Documents",
            "parent_id": null,
            "file_count": 5,
            "created_at": "2024-01-10 09:00:00"
        }
    ]
}
```

### Task Management

#### Create Task
```http
POST /api/tasks.php?action=create
Content-Type: application/json

{
    "title": "Complete project documentation",
    "description": "Write comprehensive documentation for all modules",
    "project_id": 10,
    "assigned_to": 5,
    "due_date": "2024-02-15",
    "priority": "high",
    "tags": ["documentation", "urgent"]
}

Response:
{
    "success": true,
    "data": {
        "task_id": 789,
        "status": "pending",
        "created_at": "2024-01-15 14:30:00"
    }
}
```

#### Update Task Status
```http
PUT /api/tasks.php?action=update_status
Content-Type: application/json

{
    "task_id": 789,
    "status": "in_progress",
    "progress": 50
}

Response:
{
    "success": true,
    "message": "Stato aggiornato con successo"
}
```

#### Get Task Details
```http
GET /api/tasks.php?action=get&id=789

Response:
{
    "success": true,
    "data": {
        "id": 789,
        "title": "Complete project documentation",
        "description": "...",
        "status": "in_progress",
        "progress": 50,
        "assigned_to": {...},
        "comments": [...],
        "attachments": [...]
    }
}
```

### Calendar Events

#### Create Event
```http
POST /api/events.php?action=create
Content-Type: application/json

{
    "title": "Team Meeting",
    "description": "Weekly sync meeting",
    "start_date": "2024-01-20 14:00:00",
    "end_date": "2024-01-20 15:00:00",
    "location": "Conference Room A",
    "attendees": [2, 3, 4],
    "reminder_minutes": 15,
    "recurring": "weekly"
}

Response:
{
    "success": true,
    "data": {
        "event_id": 234,
        "calendar_link": "https://domain.com/calendar/event/234"
    }
}
```

#### Get Events
```http
GET /api/events.php?action=list&start=2024-01-01&end=2024-01-31

Response:
{
    "success": true,
    "data": [
        {
            "id": 234,
            "title": "Team Meeting",
            "start_date": "2024-01-20 14:00:00",
            "end_date": "2024-01-20 15:00:00",
            "attendees_count": 4,
            "status": "confirmed"
        }
    ],
    "total": 12
}
```

### Real-Time Chat

#### Send Message
```http
POST /api/chat_messages.php?action=send
Content-Type: application/json

{
    "channel_id": 5,
    "message": "Hello team!",
    "type": "text",
    "attachments": []
}

Response:
{
    "success": true,
    "data": {
        "message_id": 9876,
        "timestamp": "2024-01-15 10:45:23"
    }
}
```

#### Get Messages
```http
GET /api/chat_messages.php?action=get&channel_id=5&last_id=9870&limit=50

Response:
{
    "success": true,
    "data": [
        {
            "id": 9876,
            "user": {
                "id": 1,
                "name": "John Doe",
                "avatar": "..."
            },
            "message": "Hello team!",
            "timestamp": "2024-01-15 10:45:23",
            "edited": false
        }
    ]
}
```

#### Poll Messages (Long Polling)
```http
GET /api/chat-poll.php?channel_id=5&last_message_id=9875

Response (after new message or timeout):
{
    "success": true,
    "messages": [...],
    "last_id": 9876
}
```

#### Create Channel
```http
POST /api/channels.php?action=create
Content-Type: application/json

{
    "name": "project-alpha",
    "display_name": "Project Alpha Team",
    "description": "Discussion for Project Alpha",
    "is_private": false,
    "members": [1, 2, 3, 4]
}

Response:
{
    "success": true,
    "data": {
        "channel_id": 10,
        "name": "project-alpha"
    }
}
```

### Dashboard Analytics

#### Get Dashboard Stats
```http
GET /api/dashboard.php?action=stats

Response:
{
    "success": true,
    "data": {
        "users": {
            "total": 150,
            "active_today": 45,
            "new_this_month": 12
        },
        "storage": {
            "used": 5368709120,
            "limit": 107374182400,
            "percentage": 5
        },
        "tasks": {
            "total": 234,
            "completed_today": 12,
            "overdue": 3
        },
        "files": {
            "total": 1567,
            "uploaded_today": 23
        },
        "events": {
            "today": 5,
            "this_week": 18
        }
    }
}
```

#### Get Activity Feed
```http
GET /api/dashboard.php?action=activity&limit=20

Response:
{
    "success": true,
    "data": [
        {
            "type": "file_upload",
            "user": "John Doe",
            "description": "uploaded document.pdf",
            "timestamp": "2024-01-15 10:30:00"
        },
        {
            "type": "task_completed",
            "user": "Jane Smith",
            "description": "completed task: API Documentation",
            "timestamp": "2024-01-15 10:15:00"
        }
    ]
}
```

## Configuration

### Environment Configuration

Create a `.env` file (optional, for additional security):

```env
# Database
DB_HOST=localhost
DB_NAME=collabora
DB_USER=collabora_user
DB_PASSWORD=your_secure_password

# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_TIMEZONE=Europe/Rome

# Security
ENCRYPTION_KEY=32-character-random-string-here
JWT_SECRET=your-jwt-secret-key
SESSION_LIFETIME=7200

# Email Configuration
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURITY=tls
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_FROM_EMAIL=noreply@your-domain.com
SMTP_FROM_NAME=CollaboraNexio

# Storage
MAX_UPLOAD_SIZE=104857600
ALLOWED_EXTENSIONS=pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,gif,zip,rar,txt,csv
STORAGE_PATH=/var/www/collaboranexio/uploads

# Rate Limiting
RATE_LIMIT_REQUESTS=100
RATE_LIMIT_WINDOW=60
RATE_LIMIT_BAN_TIME=600

# External Services (Optional)
GOOGLE_MAPS_API_KEY=
RECAPTCHA_SITE_KEY=
RECAPTCHA_SECRET_KEY=
```

### Security Configuration

#### Session Security (`includes/session_config.php`)
```php
<?php
// Secure session configuration
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 7200);
ini_set('session.use_trans_sid', 0);
ini_set('session.sid_length', 48);
ini_set('session.sid_bits_per_character', 6);
```

#### CORS Configuration (`includes/cors.php`)
```php
<?php
$allowed_origins = [
    'https://your-domain.com',
    'https://app.your-domain.com',
    'https://mobile.your-domain.com'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}
```

#### Content Security Policy
```php
// includes/security_headers.php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Database Connection Failed
**Error**: "Connessione al database fallita" or "Failed to connect to database"

**Solutions**:
```bash
# Check MySQL service status
systemctl status mysql
# or for XAMPP
/opt/lampp/lampp status

# Test connection manually
mysql -u collabora_user -p -h localhost collabora

# Verify with PHP
php test_connection.php

# Check error logs
tail -f /var/log/mysql/error.log
```

#### 2. File Upload Failures
**Error**: "Caricamento file fallito" or "File upload failed"

**Solutions**:
```bash
# Check directory permissions
ls -la uploads/
chmod -R 775 uploads/

# Verify PHP settings
php -i | grep upload_max_filesize
php -i | grep post_max_size

# Check available disk space
df -h

# Review upload logs
tail -f logs/error/upload_errors.log

# Test upload directory
php -r "echo is_writable('uploads/') ? 'Writable' : 'Not writable';"
```

#### 3. Session Issues
**Error**: "Sessione scaduta" or login loops

**Solutions**:
```bash
# Check session save path
php -i | grep session.save_path

# Verify session directory permissions
ls -la /var/lib/php/sessions/
chmod 1733 /var/lib/php/sessions/

# Clear old sessions
php cron/clean_sessions.php

# Test session functionality
php -r "session_start(); echo session_id();"
```

#### 4. Rate Limiting Errors
**Error**: "Troppe richieste" or "Too many requests"

**Solutions**:
```php
// Adjust in config.php
define('RATE_LIMIT_REQUESTS', 200); // Increase limit
define('RATE_LIMIT_WINDOW', 60); // Time window in seconds

// Clear rate limit cache
php api/setup_rate_limits.php --reset

// Check if behind proxy - add to config.php
$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
```

#### 5. Chat/Real-Time Features Not Working
**Error**: Messages not appearing in real-time

**Solutions**:
```bash
# Check long polling configuration
grep -r "poll" api/

# Verify database triggers
mysql -u collabora_user -p collabora -e "SHOW TRIGGERS;"

# Test polling endpoint
curl http://localhost/api/chat-poll.php?channel_id=1

# Check JavaScript console for errors
# Enable browser developer tools

# Increase polling timeout if needed (in chat.php)
# pollTimeout: 30000 // 30 seconds
```

#### 6. External Share Links Not Working
**Error**: 404 on public share links

**Solutions**:
```bash
# Check .htaccess in public directory
cat public/.htaccess

# Verify Apache mod_rewrite
a2enmod rewrite
systemctl restart apache2

# Check share directory structure
ls -la public/shares/

# Test rewrite rules
curl -I http://localhost/public/share/test123

# Verify share record in database
mysql -u collabora_user -p collabora -e "SELECT * FROM file_shares WHERE share_code='test123';"
```

#### 7. Dashboard Not Loading
**Error**: Dashboard shows blank or error

**Solutions**:
```bash
# Check PHP error logs
tail -f /var/log/php/error.log

# Verify all required tables exist
php verify_database.php

# Check API endpoint
curl http://localhost/api/dashboard.php?action=stats

# Clear cache if implemented
php cron/clear_cache.php
```

#### 8. Permission Denied Errors
**Error**: "Permesso negato" or permission errors

**Solutions**:
```bash
# Fix file ownership
chown -R www-data:www-data /var/www/collaboranexio

# Fix directory permissions
find /var/www/collaboranexio -type d -exec chmod 755 {} \;
find /var/www/collaboranexio -type f -exec chmod 644 {} \;

# Special permissions for writable directories
chmod -R 775 uploads/ temp/ logs/ public/shares/
```

### Performance Optimization

#### 1. Enable OPcache
```ini
; Add to php.ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
opcache.validate_timestamps=1
```

#### 2. Database Optimization
```sql
-- Add indexes for common queries
ALTER TABLE files ADD INDEX idx_tenant_folder (tenant_id, folder_id);
ALTER TABLE files ADD INDEX idx_created (created_at);
ALTER TABLE tasks ADD INDEX idx_project_status (project_id, status);
ALTER TABLE tasks ADD INDEX idx_assigned (assigned_to, status);
ALTER TABLE messages ADD INDEX idx_channel_created (channel_id, created_at);
ALTER TABLE events ADD INDEX idx_date_range (start_date, end_date);
ALTER TABLE user_sessions ADD INDEX idx_user_last (user_id, last_activity);

-- Optimize all tables
OPTIMIZE TABLE files, folders, tasks, messages, events, users, tenants;

-- Analyze tables for query optimizer
ANALYZE TABLE files, folders, tasks, messages, events, users, tenants;
```

#### 3. MySQL Configuration
```ini
# Add to my.cnf or my.ini
[mysqld]
# Cache
query_cache_type = 1
query_cache_size = 256M
query_cache_limit = 2M

# InnoDB
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Connections
max_connections = 200
max_connect_errors = 10

# Temporary tables
tmp_table_size = 256M
max_heap_table_size = 256M
```

#### 4. Enable Compression
```apache
# Apache - Enable mod_deflate
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json
    AddOutputFilterByType DEFLATE application/x-javascript application/xml
</IfModule>
```

```nginx
# Nginx - Enable gzip
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss;
```

## FAQ

### General Questions

**Q: How many tenants can the system support?**
A: The system is designed to scale horizontally. With proper infrastructure and database optimization, it can support thousands of tenants. Each tenant's data is completely isolated.

**Q: Is there a mobile app available?**
A: The web interface is fully responsive and works excellently on mobile devices. Native iOS and Android apps are on the development roadmap for future releases.

**Q: Can I customize the interface for my organization?**
A: Yes, each tenant can customize:
- Logo and branding
- Color schemes
- Dashboard widgets
- Email templates
- Custom fields for tasks and projects

**Q: What languages are supported?**
A: Currently, the interface supports Italian and English. Additional languages can be added through language files in the `includes/languages/` directory.

**Q: Can I integrate with other systems?**
A: Yes, the platform provides RESTful APIs for integration. Webhooks for events and OAuth 2.0 support are planned for future releases.

### Technical Questions

**Q: Does it support LDAP/Active Directory?**
A: LDAP integration is available as an add-on module. Configuration example:
```php
// config_ldap.php
define('LDAP_HOST', 'ldap://your-dc.domain.com');
define('LDAP_PORT', 389);
define('LDAP_BASE_DN', 'dc=domain,dc=com');
define('LDAP_BIND_DN', 'cn=admin,dc=domain,dc=com');
```

**Q: Can I use PostgreSQL instead of MySQL?**
A: Currently, only MySQL 8.0+ and MariaDB 10.5+ are officially supported. PostgreSQL support is planned for version 2.0.

**Q: How do I backup the system?**
A: Use the included backup script or standard MySQL tools:
```bash
# Full backup
mysqldump -u collabora_user -p collabora > backup_$(date +%Y%m%d).sql

# Backup with files
php cron/backup.php --full --compress

# Automated daily backups
0 2 * * * /usr/bin/php /var/www/collaboranexio/cron/backup.php
```

**Q: What's the maximum file size I can upload?**
A: Default is 100MB, configurable in `config.php` and `php.ini`. The system supports chunked uploads for larger files up to 5GB.

**Q: Is there an API rate limit?**
A: Yes, default is 100 requests per minute per user. This is configurable per tenant and can be adjusted based on user roles.

### Security Questions

**Q: How is data encrypted?**
A:
- Passwords: bcrypt with cost factor 12
- Sensitive data: AES-256-CBC encryption
- File storage: Optional encryption at rest
- Transmission: Enforced HTTPS/TLS 1.2+

**Q: Is two-factor authentication supported?**
A: Yes, TOTP-based 2FA is supported and can be enforced at the tenant level. SMS-based 2FA is available with Twilio integration.

**Q: How are SQL injections prevented?**
A: All database queries use PDO prepared statements with parameterized queries. Input validation and sanitization are enforced at multiple layers.

**Q: What about XSS protection?**
A:
- All output is escaped using `htmlspecialchars()`
- Content Security Policy headers are enforced
- Input validation on all forms
- HTMLPurifier for rich text content

**Q: Is there audit logging?**
A: Yes, comprehensive audit logging tracks:
- Login/logout events
- File access and modifications
- Permission changes
- Data exports
- Failed authentication attempts

### Troubleshooting Questions

**Q: Why am I getting "Memory exhausted" errors?**
A: Increase PHP memory limit:
```ini
memory_limit = 256M  # or higher
```

**Q: File downloads are corrupted, what's wrong?**
A: Check for output before headers:
- Remove any whitespace before `<?php`
- Check for BOM in files
- Disable compression for downloads

**Q: Why are emails not being sent?**
A: Verify SMTP configuration:
```php
// Test email configuration
php test/test_email.php recipient@example.com
```

## Contributing

We welcome contributions! Please follow these guidelines:

### Development Setup

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Install development dependencies:
   ```bash
   composer install --dev
   npm install --dev
   ```
4. Make your changes
5. Run tests: `php vendor/bin/phpunit`
6. Commit: `git commit -am 'Add amazing feature'`
7. Push: `git push origin feature/amazing-feature`
8. Create a Pull Request

### Coding Standards

- Follow PSR-12 coding standards
- Use meaningful variable names in English
- Comments in Italian (as per project convention)
- Add unit tests for new features
- Update documentation for API changes
- Ensure PHP 8.3 compatibility
- No framework dependencies (vanilla PHP only)

### Testing

```bash
# Run all tests
php vendor/bin/phpunit

# Run specific test suite
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit --testsuite Integration

# Run with coverage
php vendor/bin/phpunit --coverage-html coverage/

# Run static analysis
php vendor/bin/phpstan analyse
php vendor/bin/psalm
```

### Code Review Checklist

- [ ] Code follows PSR-12 standards
- [ ] No SQL injection vulnerabilities
- [ ] Proper input validation
- [ ] Error handling implemented
- [ ] Unit tests included
- [ ] Documentation updated
- [ ] No sensitive data in logs
- [ ] Performance impact considered

### Security Reporting

Found a security vulnerability? Please email security@collaboranexio.com directly. Do not create public issues for security vulnerabilities.

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

## License

Copyright (c) 2024 CollaboraNexio Development Team. All rights reserved.

This is proprietary software. Unauthorized copying, modification, distribution, or use of this software, via any medium, is strictly prohibited without explicit written permission from the copyright holders.

For licensing inquiries, contact: licensing@collaboranexio.com

## Support

### Resources
- **Documentation**: https://docs.collaboranexio.com
- **API Reference**: https://api-docs.collaboranexio.com
- **Community Forum**: https://forum.collaboranexio.com
- **Knowledge Base**: https://kb.collaboranexio.com

### Contact
- **General Support**: support@collaboranexio.com
- **Enterprise Support**: enterprise@collaboranexio.com
- **Sales**: sales@collaboranexio.com
- **Security Issues**: security@collaboranexio.com

### Support Tiers
- **Community**: Forum support, documentation access
- **Professional**: Email support, 48-hour response time
- **Enterprise**: Priority support, phone support, dedicated account manager

## Acknowledgments

Special thanks to:
- The PHP development team for PHP 8.3
- MySQL team for the robust database engine
- Open-source community for inspiration and tools
- All contributors and beta testers
- Our clients for valuable feedback

---

**Version**: 1.7.0
**Last Updated**: January 2024
**Status**: Production Ready
**Build**: Stable