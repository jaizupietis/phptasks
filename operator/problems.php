<?php
/**
 * Operator Problems Management Page
 * View and manage reported problems
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

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$priority_filter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : 'all';
$category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : 'all';

// Build WHERE clause for filters
$where_conditions = ["reported_by = ?"];
$params = [$user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "priority = ?";
    $params[] = $priority_filter;
}

if ($category_filter !== 'all') {
    $where_conditions[] = "category = ?";
    $params[] = $category_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Get problems with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = $is_mobile ? 5 : 10;
$offset = ($page - 1) * $per_page;

$problems = $db->fetchAll(
    "SELECT p.*, 
            ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
            ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname,
            t.id as task_id, t.status as task_status, t.title as task_title
     FROM problems p 
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

$total_pages = ceil($total_problems / $per_page);

// Get problem statistics for current filters
$problem_stats = [
    'total' => $total_problems,
    'reported' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$where_clause} AND p.status = 'reported'", $params),
    'assigned' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$where_clause} AND p.status = 'assigned'", $params),
    'in_progress' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$where_clause} AND p.status = 'in_progress'", $params),
    'resolved' => $db->fetchCount("SELECT COUNT(*) FROM problems p WHERE {$where_clause} AND p.status = 'resolved'", $params)
];

$page_title = 'My Problems';
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
    <link href="../css/mobile.css" rel="stylesheet">
    
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
            background: #f8f9fa;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--operator-primary) 0%, #138496 100%);
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
        
        .filter-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .stats-mini {
            text-align: center;
            padding: 1rem;
            border-radius: 10px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 1rem;
        }
        
        .stats-mini h4 {
            margin: 0;
            font-weight: 700;
        }
        
        .stats-mini.total { border-top: 3px solid var(--operator-primary); }
        .stats-mini.reported { border-top: 3px solid var(--operator-secondary); }
        .stats-mini.assigned { border-top: 3px solid var(--info-color); }
        .stats-mini.progress { border-top: 3px solid var(--warning-color); }
        .stats-mini.resolved { border-top: 3px solid var(--success-color); }
        
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
        .status-closed { background: var(--operator-secondary); color: white; }
        
        .severity-minor { background: #e3f2fd; color: #1976d2; }
        .severity-moderate { background: #fff3e0; color: #f57c00; }
        .severity-major { background: #ffebee; color: #d32f2f; }
        .severity-critical { background: var(--danger-color); color: white; }
        
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
        
        .problem-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin: 1rem 0;
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .problem-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
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
        
        @media (max-width: 768px) {
            .problem-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body class="<?php echo $is_mobile ? 'mobile-device' : ''; ?>">
    
    <!-- Top Navigation -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-3">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <span class="navbar-brand mb-0 h1">
                    <i class="fas fa-exclamation-triangle"></i> My Problems
                </span>
            </div>
            <button class="btn btn-success btn-sm" onclick="showReportProblemModal()">
                <i class="fas fa-plus"></i> Report Problem
            </button>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid p-4 <?php echo $is_mobile ? 'mobile-padding' : ''; ?>">
        
        <!-- Statistics Row -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini total">
                    <h4 class="text-primary"><?php echo $problem_stats['total']; ?></h4>
                    <small class="text-muted">Total Problems</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini reported">
                    <h4 class="text-secondary"><?php echo $problem_stats['reported']; ?></h4>
                    <small class="text-muted">Reported</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini assigned">
                    <h4 class="text-info"><?php echo $problem_stats['assigned']; ?></h4>
                    <small class="text-muted">Assigned</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini progress">
                    <h4 class="text-warning"><?php echo $problem_stats['in_progress']; ?></h4>
                    <small class="text-muted">In Progress</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini resolved">
                    <h4 class="text-success"><?php echo $problem_stats['resolved']; ?></h4>
                    <small class="text-muted">Resolved</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini">
                    <h4 class="text-primary"><?php echo count($problems); ?></h4>
                    <small class="text-muted">Showing</small>
                </div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filter-section p-4">
            <form method="GET" class="row g-3">
                <div class="col-lg-3 col-md-6">
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
                <div class="col-lg-3 col-md-6">
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
                    <label for="category" class="form-label">Category</label>
                    <select class="form-select" id="category" name="category">
                        <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <option value="Mechanical" <?php echo $category_filter === 'Mechanical' ? 'selected' : ''; ?>>Mechanical</option>
                        <option value="Electrical" <?php echo $category_filter === 'Electrical' ? 'selected' : ''; ?>>Electrical</option>
                        <option value="Hydraulic System" <?php echo $category_filter === 'Hydraulic System' ? 'selected' : ''; ?>>Hydraulic System</option>
                        <option value="Engine" <?php echo $category_filter === 'Engine' ? 'selected' : ''; ?>>Engine</option>
                        <option value="Brake System" <?php echo $category_filter === 'Brake System' ? 'selected' : ''; ?>>Brake System</option>
                        <option value="Safety" <?php echo $category_filter === 'Safety' ? 'selected' : ''; ?>>Safety</option>
                        <option value="Environmental" <?php echo $category_filter === 'Environmental' ? 'selected' : ''; ?>>Environmental</option>
                        <option value="Other" <?php echo $category_filter === 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-operator">
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
                            <p class="text-muted">No problems match your current filters.<br>Try adjusting the filters above or report a new problem.</p>
                            <button class="btn btn-operator" onclick="showReportProblemModal()">
                                <i class="fas fa-plus"></i> Report New Problem
                            </button>
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
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="refreshProblems()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Problem Cards -->
                <?php foreach ($problems as $problem): ?>
                <div class="card problem-card priority-<?php echo $problem['priority']; ?>" data-problem-id="<?php echo $problem['id']; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
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
                                    <span class="severity-badge severity-<?php echo $problem['severity']; ?>">
                                        <?php echo ucfirst($problem['severity']); ?>
                                    </span>
                                </div>
                            </div>
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
                                    <?php endif; ?>
                                    <?php if ($problem['task_id']): ?>
                                    <li><a class="dropdown-item" href="../mechanic/tasks.php?task_id=<?php echo $problem['task_id']; ?>">
                                        <i class="fas fa-wrench"></i> View Task</a></li>
                                    <?php endif; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" onclick="addComment(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-comment"></i> Add Comment</a></li>
                                </ul>
                            </div>
                        </div>
                        
                        <?php if ($problem['description']): ?>
                        <p class="card-text text-muted mb-3">
                            <?php echo htmlspecialchars($problem['description']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div class="problem-meta">
                            <?php if ($problem['category']): ?>
                            <div class="problem-meta-item">
                                <i class="fas fa-tag"></i>
                                <span><?php echo htmlspecialchars($problem['category']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($problem['location']): ?>
                            <div class="problem-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($problem['location']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($problem['equipment']): ?>
                            <div class="problem-meta-item">
                                <i class="fas fa-tools"></i>
                                <span><?php echo htmlspecialchars($problem['equipment']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="problem-meta-item">
                                <i class="fas fa-clock"></i>
                                <span>Reported: <?php echo timeAgo($problem['created_at']); ?></span>
                            </div>
                            
                            <?php if ($problem['assigned_to_name']): ?>
                            <div class="problem-meta-item">
                                <i class="fas fa-user"></i>
                                <span>Assigned to: <?php echo htmlspecialchars($problem['assigned_to_name'] . ' ' . $problem['assigned_to_lastname']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                <small>
                                    <strong>Impact:</strong> <?php echo ucfirst($problem['impact']); ?> |
                                    <strong>Urgency:</strong> <?php echo ucfirst($problem['urgency']); ?>
                                    <?php if ($problem['estimated_resolution_time']): ?>
                                    | <strong>Est. Time:</strong> <?php echo $problem['estimated_resolution_time']; ?>h
                                    <?php endif; ?>
                                </small>
                            </div>
                            
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="viewProblem(<?php echo $problem['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if ($problem['status'] === 'reported'): ?>
                                <button class="btn btn-outline-warning" onclick="editProblem(<?php echo $problem['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php endif; ?>
                                <?php if ($problem['task_id']): ?>
                                <a href="../mechanic/tasks.php?task_id=<?php echo $problem['task_id']; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-wrench"></i> Task
                                </a>
                                <?php endif; ?>
                            </div>
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
                </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Problems pagination" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>">
                                Previous
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&category=<?php echo $category_filter; ?>">
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
    
    <!-- Mobile Bottom Navigation -->
    <?php if ($is_mobile): ?>
    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="problems.php" class="bottom-nav-item active">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Problems</span>
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
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Operator Problems page loaded');
        console.log('Total problems:', <?php echo $total_problems; ?>);
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    
    function viewProblem(problemId) {
        // For now, show a simple alert with problem ID
        // Later, this can open a detailed modal or navigate to a detail page
        showToast(`Viewing problem #${problemId} - Detail view coming soon!`, 'info');
    }
    
    function editProblem(problemId) {
        showToast('Problem editing feature will be implemented soon!', 'info');
    }
    
    function addComment(problemId) {
        showToast('Comment feature will be implemented soon!', 'info');
    }
    
    function showReportProblemModal() {
        // Redirect to dashboard where the modal is implemented
        window.location.href = 'dashboard.php#report-problem';
    }
    
    function refreshProblems() {
        location.reload();
    }
    
    function showToast(message, type = 'info') {
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
                    <i class="fas fa-${getToastIcon(type)}"></i> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
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
    </script>
</body>
</html>