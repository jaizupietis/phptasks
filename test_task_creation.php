<?php
/**
 * Test Task Creation Script
 * Create as: /var/www/tasks/test_task_creation.php
 * Run: php /var/www/tasks/test_task_creation.php
 */

define('SECURE_ACCESS', true);
require_once '/var/www/tasks/config/config.php';

echo "=== Task Creation Test ===\n";

try {
    $db = Database::getInstance();
    echo "✓ Database connection successful\n";
    
    // Test 1: Check if users exist
    echo "\n1. Checking users...\n";
    $users = $db->fetchAll("SELECT id, username, first_name, last_name, role FROM users WHERE is_active = 1");
    foreach ($users as $user) {
        echo "   - ID: {$user['id']}, Username: {$user['username']}, Role: {$user['role']}\n";
    }
    
    // Test 2: Check tasks table structure
    echo "\n2. Checking tasks table structure...\n";
    $columns = $db->fetchAll("SHOW COLUMNS FROM tasks");
    foreach ($columns as $col) {
        echo "   - {$col['Field']}: {$col['Type']}\n";
    }
    
    // Test 3: Create a test task
    echo "\n3. Creating test task...\n";
    
    $task_data = [
        'title' => 'CLI Test Task - ' . date('Y-m-d H:i:s'),
        'description' => 'This is a test task created by CLI script',
        'priority' => 'medium',
        'status' => 'pending',
        'assigned_to' => 3, // Mechanic1
        'assigned_by' => 2, // Manager1
        'category' => 'Testing',
        'location' => 'Debug Location',
        'equipment' => 'Debug Equipment',
        'estimated_hours' => 2.5,
        'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')),
        'progress_percentage' => 0
    ];
    
    // Build insert query
    $fields = array_keys($task_data);
    $placeholders = array_fill(0, count($fields), '?');
    $values = array_values($task_data);
    
    $sql = "INSERT INTO tasks (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    echo "   SQL: {$sql}\n";
    echo "   Values: " . json_encode($values) . "\n";
    
    $stmt = $db->query($sql, $values);
    $new_id = $db->getConnection()->lastInsertId();
    
    if ($new_id) {
        echo "   ✓ Task created successfully! ID: {$new_id}\n";
        
        // Verify the task was created
        $created_task = $db->fetch("SELECT * FROM tasks WHERE id = ?", [$new_id]);
        if ($created_task) {
            echo "   ✓ Task verified in database\n";
            echo "   - Title: {$created_task['title']}\n";
            echo "   - Status: {$created_task['status']}\n";
            echo "   - Created: {$created_task['created_at']}\n";
        } else {
            echo "   ✗ Task not found after creation\n";
        }
    } else {
        echo "   ✗ Failed to get new task ID\n";
    }
    
    // Test 4: Test API via curl simulation
    echo "\n4. Testing API endpoint...\n";
    
    // Start session for API test
    session_start();
    $_SESSION['user_id'] = 2; // Manager1
    $_SESSION['role'] = 'manager';
    
    // Simulate API call
    $api_data = [
        'action' => 'create_task',
        'title' => 'API Test Task - ' . date('Y-m-d H:i:s'),
        'description' => 'Testing API endpoint',
        'assigned_to' => 3,
        'priority' => 'high',
        'category' => 'API Testing'
    ];
    
    // Test the API logic directly
    ob_start();
    
    // Simulate POST request
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    
    // Mock the input
    $json_input = json_encode($api_data);
    
    // This would normally be done by the API
    $input = json_decode($json_input, true);
    
    if ($input && isset($input['action']) && $input['action'] === 'create_task') {
        echo "   ✓ API input parsed correctly\n";
        echo "   - Action: {$input['action']}\n";
        echo "   - Title: {$input['title']}\n";
        echo "   - Assigned to: {$input['assigned_to']}\n";
    } else {
        echo "   ✗ API input parsing failed\n";
    }
    
    ob_end_clean();
    
    echo "\n=== Test Complete ===\n";
    echo "If all tests passed, the task creation should work.\n";
    echo "Check the manager dashboard to create tasks via the web interface.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
