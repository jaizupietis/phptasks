<?php
/**
 * Enhanced Manager Dashboard - UPDATED with Problems Navigation
 * Task Management System - Fixed and Enhanced Version
 * Update: /var/www/tasks/manager/dashboard.php
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
        
        // ADDED: Problem statistics
        'total_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems"),
        'reported_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE status = 'reported'"),
        'urgent_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE priority = 'urgent' AND status NOT IN ('resolved', 'closed')"),
        'problems_today' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE DATE(created_at) = CURDATE()"),
    ];
    
    // Calculate completion rate
    $stats['completion_rate'] = $stats['total_tasks'] > 0 ? 
        round(($stats['completed_tasks'] / $stats['total_tasks']) * 100, 1) : 0;
        
} catch (Exception $e) {
    error_log("Manager dashboard stats error: " . $e->getMessage());
    $stats = array_fill_keys(['total_mechanics', 'total_tasks', 'pending_tasks', 'in_progress_tasks', 
                             'completed_tasks', 'overdue_tasks', 'urgent_tasks', 'tasks_today', 
                             'completed_today', 'completion_rate', 'total_problems', 'reported_problems',
                             'urgent_problems', 'problems_today'], 0);
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
         LIMIT 8"
    );
} catch (Exception $e) {
    error_log("Manager recent tasks error: " . $e->getMessage());
    $recent_tasks = [];
}

// ADDED: Get recent problems
try {
    $recent_problems = $db->fetchAll(
        "SELECT p.*, 
                ur.first_name as reported_by_name, ur.last_name as reported_by_lastname,
                ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname
         FROM problems p 
         LEFT JOIN users ur ON p.reported_by = ur.id 
         LEFT JOIN users ua ON p.assigned_to = ua.id 
         WHERE p.status IN ('reported', 'assigned')
         ORDER BY 
            CASE WHEN p.priority = 'urgent' THEN 1 ELSE 2 END,
            p.created_at DESC 
         LIMIT 5"
    );
} catch (Exception $e) {
    error_log("Manager recent problems error: " . $e->getMessage());
    $recent_problems = [];
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
            --operator-primary: #17a2b8;
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
        .stats-icon.operator { background: linear-gradient(135deg, var(--operator-primary), #138496); }
        
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
        
        .problem-alert {
            border-left: 4px solid var(--operator-primary);
            background: #f8f9fa;
            margin-bottom: 0.5rem;
            padding: 0.75rem;
            border-radius: 5px;
        }
        
        .problem-alert.urgent {
            border-left-color: var(--danger-color);
            background: #fff5f5;
        }
        
        .problem-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .problem-urgent { background: var(--danger-color); color: white; }
        .problem-high { background: #fd7e14; color: white; }
        .problem-medium { background: var(--warning-color); color: #212529; }
        .problem-low { background: var(--success-color); color: white; }
        
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
                
                <!-- ADDED: Problems Alert Badge -->
                <?php if ($stats['reported_problems'] > 0): ?>
                <a href="problems.php" class="btn btn-outline-light btn-sm me-2 position-relative">
                    <i class="fas fa-exclamation-triangle"></i> Problems
                    <span class="notification-badge"><?php echo $stats['reported_problems']; ?></span>
                </a>
                <?php endif; ?>
                
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
                        <!-- ADDED: Problems link in dropdown -->
                        <li><a class="dropdown-item" href="problems.php">
                            <i class="fas fa-exclamation-triangle"></i> Manage Problems</a></li>
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
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- UPDATED: Key Metrics Row with Problems -->
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
            
            <!-- ADDED: Problems Statistics -->
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='problems.php'">
                    <div class="stats-icon operator mx-auto">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['total_problems']; ?></h3>
                    <p class="text-muted mb-0">Problems</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="window.location.href='problems.php?status=reported'">
                    <div class="stats-icon warning mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['reported_problems']; ?></h3>
                    <p class="text-muted mb-0">Need Assignment</p>
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
                            <div class="col-lg-2 col-md-6">
                                <a href="#" onclick="showCreateTaskModal()" 
                                   class="btn btn-success w-100 quick-action-btn">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Create New Task</span>
                                </a>
                            </div>
                            <!-- ADDED: Problems Management -->
                            <div class="col-lg-2 col-md-6">
                                <a href="problems.php" class="btn btn-warning w-100 quick-action-btn">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Manage Problems</span>
                                    <?php if ($stats['reported_problems'] > 0): ?>
                                    <small class="d-block mt-1"><?php echo $stats['reported_problems']; ?> waiting</small>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="team.php" class="btn btn-primary w-100 quick-action-btn">
                                    <i class="fas fa-users"></i>
                                    <span>Manage Team</span>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="tasks.php" class="btn btn-info w-100 quick-action-btn">
                                    <i class="fas fa-tasks"></i>
                                    <span>View Tasks</span>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="reports.php" class="btn btn-secondary w-100 quick-action-btn">
                                    <i class="fas fa-chart-line"></i>
                                    <span>View Reports</span>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="#" onclick="bulkAssignTasks()" 
                                   class="btn btn-outline-primary w-100 quick-action-btn">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Bulk Assignment</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ADDED: Urgent Problems Alert Section -->
        <?php if (!empty($recent_problems)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Problems Requiring Attention 
                            <span class="badge bg-dark"><?php echo count($recent_problems); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($recent_problems as $problem): ?>
                        <div class="problem-alert <?php echo $problem['priority'] === 'urgent' ? 'urgent' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">
                                        <?php echo htmlspecialchars($problem['title']); ?>
                                        <span class="problem-badge problem-<?php echo $problem['priority']; ?>">
                                            <?php echo ucfirst($problem['priority']); ?>
                                        </span>
                                    </h6>
                                    <small class="text-muted">
                                        Reported by: <?php echo htmlspecialchars($problem['reported_by_name'] . ' ' . $problem['reported_by_lastname']); ?>
                                        • <?php echo timeAgo($problem['created_at']); ?>
                                        <?php if ($problem['location']): ?>
                                        • Location: <?php echo htmlspecialchars($problem['location']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <a href="problems.php?problem_id=<?php echo $problem['id']; ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($problem['status'] === 'reported'): ?>
                                    <a href="problems.php?assign=<?php echo $problem['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-user-plus"></i> Assign
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="problems.php" class="btn btn-warning">
                                <i class="fas fa-exclamation-triangle"></i> View All Problems
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
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
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-1">
                                                    <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?php if ($member['last_login']): ?>
                                                        Last seen: <?php echo timeAgo($member['last_login']); ?>
                                                    <?php else: ?>
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
                            <div class="card mb-2">
                                <div class="card-body p-3">
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
                                            <span class="badge bg-<?php echo $task['status'] === 'completed' ? 'success' : ($task['status'] === 'in_progress' ? 'primary' : 'secondary'); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                            </span>
                                            <br>
                                            <span class="badge bg-<?php echo $task['priority'] === 'urgent' ? 'danger' : ($task['priority'] === 'high' ? 'warning' : 'info'); ?> mt-1">
                                                <?php echo ucfirst($task['priority']); ?>
                                            </span>
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
            
            <!-- Sidebar with Charts and Summary -->
            <div class="col-lg-4">
                <!-- Daily Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-day"></i> Today's Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h4 class="text-primary"><?php echo $stats['tasks_today']; ?></h4>
                                <small class="text-muted">Tasks Created</small>
                            </div>
                            <div class="col-6">
                                <h4 class="text-success"><?php echo $stats['completed_today']; ?></h4>
                                <small class="text-muted">Tasks Completed</small>
                            </div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <h4 class="text-warning"><?php echo $stats['problems_today']; ?></h4>
                                <small class="text-muted">Problems Reported</small>
                            </div>
                            <div class="col-6">
                                <h4 class="text-info"><?php echo $stats['completion_rate']; ?>%</h4>
                                <small class="text-muted">Completion Rate</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Quick Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Tasks in Progress</span>
                                <span class="fw-bold"><?php echo $stats['in_progress_tasks']; ?></span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-primary" style="width: <?php echo $stats['total_tasks'] > 0 ? ($stats['in_progress_tasks'] / $stats['total_tasks']) * 100 : 0; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Pending Tasks</span>
                                <span class="fw-bold"><?php echo $stats['pending_tasks']; ?></span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-warning" style="width: <?php echo $stats['total_tasks'] > 0 ? ($stats['pending_tasks'] / $stats['total_tasks']) * 100 : 0; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Problems Awaiting</span>
                                <span class="fw-bold text-warning"><?php echo $stats['reported_problems']; ?></span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-warning" style="width: <?php echo $stats['total_problems'] > 0 ? ($stats['reported_problems'] / $stats['total_problems']) * 100 : 0; ?>%"></div>
                            </div>
                        </div>
                        
                        <?php if ($stats['urgent_problems'] > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong><?php echo $stats['urgent_problems']; ?> urgent problem(s)</strong> need immediate attention!
                            <br>
                            <a href="problems.php?priority=urgent" class="btn btn-sm btn-warning mt-2">
                                View Urgent Problems
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Existing Modals and JavaScript remain the same -->
    <!-- ... (previous modal and JavaScript code) ... -->
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    
    <script>
    // Enhanced dashboard with problems support
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== Enhanced Manager Dashboard Loading ===');
        
        // Check for urgent problems and show alerts
        const urgentProblems = <?php echo $stats['urgent_problems']; ?>;
        if (urgentProblems > 0) {
            setTimeout(() => {
                showToast(`⚠️ ${urgentProblems} urgent problem(s) require immediate attention!`, 'warning', 8000);
            }, 2000);
        }
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        console.log('Dashboard stats:', <?php echo json_encode($stats); ?>);
    });
    
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
        toast.className = `toast align-items-center text-white bg-${type} border-0 show`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, duration);
    }
    
    function showCreateTaskModal() {
        // Redirect to existing task creation
        window.location.href = 'tasks.php#create';
    }
    
    function assignTaskToMember(memberId) {
        window.location.href = `tasks.php?assign_to=${memberId}`;
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
        showToast('Bulk assignment feature available in Tasks page', 'info');
        setTimeout(() => {
            window.location.href = 'tasks.php#bulk';
        }, 1500);
    }
    
    function showNotifications() {
        showToast('Notification center coming soon!', 'info');
    }
    </script>
</body>
</html>