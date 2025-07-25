<?php
/**
 * COMPLETE FIXED Manager Tasks Page
 * This fixes: task deletion, edit functionality, status thumbnail sizing, and other issues
 * Replace the entire content of /var/www/tasks/manager/tasks.php
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

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$priority_filter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : 'all';
$assigned_to_filter = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build WHERE clause for filters
$where_conditions = ["1 = 1"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "t.priority = ?";
    $params[] = $priority_filter;
}

if ($assigned_to_filter > 0) {
    $where_conditions[] = "t.assigned_to = ?";
    $params[] = $assigned_to_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(t.title LIKE ? OR t.description LIKE ? OR t.location LIKE ? OR t.equipment LIKE ?)";
    $search_param = "%{$search}%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

$where_clause = implode(' AND ', $where_conditions);

// Get tasks with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = $is_mobile ? 8 : 15;
$offset = ($page - 1) * $per_page;

$tasks = $db->fetchAll(
    "SELECT t.*, 
            ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname, ua.email as assigned_to_email,
            ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname
     FROM tasks t 
     LEFT JOIN users ua ON t.assigned_to = ua.id 
     LEFT JOIN users ub ON t.assigned_by = ub.id 
     WHERE {$where_clause}
     ORDER BY 
        CASE 
            WHEN t.status = 'in_progress' THEN 1 
            WHEN t.priority = 'urgent' THEN 2 
            WHEN t.priority = 'high' THEN 3 
            ELSE 4 
        END,
        t.due_date ASC,
        t.created_at DESC 
     LIMIT {$per_page} OFFSET {$offset}",
    $params
);

// Get total count for pagination
$total_tasks = $db->fetchCount(
    "SELECT COUNT(*) FROM tasks t 
     LEFT JOIN users ua ON t.assigned_to = ua.id 
     WHERE {$where_clause}",
    $params
);

$total_pages = ceil($total_tasks / $per_page);

// Get all mechanics for filter dropdown
$mechanics = $db->fetchAll(
    "SELECT id, first_name, last_name FROM users 
     WHERE role = 'mechanic' AND is_active = 1 
     ORDER BY first_name, last_name"
);

// Get task statistics for current filters
$task_stats = [
    'total' => $total_tasks,
    'pending' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$where_clause} AND t.status = 'pending'", $params),
    'in_progress' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$where_clause} AND t.status = 'in_progress'", $params),
    'completed' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$where_clause} AND t.status = 'completed'", $params),
    'overdue' => $db->fetchCount("SELECT COUNT(*) FROM tasks t WHERE {$where_clause} AND t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled')", $params)
];

$page_title = 'Task Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    
    <style>
        :root {
            --manager-primary: #6f42c1;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background: #f8f9fa;
        }
        
        .manager-navbar {
            background: linear-gradient(135deg, var(--manager-primary) 0%, #5a32a3 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .task-card {
            border-left: 4px solid var(--manager-primary);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .task-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .task-card.priority-urgent { border-left-color: var(--danger-color); }
        .task-card.priority-high { border-left-color: #fd7e14; }
        .task-card.priority-medium { border-left-color: var(--warning-color); }
        .task-card.priority-low { border-left-color: var(--success-color); }
        
        .filter-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        /* FIXED: Uniform stats card sizing */
        .stats-mini {
            text-align: center;
            padding: 1.5rem 1rem;
            border-radius: 10px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 1rem;
            height: 120px; /* Fixed height */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .stats-mini h4 {
            margin: 0.5rem 0;
            font-weight: 700;
            font-size: 1.8rem;
            line-height: 1.2;
        }
        
        .stats-mini small {
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
        }
        
        .stats-mini.total { border-top: 4px solid var(--manager-primary); }
        .stats-mini.pending { border-top: 4px solid var(--warning-color); }
        .stats-mini.progress { border-top: 4px solid var(--info-color); }
        .stats-mini.completed { border-top: 4px solid var(--success-color); }
        .stats-mini.overdue { border-top: 4px solid var(--danger-color); }
        
        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin: 1rem 0;
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .task-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .priority-badge, .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-urgent { background: var(--danger-color); color: white; }
        .priority-high { background: #fd7e14; color: white; }
        .priority-medium { background: var(--warning-color); color: #212529; }
        .priority-low { background: var(--success-color); color: white; }
        
        .status-pending { background: #6c757d; color: white; }
        .status-in_progress { background: var(--info-color); color: white; }
        .status-completed { background: var(--success-color); color: white; }
        .status-cancelled { background: var(--danger-color); color: white; }
        .status-on_hold { background: var(--warning-color); color: #212529; }
        
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
        
        .task-actions .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box .form-control {
            padding-left: 2.5rem;
        }
        
        .search-box .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .bulk-actions {
            background: #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: none;
        }
        
        .bulk-actions.show {
            display: block;
        }
        
        .task-checkbox {
            transform: scale(1.2);
        }
        
        .modal-header {
            background: var(--manager-primary);
            color: white;
        }
        
        .delete-confirm {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 5px;
            margin: 1rem 0;
        }
        
        @media (max-width: 768px) {
            .task-actions {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .task-actions .btn {
                margin: 0;
                flex: 1;
            }
            
            .task-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .stats-mini {
                height: 100px;
                padding: 1rem 0.5rem;
            }
            
            .stats-mini h4 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark manager-navbar">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-3">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <span class="navbar-brand mb-0 h1">
                    <i class="fas fa-tasks"></i> Task Management
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-light btn-sm" onclick="testAPI()">
                    <i class="fas fa-bug"></i> Test API
                </button>
                <button class="btn btn-success btn-sm" onclick="showCreateTaskModal()">
                    <i class="fas fa-plus"></i> New Task
                </button>
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
        
        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- FIXED: Statistics Row with uniform sizing -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini total">
                    <h4 class="text-primary"><?php echo $task_stats['total']; ?></h4>
                    <small>Total Tasks</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini pending">
                    <h4 class="text-warning"><?php echo $task_stats['pending']; ?></h4>
                    <small>Pending</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini progress">
                    <h4 class="text-info"><?php echo $task_stats['in_progress']; ?></h4>
                    <small>In Progress</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini completed">
                    <h4 class="text-success"><?php echo $task_stats['completed']; ?></h4>
                    <small>Completed</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini overdue">
                    <h4 class="text-danger"><?php echo $task_stats['overdue']; ?></h4>
                    <small>Overdue</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini">
                    <h4 class="text-primary"><?php echo count($tasks); ?></h4>
                    <small>Showing</small>
                </div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filter-section p-4">
            <form method="GET" class="row g-3">
                <div class="col-lg-3 col-md-6">
                    <label for="search" class="form-label">Search Tasks</label>
                    <div class="search-box">
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by title, description, location...">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="on_hold" <?php echo $status_filter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label for="priority" class="form-label">Priority</label>
                    <select class="form-select" id="priority" name="priority">
                        <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                        <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="assigned_to" class="form-label">Assigned To</label>
                    <select class="form-select" id="assigned_to" name="assigned_to">
                        <option value="0">All Mechanics</option>
                        <?php foreach ($mechanics as $mechanic): ?>
                        <option value="<?php echo $mechanic['id']; ?>" 
                                <?php echo $assigned_to_filter === (int)$mechanic['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-manager">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="tasks.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Bulk Actions -->
        <div class="bulk-actions" id="bulkActions">
            <div class="d-flex justify-content-between align-items-center">
                <span id="selectedCount">0 tasks selected</span>
                <div class="btn-group">
                    <button class="btn btn-sm btn-warning" onclick="bulkUpdateStatus('in_progress')">
                        <i class="fas fa-play"></i> Start Selected
                    </button>
                    <button class="btn btn-sm btn-success" onclick="bulkUpdateStatus('completed')">
                        <i class="fas fa-check"></i> Complete Selected
                    </button>
                    <button class="btn btn-sm btn-secondary" onclick="bulkUpdateStatus('on_hold')">
                        <i class="fas fa-pause"></i> Hold Selected
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Tasks List -->
        <div class="row">
            <div class="col-12">
                <?php if (empty($tasks)): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="text-center py-5">
                            <i class="fas fa-tasks fa-4x text-muted mb-3"></i>
                            <h5>No Tasks Found</h5>
                            <p class="text-muted">No tasks match your current filters.<br>Try adjusting the filters above or create a new task.</p>
                            <button class="btn btn-manager" onclick="showCreateTaskModal()">
                                <i class="fas fa-plus"></i> Create New Task
                            </button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center">
                        <div class="form-check me-3">
                            <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleAllTasks(this)">
                            <label class="form-check-label" for="selectAll">Select All</label>
                        </div>
                        <h6 class="text-muted mb-0">
                            Showing <?php echo count($tasks); ?> of <?php echo $total_tasks; ?> tasks
                        </h6>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></small>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="exportTasks()">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <button class="btn btn-outline-secondary" onclick="refreshTasks()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Task Cards -->
                <?php foreach ($tasks as $task): ?>
                <div class="card task-card priority-<?php echo $task['priority']; ?>" data-task-id="<?php echo $task['id']; ?>">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-auto">
                                <div class="form-check mt-1">
                                    <input class="form-check-input task-checkbox" type="checkbox" 
                                           value="<?php echo $task['id']; ?>" onchange="updateSelectedCount()">
                                </div>
                            </div>
                            <div class="col">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="card-title mb-1">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                            <?php if ($task['due_date'] && strtotime($task['due_date']) < time() && 
                                                     !in_array($task['status'], ['completed', 'cancelled'])): ?>
                                            <span class="badge bg-danger ms-2">
                                                <i class="fas fa-exclamation-triangle"></i> OVERDUE
                                            </span>
                                            <?php endif; ?>
                                        </h5>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="status-badge status-<?php echo $task['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                            </span>
                                            <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                                <?php echo ucfirst($task['priority']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="editTask(<?php echo $task['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit Task</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="duplicateTask(<?php echo $task['id']; ?>)">
                                                <i class="fas fa-copy"></i> Duplicate</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="viewHistory(<?php echo $task['id']; ?>)">
                                                <i class="fas fa-history"></i> View History</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteTask(<?php echo $task['id']; ?>)">
                                                <i class="fas fa-trash"></i> Delete Task</a></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <?php if ($task['description']): ?>
                                <p class="card-text text-muted mb-3">
                                    <?php echo htmlspecialchars($task['description']); ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="task-meta">
                                    <div class="task-meta-item">
                                        <i class="fas fa-user"></i>
                                        <span>
                                            <?php echo htmlspecialchars(($task['assigned_to_name'] ?? 'Unassigned') . ' ' . ($task['assigned_to_lastname'] ?? '')); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($task['due_date']): ?>
                                    <div class="task-meta-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span>Due: <?php echo date('M j, Y g:i A', strtotime($task['due_date'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($task['location']): ?>
                                    <div class="task-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($task['location']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($task['equipment']): ?>
                                    <div class="task-meta-item">
                                        <i class="fas fa-tools"></i>
                                        <span><?php echo htmlspecialchars($task['equipment']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($task['category']): ?>
                                    <div class="task-meta-item">
                                        <i class="fas fa-tag"></i>
                                        <span><?php echo htmlspecialchars($task['category']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($task['estimated_hours']): ?>
                                    <div class="task-meta-item">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo $task['estimated_hours']; ?>h estimated</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Progress Bar -->
                                <?php if ($task['progress_percentage'] > 0): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted">Progress</small>
                                        <small class="text-muted"><?php echo $task['progress_percentage']; ?>%</small>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $task['progress_percentage']; ?>%"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="task-actions">
                                    <?php if ($task['status'] === 'pending'): ?>
                                    <button class="btn btn-success btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')">
                                        <i class="fas fa-play"></i> Start Task
                                    </button>
                                    <?php elseif ($task['status'] === 'in_progress'): ?>
                                    <button class="btn btn-primary btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'completed')">
                                        <i class="fas fa-check"></i> Complete
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'on_hold')">
                                        <i class="fas fa-pause"></i> Hold
                                    </button>
                                    <?php elseif ($task['status'] === 'on_hold'): ?>
                                    <button class="btn btn-success btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')">
                                        <i class="fas fa-play"></i> Resume
                                    </button>
                                    <?php endif; ?>
                                    
                                    <button class="btn btn-outline-primary btn-sm" onclick="editTask(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteTask(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                    
                                    <?php if ($task['assigned_to_email']): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($task['assigned_to_email']); ?>?subject=Task: <?php echo urlencode($task['title']); ?>" 
                                       class="btn btn-outline-info btn-sm">
                                        <i class="fas fa-envelope"></i> Email
                                    </a>
                                    <?php endif; ?>
                                    
                                    <small class="text-muted">
                                        Created: <?php echo date('M j, Y', strtotime($task['created_at'])); ?>
                                        <?php if ($task['assigned_by_name']): ?>
                                        by <?php echo htmlspecialchars($task['assigned_by_name'] . ' ' . $task['assigned_by_lastname']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Tasks pagination" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&assigned_to=<?php echo $assigned_to_filter; ?>&search=<?php echo urlencode($search); ?>">
                                Previous
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&assigned_to=<?php echo $assigned_to_filter; ?>&search=<?php echo urlencode($search); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&assigned_to=<?php echo $assigned_to_filter; ?>&search=<?php echo urlencode($search); ?>">
                                Next
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editTaskForm" novalidate>
                    <input type="hidden" id="editTaskId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="editTaskTitle" class="form-label">Task Title *</label>
                                    <input type="text" class="form-control" id="editTaskTitle" required>
                                </div>
                                <div class="mb-3">
                                    <label for="editTaskDescription" class="form-label">Description</label>
                                    <textarea class="form-control" id="editTaskDescription" rows="3"></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editTaskLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="editTaskLocation">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editTaskEquipment" class="form-label">Equipment</label>
                                            <input type="text" class="form-control" id="editTaskEquipment">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editTaskCategory" class="form-label">Category</label>
                                            <select class="form-select" id="editTaskCategory">
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
                                            <label for="editEstimatedHours" class="form-label">Estimated Hours</label>
                                            <input type="number" class="form-control" id="editEstimatedHours" min="0.5" step="0.5">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="editAssignedTo" class="form-label">Assign To *</label>
                                    <select class="form-select" id="editAssignedTo" required>
                                        <option value="">Select Mechanic</option>
                                        <?php foreach ($mechanics as $mechanic): ?>
                                        <option value="<?php echo $mechanic['id']; ?>">
                                            <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editTaskPriority" class="form-label">Priority *</label>
                                    <select class="form-select" id="editTaskPriority" required>
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editTaskStatus" class="form-label">Status</label>
                                    <select class="form-select" id="editTaskStatus">
                                        <option value="pending">Pending</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="completed">Completed</option>
                                        <option value="on_hold">On Hold</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editDueDate" class="form-label">Due Date</label>
                                    <input type="datetime-local" class="form-control" id="editDueDate">
                                </div>
                                <div class="mb-3">
                                    <label for="editStartDate" class="form-label">Start Date</label>
                                    <input type="datetime-local" class="form-control" id="editStartDate">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editTaskNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="editTaskNotes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-manager" id="saveTaskBtn">
                            <i class="fas fa-save"></i> Save Changes
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
                    <div class="delete-confirm">
                        <h6><i class="fas fa-exclamation-triangle"></i> Are you sure?</h6>
                        <p>This action cannot be undone. The task will be permanently deleted.</p>
                    </div>
                    <div id="deleteTaskDetails">
                        <!-- Task details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i> Delete Task
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        setupEventListeners();
        updateSelectedCount();
        
        // Add debug info
        console.log('Manager Tasks page loaded');
        console.log('Total tasks:', <?php echo $total_tasks; ?>);
        console.log('Current page:', <?php echo $page; ?>);
    });
    
    function setupEventListeners() {
        // Auto-submit search form on enter
        const searchField = document.getElementById('search');
        if (searchField) {
            searchField.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    this.form.submit();
                }
            });
        }
        
        // Edit task form submission
        const editForm = document.getElementById('editTaskForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                saveTaskChanges();
            });
        }
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    }
    
    function toggleAllTasks(checkbox) {
        const taskCheckboxes = document.querySelectorAll('.task-checkbox');
        taskCheckboxes.forEach(cb => cb.checked = checkbox.checked);
        updateSelectedCount();
    }
    
    function updateSelectedCount() {
        const selectedTasks = document.querySelectorAll('.task-checkbox:checked');
        const count = selectedTasks.length;
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        
        if (bulkActions && selectedCount) {
            if (count > 0) {
                bulkActions.classList.add('show');
                selectedCount.textContent = `${count} task${count > 1 ? 's' : ''} selected`;
            } else {
                bulkActions.classList.remove('show');
            }
        }
        
        // Update select all checkbox
        const selectAll = document.getElementById('selectAll');
        const allCheckboxes = document.querySelectorAll('.task-checkbox');
        if (selectAll && allCheckboxes.length > 0) {
            selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
            selectAll.checked = count === allCheckboxes.length && count > 0;
        }
    }
    
    function updateTaskStatus(taskId, newStatus) {
        if (confirm('Are you sure you want to update this task status?')) {
            const statusData = {
                action: 'update_status',
                task_id: taskId,
                status: newStatus
            };
            
            console.log('Updating task status:', statusData);
            
            fetch('../api/tasks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(statusData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Status update response:', data);
                if (data.success) {
                    showToast('Task status updated successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Error: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Status update error:', error);
                showToast('Network error occurred', 'danger');
            });
        }
    }
    
    function editTask(taskId) {
        console.log('Editing task:', taskId);
        
        // Fetch task details and populate edit form
        fetch(`../api/tasks.php?action=get_task&id=${taskId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Task data:', data);
            if (data.success && data.task) {
                const task = data.task;
                
                // Populate form fields
                document.getElementById('editTaskId').value = task.id;
                document.getElementById('editTaskTitle').value = task.title || '';
                document.getElementById('editTaskDescription').value = task.description || '';
                document.getElementById('editTaskLocation').value = task.location || '';
                document.getElementById('editTaskEquipment').value = task.equipment || '';
                document.getElementById('editTaskCategory').value = task.category || '';
                document.getElementById('editEstimatedHours').value = task.estimated_hours || '';
                document.getElementById('editAssignedTo').value = task.assigned_to || '';
                document.getElementById('editTaskPriority').value = task.priority || '';
                document.getElementById('editTaskStatus').value = task.status || '';
                document.getElementById('editTaskNotes').value = task.notes || '';
                
                if (task.due_date) {
                    const dueDate = new Date(task.due_date);
                    document.getElementById('editDueDate').value = formatDateTimeLocal(dueDate);
                }
                
                if (task.start_date) {
                    const startDate = new Date(task.start_date);
                    document.getElementById('editStartDate').value = formatDateTimeLocal(startDate);
                }
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('editTaskModal'));
                modal.show();
            } else {
                showToast('Failed to load task details', 'danger');
            }
        })
        .catch(error => {
            console.error('Edit task error:', error);
            showToast('Error loading task: ' + error.message, 'danger');
        });
    }
    
    function saveTaskChanges() {
        const taskData = {
            action: 'update_task',
            task_id: document.getElementById('editTaskId').value,
            title: document.getElementById('editTaskTitle').value,
            description: document.getElementById('editTaskDescription').value,
            location: document.getElementById('editTaskLocation').value,
            equipment: document.getElementById('editTaskEquipment').value,
            category: document.getElementById('editTaskCategory').value,
            estimated_hours: parseFloat(document.getElementById('editEstimatedHours').value) || null,
            assigned_to: parseInt(document.getElementById('editAssignedTo').value),
            priority: document.getElementById('editTaskPriority').value,
            status: document.getElementById('editTaskStatus').value,
            due_date: document.getElementById('editDueDate').value || null,
            start_date: document.getElementById('editStartDate').value || null,
            notes: document.getElementById('editTaskNotes').value
        };
        
        console.log('Saving task changes:', taskData);
        
        // Show loading
        const saveBtn = document.getElementById('saveTaskBtn');
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveBtn.disabled = true;
        
        fetch('../api/tasks.php', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(taskData)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Save response:', data);
            
            // Reset button
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
            
            if (data.success) {
                showToast('Task updated successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('editTaskModal')).hide();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Save error:', error);
            showToast('Error updating task: ' + error.message, 'danger');
            
            // Reset button
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        });
    }
    
    // Global variable to store task ID for deletion
    let taskToDelete = null;
    
    function deleteTask(taskId) {
        console.log('Preparing to delete task:', taskId);
        taskToDelete = taskId;
        
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
    
    // Add event listener for delete confirmation
    document.addEventListener('DOMContentLoaded', function() {
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', function() {
                if (taskToDelete) {
                    confirmDeleteTask(taskToDelete);
                }
            });
        }
    });
    
    function confirmDeleteTask(taskId) {
        console.log('Confirming delete for task:', taskId);
        
        // Show loading
        const deleteBtn = document.getElementById('confirmDeleteBtn');
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        deleteBtn.disabled = true;
        
        fetch('../api/tasks.php', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                task_id: taskId
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Delete response:', data);
            
            // Reset button
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
            
            if (data.success) {
                showToast('Task deleted successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('deleteTaskModal')).hide();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Delete error:', error);
            showToast('Error deleting task: ' + error.message, 'danger');
            
            // Reset button
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
        });
    }
    
    function duplicateTask(taskId) {
        console.log('Duplicating task:', taskId);
        
        fetch(`../api/tasks.php?action=get_task&id=${taskId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.task) {
                const task = data.task;
                const duplicateData = {
                    action: 'create_task',
                    title: `Copy of ${task.title}`,
                    description: task.description,
                    assigned_to: task.assigned_to,
                    priority: task.priority,
                    location: task.location,
                    equipment: task.equipment,
                    category: task.category,
                    estimated_hours: task.estimated_hours,
                    notes: task.notes
                };
                
                fetch('../api/tasks.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(duplicateData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Task duplicated successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Error duplicating task: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Duplicate error:', error);
                    showToast('Error duplicating task: ' + error.message, 'danger');
                });
            }
        })
        .catch(error => {
            console.error('Duplicate fetch error:', error);
            showToast('Error loading task for duplication: ' + error.message, 'danger');
        });
    }
    
    function bulkUpdateStatus(newStatus) {
        const selectedTasks = Array.from(document.querySelectorAll('.task-checkbox:checked')).map(cb => cb.value);
        
        if (selectedTasks.length === 0) {
            showToast('Please select tasks to update', 'warning');
            return;
        }
        
        if (confirm(`Are you sure you want to update ${selectedTasks.length} task(s) to ${newStatus.replace('_', ' ')}?`)) {
            const requests = selectedTasks.map(taskId => 
                fetch('../api/tasks.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'update_status',
                        task_id: taskId,
                        status: newStatus
                    })
                })
            );
            
            Promise.all(requests)
            .then(responses => Promise.all(responses.map(r => r.json())))
            .then(results => {
                const successCount = results.filter(r => r.success).length;
                showToast(`Successfully updated ${successCount} task(s)`, 'success');
                setTimeout(() => location.reload(), 1000);
            })
            .catch(error => {
                console.error('Bulk update error:', error);
                showToast('Error during bulk update: ' + error.message, 'danger');
            });
        }
    }
    
    function bulkDelete() {
        const selectedTasks = Array.from(document.querySelectorAll('.task-checkbox:checked')).map(cb => cb.value);
        
        if (selectedTasks.length === 0) {
            showToast('Please select tasks to delete', 'warning');
            return;
        }
        
        if (confirm(`Are you sure you want to delete ${selectedTasks.length} task(s)? This action cannot be undone.`)) {
            const requests = selectedTasks.map(taskId => 
                fetch('../api/tasks.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        task_id: taskId
                    })
                })
            );
            
            Promise.all(requests)
            .then(responses => Promise.all(responses.map(r => r.json())))
            .then(results => {
                const successCount = results.filter(r => r.success).length;
                showToast(`Successfully deleted ${successCount} task(s)`, 'success');
                setTimeout(() => location.reload(), 1000);
            })
            .catch(error => {
                console.error('Bulk delete error:', error);
                showToast('Error during bulk delete: ' + error.message, 'danger');
            });
        }
    }
    
    function exportTasks() {
        const params = new URLSearchParams(window.location.search);
        params.set('export', 'csv');
        
        showToast('Export functionality will be implemented soon!', 'info');
        // Future: window.location.href = 'tasks.php?' + params.toString();
    }
    
    function refreshTasks() {
        location.reload();
    }
    
    function viewHistory(taskId) {
        showToast('Task history feature will be implemented soon!', 'info');
    }
    
    function showCreateTaskModal() {
        // Redirect to dashboard for task creation
        window.location.href = 'dashboard.php';
    }
    
    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    function showToast(message, type = 'info') {
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
        toast.className = `toast align-items-center text-white bg-${type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-${getToastIcon(type)}"></i> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Show toast
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
        // Remove after hiding
        toast.addEventListener('hidden.bs.toast', function() {
            toast.remove();
        });
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
    
    // DEBUG FUNCTIONS
    function testAPI() {
        console.log('Testing API connection...');
        
        fetch('../api/tasks.php?action=test', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('API Test Response Status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('API Test Raw Response:', text);
            try {
                const data = JSON.parse(text);
                console.log('API Test Parsed:', data);
                
                if (data.success) {
                    showToast('✅ API is working correctly!', 'success');
                } else {
                    showToast('❌ API test failed: ' + data.message, 'danger');
                }
            } catch (e) {
                showToast('❌ API returned invalid JSON', 'danger');
                console.error('JSON Parse Error:', e);
            }
        })
        .catch(error => {
            console.error('API Test Error:', error);
            showToast('❌ API connection failed: ' + error.message, 'danger');
        });
    }
    </script>
</body>
</html>