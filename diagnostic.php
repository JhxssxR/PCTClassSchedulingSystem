<?php
// Quick diagnostic page to check database connection
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Diagnostic</h1>";
echo "<pre>";

// Check environment variables
echo "=== Environment Variables ===\n";
echo "DB_HOST: " . (getenv('DB_HOST') ?: 'NOT SET') . "\n";
echo "DB_PORT: " . (getenv('DB_PORT') ?: 'NOT SET') . "\n";
echo "DB_NAME: " . (getenv('DB_NAME') ?: 'NOT SET') . "\n";
echo "DB_USER: " . (getenv('DB_USER') ?: 'NOT SET') . "\n";
echo "DB_PASS: " . (getenv('DB_PASS') ? '***HIDDEN***' : 'NOT SET') . "\n\n";

// Try connection
echo "=== Connection Attempt ===\n";
try {
    $dsn = "mysql:host=" . (getenv('DB_HOST') ?: 'localhost') . 
           ";port=" . (getenv('DB_PORT') ?: '3306') . 
           ";dbname=" . (getenv('DB_NAME') ?: 'class_scheduling');
    
    echo "DSN: " . $dsn . "\n\n";
    
    $conn = new PDO(
        $dsn,
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );
    
    echo "✓ Connection successful!\n\n";
    
    // Test query
    $result = $conn->query("SELECT 1 as test");
    echo "✓ Query successful!\n\n";
    
    // Show databases
    echo "=== Available Databases ===\n";
    $dbs = $conn->query("SHOW DATABASES");
    foreach ($dbs as $db) {
        echo "- " . $db['Database'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "✗ Connection failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
}

echo "</pre>";
?>
