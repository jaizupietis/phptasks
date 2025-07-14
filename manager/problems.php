<?php
/**
 * FIXED Manager Problem Management Dashboard
 * This version fixes the blank page issue and SQL parameter errors
 * Replace: /var/www/tasks/manager/problems.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Initialize variables to prevent undefined errors
$status_filter = 'all';
$priority_filter = 'all';
$category_filter = 'all';
$assigned_to_filter = 0;
$problems = [];
$total_problems = 0;
$total_pages = 1;
$page = 1;
$per_page = 15;
$mechanics = [];
$problem_stats = ['total' => 0, 'reported' => 0, 'assigned' => 0, 'in_progress' => 0, 'resolved' => 0];

try {
    // Handle problem assignment and task conversion
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'assign_problem') {
            $problem_id = (int)$_POST['problem_id'];
            $assigned_to = (int)$_POST['assigned_to'];
            
            if ($problem_id > 0 && $assigned_to > 0) {
                try {
                    $db->query(
                        "UPDATE problems SET assigned_to = ?, assigned_by = ?, status = 'assigned' WHERE id = ?",
                        [$assigned_to, $user_id, $problem_id]
                    );
                    
                    // Create notification for assigned mechanic
                    $problem = $db->fetch("SELECT title FROM problems WHERE id = ?", [$problem_id]);
                    if ($problem) {
                        $db->query(
                            "INSERT INTO notifications (user_id, problem_id, type, title, message) 
                             VALUES (?, ?, 'problem_assigned', 'Problem Assigned', ?)",
                            [$assigned_to, $problem_id, "Problem assigned: '{$problem['title']}'"]
                        );
                    }
                    
                    $_SESSION['success_message'] = 'Problem assigned successfully!';
                } catch (Exception $e) {
                    error_log("Problem assignment error: " . $e->getMessage());
                    $_SESSION['error_message'] = 'Error assigning problem: ' . $e->getMessage();
                }
            }
            
            header('Location: problems.php');
            exit;
        }
        
        if ($action === 'convert_to_task') {
            $problem_id = (int)$_POST['problem_id'];
            $assigned_to = (int)$_POST['assigned_to'];
            
            if ($problem_id > 0 && $assigned_to > 0) {
                try {
                    $problem = $db->fetch("SELECT * FROM problems WHERE id = ?", [$problem_id]);
                    
                    if ($problem) {
                        // Create task from problem
                        $task_data = [
                            'title' => "Fix: " . $problem['title'],
                            'description' => $problem['description'],
                            'priority' => $problem['priority'],
                            'status' => 'pending',
                            'assigned_to' => $assigned_to,
                            'assigned_by' => $user_id,
                            'category' => $problem['category'],
                            'location' => $problem['location'],
                            'equipment' => $problem['equipment'],
                            'estimated_hours' => $problem['estimated_resolution_time'],
                            'notes' => "Created from Problem #" . $problem_id,
                            'progress_percentage' => 0
                        ];
                        
                        $fields = array_keys($task_data);
                        $placeholders = array_fill(0, count($fields), '?');
                        $values = array_values($task_data);
                        
                        $sql = "INSERT INTO tasks (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                        $stmt = $db->query($sql, $values);
                        $new_task_id = $db->getConnection()->lastInsertId();
                        
                        // Update problem with task ID
                        $db->query(
                            "UPDATE problems SET task_id = ?, status = 'assigned' WHERE id = ?",
                            [$new_task_id, $problem_id]
                        );
                        
                        // Create notifications
                        $db->query(
                            "INSERT INTO notifications (user_id, task_id, type, title, message) 
                             VALUES (?, ?, 'task_assigned', 'Task Created from Problem', ?)",
                            [$assigned_to, $new_task_id, "Task created from problem: '{$problem['title']}'"]
                        );
                        
                        $_SESSION['success_message'] = 'Problem converted to task successfully!';
                    }
                } catch (Exception $e) {
                    error_log("Problem conversion error: " . $e->getMessage());
                    $_SESSION['error_message'] = 'Error converting problem: ' . $e->getMessage();
                }
            }
            
            header('Location: problems.php');
            exit;
        }
    }

    // Get filter parameters with proper sanitization
    $status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
    $priority_filter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : 'all';
    $category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : 'all';
    $assigned_to_filter = isset($_GET['assigned_to']) ? max(0, (int)$_GET['assigned_to']) : 0;

    // Build WHERE clause with proper parameter binding
    $where_conditions = [];
    $params = [];

    if ($status_filter !== 'all' && in_array($status_filter, ['reported', 'assigned', 'in_progress', 'resolved', 'closed'])) {
        $where_conditions[] = "p.status = ?";
        $params[] = $status_filter;
    }

    if ($priority_filter !== 'all' && in_array($priority_filter, ['low', 'medium', 'high', 'urgent'])) {
        $where_conditions[] = "p.priority = ?";
        $params[] = $priority_filter;
    }

    if ($category_filter !== 'all') {
        $where_conditions[] = "p.category = ?";
        $params[] = $category_filter;
    }

    if ($assigned_to_filter > 0) {
        $where_conditions[] = "p.assigned_to = ?";
        $params[] = $assigned_to_filter;
    }

    // Build final WHERE clause
    $where_clause = empty($where_conditions) ? "1 = 1" : implode(' AND ', $where_conditions);

    // Get pagination parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = $is_mobile ? 8 : 15;
    $offset = ($page - 1) * $per_page;

    // Get problems with proper error handling
    $problems = $db->fetchAll(
        "SELECT p.*, 
                ur.first_name as reported_by_name, ur.last_name as reported_by_lastname,
                ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
                ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname,
                t.id as task_id, t.status as task_status, t.title as task_title
         FROM problems p 
         LEFT JOIN users ur ON p.reported_by = ur.id 
         LEFT JOIN users ua ON p.assigned_to = ua.id 
         LEFT JOIN users ub ON p.assigned_by = ub.id 
         LEFT JOIN tasks t ON p.task_id = t.id
         WHERE {$where_clause}
         ORDER BY 
            CASE 
                WHEN p.status = 'reported' THEN 1 
                WHEN p.priority = 'urgent' THEN 2 
                WHEN p.priority = 'high' THEN 3 
                ELSE 4 
            END,
            p.created_at DESC 
         LIMIT {$per_page} OFFSET {$offset}",
        $params
    );

    // Get total count for pagination
    $total_problems = $db->fetchCount(
        "SELECT COUNT(*) FROM problems p WHERE {$where_clause}",
        $params
    );

    $total_pages = max(1, ceil($total_problems / $per_page));

    // Get all mechanics for assignment
    $mechanics = $db->fetchAll(
        "SELECT id, first_name, last_name FROM users 
         WHERE role = 'mechanic' AND is_active = 1 
         ORDER BY first_name, last_name"
    );

    // Get problem statistics with safe parameter handling
    $base_params = $params; // Store base parameters
    
    $problem_stats = [
        'total' => $total_problems,
        'reported' => $db->fetchCount(
            "SELECT COUNT(*) FROM problems p WHERE {$where_clause}" . 
            ($where_clause === "1 = 1" ? " AND p.status = 'reported'" : " AND p.status = 'reported'"), 
            array_merge($base_params, $where_clause === "1 = 1" ? ['reported'] : [])
        ),
        'assigned' => $db->fetchCount(
            "SELECT COUNT(*) FROM problems p WHERE {$where_clause}" . 
            ($where_clause === "1 = 1" ? " AND p.status = 'assigned'" : " AND p.status = 'assigned'"), 
            array_merge($base_params, $where_clause === "1 = 1" ? ['assigned'] : [])
        ),
        'in_progress' => $db->fetchCount(
            "SELECT COUNT(*) FROM problems p WHERE {$where_clause}" . 
            ($where_clause === "1 = 1" ? " AND p.status = 'in_progress'" : " AND p.status = 'in_progress'"), 
            array_merge($base_params, $where_clause === "1 = 1" ? ['in_progress'] : [])
        ),
        'resolved' => $db->fetchCount(
            "SELECT COUNT(*) FROM problems p WHERE {$where_clause}" . 
            ($where_clause === "1 = 1" ? " AND p.status = 'resolved'" : " AND p.status = 'resolved'"), 
            array_merge($base_params, $where_clause === "1 = 1" ? ['resolved'] : [])
        )
    ];

} catch (Exception $e) {
    error_log("Manager problems page error: " . $e->getMessage());
    $error_message = "System error occurred. Please try again.";
}

$page_title = 'Problem Management';
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
        :root {
            --manager-primary: #6f42c1;
            --operator-primary: #17a2b8;
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
        
        .problem-card {
            border-left: 4px solid var(--operator-primary);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .problem-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .problem-card.priority-urgent { border-left-color: var(--danger-color); }
        .problem-card.priority-high { border-left-color: #fd7e14; }
        .problem-card.priority-medium { border-left-color: var(--warning-color); }
        .problem-card.priority-low { border-left-color: var(--success-color); }
        
        .priority-badge, .status-badge, .severity-badge {
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
        
        .status-reported { background: #6c757d; color: white; }
        .status-assigned { background: var(--operator-primary); color: white; }
        .status-in_progress { background: var(--warning-color); color: #212529; }
        .status-resolved { background: var(--success-color); color: white; }
        .status-closed { background: #6c757d; color: white; }
        
        .severity-minor { background: #e3f2fd; color: #1976d2; }
        .severity-moderate { background: #fff3e0; color: #f57c00; }
        .severity-major { background: #ffebee; color: #d32f2f; }
        .severity-critical { background: var(--danger-color); color: white; }
        
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
        
        .stats-mini {
            text-align: center;
            padding: 1.5rem 1rem;
            border-radius: 10px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 1rem;
            height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .stats-mini h4 {
            margin: 0.5rem 0;
            font-weight: 700;
            font-size: 1.8rem;
        }
        
        .stats-mini.total { border-top: 4px solid var(--manager-primary); }
        .stats-mini.reported { border-top: 4px solid #6c757d; }
        .stats-mini.assigned { border-top: 4px solid var(--operator-primary); }
        .stats-mini.progress { border-top: 4px solid var(--warning-color); }
        .stats-mini.resolved { border-top: 4px solid var(--success-color); }
        
        .filter-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .modal-header {
            background: var(--manager-primary);
            color: white;
        }
        
        .workflow-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 1rem 0;
        }
        
        .workflow-step {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
            flex: 1;
        }
        
        .workflow-step.active {
            background: var(--manager-primary);
            color: white;
        }
        
        .workflow-step.completed {
            background: var(--success-color);
            color: white;
        }
        
        .workflow-step.pending {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .action-buttons .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .action-buttons .btn {
                margin: 0;
                flex: 1;
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
                    <i class="fas fa-exclamation-triangle"></i> Problem Management
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-light btn-sm" onclick="refreshData()">
                    <i class="fas fa-sync"></i> Refresh
                </button>
                <button class="btn btn-success btn-sm" onclick="exportProblems()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- Debug Info (remove in production) -->
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <!-- Statistics Row -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini total">
                    <h4 class="text-primary"><?php echo $problem_stats['total']; ?></h4>
                    <small>Total Problems</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini reported">
                    <h4 class="text-secondary"><?php echo $problem_stats['reported']; ?></h4>
                    <small>Awaiting Assignment</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini assigned">
                    <h4 class="text-info"><?php echo $problem_stats['assigned']; ?></h4>
                    <small>Assigned</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini progress">
                    <h4 class="text-warning"><?php echo $problem_stats['in_progress']; ?></h4>
                    <small>In Progress</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini resolved">
                    <h4 class="text-success"><?php echo $problem_stats['resolved']; ?></h4>
                    <small>Resolved</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini">
                    <h4 class="text-primary"><?php echo count($problems); ?></h4>
                    <small>Showing</small>
                </div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filter-section p-4">
            <form method="GET" class="row g-3">
                <div class="col-lg-2 col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="reported" <?php echo $status_filter === 'reported' ? 'selected' : ''; ?>>Reported</option>
                        <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="priority" class="form-label">Priority</label>
                    <select class="form-select" id="priority" name="priority">
                        <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                        <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="category" class="form-label">Category</label>
                    <select class="form-select" id="category" name="category">
                        <option value="all">All Categories</option>
                        <option value="Mechanical" <?php echo $category_filter === 'Mechanical' ? 'selected' : ''; ?>>Mechanical</option>
                        <option value="Electrical" <?php echo $category_filter === 'Electrical' ? 'selected' : ''; ?>>Electrical</option>
                        <option value="Hydraulic System" <?php echo $category_filter === 'Hydraulic System' ? 'selected' : ''; ?>>Hydraulic System</option>
                        <option value="Engine" <?php echo $category_filter === 'Engine' ? 'selected' : ''; ?>>Engine</option>
                        <option value="Brake System" <?php echo $category_filter === 'Brake System' ? 'selected' : ''; ?>>Brake System</option>
                        <option value="Safety" <?php echo $category_filter === 'Safety' ? 'selected' : ''; ?>>Safety</option>
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
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-manager">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="problems.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Problems List -->
        <div class="row">
            <div class="col-12">
                <?php if (empty($problems)): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-triangle fa-4x text-muted mb-3"></i>
                            <h5>No Problems Found</h5>
                            <p class="text-muted">
                                <?php if (count($mechanics) === 0): ?>
                                No mechanics available for assignment. Please add mechanics to the system first.
                                <?php else: ?>
                                No problems match your current filters.<br>Adjust the filters above to see more results.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted mb-0">
                        Showing <?php echo count($problems); ?> of <?php echo $total_problems; ?> problems
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></small>
                    </div>
                </div>
                
                <!-- Problem Cards -->
                <?php foreach ($problems as $problem): ?>
                <div class="card problem-card priority-<?php echo $problem['priority']; ?>" data-problem-id="<?php echo $problem['id']; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="card-title mb-1">
                                    <?php echo htmlspecialchars($problem['title']); ?>
                                    <?php if ($problem['task_id']): ?>
                                    <span class="badge bg-success ms-2">
                                        <i class="fas fa-wrench"></i> Task #<?php echo $problem['task_id']; ?>
                                    </span>
                                    <?php endif; ?>
                                </h5>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="status-badge status-<?php echo $problem['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $problem['status'])); ?>
                                    </span>
                                    <span class="priority-badge priority-<?php echo $problem['priority']; ?>">
                                        <?php echo ucfirst($problem['priority']); ?>
                                    </span>
                                    <?php if ($problem['severity']): ?>
                                    <span class="severity-badge severity-<?php echo $problem['severity']; ?>">
                                        <?php echo ucfirst($problem['severity']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="viewProblemDetails(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-eye"></i> View Details</a></li>
                                    <?php if ($problem['status'] === 'reported'): ?>
                                    <li><a class="dropdown-item" href="#" onclick="assignProblem(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-user-plus"></i> Assign to Mechanic</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="convertToTask(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-wrench"></i> Convert to Task</a></li>
                                    <?php endif; ?>
                                    <?php if ($problem['task_id']): ?>
                                    <li><a class="dropdown-item" href="tasks.php?task_id=<?php echo $problem['task_id']; ?>">
                                        <i class="fas fa-tasks"></i> View Task</a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Workflow Status -->
                        <div class="workflow-status">
                            <div class="workflow-step <?php echo $problem['status'] === 'reported' ? 'active' : 'completed'; ?>">
                                Reported
                            </div>
                            <div class="workflow-step <?php echo $problem['status'] === 'assigned' ? 'active' : ($problem['status'] === 'reported' ? 'pending' : 'completed'); ?>">
                                Assigned
                            </div>
                            <div class="workflow-step <?php echo $problem['status'] === 'in_progress' ? 'active' : ($problem['status'] === 'resolved' ? 'completed' : 'pending'); ?>">
                                In Progress
                            </div>
                            <div class="workflow-step <?php echo $problem['status'] === 'resolved' ? 'active' : 'pending'; ?>">
                                Resolved
                            </div>
                        </div>
                        
                        <?php if ($problem['description']): ?>
                        <p class="card-text text-muted mb-3">
                            <?php echo htmlspecialchars($problem['description']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <small class="text-muted d-block">Reported by:</small>
                                <strong><?php echo htmlspecialchars($problem['reported_by_name'] . ' ' . $problem['reported_by_lastname']); ?></strong>
                            </div>
                            <?php if ($problem['category']): ?>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Category:</small>
                                <strong><?php echo htmlspecialchars($problem['category']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($problem['location']): ?>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Location:</small>
                                <strong><?php echo htmlspecialchars($problem['location']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($problem['equipment']): ?>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Equipment:</small>
                                <strong><?php echo htmlspecialchars($problem['equipment']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Reported:</small>
                                <strong><?php echo timeAgo($problem['created_at']); ?></strong>
                            </div>
                        </div>
                        
                        <?php if ($problem['assigned_to_name']): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-user"></i>
                            <strong>Assigned to:</strong> <?php echo htmlspecialchars($problem['assigned_to_name'] . ' ' . $problem['assigned_to_lastname']); ?>
                            <?php if ($problem['assigned_by_name']): ?>
                            by <?php echo htmlspecialchars($problem['assigned_by_name'] . ' ' . $problem['assigned_by_lastname']); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="action-buttons">
                            <?php if ($problem['status'] === 'reported' && count($mechanics) > 0): ?>
                            <button class="btn btn-primary btn-sm" onclick="assignProblem(<?php echo $problem['id']; ?>)">
                                <i class="fas fa-user-plus"></i> Assign to Mechanic
                            </button>
                            <button class="btn btn-success btn-sm" onclick="convertToTask(<?php echo $problem['id']; ?>)">
                                <i class="fas fa-wrench"></i> Convert to Task
                            </button>
                            <?php elseif ($problem['status'] === 'assigned' && !$problem['task_id']): ?>
                            <button class="btn btn-warning btn-sm" onclick="convertToTask(<?php echo $problem['id']; ?>)">
                                <i class="fas fa-wrench"></i> Convert to Task
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($problem['task_id']): ?>
                            <a href="tasks.php?task_id=<?php echo $problem['task_id']; ?>" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-tasks"></i> View Task
                            </a>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-primary btn-sm" onclick="viewProblemDetails(<?php echo $problem['id']; ?>)">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            
                            <small class="text-muted d-block mt-2">
                                <strong>Impact:</strong> <?php echo ucfirst($problem['impact'] ?? 'medium'); ?> |
                                <strong>Urgency:</strong> <?php echo ucfirst($problem['urgency'] ?? 'medium'); ?>
                                <?php if ($problem['estimated_resolution_time']): ?>
                                | <strong>Est. Time:</strong> <?php echo $problem['estimated_resolution_time']; ?>h
                                <?php endif; ?>
                            </small>
                        </div>
                        
                        <?php if ($problem['resolved_at']): ?>
                        <div class="mt-3">
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <strong>Resolved:</strong> <?php echo date('M j, Y g:i A', strtotime($problem['resolved_at'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Problems pagination" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&assigned_to=<?php echo $assigned_to_filter; ?>">
                                Previous
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&assigned_to=<?php echo $assigned_to_filter; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>&assigned_to=<?php echo $assigned_to_filter; ?>">
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
    
    <!-- Assign Problem Modal -->
    <div class="modal fade" id="assignProblemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Problem to Mechanic</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_problem">
                    <input type="hidden" name="problem_id" id="assignProblemId">
                    <div class="modal-body">
                        <div id="assignProblemDetails">
                            <!-- Problem details will be loaded here -->
                        </div>
                        <?php if (count($mechanics) > 0): ?>
                        <div class="mb-3">
                            <label for="assignMechanic" class="form-label">Select Mechanic *</label>
                            <select class="form-select" name="assigned_to" id="assignMechanic" required>
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
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            No mechanics available. Please add mechanics to the system first.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if (count($mechanics) > 0): ?>
                        <button type="submit" class="btn btn-manager">
                            <i class="fas fa-user-plus"></i> Assign Problem
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Convert to Task Modal -->
    <div class="modal fade" id="convertTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-wrench"></i> Convert Problem to Task</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="convert_to_task">
                    <input type="hidden" name="problem_id" id="convertProblemId">
                    <div class="modal-body">
                        <div id="convertProblemDetails">
                            <!-- Problem details will be loaded here -->
                        </div>
                        <?php if (count($mechanics) > 0): ?>
                        <div class="mb-3">
                            <label for="convertMechanic" class="form-label">Assign Task To *</label>
                            <select class="form-select" name="assigned_to" id="convertMechanic" required>
                                <option value="">Choose a mechanic...</option>
                                <?php foreach ($mechanics as $mechanic): ?>
                                <option value="<?php echo $mechanic['id']; ?>">
                                    <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            This will create a maintenance task based on this problem and assign it to the selected mechanic.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            No mechanics available. Please add mechanics to the system first.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if (count($mechanics) > 0): ?>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-wrench"></i> Convert to Task
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Problem Details Modal -->
    <div class="modal fade" id="viewProblemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye"></i> Problem Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewProblemContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('✅ FIXED Manager Problem Management loaded successfully');
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                if (alert.classList.contains('show')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
        
        console.log('Problem stats:', <?php echo json_encode($problem_stats); ?>);
        console.log('Total mechanics available:', <?php echo count($mechanics); ?>);
        console.log('Current filters:', {
            status: '<?php echo $status_filter; ?>',
            priority: '<?php echo $priority_filter; ?>',
            category: '<?php echo $category_filter; ?>',
            assigned_to: <?php echo $assigned_to_filter; ?>
        });
    });
    
    function assignProblem(problemId) {
        console.log('✅ Assigning problem:', problemId);
        
        // Check if mechanics are available
        const mechanicsAvailable = <?php echo count($mechanics); ?>;
        if (mechanicsAvailable === 0) {
            showToast('❌ No mechanics available for assignment', 'warning');
            return;
        }
        
        // Find problem details from page
        const problemCard = document.querySelector(`[data-problem-id="${problemId}"]`);
        let problemTitle = `Problem #${problemId}`;
        
        if (problemCard) {
            const titleElement = problemCard.querySelector('.card-title');
            if (titleElement) {
                problemTitle = titleElement.textContent.trim();
            }
        }
        
        document.getElementById('assignProblemId').value = problemId;
        document.getElementById('assignProblemDetails').innerHTML = `
            <div class="alert alert-light">
                <h6><i class="fas fa-exclamation-triangle"></i> ${problemTitle}</h6>
                <p class="mb-0">Select a mechanic to assign this problem to. They will be notified automatically.</p>
            </div>
        `;
        
        // Reset form
        document.getElementById('assignMechanic').value = '';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('assignProblemModal'));
        modal.show();
    }
    
    function convertToTask(problemId) {
        console.log('✅ Converting problem to task:', problemId);
        
        // Check if mechanics are available
        const mechanicsAvailable = <?php echo count($mechanics); ?>;
        if (mechanicsAvailable === 0) {
            showToast('❌ No mechanics available for task assignment', 'warning');
            return;
        }
        
        const problemCard = document.querySelector(`[data-problem-id="${problemId}"]`);
        let problemTitle = `Problem #${problemId}`;
        
        if (problemCard) {
            const titleElement = problemCard.querySelector('.card-title');
            if (titleElement) {
                problemTitle = titleElement.textContent.trim();
            }
        }
        
        document.getElementById('convertProblemId').value = problemId;
        document.getElementById('convertProblemDetails').innerHTML = `
            <div class="alert alert-light">
                <h6><i class="fas fa-wrench"></i> ${problemTitle}</h6>
                <p class="mb-0">This will create a maintenance task: "Fix: ${problemTitle}"</p>
                <small class="text-muted">The task will include all problem details and estimated resolution time.</small>
            </div>
        `;
        
        // Reset form
        document.getElementById('convertMechanic').value = '';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('convertTaskModal'));
        modal.show();
    }
    
    function viewProblemDetails(problemId) {
        console.log('✅ Viewing problem details:', problemId);
        
        // Show loading
        document.getElementById('viewProblemContent').innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading problem details...</p>
            </div>
        `;
        
        // Show modal first
        const modal = new bootstrap.Modal(document.getElementById('viewProblemModal'));
        modal.show();
        
        // Try to load problem details from the page first
        const problemCard = document.querySelector(`[data-problem-id="${problemId}"]`);
        if (problemCard) {
            // Extract details from the card
            const title = problemCard.querySelector('.card-title')?.textContent.trim() || 'Unknown';
            const description = problemCard.querySelector('.card-text')?.textContent.trim() || 'No description';
            const status = problemCard.querySelector('.status-badge')?.textContent.trim() || 'Unknown';
            const priority = problemCard.querySelector('.priority-badge')?.textContent.trim() || 'Unknown';
            
            setTimeout(() => {
                document.getElementById('viewProblemContent').innerHTML = `
                    <div class="row">
                        <div class="col-12">
                            <h6 class="mb-3">${title}</h6>
                            <div class="mb-3">
                                <strong>Description:</strong>
                                <p class="text-muted">${description}</p>
                            </div>
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <strong>Status:</strong> <span class="badge bg-secondary">${status}</span>
                                </div>
                                <div class="col-sm-6">
                                    <strong>Priority:</strong> <span class="badge bg-warning">${priority}</span>
                                </div>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                This is a basic view. For full details, use the API when available.
                            </div>
                        </div>
                    </div>
                `;
            }, 500);
        } else {
            setTimeout(() => {
                document.getElementById('viewProblemContent').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Problem details not found on current page.
                    </div>
                `;
            }, 500);
        }
    }
    
    function refreshData() {
        showToast('Refreshing data...', 'info');
        setTimeout(() => {
            location.reload();
        }, 1000);
    }
    
    function exportProblems() {
        showToast('Export feature will be implemented soon!', 'info');
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
        const bsToast = new bootstrap.Toast(toast, {
            autohide: true,
            delay: duration
        });
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
    
    // Add debug information
    console.log('🔧 FIXED Problems Page Debug Info:');
    console.log('- Problems loaded:', <?php echo count($problems); ?>);
    console.log('- Mechanics available:', <?php echo count($mechanics); ?>);
    console.log('- Current page:', <?php echo $page; ?>);
    console.log('- Total pages:', <?php echo $total_pages; ?>);
    console.log('- Filters active:', '<?php echo ($status_filter !== 'all' || $priority_filter !== 'all' || $category_filter !== 'all' || $assigned_to_filter > 0) ? 'Yes' : 'No'; ?>');
    </script>

    <?php
    // Helper functions for time display
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

</body>
</html>