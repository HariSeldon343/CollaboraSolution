# CollaboraNexio Integration Test Suite

## Overview

This comprehensive integration test suite validates all modules of the CollaboraNexio multi-tenant collaboration platform.

## Features

- **Complete Module Coverage**: Tests all 7 core modules of the platform
- **Multi-tenant Testing**: Validates tenant isolation and data security
- **Performance Benchmarking**: Tracks execution time for each test
- **Detailed Reporting**: Console and HTML report generation
- **Error Handling**: Tests edge cases and error scenarios
- **Database Integrity**: Validates data consistency and relationships

## Modules Tested

1. **Authentication & Multi-tenancy**
   - User registration and login
   - Tenant creation and isolation
   - Password security
   - Session management
   - Role-based access control
   - Login attempt tracking

2. **File Management**
   - File upload/download
   - Folder operations
   - File permissions
   - File versioning
   - File search
   - File sharing
   - Storage quota management

3. **Calendar & Events**
   - Event CRUD operations
   - Recurring events
   - Event reminders
   - Event invitations
   - Calendar sharing
   - Event conflict detection

4. **Task Management**
   - Project creation
   - Task CRUD operations
   - Task assignments
   - Task dependencies
   - Status workflow
   - Gantt chart data
   - Comments and attachments

5. **Real-time Chat**
   - Channel management
   - Message sending
   - Threading support
   - Channel membership
   - Unread notifications
   - Message search
   - Reactions and direct messages

## Requirements

- PHP 8.3 or higher
- MySQL/MariaDB database
- Write permissions for test directories
- CollaboraNexio database configured in `/config.php`

## Installation

1. Ensure the database is configured in `/config.php`
2. Create test directories (automatically done by the script):
   ```bash
   mkdir -p test/reports test/temp
   ```

## Usage

### Run All Tests
```bash
php test/run_all_tests.php
```

### Run Specific Module
```bash
php test/run_all_tests.php auth
php test/run_all_tests.php files
php test/run_all_tests.php calendar
php test/run_all_tests.php tasks
php test/run_all_tests.php chat
```

### Options
- `--verbose` or `-v`: Show detailed test output
- `--html-report` or `-h`: Generate HTML report

### Examples
```bash
# Run all tests with verbose output
php test/run_all_tests.php --verbose

# Run authentication tests with HTML report
php test/run_all_tests.php auth --html-report

# Run all tests with both verbose output and HTML report
php test/run_all_tests.php --verbose --html-report
```

## Test Results

### Console Output
- Color-coded results (green = pass, red = fail)
- Execution time for each test
- Module-wise breakdown
- Overall success rate

### HTML Report
- Interactive module expansion
- Detailed test results
- Visual progress indicators
- Execution statistics
- Located in `test/reports/` directory

## Test Data

The test suite:
- Creates temporary test data (tenants, users, files, etc.)
- Automatically cleans up after each module
- Does not affect production data
- Uses unique timestamps to avoid conflicts

## Performance Benchmarks

Each test tracks:
- Individual test execution time
- Module completion time
- Total suite execution time
- Database query performance

## Error Handling

The suite tests:
- Invalid input scenarios
- Permission violations
- Resource conflicts
- Database constraints
- API error responses

## Customization

### Adding New Tests

1. Create a new test class extending `BaseTest`
2. Implement required methods:
   - `getModuleName()`
   - `runTests()`
   - `setupTestData()`
   - `cleanupTestData()`

3. Register in `TestRunner`:
```php
$this->testModules['newmodule'] = NewModuleTest::class;
```

### Modifying Test Parameters

Edit constants at the top of `run_all_tests.php`:
- `TEST_VERSION`: Suite version
- `REPORT_DIR`: Report output directory
- `TEMP_DIR`: Temporary file directory

## Troubleshooting

### Database Connection Issues
- Verify database credentials in `/config.php`
- Ensure database server is running
- Check database user permissions

### File Permission Issues
- Ensure write permissions for `test/reports/`
- Verify upload directory permissions
- Check PHP user permissions

### Memory Issues
For large datasets, increase PHP memory limit:
```php
ini_set('memory_limit', '256M');
```

## Security Notes

- Test data uses secure password hashing
- SQL injection prevention via prepared statements
- Tenant isolation validation
- File upload security checks
- Session security testing

## Contributing

When adding new tests:
1. Follow existing test structure
2. Include both positive and negative test cases
3. Clean up all test data
4. Document test purpose and expected results
5. Update this README with new test details

## License

Part of CollaboraNexio - Multi-tenant Collaboration Platform

## Support

For issues or questions, refer to the main CollaboraNexio documentation or contact the development team.