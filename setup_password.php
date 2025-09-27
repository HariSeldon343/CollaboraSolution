<?php
   $pdo = new PDO("mysql:host=localhost;dbname=collabora;charset=utf8mb4", "root", "");
   
   $users = [
       'asamodeo@fortibyte.it' => 'Ricord@1991',
       'special@demo.com' => 'Special123!',
       'user@demo.com' => 'Demo123!'
   ];
   
   foreach ($users as $email => $password) {
       $hash = password_hash($password, PASSWORD_DEFAULT);
       $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
       $stmt->execute([$hash, $email]);
       echo "✓ $email configurato<br>";
   }
   ?>