<?php
/**
 * Manager Dashboard - Complete Interface
 * Task Management System
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

// Get comprehensive statistics
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

// Get team overview
try {
    $team_overview = $db->fetchAll(
        "SELECT u.id, u.first_name, u.last_name, u.last_login,
                COUNT(t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_tasks
         FROM users u
         LEFT JOIN tasks t ON u.id = t.assigned_to
         WHERE u.role = 'mechanic' AND u.is_active = 1
         GROUP BY u.id, u.first_name, u.last_name, u.last_login
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

// Add this after your existing $recent_tasks query
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
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- Alert Messages -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Key Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative">
                    <div class="stats-icon primary mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_mechanics']; ?></h3>
                    <p class="text-muted mb-0">Team Members</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative">
                    <div class="stats-icon info mx-auto">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['total_tasks']; ?></h3>
                    <p class="text-muted mb-0">Total Tasks</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative">
                    <div class="stats-icon warning mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['pending_tasks']; ?></h3>
                    <p class="text-muted mb-0">Pending</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative">
                    <div class="stats-icon primary mx-auto">
                        <i class="fas fa-play"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['in_progress_tasks']; ?></h3>
                    <p class="text-muted mb-0">In Progress</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative">
                    <div class="stats-icon success mx-auto">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['completed_tasks']; ?></h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative">
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
                                <a href="#" onclick="showAssignmentModal()" 
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
                                                        Last seen: <?php echo date('M j, g:i A', strtotime($member['last_login'])); ?>
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
                                                           onclick="assignTask(<?php echo $member['id']; ?>)">
                                                        <i class="fas fa-plus"></i> Assign Task</a></li>
                                                    <li><a class="dropdown-item" href="#" 
                                                           onclick="viewMemberTasks(<?php echo $member['id']; ?>)">
                                                        <i class="fas fa-tasks"></i> View Tasks</a></li>
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
                                        <?php echo date('M j, g:i A', strtotime($task['due_date'])); ?>
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
                <form id="createTaskForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="taskTitle" class="form-label">Task Title *</label>
                                    <input type="text" class="form-control" id="taskTitle" required>
                                </div>
                                <div class="mb-3">
                                    <label for="taskDescription" class="form-label">Description</label>
                                    <textarea class="form-control" id="taskDescription" rows="3"></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="taskLocation" placeholder="Workshop Bay 1, Yard Area B, etc.">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskEquipment" class="form-label">Equipment</label>
                                            <input type="text" class="form-control" id="taskEquipment" placeholder="Excavator CAT 320, Mobile Crane, etc.">
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
                                            <input type="number" class="form-control" id="estimatedHours" min="0.5" step="0.5" placeholder="2.5">
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
                            <textarea class="form-control" id="taskNotes" rows="2" placeholder="Any special instructions or requirements..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-manager">
                            <i class="fas fa-plus"></i> Create Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assignment Modal -->
    <div class="modal fade" id="assignmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Bulk Task Assignment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Assign multiple pending tasks to team members</p>
                    <div class="mb-3">
                        <label for="bulkAssignTo" class="form-label">Assign To</label>
                        <select class="form-select" id="bulkAssignTo">
                            <option value="">Select Mechanic</option>
                            <?php foreach ($team_overview as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                (<?php echo $member['pending_tasks'] + $member['in_progress_tasks']; ?> active tasks)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="pendingTasksList">
                        <!-- Will be populated via AJAX -->
                        <div class="text-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-manager" onclick="processBulkAssignment()">
                        <i class="fas fa-check"></i> Assign Selected
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    

<!-- Replace the entire <script> at the bottom of manager/dashboard.php -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== Manager Dashboard Loading ===');
    
    // Initialize everything
    initializeDashboard();
    setupTaskForm();
    
    // Set default dates
    const now = new Date();
    const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
    
    const startDateField = document.getElementById('startDate');
    const dueDateField = document.getElementById('dueDate');
    
    if (startDateField) startDateField.value = formatDateTimeLocal(now);
    if (dueDateField) dueDateField.value = formatDateTimeLocal(tomorrow);
    
    console.log('Dashboard initialization complete');
});

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
        
        handleTaskCreation();
    });
    
    console.log('✅ Event listener attached successfully');
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
        alert('Please enter a task title');
        return;
    }
    
    if (!assignedTo) {
        alert('Please select a mechanic');
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
    const submitBtn = document.querySelector('#createTaskForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Creating...';
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
            alert('✅ Task created successfully! ID: ' + (data.task_id || 'Unknown'));
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('createTaskModal'));
            if (modal) modal.hide();
            
            // Reset form
            document.getElementById('createTaskForm').reset();
            
            // Reload page
            setTimeout(() => {
                console.log('🔄 Reloading page...');
                window.location.reload();
            }, 1000);
            
        } else {
            console.error('❌ API Error:', data.message);
            alert('❌ Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('💥 Request failed:', error);
        
        // Reset button
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        alert('💥 Network error: ' + error.message);
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
    const now = new Date();
    const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
    
    const startDateField = document.getElementById('startDate');
    const dueDateField = document.getElementById('dueDate');
    
    if (startDateField) startDateField.value = formatDateTimeLocal(now);
    if (dueDateField) dueDateField.value = formatDateTimeLocal(tomorrow);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('createTaskModal'));
    modal.show();
    
    console.log('✅ Modal opened');
}

function formatDateTimeLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function initializeDashboard() {
    // Keep your existing chart and other initialization code here
    console.log('Dashboard components initialized');
}

// Debug button for testing
function testTaskAPI() {
    console.log('🧪 Testing API...');
    
    fetch('../api/tasks.php?action=test')
    .then(r => r.json())
    .then(data => {
        console.log('API Test Result:', data);
        alert(data.success ? '✅ API Working!' : '❌ API Failed: ' + data.message);
    })
    .catch(err => {
        console.error('API Test Error:', err);
        alert('❌ API Test Failed: ' + err.message);
    });
}

// Add test button to navbar
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        const navbar = document.querySelector('.navbar .container-fluid');
        if (navbar) {
            const testBtn = document.createElement('button');
            testBtn.className = 'btn btn-outline-light btn-sm me-2';
            testBtn.innerHTML = '🧪 Test API';
            testBtn.onclick = testTaskAPI;
            navbar.appendChild(testBtn);
        }
    }, 1000);
});
</script>

</body>
</html>
