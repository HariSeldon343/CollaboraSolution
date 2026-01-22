<?php
/**
 * Legacy entrypoint kept for backward compatibility.
 *
 * NOTE: The previous version of this file was unsafe because it forged session data.
 * Use the secured tool below (Super Admin only):
 *   /CollaboraNexio/tools/force_clear_opcache.php
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

header('Location: tools/force_clear_opcache.php', true, 302);
exit;