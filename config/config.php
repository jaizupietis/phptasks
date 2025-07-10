<?php
date_default_timezone_set('Europe/Riga');
// Security check
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/tasks/logs/php_errors.log');

// Application configuration
define('APP_NAME', 'Task Management System');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://192.168.2.11/tasks');
define('WEB_ROOT', '/tasks');
define('ROOT_PATH', '/var/www/tasks');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('LOG_PATH', ROOT_PATH . '/logs');

// Security
define('SESSION_TIMEOUT', 3600);
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB

// Include database
require_once ROOT_PATH . '/config/database.php';

// Utility functions
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function isMobile() {
    return preg_match("/(android|webos|avantgo|iphone|ipad|ipod|blackberry|iemobile|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER['HTTP_USER_AGENT']);
}

function logActivity($message, $level = 'INFO', $user_id = null) {
    $log_file = LOG_PATH . '/app.log';
    $timestamp = date('Y-m-d H:i:s');
    $user_info = $user_id ? " [User: $user_id]" : '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $log_entry = "[$timestamp] [$level] [$ip]$user_info $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Auto-create directories
$required_dirs = [UPLOAD_PATH, LOG_PATH];
foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

logActivity("Application initialized", 'INFO');
// Timezone utility functions
function getCurrentAppTime($format = 'Y-m-d H:i:s') {
    return date($format);
}

function formatAppTime($timestamp, $format = 'M j, Y g:i A') {
    return date($format, strtotime($timestamp));
}

// Timezone utility functions
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time / 60) . 'm ago';
        if ($time < 86400) return floor($time / 3600) . 'h ago';
        return date('M j', strtotime($datetime));
    }
}

if (!function_exists('getCurrentAppTime')) {
    function getCurrentAppTime($format = 'Y-m-d H:i:s') {
        return date($format);
    }
}

if (!function_exists('formatAppTime')) {
    function formatAppTime($timestamp, $format = 'M j, Y g:i A') {
        return date($format, strtotime($timestamp));
    }
}
?>
