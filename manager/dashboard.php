<?php
/**
 * FIXED Manager Dashboard with Task Creation Modal
 * Replace the entire /var/www/tasks/manager/dashboard.php
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

// Handle filtering by clicking on stats cards
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$assigned_filter = isset($_GET['assigned']) ? sanitizeInput($_GET['assigned']) : 'all';

// Build filter conditions
$task_where = "1 = 1";
$task_params = [];
$problem_where = "1 = 1";
$problem_params = [];

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

if ($assigned_filter !== 'all') {
    if ($assigned_filter === 'unassigned') {
        $task_where .= " AND t.assigned_to IS NULL";
        $problem_where .= " AND p.assigned_to IS NULL";
    }
}

// Get comprehensive statistics with error handling
try {
    $stats = [
        'total_mechanics' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'mechanic' AND is_active = 1"),
        'total_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where}", $task_params),
        'pending_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND t.status = 'pending'", array_merge($task_params, ['pending'])),
        'in_progress_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND t.status = 'in_progress'", array_merge($task_params, ['in_progress'])),
        'completed_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND t.status = 'completed'", array_merge($task_params, ['completed'])),
        'overdue_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled')", $task_params),
        'urgent_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND t.priority = 'urgent' AND t.status NOT IN ('completed', 'cancelled')", array_merge($task_params, ['urgent'])),
        'tasks_today' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND DATE(t.created_at) = CURDATE()", $task_params),
        'completed_today' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$task_where} AND DATE(t.completed_date) = CURDATE()", $task_params),
        
        // Problem statistics
        'total_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$problem_where}", $problem_params),
        'reported_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$problem_where} AND p.status = 'reported'", array_merge($problem_params, ['reported'])),
        'urgent_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$problem_where} AND p.priority = 'urgent' AND p.status NOT IN ('resolved', 'closed')", array_merge($problem_params, ['urgent'])),
        'problems_today' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$problem_where} AND DATE(p.created_at) = CURDATE()", $problem_params),
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
                COUNT(DISTINCT t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_tasks,
                SUM(CASE WHEN DATE(t.completed_date) = CURDATE() THEN 1 ELSE 0 END) as completed_today
         FROM users u
         LEFT JOIN tasks t ON u.id = t.assigned_to AND {$task_where}
         WHERE u.role = 'mechanic' AND u.is_active = 1
         GROUP BY u.id, u.first_name, u.last_name, u.last_login, u.email
         ORDER BY u.first_name, u.last_name",
        $task_params
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
         WHERE {$task_where}
         ORDER BY t.created_at DESC 
         LIMIT 8",
        $task_params
    );
} catch (Exception $e) {
    error_log("Manager recent tasks error: " . $e->getMessage());
    $recent_tasks = [];
}

// Get recent problems
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

// Get all mechanics for task assignment
$mechanics = $db->fetchAll(
    "SELECT id, first_name, last_name FROM users 
     WHERE role = 'mechanic' AND is_active = 1 
     ORDER BY first_name, last_name"
);

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
        
        .stats-card.active {
            border: 3px solid var(--manager-primary);
            transform: translateY(-3px);
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
        
        .filter-info {
            background: #e3f2fd;
            border: 1px solid #1976d2;
            color: #1976d2;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
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
    <nav class="navbar navbar-dark manager-navbar">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-users-cog"></i> Manager Dashboard
            </span>
            <div class="d-flex align-items-center">
                <span class="me-3">Welcome, <?php echo htmlspecialchars($manager['first_name']); ?>!</span>
                
                <!-- Problems Alert Badge -->
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
        
        <!-- Filter Information -->
        <?php if ($status_filter !== 'all' || $assigned_filter !== 'all'): ?>
        <div class="filter-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-filter"></i>
                    <strong>Active Filters:</strong>
                    <?php if ($status_filter !== 'all'): ?>
                    Status: <span class="badge bg-primary"><?php echo ucfirst(str_replace('_', ' ', $status_filter)); ?></span>
                    <?php endif; ?>
                    <?php if ($assigned_filter !== 'all'): ?>
                    Assignment: <span class="badge bg-primary"><?php echo ucfirst($assigned_filter); ?></span>
                    <?php endif; ?>
                </div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Key Metrics Row with Clickable Filtering -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative" onclick="filterDashboard('members')">
                    <div class="stats-icon primary mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_mechanics']; ?></h3>
                    <p class="text-muted mb-0">Team Members</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative <?php echo $status_filter === 'all' ? 'active' : ''; ?>" onclick="filterDashboard('all')">
                    <div class="stats-icon info mx-auto">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['total_tasks']; ?></h3>
                    <p class="text-muted mb-0">Total Tasks</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative <?php echo $status_filter === 'reported' ? 'active' : ''; ?>" onclick="filterDashboard('problems')">
                    <div class="stats-icon operator mx-auto">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['total_problems']; ?></h3>
                    <p class="text-muted mb-0">Problems</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" onclick="filterDashboard('pending')">
                    <div class="stats-icon warning mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['reported_problems']; ?></h3>
                    <p class="text-muted mb-0">Need Assignment</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" onclick="filterDashboard('completed')">
                    <div class="stats-icon success mx-auto">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['completed_tasks']; ?></h3>
                    <p class="text-muted mb-0">Completed</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 position-relative <?php echo $status_filter === 'overdue' ? 'active' : ''; ?>" onclick="filterDashboard('overdue')">
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
        
        <!-- Urgent Problems Alert Section -->
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
                                    <button class="btn btn-primary" onclick="assignProblem(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-user-plus"></i> Assign
                                    </button>
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
        
        <!-- Rest of dashboard content... (team overview, recent tasks, etc.) -->
        <!-- ... [Previous dashboard content remains the same] ... -->
        
    </div>
    
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--manager-primary); color: white;">
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
    
    <!-- Assign Problem Modal -->
    <div class="modal fade" id="assignProblemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--manager-primary); color: white;">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Problem</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="assignProblemForm">
                    <input type="hidden" id="problemId">
                    <div class="modal-body">
                        <div id="problemDetails">
                            <!-- Problem details will be loaded here -->
                        </div>
                        <div class="mb-3">
                            <label for="assignMechanic" class="form-label">Select Mechanic *</label>
                            <select class="form-select" id="assignMechanic" required>
                                <option value="">Choose a mechanic...</option>
                                <?php foreach ($mechanics as $mechanic): ?>
                                <option value="<?php echo $mechanic['id']; ?>">
                                    <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            The selected mechanic will be notified about this assignment.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Assign Problem
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== FIXED Manager Dashboard Loading ===');
        
        // Check for urgent problems and show alerts
        const urgentProblems = <?php echo $stats['urgent_problems']; ?>;
        if (urgentProblems > 0) {
            setTimeout(() => {
                showToast(`⚠️ ${urgentProblems} urgent problem(s) require immediate attention!`, 'warning', 8000);
            }, 2000);
        }
        
        // Setup event listeners
        setupEventListeners();
        
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
    
    function setupEventListeners() {
        // Create task form submission
        const createForm = document.getElementById('createTaskForm');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                e.preventDefault();
                createNewTask();
            });
        }
        
        // Assign problem form submission
        const assignForm = document.getElementById('assignProblemForm');
        if (assignForm) {
            assignForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitProblemAssignment();
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
    
    function filterDashboard(filterType) {
        let url = 'dashboard.php?';
        
        switch(filterType) {
            case 'members':
                window.location.href = 'team.php';
                return;
            case 'problems':
                window.location.href = 'problems.php';
                return;
            case 'all':
                url += 'status=all';
                break;
            case 'pending':
                url += 'status=pending';
                break;
            case 'completed':
                url += 'status=completed';
                break;
            case 'overdue':
                url += 'status=overdue';
                break;
            default:
                return;
        }
        
        window.location.href = url;
    }
    
    function showCreateTaskModal() {
        console.log('Opening task creation modal...');
        
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
            title: document.getElementById('taskTitle').value,
            description: document.getElementById('taskDescription').value,
            location: document.getElementById('taskLocation').value,
            equipment: document.getElementById('taskEquipment').value,
            category: document.getElementById('taskCategory').value,
            estimated_hours: parseFloat(document.getElementById('estimatedHours').value) || null,
            assigned_to: parseInt(document.getElementById('assignedTo').value),
            priority: document.getElementById('taskPriority').value,
            due_date: document.getElementById('dueDate').value || null,
            start_date: document.getElementById('startDate').value || null,
            notes: document.getElementById('taskNotes').value
        };
        
        console.log('Creating task with data:', taskData);
        
        // Validate required fields
        if (!taskData.title || !taskData.assigned_to) {
            showToast('Please fill in all required fields', 'warning');
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
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(data => {
            console.log('Task creation response:', data);
            
            // Reset button
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
            
            if (data.success) {
                showToast('Task created successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('createTaskModal')).hide();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('Task creation error:', error);
            showToast('Error creating task: ' + error.message, 'danger');
            
            // Reset button
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
        });
    }
    
    function assignProblem(problemId) {
        console.log('Assigning problem:', problemId);
        
        document.getElementById('problemId').value = problemId;
        
        // Load problem details
        document.getElementById('problemDetails').innerHTML = `
            <div class="alert alert-light">
                <h6><i class="fas fa-exclamation-triangle"></i> Problem #${problemId}</h6>
                <p class="mb-0">Select a mechanic to assign this problem to.</p>
            </div>
        `;
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('assignProblemModal'));
        modal.show();
    }
    
    function submitProblemAssignment() {
        const problemId = document.getElementById('problemId').value;
        const mechanicId = document.getElementById('assignMechanic').value;
        
        if (!mechanicId) {
            showToast('Please select a mechanic', 'warning');
            return;
        }
        
        // Submit assignment (this would normally go to problems.php)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'problems.php';
        
        const actionField = document.createElement('input');
        actionField.type = 'hidden';
        actionField.name = 'action';
        actionField.value = 'assign_problem';
        
        const problemField = document.createElement('input');
        problemField.type = 'hidden';
        problemField.name = 'problem_id';
        problemField.value = problemId;
        
        const mechanicField = document.createElement('input');
        mechanicField.type = 'hidden';
        mechanicField.name = 'assigned_to';
        mechanicField.value = mechanicId;
        
        form.appendChild(actionField);
        form.appendChild(problemField);
        form.appendChild(mechanicField);
        
        document.body.appendChild(form);
        form.submit();
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
    
    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
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