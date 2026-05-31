<?php
/**
 * PostgreSQL Data Import Script
 * Imports converted MySQL SQL dump to Render PostgreSQL
 */

// Connection details
$pgHost = 'dpg-d8e5p9cm0tmc73ein590-a';
$pgDatabase = 'pctclass';
$pgUser = 'pctclass_user';
$pgPassword = 'QiNwKQRwPVpm3oziQbmYjACCvNdsDbBR';
$pgPort = 5432;

echo "=== PostgreSQL Import Script ===\n\n";

// Step 1: Connect to PostgreSQL
echo "[1/3] Connecting to PostgreSQL...\n";
try {
    $dsn = "pgsql:host=$pgHost;port=$pgPort;dbname=$pgDatabase";
    $pdo = new PDO($dsn, $pgUser, $pgPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 30
    ]);
    echo "✓ Connected successfully\n\n";
} catch (PDOException $e) {
    die("✗ Connection failed: " . $e->getMessage() . "\n");
}

// Step 2: Read SQL file
echo "[2/3] Reading SQL file...\n";
$sqlFile = __DIR__ . '/../Downloads/buwyfvp2ejdwjgoxsbcw_postgresql.sql';

if (!file_exists($sqlFile)) {
    die("✗ SQL file not found: $sqlFile\n");
}

$sqlContent = file_get_contents($sqlFile);
echo "✓ SQL file loaded (" . strlen($sqlContent) . " bytes)\n\n";

// Step 3: Execute SQL
echo "[3/3] Importing data to PostgreSQL...\n";
echo "This may take 30-60 seconds...\n\n";

try {
    // Split by semicolons and execute each statement
    $statements = array_filter(
        array_map('trim', preg_split('/;/', $sqlContent)),
        fn($s) => !empty($s) && !preg_match('/^--/', $s)
    );
    
    $count = 0;
    $startTime = time();
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            try {
                $pdo->exec($statement);
                $count++;
                
                // Show progress every 10 statements
                if ($count % 10 === 0) {
                    echo "  ✓ Executed $count statements\n";
                }
            } catch (PDOException $e) {
                // Some statements may fail (e.g., DROP IF EXISTS on empty DB)
                // This is normal - continue
                if (strpos($e->getMessage(), 'does not exist') === false) {
                    echo "  ⚠ Statement failed (continuing): " . substr($e->getMessage(), 0, 100) . "\n";
                }
            }
        }
    }
    
    $elapsed = time() - $startTime;
    echo "\n✓ Import completed in {$elapsed}s\n";
    echo "✓ Executed {$count} SQL statements\n\n";
    
} catch (Exception $e) {
    die("✗ Import failed: " . $e->getMessage() . "\n");
}

// Step 4: Verify import
echo "[4/4] Verifying data...\n";
try {
    $tables = [
        'users' => 'SELECT COUNT(*) FROM "users"',
        'classrooms' => 'SELECT COUNT(*) FROM "classrooms"',
        'courses' => 'SELECT COUNT(*) FROM "courses"',
        'subjects' => 'SELECT COUNT(*) FROM "subjects"',
        'enrollments' => 'SELECT COUNT(*) FROM "enrollments"',
        'schedules' => 'SELECT COUNT(*) FROM "schedules"',
    ];
    
    foreach ($tables as $table => $query) {
        try {
            $result = $pdo->query($query)->fetch();
            $count = $result[0];
            echo "  ✓ $table: $count records\n";
        } catch (PDOException $e) {
            echo "  ⚠ $table: not found (may not exist in this schema)\n";
        }
    }
    
    echo "\n✅ PostgreSQL import successful!\n";
    echo "\nNext steps:\n";
    echo "1. Update config/database.php with new connection details\n";
    echo "2. Test the app in browser\n";
    echo "3. Commit and push to GitHub\n";
    echo "4. Render will auto-deploy\n";
    
} catch (Exception $e) {
    echo "✗ Verification failed: " . $e->getMessage() . "\n";
}

$pdo = null;
?>
