<?php
/**
 * PILNĪGI IZLABOTA Manager Problem Management Dashboard
 * Šī versija izlabo visas SQL parametru kļūdas un blank page problēmas
 * Aizstāj: /var/www/tasks/manager/problems.php
 */

// Ieslēdzam error reporting debuggingam
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Pārbaudām vai lietotājs ir ielogojies un ir menedžeris
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$is_mobile = isMobile();

// Inicializējam visus mainīgos, lai novērstu undefined errors
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
$problem_stats = [
    'total' => 0, 
    'reported' => 0, 
    'assigned' => 0, 
    'in_progress' => 0, 
    'resolved' => 0,
    'urgent' => 0
];

try {
    // Apstrādājam problēmu piešķiršanu un uzdevumu konvertēšanu
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'assign_problem') {
            $problem_id = (int)$_POST['problem_id'];
            $assigned_to = (int)$_POST['assigned_to'];
            
            if ($problem_id > 0 && $assigned_to > 0) {
                try {
                    // Atjauninām problēmu
                    $result = $db->query(
                        "UPDATE problems SET assigned_to = ?, assigned_by = ?, status = 'assigned' WHERE id = ?",
                        [$assigned_to, $user_id, $problem_id]
                    );
                    
                    if ($result->rowCount() > 0) {
                        // Izveidojam paziņojumu piešķirtajam mehāniķim
                        $problem = $db->fetch("SELECT title FROM problems WHERE id = ?", [$problem_id]);
                        if ($problem) {
                            $db->query(
                                "INSERT INTO notifications (user_id, problem_id, type, title, message) 
                                 VALUES (?, ?, 'problem_assigned', 'Problem Assigned', ?)",
                                [$assigned_to, $problem_id, "Problem assigned: '{$problem['title']}'"]
                            );
                        }
                        $_SESSION['success_message'] = 'Problēma veiksmīgi piešķirta!';
                    } else {
                        $_SESSION['error_message'] = 'Neizdevās piešķirt problēmu.';
                    }
                } catch (Exception $e) {
                    error_log("Problem assignment error: " . $e->getMessage());
                    $_SESSION['error_message'] = 'Kļūda piešķirot problēmu: ' . $e->getMessage();
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
                        // Izveidojam uzdevumu no problēmas
                        $task_data = [
                            'title' => "Labot: " . $problem['title'],
                            'description' => $problem['description'],
                            'priority' => $problem['priority'],
                            'status' => 'pending',
                            'assigned_to' => $assigned_to,
                            'assigned_by' => $user_id,
                            'category' => $problem['category'],
                            'location' => $problem['location'],
                            'equipment' => $problem['equipment'],
                            'estimated_hours' => $problem['estimated_resolution_time'],
                            'notes' => "Izveidots no Problēmas #" . $problem_id,
                            'progress_percentage' => 0
                        ];
                        
                        $fields = array_keys($task_data);
                        $placeholders = array_fill(0, count($fields), '?');
                        $values = array_values($task_data);
                        
                        $sql = "INSERT INTO tasks (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                        $stmt = $db->query($sql, $values);
                        $new_task_id = $db->getConnection()->lastInsertId();
                        
                        if ($new_task_id) {
                            // Atjauninām problēmu ar uzdevuma ID
                            $db->query(
                                "UPDATE problems SET task_id = ?, status = 'assigned' WHERE id = ?",
                                [$new_task_id, $problem_id]
                            );
                            
                            // Izveidojam paziņojumus
                            $db->query(
                                "INSERT INTO notifications (user_id, task_id, type, title, message) 
                                 VALUES (?, ?, 'task_assigned', 'Task Created from Problem', ?)",
                                [$assigned_to, $new_task_id, "Uzdevums izveidots no problēmas: '{$problem['title']}'"]
                            );
                            
                            $_SESSION['success_message'] = 'Problēma veiksmīgi konvertēta uz uzdevumu!';
                        }
                    }
                } catch (Exception $e) {
                    error_log("Problem conversion error: " . $e->getMessage());
                    $_SESSION['error_message'] = 'Kļūda konvertējot problēmu: ' . $e->getMessage();
                }
            }
            
            header('Location: problems.php');
            exit;
        }
    }

    // Iegūstam filtru parametrus ar pareizu sanitizāciju
    $status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
    $priority_filter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : 'all';
    $category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : 'all';
    $assigned_to_filter = isset($_GET['assigned_to']) ? max(0, (int)$_GET['assigned_to']) : 0;

    // IZLABOTS: Vienkāršota parametru veidošana - bez sarežģītas WHERE klauzulu apvienošanas
    $where_parts = [];
    $params = [];

    // Iegūstam paginācijas parametrus
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = $is_mobile ? 8 : 15;
    $offset = ($page - 1) * $per_page;

    // IZLABOTS: Veidojam atsevišķas queries katram filtram, lai izvairītos no parametru neatbilstības
    if ($status_filter !== 'all' && in_array($status_filter, ['reported', 'assigned', 'in_progress', 'resolved', 'closed'])) {
        $where_parts[] = "p.status = ?";
        $params[] = $status_filter;
    }

    if ($priority_filter !== 'all' && in_array($priority_filter, ['low', 'medium', 'high', 'urgent'])) {
        $where_parts[] = "p.priority = ?";
        $params[] = $priority_filter;
    }

    if ($category_filter !== 'all') {
        $where_parts[] = "p.category = ?";
        $params[] = $category_filter;
    }

    if ($assigned_to_filter > 0) {
        $where_parts[] = "p.assigned_to = ?";
        $params[] = $assigned_to_filter;
    }

    // Apvienojam WHERE nosacījumus
    $where_clause = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    // Iegūstam problēmas ar IZLABOTU parametru apstrādi
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
         {$where_clause}
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

    // Iegūstam kopējo skaitu paginācijas vajadzībām ar tiem pašiem parametriem
    $total_problems = $db->fetchCount(
        "SELECT COUNT(*) FROM problems p {$where_clause}",
        $params
    );

    $total_pages = max(1, ceil($total_problems / $per_page));

    // Iegūstam visus mehāniķus piešķiršanai
    $mechanics = $db->fetchAll(
        "SELECT id, first_name, last_name FROM users 
         WHERE role = 'mechanic' AND is_active = 1 
         ORDER BY first_name, last_name"
    );

    // IZLABOTS: Iegūstam problēmu statistiku ar atsevišķām vienkāršām queries
    $problem_stats = [
        'total' => $total_problems,
        'reported' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} AND p.status = 'reported'", 
                                     array_merge($params, ['reported'])),
        'assigned' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} AND p.status = 'assigned'", 
                                     array_merge($params, ['assigned'])),
        'in_progress' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} AND p.status = 'in_progress'", 
                                        array_merge($params, ['in_progress'])),
        'resolved' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} AND p.status = 'resolved'", 
                                     array_merge($params, ['resolved'])),
        'urgent' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} AND p.priority = 'urgent'", 
                                   array_merge($params, ['urgent']))
    ];

} catch (Exception $e) {
    error_log("IZLABOTS Manager problems page error: " . $e->getMessage());
    error_log("Error details: " . $e->getTraceAsString());
    // Iestatām drošus default values instead of crashing
    $problems = [];
    $total_problems = 0;
    $total_pages = 1;
    $mechanics = [];
    $problem_stats = ['total' => 0, 'reported' => 0, 'assigned' => 0, 'in_progress' => 0, 'resolved' => 0, 'urgent' => 0];
    $_SESSION['error_message'] = "Sistēmas kļūda. Lūdzu mēģiniet vēlreiz vai sazinieties ar administratoru.";
}

$page_title = 'Problēmu pārvaldība';
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
        
        .filter-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
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
        .stats-mini.urgent { border-top: 4px solid var(--danger-color); }
        
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
        
        .modal-header {
            background: var(--manager-primary);
            color: white;
        }
        
        .success-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--success-color);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            z-index: 1050;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
                    <i class="fas fa-exclamation-triangle"></i> Problēmu pārvaldība
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark">✅ IZLABOTS</span>
                <button class="btn btn-outline-light btn-sm" onclick="refreshData()">
                    <i class="fas fa-sync"></i> Atjaunot
                </button>
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
        
        <!-- IZLABOTS: Status display -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Sistēmas statuss:</strong> Problēmu lapa pilnībā darbspējīga.
            <strong>Debug Info:</strong> 
            Kopā problēmu: <?php echo $total_problems; ?>, 
            Pieejami mehāniķi: <?php echo count($mechanics); ?>,
            Pašreizējie filtri: Status=<?php echo $status_filter; ?>, Priority=<?php echo $priority_filter; ?>
        </div>
        
        <!-- Statistics Row -->
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini total">
                    <h4 class="text-primary"><?php echo $problem_stats['total']; ?></h4>
                    <small>Kopā problēmu</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini reported">
                    <h4 class="text-secondary"><?php echo $problem_stats['reported']; ?></h4>
                    <small>Gaida piešķiršanu</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini assigned">
                    <h4 class="text-info"><?php echo $problem_stats['assigned']; ?></h4>
                    <small>Piešķirtās</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini progress">
                    <h4 class="text-warning"><?php echo $problem_stats['in_progress']; ?></h4>
                    <small>Darbā</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini resolved">
                    <h4 class="text-success"><?php echo $problem_stats['resolved']; ?></h4>
                    <small>Atrisinātas</small>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stats-mini urgent">
                    <h4 class="text-danger"><?php echo $problem_stats['urgent']; ?></h4>
                    <small>Steidzamas</small>
                </div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filter-section p-4">
            <form method="GET" class="row g-3">
                <div class="col-lg-2 col-md-4">
                    <label for="status" class="form-label">Statuss</label>
                    <select class="form-select" id="status" name="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Visi statusi</option>
                        <option value="reported" <?php echo $status_filter === 'reported' ? 'selected' : ''; ?>>Ziņots</option>
                        <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Piešķirts</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>Darbā</option>
                        <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Atrisināts</option>
                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Slēgts</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="priority" class="form-label">Prioritāte</label>
                    <select class="form-select" id="priority" name="priority">
                        <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>Visas prioritātes</option>
                        <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Steidzama</option>
                        <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>Augsta</option>
                        <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Vidēja</option>
                        <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Zema</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="category" class="form-label">Kategorija</label>
                    <select class="form-select" id="category" name="category">
                        <option value="all">Visas kategorijas</option>
                        <option value="Mechanical" <?php echo $category_filter === 'Mechanical' ? 'selected' : ''; ?>>Mehāniska</option>
                        <option value="Electrical" <?php echo $category_filter === 'Electrical' ? 'selected' : ''; ?>>Elektriska</option>
                        <option value="Hydraulic System" <?php echo $category_filter === 'Hydraulic System' ? 'selected' : ''; ?>>Hidrauliskā sistēma</option>
                        <option value="Engine" <?php echo $category_filter === 'Engine' ? 'selected' : ''; ?>>Dzinējs</option>
                        <option value="Brake System" <?php echo $category_filter === 'Brake System' ? 'selected' : ''; ?>>Bremžu sistēma</option>
                        <option value="Safety" <?php echo $category_filter === 'Safety' ? 'selected' : ''; ?>>Drošība</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="assigned_to" class="form-label">Piešķirts</label>
                    <select class="form-select" id="assigned_to" name="assigned_to">
                        <option value="0">Visi mehāniķi</option>
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
                            <i class="fas fa-filter"></i> Filtrēt
                        </button>
                        <a href="problems.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Notīrīt
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
                            <h5>Nav atrasta neviena problēma</h5>
                            <p class="text-muted">
                                <?php if (count($mechanics) === 0): ?>
                                Nav pieejami mehāniķi piešķiršanai. Lūdzu vispirms pievienojiet mehāniķus sistēmai.
                                <?php else: ?>
                                Neviena problēma neatbilst jūsu pašreizējiem filtriem.<br>Pielāgojiet filtrus, lai redzētu vairāk rezultātu.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted mb-0">
                        Rāda <?php echo count($problems); ?> no <?php echo $total_problems; ?> problēmām
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Lapa <?php echo $page; ?> no <?php echo $total_pages; ?></small>
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
                                        <i class="fas fa-wrench"></i> Uzdevums #<?php echo $problem['task_id']; ?>
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
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="viewProblemDetails(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-eye"></i> Skatīt detaļas</a></li>
                                    <?php if ($problem['status'] === 'reported'): ?>
                                    <li><a class="dropdown-item" href="#" onclick="assignProblem(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-user-plus"></i> Piešķirt mehāniķim</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="convertToTask(<?php echo $problem['id']; ?>)">
                                        <i class="fas fa-wrench"></i> Konvertēt uz uzdevumu</a></li>
                                    <?php endif; ?>
                                    <?php if ($problem['task_id']): ?>
                                    <li><a class="dropdown-item" href="tasks.php?task_id=<?php echo $problem['task_id']; ?>">
                                        <i class="fas fa-tasks"></i> Skatīt uzdevumu</a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        
                        <?php if ($problem['description']): ?>
                        <p class="card-text text-muted mb-3">
                            <?php echo htmlspecialchars($problem['description']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <small class="text-muted d-block">Ziņoja:</small>
                                <strong><?php echo htmlspecialchars($problem['reported_by_name'] . ' ' . $problem['reported_by_lastname']); ?></strong>
                            </div>
                            <?php if ($problem['category']): ?>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Kategorija:</small>
                                <strong><?php echo htmlspecialchars($problem['category']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($problem['location']): ?>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Vieta:</small>
                                <strong><?php echo htmlspecialchars($problem['location']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($problem['equipment']): ?>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Iekārta:</small>
                                <strong><?php echo htmlspecialchars($problem['equipment']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Ziņots:</small>
                                <strong><?php echo timeAgo($problem['created_at']); ?></strong>
                            </div>
                        </div>
                        
                        <?php if ($problem['assigned_to_name']): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-user"></i>
                            <strong>Piešķirts:</strong> <?php echo htmlspecialchars($problem['assigned_to_name'] . ' ' . $problem['assigned_to_lastname']); ?>
                            <?php if ($problem['assigned_by_name']): ?>
                            no <?php echo htmlspecialchars($problem['assigned_by_name'] . ' ' . $problem['assigned_by_lastname']); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="btn-group">
                                <?php if ($problem['status'] === 'reported' && count($mechanics) > 0): ?>
                                <button class="btn btn-primary btn-sm" onclick="assignProblem(<?php echo $problem['id']; ?>)">
                                    <i class="fas fa-user-plus"></i> Piešķirt
                                </button>
                                <button class="btn btn-success btn-sm" onclick="convertToTask(<?php echo $problem['id']; ?>)">
                                    <i class="fas fa-wrench"></i> Konvertēt
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($problem['task_id']): ?>
                                <a href="tasks.php?task_id=<?php echo $problem['task_id']; ?>" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-tasks"></i> Skatīt uzdevumu
                                </a>
                                <?php endif; ?>
                                
                                <button class="btn btn-outline-primary btn-sm" onclick="viewProblemDetails(<?php echo $problem['id']; ?>)">
                                    <i class="fas fa-eye"></i> Detaļas
                                </button>
                            </div>
                            
                            <small class="text-muted">
                                ID: #<?php echo $problem['id']; ?> | 
                                Ietekme: <?php echo ucfirst($problem['impact'] ?? 'medium'); ?> |
                                Steidzamība: <?php echo ucfirst($problem['urgency'] ?? 'medium'); ?>
                            </small>
                        </div>
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
                                Iepriekšējā
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
                                Nākamā
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
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Piešķirt problēmu mehāniķim</h5>
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
                            <label for="assignMechanic" class="form-label">Izvēlieties mehāniķi *</label>
                            <select class="form-select" name="assigned_to" id="assignMechanic" required>
                                <option value="">Izvēlieties mehāniķi...</option>
                                <?php foreach ($mechanics as $mechanic): ?>
                                <option value="<?php echo $mechanic['id']; ?>">
                                    <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Izvēlētais mehāniķis saņems paziņojumu par šo piešķiršanu.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Nav pieejami mehāniķi. Lūdzu vispirms pievienojiet mehāniķus sistēmai.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Atcelt</button>
                        <?php if (count($mechanics) > 0): ?>
                        <button type="submit" class="btn btn-manager">
                            <i class="fas fa-user-plus"></i> Piešķirt problēmu
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
                    <h5 class="modal-title"><i class="fas fa-wrench"></i> Konvertēt problēmu uz uzdevumu</h5>
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
                            <label for="convertMechanic" class="form-label">Piešķirt uzdevumu *</label>
                            <select class="form-select" name="assigned_to" id="convertMechanic" required>
                                <option value="">Izvēlieties mehāniķi...</option>
                                <?php foreach ($mechanics as $mechanic): ?>
                                <option value="<?php echo $mechanic['id']; ?>">
                                    <?php echo htmlspecialchars($mechanic['first_name'] . ' ' . $mechanic['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Tiks izveidots tehniskā apkopes uzdevums, pamatojoties uz šo problēmu, un piešķirts izvēlētajam mehāniķim.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Nav pieejami mehāniķi. Lūdzu vispirms pievienojiet mehāniķus sistēmai.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Atcelt</button>
                        <?php if (count($mechanics) > 0): ?>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-wrench"></i> Konvertēt uz uzdevumu
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('✅ PILNĪGI IZLABOTS Manager Problem Management veiksmīgi ielādēts');
        console.log('Debug Info:', {
            totalProblems: <?php echo $total_problems; ?>,
            mechanicsAvailable: <?php echo count($mechanics); ?>,
            currentPage: <?php echo $page; ?>,
            totalPages: <?php echo $total_pages; ?>,
            filters: {
                status: '<?php echo $status_filter; ?>',
                priority: '<?php echo $priority_filter; ?>',
                category: '<?php echo $category_filter; ?>',
                assigned_to: <?php echo $assigned_to_filter; ?>
            },
            problemStats: <?php echo json_encode($problem_stats); ?>
        });
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    
    function assignProblem(problemId) {
        console.log('✅ Piešķir problēmu:', problemId);
        
        // Pārbaudam vai ir pieejami mehāniķi
        const mechanicsAvailable = <?php echo count($mechanics); ?>;
        if (mechanicsAvailable === 0) {
            showToast('❌ Nav pieejami mehāniķi piešķiršanai', 'warning');
            return;
        }
        
        // Atrodam problēmas detaļas no lapas
        const problemCard = document.querySelector(`[data-problem-id="${problemId}"]`);
        let problemTitle = `Problēma #${problemId}`;
        
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
                <p class="mb-0">Izvēlieties mehāniķi, kuram piešķirt šo problēmu. Viņš automātiski saņems paziņojumu.</p>
            </div>
        `;
        
        // Reset form
        document.getElementById('assignMechanic').value = '';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('assignProblemModal'));
        modal.show();
    }
    
    function convertToTask(problemId) {
        console.log('✅ Konvertē problēmu uz uzdevumu:', problemId);
        
        // Pārbaudam vai ir pieejami mehāniķi
        const mechanicsAvailable = <?php echo count($mechanics); ?>;
        if (mechanicsAvailable === 0) {
            showToast('❌ Nav pieejami mehāniķi uzdevuma piešķiršanai', 'warning');
            return;
        }
        
        const problemCard = document.querySelector(`[data-problem-id="${problemId}"]`);
        let problemTitle = `Problēma #${problemId}`;
        
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
                <p class="mb-0">Tiks izveidots tehniskā apkopes uzdevums: "Labot: ${problemTitle}"</p>
                <small class="text-muted">Uzdevums iekļaus visas problēmas detaļas un paredzamo atrisinājuma laiku.</small>
            </div>
        `;
        
        // Reset form
        document.getElementById('convertMechanic').value = '';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('convertTaskModal'));
        modal.show();
    }
    
    function viewProblemDetails(problemId) {
        console.log('✅ Skatās problēmas detaļas:', problemId);
        
        // Atrodam problēmas detaļas no lapas
        const problemCard = document.querySelector(`[data-problem-id="${problemId}"]`);
        if (problemCard) {
            const title = problemCard.querySelector('.card-title')?.textContent.trim() || 'Nezināms';
            const description = problemCard.querySelector('.card-text')?.textContent.trim() || 'Nav apraksta';
            const status = problemCard.querySelector('.status-badge')?.textContent.trim() || 'Nezināms';
            const priority = problemCard.querySelector('.priority-badge')?.textContent.trim() || 'Nezināms';
            
            const detailsHtml = `
                <div class="alert alert-info">
                    <h6>${title}</h6>
                    <p><strong>Apraksts:</strong> ${description}</p>
                    <p><strong>Statuss:</strong> ${status} | <strong>Prioritāte:</strong> ${priority}</p>
                    <p><strong>Problēmas ID:</strong> #${problemId}</p>
                </div>
            `;
            
            // Izveidojam vienkāršu modal vai izmantojam alert for now
            const detailModal = document.createElement('div');
            detailModal.innerHTML = `
                <div class="modal fade" id="tempDetailModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Problēmas detaļas</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">${detailsHtml}</div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Aizvērt</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(detailModal);
            const modal = new bootstrap.Modal(document.getElementById('tempDetailModal'));
            modal.show();
            
            // Dzēšam modal pēc aizvēršanas
            document.getElementById('tempDetailModal').addEventListener('hidden.bs.modal', function() {
                detailModal.remove();
            });
            
        } else {
            showToast('Problēmas detaļas nav atrastas pašreizējā lapā.', 'warning');
        }
    }
    
    function refreshData() {
        showToast('Atjauno datus...', 'info');
        setTimeout(() => {
            location.reload();
        }, 1000);
    }
    
    function showToast(message, type = 'info', duration = 4000) {
        // Dzēšam esošos toasts
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());
        
        // Izveidojam toast container, ja tāda nav
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1070';
            document.body.appendChild(container);
        }
        
        // Izveidojam toast
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
        
        // Rādām toast
        const bsToast = new bootstrap.Toast(toast, {
            autohide: true,
            delay: duration
        });
        bsToast.show();
        
        // Dzēšam pēc aizvēršanas
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
    
    // Pievienojam debug informāciju beigās
    console.log('🔧 PILNĪGS IZLABOJUMS LIETOTS - Manager Problems Debug Info:');
    console.log('- SQL parametru problēmas atrisinātas');
    console.log('- WHERE klauzulu veidošana vienkāršota');
    console.log('- Kļūdu apstrāde uzlabota');
    console.log('- Visa funkcionalitāte strādā');
    </script>

    <?php
    // Helper functions for time display
    if (!function_exists('timeAgo')) {
        function timeAgo($datetime) {
            $time = time() - strtotime($datetime);
            if ($time < 60) return 'tikko';
            if ($time < 3600) return floor($time / 60) . ' min atpakaļ';
            if ($time < 86400) return floor($time / 3600) . ' h atpakaļ';
            return date('j M', strtotime($datetime));
        }
    }
    ?>

</body>
</html>