# CollaboraNexio Deployment Improvements

## Disk Space Check Enhancements

### Updates to deploy.bat

1. **Improved Disk Space Detection**
   - Now uses WMIC for accurate disk space checking on Windows
   - Displays available space in both GB and MB for better readability
   - Reduced minimum space requirement from 500MB to 100MB for development

2. **Force Deployment Option**
   - Added `--force` flag to bypass disk space checks
   - Usage: `deploy.bat --force`
   - Shows warning but continues deployment

3. **Interactive Confirmation**
   - When disk space is low, prompts user to continue
   - Provides helpful suggestions including running cleanup.bat
   - Shows actual available space in error messages

4. **Help Command**
   - Added `--help` flag to show usage information
   - Usage: `deploy.bat --help`

### New cleanup.bat Script

A comprehensive cleanup utility to free disk space before deployment:

#### Features:

1. **Windows Temporary Files Cleanup**
   - Cleans Windows temp directory
   - Cleans user temp directory

2. **Project Files Cleanup**
   - Removes project temporary files
   - Cleans PHP session files in XAMPP

3. **Log Files Management**
   - Removes logs older than 7 days (configurable)
   - Cleans Apache logs
   - Cleans MySQL error logs

4. **Backup Management**
   - Removes backups older than 30 days (configurable)
   - Shows space freed from old backups

5. **Browser Cache Cleanup (Optional)**
   - Cleans Chrome cache
   - Cleans Firefox cache
   - Cleans Edge cache
   - Requires user confirmation

6. **Additional Cleanup**
   - Windows Update cache (requires admin rights)
   - Empties Recycle Bin
   - Optional old upload files cleanup

7. **Space Reporting**
   - Shows initial free space
   - Shows final free space
   - Reports total space freed
   - Offers to run deployment after cleanup

## Usage

### Standard Deployment
```batch
deploy.bat
```

### Force Deployment (Skip Disk Check)
```batch
deploy.bat --force
```

### Clean Up Disk Space
```batch
cleanup.bat
```

### Typical Workflow
1. Run `cleanup.bat` to free up space
2. Script will offer to run deployment automatically
3. Or manually run `deploy.bat` after cleanup

## Requirements

- Windows 10/11
- XAMPP installed at C:\xampp
- Standard Windows user permissions
- Admin rights optional (for Windows Update cache cleanup)

## Configuration

Both scripts use these configurable values:

- **deploy.bat**
  - `MIN_SPACE_MB=100` - Minimum required space in MB

- **cleanup.bat**
  - `DAYS_OLD_LOGS=7` - Delete logs older than this
  - `DAYS_OLD_BACKUPS=30` - Delete backups older than this

## Error Handling

- Disk space check now handles edge cases better
- Continues with warning for non-critical space issues
- Shows clear error messages with actual space available
- Provides actionable suggestions for resolving space issues

## Compatibility

Tested on:
- Windows 10
- Windows 11
- XAMPP 8.2.x with PHP 8.3

## Notes

- The cleanup script is safe and won't delete critical system files
- Browser cache cleanup requires closing browsers
- Some cleanup operations may require admin privileges
- Always backup important data before running cleanup