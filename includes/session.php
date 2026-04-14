<?php
// Configure session settings before starting
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_path', '/');

require_once __DIR__ . '/../config/app.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    debug_to_log("=== NEW SESSION STARTED ===");
    debug_to_log("Session ID: " . session_id());
}

// Debug function
function debug_to_log($message) {
    error_log("[DEBUG] " . $message);
}

// Function to check if user is logged in
function is_logged_in() {
    debug_to_log("=== CHECKING LOGIN STATUS ===");
    debug_to_log("Session ID: " . session_id());
    debug_to_log("Session contents: " . print_r($_SESSION, true));
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Function to check if user has specific role
function has_role($role) {
    debug_to_log("=== CHECKING ROLE ===");
    debug_to_log("Checking for role: " . $role);
    debug_to_log("Session contents: " . print_r($_SESSION, true));
    return is_logged_in() && isset($_SESSION['role']) && normalize_role($_SESSION['role']) === normalize_role($role);
}

function normalize_role($role) {
    $role = strtolower(trim((string) $role));

    // Legacy compatibility:
    // In parts of this codebase, the registrar portal is referred to as 'admin'.
    // Normalize both 'admin' and 'registrar' to a single role value.
    if ($role === 'admin' || $role === 'registrar') {
        return 'registrar';
    }

    return $role;
}

// Function to get current user's role
function get_user_role() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

// Function to get current user's ID
function get_user_id() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

// Function to get current user's name
function get_user_name() {
    if (isset($_SESSION['full_name']) && trim((string)$_SESSION['full_name']) !== '') {
        return $_SESSION['full_name'];
    }

    return isset($_SESSION['first_name']) && isset($_SESSION['last_name']) 
        ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] 
        : null;
}

// Function to check session timeout
function check_session_timeout() {
    $timeout = 30 * 60; // 30 minutes
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        clear_session();
        header('Location: ' . app_url('auth/login.php'));
        exit();
    }
    $_SESSION['last_activity'] = time();
}

// Function to redirect based on role
function redirect_by_role() {
    $role = get_user_role();
    switch($role) {
        case 'super_admin':
            header('Location: ' . app_url('admin/dashboard.php'));
            break;
        case 'instructor':
            header('Location: ' . app_url('instructor/dashboard.php'));
            break;
        case 'admin':
        case 'registrar':
            header('Location: ' . app_url('registrar/dashboard.php'));
            break;
        case 'student':
            header('Location: ' . app_url('student/dashboard.php'));
            break;
        default:
            header('Location: ' . app_url('index.php'));
    }
    exit();
}

// Function to require specific role
function require_role($role) {
    debug_to_log("=== REQUIRING ROLE ===");
    debug_to_log("Required role: " . $role);
    debug_to_log("Current session: " . print_r($_SESSION, true));
    
    if (!is_logged_in()) {
        debug_to_log("Not logged in, redirecting to login");
        header('Location: ' . app_url('auth/login.php') . '?role=' . urlencode($role));
        exit();
    }
    
    if (!has_role($role)) {
        debug_to_log("Wrong role, clearing session and redirecting");
        clear_session();
        header('Location: ' . app_url('auth/login.php') . '?role=' . urlencode($role));
        exit();
    }
    
    debug_to_log("Role check passed");
}

// Function to clear session
function clear_session() {
    debug_to_log("=== CLEARING SESSION ===");
    debug_to_log("Session ID before clearing: " . session_id());
    debug_to_log("Session contents before clearing: " . print_r($_SESSION, true));
    
    $_SESSION = array();
    session_destroy();
    
    debug_to_log("Session cleared");
}

// Function to set session variables
function set_session_vars($user) {
    debug_to_log("=== SETTING SESSION VARIABLES ===");
    debug_to_log("User data: " . print_r($user, true));
    debug_to_log("Current session ID: " . session_id());
    
    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['full_name'] = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
    $_SESSION['email'] = $user['email'];
    
    debug_to_log("Session variables set");
    debug_to_log("New session contents: " . print_r($_SESSION, true));
}

// Initialize session timeout check
if (is_logged_in()) {
    check_session_timeout();
}
?> 