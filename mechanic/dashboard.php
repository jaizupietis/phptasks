<?php
/**
 * Mechanic Dashboard
 * Mobile-optimized interface for mechanics
 */

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Check if user is logged in and is a mechanic
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mechanic') {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$is_mobile = isMobile();

// Get user information from database
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

if (!$user) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Get dashboard statistics
$stats = [
    'pending' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'pending'",
        [$user_id]
    ),
    'in_progress' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'in_progress'",
        [$user_id]
    ),
    'completed' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'completed' AND DATE(completed_date) = CURDATE()",
        [$user_id]
    ),
    'overdue' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND due_date < NOW() AND status NOT IN ('completed', 'cancelled')",
        [$user_id]
    )
];

// Get recent tasks
$recent_tasks = $db->fetchAll(
    "SELECT t.*, u.first_name, u.last_name 
     FROM tasks t 
     LEFT JOIN users u ON t.assigned_by = u.id 
     WHERE t.assigned_to = ? 
     ORDER BY 
        CASE 
            WHEN t.status = 'in_progress' THEN 1 
            WHEN t.priority = 'urgent' THEN 2 
            WHEN t.priority = 'high' THEN 3 
            ELSE 4 
        END,
        t.due_date ASC,
        t.created_at DESC 
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

$page_title = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- CSS Files -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <?php if ($is_mobile): ?>
    <link href="../css/mobile.css" rel="stylesheet">
    <?php endif; ?>
    
    <!-- PWA Configuration -->
    <link rel="manifest" href="../push/manifest.json">
    <meta name="theme-color" content="#007bff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    
    <style>
        :root {
            --primary-color: #007bff;
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
            background: linear-gradient(135deg, var(--primary-color) 0%, #0056b3 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .welcome-card {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
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
        
        .stats-icon.primary { background: linear-gradient(135deg, var(--primary-color), #0056b3); color: white; }
        .stats-icon.warning { background: linear-gradient(135deg, var(--warning-color), #e0a800); color: #212529; }
        .stats-icon.success { background: linear-gradient(135deg, var(--success-color), #1e7e34); color: white; }
        .stats-icon.danger { background: linear-gradient(135deg, var(--danger-color), #c82333); color: white; }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .stats-label {
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
        }
        
        .task-card {
            border-left: 4px solid var(--primary-color);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .task-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .task-card.priority-low { border-left-color: var(--success-color); }
        .task-card.priority-medium { border-left-color: var(--warning-color); }
        .task-card.priority-high { border-left-color: #fd7e14; }
        .task-card.priority-urgent { border-left-color: var(--danger-color); }
        
        .priority-badge {
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
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending { background: #6c757d; color: white; }
        .status-in_progress { background: var(--primary-color); color: white; }
        .status-completed { background: var(--success-color); color: white; }
        .status-cancelled { background: var(--danger-color); color: white; }
        .status-on_hold { background: var(--warning-color); color: #212529; }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: white;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 1000;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        
        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #6c757d;
            padding: 0.5rem;
            transition: all 0.3s ease;
            min-width: 60px;
            position: relative;
        }
        
        .bottom-nav-item.active {
            color: var(--primary-color);
            transform: translateY(-2px);
        }
        
        .bottom-nav-item:hover {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .bottom-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }
        
        .bottom-nav-item span {
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .mobile-padding {
            padding-bottom: 90px;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
        
        .btn-loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .task-actions .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .task-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
        }
        
        .progress-bar {
            border-radius: 4px;
        }
        
        @media (max-width: 768px) {
            .container-fluid {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .stats-card {
                padding: 1rem;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .task-actions {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .task-actions .btn {
                margin: 0;
                flex: 1;
            }
        }
    </style>
</head>
<body class="<?php echo $is_mobile ? 'mobile-device' : ''; ?>">
    
    <!-- Top Navigation -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-tools"></i> <?php echo APP_NAME; ?>
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
                case 'status_updated':
                    echo 'Task status updated successfully!';
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
                                    <i class="fas fa-tools fa-2x"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h4 class="mb-1">
                                    Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!
                                </h4>
                                <p class="mb-0 opacity-90">
                                    Your mobile-optimized task management dashboard
                                </p>
                            </div>
                            <div class="col-auto">
                                <div class="current-time">
                                    <small class="opacity-75" id="currentTime"></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <a href="tasks.php?status=pending" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon primary">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['pending']; ?></div>
                        <div class="stats-label">Pending</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-3">
                <a href="tasks.php?status=in_progress" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon warning">
                            <i class="fas fa-play"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['in_progress']; ?></div>
                        <div class="stats-label">In Progress</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-3">
                <a href="tasks.php?status=completed" class="text-decoration-none">
                    <div class="card stats-card">
                        <div class="stats-icon success">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="stats-number"><?php echo $stats['completed']; ?></div>
                        <div class="stats-label">Completed Today</div>
                    </div>
                </a>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stats-card" onclick="showOverdueTasks()">
                    <div class="stats-icon danger">
                        <i class="fas fa-exclamation"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['overdue']; ?></div>
                    <div class="stats-label">Overdue</div>
                </div>
            </div>
        </div>
        
        <!-- Recent Tasks -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-tasks"></i> Recent Tasks
                        </h5>
                        <a href="tasks.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_tasks)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <h5>No Tasks Yet</h5>
                            <p>You don't have any tasks assigned yet.<br>Check back later or contact your manager.</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_tasks as $index => $task): ?>
                            <div class="list-group-item task-card priority-<?php echo $task['priority']; ?>" id="task-<?php echo $task['id']; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="mb-1 fw-bold">
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </h6>
                                    <span class="status-badge status-<?php echo $task['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                </div>
                                
                                <?php if ($task['description']): ?>
                                <p class="mb-2 text-muted">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 120)); ?>
                                    <?php if (strlen($task['description']) > 120): ?>...<?php endif; ?>
                                </p>
                                <?php endif; ?>
                                
                                <div class="task-meta">
                                    <span class="task-meta-item">
                                        <i class="fas fa-flag"></i>
                                        <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                            <?php echo ucfirst($task['priority']); ?>
                                        </span>
                                    </span>
                                    
                                    <?php if ($task['due_date']): ?>
                                    <span class="task-meta-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span><?php echo date('M j, g:i A', strtotime($task['due_date'])); ?></span>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($task['location']): ?>
                                    <span class="task-meta-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($task['location']); ?></span>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($task['equipment']): ?>
                                    <span class="task-meta-item">
                                        <i class="fas fa-tools"></i>
                                        <span><?php echo htmlspecialchars($task['equipment']); ?></span>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Progress Bar -->
                                <?php if ($task['progress_percentage'] > 0): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted">Progress</small>
                                        <small class="text-muted"><?php echo $task['progress_percentage']; ?>%</small>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $task['progress_percentage']; ?>%"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="task-actions">
                                    <?php if ($task['status'] === 'pending'): ?>
                                    <button class="btn btn-success btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')">
                                        <i class="fas fa-play"></i> Start Work
                                    </button>
                                    <?php elseif ($task['status'] === 'in_progress'): ?>
                                    <button class="btn btn-primary btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'completed')">
                                        <i class="fas fa-check"></i> Complete
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'on_hold')">
                                        <i class="fas fa-pause"></i> Pause
                                    </button>
                                    <?php endif; ?>
                                    <a href="tasks.php?task_id=<?php echo $task['id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
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
        <a href="tasks.php" class="bottom-nav-item">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
            <?php if ($stats['pending'] > 0): ?>
            <span class="notification-badge"><?php echo $stats['pending']; ?></span>
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
    
    <!-- JavaScript Files -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="../js/main.js"></script>
    
    <script>
    // COMPLETE FIXED JavaScript for Mechanic Dashboard
// Replace the <script> section at the bottom of mechanic/dashboard.php

// Dashboard specific JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Update current time
    function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleString('en-GB', {
        timeZone: 'Europe/Riga',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit'
    });
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}
    updateTime();
    setInterval(updateTime, 60000); // Update every minute
    
    // Auto-dismiss alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Add debug controls
    addDebugControls();
    
    console.log('Dashboard loaded successfully');
    console.log('User stats:', <?php echo json_encode($stats); ?>);
    console.log('Recent tasks:', <?php echo json_encode($recent_tasks); ?>);
});

function addDebugControls() {
    const navbar = document.querySelector('.navbar .container-fluid');
    if (navbar) {
        const debugGroup = document.createElement('div');
        debugGroup.className = 'd-flex gap-2 me-3';
        debugGroup.innerHTML = `
            <button class="btn btn-outline-light btn-sm" onclick="testAPI()">
                <i class="fas fa-bug"></i> Test API
            </button>
            <button class="btn btn-outline-light btn-sm" onclick="testDatabase()">
                <i class="fas fa-database"></i> Debug
            </button>
        `;
        navbar.insertBefore(debugGroup, navbar.lastElementChild);
    }
}

// Update task status with enhanced error handling
function updateTaskStatus(taskId, newStatus) {
    if (confirm('Are you sure you want to update this task status?')) {
        const taskElement = document.getElementById('task-' + taskId);
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        
        // Show loading state
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        btn.disabled = true;
        btn.classList.add('btn-loading');
        
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
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(statusData),
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
            console.log('Parsed response:', data);
            
            if (data.success) {
                // Show success message
                showToast('Task status updated successfully!', 'success');
                
                // Add visual feedback
                if (taskElement) {
                    taskElement.style.transform = 'scale(1.02)';
                    taskElement.style.background = '#d4edda';
                    
                    setTimeout(() => {
                        taskElement.style.transform = '';
                        taskElement.style.background = '';
                    }, 1000);
                }
                
                // Reload page after short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showToast('Error: ' + (data.message || 'Unknown error'), 'danger');
                resetButton();
            }
        })
        .catch(error => {
            console.error('Request Error:', error);
            showToast('Network error: ' + error.message, 'danger');
            resetButton();
        });
        
        function resetButton() {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            btn.classList.remove('btn-loading');
        }
    }
}

// Show overdue tasks
function showOverdueTasks() {
    const overdueCount = <?php echo $stats['overdue']; ?>;
    if (overdueCount > 0) {
        window.location.href = 'tasks.php?overdue=1';
    } else {
        showToast('No overdue tasks! Great job!', 'success');
    }
}

// Enhanced toast function with better styling
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
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
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

// Handle mobile gestures
if (<?php echo $is_mobile ? 'true' : 'false'; ?>) {
    // Add touch feedback for cards
    document.querySelectorAll('.stats-card, .task-card').forEach(card => {
        card.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        });
        
        card.addEventListener('touchend', function() {
            setTimeout(() => {
                this.style.transform = '';
            }, 150);
        });
    });
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
                showToast('✓ API is working correctly!', 'success');
            } else {
                showToast('✗ API test failed: ' + data.message, 'danger');
            }
        } catch (e) {
            showToast('✗ API returned invalid JSON', 'danger');
            console.error('JSON Parse Error:', e);
        }
    })
    .catch(error => {
        console.error('API Test Error:', error);
        showToast('✗ API connection failed: ' + error.message, 'danger');
    });
}

function testDatabase() {
    window.open('../debug_db.php', '_blank');
}

// Load tasks function for testing
function loadTasks() {
    fetch('../api/tasks.php?action=get_tasks', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Tasks loaded:', data);
        if (data.success) {
            showToast(`✓ Loaded ${data.tasks.length} tasks`, 'success');
        } else {
            showToast('✗ Failed to load tasks: ' + data.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Load tasks error:', error);
        showToast('✗ Failed to load tasks: ' + error.message, 'danger');
    });
}
    </script>
</body>
</html>