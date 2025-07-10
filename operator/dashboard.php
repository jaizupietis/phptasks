<?php
/**
 * Enhanced Operator Dashboard
 * Complete problem reporting and management interface with full CRUD operations
 * Replace: /var/www/tasks/operator/dashboard.php
 */

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Check if user is logged in and is an operator
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'operator') {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$is_mobile = isMobile();

// Handle problem deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_problem') {
    $problem_id = (int)$_POST['problem_id'];
    
    try {
        // Verify the problem belongs to the current user and can be deleted
        $problem = $db->fetch(
            "SELECT * FROM problems WHERE id = ? AND reported_by = ? AND status = 'reported'",
            [$problem_id, $user_id]
        );
        
        if ($problem) {
            // Delete the problem (comments and notifications will be deleted automatically due to foreign keys)
            $result = $db->query("DELETE FROM problems WHERE id = ?", [$problem_id]);
            
            if ($result->rowCount() > 0) {
                logActivity("Problem deleted: {$problem['title']}", 'INFO', $user_id);
                $_SESSION['success_message'] = 'Problem deleted successfully!';
            } else {
                $_SESSION['error_message'] = 'Failed to delete problem.';
            }
        } else {
            $_SESSION['error_message'] = 'Problem not found or cannot be deleted.';
        }
    } catch (Exception $e) {
        error_log("Problem deletion error: " . $e->getMessage());
        $_SESSION['error_message'] = 'Error deleting problem: ' . $e->getMessage();
    }
    
    header('Location: dashboard.php');
    exit;
}

// Get user information
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

if (!$user) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Get dashboard statistics
$stats = [
    'total_reported' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ?",
        [$user_id]
    ),
    'pending_assignment' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'reported'",
        [$user_id]
    ),
    'assigned' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'assigned'",
        [$user_id]
    ),
    'in_progress' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'in_progress'",
        [$user_id]
    ),
    'resolved' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'resolved'",
        [$user_id]
    ),
    'urgent_problems' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ? AND priority = 'urgent' AND status NOT IN ('resolved', 'closed')",
        [$user_id]
    ),
    'problems_today' => $db->fetchCount(
        "SELECT COUNT(*) FROM problems WHERE reported_by = ? AND DATE(created_at) = CURDATE()",
        [$user_id]
    )
];

// Get recent problems with detailed information
$recent_problems = $db->fetchAll(
    "SELECT p.*, 
            ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
            ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname,
            t.id as task_id, t.status as task_status, t.title as task_title
     FROM problems p 
     LEFT JOIN users ua ON p.assigned_to = ua.id 
     LEFT JOIN users ub ON p.assigned_by = ub.id 
     LEFT JOIN tasks t ON p.task_id = t.id
     WHERE p.reported_by = ? 
     ORDER BY 
        CASE 
            WHEN p.status = 'reported' THEN 1 
            WHEN p.priority = 'urgent' THEN 2 
            WHEN p.priority = 'high' THEN 3 
            ELSE 4 
        END,
        p.created_at DESC 
     LIMIT 10",
    [$user_id]
);

// Get unread notifications
$notifications = $db->fetchAll(
    "SELECT * FROM notifications 
     WHERE user_id = ? AND is_read = 0 
     ORDER BY created_at DESC 
     LIMIT 5",
    [$user_id]
);

$page_title = 'Operator Dashboard';
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
    <?php if ($is_mobile): ?>
    <link href="../css/mobile.css" rel="stylesheet">
    <?php endif; ?>
    
    <style>
        :root {
            --operator-primary: #17a2b8;
            --operator-secondary: #6c757d;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--operator-primary) 0%, #138496 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .welcome-card {
            background: linear-gradient(135deg, var(--operator-primary) 0%, #138496 100%);
            color: white;
        }
        
        .stats-card {
            text-align: center;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 15px;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: none;
        }
        
        .stats-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }
        
        .stats-icon.primary { background: linear-gradient(135deg, var(--operator-primary), #138496); color: white; }
        .stats-icon.warning { background: linear-gradient(135deg, var(--warning-color), #e0a800); color: #212529; }
        .stats-icon.success { background: linear-gradient(135deg, var(--success-color), #1e7e34); color: white; }
        .stats-icon.danger { background: linear-gradient(135deg, var(--danger-color), #c82333); color: white; }
        .stats-icon.info { background: linear-gradient(135deg, var(--info-color), #138496); color: white; }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--operator-primary);
        }
        
        .stats-label {
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
        }
        
        .problem-card {
            border-left: 4px solid var(--operator-primary);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .problem-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .problem-card.priority-low { border-left-color: var(--success-color); }
        .problem-card.priority-medium { border-left-color: var(--warning-color); }
        .problem-card.priority-high { border-left-color: #fd7e14; }
        .problem-card.priority-urgent { border-left-color: var(--danger-color); }
        
        .priority-badge, .status-badge, .severity-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-low { background: var(--success-color); color: white; }
        .priority-medium { background: var(--warning-color); color: #212529; }
        .priority-high { background: #fd7e14; color: white; }
        .priority-urgent { background: var(--danger-color); color: white; }
        
        .status-reported { background: #6c757d; color: white; }
        .status-assigned { background: var(--operator-primary); color: white; }
        .status-in_progress { background: var(--warning-color); color: #212529; }
        .status-resolved { background: var(--success-color); color: white; }
        .status-closed { background: var(--operator-secondary); color: white; }
        
        .severity-minor { background: #e3f2fd; color: #1976d2; }
        .severity-moderate { background: #fff3e0; color: #f57c00; }
        .severity-major { background: #ffebee; color: #d32f2f; }
        .severity-critical { background: var(--danger-color); color: white; }
        
        .quick-report-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--operator-primary), #138496);
            color: white;
            border: none;
            box-shadow: 0 4px 20px rgba(23, 162, 184, 0.4);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .quick-report-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 25px rgba(23, 162, 184, 0.6);
            color: white;
        }
        
        .btn-operator {
            background: var(--operator-primary);
            border-color: var(--operator-primary);
            color: white;
        }
        
        .btn-operator:hover {
            background: #138496;
            border-color: #138496;
            color: white;
        }
        
        .modal-header {
            background: var(--operator-primary);
            color: white;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .problem-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .problem-actions .btn {
            flex: 1;
            min-width: 80px;
        }
        
        .delete-confirm {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 5px;
            margin: 1rem 0;
        }
        
        .workflow-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
            font-size: 0.875rem;
        }
        
        .workflow-step {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .workflow-step.active {
            background: var(--operator-primary);
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
        
        @media (max-width: 768px) {
            .quick-report-btn {
                bottom: 90px;
            }
            
            .problem-actions {
                flex-direction: column;
            }
            
            .stats-card {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body class="<?php echo $is_mobile ? 'mobile-device' : ''; ?>">
    
    <!-- Top Navigation -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-exclamation-triangle"></i> Operator Panel
            </span>
            <div class="d-flex align-items-center">
                <span class="navbar-text me-3 d-none d-md-inline">
                    Welcome, <?php echo htmlspecialchars($user['first_name']); ?>
                </span>
                <?php if (count($notifications) > 0): ?>
                <button class="btn btn-outline-light btn-sm me-2 position-relative" id="notificationBtn">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge"><?php echo count($notifications); ?></span>
                </button>
                <?php endif; ?>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="d-none d-md-inline ms-1">Logout</span>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid mt-4 <?php echo $is_mobile ? 'mobile-padding' : ''; ?>">
        
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
        
        <!-- Welcome Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card welcome-card">
                    <div class="card-body text-center">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="welcome-icon">
                                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h4 class="mb-1">
                                    Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!
                                </h4>
                                <p class="mb-0 opacity-90">
                                    Report problems and track their resolution status
                                </p>
                                <?php if ($stats['problems_today'] > 0): ?>
                                <small class="opacity-75">
                                    You've reported <?php echo $stats['problems_today']; ?> problem(s) today
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-light" onclick="showReportProblemModal()">
                                    <i class="fas fa-plus"></i> Report Problem
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <a href="problems.php" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon primary">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['total_reported']; ?></div>
                        <div class="stats-label">Total Reported</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-2">
                <a href="problems.php?status=reported" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['pending_assignment']; ?></div>
                        <div class="stats-label">Pending</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-2">
                <a href="problems.php?status=assigned" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon info">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['assigned']; ?></div>
                        <div class="stats-label">Assigned</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-2">
                <a href="problems.php?status=in_progress" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon warning">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['in_progress']; ?></div>
                        <div class="stats-label">In Progress</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-2">
                <a href="problems.php?status=resolved" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['resolved']; ?></div>
                        <div class="stats-label">Resolved</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-2">
                <div class="card stats-card" onclick="showUrgentProblems()">
                    <div class="stats-icon danger">
                        <i class="fas fa-exclamation"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['urgent_problems']; ?></div>
                    <div class="stats-label">Urgent</div>
                </div>
            </div>
        </div>
        
        <!-- Recent Problems -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> Recent Problems
                        </h5>
                        <div class="d-flex gap-2">
                            <a href="problems.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i> View All
                            </a>
                            <button class="btn btn-sm btn-operator" onclick="showReportProblemModal()">
                                <i class="fas fa-plus"></i> Report New
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_problems)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h5>No Problems Reported Yet</h5>
                            <p>You haven't reported any problems yet.<br>Use the button below to report your first problem.</p>
                            <button class="btn btn-operator" onclick="showReportProblemModal()">
                                <i class="fas fa-plus"></i> Report First Problem
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_problems as $problem): ?>
                            <div class="list-group-item problem-card priority-<?php echo $problem['priority']; ?>" id="problem-<?php echo $problem['id']; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="mb-1 fw-bold">
                                        <?php echo htmlspecialchars($problem['title']); ?>
                                        <?php if ($problem['task_id']): ?>
                                        <span class="badge bg-success ms-2">
                                            <i class="fas fa-wrench"></i> Task #<?php echo $problem['task_id']; ?>
                                        </span>
                                        <?php endif; ?>
                                    </h6>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="status-badge status-<?php echo $problem['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $problem['status'])); ?>
                                        </span>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="#" onclick="viewProblem(<?php echo $problem['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View Details</a></li>
                                                <?php if ($problem['status'] === 'reported'): ?>
                                                <li><a class="dropdown-item" href="#" onclick="editProblem(<?php echo $problem['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit Problem</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteProblem(<?php echo $problem['id']; ?>)">
                                                    <i class="fas fa-trash"></i> Delete Problem</a></li>
                                                <?php endif; ?>
                                                <?php if ($problem['task_id']): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="../mechanic/tasks.php?task_id=<?php echo $problem['task_id']; ?>">
                                                    <i class="fas fa-wrench"></i> View Task</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Workflow Indicator -->
                                <div class="workflow-indicator">
                                    <div class="workflow-step <?php echo $problem['status'] === 'reported' ? 'active' : 'completed'; ?>">
                                        Reported
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                    <div class="workflow-step <?php echo $problem['status'] === 'assigned' ? 'active' : ($problem['status'] === 'reported' ? 'pending' : 'completed'); ?>">
                                        Assigned
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                    <div class="workflow-step <?php echo $problem['status'] === 'in_progress' ? 'active' : ($problem['status'] === 'resolved' ? 'completed' : 'pending'); ?>">
                                        In Progress
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                    <div class="workflow-step <?php echo $problem['status'] === 'resolved' ? 'active' : 'pending'; ?>">
                                        Resolved
                                    </div>
                                </div>
                                
                                <?php if ($problem['description']): ?>
                                <p class="mb-2 text-muted">
                                    <?php echo htmlspecialchars(substr($problem['description'], 0, 120)); ?>
                                    <?php if (strlen($problem['description']) > 120): ?>...<?php endif; ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="row g-2 mb-2">
                                    <div class="col-auto">
                                        <span class="priority-badge priority-<?php echo $problem['priority']; ?>">
                                            <i class="fas fa-flag"></i> <?php echo ucfirst($problem['priority']); ?>
                                        </span>
                                    </div>
                                    <div class="col-auto">
                                        <span class="severity-badge severity-<?php echo $problem['severity']; ?>">
                                            <i class="fas fa-exclamation-circle"></i> <?php echo ucfirst($problem['severity']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($problem['location']): ?>
                                    <div class="col-auto">
                                        <small class="text-muted">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($problem['location']); ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($problem['equipment']): ?>
                                    <div class="col-auto">
                                        <small class="text-muted">
                                            <i class="fas fa-tools"></i>
                                            <?php echo htmlspecialchars($problem['equipment']); ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="text-muted">
                                        <small>
                                            <i class="fas fa-clock"></i>
                                            Reported: <?php echo timeAgo($problem['created_at']); ?>
                                        </small>
                                        <?php if ($problem['assigned_to_name']): ?>
                                        <br>
                                        <small>
                                            <i class="fas fa-user"></i>
                                            Assigned to: <?php echo htmlspecialchars($problem['assigned_to_name'] . ' ' . $problem['assigned_to_lastname']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="text-end">
                                        <small class="text-muted">
                                            <strong>Impact:</strong> <?php echo ucfirst($problem['impact']); ?> |
                                            <strong>Urgency:</strong> <?php echo ucfirst($problem['urgency']); ?>
                                        </small>
                                        <?php if ($problem['estimated_resolution_time']): ?>
                                        <br>
                                        <small class="text-muted">
                                            <strong>Est. Time:</strong> <?php echo $problem['estimated_resolution_time']; ?>h
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="problem-actions">
                                    <button class="btn btn-outline-primary btn-sm" onclick="viewProblem(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                    <?php if ($problem['status'] === 'reported'): ?>
                                    <button class="btn btn-outline-warning btn-sm" onclick="editProblem(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteProblem(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($problem['task_id']): ?>
                                    <a href="../mechanic/tasks.php?task_id=<?php echo $problem['task_id']; ?>" class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-wrench"></i> View Task
                                    </a>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($problem['resolved_at']): ?>
                                <div class="mt-2">
                                    <small class="text-success">
                                        <i class="fas fa-check-circle"></i>
                                        Resolved: <?php echo date('M j, Y g:i A', strtotime($problem['resolved_at'])); ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Mobile Bottom Navigation -->
    <?php if ($is_mobile): ?>
    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item active">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="problems.php" class="bottom-nav-item">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Problems</span>
            <?php if ($stats['pending_assignment'] > 0): ?>
            <span class="notification-badge"><?php echo $stats['pending_assignment']; ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="bottom-nav-item">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
        <a href="../logout.php" class="bottom-nav-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
    <?php endif; ?>
    
    <!-- Quick Report Button -->
    <button class="quick-report-btn" onclick="showReportProblemModal()" title="Report Problem">
        <i class="fas fa-plus"></i>
    </button>
    
    <!-- Report Problem Modal -->
    <div class="modal fade" id="reportProblemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Report New Problem</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="reportProblemForm" novalidate>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="problemTitle" class="form-label">Problem Title *</label>
                                    <input type="text" class="form-control" id="problemTitle" required
                                           placeholder="Brief description of the problem">
                                    <div class="invalid-feedback">Please provide a problem title.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="problemDescription" class="form-label">Detailed Description</label>
                                    <textarea class="form-control" id="problemDescription" rows="4" 
                                              placeholder="Provide detailed information about the problem, when it occurred, symptoms, etc."></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="problemLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="problemLocation" 
                                                   placeholder="Workshop Bay 1, Production Line A, etc.">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="problemEquipment" class="form-label">Equipment/Asset</label>
                                            <input type="text" class="form-control" id="problemEquipment" 
                                                   placeholder="Machine ID, Vehicle number, etc.">
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="problemCategory" class="form-label">Category</label>
                                    <select class="form-select" id="problemCategory">
                                        <option value="">Select Category</option>
                                        <option value="Mechanical">Mechanical</option>
                                        <option value="Electrical">Electrical</option>
                                        <option value="Hydraulic System">Hydraulic System</option>
                                        <option value="Engine">Engine</option>
                                        <option value="Brake System">Brake System</option>
                                        <option value="Safety">Safety</option>
                                        <option value="Environmental">Environmental</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="problemPriority" class="form-label">Priority *</label>
                                    <select class="form-select" id="problemPriority" required>
                                        <option value="medium">Medium</option>
                                        <option value="low">Low</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        Urgent: Immediate attention required<br>
                                        High: Address within hours<br>
                                        Medium: Address within days<br>
                                        Low: Address when convenient
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label for="problemSeverity" class="form-label">Severity</label>
                                    <select class="form-select" id="problemSeverity">
                                        <option value="moderate">Moderate</option>
                                        <option value="minor">Minor</option>
                                        <option value="major">Major</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        How serious is the problem?
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label for="problemImpact" class="form-label">Impact</label>
                                    <select class="form-select" id="problemImpact">
                                        <option value="medium">Medium</option>
                                        <option value="low">Low</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        How many people/processes affected?
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label for="problemUrgency" class="form-label">Urgency</label>
                                    <select class="form-select" id="problemUrgency">
                                        <option value="medium">Medium</option>
                                        <option value="low">Low</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        How quickly does this need to be resolved?
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label for="estimatedTime" class="form-label">Estimated Resolution Time</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="estimatedTime" min="0.5" step="0.5">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                    <small class="form-text text-muted">
                                        Your estimate (optional)
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-operator" id="reportProblemBtn">
                            <i class="fas fa-exclamation-triangle"></i> Report Problem
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Problem Modal -->
    <div class="modal fade" id="editProblemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Problem</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editProblemForm" novalidate>
                    <input type="hidden" id="editProblemId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="editProblemTitle" class="form-label">Problem Title *</label>
                                    <input type="text" class="form-control" id="editProblemTitle" required>
                                    <div class="invalid-feedback">Please provide a problem title.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="editProblemDescription" class="form-label">Detailed Description</label>
                                    <textarea class="form-control" id="editProblemDescription" rows="4"></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProblemLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="editProblemLocation">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="editProblemEquipment" class="form-label">Equipment/Asset</label>
                                            <input type="text" class="form-control" id="editProblemEquipment">
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="editProblemCategory" class="form-label">Category</label>
                                    <select class="form-select" id="editProblemCategory">
                                        <option value="">Select Category</option>
                                        <option value="Mechanical">Mechanical</option>
                                        <option value="Electrical">Electrical</option>
                                        <option value="Hydraulic System">Hydraulic System</option>
                                        <option value="Engine">Engine</option>
                                        <option value="Brake System">Brake System</option>
                                        <option value="Safety">Safety</option>
                                        <option value="Environmental">Environmental</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="editProblemPriority" class="form-label">Priority *</label>
                                    <select class="form-select" id="editProblemPriority" required>
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editProblemSeverity" class="form-label">Severity</label>
                                    <select class="form-select" id="editProblemSeverity">
                                        <option value="minor">Minor</option>
                                        <option value="moderate">Moderate</option>
                                        <option value="major">Major</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editProblemImpact" class="form-label">Impact</label>
                                    <select class="form-select" id="editProblemImpact">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editProblemUrgency" class="form-label">Urgency</label>
                                    <select class="form-select" id="editProblemUrgency">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="editEstimatedTime" class="form-label">Estimated Resolution Time</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="editEstimatedTime" min="0.5" step="0.5">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-operator" id="editProblemBtn">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Problem Modal -->
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
    
    <!-- Delete Problem Modal -->
    <div class="modal fade" id="deleteProblemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Problem</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="delete-confirm">
                        <h6><i class="fas fa-exclamation-triangle"></i> Are you sure?</h6>
                        <p>This action cannot be undone. The problem will be permanently deleted.</p>
                    </div>
                    <div id="deleteProblemDetails">
                        <!-- Problem details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_problem">
                        <input type="hidden" name="problem_id" id="deleteProblemId">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Problem
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        setupForms();
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        console.log('Enhanced Operator Dashboard loaded successfully');
        console.log('User stats:', <?php echo json_encode($stats); ?>);
    });
    
    function setupForms() {
        // Report Problem Form
        const reportForm = document.getElementById('reportProblemForm');
        if (reportForm) {
            reportForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (this.checkValidity()) {
                    handleProblemReporting();
                } else {
                    this.classList.add('was-validated');
                    showToast('Please check the form for errors', 'warning');
                }
            });
        }
        
        // Edit Problem Form
        const editForm = document.getElementById('editProblemForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (this.checkValidity()) {
                    handleProblemEdit();
                } else {
                    this.classList.add('was-validated');
                    showToast('Please check the form for errors', 'warning');
                }
            });
        }
    }
    
    function handleProblemReporting() {
        console.log('=== Starting Problem Reporting ===');
        
        const title = document.getElementById('problemTitle').value.trim();
        const priority = document.getElementById('problemPriority').value;
        
        if (!title) {
            showToast('Please enter a problem title', 'warning');
            return;
        }
        
        const problemData = {
            action: 'create_problem',
            title: title,
            description: document.getElementById('problemDescription').value.trim(),
            priority: priority,
            category: document.getElementById('problemCategory').value.trim(),
            location: document.getElementById('problemLocation').value.trim(),
            equipment: document.getElementById('problemEquipment').value.trim(),
            severity: document.getElementById('problemSeverity').value,
            impact: document.getElementById('problemImpact').value,
            urgency: document.getElementById('problemUrgency').value,
            estimated_resolution_time: parseFloat(document.getElementById('estimatedTime').value) || null
        };
        
        console.log('Sending problem data:', problemData);
        
        const submitBtn = document.getElementById('reportProblemBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reporting...';
        submitBtn.disabled = true;
        
        fetch('../api/problems.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(problemData),
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
            
            console.log('Parsed data:', data);
            
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            if (data.success) {
                console.log('✅ Problem reported successfully!');
                showToast('Problem reported successfully! Managers have been notified.', 'success');
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('reportProblemModal'));
                if (modal) modal.hide();
                
                document.getElementById('reportProblemForm').reset();
                document.getElementById('reportProblemForm').classList.remove('was-validated');
                
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
            } else {
                console.error('❌ API Error:', data.message);
                showToast('Error: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('💥 Request failed:', error);
            
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            showToast('Network error: ' + error.message, 'danger');
        });
    }
    
    function handleProblemEdit() {
        const problemId = document.getElementById('editProblemId').value;
        
        const problemData = {
            action: 'update_problem',
            problem_id: parseInt(problemId),
            title: document.getElementById('editProblemTitle').value.trim(),
            description: document.getElementById('editProblemDescription').value.trim(),
            priority: document.getElementById('editProblemPriority').value,
            category: document.getElementById('editProblemCategory').value.trim(),
            location: document.getElementById('editProblemLocation').value.trim(),
            equipment: document.getElementById('editProblemEquipment').value.trim(),
            severity: document.getElementById('editProblemSeverity').value,
            impact: document.getElementById('editProblemImpact').value,
            urgency: document.getElementById('editProblemUrgency').value,
            estimated_resolution_time: parseFloat(document.getElementById('editEstimatedTime').value) || null
        };
        
        console.log('Updating problem:', problemData);
        
        const submitBtn = document.getElementById('editProblemBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        
        fetch('../api/problems.php', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(problemData),
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            if (data.success) {
                showToast('Problem updated successfully!', 'success');
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('editProblemModal'));
                if (modal) modal.hide();
                
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast('Error: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('Edit error:', error);
            showToast('Network error: ' + error.message, 'danger');
            
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    }
    
    function showReportProblemModal() {
        const form = document.getElementById('reportProblemForm');
        if (form) {
            form.reset();
            form.classList.remove('was-validated');
        }
        
        const modal = new bootstrap.Modal(document.getElementById('reportProblemModal'));
        modal.show();
    }
    
    function viewProblem(problemId) {
        console.log('Viewing problem:', problemId);
        
        fetch(`../api/problems.php?action=get_problem&id=${problemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.problem) {
                const problem = data.problem;
                document.getElementById('viewProblemContent').innerHTML = `
                    <div class="row">
                        <div class="col-md-8">
                            <h6>${problem.title}</h6>
                            <p class="text-muted">${problem.description || 'No description provided'}</p>
                            
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <strong>Location:</strong> ${problem.location || 'Not specified'}
                                </div>
                                <div class="col-sm-6">
                                    <strong>Equipment:</strong> ${problem.equipment || 'Not specified'}
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-sm-6">
                                    <strong>Category:</strong> ${problem.category || 'Not specified'}
                                </div>
                                <div class="col-sm-6">
                                    <strong>Reported:</strong> ${new Date(problem.created_at).toLocaleString()}
                                </div>
                            </div>
                            
                            ${problem.assigned_to_name ? `
                            <div class="alert alert-info">
                                <strong>Assigned to:</strong> ${problem.assigned_to_name} ${problem.assigned_to_lastname || ''}
                            </div>
                            ` : ''}
                            
                            ${problem.task_title ? `
                            <div class="alert alert-success">
                                <strong>Task Created:</strong> ${problem.task_title}
                            </div>
                            ` : ''}
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <span class="status-badge status-${problem.status}">
                                    ${problem.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                </span>
                            </div>
                            <div class="mb-3">
                                <span class="priority-badge priority-${problem.priority}">
                                    ${problem.priority.charAt(0).toUpperCase() + problem.priority.slice(1)} Priority
                                </span>
                            </div>
                            <div class="mb-3">
                                <span class="severity-badge severity-${problem.severity}">
                                    ${problem.severity.charAt(0).toUpperCase() + problem.severity.slice(1)} Severity
                                </span>
                            </div>
                            
                            <hr>
                            
                            <div class="mb-2">
                                <strong>Impact:</strong> ${problem.impact.charAt(0).toUpperCase() + problem.impact.slice(1)}
                            </div>
                            <div class="mb-2">
                                <strong>Urgency:</strong> ${problem.urgency.charAt(0).toUpperCase() + problem.urgency.slice(1)}
                            </div>
                            ${problem.estimated_resolution_time ? `
                            <div class="mb-2">
                                <strong>Est. Time:</strong> ${problem.estimated_resolution_time}h
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
                
                const modal = new bootstrap.Modal(document.getElementById('viewProblemModal'));
                modal.show();
            } else {
                showToast('Failed to load problem details', 'danger');
            }
        })
        .catch(error => {
            console.error('View problem error:', error);
            showToast('Error loading problem: ' + error.message, 'danger');
        });
    }
    
    function editProblem(problemId) {
        console.log('Editing problem:', problemId);
        
        fetch(`../api/problems.php?action=get_problem&id=${problemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.problem) {
                const problem = data.problem;
                
                // Populate edit form
                document.getElementById('editProblemId').value = problem.id;
                document.getElementById('editProblemTitle').value = problem.title || '';
                document.getElementById('editProblemDescription').value = problem.description || '';
                document.getElementById('editProblemLocation').value = problem.location || '';
                document.getElementById('editProblemEquipment').value = problem.equipment || '';
                document.getElementById('editProblemCategory').value = problem.category || '';
                document.getElementById('editProblemPriority').value = problem.priority || 'medium';
                document.getElementById('editProblemSeverity').value = problem.severity || 'moderate';
                document.getElementById('editProblemImpact').value = problem.impact || 'medium';
                document.getElementById('editProblemUrgency').value = problem.urgency || 'medium';
                document.getElementById('editEstimatedTime').value = problem.estimated_resolution_time || '';
                
                // Clear validation
                const form = document.getElementById('editProblemForm');
                form.classList.remove('was-validated');
                
                const modal = new bootstrap.Modal(document.getElementById('editProblemModal'));
                modal.show();
            } else {
                showToast('Failed to load problem details', 'danger');
            }
        })
        .catch(error => {
            console.error('Edit problem error:', error);
            showToast('Error loading problem: ' + error.message, 'danger');
        });
    }
    
    function deleteProblem(problemId) {
        console.log('Preparing to delete problem:', problemId);
        
        fetch(`../api/problems.php?action=get_problem&id=${problemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.problem) {
                const problem = data.problem;
                
                document.getElementById('deleteProblemId').value = problemId;
                document.getElementById('deleteProblemDetails').innerHTML = `
                    <p><strong>Problem:</strong> ${problem.title}</p>
                    <p><strong>Status:</strong> ${problem.status}</p>
                    <p><strong>Priority:</strong> ${problem.priority}</p>
                    <p><strong>Reported:</strong> ${new Date(problem.created_at).toLocaleDateString()}</p>
                `;
                
                const modal = new bootstrap.Modal(document.getElementById('deleteProblemModal'));
                modal.show();
            } else {
                showToast('Failed to load problem details', 'danger');
            }
        })
        .catch(error => {
            console.error('Delete preparation error:', error);
            showToast('Error loading problem details', 'danger');
        });
    }
    
    function showUrgentProblems() {
        const urgentCount = <?php echo $stats['urgent_problems']; ?>;
        if (urgentCount > 0) {
            window.location.href = 'problems.php?priority=urgent';
        } else {
            showToast('No urgent problems! Great job!', 'success');
        }
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
                    <i class="fas fa-${getToastIcon(type)}"></i> ${message}
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
    
    function getToastIcon(type) {
        const icons = {
            success: 'check-circle',
            danger: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    // Test API function for debugging
    function testProblemsAPI() {
        console.log('Testing Problems API...');
        
        fetch('../api/problems.php?action=test')
        .then(r => r.json())
        .then(data => {
            console.log('API Test Result:', data);
            showToast(data.success ? '✅ Problems API Working!' : '❌ API Failed: ' + data.message, 
                     data.success ? 'success' : 'danger');
        })
        .catch(err => {
            console.error('API Test Error:', err);
            showToast('❌ API Test Failed: ' + err.message, 'danger');
        });
    }
    </script>
</body>
</html>