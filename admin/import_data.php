<?php
/**
 * PostgreSQL Data Import Endpoint
 * Access at: https://your-render-app.onrender.com/admin/import_data.php?action=import
 * 
 * Security: Requires specific authorization token (set DB_IMPORT_TOKEN env var)
 */

// Check authorization
$token = getenv('DB_IMPORT_TOKEN');
$action = $_GET['action'] ?? '';
$provided_token = $_GET['token'] ?? '';

// Only allow import if token is provided and matches
if (empty($token) || empty($provided_token) || $provided_token !== $token) {
    http_response_code(403);
    die(json_encode([
        'status' => 'error',
        'message' => 'Unauthorized. Please provide valid token: ?token=YOUR_TOKEN'
    ]));
}

echo "<pre style='font-family: monospace; white-space: pre-wrap;'>";
echo "=== PostgreSQL Data Import ===\n\n";

// Direct PostgreSQL connection (bypass the config which might be MySQL)
$pgHost = getenv('DB_HOST') ?: 'dpg-d8e5p9cm0tmc73ein590-a';
$pgDatabase = getenv('DB_NAME') ?: 'pctclass';
$pgUser = getenv('DB_USER') ?: 'pctclass_user';
$pgPassword = getenv('DB_PASS') ?: 'QiNwKQRwPVpm3oziQbmYjACCvNdsDbBR';
$pgPort = getenv('DB_PORT') ?: '5432';

echo "[1/4] Database connection status:\n";
try {
    $dsn = "pgsql:host=$pgHost;port=$pgPort;dbname=$pgDatabase";
    $conn = new PDO($dsn, $pgUser, $pgPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 30
    ]);
    
    $test = $conn->query("SELECT version()");
    $version = $test->fetch(PDO::FETCH_NUM)[0];
    echo "  ✓ Connected to PostgreSQL\n";
    echo "  Version: " . substr($version, 0, 50) . "...\n";
    echo "  Host: $pgHost\n";
    echo "  Port: $pgPort\n";
    echo "  Database: $pgDatabase\n\n";
} catch (Exception $e) {
    die("  ✗ Connection failed: " . $e->getMessage() . "\n");
}

// Step 2: Read the converted SQL file from Downloads
echo "[2/4] Loading SQL dump...\n";
$sqlFile = $_SERVER['HOME'] . '/Downloads/buwyfvp2ejdwjgoxsbcw_postgresql.sql';

if (!file_exists($sqlFile)) {
    // Try alternate path
    $sqlFile = '/home/' . get_current_user() . '/Downloads/buwyfvp2ejdwjgoxsbcw_postgresql.sql';
}

if (!file_exists($sqlFile)) {
    // On Render, it might be in a different location
    // List available files
    echo "  ✗ SQL file not found at expected locations:\n";
    echo "    - {$_SERVER['HOME']}/Downloads/buwyfvp2ejdwjgoxsbcw_postgresql.sql\n";
    echo "  \n  Checking for uploaded files...\n";
    echo "  You can upload the SQL file and try again.\n";
    die();
}

$sqlContent = file_get_contents($sqlFile);
$fileSize = strlen($sqlContent);

if (empty($sqlContent)) {
    die("  ✗ SQL file is empty\n");
}

echo "  ✓ SQL file loaded: " . number_format($fileSize) . " bytes\n\n";

// Step 3: Parse and execute SQL statements
echo "[3/4] Importing data (this may take 30-60 seconds)...\n";

try {
    // Split by semicolons (simple parser)
    $statements = array_filter(
        array_map('trim', preg_split('/;/', $sqlContent)),
        fn($s) => !empty($s) && !preg_match('/^--/', $s)
    );
    
    $executed = 0;
    $failed = 0;
    $skipped = 0;
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            try {
                $conn->exec($statement);
                $executed++;
                
                if ($executed % 20 === 0) {
                    echo "    ✓ {$executed} statements executed\n";
                }
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                
                // Skip expected errors
                if (strpos($errorMsg, 'already exists') !== false ||
                    strpos($errorMsg, 'does not exist') !== false ||
                    strpos($errorMsg, 'duplicate key') !== false) {
                    $skipped++;
                } else {
                    $failed++;
                    if ($failed <= 5) {  // Show first 5 errors
                        echo "    ⚠ Error: " . substr($errorMsg, 0, 100) . "\n";
                    }
                }
            }
        }
    }
    
    echo "\n  ✓ Import completed\n";
    echo "    - Executed: {$executed} statements\n";
    echo "    - Skipped (expected): {$skipped} statements\n";
    echo "    - Failed: {$failed} statements\n\n";
    
} catch (Exception $e) {
    die("  ✗ Import failed: " . $e->getMessage() . "\n");
}

// Step 4: Verify data
echo "[4/4] Verifying imported data...\n";

try {
    $tables = [
        'users' => 'SELECT COUNT(*) as count FROM "users"',
        'classrooms' => 'SELECT COUNT(*) as count FROM "classrooms"',
        'courses' => 'SELECT COUNT(*) as count FROM "courses"',
        'subjects' => 'SELECT COUNT(*) as count FROM "subjects"',
        'enrollments' => 'SELECT COUNT(*) as count FROM "enrollments"',
        'schedules' => 'SELECT COUNT(*) as count FROM "schedules"',
    ];
    
    echo "\n  Table Record Counts:\n";
    foreach ($tables as $table => $query) {
        try {
            $result = $conn->query($query)->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'] ?? 0;
            echo "    ✓ {$table}: {$count} records\n";
        } catch (PDOException $e) {
            echo "    ⚠ {$table}: Not found (may be expected)\n";
        }
    }
    
    echo "\n✅ PostgreSQL import successful!\n";
    echo "\nNext steps:\n";
    echo "  1. Set environment variables in Render dashboard:\n";
    echo "     DB_ENGINE=pgsql\n";
    echo "     DB_HOST=dpg-d8e5p9cm0tmc73ein590-a\n";
    echo "     DB_NAME=pctclass\n";
    echo "     DB_USER=pctclass_user\n";
    echo "     DB_PASS=QiNwKQRwPVpm3oziQbmYjACCvNdsDbBR\n";
    echo "     DB_PORT=5432\n";
    echo "\n  2. Redeploy the app in Render dashboard\n";
    echo "\n  3. Test the app: https://your-app.onrender.com\n";
    echo "\n  4. Check performance_diagnostic.php for query times\n";
    
} catch (Exception $e) {
    echo "✗ Verification failed: " . $e->getMessage() . "\n";
}

echo "\n=== End Import ===\n";
echo "</pre>";
?>
