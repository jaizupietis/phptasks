<?php
/**
 * COMPLETE Admin Dashboard - FIXED with Task Creation
 * Replace: /var/www/tasks/admin/dashboard.php
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

// Handle filtering by clicking on stats cards
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$role_filter = isset($_GET['role']) ? sanitizeInput($_GET['role']) : 'all';

// Build filter conditions
$task_where = "1 = 1";
$task_params = [];
$user_where = "1 = 1";
$user_params = [];

if ($status_filter !== 'all') {
    if (in_array($status_filter, ['pending', 'in_progress', 'completed', 'overdue'])) {
        if ($status_filter === 'overdue') {
            $task_where .= " AND t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled')";
        } else {
            $task_where .= " AND t.status = ?";
            $task_params[] = $status_filter;
        }
    }
}

if ($role_filter !== 'all') {
    $user_where .= " AND u.role = ?";
    $user_params[] = $role_filter;
}

// Get basic statistics with error handling
try {
    // Use simpler, safer queries to avoid parameter errors
    $stats = [
        'total_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE is_active = 1"),
        'total_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks"),
        'pending_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'pending'"),
        'in_progress_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'"),
        'completed_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'completed'"),
        'overdue_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE due_date < NOW() AND status NOT IN ('completed', 'cancelled')"),
        
        // Role-based user statistics - fixed queries
        'admin_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"),
        'manager_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'manager' AND is_active = 1"),
        'mechanic_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'mechanic' AND is_active = 1"),
        'operator_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'operator' AND is_active = 1"),
        
        // Problem statistics - fixed queries
        'total_problems' => 0,
        'reported_problems' => 0,
        'urgent_problems' => 0,
    ];
    
    // Safely get problem stats
    try {
        $stats['total_problems'] = $db->fetchCount("SELECT COUNT(*) FROM problems");
        $stats['reported_problems'] = $db->fetchCount("SELECT COUNT(*) FROM problems WHERE status = 'reported'");
        $stats['urgent_problems'] = $db->fetchCount("SELECT COUNT(*) FROM problems WHERE priority = 'urgent' AND status NOT IN ('resolved', 'closed')");
    } catch (Exception $e) {
        error_log("Problems stats error: " . $e->getMessage());
        // Continue with zero values if problems table has issues
    }
        
} catch (Exception $e) {
    error_log("Admin dashboard stats error: " . $e->getMessage());
    // Initialize with safe defaults
    $stats = [
        'total_users' => 0, 'total_tasks' => 0, 'pending_tasks' => 0, 'in_progress_tasks' => 0, 
        'completed_tasks' => 0, 'overdue_tasks' => 0, 'admin_users' => 0, 'manager_users' => 0,
        'mechanic_users' => 0, 'operator_users' => 0, 'total_problems' => 0, 'reported_problems' => 0,
        'urgent_problems' => 0
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
         WHERE {$task_where}
         ORDER BY t.created_at DESC 
         LIMIT 10",
        $task_params
    );
} catch (Exception $e) {
    error_log("Admin dashboard tasks error: " . $e->getMessage());
    $recent_tasks = [];
}

// Get all users for dropdowns
$users = $db->fetchAll(
    "SELECT id, first_name, last_name, role FROM users 
     WHERE is_active = 1 
     ORDER BY role, first_name, last_name"
);

// Separate mechanics for task assignment
$mechanics = array_filter($users, function($user) {
    return $user['role'] === 'mechanic';
});

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
            overflow: hidden;
            position: relative;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stats-card.active {
            border: 3px solid #6f42c1;
            transform: translateY(-3px);
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #6f42c1, #5a32a3);
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
        .stats-icon.admin { background: linear-gradient(135deg, #dc3545, #c82333); }
        .stats-icon.manager { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
        .stats-icon.mechanic { background: linear-gradient(135deg, #28a745, #1e7e34); }
        .stats-icon.operator { background: linear-gradient(135deg, #17a2b8, #138496); }
        .stats-icon.problems { background: linear-gradient(135deg, #ffc107, #e0a800); }
        
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
        
        .filter-info {
            background: #e3f2fd;
            border: 1px solid #1976d2;
            color: #1976d2;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .stats-card {
                margin-bottom: 1rem;
            }
            
            .quick-action-btn {
                height: 100px;
                font-size: 0.875rem;
            }
            
            .quick-action-btn i {
                font-size: 1.5rem;
            }
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
                
                <!-- FIXED Problems Alert Badge Section -->
<?php if ($stats['urgent_problems'] > 0): ?>
<a href="../manager/problems.php?priority=urgent" class="btn btn-outline-light btn-sm me-2 position-relative">
    <i class="fas fa-exclamation-triangle"></i> Urgent
    <span class="notification-badge"><?php echo $stats['urgent_problems']; ?></span>
</a>
<?php endif; ?>
                
               <div class="dropdown">
    <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <i class="fas fa-cog"></i>
    </button>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="#" onclick="showCreateTaskModal()">
            <i class="fas fa-plus"></i> Create Task</a></li>
        <li><a class="dropdown-item" href="users.php">
            <i class="fas fa-users"></i> Manage Users</a></li>
        <li><a class="dropdown-item" href="../manager/tasks.php">
            <i class="fas fa-tasks"></i> Manage Tasks</a></li>
        <!-- FIXED: Safe Problems link with error handling -->
        <li><a class="dropdown-item" href="#" onclick="safeNavigateToProblems()">
            <i class="fas fa-exclamation-triangle"></i> Manage Problems</a></li>
        <li><a class="dropdown-item" href="reports.php">
            <i class="fas fa-chart-bar"></i> Reports</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="../logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- Filter Information -->
        <?php if ($status_filter !== 'all' || $role_filter !== 'all'): ?>
        <div class="filter-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-filter"></i>
                    <strong>Active Filters:</strong>
                    <?php if ($status_filter !== 'all'): ?>
                    Status: <span class="badge bg-primary"><?php echo ucfirst(str_replace('_', ' ', $status_filter)); ?></span>
                    <?php endif; ?>
                    <?php if ($role_filter !== 'all'): ?>
                    Role: <span class="badge bg-primary"><?php echo ucfirst($role_filter); ?></span>
                    <?php endif; ?>
                </div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- System Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'all' ? 'active' : ''; ?>" onclick="filterDashboard('users')">
                    <div class="stats-icon users mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_users']; ?></h3>
                    <p class="text-muted mb-0">Total Users</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'all' ? 'active' : ''; ?>" onclick="filterDashboard('tasks')">
                    <div class="stats-icon tasks mx-auto">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_tasks']; ?></h3>
                    <p class="text-muted mb-0">Total Tasks</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" onclick="filterDashboard('pending')">
                    <div class="stats-icon pending mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['pending_tasks']; ?></h3>
                    <p class="text-muted mb-0">Pending</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" onclick="filterDashboard('in_progress')">
                    <div class="stats-icon progress mx-auto">
                        <i class="fas fa-play"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['in_progress_tasks']; ?></h3>
                    <p class="text-muted mb-0">In Progress</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" onclick="filterDashboard('completed')">
                    <div class="stats-icon completed mx-auto">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['completed_tasks']; ?></h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'overdue' ? 'active' : ''; ?>" onclick="filterDashboard('overdue')">
                    <div class="stats-icon overdue mx-auto">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-danger"><?php echo $stats['overdue_tasks']; ?></h3>
                    <p class="text-muted mb-0">Overdue</p>
                </div>
            </div>
        </div>
        
        <!-- User Role Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'admin' ? 'active' : ''; ?>" onclick="filterDashboard('admin')">
                    <div class="stats-icon admin mx-auto">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3 class="text-danger"><?php echo $stats['admin_users']; ?></h3>
                    <p class="text-muted mb-0">Administrators</p>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'manager' ? 'active' : ''; ?>" onclick="filterDashboard('manager')">
                    <div class="stats-icon manager mx-auto">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['manager_users']; ?></h3>
                    <p class="text-muted mb-0">Managers</p>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'mechanic' ? 'active' : ''; ?>" onclick="filterDashboard('mechanic')">
                    <div class="stats-icon mechanic mx-auto">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['mechanic_users']; ?></h3>
                    <p class="text-muted mb-0">Mechanics</p>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'operator' ? 'active' : ''; ?>" onclick="filterDashboard('operator')">
                    <div class="stats-icon operator mx-auto">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['operator_users']; ?></h3>
                    <p class="text-muted mb-0">Operators</p>
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
                    <div class="col-lg-2 col-md-6">
                        <a href="#" onclick="showCreateTaskModal()" 
                           class="btn btn-success w-100 quick-action-btn">
                            <i class="fas fa-plus-circle"></i>
                            <span>Create Task</span>
                        </a>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <a href="users.php" class="btn btn-primary w-100 quick-action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span>Manage Users</span>
                        </a>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <a href="../manager/tasks.php" class="btn btn-info w-100 quick-action-btn">
                            <i class="fas fa-tasks"></i>
                            <span>View Tasks</span>
                        </a>
                    </div>
                    <!-- FIXED: Corrected Problems link -->
                    <div class="col-lg-2 col-md-6">
    <a href="#" onclick="safeNavigateToProblems()" class="btn btn-warning w-100 quick-action-btn">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Problems</span>
        <?php if ($stats['reported_problems'] > 0): ?>
        <small class="d-block mt-1"><?php echo $stats['reported_problems']; ?> new</small>
        <?php endif; ?>
    </a>
</div>
                    <div class="col-lg-2 col-md-6">
                        <a href="../mechanic/dashboard.php" class="btn btn-info w-100 quick-action-btn">
                            <i class="fas fa-eye"></i>
                            <span>View as Mechanic</span>
                        </a>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <button class="btn btn-secondary w-100 quick-action-btn" onclick="showSystemInfo()">
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
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-tasks"></i> Recent Tasks</h5>
                        <a href="../manager/tasks.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_tasks)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <h6>No tasks found</h6>
                            <p class="text-muted">Create some tasks to see them here</p>
                            <button class="btn btn-success" onclick="showCreateTaskModal()">
                                <i class="fas fa-plus"></i> Create First Task
                            </button>
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
                                        <th>Actions</th>
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
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="viewTask(<?php echo $task['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-warning" onclick="editTask(<?php echo $task['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
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
    
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #6f42c1; color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Create New Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="createTaskForm" novalidate>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="taskTitle" class="form-label">Task Title *</label>
                                    <input type="text" class="form-control" id="taskTitle" required 
                                           placeholder="Enter task title">
                                    <div class="invalid-feedback">Please provide a task title.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="taskDescription" class="form-label">Description</label>
                                    <textarea class="form-control" id="taskDescription" rows="3" 
                                              placeholder="Describe the task details"></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="taskLocation" 
                                                   placeholder="Work location">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskEquipment" class="form-label">Equipment</label>
                                            <input type="text" class="form-control" id="taskEquipment" 
                                                   placeholder="Required equipment">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskCategory" class="form-label">Category</label>
                                            <select class="form-select" id="taskCategory">
                                                <option value="">Select Category</option>
                                                <option value="Preventive Maintenance">Preventive Maintenance</option>
                                                <option value="Repair">Repair</option>
                                                <option value="Inspection">Inspection</option>
                                                <option value="Safety Check">Safety Check</option>
                                                <option value="Installation">Installation</option>
                                                <option value="Emergency">Emergency</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="estimatedHours" class="form-label">Estimated Hours</label>
                                            <input type="number" class="form-control" id="estimatedHours" 
                                                   min="0.5" step="0.5" placeholder="2.5">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="assignedTo" class="form-label">Assign To *</label>
                                    <select class="form-select" id="assignedTo" required>
                                        <option value="">Select Mechanic</option>
                                        <?php foreach ($mechanics as $mechanic): ?>
                                        <option value="<?php echo $mechanic['id']; ?>">
                                            <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a mechanic.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="taskPriority" class="form-label">Priority *</label>
                                    <select class="form-select" id="taskPriority" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="dueDate" class="form-label">Due Date</label>
                                    <input type="datetime-local" class="form-control" id="dueDate">
                                </div>
                                <div class="mb-3">
                                    <label for="startDate" class="form-label">Start Date</label>
                                    <input type="datetime-local" class="form-control" id="startDate">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="taskNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="taskNotes" rows="2" 
                                      placeholder="Additional notes or instructions"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="createTaskBtn">
                            <i class="fas fa-plus-circle"></i> Create Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== Admin Dashboard with Task Creation Loading ===');
        
        // Setup event listeners
        setupEventListeners();
        
        // Check for urgent problems
        const urgentProblems = <?php echo $stats['urgent_problems']; ?>;
        if (urgentProblems > 0) {
            setTimeout(() => {
                showToast(`⚠️ ${urgentProblems} urgent problem(s) require immediate attention!`, 'warning', 8000);
            }, 2000);
        }
        
        console.log('Admin dashboard loaded successfully');
        console.log('System stats:', <?php echo json_encode($stats); ?>);
    });
    
    function setupEventListeners() {
        // Create task form submission
        const createForm = document.getElementById('createTaskForm');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                e.preventDefault();
                createNewTask();
            });
        }
        
        // Set default dates
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        
        const dueDateField = document.getElementById('dueDate');
        const startDateField = document.getElementById('startDate');
        
        if (dueDateField) {
            dueDateField.value = formatDateTimeLocal(tomorrow);
        }
        
        const today = new Date();
        today.setHours(8, 0, 0, 0);
        if (startDateField) {
            startDateField.value = formatDateTimeLocal(today);
        }
    }
    
     // FIXED: Safe navigation to problems page with error handling
function safeNavigateToProblems() {
    console.log('🔧 Navigating to problems page safely...');
    
    // First check if the page is accessible
    fetch('../manager/problems.php', {
        method: 'HEAD',
        credentials: 'same-origin'
    })
    .then(response => {
        console.log('Problems page check status:', response.status);
        
        if (response.ok) {
            // Page is accessible, navigate normally
            window.location.href = '../manager/problems.php';
        } else {
            // Page has issues, show fallback
            showProblemsError();
        }
    })
    .catch(error => {
        console.error('Problems page check failed:', error);
        showProblemsError();
    });
}

function showProblemsError() {
    showToast('⚠️ Problems page is temporarily unavailable. Please try again later.', 'warning', 8000);
    
    // Offer alternative actions
    setTimeout(() => {
        if (confirm('Would you like to view the debug page to help resolve this issue?')) {
            window.open('../debug_api.php', '_blank');
        }
    }, 2000);
}
                               
// FIXED: Filter dashboard function with safe problem navigation
function filterDashboard(filterType) {
    let url = 'dashboard.php?';
    
    switch(filterType) {
        case 'users':
            url += 'role=all';
            break;
        case 'tasks':
            url += 'status=all';
            break;
        case 'pending':
            url += 'status=pending';
            break;
        case 'in_progress':
            url += 'status=in_progress';
            break;
        case 'completed':
            url += 'status=completed';
            break;
        case 'overdue':
            url += 'status=overdue';
            break;
        case 'admin':
            url += 'role=admin';
            break;
        case 'manager':
            url += 'role=manager';
            break;
        case 'mechanic':
            url += 'role=mechanic';
            break;
        case 'operator':
            url += 'role=operator';
            break;
        // FIXED: Safe problems redirect
        case 'problems':
            safeNavigateToProblems();
            return;
        case 'urgent_problems':
            safeNavigateToProblems();
            return;
        default:
            return;
    }
    
    window.location.href = url;
}
  // Enhanced debug information
console.log('🔧 FIXED Admin Dashboard Debug Info:');
console.log('- Total problems:', <?php echo $stats['total_problems']; ?>);
console.log('- Reported problems:', <?php echo $stats['reported_problems']; ?>);
console.log('- Urgent problems:', <?php echo $stats['urgent_problems']; ?>);
console.log('- Problems system available:', <?php echo $stats['total_problems'] >= 0 ? 'true' : 'false'; ?>);  
    function showCreateTaskModal() {
        console.log('Opening admin task creation modal...');
        
        // Reset form
        const form = document.getElementById('createTaskForm');
        if (form) {
            form.reset();
            form.classList.remove('was-validated');
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('createTaskModal'));
        modal.show();
    }
    
    function createNewTask() {
        const taskData = {
            action: 'create_task',
            title: document.getElementById('taskTitle').value.trim(),
            description: document.getElementById('taskDescription').value.trim(),
            location: document.getElementById('taskLocation').value.trim(),
            equipment: document.getElementById('taskEquipment').value.trim(),
            category: document.getElementById('taskCategory').value,
            estimated_hours: parseFloat(document.getElementById('estimatedHours').value) || null,
            assigned_to: parseInt(document.getElementById('assignedTo').value),
            priority: document.getElementById('taskPriority').value,
            due_date: document.getElementById('dueDate').value || null,
            start_date: document.getElementById('startDate').value || null,
            notes: document.getElementById('taskNotes').value.trim()
        };
        
        console.log('Creating admin task with data:', taskData);
        
        // Enhanced validation
        const errors = validateTaskData(taskData);
        if (errors.length > 0) {
            showValidationErrors(errors);
            return;
        }
        
        // Show loading
        const createBtn = document.getElementById('createTaskBtn');
        const originalText = createBtn.innerHTML;
        createBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        createBtn.disabled = true;
        
        fetch('../api/tasks.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(taskData),
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.text().then(text => {
                console.log('Raw response:', text);
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(data => {
            console.log('Admin task creation response:', data);
            
            // Reset button
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
            
            if (data.success) {
                showToast('✅ Task created successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('createTaskModal')).hide();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('❌ Error: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('Admin task creation error:', error);
            showToast('❌ Error creating task: ' + error.message, 'danger');
            
            // Reset button
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
        });
    }
    
    function validateTaskData(data) {
        const errors = [];
        
        if (!data.title || data.title.length < 3) {
            errors.push('Task title must be at least 3 characters long');
        }
        
        if (!data.assigned_to || data.assigned_to === 0) {
            errors.push('Please select a mechanic to assign the task to');
        }
        
        if (!data.priority) {
            errors.push('Please select a priority level');
        }
        
        if (data.estimated_hours && (data.estimated_hours < 0.5 || data.estimated_hours > 100)) {
            errors.push('Estimated hours must be between 0.5 and 100');
        }
        
        if (data.due_date) {
            const dueDate = new Date(data.due_date);
            const now = new Date();
            if (dueDate < now) {
                errors.push('Due date cannot be in the past');
            }
        }
        
        return errors;
    }
    
    function showValidationErrors(errors) {
        const errorHtml = errors.map(error => `<li>${error}</li>`).join('');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger';
        errorDiv.innerHTML = `
            <h6><i class="fas fa-exclamation-triangle"></i> Please fix the following errors:</h6>
            <ul class="mb-0">${errorHtml}</ul>
        `;
        
        const modalBody = document.querySelector('#createTaskModal .modal-body');
        const existingAlert = modalBody.querySelector('.alert');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        modalBody.insertBefore(errorDiv, modalBody.firstChild);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 5000);
    }
    
    function viewTask(taskId) {
        window.location.href = `../manager/tasks.php?task_id=${taskId}`;
    }
    
    function editTask(taskId) {
        window.location.href = `../manager/tasks.php?edit=${taskId}`;
    }
                               
    function viewProblems() {
    window.location.href = '../manager/problems.php';
}

function viewUrgentProblems() {
    window.location.href = '../manager/problems.php?priority=urgent';
}
    
    function showSystemInfo() {
        const info = `System Information:

Application: <?php echo APP_NAME; ?>
Version: <?php echo APP_VERSION; ?>
Database: Connected
PHP Version: <?php echo PHP_VERSION; ?>
Server Time: <?php echo date('Y-m-d H:i:s'); ?>

Statistics:
- Total Users: <?php echo $stats['total_users']; ?>
- Total Tasks: <?php echo $stats['total_tasks']; ?>
- Active Problems: <?php echo $stats['reported_problems']; ?>

Server Info:
- Document Root: <?php echo $_SERVER['DOCUMENT_ROOT']; ?>
- Server Software: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?>`;

        alert(info);
    }
    
    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    function showToast(message, type = 'info', duration = 4000) {
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());
        
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1070';
            document.body.appendChild(container);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast, {
            autohide: true,
            delay: duration
        });
        bsToast.show();
        
        toast.addEventListener('hidden.bs.toast', function() {
            toast.remove();
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            showCreateTaskModal();
        }
    });
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

<?php
/**
 * ADDITIONAL DEBUGGING SECTION
 * Add this to help diagnose the issue
 */

// Debug information (remove in production)
if (isset($_GET['debug'])) {
    echo "<div class='alert alert-info'>";
    echo "<h6>Debug Information:</h6>";
    echo "<p><strong>Problems Table Status:</strong> ";
    
    try {
        $problem_count = $db->fetchCount("SELECT COUNT(*) FROM problems");
        echo "✅ Available ($problem_count records)";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage();
    }
    
    echo "</p>";
    echo "<p><strong>Mechanics Available:</strong> ";
    
    try {
        $mechanic_count = $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'mechanic' AND is_active = 1");
        echo "✅ $mechanic_count mechanics";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage();
    }
    
    echo "</p>";
    echo "<p><strong>Problems Page Test:</strong> <button onclick='safeNavigateToProblems()' class='btn btn-sm btn-primary'>Test Navigation</button></p>";
    echo "</div>";
}
?>

<!-- Add debug link for admins -->
<div class="text-center mt-3">
    <a href="?debug=1" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-bug"></i> Show Debug Info
    </a>
    <a href="../debug_api.php" class="btn btn-sm btn-outline-info" target="_blank">
        <i class="fas fa-tools"></i> API Debug Tool
    </a>
</div>