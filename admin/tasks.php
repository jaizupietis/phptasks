<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// Redirect to manager tasks page (since it's more complete)
header('Location: ../manager/tasks.php');
exit;
?>
