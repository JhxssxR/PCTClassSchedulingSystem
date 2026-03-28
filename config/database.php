<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/app.php';

// Set error log path
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Create logs directory if it doesn't exist
if (!file_exists(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0777, true);
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'class_scheduling');
define('DB_USER', 'root');
define('DB_PASS', '');

// Function to test database connection
function testDatabaseConnection() {
    global $conn;
    try {
        $conn->query("SELECT 1");
        return true;
    } catch (PDOException $e) {
        error_log("Database Connection Test Failed: " . $e->getMessage());
        return false;
    }
}

try {
    // Create PDO connection with error mode
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );

    // Test the connection
    if (!testDatabaseConnection()) {
        throw new PDOException("Database connection test failed");
    }
    
} catch(PDOException $e) {
    // Log the error
    error_log("Database Connection Error: " . $e->getMessage());
    
    // Show user-friendly error
    die("Connection failed: Please contact the administrator. Error code: " . $e->getCode());
}

// Function to safely close database connection
function closeConnection() {
    global $conn;
    if (isset($conn) && $conn instanceof PDO) {
        $conn = null;
    }
}

// Register shutdown function
register_shutdown_function('closeConnection');

// Create tables if they don't exist
$tables = [
    "users" => "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        role ENUM('super_admin', 'admin', 'registrar', 'instructor', 'student') NOT NULL,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    
    "courses" => "CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(20) UNIQUE NOT NULL,
        course_name VARCHAR(100) NOT NULL,
        description TEXT,
        credits INT NOT NULL DEFAULT 3,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",
    
    "classrooms" => "CREATE TABLE IF NOT EXISTS classrooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_number VARCHAR(20) UNIQUE NOT NULL,
        capacity INT NOT NULL DEFAULT 30,
        building VARCHAR(50) NOT NULL DEFAULT 'Main Building',
        room_type ENUM('lecture', 'laboratory', 'conference') NOT NULL DEFAULT 'lecture',
        status ENUM('active', 'maintenance', 'inactive') NOT NULL DEFAULT 'active',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",
    
    "schedules" => "CREATE TABLE IF NOT EXISTS schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        instructor_id INT NOT NULL,
        classroom_id INT NOT NULL,
        day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        max_students INT NOT NULL DEFAULT 30,
        semester VARCHAR(20) NOT NULL,
        academic_year VARCHAR(9) NOT NULL,
        status ENUM('active', 'cancelled', 'completed') NOT NULL DEFAULT 'active',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id),
        FOREIGN KEY (instructor_id) REFERENCES users(id),
        FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",
    
    "enrollments" => "CREATE TABLE IF NOT EXISTS enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        schedule_id INT NOT NULL,
        status ENUM('pending', 'approved', 'rejected', 'dropped') NOT NULL DEFAULT 'pending',
        grade DECIMAL(4,2),
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id),
        FOREIGN KEY (schedule_id) REFERENCES schedules(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    "settings" => "CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        `key` VARCHAR(100) NOT NULL,
        `value` TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_settings_key (`key`)
    )"

    ,
    "notification_state" => "CREATE TABLE IF NOT EXISTS notification_state (
        user_id INT PRIMARY KEY,
        notif_seen_at DATETIME NULL,
        notif_cleared_at DATETIME NULL,
        admin_notif_seen_at DATETIME NULL,
        admin_notif_cleared_at DATETIME NULL,
        registrar_notif_seen_at DATETIME NULL,
        registrar_notif_cleared_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )"
];

// Execute each table creation query
foreach ($tables as $table => $sql) {
    try {
        $conn->exec($sql);
    } catch (PDOException $e) {
        error_log("Error creating table $table: " . $e->getMessage());
    }
}

// Seed default settings (best-effort)
try {
    $conn->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES
        ('school_name', 'Philippine College of Technology'),
        ('school_short_name', 'PCT'),
        ('school_address', 'Davao City, Philippines'),
        ('contact_email', 'registrar@pct.edu.ph'),
        ('contact_phone', '+63 82 123 4567'),
        ('school_website', 'https://pct.edu.ph'),
        ('school_description', 'Philippine College of Technology is a leading institution in Davao City offering quality education in technology and engineering.'),
        ('max_enrollments', '6'),
        ('enrollment_approval', '1'),
        ('default_class_duration', '120'),
        ('break_time', '15'),
        ('email_notifications', '1'),
        ('notification_days', '1')
    ");
} catch (PDOException $e) {
    error_log('Settings seed warning: ' . $e->getMessage());
}

// Best-effort schema repair for existing settings tables.
// Ensures the unique key needed for ON DUPLICATE KEY UPDATE / INSERT IGNORE semantics.
try {
    $conn->exec("ALTER TABLE settings ADD UNIQUE KEY uniq_settings_key (`key`)");
} catch (PDOException $e) {
    // Ignore if it already exists or table doesn't allow alteration.
    error_log('Schema repair warning (settings.uniq_settings_key): ' . $e->getMessage());
}

// Best-effort schema repair for existing databases.
// CREATE TABLE IF NOT EXISTS does not update existing schemas.
try {
    $conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'registrar', 'instructor', 'student') NOT NULL DEFAULT 'student'");
} catch (PDOException $e) {
    // Ignore if permissions/schema prevent alteration.
    error_log("Schema repair warning (users.role): " . $e->getMessage());
}

// Repair any users with missing/blank roles.
try {
    $conn->exec("UPDATE users SET role = 'student' WHERE role IS NULL OR role = ''");
    $stmt = $conn->prepare("UPDATE users SET role = 'super_admin' WHERE username = 'admin'");
    $stmt->execute();
    $stmt = $conn->prepare("UPDATE users SET role = 'registrar' WHERE username = 'registrar'");
    $stmt->execute();
} catch (PDOException $e) {
    error_log("Role repair warning: " . $e->getMessage());
}

// Check if default users exist, if not create them
$default_users = [
    [
        'username' => 'admin',
        'password' => 'admin123',
        'email' => 'admin@pct.edu',
        'role' => 'super_admin',
        'first_name' => 'System',
        'last_name' => 'Administrator'
    ],
    [
        'username' => 'registrar',
        'password' => 'registrar123',
        'email' => 'registrar@pct.edu',
        'role' => 'registrar',
        'first_name' => 'Jane',
        'last_name' => 'Smith'
    ]
];

foreach ($default_users as $user) {
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$user['username']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $hashed_password = password_hash($user['password'], PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (username, password, email, role, first_name, last_name) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $user['username'],
            $hashed_password,
            $user['email'],
            $user['role'],
            $user['first_name'],
            $user['last_name']
        ]);
    } else {
        // Keep existing credentials, but ensure the role matches the expected default.
        if (($existing['role'] ?? null) !== $user['role']) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$user['role'], $existing['id']]);
        }
    }
} 