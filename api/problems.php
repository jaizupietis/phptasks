<?php
/**
 * Problems API
 * Handles problem reporting and management
 */

date_default_timezone_set('Europe/Riga');

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

require_once dirname(__DIR__) . '/config/config.php';

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['success' => true]));
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function logError($message, $context = []) {
    $log_message = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($context)) {
        $log_message .= ' - Context: ' . json_encode($context);
    }
    error_log($log_message);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'mechanic';

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    logError('Database connection failed', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

// Route requests
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost();
        break;
    case 'PUT':
        handlePut();
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

function handleGet() {
    global $db, $user_id, $user_role;
    
    $action = $_GET['action'] ?? 'get_problems';
    
    switch ($action) {
        case 'test':
            jsonResponse([
                'success' => true,
                'message' => 'Problems API is working',
                'user_id' => $user_id,
                'role' => $user_role,
                'timestamp' => date('Y-m-d H:i:s'),
                'features' => ['problem_reporting', 'problem_assignment', 'task_conversion']
            ]);
            break;
            
        case 'get_problems':
            getProblems();
            break;
            
        case 'get_problem':
            getProblem();
            break;
            
        case 'get_stats':
            getProblemStats();
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getProblems() {
    global $db, $user_id, $user_role;
    
    try {
        $status_filter = $_GET['status'] ?? 'all';
        $priority_filter = $_GET['priority'] ?? 'all';
        $category_filter = $_GET['category'] ?? 'all';
        $search = $_GET['search'] ?? '';
        $limit = min(100, (int)($_GET['limit'] ?? 50));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        
        $where_conditions = [];
        $params = [];
        
        // Role-based filtering
        if ($user_role === 'operator') {
            $where_conditions[] = "p.reported_by = ?";
            $params[] = $user_id;
        } elseif ($user_role === 'mechanic') {
            $where_conditions[] = "p.assigned_to = ?";
            $params[] = $user_id;
        }
        
        if ($status_filter !== 'all') {
            $where_conditions[] = "p.status = ?";
            $params[] = $status_filter;
        }
        
        if ($priority_filter !== 'all') {
            $where_conditions[] = "p.priority = ?";
            $params[] = $priority_filter;
        }
        
        if ($category_filter !== 'all') {
            $where_conditions[] = "p.category = ?";
            $params[] = $category_filter;
        }
        
        if (!empty($search)) {
            $where_conditions[] = "(p.title LIKE ? OR p.description LIKE ? OR p.location LIKE ?)";
            $search_param = "%{$search}%";
            $params = array_merge($params, [$search_param, $search_param, $search_param]);
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $sql = "SELECT p.*, 
                       ur.first_name as reported_by_name, ur.last_name as reported_by_lastname,
                       ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
                       ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname,
                       t.title as task_title, t.status as task_status
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
                LIMIT {$limit} OFFSET {$offset}";
        
        $problems = $db->fetchAll($sql, $params);
        
        jsonResponse([
            'success' => true,
            'problems' => $problems,
            'count' => count($problems),
            'filters' => [
                'status' => $status_filter,
                'priority' => $priority_filter,
                'category' => $category_filter,
                'search' => $search
            ]
        ]);
        
    } catch (Exception $e) {
        logError('Get problems failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve problems'], 500);
    }
}

function getProblem() {
    global $db, $user_id, $user_role;
    
    $problem_id = (int)($_GET['id'] ?? 0);
    
    if (!$problem_id) {
        jsonResponse(['success' => false, 'message' => 'Problem ID required'], 400);
    }
    
    try {
        $sql = "SELECT p.*, 
                       ur.first_name as reported_by_name, ur.last_name as reported_by_lastname,
                       ua.first_name as assigned_to_name, ua.last_name as assigned_to_lastname,
                       ub.first_name as assigned_by_name, ub.last_name as assigned_by_lastname,
                       t.title as task_title, t.status as task_status
                FROM problems p 
                LEFT JOIN users ur ON p.reported_by = ur.id 
                LEFT JOIN users ua ON p.assigned_to = ua.id 
                LEFT JOIN users ub ON p.assigned_by = ub.id 
                LEFT JOIN tasks t ON p.task_id = t.id
                WHERE p.id = ?";
        
        $params = [$problem_id];
        
        // Role-based access control
        if ($user_role === 'operator') {
            $sql .= " AND p.reported_by = ?";
            $params[] = $user_id;
        } elseif ($user_role === 'mechanic') {
            $sql .= " AND p.assigned_to = ?";
            $params[] = $user_id;
        }
        
        $problem = $db->fetch($sql, $params);
        
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        // Parse attachments
        if ($problem['attachments']) {
            $problem['attachments'] = json_decode($problem['attachments'], true);
        }
        
        // Get comments
        $comments = $db->fetchAll(
            "SELECT pc.*, u.first_name, u.last_name, u.role
             FROM problem_comments pc
             LEFT JOIN users u ON pc.user_id = u.id
             WHERE pc.problem_id = ?
             ORDER BY pc.created_at ASC",
            [$problem_id]
        );
        
        $problem['comments'] = $comments;
        
        jsonResponse(['success' => true, 'problem' => $problem]);
        
    } catch (Exception $e) {
        logError('Get problem failed', ['error' => $e->getMessage(), 'problem_id' => $problem_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve problem'], 500);
    }
}

function getProblemStats() {
    global $db, $user_id, $user_role;
    
    try {
        $where_clause = "";
        $params = [];
        
        // Role-based filtering
        if ($user_role === 'operator') {
            $where_clause = "WHERE reported_by = ?";
            $params[] = $user_id;
        } elseif ($user_role === 'mechanic') {
            $where_clause = "WHERE assigned_to = ?";
            $params[] = $user_id;
        }
        
        $stats = [
            'total' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause}", $params),
            'reported' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} " . 
                ($where_clause ? "AND" : "WHERE") . " status = 'reported'", $params),
            'assigned' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} " . 
                ($where_clause ? "AND" : "WHERE") . " status = 'assigned'", $params),
            'in_progress' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} " . 
                ($where_clause ? "AND" : "WHERE") . " status = 'in_progress'", $params),
            'resolved' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} " . 
                ($where_clause ? "AND" : "WHERE") . " status = 'resolved'", $params),
            'urgent' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} " . 
                ($where_clause ? "AND" : "WHERE") . " priority = 'urgent'", $params),
            'high' => $db->fetchCount("SELECT COUNT(*) FROM problems p {$where_clause} " . 
                ($where_clause ? "AND" : "WHERE") . " priority = 'high'", $params)
        ];
        
        jsonResponse([
            'success' => true,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        logError('Get problem stats failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve problem statistics'], 500);
    }
}

function handlePost() {
    global $db, $user_id, $user_role;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('Invalid JSON input', ['error' => json_last_error_msg()]);
        jsonResponse(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create_problem':
            createProblem($input);
            break;
            
        case 'assign_problem':
            assignProblem($input);
            break;
            
        case 'update_status':
            updateProblemStatus($input);
            break;
            
        case 'add_comment':
            addProblemComment($input);
            break;
            
        case 'convert_to_task':
            convertToTask($input);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function createProblem($input) {
    global $db, $user_id, $user_role;
    
    // Only operators can create problems
    if ($user_role !== 'operator') {
        jsonResponse(['success' => false, 'message' => 'Only operators can report problems'], 403);
    }
    
    if (empty($input['title'])) {
        jsonResponse(['success' => false, 'message' => 'Title is required'], 400);
    }
    
    try {
        $valid_priorities = ['low', 'medium', 'high', 'urgent'];
        $valid_severities = ['minor', 'moderate', 'major', 'critical'];
        $valid_impacts = ['low', 'medium', 'high', 'critical'];
        $valid_urgencies = ['low', 'medium', 'high', 'urgent'];
        
        $problem_data = [
            'title' => trim($input['title']),
            'description' => trim($input['description'] ?? ''),
            'priority' => in_array($input['priority'] ?? '', $valid_priorities) ? $input['priority'] : 'medium',
            'category' => trim($input['category'] ?? ''),
            'location' => trim($input['location'] ?? ''),
            'equipment' => trim($input['equipment'] ?? ''),
            'reported_by' => $user_id,
            'severity' => in_array($input['severity'] ?? '', $valid_severities) ? $input['severity'] : 'moderate',
            'impact' => in_array($input['impact'] ?? '', $valid_impacts) ? $input['impact'] : 'medium',
            'urgency' => in_array($input['urgency'] ?? '', $valid_urgencies) ? $input['urgency'] : 'medium',
            'estimated_resolution_time' => !empty($input['estimated_resolution_time']) ? 
                (int)$input['estimated_resolution_time'] : null
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
                        [$manager['id'], $new_problem_id, "Problem: '{$problem_data['title']}'"]
                    );
                }
            } catch (Exception $e) {
                logError('Problem notification creation failed', ['error' => $e->getMessage()]);
            }
            
            logActivity("Problem reported: {$problem_data['title']}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Problem reported successfully',
                'problem_id' => $new_problem_id,
                'problem' => array_merge($problem_data, ['id' => $new_problem_id])
            ]);
            
        } else {
            throw new Exception('Failed to get problem ID after insert');
        }
        
    } catch (Exception $e) {
        logError('Problem creation failed', [
            'error' => $e->getMessage(),
            'input' => $input,
            'user_id' => $user_id
        ]);
        
        jsonResponse([
            'success' => false,
            'message' => 'Failed to create problem: ' . $e->getMessage()
        ], 500);
    }
}

function assignProblem($input) {
    global $db, $user_id, $user_role;
    
    // Only managers and admins can assign problems
    if (!in_array($user_role, ['manager', 'admin'])) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $problem_id = (int)($input['problem_id'] ?? 0);
    $assigned_to = (int)($input['assigned_to'] ?? 0);
    
    if (!$problem_id || !$assigned_to) {
        jsonResponse(['success' => false, 'message' => 'Problem ID and assigned user are required'], 400);
    }
    
    try {
        // Validate problem exists
        $problem = $db->fetch("SELECT * FROM problems WHERE id = ?", [$problem_id]);
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        // Validate assigned user is a mechanic
        $mechanic = $db->fetch(
            "SELECT id, first_name, last_name FROM users WHERE id = ? AND role = 'mechanic' AND is_active = 1",
            [$assigned_to]
        );
        if (!$mechanic) {
            jsonResponse(['success' => false, 'message' => 'Invalid mechanic selected'], 400);
        }
        
        // Update problem
        $result = $db->query(
            "UPDATE problems SET assigned_to = ?, assigned_by = ?, status = 'assigned' WHERE id = ?",
            [$assigned_to, $user_id, $problem_id]
        );
        
        if ($result->rowCount() > 0) {
            // Create notification for assigned mechanic
            try {
                $db->query(
                    "INSERT INTO notifications (user_id, problem_id, type, title, message) 
                     VALUES (?, ?, 'problem_assigned', 'Problem Assigned', ?)",
                    [$assigned_to, $problem_id, "Problem assigned: '{$problem['title']}'"]
                );
            } catch (Exception $e) {
                logError('Problem assignment notification failed', ['error' => $e->getMessage()]);
            }
            
            logActivity("Problem assigned: {$problem['title']} to {$mechanic['first_name']} {$mechanic['last_name']}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Problem assigned successfully',
                'problem_id' => $problem_id,
                'assigned_to' => $assigned_to
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'No changes made'], 400);
        }
        
    } catch (Exception $e) {
        logError('Problem assignment failed', ['error' => $e->getMessage(), 'problem_id' => $problem_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to assign problem'], 500);
    }
}

function updateProblemStatus($input) {
    global $db, $user_id, $user_role;
    
    $problem_id = (int)($input['problem_id'] ?? 0);
    $new_status = trim($input['status'] ?? '');
    
    if (!$problem_id || !$new_status) {
        jsonResponse(['success' => false, 'message' => 'Problem ID and status are required'], 400);
    }
    
    $valid_statuses = ['reported', 'assigned', 'in_progress', 'resolved', 'closed'];
    if (!in_array($new_status, $valid_statuses)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
    }
    
    try {
        $problem = $db->fetch("SELECT * FROM problems WHERE id = ?", [$problem_id]);
        
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        // Check permissions
        if ($user_role === 'mechanic' && $problem['assigned_to'] != $user_id) {
            jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
        }
        if ($user_role === 'operator' && $problem['reported_by'] != $user_id) {
            jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
        }
        
        // Prepare update fields
        $update_fields = ['status = ?'];
        $update_params = [$new_status];
        
        if ($new_status === 'resolved') {
            $update_fields[] = 'resolved_at = NOW()';
        }
        
        $update_sql = "UPDATE problems SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $update_params[] = $problem_id;
        
        $result = $db->query($update_sql, $update_params);
        
        if ($result->rowCount() > 0) {
            // Create notifications
            try {
                $notify_users = [];
                
                // Notify reporter if not the one updating
                if ($problem['reported_by'] != $user_id) {
                    $notify_users[] = $problem['reported_by'];
                }
                
                // Notify assigned mechanic if not the one updating
                if ($problem['assigned_to'] && $problem['assigned_to'] != $user_id) {
                    $notify_users[] = $problem['assigned_to'];
                }
                
                // Notify managers
                if ($user_role !== 'manager' && $user_role !== 'admin') {
                    $managers = $db->fetchAll("SELECT id FROM users WHERE role IN ('manager', 'admin') AND is_active = 1");
                    foreach ($managers as $manager) {
                        $notify_users[] = $manager['id'];
                    }
                }
                
                foreach (array_unique($notify_users) as $notify_user_id) {
                    $db->query(
                        "INSERT INTO notifications (user_id, problem_id, type, title, message) 
                         VALUES (?, ?, 'problem_updated', 'Problem Status Updated', ?)",
                        [$notify_user_id, $problem_id, "Problem '{$problem['title']}' status changed to {$new_status}"]
                    );
                }
            } catch (Exception $e) {
                logError('Problem status update notification failed', ['error' => $e->getMessage()]);
            }
            
            logActivity("Problem status updated: {$problem['title']} -> {$new_status}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Problem status updated successfully',
                'problem_id' => $problem_id,
                'new_status' => $new_status
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'No changes made'], 400);
        }
        
    } catch (Exception $e) {
        logError('Problem status update failed', ['error' => $e->getMessage(), 'problem_id' => $problem_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to update status'], 500);
    }
}

function addProblemComment($input) {
    global $db, $user_id;
    
    $problem_id = (int)($input['problem_id'] ?? 0);
    $comment = trim($input['comment'] ?? '');
    
    if (!$problem_id || !$comment) {
        jsonResponse(['success' => false, 'message' => 'Problem ID and comment are required'], 400);
    }
    
    try {
        $problem = $db->fetch("SELECT * FROM problems WHERE id = ?", [$problem_id]);
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        $db->query(
            "INSERT INTO problem_comments (problem_id, user_id, comment) VALUES (?, ?, ?)",
            [$problem_id, $user_id, $comment]
        );
        
        jsonResponse([
            'success' => true,
            'message' => 'Comment added successfully'
        ]);
        
    } catch (Exception $e) {
        logError('Add problem comment failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to add comment'], 500);
    }
}

function convertToTask($input) {
    global $db, $user_id, $user_role;
    
    // Only managers and admins can convert problems to tasks
    if (!in_array($user_role, ['manager', 'admin'])) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $problem_id = (int)($input['problem_id'] ?? 0);
    
    if (!$problem_id) {
        jsonResponse(['success' => false, 'message' => 'Problem ID is required'], 400);
    }
    
    try {
        $problem = $db->fetch("SELECT * FROM problems WHERE id = ?", [$problem_id]);
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        if ($problem['task_id']) {
            jsonResponse(['success' => false, 'message' => 'Problem already has an associated task'], 400);
        }
        
        // Create task from problem
        $task_data = [
            'title' => "Fix: " . $problem['title'],
            'description' => $problem['description'],
            'priority' => $problem['priority'],
            'status' => 'pending',
            'assigned_to' => $problem['assigned_to'] ?: (int)($input['assigned_to'] ?? 0),
            'assigned_by' => $user_id,
            'category' => $problem['category'],
            'location' => $problem['location'],
            'equipment' => $problem['equipment'],
            'estimated_hours' => $problem['estimated_resolution_time'],
            'notes' => "Created from Problem #" . $problem_id . "\nSeverity: " . ucfirst($problem['severity']) . 
                      "\nImpact: " . ucfirst($problem['impact']),
            'progress_percentage' => 0
        ];
        
        if (!$task_data['assigned_to']) {
            jsonResponse(['success' => false, 'message' => 'Assigned mechanic is required'], 400);
        }
        
        // Validate assigned user
        $mechanic = $db->fetch(
            "SELECT id FROM users WHERE id = ? AND role = 'mechanic' AND is_active = 1",
            [$task_data['assigned_to']]
        );
        if (!$mechanic) {
            jsonResponse(['success' => false, 'message' => 'Invalid mechanic selected'], 400);
        }
        
        $fields = array_keys($task_data);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($task_data);
        
        $sql = "INSERT INTO tasks (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $db->query($sql, $values);
        $new_task_id = $db->getConnection()->lastInsertId();
        
        if ($new_task_id) {
            // Update problem with task ID and status
            $db->query(
                "UPDATE problems SET task_id = ?, status = 'assigned' WHERE id = ?",
                [$new_task_id, $problem_id]
            );
            
            // Create notifications
            try {
                // Notify assigned mechanic
                $db->query(
                    "INSERT INTO notifications (user_id, task_id, type, title, message) 
                     VALUES (?, ?, 'task_assigned', 'Task Created from Problem', ?)",
                    [$task_data['assigned_to'], $new_task_id, "Task created from problem: '{$problem['title']}'"]
                );
                
                // Notify problem reporter
                if ($problem['reported_by'] != $user_id) {
                    $db->query(
                        "INSERT INTO notifications (user_id, problem_id, type, title, message) 
                         VALUES (?, ?, 'problem_updated', 'Problem Converted to Task', ?)",
                        [$problem['reported_by'], $problem_id, "Your problem '{$problem['title']}' has been converted to a maintenance task"]
                    );
                }
            } catch (Exception $e) {
                logError('Task conversion notification failed', ['error' => $e->getMessage()]);
            }
            
            logActivity("Problem converted to task: {$problem['title']}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Problem converted to task successfully',
                'task_id' => $new_task_id,
                'problem_id' => $problem_id
            ]);
            
        } else {
            throw new Exception('Failed to create task');
        }
        
    } catch (Exception $e) {
        logError('Problem to task conversion failed', ['error' => $e->getMessage(), 'problem_id' => $problem_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to convert problem to task'], 500);
    }
}

function handlePut() {
    global $db, $user_id, $user_role;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }
    
    $action = $input['action'] ?? 'update_problem';
    
    switch ($action) {
        case 'update_problem':
            updateProblem($input);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function updateProblem($input) {
    global $db, $user_id, $user_role;
    
    $problem_id = (int)($input['problem_id'] ?? 0);
    
    if (!$problem_id) {
        jsonResponse(['success' => false, 'message' => 'Problem ID required'], 400);
    }
    
    try {
        $problem = $db->fetch("SELECT * FROM problems WHERE id = ?", [$problem_id]);
        
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        // Check permissions
        if ($user_role === 'operator' && $problem['reported_by'] != $user_id) {
            jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
        }
        if ($user_role === 'mechanic' && $problem['assigned_to'] != $user_id) {
            jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
        }
        
        // Build update fields
        $update_fields = [];
        $update_params = [];
        
        $updatable_fields = ['title', 'description', 'priority', 'category', 'location', 'equipment', 'severity', 'impact', 'urgency'];
        
        foreach ($updatable_fields as $field) {
            if (isset($input[$field])) {
                $update_fields[] = "{$field} = ?";
                $update_params[] = $input[$field];
            }
        }
        
        if (empty($update_fields)) {
            jsonResponse(['success' => false, 'message' => 'No fields to update'], 400);
        }
        
        $update_sql = "UPDATE problems SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $update_params[] = $problem_id;
        
        $result = $db->query($update_sql, $update_params);
        
        if ($result->rowCount() > 0) {
            logActivity("Problem updated: {$problem['title']}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Problem updated successfully',
                'problem_id' => $problem_id
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'No changes made'], 400);
        }
        
    } catch (Exception $e) {
        logError('Problem update failed', ['error' => $e->getMessage(), 'problem_id' => $problem_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to update problem'], 500);
    }
}

function handleDelete() {
    global $db, $user_id, $user_role;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }
    
    deleteProblem($input);
}

function deleteProblem($input) {
    global $db, $user_id, $user_role;
    
    $problem_id = (int)($input['problem_id'] ?? 0);
    
    if (!$problem_id) {
        jsonResponse(['success' => false, 'message' => 'Problem ID required'], 400);
    }
    
    // Only managers and admins can delete problems
    if (!in_array($user_role, ['admin', 'manager'])) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    try {
        $problem = $db->fetch("SELECT title FROM problems WHERE id = ?", [$problem_id]);
        
        if (!$problem) {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
        // Delete problem (comments and notifications will be deleted automatically due to foreign key constraints)
        $result = $db->query("DELETE FROM problems WHERE id = ?", [$problem_id]);
        
        if ($result->rowCount() > 0) {
            logActivity("Problem deleted: {$problem['title']}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Problem deleted successfully',
                'problem_id' => $problem_id
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Problem not found'], 404);
        }
        
    } catch (Exception $e) {
        logError('Problem deletion failed', ['error' => $e->getMessage(), 'problem_id' => $problem_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to delete problem'], 500);
    }
}
?>