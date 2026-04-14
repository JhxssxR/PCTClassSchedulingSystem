<?php
// Centralized app URL helpers.
// This project is sometimes hosted under /caps, or under its folder name (e.g. /PCTClassSchedulingSystem).

if (!defined('APP_BASE')) {
    /**
     * Detect the application base path from the current request.
     * Examples:
     * - /PCTClassSchedulingSystem/auth/login.php -> /PCTClassSchedulingSystem
     * - /caps/registrar/dashboard.php -> /caps
     */
    function detect_app_base_path(): string {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName === '') {
            return '';
        }

        $markers = ['/auth/', '/admin/', '/registrar/', '/instructor/', '/student/', '/config/', '/includes/'];
        foreach ($markers as $marker) {
            $pos = stripos($scriptName, $marker);
            if ($pos !== false) {
                return rtrim(substr($scriptName, 0, $pos), '/');
            }
        }

        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        return $dir === '/' ? '' : $dir;
    }

    define('APP_BASE', detect_app_base_path());
}

if (date_default_timezone_get() !== 'Asia/Manila') {
    date_default_timezone_set('Asia/Manila');
}

/**
 * Build an absolute app URL (path-only) under the detected base.
 * Example: app_url('auth/login.php') -> /PCTClassSchedulingSystem/auth/login.php
 */
function app_url(string $path = ''): string {
    $path = '/' . ltrim($path, '/');
    return (APP_BASE === '' ? '' : APP_BASE) . $path;
}
