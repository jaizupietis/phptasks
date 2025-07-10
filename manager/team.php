<?php
/**
 * Manager Team Management Page
 * Complete team overview and management interface
 */

define('SECURE_ACCESS', true);
require_once '../config/config.php';

// Check if user is logged in and is a manager/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$is_mobile = isMobile();

// Get comprehensive team data with performance metrics
try {
    $team_members = $db->fetchAll(
        "SELECT u.*, 
                COUNT(DISTINCT t.id) as total_tasks,
                COUNT(DISTINCT CASE WHEN t.status = 'pending' THEN t.id END) as pending_tasks,
                COUNT(DISTINCT CASE WHEN t.status = 'in_progress' THEN t.id END) as in_progress_tasks,
                COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id END) as completed_tasks,
                COUNT(DISTINCT CASE WHEN t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled') THEN t.id END) as overdue_tasks,
                COUNT(DISTINCT CASE WHEN DATE(t.completed_date) = CURDATE() THEN t.id END) as completed_today,
                COUNT(DISTINCT CASE WHEN DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN t.id END) as tasks_this_week,
                AVG(CASE WHEN t.status = 'completed' AND t.estimated_hours > 0 AND t.actual_hours > 0 
                         THEN (t.actual_hours / t.estimated_hours) * 100 ELSE NULL END) as avg_efficiency,
                AVG(CASE WHEN t.status = 'completed' AND t.due_date IS NOT NULL 
                         THEN DATEDIFF(t.completed_date, t.due_date) ELSE NULL END) as avg_delay_days
         FROM users u
         LEFT JOIN tasks t ON u.id = t.assigned_to
         WHERE u.role = 'mechanic' AND u.is_active = 1
         GROUP BY u.id, u.username, u.email, u.first_name, u.last_name, u.phone, u.department, u.last_login, u.created_at
         ORDER BY u.first_name, u.last_name"
    );
} catch (Exception $e) {
    error_log("Team management error: " . $e->getMessage());
    $team_members = [];
}

// Get team performance summary
$team_stats = [
    'total_members' => count($team_members),
    'active_members' => count(array_filter($team_members, function($member) {
        return $member['last_login'] && strtotime($member['last_login']) > strtotime('-7 days');
    })),
    'total_tasks' => array_sum(array_column($team_members, 'total_tasks')),
    'completed_tasks' => array_sum(array_column($team_members, 'completed_tasks')),
    'overdue_tasks' => array_sum(array_column($team_members, 'overdue_tasks'))
];

$team_stats['completion_rate'] = $team_stats['total_tasks'] > 0 ? 
    round(($team_stats['completed_tasks'] / $team_stats['total_tasks']) * 100, 1) : 0;

$page_title = 'Team Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.css" rel="stylesheet">
    
    <style>
        :root {
            --manager-primary: #6f42c1;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .manager-navbar {
            background: linear-gradient(135deg, var(--manager-primary) 0%, #5a32a3 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .member-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .member-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .member-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--manager-primary), #5a32a3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            margin-right: 1rem;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .status-online { background: var(--success-color); }
        .status-away { background: var(--warning-color); }
        .status-offline { background: var(--danger-color); }
        
        .performance-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .performance-excellent { background: var(--success-color); color: white; }
        .performance-good { background: #20c997; color: white; }
        .performance-average { background: var(--warning-color); color: #212529; }
        .performance-needs-attention { background: var(--danger-color); color: white; }
        
        .workload-bar {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        
        .workload-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .workload-low { background: var(--success-color); }
        .workload-medium { background: var(--warning-color); }
        .workload-high { background: var(--danger-color); }
        
        .stats-summary {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .team-chart {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1.5rem;
        }
        
        .metric-card {
            text-align: center;
            padding: 1.5rem;
            border-radius: 12px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 1rem;
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .metric-label {
            color: #6c757d;
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
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
        
        .task-distribution {
            display: flex;
            gap: 0.25rem;
            margin: 0.5rem 0;
        }
        
        .task-segment {
            height: 8px;
            border-radius: 4px;
            flex: 1;
            min-width: 2px;
        }
        
        .task-pending { background: #6c757d; }
        .task-progress { background: var(--info-color); }
        .task-completed { background: var(--success-color); }
        .task-overdue { background: var(--danger-color); }
        
        @media (max-width: 768px) {
            .member-card .row {
                text-align: center;
            }
            
            .member-avatar {
                margin: 0 auto 1rem;
            }
            
            .stats-summary {
                padding: 1rem;
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
                    <i class="fas fa-users"></i> Team Management
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-success btn-sm" onclick="exportTeamReport()">
                    <i class="fas fa-download"></i> Export Report
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="refreshTeamData()">
                    <i class="fas fa-sync"></i> Refresh
                </button>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid p-4">
        
        <!-- Team Performance Summary -->
        <div class="stats-summary">
            <div class="row text-center">
                <div class="col-lg-2 col-md-4 col-6">
                    <div class="metric-card">
                        <div class="metric-value text-primary"><?php echo $team_stats['total_members']; ?></div>
                        <div class="metric-label">Team Members</div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <div class="metric-card">
                        <div class="metric-value text-success"><?php echo $team_stats['active_members']; ?></div>
                        <div class="metric-label">Active This Week</div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <div class="metric-card">
                        <div class="metric-value text-info"><?php echo $team_stats['total_tasks']; ?></div>
                        <div class="metric-label">Total Tasks</div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <div class="metric-card">
                        <div class="metric-value text-success"><?php echo $team_stats['completed_tasks']; ?></div>
                        <div class="metric-label">Completed</div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <div class="metric-card">
                        <div class="metric-value text-danger"><?php echo $team_stats['overdue_tasks']; ?></div>
                        <div class="metric-label">Overdue</div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <div class="metric-card">
                        <div class="metric-value text-warning"><?php echo $team_stats['completion_rate']; ?>%</div>
                        <div class="metric-label">Completion Rate</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Team Members List -->
            <div class="col-lg-8">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Team Members (<?php echo count($team_members); ?>)</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" onclick="sortTeam('performance')">
                            <i class="fas fa-sort"></i> By Performance
                        </button>
                        <button class="btn btn-outline-secondary" onclick="sortTeam('workload')">
                            <i class="fas fa-sort"></i> By Workload
                        </button>
                        <button class="btn btn-outline-secondary" onclick="sortTeam('name')">
                            <i class="fas fa-sort-alpha-down"></i> By Name
                        </button>
                    </div>
                </div>
                
                <?php if (empty($team_members)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-users fa-4x text-muted mb-3"></i>
                        <h5>No Team Members</h5>
                        <p class="text-muted">No mechanics found in the system.</p>
                    </div>
                </div>
                <?php else: ?>
                
                <div id="teamMembersList">
                    <?php foreach ($team_members as $member): ?>
                    <?php
                    // Calculate performance metrics
                    $completion_rate = $member['total_tasks'] > 0 ? 
                        round(($member['completed_tasks'] / $member['total_tasks']) * 100, 1) : 0;
                    
                    $workload = $member['pending_tasks'] + $member['in_progress_tasks'];
                    $workload_percentage = min(100, $workload * 20); // Assume 5 tasks = 100%
                    
                    $performance_class = $completion_rate >= 90 ? 'excellent' : 
                                       ($completion_rate >= 75 ? 'good' : 
                                       ($completion_rate >= 50 ? 'average' : 'needs-attention'));
                    
                    $workload_class = $workload <= 2 ? 'low' : ($workload <= 4 ? 'medium' : 'high');
                    
                    $status_class = 'offline';
                    $status_text = 'Offline';
                    if ($member['last_login']) {
                        $last_login_time = strtotime($member['last_login']);
                        $now = time();
                        if ($now - $last_login_time < 3600) { // 1 hour
                            $status_class = 'online';
                            $status_text = 'Online';
                        } elseif ($now - $last_login_time < 86400) { // 24 hours
                            $status_class = 'away';
                            $status_text = 'Away';
                        }
                    }
                    ?>
                    
                    <div class="card member-card" data-member-id="<?php echo $member['id']; ?>" 
                         data-performance="<?php echo $completion_rate; ?>" 
                         data-workload="<?php echo $workload; ?>" 
                         data-name="<?php echo $member['first_name'] . ' ' . $member['last_name']; ?>">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <div class="member-avatar">
                                        <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                                <span class="performance-badge performance-<?php echo $performance_class; ?>">
                                                    <?php echo $completion_rate; ?>% completion
                                                </span>
                                            </h6>
                                            <p class="text-muted mb-1">
                                                <i class="fas fa-envelope"></i>
                                                <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>">
                                                    <?php echo htmlspecialchars($member['email']); ?>
                                                </a>
                                            </p>
                                            <p class="text-muted mb-0">
                                                <span class="status-indicator status-<?php echo $status_class; ?>"></span>
                                                <?php echo $status_text; ?>
                                                <?php if ($member['last_login']): ?>
                                                - Last seen: <?php echo date('M j, g:i A', strtotime($member['last_login'])); ?>
                                                <?php else: ?>
                                                - Never logged in
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="row text-center mb-2">
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Total</small>
                                                    <strong class="text-primary"><?php echo $member['total_tasks']; ?></strong>
                                                </div>
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Pending</small>
                                                    <strong class="text-warning"><?php echo $member['pending_tasks']; ?></strong>
                                                </div>
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Active</small>
                                                    <strong class="text-info"><?php echo $member['in_progress_tasks']; ?></strong>
                                                </div>
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Done</small>
                                                    <strong class="text-success"><?php echo $member['completed_tasks']; ?></strong>
                                                </div>
                                            </div>
                                            
                                            <!-- Task Distribution Visualization -->
                                            <div class="task-distribution">
                                                <?php if ($member['total_tasks'] > 0): ?>
                                                    <?php for ($i = 0; $i < $member['pending_tasks']; $i++): ?>
                                                    <div class="task-segment task-pending" title="Pending Task"></div>
                                                    <?php endfor; ?>
                                                    <?php for ($i = 0; $i < $member['in_progress_tasks']; $i++): ?>
                                                    <div class="task-segment task-progress" title="In Progress Task"></div>
                                                    <?php endfor; ?>
                                                    <?php for ($i = 0; $i < $member['completed_tasks']; $i++): ?>
                                                    <div class="task-segment task-completed" title="Completed Task"></div>
                                                    <?php endfor; ?>
                                                    <?php for ($i = 0; $i < $member['overdue_tasks']; $i++): ?>
                                                    <div class="task-segment task-overdue" title="Overdue Task"></div>
                                                    <?php endfor; ?>
                                                <?php else: ?>
                                                    <small class="text-muted">No tasks assigned</small>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Current Workload -->
                                            <div class="mt-2">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="text-muted">Current Workload</small>
                                                    <small class="text-muted"><?php echo $workload; ?> active tasks</small>
                                                </div>
                                                <div class="workload-bar">
                                                    <div class="workload-fill workload-<?php echo $workload_class; ?>" 
                                                         style="width: <?php echo $workload_percentage; ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="row mt-3">
                                        <div class="col">
                                            <div class="btn-group btn-group-sm w-100">
                                                <button class="btn btn-outline-primary" onclick="assignTask(<?php echo $member['id']; ?>)">
                                                    <i class="fas fa-plus"></i> Assign Task
                                                </button>
                                                <button class="btn btn-outline-info" onclick="viewMemberTasks(<?php echo $member['id']; ?>)">
                                                    <i class="fas fa-tasks"></i> View Tasks
                                                </button>
                                                <button class="btn btn-outline-success" onclick="sendMessage(<?php echo $member['id']; ?>)">
                                                    <i class="fas fa-envelope"></i> Message
                                                </button>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                        <i class="fas fa-cog"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item" href="#" onclick="viewPerformance(<?php echo $member['id']; ?>)">
                                                            <i class="fas fa-chart-line"></i> View Performance</a></li>
                                                        <li><a class="dropdown-item" href="#" onclick="editMember(<?php echo $member['id']; ?>)">
                                                            <i class="fas fa-edit"></i> Edit Profile</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item text-warning" href="#" onclick="resetPassword(<?php echo $member['id']; ?>)">
                                                            <i class="fas fa-key"></i> Reset Password</a></li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($member['overdue_tasks'] > 0): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-danger">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <?php echo $member['overdue_tasks']; ?> overdue task<?php echo $member['overdue_tasks'] > 1 ? 's' : ''; ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($member['completed_today'] > 0): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle"></i>
                                            <?php echo $member['completed_today']; ?> completed today
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php endif; ?>
            </div>
            
            <!-- Team Analytics -->
            <div class="col-lg-4">
                <div class="team-chart mb-4">
                    <h6 class="mb-3"><i class="fas fa-chart-pie"></i> Team Workload Distribution</h6>
                    <canvas id="workloadChart" style="max-height: 300px;"></canvas>
                </div>
                
                <div class="team-chart mb-4">
                    <h6 class="mb-3"><i class="fas fa-chart-bar"></i> Weekly Performance</h6>
                    <canvas id="performanceChart" style="max-height: 300px;"></canvas>
                </div>
                
                <div class="team-chart">
                    <h6 class="mb-3"><i class="fas fa-exclamation-triangle"></i> Attention Required</h6>
                    <div id="attentionList">
                        <?php
                        $attention_items = [];
                        foreach ($team_members as $member) {
                            if ($member['overdue_tasks'] > 0) {
                                $attention_items[] = [
                                    'type' => 'overdue',
                                    'message' => htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . " has {$member['overdue_tasks']} overdue task(s)",
                                    'severity' => 'danger'
                                ];
                            }
                            $workload = $member['pending_tasks'] + $member['in_progress_tasks'];
                            if ($workload > 5) {
                                $attention_items[] = [
                                    'type' => 'overload',
                                    'message' => htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . " has heavy workload ({$workload} active tasks)",
                                    'severity' => 'warning'
                                ];
                            }
                            if (!$member['last_login'] || strtotime($member['last_login']) < strtotime('-3 days')) {
                                $attention_items[] = [
                                    'type' => 'inactive',
                                    'message' => htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . " hasn't been active recently",
                                    'severity' => 'info'
                                ];
                            }
                        }
                        ?>
                        
                        <?php if (empty($attention_items)): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                            <p class="text-muted mb-0">All team members are performing well!</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($attention_items as $item): ?>
                        <div class="alert alert-<?php echo $item['severity']; ?> alert-sm mb-2">
                            <i class="fas fa-<?php echo $item['type'] === 'overdue' ? 'exclamation-triangle' : 
                                                     ($item['type'] === 'overload' ? 'weight-hanging' : 'clock'); ?>"></i>
                            <?php echo $item['message']; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        setupCharts();
        setupEventListeners();
    });
    
    function setupCharts() {
        // Workload Distribution Chart
        const workloadCtx = document.getElementById('workloadChart').getContext('2d');
        const teamData = <?php echo json_encode($team_members); ?>;
        
        const workloadData = teamData.map(member => ({
            name: member.first_name + ' ' + member.last_name,
            pending: parseInt(member.pending_tasks),
            progress: parseInt(member.in_progress_tasks),
            completed: parseInt(member.completed_tasks)
        }));
        
        new Chart(workloadCtx, {
            type: 'doughnut',
            data: {
                labels: workloadData.map(d => d.name),
                datasets: [{
                    data: workloadData.map(d => d.pending + d.progress),
                    backgroundColor: [
                        '#6f42c1', '#17a2b8', '#28a745', '#ffc107', '#dc3545',
                        '#6c757d', '#343a40', '#fd7e14', '#20c997', '#e83e8c'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
        
        // Performance Chart
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        
        const completionRates = teamData.map(member => {
            const total = parseInt(member.total_tasks);
            const completed = parseInt(member.completed_tasks);
            return total > 0 ? Math.round((completed / total) * 100) : 0;
        });
        
        new Chart(performanceCtx, {
            type: 'bar',
            data: {
                labels: workloadData.map(d => d.name.split(' ').map(n => n[0]).join('')),
                datasets: [{
                    label: 'Completion Rate (%)',
                    data: completionRates,
                    backgroundColor: completionRates.map(rate => 
                        rate >= 90 ? '#28a745' : 
                        rate >= 75 ? '#20c997' : 
                        rate >= 50 ? '#ffc107' : '#dc3545'
                    ),
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        ticks: { font: { size: 10 } }
                    }
                }
            }
        });
    }
    
    function setupEventListeners() {
        // Auto-refresh every 5 minutes
        setInterval(refreshTeamData, 300000);
    }
    
    function sortTeam(criteria) {
        const container = document.getElementById('teamMembersList');
        const cards = Array.from(container.querySelectorAll('.member-card'));
        
        cards.sort((a, b) => {
            switch (criteria) {
                case 'performance':
                    return parseFloat(b.dataset.performance) - parseFloat(a.dataset.performance);
                case 'workload':
                    return parseInt(b.dataset.workload) - parseInt(a.dataset.workload);
                case 'name':
                    return a.dataset.name.localeCompare(b.dataset.name);
                default:
                    return 0;
            }
        });
        
        // Clear and re-append sorted cards
        container.innerHTML = '';
        cards.forEach(card => container.appendChild(card));
        
        showToast(`Team sorted by ${criteria}`, 'info');
    }
    
    function assignTask(userId) {
        // Redirect to dashboard with pre-selected user
        window.location.href = `dashboard.php#create-task-${userId}`;
    }
    
    function viewMemberTasks(userId) {
        window.location.href = `tasks.php?assigned_to=${userId}`;
    }
    
    function sendMessage(userId) {
        // Get user email and open email client
        const memberCard = document.querySelector(`[data-member-id="${userId}"]`);
        const emailLink = memberCard.querySelector('a[href^="mailto:"]');
        if (emailLink) {
            window.location.href = emailLink.href + '&subject=Task Management Update';
        }
    }
    
    function viewPerformance(userId) {
        showToast('Detailed performance analytics coming soon!', 'info');
    }
    
    function editMember(userId) {
        showToast('User profile editing will be implemented soon!', 'info');
    }
    
    function resetPassword(userId) {
        if (confirm('Are you sure you want to reset this user\'s password?')) {
            showToast('Password reset functionality will be implemented soon!', 'info');
        }
    }
    
    function exportTeamReport() {
        showToast('Team report export feature coming soon!', 'info');
    }
    
    function refreshTeamData() {
        showToast('Refreshing team data...', 'info');
        setTimeout(() => {
            location.reload();
        }, 1000);
    }
    
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
        const bsToast = new bootstrap.Toast(toast);
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
    </script>
</body>
</html>
