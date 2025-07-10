<?php
/**
 * Enhanced Manager Dashboard - Complete Interface
 * Task Management System - Fixed and Enhanced Version
 */

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$is_mobile = isMobile();

// Handle task deletion via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    $task_id = (int)$_POST['task_id'];
    
    try {
        // Get task info before deletion
        $task = $db->fetch("SELECT title FROM tasks WHERE id = ?", [$task_id]);
        
        if ($task) {
            // Delete task (notifications will be deleted automatically due to foreign key constraint)
            $result = $db->query("DELETE FROM tasks WHERE id = ?", [$task_id]);
            
            if ($result->rowCount() > 0) {
                logActivity("Task deleted: {$task['title']}", 'INFO', $user_id);
                $_SESSION['success_message'] = 'Task deleted successfully!';
            }
        }
    } catch (Exception $e) {
        error_log("Task deletion error: " . $e->getMessage());
        $_SESSION['error_message'] = 'Error deleting task: ' . $e->getMessage();
    }
    
    header('Location: dashboard.php');
    exit;
}

// Get manager information
$manager = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

if (!$manager) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Get comprehensive statistics with error handling
try {
    $stats = [
        'total_mechanics' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'mechanic' AND is_active = 1"),
        'total_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks"),
        'pending_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'pending'"),
        'in_progress_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'"),
        'completed_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'completed'"),
        'overdue_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE due_date < NOW() AND status NOT IN ('completed', 'cancelled')"),
        'urgent_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE priority = 'urgent' AND status NOT IN ('completed', 'cancelled')"),
        'tasks_today' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE DATE(created_at) = CURDATE()"),
        'completed_today' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE DATE(completed_date) = CURDATE()"),
    ];
    
    // Calculate completion rate
    $stats['completion_rate'] = $stats['total_tasks'] > 0 ? 
        round(($stats['completed_tasks'] / $stats['total_tasks']) * 100, 1) : 0;
        
} catch (Exception $e) {
    error_log("Manager dashboard stats error: " . $e->getMessage());
    $stats = array_fill_keys(['total_mechanics', 'total_tasks', 'pending_tasks', 'in_progress_tasks', 
                             'completed_tasks', 'overdue_tasks', 'urgent_tasks', 'tasks_today', 
                             'completed_today', 'completion_rate'], 0);
}

// Get team overview with performance metrics
try {
    $team_overview = $db->fetchAll(
        "SELECT u.id, u.first_name, u.last_name, u.last_login, u.email,
                COUNT(t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_tasks,
                SUM(CASE WHEN DATE(t.completed_date) = CURDATE() THEN 1 ELSE 0 END) as completed_today
         FROM users u
         LEFT JOIN tasks t ON u.id = t.assigned_to
         WHERE u.role = 'mechanic' AND u.is_active = 1
         GROUP BY u.id, u.first_name, u.last_name, u.last_login, u.email
         ORDER BY u.first_name, u.last_name"
    );
} catch (Exception $e) {
    error_log("Manager team overview error: " . $e->getMessage());
    $team_overview = [];
}

// Get recent tasks for the overview
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
    error_log("Manager recent tasks error: " . $e->getMessage());
    $recent_tasks = [];
}

// Get priority tasks that need attention
try {
    $priority_tasks = $db->fetchAll(
        "SELECT t.*, u.first_name, u.last_name
         FROM tasks t
         LEFT JOIN users u ON t.assigned_to = u.id
         WHERE (t.priority = 'urgent' OR t.due_date < DATE_ADD(NOW(), INTERVAL 24 HOUR))
         AND t.status NOT IN ('completed', 'cancelled')
         ORDER BY 
            CASE WHEN t.priority = 'urgent' THEN 1 ELSE 2 END,
            t.due_date ASC
         LIMIT 8"
    );
} catch (Exception $e) {
    error_log("Manager priority tasks error: " . $e->getMessage());
    $priority_tasks = [];
}

$page_title = 'Manager Dashboard';

// Get notifications for the current user
$notifications = $db->fetchAll(
    "SELECT n.*, t.title as task_title, t.status as task_status 
     FROM notifications n
     LEFT JOIN tasks t ON n.task_id = t.id
     WHERE n.user_id = ? AND n.is_read = 0 
     ORDER BY n.created_at DESC 
     LIMIT 10",
    [$user_id]
);

$notification_count = count($notifications);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.css" rel="stylesheet">
    
    <style>
        :root {
            --manager-primary: #6f42c1;
            --manager-secondary: #495057;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .manager-navbar {
            background: linear-gradient(135deg, var(--manager-primary) 0%, #5a32a3 100%);
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
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--manager-primary), #5a32a3);
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stats-icon.primary { background: linear-gradient(135deg, var(--manager-primary), #5a32a3); }
        .stats-icon.success { background: linear-gradient(135deg, var(--success-color), #1e7e34); }
        .stats-icon.warning { background: linear-gradient(135deg, var(--warning-color), #e0a800); }
        .stats-icon.danger { background: linear-gradient(135deg, var(--danger-color), #c82333); }
        .stats-icon.info { background: linear-gradient(135deg, var(--info-color), #138496); }
        
        .team-card {
            border-left: 4px solid var(--manager-primary);
            transition: all 0.3s ease;
        }
        
        .team-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .task-priority-urgent { border-left-color: var(--danger-color); }
        .task-priority-high { border-left-color: #fd7e14; }
        .task-priority-medium { border-left-color: var(--warning-color); }
        .task-priority-low { border-left-color: var(--success-color); }
        
        .quick-action-btn {
            height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            text-decoration: none;
            border-radius: 15px;
            color: white;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            color: white;
            text-decoration: none;
        }
        
        .quick-action-btn i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1rem 0;
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .status-online { background: var(--success-color); }
        .status-offline { background: var(--danger-color); }
        .status-away { background: var(--warning-color); }
        
        .workload-bar {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        
        .workload-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .workload-low { background: var(--success-color); }
        .workload-medium { background: var(--warning-color); }
        .workload-high { background: var(--danger-color); }
        
        .notification-panel {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-urgent { background: var(--danger-color); color: white; }
        .priority-high { background: #fd7e14; color: white; }
        .priority-medium { background: var(--warning-color); color: #212529; }
        .priority-low { background: var(--success-color); color: white; }
        
        .modal-header {
            background: var(--manager-primary);
            color: white;
        }
        
        .btn-manager {
            background: var(--manager-primary);
            border-color: var(--manager-primary);
            color: white;
        }
        
        .btn-manager:hover {
            background: #5a32a3;
            border-color: #5a32a3;
            color: white;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(2px);
        }
        
        .task-actions .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .recent-task-card {
            border-left: 3px solid var(--info-color);
            margin-bottom: 0.5rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .recent-task-card:hover {
            transform: translateX(3px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger-color);
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
            
            .chart-container {
                height: 250px;
            }
            
            .quick-action-btn {
                height: 100px;
                font-size: 0.875rem;
            }
            
            .quick-action-btn i {
                font-size: 1.5rem;
            }
        }
        
        .alert-custom {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1060;
            min-width: 300px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark manager-navbar">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-users-cog"></i> Manager Dashboard
            </span>
            <div class="d-flex align-items-center">
                <span class="me-3">Welcome, <?php echo htmlspecialchars($manager['first_name']); ?>!</span>
                <?php if ($notification_count > 0): ?>
                <button class="btn btn-outline-light btn-sm me-2 position-relative" onclick="showNotifications()">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                </button>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-cog"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="showCreateTaskModal()">
                            <i class="fas fa-plus"></i> Create Task</a></li>
                        <li><a class="dropdown-item" href="tasks.php">
                            <i class="fas fa-tasks"></i> Manage Tasks</a></li>
                        <li><a class="dropdown-item" href="team.php">
                            <i class="fas fa-users"></i> Team Management</a></li>
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
    
    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show alert-custom" role="alert">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show alert-custom" role="alert">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show alert-custom" role="alert">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- Key Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='team.php'">
                    <div class="stats-icon primary mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_mechanics']; ?></h3>
                    <p class="text-muted mb-0">Team Members</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='tasks.php'">
                    <div class="stats-icon info mx-auto">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['total_tasks']; ?></h3>
                    <p class="text-muted mb-0">Total Tasks</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='tasks.php?status=pending'">
                    <div class="stats-icon warning mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['pending_tasks']; ?></h3>
                    <p class="text-muted mb-0">Pending</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='tasks.php?status=in_progress'">
                    <div class="stats-icon primary mx-auto">
                        <i class="fas fa-play"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['in_progress_tasks']; ?></h3>
                    <p class="text-muted mb-0">In Progress</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='tasks.php?status=completed'">
                    <div class="stats-icon success mx-auto">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['completed_tasks']; ?></h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="showOverdueTasks()">
                    <div class="stats-icon danger mx-auto">
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
                                <a href="#" onclick="showCreateTaskModal()" 
                                   class="btn btn-success w-100 quick-action-btn">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Create New Task</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <a href="team.php" class="btn btn-primary w-100 quick-action-btn">
                                    <i class="fas fa-users"></i>
                                    <span>Manage Team</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <a href="reports.php" class="btn btn-info w-100 quick-action-btn">
                                    <i class="fas fa-chart-line"></i>
                                    <span>View Reports</span>
                                </a>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <a href="#" onclick="bulkAssignTasks()" 
                                   class="btn btn-warning w-100 quick-action-btn">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Bulk Assignment</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Team Overview -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Team Overview</h5>
                        <span class="badge bg-primary"><?php echo count($team_overview); ?> members</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($team_overview)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <h6>No Team Members</h6>
                            <p class="text-muted">No mechanics found in the system</p>
                        </div>
                        <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($team_overview as $member): ?>
                            <div class="col-md-6">
                                <div class="card team-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-1">
                                                    <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?php if ($member['last_login']): ?>
                                                        <span class="status-indicator status-online"></span>
                                                        Last seen: <?php echo timeAgo($member['last_login']); ?>
                                                    <?php else: ?>
                                                        <span class="status-indicator status-offline"></span>
                                                        Never logged in
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="fas fa-cog"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#" 
                                                           onclick="assignTaskToMember(<?php echo $member['id']; ?>)">
                                                        <i class="fas fa-plus"></i> Assign Task</a></li>
                                                    <li><a class="dropdown-item" href="tasks.php?assigned_to=<?php echo $member['id']; ?>">
                                                        <i class="fas fa-tasks"></i> View Tasks</a></li>
                                                    <li><a class="dropdown-item" href="mailto:<?php echo htmlspecialchars($member['email']); ?>">
                                                        <i class="fas fa-envelope"></i> Send Email</a></li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="row text-center">
                                            <div class="col-3">
                                                <small class="text-muted d-block">Total</small>
                                                <strong class="text-primary"><?php echo $member['total_tasks']; ?></strong>
                                            </div>
                                            <div class="col-3">
                                                <small class="text-muted d-block">Pending</small>
                                                <strong class="text-warning"><?php echo $member['pending_tasks']; ?></strong>
                                            </div>
                                            <div class="col-3">
                                                <small class="text-muted d-block">Active</small>
                                                <strong class="text-info"><?php echo $member['in_progress_tasks']; ?></strong>
                                            </div>
                                            <div class="col-3">
                                                <small class="text-muted d-block">Done</small>
                                                <strong class="text-success"><?php echo $member['completed_tasks']; ?></strong>
                                            </div>
                                        </div>
                                        
                                        <?php if ($member['overdue_tasks'] > 0): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <?php echo $member['overdue_tasks']; ?> overdue
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($member['completed_today'] > 0): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle"></i>
                                                <?php echo $member['completed_today']; ?> completed today
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Workload Indicator -->
                                        <div class="mt-3">
                                            <small class="text-muted">Workload</small>
                                            <div class="workload-bar">
                                                <?php 
                                                $workload = $member['pending_tasks'] + $member['in_progress_tasks'];
                                                $workload_percentage = min(100, $workload * 20); // Assume 5 tasks = 100%
                                                $workload_class = $workload <= 2 ? 'workload-low' : 
                                                                 ($workload <= 4 ? 'workload-medium' : 'workload-high');
                                                ?>
                                                <div class="workload-fill <?php echo $workload_class; ?>" 
                                                     style="width: <?php echo $workload_percentage; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $workload; ?> active tasks</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Tasks -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-clock"></i> Recent Tasks</h5>
                        <a href="tasks.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_tasks)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <h6>No Recent Tasks</h6>
                            <p class="text-muted">No tasks have been created yet.</p>
                            <button class="btn btn-primary" onclick="showCreateTaskModal()">
                                <i class="fas fa-plus"></i> Create First Task
                            </button>
                        </div>
                        <?php else: ?>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($recent_tasks as $task): ?>
                            <div class="recent-task-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($task['title']); ?></h6>
                                        <small class="text-muted">
                                            Assigned to: <?php echo htmlspecialchars(($task['assigned_to_name'] ?? 'Unassigned') . ' ' . ($task['assigned_to_lastname'] ?? '')); ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-clock"></i>
                                            Created: <?php echo timeAgo($task['created_at']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                            <?php echo ucfirst($task['priority']); ?>
                                        </span>
                                        <br>
                                        <div class="btn-group btn-group-sm mt-1">
                                            <button class="btn btn-outline-primary btn-sm" onclick="editTask(<?php echo $task['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm" onclick="deleteTask(<?php echo $task['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Priority Tasks & Charts -->
            <div class="col-lg-4">
                <!-- Priority Tasks -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-exclamation-circle"></i> Priority Tasks</h5>
                    </div>
                    <div class="card-body notification-panel">
                        <?php if (empty($priority_tasks)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                            <p class="text-muted mb-0">No urgent tasks!</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($priority_tasks as $task): ?>
                        <div class="border-start border-3 task-priority-<?php echo $task['priority']; ?> ps-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($task['title']); ?></h6>
                                    <small class="text-muted">
                                        Assigned to: <?php echo htmlspecialchars(($task['first_name'] ?? 'Unassigned') . ' ' . ($task['last_name'] ?? '')); ?>
                                    </small>
                                    <br>
                                    <?php if ($task['due_date']): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-clock"></i>
                                        Due: <?php echo date('M j, g:i A', strtotime($task['due_date'])); ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                                <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                    <?php echo ucfirst($task['priority']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Task Distribution Chart -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Task Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="taskDistributionChart"></canvas>
                        </div>
                        <div class="row text-center mt-3">
                            <div class="col">
                                <small class="text-muted">Completion Rate</small>
                                <h4 class="text-success"><?php echo $stats['completion_rate']; ?>%</h4>
                            </div>
                            <div class="col">
                                <small class="text-muted">Today's Tasks</small>
                                <h4 class="text-primary"><?php echo $stats['tasks_today']; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Create New Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="createTaskForm" novalidate>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="taskTitle" class="form-label">Task Title *</label>
                                    <input type="text" class="form-control" id="taskTitle" required>
                                    <div class="invalid-feedback">Please provide a task title.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="taskDescription" class="form-label">Description</label>
                                    <textarea class="form-control" id="taskDescription" rows="3" 
                                              placeholder="Detailed description of the task..."></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="taskLocation" 
                                                   placeholder="Workshop Bay 1, Yard Area B, etc.">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskEquipment" class="form-label">Equipment</label>
                                            <input type="text" class="form-control" id="taskEquipment" 
                                                   placeholder="Excavator CAT 320, Mobile Crane, etc.">
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
                                        <?php foreach ($team_overview as $member): ?>
                                        <option value="<?php echo $member['id']; ?>">
                                            <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a mechanic.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="taskPriority" class="form-label">Priority *</label>
                                    <select class="form-select" id="taskPriority" required>
                                        <option value="medium">Medium</option>
                                        <option value="low">Low</option>
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
                            <label for="taskNotes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="taskNotes" rows="2" 
                                      placeholder="Any special instructions or requirements..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-manager" id="createTaskBtn">
                            <i class="fas fa-plus"></i> Create Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle"></i> Are you sure?</h6>
                        <p class="mb-0">This action cannot be undone. The task will be permanently deleted.</p>
                    </div>
                    <div id="deleteTaskDetails">
                        <!-- Task details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form id="deleteTaskForm" method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_task">
                        <input type="hidden" name="task_id" id="deleteTaskId">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Task
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Notifications Modal -->
    <div class="modal fade" id="notificationsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bell"></i> Notifications</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($notifications)): ?>
                    <div class="text-center py-3">
                        <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">No new notifications</p>
                    </div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($notifications as $notification): ?>
                        <div class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                <small><?php echo timeAgo($notification['created_at']); ?></small>
                            </div>
                            <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                            <?php if ($notification['task_title']): ?>
                            <small class="text-muted">Task: <?php echo htmlspecialchars($notification['task_title']); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <?php if (!empty($notifications)): ?>
                    <button type="button" class="btn btn-primary" onclick="markAllNotificationsRead()">
                        <i class="fas fa-check"></i> Mark All Read
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== Manager Dashboard Loading ===');
        
        // Initialize everything
        initializeDashboard();
        setupTaskForm();
        setupCharts();
        
        // Set default dates
        setDefaultDates();
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        console.log('Dashboard initialization complete');
    });
    
    function initializeDashboard() {
        // Setup tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Add click handlers for stats cards
        document.querySelectorAll('.stats-card[onclick]').forEach(card => {
            card.style.cursor = 'pointer';
        });
    }
    
    function setupTaskForm() {
        console.log('Setting up task form...');
        
        const form = document.getElementById('createTaskForm');
        if (!form) {
            console.error('❌ Form not found!');
            return;
        }
        
        console.log('✅ Form found, attaching listener');
        
        // Remove any existing listeners
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        
        // Add fresh listener
        document.getElementById('createTaskForm').addEventListener('submit', function(e) {
            console.log('🚀 Form submitted!');
            e.preventDefault();
            e.stopPropagation();
            
            if (this.checkValidity()) {
                handleTaskCreation();
            } else {
                this.classList.add('was-validated');
            }
        });
        
        console.log('✅ Event listener attached successfully');
    }
    
    function setupCharts() {
        const ctx = document.getElementById('taskDistributionChart');
        if (!ctx) return;
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Completed', 'Overdue'],
                datasets: [{
                    data: [
                        <?php echo $stats['pending_tasks']; ?>,
                        <?php echo $stats['in_progress_tasks']; ?>,
                        <?php echo $stats['completed_tasks']; ?>,
                        <?php echo $stats['overdue_tasks']; ?>
                    ],
                    backgroundColor: [
                        '#ffc107',
                        '#17a2b8',
                        '#28a745',
                        '#dc3545'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    }
                }
            }
        });
    }
    
    function setDefaultDates() {
        const now = new Date();
        const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
        
        const startDateField = document.getElementById('startDate');
        const dueDateField = document.getElementById('dueDate');
        
        if (startDateField) startDateField.value = formatDateTimeLocal(now);
        if (dueDateField) dueDateField.value = formatDateTimeLocal(tomorrow);
    }
    
    function handleTaskCreation() {
        console.log('=== Starting Task Creation ===');
        
        // Get form data
        const title = document.getElementById('taskTitle').value.trim();
        const assignedTo = parseInt(document.getElementById('assignedTo').value);
        const priority = document.getElementById('taskPriority').value;
        
        console.log('Form data:', { title, assignedTo, priority });
        
        // Validate required fields
        if (!title) {
            showToast('Please enter a task title', 'warning');
            return;
        }
        
        if (!assignedTo) {
            showToast('Please select a mechanic', 'warning');
            return;
        }
        
        // Prepare data
        const taskData = {
            action: 'create_task',
            title: title,
            description: document.getElementById('taskDescription').value.trim(),
            assigned_to: assignedTo,
            priority: priority,
            category: document.getElementById('taskCategory').value.trim(),
            location: document.getElementById('taskLocation').value.trim(),
            equipment: document.getElementById('taskEquipment').value.trim(),
            estimated_hours: parseFloat(document.getElementById('estimatedHours').value) || null,
            due_date: document.getElementById('dueDate').value || null,
            start_date: document.getElementById('startDate').value || null,
            notes: document.getElementById('taskNotes').value.trim()
        };
        
        console.log('Sending task data:', taskData);
        
        // Show loading
        const submitBtn = document.getElementById('createTaskBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        submitBtn.disabled = true;
        
        // Send to API
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
            console.log('📡 Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.text();
        })
        .then(text => {
            console.log('📄 Raw response:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('❌ JSON Parse Error:', e);
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
            
            console.log('📦 Parsed data:', data);
            
            // Reset button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            if (data.success) {
                console.log('✅ Task created successfully!');
                showToast('Task created successfully! ID: ' + (data.task_id || 'Unknown'), 'success');
                
                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('createTaskModal'));
                if (modal) modal.hide();
                
                // Reset form
                document.getElementById('createTaskForm').reset();
                document.getElementById('createTaskForm').classList.remove('was-validated');
                
                // Reload page
                setTimeout(() => {
                    console.log('🔄 Reloading page...');
                    window.location.reload();
                }, 1500);
                
            } else {
                console.error('❌ API Error:', data.message);
                showToast('Error: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('💥 Request failed:', error);
            
            // Reset button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            showToast('Network error: ' + error.message, 'danger');
        });
    }
    
    function showCreateTaskModal() {
        console.log('📝 Opening create task modal...');
        
        // Reset form
        const form = document.getElementById('createTaskForm');
        if (form) {
            form.reset();
            form.classList.remove('was-validated');
        }
        
        // Set default dates
        setDefaultDates();
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('createTaskModal'));
        modal.show();
        
        console.log('✅ Modal opened');
    }
    
    function deleteTask(taskId) {
        console.log('Preparing to delete task:', taskId);
        
        // Load task details for confirmation
        fetch(`../api/tasks.php?action=get_task&id=${taskId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.task) {
                const task = data.task;
                document.getElementById('deleteTaskDetails').innerHTML = `
                    <p><strong>Task:</strong> ${task.title}</p>
                    <p><strong>Assigned to:</strong> ${task.assigned_to_name || 'Unassigned'} ${task.assigned_to_lastname || ''}</p>
                    <p><strong>Status:</strong> ${task.status}</p>
                    <p><strong>Created:</strong> ${new Date(task.created_at).toLocaleDateString()}</p>
                `;
                
                document.getElementById('deleteTaskId').value = taskId;
                
                // Show delete modal
                const modal = new bootstrap.Modal(document.getElementById('deleteTaskModal'));
                modal.show();
            } else {
                showToast('Failed to load task details', 'danger');
            }
        })
        .catch(error => {
            console.error('Delete preparation error:', error);
            showToast('Error loading task details', 'danger');
        });
    }
    
    function editTask(taskId) {
        // Redirect to tasks page with edit parameter
        window.location.href = `tasks.php?edit=${taskId}`;
    }
    
    function assignTaskToMember(memberId) {
        // Open create task modal with pre-selected member
        showCreateTaskModal();
        setTimeout(() => {
            document.getElementById('assignedTo').value = memberId;
        }, 100);
    }
    
    function showOverdueTasks() {
        const overdueCount = <?php echo $stats['overdue_tasks']; ?>;
        if (overdueCount > 0) {
            window.location.href = 'tasks.php?overdue=1';
        } else {
            showToast('No overdue tasks! Great job!', 'success');
        }
    }
    
    function bulkAssignTasks() {
        showToast('Bulk assignment feature will be implemented soon!', 'info');
    }
    
    function showNotifications() {
        const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
        modal.show();
    }
    
    function markAllNotificationsRead() {
        showToast('Mark notifications read feature will be implemented soon!', 'info');
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
        // Remove existing toasts
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());
        
        // Create toast container if it doesn't exist
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1070';
            document.body.appendChild(container);
        }
        
        // Create toast
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0 show`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-${getToastIcon(type)}"></i> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Auto remove after duration
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, duration);
    }
    
    function getToastIcon(type) {
        const icons = {
            success: 'check-circle',
            danger: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    // Debug function for testing
    function testTaskAPI() {
        console.log('🧪 Testing API...');
        
        fetch('../api/tasks.php?action=test')
        .then(r => r.json())
        .then(data => {
            console.log('API Test Result:', data);
            showToast(data.success ? '✅ API Working!' : '❌ API Failed: ' + data.message, data.success ? 'success' : 'danger');
        })
        .catch(err => {
            console.error('API Test Error:', err);
            showToast('❌ API Test Failed: ' + err.message, 'danger');
        });
    }
    
    // Add debug controls for development
    function addDebugControls() {
        const navbar = document.querySelector('.navbar .container-fluid');
        if (navbar && window.location.hostname === 'localhost') {
            const debugGroup = document.createElement('div');
            debugGroup.className = 'd-flex gap-2 me-3';
            debugGroup.innerHTML = `
                <button class="btn btn-outline-light btn-sm" onclick="testTaskAPI()">
                    <i class="fas fa-bug"></i> Test API
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="window.open('../debug_db.php', '_blank')">
                    <i class="fas fa-database"></i> Debug DB
                </button>
            `;
            navbar.insertBefore(debugGroup, navbar.lastElementChild);
        }
    }
    
    // Initialize debug controls on localhost
    if (window.location.hostname === 'localhost') {
        setTimeout(addDebugControls, 1000);
    }
    
    // System info function
    function showSystemInfo() {
        const info = `System Information:
        
Application: <?php echo APP_NAME; ?>
Version: <?php echo APP_VERSION; ?>
Database: Connected
PHP Version: <?php echo PHP_VERSION; ?>
Server Time: <?php echo date('Y-m-d H:i:s'); ?>
User Role: <?php echo $_SESSION['role']; ?>
User ID: <?php echo $user_id; ?>

Statistics:
- Total Team Members: <?php echo $stats['total_mechanics']; ?>
- Total Tasks: <?php echo $stats['total_tasks']; ?>
- Completion Rate: <?php echo $stats['completion_rate']; ?>%
- Tasks Today: <?php echo $stats['tasks_today']; ?>`;
        
        alert(info);
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + N = New Task
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            showCreateTaskModal();
        }
        
        // Ctrl/Cmd + T = View Tasks
        if ((e.ctrlKey || e.metaKey) && e.key === 't') {
            e.preventDefault();
            window.location.href = 'tasks.php';
        }
        
        // Ctrl/Cmd + U = View Team
        if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
            e.preventDefault();
            window.location.href = 'team.php';
        }
    });
    
    // Auto-refresh dashboard every 5 minutes
    setInterval(() => {
        if (!document.hidden && !document.querySelector('.modal.show')) {
            console.log('Auto-refreshing dashboard stats...');
            fetch('../api/tasks.php?action=get_tasks&limit=1')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Could update stats here without full reload
                        console.log('Stats refreshed');
                    }
                })
                .catch(error => {
                    console.log('Auto-refresh failed:', error);
                });
        }
    }, 300000); // 5 minutes
    
    // Progressive Web App features
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/tasks/sw.js')
                .then(function(registration) {
                    console.log('ServiceWorker registration successful');
                })
                .catch(function(err) {
                    console.log('ServiceWorker registration failed');
                });
        });
    }
    
    // Handle online/offline status
    window.addEventListener('online', function() {
        showToast('Connection restored', 'success');
    });
    
    window.addEventListener('offline', function() {
        showToast('You are offline', 'warning');
    });
    
    // Performance monitoring
    window.addEventListener('load', function() {
        setTimeout(() => {
            if (window.performance) {
                const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
                console.log(`Page load time: ${loadTime}ms`);
                
                if (loadTime > 3000) {
                    console.warn('Slow page load detected');
                }
            }
        }, 100);
    });
    
    // Export dashboard data
    function exportDashboardData() {
        const data = {
            stats: <?php echo json_encode($stats); ?>,
            team: <?php echo json_encode($team_overview); ?>,
            recent_tasks: <?php echo json_encode($recent_tasks); ?>,
            priority_tasks: <?php echo json_encode($priority_tasks); ?>,
            exported_at: new Date().toISOString(),
            exported_by: '<?php echo htmlspecialchars($manager['first_name'] . ' ' . $manager['last_name']); ?>'
        };
        
        const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `dashboard-export-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast('Dashboard data exported successfully', 'success');
    }
    
    // Global error handler
    window.addEventListener('error', function(e) {
        console.error('Global error:', e.error);
        if (window.location.hostname === 'localhost') {
            showToast('JavaScript error: ' + e.message, 'danger');
        }
    });
    
    // Unhandled promise rejection handler
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Unhandled promise rejection:', e.reason);
        if (window.location.hostname === 'localhost') {
            showToast('Promise rejection: ' + e.reason, 'danger');
        }
    });
    
    console.log('🎉 Manager Dashboard fully loaded and initialized');
    </script>
</body>
</html>