<?php
/**
 * CollaboraNexio - Configuration Wrapper
 * This file includes the main configuration from the root directory
 * It serves as a bridge to maintain backward compatibility with the expected file structure
 */

// Include the main configuration file from the root directory
require_once dirname(__DIR__) . '/config.php';

// Additional configuration specific to the config directory can be added here if needed
// This ensures that both /config.php and /config/config.php work correctly

// Verify that the main configuration has been loaded
if (!defined('DB_HOST')) {
    die('Error: Main configuration file not loaded properly.');
}

// Optional: Define a constant to indicate this config wrapper was loaded
define('CONFIG_WRAPPER_LOADED', true);