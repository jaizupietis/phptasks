<?php
/**
 * Mechanic Tasks Page
 * View and manage assigned tasks
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

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$priority_filter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : 'all';

// Build WHERE clause for filters
$where_conditions = ["assigned_to = ?"];
$params = [$user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "priority = ?";
    $params[] = $priority_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Get tasks with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = $is_mobile ? 5 : 10;
$offset = ($page - 1) * $per_page;

$tasks = $db->fetchAll(
    "SELECT t.*, u.first_name, u.last_name 
     FROM tasks t 
     LEFT JOIN users u ON t.assigned_by = u.id 
     WHERE {$where_clause}
     ORDER BY 
        CASE 
            WHEN t.status = 'in_progress' THEN 1 
            WHEN t.priority = 'urgent' THEN 2 
            WHEN t.priority = 'high' THEN 3 
            ELSE 4 
        END,
        t.due_date ASC 
     LIMIT {$per_page} OFFSET {$offset}",
    $params
);

// Get total count for pagination
$total_tasks = $db->fetchCount(
    "SELECT COUNT(*) FROM tasks t WHERE {$where_clause}",
    $params
);

$total_pages = ceil($total_tasks / $per_page);

$page_title = 'My Tasks';
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
</head>
<body class="<?php echo $is_mobile ? 'mobile-device' : ''; ?>">
    
    <!-- Top Navigation -->
    <nav class="navbar navbar-dark" style="background: var(--primary-gradient);">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-tasks"></i> My Tasks
            </span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-home"></i>
                <span class="d-none d-md-inline ms-1">Dashboard</span>
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid mt-4 <?php echo $is_mobile ? 'mobile-padding' : ''; ?>">
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="on_hold" <?php echo $status_filter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="priority" class="form-label">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                            <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tasks List -->
        <div class="row">
            <div class="col-12">
                <?php if (empty($tasks)): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <h5>No Tasks Found</h5>
                            <p>No tasks match your current filters.<br>Try adjusting the filters above.</p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted mb-0">
                        Showing <?php echo count($tasks); ?> of <?php echo $total_tasks; ?> tasks
                    </h6>
                    <small class="text-muted">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </small>
                </div>
                
                <!-- Task Cards -->
                <?php foreach ($tasks as $task): ?>
                <div class="card task-card priority-<?php echo $task['priority']; ?> mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-1">
                                <?php echo htmlspecialchars($task['title']); ?>
                            </h5>
                            <span class="status-badge status-<?php echo $task['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                            </span>
                        </div>
                        
                        <?php if ($task['description']): ?>
                        <p class="card-text text-muted mb-3">
                            <?php echo htmlspecialchars($task['description']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-auto">
                                <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                    <i class="fas fa-flag"></i> <?php echo ucfirst($task['priority']); ?>
                                </span>
                            </div>
                            
                            <?php if ($task['location']): ?>
                            <div class="col-auto">
                                <small class="text-muted">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($task['location']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($task['equipment']): ?>
                            <div class="col-auto">
                                <small class="text-muted">
                                    <i class="fas fa-tools"></i>
                                    <?php echo htmlspecialchars($task['equipment']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted">
                                <?php if ($task['due_date']): ?>
                                <small>
                                    <i class="fas fa-calendar-alt"></i>
                                    Due: <?php echo date('M j, Y g:i A', strtotime($task['due_date'])); ?>
                                </small>
                                <?php else: ?>
                                <small>No due date set</small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="btn-group btn-group-sm">
                                <?php if ($task['status'] === 'pending'): ?>
                                <button class="btn btn-success" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')">
                                    <i class="fas fa-play"></i> Start
                                </button>
                                <?php elseif ($task['status'] === 'in_progress'): ?>
                                <button class="btn btn-primary" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'completed')">
                                    <i class="fas fa-check"></i> Complete
                                </button>
                                <button class="btn btn-warning" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'on_hold')">
                                    <i class="fas fa-pause"></i> Pause
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-outline-primary" onclick="viewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <?php if ($task['progress_percentage'] > 0): ?>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-muted">Progress</small>
                                <small class="text-muted"><?php echo $task['progress_percentage']; ?>%</small>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar" role="progressbar" 
                                     style="width: <?php echo $task['progress_percentage']; ?>%"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Tasks pagination">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>">
                                Previous
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>">
                                <?<?php echo $i; ?>
                           </a>
                       </li>
                       <?php endfor; ?>
                       
                       <?php if ($page < $total_pages): ?>
                       <li class="page-item">
                           <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>">
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
       <a href="tasks.php" class="bottom-nav-item active">
           <i class="fas fa-tasks"></i>
           <span>Tasks</span>
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
   <script src="../js/main.js"></script>
   
   <script>
   // COMPLETE FIXED JavaScript for Mechanic Tasks Page
// Replace the <script> section at the bottom of mechanic/tasks.php

// Update task status with enhanced error handling
function updateTaskStatus(taskId, newStatus) {
    if (confirm('Are you sure you want to update this task status?')) {
        showLoading(document.body);
        
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
            hideLoading(document.body);
            
            if (data.success) {
                showToast('Task status updated successfully!', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast('Error updating task: ' + (data.message || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            console.error('Request Error:', error);
            hideLoading(document.body);
            showToast('Network error: ' + error.message, 'danger');
        });
    }
}

// View task details (placeholder)
function viewTask(taskId) {
    console.log('Viewing task:', taskId);
    showToast('Task details feature coming soon!', 'info');
}

// Show loading state
function showLoading(element) {
    if (element) {
        element.classList.add('loading');
        
        // Add loading overlay
        const overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        `;
        overlay.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        `;
        document.body.appendChild(overlay);
    }
}

// Hide loading state
function hideLoading(element) {
    if (element) {
        element.classList.remove('loading');
        
        // Remove loading overlay
        const overlay = document.querySelector('.loading-overlay');
        if (overlay) {
            overlay.remove();
        }
    }
}

// Enhanced toast function
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
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
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
        delay: 4000
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

// Add debug controls
document.addEventListener('DOMContentLoaded', function() {
    addDebugControls();
    
    // Test API on page load
    console.log('Mechanic Tasks page loaded');
    
    // Auto-refresh every 5 minutes
    setInterval(() => {
        console.log('Auto-refreshing tasks...');
        location.reload();
    }, 300000);
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
            <button class="btn btn-outline-light btn-sm" onclick="loadTasks()">
                <i class="fas fa-sync"></i> Refresh
            </button>
            <button class="btn btn-outline-light btn-sm" onclick="testDatabase()">
                <i class="fas fa-database"></i> Debug
            </button>
        `;
        navbar.insertBefore(debugGroup, navbar.lastElementChild);
    }
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

function loadTasks() {
    console.log('Loading tasks...');
    
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
