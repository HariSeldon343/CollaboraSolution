<?php
/**
 * CollaboraNexio - Favicon Include
 * Include this file in all pages to add favicon support
 */

// Get base URL from config or use relative path
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
?>
<!-- Favicon -->
<link rel="icon" type="image/svg+xml" href="<?php echo $baseUrl; ?>/assets/images/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo $baseUrl; ?>/assets/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo $baseUrl; ?>/assets/images/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo $baseUrl; ?>/assets/images/logo.png">
<meta name="theme-color" content="#2563eb">
<!-- Open Graph / Social Media -->
<meta property="og:image" content="<?php echo $baseUrl; ?>/assets/images/logo.png">
<meta property="og:image:type" content="image/svg+xml">
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">