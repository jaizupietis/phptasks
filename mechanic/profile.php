<?php
/**
 * Mechanic Profile Page
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

// Get user information
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

// Get user statistics
$stats = [
    'total_tasks' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ?",
        [$user_id]
    ),
    'completed_tasks' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'completed'",
        [$user_id]
    ),
    'pending_tasks' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'pending'",
        [$user_id]
    ),
    'in_progress_tasks' => $db->fetchCount(
        "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'in_progress'",
        [$user_id]
    )
];

$completion_rate = $stats['total_tasks'] > 0 ? 
    round(($stats['completed_tasks'] / $stats['total_tasks']) * 100, 1) : 0;

$page_title = 'My Profile';
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
                <i class="fas fa-user"></i> My Profile
            </span>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-home"></i>
                <span class="d-none d-md-inline ms-1">Dashboard</span>
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid mt-4 <?php echo $is_mobile ? 'mobile-padding' : ''; ?>">
        
        <!-- Profile Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="profile-avatar mb-3">
                            <div class="avatar-circle">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                            </div>
                        </div>
                        <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                        <p class="text-muted mb-1"><?php echo ucfirst($user['role']); ?></p>
                        <p class="text-muted"><?php echo htmlspecialchars($user['department']); ?></p>
                        
                        <?php if ($user['last_login']): ?>
                        <small class="text-muted">
                            Last login: <?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?>
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card stats-card">
                    <div class="stats-icon primary">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['total_tasks']; ?></div>
                    <div class="stats-label">Total Tasks</div>
                </div>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stats-card">
                    <div class="stats-icon success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['completed_tasks']; ?></div>
                    <div class="stats-label">Completed</div>
                </div>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stats-card">
                    <div class="stats-icon warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['pending_tasks']; ?></div>
                    <div class="stats-label">Pending</div>
                </div>
            </div>
            
            <div class="col-6 col-md-3">
                <div class="card stats-card">
                    <div class="stats-icon info" style="background: var(--info-color); color: white;">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stats-number"><?php echo $completion_rate; ?>%</div>
                    <div class="stats-label">Completion Rate</div>
                </div>
            </div>
        </div>
        
        <!-- Profile Information -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle"></i> Profile Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Username</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($user['username']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Email</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Phone</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Department</label>
                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($user['department']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Role</label>
                                    <p class="form-control-plaintext"><?php echo ucfirst($user['role']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Member Since</label>
                                    <p class="form-control-plaintext"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button class="btn btn-outline-primary" onclick="showToast('Profile editing feature coming soon!', 'info')">
                                <i class="fas fa-edit"></i> Edit Profile
                            </button>
                        </div>
                    </div>
                </div>
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
        <a href="tasks.php" class="bottom-nav-item">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
        </a>
        <a href="profile.php" class="bottom-nav-item active">
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
    
    <style>
    .profile-avatar {
        margin-bottom: 1rem;
    }
    
    .avatar-circle {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: var(--primary-gradient);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: bold;
        margin: 0 auto;
    }
    
    .form-control-plaintext {
        border-bottom: 1px solid #e9ecef;
        padding-bottom: 0.5rem;
        margin-bottom: 0;
    }
    </style>
</body>
</html>
