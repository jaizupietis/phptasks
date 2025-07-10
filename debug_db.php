<?php
/**
 * Database Debug Script
 * Create this as: /var/www/tasks/debug_db.php
 * Access via: http://192.168.2.11/tasks/debug_db.php
 */

define('SECURE_ACCESS', true);
require_once 'config/config.php';

// Check if we have a session
if (!isset($_SESSION['user_id'])) {
    echo "<h1>Debug - No Session</h1>";
    echo "<p>Please <a href='index.php'>login first</a></p>";
    exit;
}

echo "<h1>Task Management System - Database Debug</h1>";
echo "<p>User ID: " . $_SESSION['user_id'] . "</p>";
echo "<p>Role: " . $_SESSION['role'] . "</p>";

try {
    $db = Database::getInstance();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Test 1: Check database tables
    echo "<h2>Database Tables:</h2>";
    $tables = $db->fetchAll("SHOW TABLES");
    foreach ($tables as $table) {
        $table_name = array_values($table)[0];
        echo "<p>• {$table_name}</p>";
    }
    
    // Test 2: Check users table
    echo "<h2>Users Table:</h2>";
    $users = $db->fetchAll("SELECT id, username, first_name, last_name, role FROM users");
    foreach ($users as $user) {
        echo "<p>ID: {$user['id']}, Username: {$user['username']}, Name: {$user['first_name']} {$user['last_name']}, Role: {$user['role']}</p>";
    }
    
    // Test 3: Check tasks table structure
    echo "<h2>Tasks Table Structure:</h2>";
    $structure = $db->fetchAll("DESCRIBE tasks");
    foreach ($structure as $field) {
        echo "<p>Field: {$field['Field']}, Type: {$field['Type']}, Null: {$field['Null']}, Default: {$field['Default']}</p>";
    }
    
    // Test 4: Check existing tasks
    echo "<h2>Existing Tasks:</h2>";
    $tasks = $db->fetchAll("SELECT id, title, status, assigned_to, created_at FROM tasks ORDER BY created_at DESC LIMIT 10");
    if (empty($tasks)) {
        echo "<p>No tasks found in database</p>";
    } else {
        foreach ($tasks as $task) {
            echo "<p>ID: {$task['id']}, Title: {$task['title']}, Status: {$task['status']}, Assigned: {$task['assigned_to']}, Created: {$task['created_at']}</p>";
        }
    }
    
    // Test 5: Try to create a test task
    echo "<h2>Test Task Creation:</h2>";
    if ($_POST['test_create'] ?? false) {
        try {
            $test_data = [
                'title' => 'Test Task - ' . date('Y-m-d H:i:s'),
                'description' => 'This is a test task created by debug script',
                'priority' => 'medium',
                'status' => 'pending',
                'assigned_to' => 3, // Assuming mechanic1 has ID 3
                'assigned_by' => $_SESSION['user_id'],
                'category' => 'Testing',
                'location' => 'Debug Location',
                'equipment' => 'Debug Equipment',
                'estimated_hours' => 2.5,
                'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')),
                'progress_percentage' => 0
            ];
            
            $fields = array_keys($test_data);
            $placeholders = array_fill(0, count($fields), '?');
            $values = array_values($test_data);
            
            $sql = "INSERT INTO tasks (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            echo "<p>SQL: {$sql}</p>";
            echo "<p>Values: " . json_encode($values) . "</p>";
            
            $stmt = $db->query($sql, $values);
            $new_id = $db->getConnection()->lastInsertId();
            
            if ($new_id) {
                echo "<p style='color: green;'>✓ Test task created successfully! ID: {$new_id}</p>";
            } else {
                echo "<p style='color: red;'>✗ Failed to get new task ID</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Error creating test task: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<form method='POST'>";
        echo "<button type='submit' name='test_create' value='1'>Create Test Task</button>";
        echo "</form>";
    }
    
    // Test 6: Check API endpoint
    echo "<h2>API Test:</h2>";
    echo "<p>API URL: <a href='api/tasks.php?action=test' target='_blank'>api/tasks.php?action=test</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database error: " . $e->getMessage() . "</p>";
}
?>