<?php
require 'config/database.php';
try {
    // Modify ENUM column first
    $conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'registrar', 'instructor', 'student') NOT NULL");
    
    // Update admin user to super_admin
    $stmt = $conn->prepare("UPDATE users SET role = 'super_admin' WHERE username = 'admin'");
    $stmt->execute();
    
    // Update registrar user to admin
    $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE username = 'registrar'");
    $stmt->execute();
    
    echo "SUCCESS: Updated 'admin' user to 'super_admin' role, and 'registrar' user to 'admin' role.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
