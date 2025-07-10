<?php
/**
 * Operator Dashboard
 * Problem reporting and management interface
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
    )
];

// Get recent problems
$recent_problems = $db->fetchAll(
    "SELECT p.*, 
            ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
            t.id as task_id, t.status as task_status
     FROM problems p 
     LEFT JOIN users ua ON p.assigned_to = ua.id 
     LEFT JOIN tasks t ON p.task_id = t.id
     WHERE p.reported_by = ? 
     ORDER BY p.created_at DESC 
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
        
        .problem-card {
            border-left: 4px solid var(--operator-primary);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
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
        
        @media (max-width: 768px) {
            .quick-report-btn {
                bottom: 90px;
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
        
        <!-- Success Message -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i>
            <?php 
            switch($_GET['success']) {
                case 'problem_reported':
                    echo 'Problem reported successfully! Managers have been notified.';
                    break;
                default:
                    echo 'Action completed successfully!';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
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
                        <a href="problems.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> View All
                        </a>
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
                                            <i class="fas fa-wrench"></i> Task Created
                                        </span>
                                        <?php endif; ?>
                                    </h6>
                                    <span class="status-badge status-<?php echo $problem['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $problem['status'])); ?>
                                    </span>
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
                                
                                <div class="d-flex justify-content-between align-items-center">
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
                                    
                                    <div class="btn-group btn-group-sm">
                                        <a href="problems.php?problem_id=<?php echo $problem['id']; ?>" class="btn btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if ($problem['status'] === 'reported'): ?>
                                        <button class="btn btn-outline-warning" onclick="editProblem(<?php echo $problem['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($problem['task_id']): ?>
                                        <a href="../mechanic/tasks.php?task_id=<?php echo $problem['task_id']; ?>" class="btn btn-outline-success">
                                            <i class="fas fa-wrench"></i> View Task
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
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
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        setupProblemForm();
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        console.log('Operator Dashboard loaded successfully');
        console.log('User stats:', <?php echo json_encode($stats); ?>);
    });
    
    function setupProblemForm() {
        const form = document.getElementById('reportProblemForm');
        if (!form) return;
        
        form.addEventListener('submit', function(e) {
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
    
    function showReportProblemModal() {
        const form = document.getElementById('reportProblemForm');
        if (form) {
            form.reset();
            form.classList.remove('was-validated');
        }
        
        const modal = new bootstrap.Modal(document.getElementById('reportProblemModal'));
        modal.show();
    }
    
    function editProblem(problemId) {
        showToast('Problem editing feature will be implemented soon!', 'info');
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
    
    // Test API function
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
    
    // Add debug button for testing
    setTimeout(() => {
        const navbar = document.querySelector('.navbar .container-fluid');
        if (navbar && window.location.hostname === 'localhost') {
            const debugBtn = document.createElement('button');
            debugBtn.className = 'btn btn-outline-light btn-sm me-2';
            debugBtn.innerHTML = '<i class="fas fa-bug"></i> Test API';
            debugBtn.onclick = testProblemsAPI;
            navbar.appendChild(debugBtn);
        }
    }, 1000);
    </script>
</body>
</html>