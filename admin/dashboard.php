<?php
/**
 * Admin Dashboard - Fixed Version
 */

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

// Get admin user information
$admin = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

if (!$admin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Get basic statistics with error handling
try {
    $stats = [
        'total_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE is_active = 1"),
        'total_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks"),
        'pending_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'pending'"),
        'in_progress_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'"),
        'completed_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'completed'"),
        'overdue_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE due_date < NOW() AND status NOT IN ('completed', 'cancelled')"),
    ];
} catch (Exception $e) {
    error_log("Admin dashboard stats error: " . $e->getMessage());
    $stats = [
        'total_users' => 0,
        'total_tasks' => 0,
        'pending_tasks' => 0,
        'in_progress_tasks' => 0,
        'completed_tasks' => 0,
        'overdue_tasks' => 0,
    ];
}

// Get recent activities with error handling
try {
    $recent_activities = $db->fetchAll(
        "SELECT al.*, u.first_name, u.last_name 
         FROM activity_logs al
         LEFT JOIN users u ON al.user_id = u.id 
         ORDER BY al.created_at DESC 
         LIMIT 10"
    );
} catch (Exception $e) {
    error_log("Admin dashboard activities error: " . $e->getMessage());
    $recent_activities = [];
}

// Get recent tasks
try {
    $recent_tasks = $db->fetchAll(
        "SELECT t.*, 
                ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
                ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname
         FROM tasks t 
         LEFT JOIN users ua ON t.assigned_to = ua.id 
         LEFT JOIN users ub ON t.assigned_by = ub.id 
         ORDER BY t.created_at DESC 
         LIMIT 10"
    );
} catch (Exception $e) {
    error_log("Admin dashboard tasks error: " . $e->getMessage());
    $recent_tasks = [];
}

$page_title = 'Admin Dashboard';
// Helper function for time display
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time / 60) . 'm ago';
        if ($time < 86400) return floor($time / 3600) . 'h ago';
        return date('M j', strtotime($datetime));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: #f8f9fa;
        }
        
        .admin-navbar {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stats-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stats-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stats-icon.users { background: linear-gradient(135deg, #17a2b8, #138496); }
        .stats-icon.tasks { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
        .stats-icon.pending { background: linear-gradient(135deg, #fd7e14, #e96500); }
        .stats-icon.progress { background: linear-gradient(135deg, #007bff, #0056b3); }
        .stats-icon.completed { background: linear-gradient(135deg, #28a745, #1e7e34); }
        .stats-icon.overdue { background: linear-gradient(135deg, #dc3545, #c82333); }
        
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f3f4;
            transition: background 0.3s ease;
        }
        
        .activity-item:hover {
            background: #f8f9fa;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            margin-right: 1rem;
        }
        
        .activity-icon.login { background: #d4edda; color: #155724; }
        .activity-icon.task { background: #cce7ff; color: #004085; }
        .activity-icon.update { background: #fff3cd; color: #856404; }
        
        .quick-action-btn {
            height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            text-decoration: none;
            border-radius: 15px;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .quick-action-btn i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark admin-navbar">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-shield-alt"></i> Admin Panel
            </span>
            <div class="d-flex align-items-center">
                <span class="me-3">Welcome, <?php echo htmlspecialchars($admin['first_name']); ?>!</span>
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-cog"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="users.php"><i class="fas fa-users"></i> Manage Users</a></li>
                        <li><a class="dropdown-item" href="tasks.php"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
                        <li><a class="dropdown-item" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- System Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3">
                    <div class="stats-icon users mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_users']; ?></h3>
                    <p class="text-muted mb-0">Total Users</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3">
                    <div class="stats-icon tasks mx-auto">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_tasks']; ?></h3>
                    <p class="text-muted mb-0">Total Tasks</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3">
                    <div class="stats-icon pending mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['pending_tasks']; ?></h3>
                    <p class="text-muted mb-0">Pending</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3">
                    <div class="stats-icon progress mx-auto">
                        <i class="fas fa-play"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['in_progress_tasks']; ?></h3>
                    <p class="text-muted mb-0">In Progress</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3">
                    <div class="stats-icon completed mx-auto">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['completed_tasks']; ?></h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3">
                    <div class="stats-icon overdue mx-auto">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-danger"><?php echo $stats['overdue_tasks']; ?></h3>
                    <p class="text-muted mb-0">Overdue</p>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-3 col-md-6">
                                <a href="users.php" class="btn btn-primary w-100 quick-action-btn">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Manage Users</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <a href="tasks.php" class="btn btn-success w-100 quick-action-btn">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Manage Tasks</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <a href="../mechanic/dashboard.php" class="btn btn-info w-100 quick-action-btn">
                                    <i class="fas fa-eye"></i>
                                    <span>View as Mechanic</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <button class="btn btn-warning w-100 quick-action-btn" onclick="showSystemInfo()">
                                    <i class="fas fa-info-circle"></i>
                                    <span>System Info</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Recent Tasks -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tasks"></i> Recent Tasks</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_tasks)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <h6>No tasks found</h6>
                            <p class="text-muted">Create some tasks to see them here</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Assigned To</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_tasks as $task): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                            <?php if ($task['description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(($task['assigned_to_name'] ?? '') . ' ' . ($task['assigned_to_lastname'] ?? '')); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $task['status'] === 'completed' ? 'success' : ($task['status'] === 'in_progress' ? 'primary' : 'secondary'); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $task['priority'] === 'urgent' ? 'danger' : ($task['priority'] === 'high' ? 'warning' : 'info'); ?>">
                                                <?php echo ucfirst($task['priority']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j', strtotime($task['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clock fa-2x text-muted mb-3"></i>
                            <p class="text-muted">No recent activity</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item d-flex align-items-center">
                            <div class="activity-icon <?php echo getActivityClass($activity['action']); ?>">
                                <i class="fas fa-<?php echo getActivityIcon($activity['action']); ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="activity-content">
                                    <strong><?php echo $activity['first_name'] ? htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']) : 'System'; ?></strong>
                                    <?php echo htmlspecialchars($activity['action']); ?>
                                </div>
                                <small class="text-muted"><?php echo timeAgo($activity['created_at']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Admin dashboard loaded successfully');
        console.log('System stats:', <?php echo json_encode($stats); ?>);
    });
    
    function showSystemInfo() {
        alert('System Information:\n\n' +
              'Application: <?php echo APP_NAME; ?>\n' +
              'Version: <?php echo APP_VERSION; ?>\n' +
              'Database: Connected\n' +
              'PHP Version: <?php echo PHP_VERSION; ?>\n' +
              'Server Time: <?php echo date('Y-m-d H:i:s'); ?>');
    }
    </script>
</body>
</html>

<?php
// Helper functions
function getActivityClass($action) {
    if (strpos(strtolower($action), 'login') !== false) return 'login';
    if (strpos(strtolower($action), 'task') !== false) return 'task';
    return 'update';
}

function getActivityIcon($action) {
    if (strpos(strtolower($action), 'login') !== false) return 'sign-in-alt';
    if (strpos(strtolower($action), 'task') !== false) return 'tasks';
    return 'edit';
}


?>