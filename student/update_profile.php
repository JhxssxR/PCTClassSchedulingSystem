<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/login.php?role=student');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_SESSION['user_id'];
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    try {
        // Split full name into first and last name
        $name_parts = explode(' ', $fullname, 2);
        if (count($name_parts) < 2) {
            throw new Exception("Please enter both first and last name");
        }
        $first_name = $name_parts[0];
        $last_name = $name_parts[1];

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }

        // Check if email is already used by another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $student_id]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Email is already in use by another user");
        }

        // Start building the update query
        $updates = ["first_name = ?, last_name = ?, email = ?"];
        $params = [$first_name, $last_name, $email];

        // Handle password update if provided
        if (!empty($new_password)) {
            if (strlen($new_password) < 8) {
                throw new Exception("Password must be at least 8 characters long");
            }
            if (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $new_password)) {
                throw new Exception("Password must contain at least one capital letter and one number");
            }
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            $updates[] = "password = ?";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        // Add user ID to parameters
        $params[] = $student_id;

        // Update user profile
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $_SESSION['success_message'] = "Profile updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Redirect back to dashboard
header('Location: dashboard.php');
exit(); 