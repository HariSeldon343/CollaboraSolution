<?php
/**
 * Shared layout start include.
 *
 * Usage (right after </head>):
 *   $bodyAttributes = 'data-foo=\"bar\"'; // optional, already escaped
 *   require __DIR__ . '/includes/layout_start.php';
 */
declare(strict_types=1);

$bodyAttributes = isset($bodyAttributes) ? (string)$bodyAttributes : '';
$bodyAttrText = $bodyAttributes !== '' ? (' ' . $bodyAttributes) : '';
?>
<body<?php echo $bodyAttrText; ?>>
<div class="main-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content" id="main-content">

