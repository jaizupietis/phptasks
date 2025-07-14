<?php
/**
 * FIXED Operator Dashboard with Problem Reporting
 * This version ensures operators can properly report problems
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

// Get operator information
$operator = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

if (!$operator) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Handle problem submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report_problem') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $category = trim($_POST['category'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $equipment = trim($_POST['equipment'] ?? '');
    $severity = $_POST['severity'] ?? 'moderate';
    $impact = $_POST['impact'] ?? 'medium';
    $urgency = $_POST['urgency'] ?? 'medium';
    $estimated_time = !empty($_POST['estimated_time']) ? (int)$_POST['estimated_time'] : null;
    
    if (!empty($title)) {
        try {
            $problem_data = [
                'title' => $title,
                'description' => $description,
                'priority' => $priority,
                'category' => $category,
                'location' => $location,
                'equipment' => $equipment,
                'reported_by' => $user_id,
                'severity' => $severity,
                'impact' => $impact,
                'urgency' => $urgency,
                'estimated_resolution_time' => $estimated_time
            ];
            
            $fields = array_keys($problem_data);
            $placeholders = array_fill(0, count($fields), '?');
            $values = array_values($problem_data);
            
            $sql = "INSERT INTO problems (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->query($sql, $values);
            $new_problem_id = $db->getConnection()->lastInsertId();
            
            if ($new_problem_id) {
                // Create notifications for managers
                try {
                    $managers = $db->fetchAll("SELECT id FROM users WHERE role IN ('manager', 'admin') AND is_active = 1");
                    foreach ($managers as $manager) {
                        $db->query(
                            "INSERT INTO notifications (user_id, problem_id, type, title, message) 
                             VALUES (?, ?, 'problem_reported', 'New Problem Reported', ?)",
                            [$manager['id'], $new_problem_id, "Problem: '{$title}'"]
                        );
                    }
                } catch (Exception $e) {
                    error_log("Problem notification error: " . $e->getMessage());
                }
                
                logActivity("Problem reported: {$title}", 'INFO', $user_id);
                $_SESSION['success_message'] = 'Problem reported successfully!';
            }
        } catch (Exception $e) {
            error_log("Problem creation error: " . $e->getMessage());
            $_SESSION['error_message'] = 'Error reporting problem: ' . $e->getMessage();
        }
        
        header('Location: dashboard.php');
        exit;
    } else {
        $_SESSION['error_message'] = 'Problem title is required.';
    }
}

// Get operator statistics
try {
    $stats = [
        'total_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE reported_by = ?", [$user_id]),
        'reported_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'reported'", [$user_id]),
        'assigned_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'assigned'", [$user_id]),
        'resolved_problems' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE reported_by = ? AND status = 'resolved'", [$user_id]),
        'problems_today' => $db->fetchCount("SELECT COUNT(*) FROM problems WHERE reported_by = ? AND DATE(created_at) = CURDATE()", [$user_id])
    ];
} catch (Exception $e) {
    error_log("Operator stats error: " . $e->getMessage());
    $stats = ['total_problems' => 0, 'reported_problems' => 0, 'assigned_problems' => 0, 'resolved_problems' => 0, 'problems_today' => 0];
}

// Get recent problems
try {
    $recent_problems = $db->fetchAll(
        "SELECT p.*, 
                ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
                t.title as task_title, t.status as task_status
         FROM problems p 
         LEFT JOIN users ua ON p.assigned_to = ua.id 
         LEFT JOIN tasks t ON p.task_id = t.id
         WHERE p.reported_by = ?
         ORDER BY p.created_at DESC 
         LIMIT 10",
        [$user_id]
    );
} catch (Exception $e) {
    error_log("Recent problems error: " . $e->getMessage());
    $recent_problems = [];
}

// Get notifications
try {
    $notifications = $db->fetchAll(
        "SELECT * FROM notifications 
         WHERE user_id = ? AND is_read = 0 
         ORDER BY created_at DESC 
         LIMIT 5",
        [$user_id]
    );
} catch (Exception $e) {
    error_log("Notifications error: " . $e->getMessage());
    $notifications = [];
}

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
    
    <style>
        :root {
            --operator-primary: #17a2b8;
            --operator-secondary: #138496;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .operator-navbar {
            background: linear-gradient(135deg, var(--operator-primary) 0%, var(--operator-secondary) 100%);
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
            text-align: center;
            padding: 1.5rem;
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
            background: linear-gradient(90deg, var(--operator-primary), var(--operator-secondary));
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
            margin: 0 auto 1rem;
        }
        
        .stats-icon.primary { background: linear-gradient(135deg, var(--operator-primary), var(--operator-secondary)); }
        .stats-icon.success { background: linear-gradient(135deg, var(--success-color), #1e7e34); }
        .stats-icon.warning { background: linear-gradient(135deg, var(--warning-color), #e0a800); }
        .stats-icon.danger { background: linear-gradient(135deg, var(--danger-color), #c82333); }
        
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
        }
        
        .problem-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .problem-card.priority-urgent { border-left-color: var(--danger-color); }
        .problem-card.priority-high { border-left-color: #fd7e14; }
        .problem-card.priority-medium { border-left-color: var(--warning-color); }
        .problem-card.priority-low { border-left-color: var(--success-color); }
        
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
        
        .status-reported { background: #6c757d; color: white; }
        .status-assigned { background: var(--operator-primary); color: white; }
        .status-in_progress { background: var(--warning-color); color: #212529; }
        .status-resolved { background: var(--success-color); color: white; }
        .status-closed { background: #6c757d; color: white; }
        
        .btn-operator {
            background: var(--operator-primary);
            border-color: var(--operator-primary);
            color: white;
        }
        
        .btn-operator:hover {
            background: var(--operator-secondary);
            border-color: var(--operator-secondary);
            color: white;
        }
        
        .report-form {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 2rem;
            margin-bottom: 2rem;
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
        
        .modal-header {
            background: var(--operator-primary);
            color: white;
        }
        
        @media (max-width: 768px) {
            .stats-card {
                margin-bottom: 1rem;
                padding: 1rem;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .report-form {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark operator-navbar">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-exclamation-triangle"></i> Operator Dashboard
            </span>
            <div class="d-flex align-items-center">
                <span class="me-3">Welcome, <?php echo htmlspecialchars($operator['first_name']); ?>!</span>
                
                <?php if (count($notifications) > 0): ?>
                <button class="btn btn-outline-light btn-sm me-2 position-relative" onclick="showNotifications()">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge"><?php echo count($notifications); ?></span>
                </button>
                <?php endif; ?>
                
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-cog"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="showReportModal()">
                            <i class="fas fa-plus"></i> Report Problem</a></li>
                        <li><a class="dropdown-item" href="problems.php">
                            <i class="fas fa-list"></i> My Problems</a></li>
                        <li><a class="dropdown-item" href="profile.php">
                            <i class="fas fa-user"></i> Profile</a></li>
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
                <div class="card" style="background: linear-gradient(135deg, var(--operator-primary), var(--operator-secondary)); color: white;">
                    <div class="card-body text-center">
                        <h3><i class="fas fa-exclamation-triangle"></i> Problem Reporting System</h3>
                        <p class="mb-0">Report equipment issues and track their resolution status</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card stats-card">
                    <div class="stats-icon primary">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['total_problems']; ?></div>
                    <div class="stats-label">Total Problems</div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stats-card">
                    <div class="stats-icon warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['reported_problems']; ?></div>
                    <div class="stats-label">Awaiting Action</div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stats-card">
                    <div class="stats-icon primary">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['assigned_problems']; ?></div>
                    <div class="stats-label">Being Fixed</div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stats-card">
                    <div class="stats-icon success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['resolved_problems']; ?></div>
                    <div class="stats-label">Resolved</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Report Problem Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="report-form">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="fas fa-plus-circle"></i> Quick Problem Report
                        </h5>
                        <button class="btn btn-operator" onclick="showReportModal()">
                            <i class="fas fa-plus"></i> Detailed Report
                        </button>
                    </div>
                    
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="report_problem">
                        
                        <div class="col-md-6">
                            <label for="quickTitle" class="form-label">Problem Title *</label>
                            <input type="text" class="form-control" id="quickTitle" name="title" required 
                                   placeholder="Brief description of the problem">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="quickPriority" class="form-label">Priority *</label>
                            <select class="form-select" id="quickPriority" name="priority" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="quickLocation" class="form-label">Location</label>
                            <input type="text" class="form-control" id="quickLocation" name="location" 
                                   placeholder="Where is the problem?">
                        </div>
                        
                        <div class="col-12">
                            <label for="quickDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="quickDescription" name="description" rows="2" 
                                      placeholder="Describe what happened and when..."></textarea>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-operator">
                                <i class="fas fa-paper-plane"></i> Report Problem
                            </button>
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Recent Problems -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> My Recent Problems
                        </h5>
                        <a href="problems.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_problems)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5>No Problems Reported</h5>
                            <p class="text-muted">You haven't reported any problems yet.<br>Use the form above to report equipment issues.</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_problems as $problem): ?>
                            <div class="list-group-item problem-card priority-<?php echo $problem['priority']; ?>">
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
                                
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="priority-badge priority-<?php echo $problem['priority']; ?>">
                                        <?php echo ucfirst($problem['priority']); ?>
                                    </span>
                                    
                                    <?php if ($problem['category']): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($problem['category']); ?>
                                    </small>
                                    <?php endif; ?>
                                    
                                    <?php if ($problem['location']): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($problem['location']); ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($problem['description']): ?>
                                <p class="mb-2 text-muted">
                                    <?php echo htmlspecialchars(substr($problem['description'], 0, 100)); ?>
                                    <?php if (strlen($problem['description']) > 100): ?>...<?php endif; ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="fas fa-clock"></i>
                                        Reported: <?php echo date('M j, Y g:i A', strtotime($problem['created_at'])); ?>
                                    </small>
                                    
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($problem['task_id']): ?>
                                        <span class="btn btn-outline-success btn-sm disabled">
                                            <i class="fas fa-wrench"></i> Task #<?php echo $problem['task_id']; ?>
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($problem['assigned_to_name']): ?>
                                        <span class="btn btn-outline-info btn-sm disabled">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($problem['assigned_to_name']); ?>
                                        </span>
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
    
    <!-- Detailed Report Modal -->
    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Detailed Problem Report</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="detailedReportForm">
                    <input type="hidden" name="action" value="report_problem">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="detailTitle" class="form-label">Problem Title *</label>
                                    <input type="text" class="form-control" id="detailTitle" name="title" required 
                                           placeholder="Brief but descriptive title">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="detailDescription" class="form-label">Detailed Description *</label>
                                    <textarea class="form-control" id="detailDescription" name="description" rows="4" required
                                              placeholder="Describe what happened, when it started, what you were doing, etc."></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="detailLocation" class="form-label">Location</label>
                                            <input type="text" class="form-control" id="detailLocation" name="location" 
                                                   placeholder="Building, floor, room, area">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="detailEquipment" class="form-label">Equipment/Asset</label>
                                            <input type="text" class="form-control" id="detailEquipment" name="equipment" 
                                                   placeholder="Machine name, asset number">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="detailCategory" class="form-label">Category</label>
                                    <select class="form-select" id="detailCategory" name="category">
                                        <option value="">Select category</option>
                                        <option value="Mechanical">Mechanical</option>
                                        <option value="Electrical">Electrical</option>
                                        <option value="Hydraulic System">Hydraulic System</option>
                                        <option value="Engine">Engine</option>
                                        <option value="Brake System">Brake System</option>
                                        <option value="Safety">Safety</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="detailPriority" class="form-label">Priority *</label>
                                    <select class="form-select" id="detailPriority" name="priority" required>
                                        <option value="low">Low - Can wait</option>
                                        <option value="medium" selected>Medium - Normal</option>
                                        <option value="high">High - Important</option>
                                        <option value="urgent">Urgent - Critical</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="detailSeverity" class="form-label">Severity</label>
                                    <select class="form-select" id="detailSeverity" name="severity">
                                        <option value="minor">Minor</option>
                                        <option value="moderate" selected>Moderate</option>
                                        <option value="major">Major</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="detailImpact" class="form-label">Impact</label>
                                    <select class="form-select" id="detailImpact" name="impact">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="detailUrgency" class="form-label">Urgency</label>
                                    <select class="form-select" id="detailUrgency" name="urgency">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="estimatedTime" class="form-label">Est. Fix Time (hours)</label>
                                    <input type="number" class="form-control" id="estimatedTime" name="estimated_time" 
                                           min="0.5" step="0.5" placeholder="2.0">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-operator">
                            <i class="fas fa-paper-plane"></i> Submit Problem Report
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
        console.log('✅ FIXED Operator Dashboard loaded successfully');
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        console.log('Operator stats:', <?php echo json_encode($stats); ?>);
    });
    
    function showReportModal() {
        const modal = new bootstrap.Modal(document.getElementById('reportModal'));
        modal.show();
    }
    
    function showNotifications() {
        const notifications = <?php echo json_encode($notifications); ?>;
        
        if (notifications.length > 0) {
            let notificationHtml = '<div class="alert alert-info"><h6>Recent Notifications:</h6><ul>';
            notifications.forEach(notification => {
                notificationHtml += `<li>${notification.title}: ${notification.message}</li>`;
            });
            notificationHtml += '</ul></div>';
            
            // Show in a modal or as a toast
            showToast('You have ' + notifications.length + ' unread notifications', 'info');
        } else {
            showToast('No new notifications', 'info');
        }
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
    
    // Form validation
    document.getElementById('detailedReportForm').addEventListener('submit', function(e) {
        const title = document.getElementById('detailTitle').value.trim();
        const description = document.getElementById('detailDescription').value.trim();
        
        if (title.length < 5) {
            e.preventDefault();
            showToast('Problem title must be at least 5 characters long', 'warning');
            return;
        }
        
        if (description.length < 10) {
            e.preventDefault();
            showToast('Description must be at least 10 characters long', 'warning');
            return;
        }
        
        // Show loading state
        const submitBtn = e.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        submitBtn.disabled = true;
        
        // Allow form to submit normally
    });
    
    // Debug information
    console.log('🔧 Operator Dashboard Debug Info:');
    console.log('- Problems reported:', <?php echo $stats['total_problems']; ?>);
    console.log('- Recent problems:', <?php echo count($recent_problems); ?>);
    console.log('- Notifications:', <?php echo count($notifications); ?>);
    </script>
</body>
</html>