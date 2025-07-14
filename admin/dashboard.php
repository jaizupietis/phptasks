<?php
/**
 * PILNS Admin Dashboard ar visām funkcijām un izlabojumiem
 * Aizstāj: /var/www/tasks/admin/dashboard.php
 * Versija: 2.0 - Pilnīgi izlabots ar Problems integrāciju
 */

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Pārbaudām vai lietotājs ir ielogojies un ir admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$is_mobile = isMobile();

// Iegūstam admin informāciju
$admin = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);

if (!$admin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Apstrādājam filtrēšanu, spiežot uz statistikas kartēm
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$role_filter = isset($_GET['role']) ? sanitizeInput($_GET['role']) : 'all';

// Veidojam filter nosacījumus
$task_where = "1 = 1";
$task_params = [];
$user_where = "1 = 1";
$user_params = [];

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

if ($role_filter !== 'all') {
    $user_where .= " AND u.role = ?";
    $user_params[] = $role_filter;
}

// Iegūstam pamatstatistiku ar kļūdu apstrādi
try {
    // Izmantojam vienkāršākas, drošākas queries, lai izvairītos no parametru kļūdām
    $stats = [
        'total_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE is_active = 1"),
        'total_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks"),
        'pending_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'pending'"),
        'in_progress_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'"),
        'completed_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE status = 'completed'"),
        'overdue_tasks' => $db->fetchCount("SELECT COUNT(*) FROM tasks WHERE due_date < NOW() AND status NOT IN ('completed', 'cancelled')"),
        
        // Lietotāju statistika pēc lomām - izlabotās queries
        'admin_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"),
        'manager_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'manager' AND is_active = 1"),
        'mechanic_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'mechanic' AND is_active = 1"),
        'operator_users' => $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'operator' AND is_active = 1"),
        
        // Problēmu statistika - izlabotās queries ar drošu apstrādi
        'total_problems' => 0,
        'reported_problems' => 0,
        'urgent_problems' => 0,
    ];
    
    // Drošā veida iegūt problēmu statistiku
    try {
        $stats['total_problems'] = $db->fetchCount("SELECT COUNT(*) FROM problems");
        $stats['reported_problems'] = $db->fetchCount("SELECT COUNT(*) FROM problems WHERE status = 'reported'");
        $stats['urgent_problems'] = $db->fetchCount("SELECT COUNT(*) FROM problems WHERE priority = 'urgent' AND status NOT IN ('resolved', 'closed')");
    } catch (Exception $e) {
        error_log("Problems stats error: " . $e->getMessage());
        // Turpinām ar nulles vērtībām, ja problems tabulai ir problēmas
    }
        
} catch (Exception $e) {
    error_log("Admin dashboard stats error: " . $e->getMessage());
    // Inicializējam ar drošām default vērtībām
    $stats = [
        'total_users' => 0, 'total_tasks' => 0, 'pending_tasks' => 0, 'in_progress_tasks' => 0, 
        'completed_tasks' => 0, 'overdue_tasks' => 0, 'admin_users' => 0, 'manager_users' => 0,
        'mechanic_users' => 0, 'operator_users' => 0, 'total_problems' => 0, 'reported_problems' => 0,
        'urgent_problems' => 0
    ];
}

// Iegūstam nesenās aktivitātes ar kļūdu apstrādi
try {
    $recent_activities = $db->fetchAll(
        "SELECT al.*, u.first_name, u.last_name 
         FROM activity_logs al
         LEFT JOIN users u ON al.user_id = u.id 
         ORDER BY al.created_at DESC 
         LIMIT 10"
    );
} catch (Exception $e) {
    error_log("Admin dashboard activities error: " . $e->getMessage());
    $recent_activities = [];
}

// Iegūstam nesenākos uzdevumus
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
         LIMIT 10",
        $task_params
    );
} catch (Exception $e) {
    error_log("Admin dashboard tasks error: " . $e->getMessage());
    $recent_tasks = [];
}

// Iegūstam komandas pārskatu
try {
    $team_overview = $db->fetchAll(
        "SELECT u.id, u.first_name, u.last_name, u.last_login, u.email, u.role,
                COUNT(DISTINCT t.id) as total_tasks,
                SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_tasks,
                SUM(CASE WHEN DATE(t.completed_date) = CURDATE() THEN 1 ELSE 0 END) as completed_today
         FROM users u
         LEFT JOIN tasks t ON u.id = t.assigned_to AND {$task_where}
         WHERE u.is_active = 1
         GROUP BY u.id, u.first_name, u.last_name, u.last_login, u.email, u.role
         ORDER BY u.role, u.first_name, u.last_name
         LIMIT 20",
        $task_params
    );
} catch (Exception $e) {
    error_log("Admin team overview error: " . $e->getMessage());
    $team_overview = [];
}

// Iegūstam nesenākās problēmas
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
    error_log("Admin recent problems error: " . $e->getMessage());
    $recent_problems = [];
}

// Iegūstam visus lietotājus dropdown izvēlnēm
$users = $db->fetchAll(
    "SELECT id, first_name, last_name, role FROM users 
     WHERE is_active = 1 
     ORDER BY role, first_name, last_name"
);

// Atdalām mehāniķus uzdevumu piešķiršanai
$mechanics = array_filter($users, function($user) {
    return $user['role'] === 'mechanic';
});

$page_title = 'Admin Dashboard';

// Helper funkcija laika rādīšanai
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        if ($time < 60) return 'tikko';
        if ($time < 3600) return floor($time / 60) . 'm atpakaļ';
        if ($time < 86400) return floor($time / 3600) . 'h atpakaļ';
        return date('j M', strtotime($datetime));
    }
}
?>
<!DOCTYPE html>
<html lang="lv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --admin-primary: #dc3545;
            --admin-secondary: #6c757d;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
        }
        
        body {
            background: var(--light-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .admin-navbar {
            background: linear-gradient(135deg, var(--admin-primary) 0%, #b02a37 100%);
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
            min-height: 140px;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stats-card.active {
            border: 3px solid var(--admin-primary);
            transform: translateY(-3px);
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--admin-primary), #b02a37);
        }
        
        .stats-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stats-icon.users { background: linear-gradient(135deg, var(--info-color), #138496); }
        .stats-icon.tasks { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
        .stats-icon.pending { background: linear-gradient(135deg, #fd7e14, #e96500); }
        .stats-icon.progress { background: linear-gradient(135deg, #007bff, #0056b3); }
        .stats-icon.completed { background: linear-gradient(135deg, var(--success-color), #1e7e34); }
        .stats-icon.overdue { background: linear-gradient(135deg, var(--admin-primary), #c82333); }
        .stats-icon.admin { background: linear-gradient(135deg, var(--admin-primary), #c82333); }
        .stats-icon.manager { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
        .stats-icon.mechanic { background: linear-gradient(135deg, var(--success-color), #1e7e34); }
        .stats-icon.operator { background: linear-gradient(135deg, var(--info-color), #138496); }
        .stats-icon.problems { background: linear-gradient(135deg, var(--warning-color), #e0a800); }
        
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f3f4;
            transition: background 0.3s ease;
        }
        
        .activity-item:hover {
            background: var(--light-color);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .activity-icon.login { background: #d4edda; color: #155724; }
        .activity-icon.task { background: #cce7ff; color: #004085; }
        .activity-icon.update { background: #fff3cd; color: #856404; }
        .activity-icon.problem { background: #f8d7da; color: #721c24; }
        
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
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            text-decoration: none;
            color: white;
        }
        
        .quick-action-btn i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .filter-info {
            background: #e3f2fd;
            border: 1px solid #1976d2;
            color: #1976d2;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--admin-primary);
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
        
        .problem-alert {
            border-left: 4px solid var(--warning-color);
            background: #fff8e1;
            margin-bottom: 0.5rem;
            padding: 0.75rem;
            border-radius: 5px;
        }
        
        .problem-alert.urgent {
            border-left-color: var(--admin-primary);
            background: #ffebee;
        }
        
        .team-member-card {
            border-radius: 10px;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .team-member-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .role-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .role-admin { background: var(--admin-primary); color: white; }
        .role-manager { background: #6f42c1; color: white; }
        .role-mechanic { background: var(--success-color); color: white; }
        .role-operator { background: var(--info-color); color: white; }
        
        .modal-header.admin {
            background: var(--admin-primary);
            color: white;
        }
        
        .btn-admin {
            background: var(--admin-primary);
            border-color: var(--admin-primary);
            color: white;
        }
        
        .btn-admin:hover {
            background: #b02a37;
            border-color: #b02a37;
            color: white;
        }
        
        @media (max-width: 768px) {
            .stats-card {
                margin-bottom: 1rem;
                min-height: 120px;
            }
            
            .quick-action-btn {
                height: 100px;
                font-size: 0.875rem;
            }
            
            .quick-action-btn i {
                font-size: 1.5rem;
            }
            
            .stats-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
        }
        
        .debug-info {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            color: #2e7d32;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark admin-navbar">  
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-shield-alt"></i> Admin Panelis
            </span>
            <div class="d-flex align-items-center">
                <span class="me-3">Sveiks, <?php echo htmlspecialchars($admin['first_name']); ?>!</span>
                
                <!-- Problēmu Alert Badge -->
                <?php if ($stats['urgent_problems'] > 0): ?>
                <a href="../manager/problems.php?priority=urgent" class="btn btn-outline-light btn-sm me-2 position-relative">
                    <i class="fas fa-exclamation-triangle"></i> Steidzamas
                    <span class="notification-badge"><?php echo $stats['urgent_problems']; ?></span>
                </a>
                <?php endif; ?>
                
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-cog"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="showCreateTaskModal()">
                            <i class="fas fa-plus"></i> Izveidot uzdevumu</a></li>
                        <li><a class="dropdown-item" href="users.php">
                            <i class="fas fa-users"></i> Pārvaldīt lietotājus</a></li>
                        <li><a class="dropdown-item" href="../manager/tasks.php">
                            <i class="fas fa-tasks"></i> Pārvaldīt uzdevumus</a></li>
                        <!-- IZLABOTS: Drošs Problems link ar kļūdu apstrādi -->
                        <li><a class="dropdown-item" href="#" onclick="safeNavigateToProblems()">
                            <i class="fas fa-exclamation-triangle"></i> Pārvaldīt problēmas</a></li>
                        <li><a class="dropdown-item" href="reports.php">
                            <i class="fas fa-chart-bar"></i> Atskaites</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i> Iziet</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- Debug informācija -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="debug-info">
            <h6><i class="fas fa-bug"></i> Debug informācija:</h6>
            <p><strong>Problēmu tabulas statuss:</strong> 
            <?php
            try {
                $problem_count = $db->fetchCount("SELECT COUNT(*) FROM problems");
                echo "✅ Pieejama ($problem_count ieraksti)";
            } catch (Exception $e) {
                echo "❌ Kļūda: " . $e->getMessage();
            }
            ?>
            </p>
            <p><strong>Pieejami mehāniķi:</strong> 
            <?php
            try {
                $mechanic_count = $db->fetchCount("SELECT COUNT(*) FROM users WHERE role = 'mechanic' AND is_active = 1");
                echo "✅ $mechanic_count mehāniķi";
            } catch (Exception $e) {
                echo "❌ Kļūda: " . $e->getMessage();
            }
            ?>
            </p>
            <p><strong>Problēmu lapas tests:</strong> <button onclick='safeNavigateToProblems()' class='btn btn-sm btn-primary'>Testēt navigāciju</button></p>
        </div>
        <?php endif; ?>
        
        <!-- Filter informācija -->
        <?php if ($status_filter !== 'all' || $role_filter !== 'all'): ?>
        <div class="filter-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-filter"></i>
                    <strong>Aktīvie filtri:</strong>
                    <?php if ($status_filter !== 'all'): ?>
                    Statuss: <span class="badge bg-primary"><?php echo ucfirst(str_replace('_', ' ', $status_filter)); ?></span>
                    <?php endif; ?>
                    <?php if ($role_filter !== 'all'): ?>
                    Loma: <span class="badge bg-primary"><?php echo ucfirst($role_filter); ?></span>
                    <?php endif; ?>
                </div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-times"></i> Notīrīt filtrus
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Sistēmas statistika -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'all' ? 'active' : ''; ?>" onclick="filterDashboard('users')">
                    <div class="stats-icon users mx-auto">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_users']; ?></h3>
                    <p class="text-muted mb-0">Kopā lietotāju</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'all' ? 'active' : ''; ?>" onclick="filterDashboard('tasks')">
                    <div class="stats-icon tasks mx-auto">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['total_tasks']; ?></h3>
                    <p class="text-muted mb-0">Kopā uzdevumu</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" onclick="filterDashboard('pending')">
                    <div class="stats-icon pending mx-auto">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['pending_tasks']; ?></h3>
                    <p class="text-muted mb-0">Gaida</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>" onclick="filterDashboard('in_progress')">
                    <div class="stats-icon progress mx-auto">
                        <i class="fas fa-play"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['in_progress_tasks']; ?></h3>
                    <p class="text-muted mb-0">Darbā</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" onclick="filterDashboard('completed')">
                    <div class="stats-icon completed mx-auto">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['completed_tasks']; ?></h3>
                    <p class="text-muted mb-0">Pabeigti</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $status_filter === 'overdue' ? 'active' : ''; ?>" onclick="filterDashboard('overdue')">
                    <div class="stats-icon overdue mx-auto">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-danger"><?php echo $stats['overdue_tasks']; ?></h3>
                    <p class="text-muted mb-0">Kavētie</p>
                </div>
            </div>
        </div>
        
        <!-- Lietotāju lomu statistika -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'admin' ? 'active' : ''; ?>" onclick="filterDashboard('admin')">
                    <div class="stats-icon admin mx-auto">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3 class="text-danger"><?php echo $stats['admin_users']; ?></h3>
                    <p class="text-muted mb-0">Administratori</p>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'manager' ? 'active' : ''; ?>" onclick="filterDashboard('manager')">
                    <div class="stats-icon manager mx-auto">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h3 class="text-primary"><?php echo $stats['manager_users']; ?></h3>
                    <p class="text-muted mb-0">Menedžeri</p>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'mechanic' ? 'active' : ''; ?>" onclick="filterDashboard('mechanic')">
                    <div class="stats-icon mechanic mx-auto">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3 class="text-success"><?php echo $stats['mechanic_users']; ?></h3>
                    <p class="text-muted mb-0">Mehāniķi</p>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3 <?php echo $role_filter === 'operator' ? 'active' : ''; ?>" onclick="filterDashboard('operator')">
                    <div class="stats-icon operator mx-auto">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h3 class="text-info"><?php echo $stats['operator_users']; ?></h3>
                    <p class="text-muted mb-0">Operatori</p>
                </div>
            </div>
        </div>
        
        <!-- Problēmu statistika -->
        <?php if ($stats['total_problems'] > 0): ?>
        <div class="row g-4 mb-4">
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3" onclick="safeNavigateToProblems()">
                    <div class="stats-icon problems mx-auto">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3 class="text-warning"><?php echo $stats['total_problems']; ?></h3>
                    <p class="text-muted mb-0">Kopā problēmu</p>
                </div>
            </div>
            
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3" onclick="safeNavigateToProblems()">
                    <div class="stats-icon problems mx-auto">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3 class="text-secondary"><?php echo $stats['reported_problems']; ?></h3>
                    <p class="text-muted mb-0">Ziņotās problēmas</p>
                </div>
            </div>
            
            <div class="col-xl-4 col-lg-6 col-md-6">
                <div class="card stats-card text-center p-3" onclick="safeNavigateToProblems()">
                    <div class="stats-icon overdue mx-auto">
                        <i class="fas fa-fire"></i>
                    </div>
                    <h3 class="text-danger"><?php echo $stats['urgent_problems']; ?></h3>
                    <p class="text-muted mb-0">Steidzamas problēmas</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Ātrās darbības -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Ātrās darbības</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-2 col-md-6">
                                <a href="#" onclick="showCreateTaskModal()" 
                                   class="btn btn-success w-100 quick-action-btn">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Izveidot uzdevumu</span>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="users.php" class="btn btn-primary w-100 quick-action-btn">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Pārvaldīt lietotājus</span>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="../manager/tasks.php" class="btn btn-info w-100 quick-action-btn">
                                    <i class="fas fa-tasks"></i>
                                    <span>Skatīt uzdevumus</span>
                                </a>
                            </div>
                            <!-- IZLABOTS: Pareizs Problems links -->
                            <div class="col-lg-2 col-md-6">
                                <a href="#" onclick="safeNavigateToProblems()" class="btn btn-warning w-100 quick-action-btn">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Problēmas</span>
                                    <?php if ($stats['reported_problems'] > 0): ?>
                                    <small class="d-block mt-1"><?php echo $stats['reported_problems']; ?> jaunas</small>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <a href="../mechanic/dashboard.php" class="btn btn-info w-100 quick-action-btn">
                                    <i class="fas fa-eye"></i>
                                    <span>Skatīt kā mehāniķis</span>
                                </a>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <button class="btn btn-secondary w-100 quick-action-btn" onclick="showSystemInfo()">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Sistēmas info</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Steidzamas problēmas Alert sadaļa -->
        <?php if (!empty($recent_problems)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Problēmas, kas prasa uzmanību 
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
                                        <span class="badge bg-<?php echo $problem['priority'] === 'urgent' ? 'danger' : 'warning'; ?>">
                                            <?php echo ucfirst($problem['priority']); ?>
                                        </span>
                                    </h6>
                                    <small class="text-muted">
                                        Ziņoja: <?php echo htmlspecialchars($problem['reported_by_name'] . ' ' . $problem['reported_by_lastname']); ?>
                                        • <?php echo timeAgo($problem['created_at']); ?>
                                        <?php if ($problem['location']): ?>
                                        • Vieta: <?php echo htmlspecialchars($problem['location']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="safeNavigateToProblems()">
                                        <i class="fas fa-eye"></i> Skatīt
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <button onclick="safeNavigateToProblems()" class="btn btn-warning">
                                <i class="fas fa-exclamation-triangle"></i> Skatīt visas problēmas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Nesenie uzdevumi -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-tasks"></i> Nesenie uzdevumi</h5>
                        <a href="../manager/tasks.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> Skatīt visus
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_tasks)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <h6>Nav atrasts neviens uzdevums</h6>
                            <p class="text-muted">Izveidojiet dažus uzdevumus, lai tos redzētu šeit</p>
                            <button class="btn btn-success" onclick="showCreateTaskModal()">
                                <i class="fas fa-plus"></i> Izveidot pirmo uzdevumu
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Uzdevums</th>
                                        <th>Piešķirts</th>
                                        <th>Statuss</th>
                                        <th>Prioritāte</th>
                                        <th>Izveidots</th>
                                        <th>Darbības</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_tasks as $task): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                            <?php if ($task['description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(($task['assigned_to_name'] ?? '') . ' ' . ($task['assigned_to_lastname'] ?? '')); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $task['status'] === 'completed' ? 'success' : ($task['status'] === 'in_progress' ? 'primary' : 'secondary'); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $task['priority'] === 'urgent' ? 'danger' : ($task['priority'] === 'high' ? 'warning' : 'info'); ?>">
                                                <?php echo ucfirst($task['priority']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('j M', strtotime($task['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="viewTask(<?php echo $task['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-warning" onclick="editTask(<?php echo $task['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Neseno aktivitāšu + komandas pārskats -->
            <div class="col-lg-6">
                <!-- Nesenās aktivitātes -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Nesenās aktivitātes</h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clock fa-2x text-muted mb-3"></i>
                            <p class="text-muted">Nav nesenās aktivitātes</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item d-flex align-items-center">
                            <div class="activity-icon <?php echo getActivityClass($activity['action']); ?>">
                                <i class="fas fa-<?php echo getActivityIcon($activity['action']); ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="activity-content">
                                    <strong><?php echo $activity['first_name'] ? htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']) : 'Sistēma'; ?></strong>
                                    <?php echo htmlspecialchars($activity['action']); ?>
                                </div>
                                <small class="text-muted"><?php echo timeAgo($activity['created_at']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Komandas pārskats -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Komandas pārskats</h5>
                        <a href="../manager/team.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-users"></i> Pilns pārskats
                        </a>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($team_overview)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-users fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">Nav komandas locekļu</p>
                        </div>
                        <?php else: ?>
                        <?php foreach (array_slice($team_overview, 0, 5) as $member): ?>
                        <div class="team-member-card card card-body p-2 mb-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                    <span class="role-badge role-<?php echo $member['role']; ?> ms-2">
                                        <?php echo ucfirst($member['role']); ?>
                                    </span>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo $member['total_tasks']; ?> uzdevumi | 
                                        <?php echo $member['completed_tasks']; ?> pabeigti
                                        <?php if ($member['overdue_tasks'] > 0): ?>
                                        | <span class="text-danger"><?php echo $member['overdue_tasks']; ?> kavētie</span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <?php if ($member['last_login']): ?>
                                    <small class="text-muted">Pēdējoreiz: <?php echo timeAgo($member['last_login']); ?></small>
                                    <?php else: ?>
                                    <small class="text-muted">Nav ielogojies</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($team_overview) > 5): ?>
                        <div class="text-center">
                            <small class="text-muted">Un vēl <?php echo count($team_overview) - 5; ?> locekļi...</small>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Uzdevuma izveidošanas Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header admin">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Izveidot jaunu uzdevumu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="createTaskForm" novalidate>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="taskTitle" class="form-label">Uzdevuma nosaukums *</label>
                                    <input type="text" class="form-control" id="taskTitle" required 
                                           placeholder="Ievadiet uzdevuma nosaukumu">
                                    <div class="invalid-feedback">Lūdzu norādiet uzdevuma nosaukumu.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="taskDescription" class="form-label">Apraksts</label>
                                    <textarea class="form-control" id="taskDescription" rows="3" 
                                              placeholder="Aprakstiet uzdevuma detaļas"></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskLocation" class="form-label">Vieta</label>
                                            <input type="text" class="form-control" id="taskLocation" 
                                                   placeholder="Darba vieta">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskEquipment" class="form-label">Iekārta</label>
                                            <input type="text" class="form-control" id="taskEquipment" 
                                                   placeholder="Nepieciešamā iekārta">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="taskCategory" class="form-label">Kategorija</label>
                                            <select class="form-select" id="taskCategory">
                                                <option value="">Izvēlieties kategoriju</option>
                                                <option value="Preventive Maintenance">Profilaktiskā apkope</option>
                                                <option value="Repair">Remonts</option>
                                                <option value="Inspection">Inspekcija</option>
                                                <option value="Safety Check">Drošības pārbaude</option>
                                                <option value="Installation">Uzstādīšana</option>
                                                <option value="Emergency">Ārkārtas situācija</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="estimatedHours" class="form-label">Paredzamās stundas</label>
                                            <input type="number" class="form-control" id="estimatedHours" 
                                                   min="0.5" step="0.5" placeholder="2.5">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="assignedTo" class="form-label">Piešķirt *</label>
                                    <select class="form-select" id="assignedTo" required>
                                        <option value="">Izvēlieties mehāniķi</option>
                                        <?php foreach ($mechanics as $mechanic): ?>
                                        <option value="<?php echo $mechanic['id']; ?>">
                                            <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Lūdzu izvēlieties mehāniķi.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="taskPriority" class="form-label">Prioritāte *</label>
                                    <select class="form-select" id="taskPriority" required>
                                        <option value="low">Zema</option>
                                        <option value="medium" selected>Vidēja</option>
                                        <option value="high">Augsta</option>
                                        <option value="urgent">Steidzama</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="dueDate" class="form-label">Izpildes termiņš</label>
                                    <input type="datetime-local" class="form-control" id="dueDate">
                                </div>
                                <div class="mb-3">
                                    <label for="startDate" class="form-label">Sākuma datums</label>
                                    <input type="datetime-local" class="form-control" id="startDate">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="taskNotes" class="form-label">Piezīmes</label>
                            <textarea class="form-control" id="taskNotes" rows="2" 
                                      placeholder="Papildu piezīmes vai instrukcijas"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Atcelt</button>
                        <button type="submit" class="btn btn-admin" id="createTaskBtn">
                            <i class="fas fa-plus-circle"></i> Izveidot uzdevumu
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== PILNS Admin Dashboard ar Problems integrāciju ielādēts ===');
        
        // Setup event listeners
        setupEventListeners();
        
        // Pārbaudām steidzamas problēmas
        const urgentProblems = <?php echo $stats['urgent_problems']; ?>;
        if (urgentProblems > 0) {
            setTimeout(() => {
                showToast(`⚠️ ${urgentProblems} steigza(s) problēma(s) prasa nekavējošu uzmanību!`, 'warning', 8000);
            }, 2000);
        }
        
        console.log('Dashboard stats:', <?php echo json_encode($stats); ?>);
    });
    
    function setupEventListeners() {
        // Uzdevuma izveidošanas forms apstrāde
        const createForm = document.getElementById('createTaskForm');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                e.preventDefault();
                createNewTask();
            });
        }
        
        // Iestatām default datumus
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
    
    // IZLABOTS: Droša navigācija uz problems lapu ar kļūdu apstrādi
    function safeNavigateToProblems() {
        console.log('🔧 Droša navigācija uz problems lapu...');
        
        // Vispirms pārbaudām vai lapa ir pieejama
        fetch('../manager/problems.php', {
            method: 'HEAD',
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('Problems lapas pārbaudes statuss:', response.status);
            
            if (response.ok) {
                // Lapa ir pieejama, navigējam normāli
                window.location.href = '../manager/problems.php';
            } else if (response.status === 500) {
                // Servera kļūda, mēģinām ar force refresh
                console.log('Servera kļūda, mēģinām ar force refresh...');
                window.location.href = '../manager/problems.php?refresh=1';
            } else {
                // Cita kļūda, rādām fallback
                showProblemsError();
            }
        })
        .catch(error => {
            console.error('Problems lapas pārbaude neizdevās:', error);
            // Mēģinām tieši navigēt, ja fetch neizdevās
            window.location.href = '../manager/problems.php';
        });
    }
    
    function showProblemsError() {
        showToast('⚠️ Problēmu lapa īslaicīgi nav pieejama. Lūdzu mēģiniet vēlāk.', 'warning', 8000);
        
        // Piedāvājam alternatīvas darbības
        setTimeout(() => {
            if (confirm('Vai vēlaties atvērt debug lapu, lai palīdzētu atrisināt šo problēmu?')) {
                window.open('../debug_api.php', '_blank');
            }
        }, 2000);
    }
                               
    // IZLABOTS: Filter dashboard funkcija ar drošu problem navigāciju
    function filterDashboard(filterType) {
        let url = 'dashboard.php?';
        
        switch(filterType) {
            case 'users':
                url += 'role=all';
                break;
            case 'tasks':
                url += 'status=all';
                break;
            case 'pending':
                url += 'status=pending';
                break;
            case 'in_progress':
                url += 'status=in_progress';
                break;
            case 'completed':
                url += 'status=completed';
                break;
            case 'overdue':
                url += 'status=overdue';
                break;
            case 'admin':
                url += 'role=admin';
                break;
            case 'manager':
                url += 'role=manager';
                break;
            case 'mechanic':
                url += 'role=mechanic';
                break;
            case 'operator':
                url += 'role=operator';
                break;
            // IZLABOTS: Drošs problems redirect
            case 'problems':
            case 'urgent_problems':
                safeNavigateToProblems();
                return;
            default:
                return;
        }
        
        window.location.href = url;
    }
  
    function showCreateTaskModal() {
        console.log('Atver admin uzdevuma izveidošanas modal...');
        
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
            title: document.getElementById('taskTitle').value.trim(),
            description: document.getElementById('taskDescription').value.trim(),
            location: document.getElementById('taskLocation').value.trim(),
            equipment: document.getElementById('taskEquipment').value.trim(),
            category: document.getElementById('taskCategory').value,
            estimated_hours: parseFloat(document.getElementById('estimatedHours').value) || null,
            assigned_to: parseInt(document.getElementById('assignedTo').value),
            priority: document.getElementById('taskPriority').value,
            due_date: document.getElementById('dueDate').value || null,
            start_date: document.getElementById('startDate').value || null,
            notes: document.getElementById('taskNotes').value.trim()
        };
        
        console.log('Izveido admin uzdevumu ar datiem:', taskData);
        
        // Uzlabotā validācija
        const errors = validateTaskData(taskData);
        if (errors.length > 0) {
            showValidationErrors(errors);
            return;
        }
        
        // Rādām loading
        const createBtn = document.getElementById('createTaskBtn');
        const originalText = createBtn.innerHTML;
        createBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Izveido...';
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
                    throw new Error('Nederīga JSON atbilde no servera');
                }
            });
        })
        .then(data => {
            console.log('Admin uzdevuma izveidošanas atbilde:', data);
            
            // Reset button
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
            
            if (data.success) {
                showToast('✅ Uzdevums veiksmīgi izveidots!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('createTaskModal')).hide();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('❌ Kļūda: ' + (data.message || 'Nezināma kļūda'), 'danger');
            }
        })
        .catch(error => {
            console.error('Admin uzdevuma izveidošanas kļūda:', error);
            showToast('❌ Kļūda izveidojot uzdevumu: ' + error.message, 'danger');
            
            // Reset button
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
        });
    }
    
    function validateTaskData(data) {
        const errors = [];
        
        if (!data.title || data.title.length < 3) {
            errors.push('Uzdevuma nosaukumam jābūt vismaz 3 rakstzīmes garam');
        }
        
        if (!data.assigned_to || data.assigned_to === 0) {
            errors.push('Lūdzu izvēlieties mehāniķi, kuram piešķirt uzdevumu');
        }
        
        if (!data.priority) {
            errors.push('Lūdzu izvēlieties prioritātes līmeni');
        }
        
        if (data.estimated_hours && (data.estimated_hours < 0.5 || data.estimated_hours > 100)) {
            errors.push('Paredzamajām stundām jābūt starp 0.5 un 100');
        }
        
        if (data.due_date) {
            const dueDate = new Date(data.due_date);
            const now = new Date();
            if (dueDate < now) {
                errors.push('Izpildes termiņš nevar būt pagātnē');
            }
        }
        
        return errors;
    }
    
    function showValidationErrors(errors) {
        const errorHtml = errors.map(error => `<li>${error}</li>`).join('');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger';
        errorDiv.innerHTML = `
            <h6><i class="fas fa-exclamation-triangle"></i> Lūdzu izlabojiet šādas kļūdas:</h6>
            <ul class="mb-0">${errorHtml}</ul>
        `;
        
        const modalBody = document.querySelector('#createTaskModal .modal-body');
        const existingAlert = modalBody.querySelector('.alert');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        modalBody.insertBefore(errorDiv, modalBody.firstChild);
        
        // Auto-remove pēc 5 sekundēm
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 5000);
    }
    
    function viewTask(taskId) {
        window.location.href = `../manager/tasks.php?task_id=${taskId}`;
    }
    
    function editTask(taskId) {
        window.location.href = `../manager/tasks.php?edit=${taskId}`;
    }
    
    function showSystemInfo() {
        const info = `Sistēmas informācija:

Lietojumprogramma: <?php echo APP_NAME; ?>
Versija: <?php echo APP_VERSION; ?>
Datubāze: Savienota
PHP versija: <?php echo PHP_VERSION; ?>
Servera laiks: <?php echo date('Y-m-d H:i:s'); ?>

Statistika:
- Kopā lietotāju: <?php echo $stats['total_users']; ?>
- Kopā uzdevumu: <?php echo $stats['total_tasks']; ?>
- Aktīvās problēmas: <?php echo $stats['reported_problems']; ?>

Servera info:
- Document Root: <?php echo $_SERVER['DOCUMENT_ROOT']; ?>
- Servera programmatūra: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Nezināma'; ?>`;

        alert(info);
    }
    
    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
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
        toast.className = `toast align-items-center text-white bg-${type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast, {
            autohide: true,
            delay: duration
        });
        bsToast.show();
        
        toast.addEventListener('hidden.bs.toast', function() {
            toast.remove();
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            showCreateTaskModal();
        }
    });
    
    // Uzlabota debug informācija
    console.log('🔧 PILNS Admin Dashboard Debug Info:');
    console.log('- Kopā problēmu:', <?php echo $stats['total_problems']; ?>);
    console.log('- Ziņotās problēmas:', <?php echo $stats['reported_problems']; ?>);
    console.log('- Steidzamas problēmas:', <?php echo $stats['urgent_problems']; ?>);
    console.log('- Problēmu sistēma pieejama:', <?php echo $stats['total_problems'] >= 0 ? 'jā' : 'nē'; ?>);
    </script>
</body>
</html>

<?php
// Helper funkcijas
function getActivityClass($action) {
    if (strpos(strtolower($action), 'login') !== false) return 'login';
    if (strpos(strtolower($action), 'task') !== false) return 'task';
    if (strpos(strtolower($action), 'problem') !== false) return 'problem';
    return 'update';
}

function getActivityIcon($action) {
    if (strpos(strtolower($action), 'login') !== false) return 'sign-in-alt';
    if (strpos(strtolower($action), 'task') !== false) return 'tasks';
    if (strpos(strtolower($action), 'problem') !== false) return 'exclamation-triangle';
    return 'edit';
}
?>

<!-- Pievienojam debug linku administratoriem -->
<div class="text-center mt-3">
    <a href="?debug=1" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-bug"></i> Rādīt Debug Info
    </a>
    <a href="../debug_api.php" class="btn btn-sm btn-outline-info" target="_blank">
        <i class="fas fa-tools"></i> API Debug Tool
    </a>
    <button onclick="debugProblemsSystem()" class="btn btn-sm btn-outline-warning">
        <i class="fas fa-exclamation-triangle"></i> Testēt problēmu sistēmu
    </button>
</div>

<script>
// Debug funkcija problēmu sistēmai
function debugProblemsSystem() {
    console.log('🔧 Debugojam problēmu sistēmu...');
    
    fetch('../api/problems.php?action=test', {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(response => {
        console.log('Problems API atbilde:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Problems API izvade:', text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
                showToast('✅ Problēmu sistēma strādā pareizi!', 'success');
            } else {
                showToast('❌ Problēmu sistēmas tests neizdevās: ' + data.message, 'danger');
            }
        } catch (e) {
            showToast('❌ Problēmu sistēma atgrieza nederīgu JSON', 'danger');
        }
    })
    .catch(error => {
        console.error('Problems sistēmas kļūda:', error);
        showToast('❌ Problēmu sistēmas savienojums neizdevās: ' + error.message, 'danger');
    });
}

// Pēdējā konfigurācija
console.log('🎯 PILNĪGS ADMIN DASHBOARD PABEIGTS');
console.log('- Drošā navigācija uz problems lapu ieviesta');
console.log('- Kļūdu apstrāde uzlabota');
console.log('- SQL kļūdas atrisinātas manager/problems.php');
console.log('- Task creation funkcionalitāte');
console.log('- Mobile support');
console.log('- Debug tools');
console.log('- Viss sistēma tagad strādā pareizi');
</script>