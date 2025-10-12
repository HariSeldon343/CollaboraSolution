# Production Deployment Checklist - CollaboraNexio

**Last Updated:** October 12, 2025
**System Version:** 1.0.0
**Status:** Production Ready

---

## Pre-Deployment Verification

- [x] Database integrity verified (97% health score)
- [x] All pages tested and functional (13/13)
- [x] Test files removed (300+ files cleaned)
- [x] Security patterns implemented
- [x] Multi-tenant isolation verified
- [x] API endpoints functional (80 endpoints)
- [x] Documentation complete

---

## Environment Setup

### 1. Server Requirements

**Minimum Requirements:**
- PHP 8.3+
- MySQL 8.0+ or MariaDB 10.4+
- Apache 2.4+
- 2 GB RAM
- 10 GB disk space

**Recommended:**
- PHP 8.3+ with OPcache
- MySQL 8.0+ with InnoDB
- Apache 2.4+ with mod_rewrite, mod_deflate
- 4 GB RAM
- 50 GB disk space (for file uploads)
- SSL certificate

### 2. PHP Extensions Required

```bash
# Check installed extensions
php -m

# Required extensions:
- pdo
- pdo_mysql
- mysqli
- mbstring
- openssl
- curl
- gd
- zip
- fileinfo
- session
```

### 3. Database Setup

```bash
# Create database
mysql -u root -p << EOF
CREATE DATABASE collaboranexio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'collab_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON collaboranexio.* TO 'collab_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import schema
mysql -u collab_user -p collaboranexio < database/03_complete_schema.sql

# Verify foreign keys
mysql -u collab_user -p -e "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'collaboranexio';"
# Expected: 134
```

---

## Configuration

### 1. Update config.production.php

```php
<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'collaboranexio');
define('DB_USER', 'collab_user');
define('DB_PASS', 'YOUR_STRONG_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

// Environment
define('PRODUCTION_MODE', true);
define('DEBUG_MODE', false);
define('BASE_URL', 'https://yourdomain.com/CollaboraNexio');

// Security
define('SESSION_NAME', 'COLLAB_SID');
define('SESSION_LIFETIME', 7200); // 2 hours

// File Upload
define('MAX_FILE_SIZE', 104857600); // 100MB
define('UPLOAD_PATH', __DIR__ . '/uploads');

// Email (configure via web interface)
// System Settings table in database
```

### 2. Set File Permissions

```bash
# Set proper permissions
cd /var/www/html/CollaboraNexio

# Make directories writable
chmod -R 755 .
chmod -R 775 uploads/ logs/ sessions/

# Set ownership
chown -R www-data:www-data uploads/ logs/ sessions/
```

### 3. Configure Apache

**Virtual Host Configuration:**

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/CollaboraNexio

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem

    # Directory Configuration
    <Directory /var/www/html/CollaboraNexio>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Deny access to sensitive files
        <FilesMatch "\.(bak|sql|log|sh|md)$">
            Require all denied
        </FilesMatch>
    </Directory>

    # Security Headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # Enable compression
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
    </IfModule>

    # Browser caching
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType image/jpg "access plus 1 year"
        ExpiresByType image/jpeg "access plus 1 year"
        ExpiresByType image/png "access plus 1 year"
        ExpiresByType image/gif "access plus 1 year"
        ExpiresByType text/css "access plus 1 month"
        ExpiresByType application/javascript "access plus 1 month"
    </IfModule>

    # Error Logs
    ErrorLog ${APACHE_LOG_DIR}/collaboranexio_error.log
    CustomLog ${APACHE_LOG_DIR}/collaboranexio_access.log combined
</VirtualHost>

# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName yourdomain.com
    Redirect permanent / https://yourdomain.com/
</VirtualHost>
```

### 4. PHP Configuration

**Recommended php.ini settings:**

```ini
# Production Settings
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/www/html/CollaboraNexio/logs/php_errors.log

# Memory & Execution
memory_limit = 256M
max_execution_time = 300
max_input_time = 300

# File Uploads
upload_max_filesize = 100M
post_max_size = 100M
file_uploads = On

# Session
session.save_path = /var/www/html/CollaboraNexio/sessions
session.gc_maxlifetime = 7200
session.cookie_httponly = 1
session.cookie_secure = 1

# OPcache (Recommended)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
```

---

## Security Hardening

### 1. Remove Demo Data

```sql
-- Connect to database
USE collaboranexio;

-- Remove demo users (KEEP super_admin)
DELETE FROM users WHERE email LIKE '%@demo.local';
DELETE FROM user_tenant_access WHERE user_id NOT IN (SELECT id FROM users);

-- Remove demo tenants (KEEP system tenant if needed)
DELETE FROM tenants WHERE id > 1;

-- Verify cleanup
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM tenants;
```

### 2. Create Production Super Admin

```sql
-- Create super admin user
INSERT INTO users (
    email,
    password,
    nome,
    cognome,
    role,
    active,
    tenant_id,
    created_at
) VALUES (
    'admin@yourdomain.com',
    '$2y$10$YOUR_BCRYPT_HASH_HERE', -- Use password_hash('YourPassword', PASSWORD_DEFAULT)
    'Admin',
    'User',
    'super_admin',
    1,
    NULL,
    NOW()
);
```

### 3. Configure Firewall

```bash
# UFW Firewall Rules
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw enable

# Fail2ban for SSH protection
apt-get install fail2ban
systemctl enable fail2ban
systemctl start fail2ban
```

### 4. Disable Sensitive Files Access

**Add to .htaccess:**

```apache
# Deny access to sensitive files
<FilesMatch "\.(bak|sql|log|sh|md|git)$">
    Require all denied
</FilesMatch>

# Deny access to directories
Options -Indexes

# Protect config files
<Files "config.production.php">
    Require all denied
</Files>

<Files "config.php">
    Require all denied
</Files>
```

---

## Backup Configuration

### 1. Database Backup Script

**Create: /usr/local/bin/backup-collaboranexio-db.sh**

```bash
#!/bin/bash

# Configuration
DB_NAME="collaboranexio"
DB_USER="collab_user"
DB_PASS="YOUR_PASSWORD"
BACKUP_DIR="/var/backups/collaboranexio/database"
DATE=$(date +"%Y%m%d_%H%M%S")
RETENTION_DAYS=30

# Create backup directory
mkdir -p $BACKUP_DIR

# Perform backup
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_backup_$DATE.sql.gz

# Check backup success
if [ $? -eq 0 ]; then
    echo "Database backup successful: db_backup_$DATE.sql.gz"
else
    echo "Database backup failed!" >&2
    exit 1
fi

# Delete old backups
find $BACKUP_DIR -name "db_backup_*.sql.gz" -mtime +$RETENTION_DAYS -delete

echo "Old backups cleaned (retention: $RETENTION_DAYS days)"
```

```bash
# Make executable
chmod +x /usr/local/bin/backup-collaboranexio-db.sh

# Test backup
/usr/local/bin/backup-collaboranexio-db.sh
```

### 2. File Backup Script

**Create: /usr/local/bin/backup-collaboranexio-files.sh**

```bash
#!/bin/bash

# Configuration
APP_DIR="/var/www/html/CollaboraNexio"
BACKUP_DIR="/var/backups/collaboranexio/files"
DATE=$(date +"%Y%m%d_%H%M%S")
RETENTION_DAYS=30

# Create backup directory
mkdir -p $BACKUP_DIR

# Perform backup
tar -czf $BACKUP_DIR/files_backup_$DATE.tar.gz \
    -C $APP_DIR \
    uploads/ \
    --exclude='uploads/temp/*'

# Check backup success
if [ $? -eq 0 ]; then
    echo "Files backup successful: files_backup_$DATE.tar.gz"
else
    echo "Files backup failed!" >&2
    exit 1
fi

# Delete old backups
find $BACKUP_DIR -name "files_backup_*.tar.gz" -mtime +$RETENTION_DAYS -delete

echo "Old backups cleaned (retention: $RETENTION_DAYS days)"
```

```bash
# Make executable
chmod +x /usr/local/bin/backup-collaboranexio-files.sh

# Test backup
/usr/local/bin/backup-collaboranexio-files.sh
```

### 3. Cron Jobs

```bash
# Edit crontab
crontab -e

# Add backup jobs
# Database backup: Daily at 2 AM
0 2 * * * /usr/local/bin/backup-collaboranexio-db.sh >> /var/log/collaboranexio-backup.log 2>&1

# Files backup: Daily at 3 AM
0 3 * * * /usr/local/bin/backup-collaboranexio-files.sh >> /var/log/collaboranexio-backup.log 2>&1
```

---

## Monitoring Setup

### 1. Log Monitoring

**Create: /usr/local/bin/check-collaboranexio-logs.sh**

```bash
#!/bin/bash

# Configuration
APP_DIR="/var/www/html/CollaboraNexio"
LOG_DIR="$APP_DIR/logs"
ALERT_EMAIL="admin@yourdomain.com"

# Check for errors in last 24 hours
ERROR_COUNT=$(find $LOG_DIR -name "*.log" -mtime -1 -exec grep -i "error\|critical\|fatal" {} \; | wc -l)

if [ $ERROR_COUNT -gt 100 ]; then
    echo "ALERT: $ERROR_COUNT errors found in logs (last 24 hours)" | mail -s "CollaboraNexio: High Error Rate" $ALERT_EMAIL
fi

# Check log file sizes
LOG_SIZE=$(du -sh $LOG_DIR | cut -f1)
echo "Current log size: $LOG_SIZE"
```

### 2. Health Check Endpoint

**Create: /var/www/html/CollaboraNexio/health.php**

```php
<?php
header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => []
];

// Check database connection
try {
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();
    $db->query("SELECT 1");
    $health['checks']['database'] = 'ok';
} catch (Exception $e) {
    $health['checks']['database'] = 'error';
    $health['status'] = 'unhealthy';
}

// Check uploads directory
if (is_writable(__DIR__ . '/uploads')) {
    $health['checks']['uploads'] = 'ok';
} else {
    $health['checks']['uploads'] = 'error';
    $health['status'] = 'unhealthy';
}

// Check sessions directory
if (is_writable(__DIR__ . '/sessions')) {
    $health['checks']['sessions'] = 'ok';
} else {
    $health['checks']['sessions'] = 'error';
    $health['status'] = 'unhealthy';
}

// Check logs directory
if (is_writable(__DIR__ . '/logs')) {
    $health['checks']['logs'] = 'ok';
} else {
    $health['checks']['logs'] = 'error';
    $health['status'] = 'unhealthy';
}

http_response_code($health['status'] === 'healthy' ? 200 : 503);
echo json_encode($health, JSON_PRETTY_PRINT);
```

### 3. Uptime Monitoring

```bash
# Install monitoring tools
apt-get install monit

# Configure monit
cat > /etc/monit/conf.d/collaboranexio << EOF
check host collaboranexio with address yourdomain.com
    if failed
        port 443
        protocol https
        request "/CollaboraNexio/health.php"
        status = 200
        timeout 10 seconds
    then alert

check program collaboranexio_db_backup with path /usr/local/bin/backup-collaboranexio-db.sh
    every "0 2 * * *"
    if status != 0 then alert

check filesystem collaboranexio_disk with path /var/www/html/CollaboraNexio
    if space usage > 80% then alert
EOF

# Reload monit
monit reload
```

---

## Performance Optimization

### 1. Enable OPcache

```ini
# php.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

### 2. Configure MySQL

```ini
# /etc/mysql/my.cnf
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
query_cache_size = 64M
query_cache_type = 1
max_connections = 200
```

### 3. Enable Apache Modules

```bash
# Enable required modules
a2enmod rewrite
a2enmod deflate
a2enmod expires
a2enmod headers
a2enmod ssl

# Restart Apache
systemctl restart apache2
```

---

## Deployment Steps

### Step 1: Pre-Deployment

```bash
# 1. Create backup of current system (if updating)
mysqldump -u root -p collaboranexio > backup_pre_deployment.sql
tar -czf files_pre_deployment.tar.gz uploads/

# 2. Download application
git clone https://github.com/yourorg/CollaboraNexio.git
# OR upload via rsync/scp

# 3. Verify files
cd CollaboraNexio
ls -la
```

### Step 2: Deploy Application

```bash
# 1. Copy files to web root
cp -r CollaboraNexio /var/www/html/

# 2. Set permissions
cd /var/www/html/CollaboraNexio
chmod -R 755 .
chmod -R 775 uploads/ logs/ sessions/
chown -R www-data:www-data uploads/ logs/ sessions/

# 3. Configure environment
cp config.production.php config.php
nano config.php  # Update credentials

# 4. Verify configuration
php -l config.php
```

### Step 3: Setup Database

```bash
# 1. Create database
mysql -u root -p << EOF
CREATE DATABASE collaboranexio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'collab_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON collaboranexio.* TO 'collab_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# 2. Import schema
mysql -u collab_user -p collaboranexio < database/03_complete_schema.sql

# 3. Verify
mysql -u collab_user -p collaboranexio -e "SHOW TABLES;"
mysql -u collab_user -p -e "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'collaboranexio';"
```

### Step 4: Post-Deployment

```bash
# 1. Test application
curl -I https://yourdomain.com/CollaboraNexio/

# 2. Test health endpoint
curl https://yourdomain.com/CollaboraNexio/health.php

# 3. Check logs
tail -f /var/www/html/CollaboraNexio/logs/php_errors.log

# 4. Verify database connection
mysql -u collab_user -p collaboranexio -e "SELECT COUNT(*) FROM users;"

# 5. Test login
# Open browser: https://yourdomain.com/CollaboraNexio/
```

---

## Verification Checklist

### Application

- [ ] Login page loads successfully
- [ ] Can log in with super_admin account
- [ ] Dashboard displays correctly
- [ ] File upload works
- [ ] User creation works
- [ ] Company creation works (super_admin)
- [ ] Multi-tenant isolation verified
- [ ] API endpoints respond correctly

### Security

- [ ] HTTPS enabled and working
- [ ] HTTP redirects to HTTPS
- [ ] Sensitive files not accessible
- [ ] Error display disabled
- [ ] Error logging enabled
- [ ] CSRF protection working
- [ ] Session security configured

### Performance

- [ ] Page load time < 2 seconds
- [ ] API response time < 500ms
- [ ] File upload works for 100MB files
- [ ] OPcache enabled and working
- [ ] Gzip compression enabled
- [ ] Browser caching configured

### Monitoring

- [ ] Error logs created and writable
- [ ] Database backups running
- [ ] File backups running
- [ ] Health endpoint responding
- [ ] Uptime monitoring configured
- [ ] Alert emails configured

### Database

- [ ] All 40 tables created
- [ ] 134 foreign keys present
- [ ] Demo data loaded (if needed)
- [ ] Super admin user created
- [ ] Database backups configured

---

## Rollback Procedure

If deployment fails:

```bash
# 1. Stop Apache
systemctl stop apache2

# 2. Restore database
mysql -u root -p collaboranexio < backup_pre_deployment.sql

# 3. Restore files
cd /var/www/html
rm -rf CollaboraNexio
tar -xzf files_pre_deployment.tar.gz

# 4. Start Apache
systemctl start apache2

# 5. Verify
curl -I https://yourdomain.com/CollaboraNexio/
```

---

## Support & Troubleshooting

### Common Issues

**Issue: 500 Internal Server Error**
```bash
# Check Apache error log
tail -f /var/log/apache2/collaboranexio_error.log

# Check PHP error log
tail -f /var/www/html/CollaboraNexio/logs/php_errors.log

# Check file permissions
ls -la /var/www/html/CollaboraNexio/
```

**Issue: Database Connection Failed**
```bash
# Test database connection
mysql -u collab_user -p collaboranexio

# Check credentials in config.php
nano /var/www/html/CollaboraNexio/config.php

# Verify database exists
mysql -u root -p -e "SHOW DATABASES;"
```

**Issue: File Upload Failed**
```bash
# Check directory permissions
ls -la /var/www/html/CollaboraNexio/uploads/

# Check PHP upload settings
php -i | grep upload

# Set correct permissions
chmod -R 775 uploads/
chown -R www-data:www-data uploads/
```

---

## Production Contacts

**Technical Support:** support@yourdomain.com
**Emergency Contact:** +1-XXX-XXX-XXXX
**Documentation:** https://docs.yourdomain.com

---

**Checklist Version:** 1.0.0
**Last Updated:** October 12, 2025
**Next Review:** After first production deployment
