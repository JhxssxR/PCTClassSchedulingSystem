<?php
require_once 'config/database.php';

try {
    // Check if instructor already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'instructor'");
    $stmt->execute();
    if ($stmt->fetch()) {
        echo "Instructor account already exists.";
        exit();
    }

    // Create instructor account
    $password = password_hash('instructor123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        INSERT INTO users (username, password, email, role, first_name, last_name)
        VALUES ('instructor', :password, 'instructor@pct.edu', 'instructor', 'John', 'Doe')
    ");
    $stmt->execute(['password' => $password]);
    
    echo "Instructor account created successfully.<br>";
    echo "Username: instructor<br>";
    echo "Password: instructor123";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 