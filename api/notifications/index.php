<?php
// Forward to main notifications handler
$_SERVER['REQUEST_URI'] = str_replace('/notifications/index.php', '/notifications.php', $_SERVER['REQUEST_URI']);
require_once dirname(__DIR__) . '/notifications.php';