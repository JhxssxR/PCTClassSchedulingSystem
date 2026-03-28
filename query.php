<?php
require 'config/database.php';
$stmt = $conn->query("SELECT id, username, email, role, first_name, last_name FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($users as $user) {
    echo "ID: {$user['id']} | Username: {$user['username']} | Role: {$user['role']}\n";
}
?>
