<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'class_scheduling');

// Create initial connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Create database if not exists
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating database: " . $conn->error);
    }

    // Select the database
    $conn->select_db(DB_NAME);

    // Set charset
    $conn->set_charset("utf8mb4");

    // Create users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        role ENUM('super_admin', 'admin', 'instructor', 'student') NOT NULL,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";

    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating users table: " . $conn->error);
    }

    // Create courses table
    $sql = "CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(20) UNIQUE NOT NULL,
        course_name VARCHAR(100) NOT NULL,
        description TEXT,
        credits INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";

    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating courses table: " . $conn->error);
    }

    // Create classrooms table
    $sql = "CREATE TABLE IF NOT EXISTS classrooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_number VARCHAR(20) UNIQUE NOT NULL,
        capacity INT NOT NULL,
        building VARCHAR(50) NOT NULL,
        room_type ENUM('lecture', 'laboratory', 'conference') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";

    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating classrooms table: " . $conn->error);
    }

    // Create schedules table
    $sql = "CREATE TABLE IF NOT EXISTS schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        instructor_id INT NOT NULL,
        classroom_id INT NOT NULL,
        day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        semester VARCHAR(20) NOT NULL,
        academic_year VARCHAR(9) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        max_students INT DEFAULT 30,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id),
        FOREIGN KEY (instructor_id) REFERENCES users(id),
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id)
    )";

    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating schedules table: " . $conn->error);
    }

    // Create enrollments table
    $sql = "CREATE TABLE IF NOT EXISTS enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        schedule_id INT NOT NULL,
        enrollment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        FOREIGN KEY (student_id) REFERENCES users(id),
        FOREIGN KEY (schedule_id) REFERENCES schedules(id),
        UNIQUE KEY unique_enrollment (student_id, schedule_id)
    )";

    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating enrollments table: " . $conn->error);
    }

    // Create schedule_templates table
    $sql = "CREATE TABLE IF NOT EXISTS schedule_templates (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        course_id INT NOT NULL,
        instructor_id INT NOT NULL,
        classroom_id INT NOT NULL,
        day_of_week VARCHAR(20) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (course_id) REFERENCES courses(id),
        FOREIGN KEY (instructor_id) REFERENCES users(id),
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )";

    if ($conn->query($sql) === FALSE) {
        throw new Exception("Error creating schedule_templates table: " . $conn->error);
    }

    echo "Database and tables created successfully!";

} catch (Exception $e) {
    die("Setup failed: " . $e->getMessage());
}

// Close the connection
if (isset($conn)) {
    $conn->close();
} 