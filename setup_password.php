<?php
// Check if requesting XAMPP dashboard
if (strpos($_SERVER['REQUEST_URI'], '/dashboard') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/phpmyadmin') !== false || 
    strpos($_SERVER['REQUEST_URI'], '/xampp') !== false) {
    // Let XAMPP handle it
    if (!empty($_SERVER['HTTPS']) && ('on' == $_SERVER['HTTPS'])) {
        $uri = 'https://';
    } else {
        $uri = 'http://';
    }
    $uri .= $_SERVER['HTTP_HOST'];
    header('Location: '.$uri.'/dashboard/');
    exit;
} else {
    // Redirect to CollaboraNexio for everything else
    header("Location: /CollaboraNexio/");
    exit();
}
?>