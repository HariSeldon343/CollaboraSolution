================================================================================
PHP CONFIGURATION FIX FOR COLLABORANEXIO ON WINDOWS XAMPP
================================================================================

PROBLEM SUMMARY:
----------------
1. PHP modules being loaded twice causing "already loaded" warnings
2. BCMath extension missing or not enabled
3. PHP version detection issues in deployment scripts

SOLUTION FILES CREATED:
-----------------------
1. fix_php_config.bat    - Main fix utility (RUN THIS FIRST)
2. check_php.bat         - Quick PHP status checker
3. test_php_config.php   - Web-based configuration test
4. deploy.bat            - Updated with better PHP detection

HOW TO FIX:
-----------
1. Run as Administrator: fix_php_config.bat
   - This will backup your php.ini
   - Remove duplicate module declarations
   - Enable BCMath extension
   - Apply optimizations

2. Verify the fix: Run check_php.bat
   - Shows PHP version
   - Lists extension status
   - Identifies any remaining issues

3. Test via browser: http://localhost/CollaboraNexio/test_php_config.php
   - Web-based configuration test
   - Shows all settings and requirements

WHAT THE FIX DOES:
------------------
✓ Creates backup of original php.ini
✓ Removes duplicate extension declarations
✓ Enables BCMath extension if available
✓ Sets optimal memory limits (256M)
✓ Sets optimal upload sizes (50M)
✓ Restarts Apache service automatically
✓ Updates deploy.bat to use PHP 8.2+ (instead of 8.3+)
✓ Suppresses module warnings in scripts

MANUAL FIXES (if automated fix fails):
---------------------------------------
1. Edit C:\xampp\php\php.ini manually
2. Search for duplicate lines like:
   extension=openssl
   extension=pdo_mysql
   extension=mbstring
   etc.
3. Keep only ONE of each extension declaration
4. Add: extension=bcmath (if missing)
5. Restart Apache from XAMPP Control Panel

COMMON XAMPP PHP VERSIONS:
--------------------------
- XAMPP 8.2.x comes with PHP 8.2.x
- XAMPP 8.1.x comes with PHP 8.1.x
- The scripts now check for PHP 8.2+ (more compatible)

TROUBLESHOOTING:
----------------
1. If "bcmath not found":
   - BCMath is usually included in XAMPP
   - Check if php_bcmath.dll exists in C:\xampp\php\ext\
   - If missing, download from https://windows.php.net/downloads/pecl/

2. If Apache won't restart:
   - Stop Apache from XAMPP Control Panel
   - Run fix_php_config.bat again
   - Start Apache from XAMPP Control Panel

3. If deploy.bat still shows errors:
   - Run check_php.bat first to verify configuration
   - Make sure you're running Command Prompt as Administrator

BACKUP LOCATION:
----------------
Your original php.ini is backed up with timestamp in:
C:\xampp\php\php.ini.backup_[DATE]_[TIME]

To restore: Copy the backup file back to C:\xampp\php\php.ini

================================================================================
For support, check the log files in the CollaboraNexio directory
================================================================================