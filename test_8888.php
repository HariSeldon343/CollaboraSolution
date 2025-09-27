<?php
echo "<h1>PHP Works on Port 8888!</h1>";
echo "<p>Server Port: " . $_SERVER['SERVER_PORT'] . "</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Apache is running successfully on port 8888</p>";
phpinfo();
?>