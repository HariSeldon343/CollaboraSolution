<?php
/**
 * Shared layout head include.
 *
 * Usage (inside <head>):
 *   $pageTitle = '...';
 *   $pageCss = ['assets/css/foo.css', ...]; // optional
 *   $pageMeta = ['<meta ...>', ...];        // optional (already-escaped strings)
 *   $pageScripts = ['assets/js/foo.js', ...]; // optional (defer)
 *   require __DIR__ . '/includes/layout_head.php';
 *
 * Notes:
 * - `$csrfToken` is optional (if present, we output the meta tag).
 * - We keep the core CSS order consistent with dashboard.php.
 */
declare(strict_types=1);

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Nexio';
$pageCss = isset($pageCss) && is_array($pageCss) ? $pageCss : [];
$pageMeta = isset($pageMeta) && is_array($pageMeta) ? $pageMeta : [];
$pageScripts = isset($pageScripts) && is_array($pageScripts) ? $pageScripts : [];

// Asset base: make links work also from subdirectories like /tools or /api
$assetBase = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
$assetPrefix = $assetBase !== '' ? ($assetBase . '/') : '';

?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="ie=edge">
<?php if (isset($csrfToken) && $csrfToken !== null && $csrfToken !== ''): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars((string)$csrfToken); ?>">
<?php endif; ?>

<!-- Global session inactivity config (seconds) -->
<meta name="cnx-session-inactivity-seconds" content="300">
<meta name="cnx-session-countdown-seconds" content="30">
<?php if (isset($_SESSION['login_nonce']) && $_SESSION['login_nonce'] !== ''): ?>
    <meta name="cnx-login-nonce" content="<?php echo htmlspecialchars((string)$_SESSION['login_nonce']); ?>">
<?php endif; ?>

<title><?php echo htmlspecialchars($pageTitle); ?></title>

<?php require_once __DIR__ . '/favicon.php'; ?>

<!-- Core CSS (standard order) -->
<link rel="stylesheet" href="<?php echo htmlspecialchars($assetPrefix . 'assets/css/styles.css'); ?>">
<?php
    $cnxCompanyFilterCssV = (string)((@filemtime(__DIR__ . '/../assets/css/company_filter.css') ?: time()) . '-' . (@filesize(__DIR__ . '/../assets/css/company_filter.css') ?: 0));
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($assetPrefix . 'assets/css/company_filter.css?v=' . $cnxCompanyFilterCssV); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($assetPrefix . 'assets/css/sidebar-responsive.css'); ?>">

<!-- Page meta (optional) -->
<?php foreach ($pageMeta as $metaHtml): ?>
    <?php echo $metaHtml . "\n"; ?>
<?php endforeach; ?>

<!-- Page CSS (optional) -->
<?php foreach ($pageCss as $href): ?>
    <?php
        $hrefStr = (string)$href;
        $isAbs = (strpos($hrefStr, 'http://') === 0) || (strpos($hrefStr, 'https://') === 0) || (strpos($hrefStr, '/') === 0);
        $finalHref = $isAbs ? $hrefStr : ($assetPrefix . ltrim($hrefStr, '/'));
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($finalHref); ?>">
<?php endforeach; ?>

<!-- Core JS -->
<?php
    $cnxCompanyFilterJsV = (string)((@filemtime(__DIR__ . '/../assets/js/company_filter.js') ?: time()) . '-' . (@filesize(__DIR__ . '/../assets/js/company_filter.js') ?: 0));
?>
<script defer src="<?php echo htmlspecialchars($assetPrefix . 'assets/js/company_filter.js?v=' . $cnxCompanyFilterJsV); ?>"></script>

<!-- Page JS (optional) -->
<?php foreach ($pageScripts as $src): ?>
    <?php
        $srcStr = (string)$src;
        $isAbs = (strpos($srcStr, 'http://') === 0) || (strpos($srcStr, 'https://') === 0) || (strpos($srcStr, '/') === 0);
        $finalSrc = $isAbs ? $srcStr : ($assetPrefix . ltrim($srcStr, '/'));
    ?>
    <script defer src="<?php echo htmlspecialchars($finalSrc); ?>"></script>
<?php endforeach; ?>

