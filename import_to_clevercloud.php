<?php
/**
 * Import Script for CleverCloud Database
 * This script safely imports the SQL dump into CleverCloud with foreign key checks disabled
 */

// Get database credentials from environment
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'class_scheduling';

echo "Connecting to database: $host / $database\n";
echo "User: $user\n";

// Connect to database
try {
    $conn = new mysqli($host, $user, $password, $database);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error . "\n");
    }
    
    echo "✓ Connected successfully\n\n";
    
    // Read SQL dump file
    $sql_file = __DIR__ . '/sql/class_scheduling (2).sql';
    
    if (!file_exists($sql_file)) {
        die("SQL file not found: $sql_file\n");
    }
    
    echo "Reading SQL file: $sql_file\n";
    $sql_content = file_get_contents($sql_file);
    
    // Split SQL statements by semicolon
    // Handle multi-line statements properly
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql_content)));
    
    echo "Found " . count($statements) . " SQL statements\n\n";
    
    // Disable foreign key checks
    echo "Disabling foreign key checks...\n";
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    
    $success = 0;
    $failed = 0;
    $skipped = 0;
    
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        
        // Skip comments and empty statements
        if (empty($statement) || substr($statement, 0, 2) === '--' || substr($statement, 0, 1) === '#') {
            $skipped++;
            continue;
        }
        
        // Add back the semicolon if it was removed
        if (substr($statement, -1) !== ';') {
            $statement .= ';';
        }
        
        // Extract statement type for logging
        $stmt_type = strtoupper(substr(trim($statement), 0, 6));
        
        if ($conn->query($statement)) {
            $success++;
            // Show progress for CREATE and INSERT statements
            if (in_array($stmt_type, ['CREATE', 'INSERT', 'TRUNCA'])) {
                echo "✓ Statement " . ($index + 1) . ": " . $stmt_type . "\n";
            }
        } else {
            $failed++;
            // Show errors for CREATE and INSERT statements
            if (in_array($stmt_type, ['CREATE', 'INSERT', 'TRUNCA'])) {
                echo "✗ Statement " . ($index + 1) . " FAILED: " . $conn->error . "\n";
                echo "   SQL: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    // Re-enable foreign key checks
    echo "\n\nRe-enabling foreign key checks...\n";
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    
    // Verify data
    echo "\n=== IMPORT SUMMARY ===\n";
    echo "✓ Successful statements: $success\n";
    echo "✗ Failed statements: $failed\n";
    echo "⊘ Skipped statements: $skipped\n";
    
    echo "\n=== TABLE ROW COUNTS ===\n";
    
    $tables = ['users', 'courses', 'subjects', 'classrooms', 'schedules', 'enrollments', 'notification_state'];
    
    foreach ($tables as $table) {
        $result = $conn->query("SELECT COUNT(*) as count FROM $table");
        if ($result) {
            $row = $result->fetch_assoc();
            $count = $row['count'];
            echo "$table: $count rows\n";
        } else {
            echo "$table: ERROR - " . $conn->error . "\n";
        }
    }
    
    $conn->close();
    echo "\n✓ Import complete!\n";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
?>
