<?php
define('SECURE_ACCESS', true);
require_once 'config/config.php';

// Log the logout
if (isset($_SESSION['user_id'])) {
    logActivity("User logged out", 'INFO', $_SESSION['user_id']);
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: index.php?logged_out=1');
exit;
?>
