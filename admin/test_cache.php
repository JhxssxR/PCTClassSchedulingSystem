<?php
// Quick diagnostic to check if file-based caching is working on Render
$cache_dir = sys_get_temp_dir();
$test_file = $cache_dir . '/pct_cache_test_' . time() . '.txt';
$test_data = json_encode(['test' => true, 'timestamp' => time()]);

echo "Cache Directory: " . $cache_dir . "\n";
echo "Is Writable: " . (is_writable($cache_dir) ? "YES" : "NO") . "\n";
echo "Attempting to write test file...\n";

if (@file_put_contents($test_file, $test_data)) {
    echo "✓ Write successful\n";
    
    $read_data = @file_get_contents($test_file);
    if ($read_data === $test_data) {
        echo "✓ Read successful\n";
    } else {
        echo "✗ Read mismatch\n";
    }
    
    @unlink($test_file);
    echo "✓ File caching should work\n";
} else {
    echo "✗ Write failed - file-based caching won't work on this system\n";
    echo "Need to use in-memory caching or alternative approach\n";
}
?>
