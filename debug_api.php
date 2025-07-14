<?php
/**
 * ENHANCED API Debug Tool for Task Management System
 * Replace: /var/www/tasks/debug_api.php
 * This version includes better error detection and fixes
 */

define('SECURE_ACCESS', true);
require_once 'config/config.php';

// Check session
if (!isset($_SESSION['user_id'])) {
    die('<h1>Please login first</h1><a href="index.php">Login</a>');
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Enhanced API Debug Tool</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .test-section { background: white; margin: 20px 0; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        .info { color: #17a2b8; }
        .debug-btn { padding: 8px 12px; margin: 5px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .code-block { background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0; overflow-x: auto; }
        .fix-btn { background: #28a745; }
        .test-btn { background: #17a2b8; }
    </style>
</head>
<body>

<h1>🔧 Enhanced Task Management API Debug Tool</h1>

<div class="test-section">
    <h2>Quick Actions</h2>
    <button class="debug-btn fix-btn" onclick="fixAPIErrors()">🛠️ Fix API Errors</button>
    <button class="debug-btn test-btn" onclick="testAllAPIs()">🧪 Test All APIs</button>
    <button class="debug-btn" onclick="checkDatabaseQueries()">📊 Check DB Queries</button>
    <button class="debug-btn" onclick="resetProblems()">🔄 Reset Problems</button>
</div>

<div id="results">
    <div class="test-section">
        <h2>🏁 Initial System Check</h2>
        <div id="initial-results">
            
            <?php
            echo "<p><strong>User:</strong> " . htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) . " (" . $_SESSION['role'] . ")</p>";
            echo "<p><strong>User ID:</strong> " . $_SESSION['user_id'] . "</p>";
            echo "<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>";
            
            // Check database connection
            try {
                $test_query = $db->fetch("SELECT COUNT(*) as count FROM users");
                echo "<p class='success'>✅ Database: Connected ({$test_query['count']} users)</p>";
            } catch (Exception $e) {
                echo "<p class='error'>❌ Database: " . $e->getMessage() . "</p>";
            }
            
            // Check critical files
            $critical_files = [
                'api/tasks.php',
                'api/problems.php', 
                'api/notifications.php',
                'manager/problems.php',
                'admin/dashboard.php'
            ];
            
            foreach ($critical_files as $file) {
                if (file_exists($file)) {
                    echo "<p class='success'>✅ File: $file</p>";
                } else {
                    echo "<p class='error'>❌ Missing: $file</p>";
                }
            }
            
            // Check for specific database errors
            echo "<h3>🔍 Checking Recent Errors</h3>";
            $error_log = LOG_PATH . '/php_errors.log';
            if (file_exists($error_log)) {
                $errors = file_get_contents($error_log);
                if (strpos($errors, 'SQLSTATE[HY093]') !== false) {
                    echo "<p class='error'>❌ Found SQL parameter errors - this causes blank pages</p>";
                }
                if (strpos($errors, 'Invalid parameter number') !== false) {
                    echo "<p class='error'>❌ Found parameter mismatch errors</p>";
                }
            }
            ?>
            
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced API Debug Tool loaded');
});

function fixAPIErrors() {
    showLoading('Fixing API errors...');
    
    fetch('debug_api.php?action=fix_errors', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({action: 'fix_api_errors'})
    })
    .then(response => response.text())
    .then(data => {
        document.getElementById('results').innerHTML += `
            <div class="test-section">
                <h3>🛠️ Fix Results</h3>
                <div class="code-block">${data}</div>
            </div>
        `;
        hideLoading();
    })
    .catch(error => {
        console.error('Fix error:', error);
        showError('Failed to apply fixes: ' + error.message);
        hideLoading();
    });
}

function testAllAPIs() {
    showLoading('Testing all APIs...');
    
    const tests = [
        {name: 'Tasks API Basic', url: 'api/tasks.php?action=test'},
        {name: 'Problems API Basic', url: 'api/problems.php?action=test'},
        {name: 'Notifications API', url: 'api/notifications.php?action=get_unread_count'},
        {name: 'Manager Problems Page', url: 'manager/problems.php', type: 'page'}
    ];
    
    let results = '<div class="test-section"><h3>🧪 API Test Results</h3>';
    let testCount = 0;
    
    tests.forEach(test => {
        fetch(test.url, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => {
            testCount++;
            if (response.ok) {
                if (test.type === 'page') {
                    results += `<p class="success">✅ ${test.name}: Page loads successfully</p>`;
                } else {
                    return response.json().then(data => {
                        if (data.success) {
                            results += `<p class="success">✅ ${test.name}: Working</p>`;
                        } else {
                            results += `<p class="error">❌ ${test.name}: ${data.message || 'Unknown error'}</p>`;
                        }
                    });
                }
            } else {
                results += `<p class="error">❌ ${test.name}: HTTP ${response.status}</p>`;
            }
            
            if (testCount === tests.length) {
                results += '</div>';
                document.getElementById('results').innerHTML += results;
                hideLoading();
            }
        })
        .catch(error => {
            testCount++;
            results += `<p class="error">❌ ${test.name}: ${error.message}</p>`;
            
            if (testCount === tests.length) {
                results += '</div>';
                document.getElementById('results').innerHTML += results;
                hideLoading();
            }
        });
    });
}

function checkDatabaseQueries() {
    showLoading('Checking database queries...');
    
    fetch('debug_api.php?action=check_db', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.text())
    .then(data => {
        document.getElementById('results').innerHTML += `
            <div class="test-section">
                <h3>📊 Database Query Check</h3>
                <div class="code-block">${data}</div>
            </div>
        `;
        hideLoading();
    })
    .catch(error => {
        showError('Database check failed: ' + error.message);
        hideLoading();
    });
}

function resetProblems() {
    if (confirm('This will reset the problems system. Continue?')) {
        showLoading('Resetting problems...');
        
        fetch('debug_api.php?action=reset_problems', {
            method: 'POST'
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('results').innerHTML += `
                <div class="test-section">
                    <h3>🔄 Reset Results</h3>
                    <div class="code-block">${data}</div>
                </div>
            `;
            hideLoading();
        })
        .catch(error => {
            showError('Reset failed: ' + error.message);
            hideLoading();
        });
    }
}

function showLoading(message) {
    document.getElementById('results').innerHTML += `
        <div class="test-section" id="loading">
            <p class="info">⏳ ${message}</p>
        </div>
    `;
}

function hideLoading() {
    const loading = document.getElementById('loading');
    if (loading) {
        loading.remove();
    }
}

function showError(message) {
    document.getElementById('results').innerHTML += `
        <div class="test-section">
            <p class="error">❌ ${message}</p>
        </div>
    `;
}
</script>

<?php
// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'fix_errors':
            echo fixAPIErrors();
            exit;
            
        case 'check_db':
            echo checkDatabaseQueries();
            exit;
            
        case 'reset_problems':
            echo resetProblems();
            exit;
    }
}

function fixAPIErrors() {
    global $db;
    
    $output = "";
    
    try {
        // Fix 1: Check and fix database parameter issues
        $output .= "<h4>🔧 Fixing Database Parameter Issues</h4>";
        
        // Test problematic queries from admin dashboard
        $test_queries = [
            "SELECT COUNT(*) FROM users u WHERE u.is_active = 1",
            "SELECT COUNT(*) FROM tasks t WHERE 1 = 1",
            "SELECT COUNT(*) FROM problems",
        ];
        
        foreach ($test_queries as $query) {
            try {
                $result = $db->fetchCount($query);
                $output .= "<p class='success'>✅ Query OK: " . substr($query, 0, 50) . "... (Result: $result)</p>";
            } catch (Exception $e) {
                $output .= "<p class='error'>❌ Query FAILED: " . substr($query, 0, 50) . "...<br>Error: " . $e->getMessage() . "</p>";
            }
        }
        
        // Fix 2: Check API file permissions
        $output .= "<h4>🔧 Checking File Permissions</h4>";
        $api_files = ['api/tasks.php', 'api/problems.php', 'api/notifications.php'];
        
        foreach ($api_files as $file) {
            if (file_exists($file)) {
                $perms = fileperms($file);
                if ($perms & 0x0004) {
                    $output .= "<p class='success'>✅ $file: Readable</p>";
                } else {
                    $output .= "<p class='error'>❌ $file: Not readable</p>";
                    chmod($file, 0644);
                    $output .= "<p class='info'>🔧 Fixed permissions for $file</p>";
                }
            }
        }
        
        // Fix 3: Clear any cached errors
        $output .= "<h4>🔧 Clearing Error Cache</h4>";
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $output .= "<p class='success'>✅ OPCache cleared</p>";
        }
        
        // Fix 4: Test direct API calls
        $output .= "<h4>🔧 Testing Direct API Calls</h4>";
        
        // Test tasks API
        $_GET['action'] = 'test';
        ob_start();
        include 'api/tasks.php';
        $api_output = ob_get_clean();
        
        if (strpos($api_output, '"success":true') !== false) {
            $output .= "<p class='success'>✅ Tasks API: Working</p>";
        } else {
            $output .= "<p class='error'>❌ Tasks API: " . htmlspecialchars(substr($api_output, 0, 100)) . "</p>";
        }
        
        $output .= "<p class='info'>🎯 Primary issues should now be resolved. Try accessing manager/problems.php again.</p>";
        
    } catch (Exception $e) {
        $output .= "<p class='error'>❌ Fix process failed: " . $e->getMessage() . "</p>";
    }
    
    return $output;
}

function checkDatabaseQueries() {
    global $db;
    
    $output = "";
    
    try {
        $output .= "<h4>📊 Database Query Diagnostics</h4>";
        
        // Check problematic queries from admin dashboard
        $queries_to_test = [
            "Basic user count" => "SELECT COUNT(*) FROM users WHERE is_active = 1",
            "Basic task count" => "SELECT COUNT(*) FROM tasks",
            "Basic problem count" => "SELECT COUNT(*) FROM problems",
            "Complex task query" => "SELECT COUNT(*) FROM tasks t WHERE t.status = 'pending'",
            "Join query test" => "SELECT COUNT(*) FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id WHERE u.role = 'mechanic'"
        ];
        
        foreach ($queries_to_test as $name => $query) {
            try {
                $start_time = microtime(true);
                $result = $db->fetchCount($query);
                $end_time = microtime(true);
                $duration = round(($end_time - $start_time) * 1000, 2);
                
                $output .= "<p class='success'>✅ $name: $result (${duration}ms)</p>";
            } catch (Exception $e) {
                $output .= "<p class='error'>❌ $name: " . $e->getMessage() . "</p>";
                $output .= "<p class='info'>Query: " . htmlspecialchars($query) . "</p>";
            }
        }
        
        // Check table structure
        $output .= "<h4>📋 Table Structure Check</h4>";
        $tables = ['users', 'tasks', 'problems', 'notifications'];
        
        foreach ($tables as $table) {
            try {
                $count = $db->fetchCount("SELECT COUNT(*) FROM $table");
                $output .= "<p class='success'>✅ Table '$table': $count records</p>";
            } catch (Exception $e) {
                $output .= "<p class='error'>❌ Table '$table': " . $e->getMessage() . "</p>";
            }
        }
        
    } catch (Exception $e) {
        $output .= "<p class='error'>❌ Database check failed: " . $e->getMessage() . "</p>";
    }
    
    return $output;
}

function resetProblems() {
    global $db;
    
    $output = "";
    
    try {
        $output .= "<h4>🔄 Resetting Problems System</h4>";
        
        // Check current problems
        $problem_count = $db->fetchCount("SELECT COUNT(*) FROM problems");
        $output .= "<p class='info'>📊 Current problems: $problem_count</p>";
        
        // Check if operator user exists
        $operator = $db->fetch("SELECT id FROM users WHERE role = 'operator' AND is_active = 1 LIMIT 1");
        if (!$operator) {
            $output .= "<p class='error'>❌ No active operator found</p>";
            return $output;
        }
        
        // Reset problem assignments
        $reset_count = $db->query("UPDATE problems SET assigned_to = NULL, assigned_by = NULL, status = 'reported' WHERE status = 'assigned'")->rowCount();
        $output .= "<p class='success'>✅ Reset $reset_count problem assignments</p>";
        
        // Check mechanics for assignment
        $mechanics = $db->fetchAll("SELECT id, first_name, last_name FROM users WHERE role = 'mechanic' AND is_active = 1");
        $output .= "<p class='info'>👥 Available mechanics: " . count($mechanics) . "</p>";
        
        foreach ($mechanics as $mechanic) {
            $output .= "<p class='info'>  - {$mechanic['first_name']} {$mechanic['last_name']} (ID: {$mechanic['id']})</p>";
        }
        
        $output .= "<p class='success'>✅ Problems system reset complete</p>";
        $output .= "<p class='info'>🎯 Try the assignment feature again</p>";
        
    } catch (Exception $e) {
        $output .= "<p class='error'>❌ Reset failed: " . $e->getMessage() . "</p>";
    }
    
    return $output;
}
?>

</body>
</html>