<?php
/**
 * Cache Helper - Works on Render with in-memory session-based caching
 * Falls back to file cache if available
 */
class CacheHelper {
    private static $memory_cache = [];
    private static $ttl_map = [];
    
    public static function get($key) {
        // Check memory cache first
        if (isset(self::$memory_cache[$key])) {
            $cache_entry = self::$memory_cache[$key];
            if (isset(self::$ttl_map[$key]) && (time() - $cache_entry['timestamp']) < self::$ttl_map[$key]) {
                return $cache_entry['data'];
            }
            unset(self::$memory_cache[$key]);
            unset(self::$ttl_map[$key]);
        }
        
        // Try file cache
        $filepath = self::getFilePath($key);
        if (file_exists($filepath)) {
            $content = @file_get_contents($filepath);
            if ($content) {
                $data = @json_decode($content, true);
                if ($data && isset($data['timestamp'], $data['ttl'])) {
                    if ((time() - $data['timestamp']) < $data['ttl']) {
                        self::$memory_cache[$key] = $data;
                        self::$ttl_map[$key] = $data['ttl'];
                        return $data['data'];
                    }
                }
                @unlink($filepath);
            }
        }
        
        return null;
    }
    
    public static function set($key, $data, $ttl = 300) {
        $cache_entry = [
            'timestamp' => time(),
            'ttl' => $ttl,
            'data' => $data
        ];
        
        // Store in memory
        self::$memory_cache[$key] = $cache_entry;
        self::$ttl_map[$key] = $ttl;
        
        // Try to persist to file
        $filepath = self::getFilePath($key);
        $dir = dirname($filepath);
        if (is_writable($dir)) {
            @file_put_contents($filepath, json_encode($cache_entry));
        }
    }
    
    public static function clear($key) {
        unset(self::$memory_cache[$key]);
        unset(self::$ttl_map[$key]);
        
        $filepath = self::getFilePath($key);
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
    }
    
    private static function getFilePath($key) {
        $cache_dir = sys_get_temp_dir();
        return $cache_dir . DIRECTORY_SEPARATOR . 'pct_' . $key . '_cache.json';
    }
}
?>
