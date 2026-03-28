<?php
// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://cdn.jsdelivr.net; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data:;');

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 3600);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Function to sanitize input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Function to validate email
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to validate URL
function validate_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL);
}

// Function to generate CSRF token
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Function to check if request is AJAX
function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

// Function to prevent XSS
function prevent_xss($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = prevent_xss($value);
        }
    } else {
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}

// Function to validate file upload
function validate_file_upload($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'], $max_size = 5242880) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return false;
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return false;
        case UPLOAD_ERR_PARTIAL:
            return false;
        case UPLOAD_ERR_NO_FILE:
            return false;
        case UPLOAD_ERR_NO_TMP_DIR:
            return false;
        case UPLOAD_ERR_CANT_WRITE:
            return false;
        case UPLOAD_ERR_EXTENSION:
            return false;
        default:
            return false;
    }

    if ($file['size'] > $max_size) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $file_type = $finfo->file($file['tmp_name']);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_types)) {
        return false;
    }

    return true;
}

// Function to secure password
function secure_password($password) {
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
}

// Function to verify password
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Function to generate random token
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

// Function to check if user is authenticated
function is_authenticated() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

// Function to check if user has permission
function has_permission($required_role) {
    return is_authenticated() && $_SESSION['role'] === $required_role;
}

// Function to log security events
function log_security_event($event, $user_id = null, $details = []) {
    $log_file = __DIR__ . '/../logs/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $user = $user_id ?? ($_SESSION['user_id'] ?? 'anonymous');
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    $log_entry = sprintf(
        "[%s] Event: %s | User: %s | IP: %s | User-Agent: %s | Details: %s\n",
        $timestamp,
        $event,
        $user,
        $ip,
        $user_agent,
        json_encode($details)
    );
    
    error_log($log_entry, 3, $log_file);
} 