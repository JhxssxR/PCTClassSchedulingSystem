<?php
session_start();
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and is an instructor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php?role=instructor');
    exit();
}

// Redirect to dashboard
header('Location: dashboard.php');
exit(); 