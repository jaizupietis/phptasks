<?php
date_default_timezone_set('Europe/Riga');

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/FileUploader.php';

// Set JSON headers unless handling file upload
if (!isset($_FILES) || empty($_FILES)) {
    header('Content-Type: application/json; charset=utf-8');
}
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

// Handle file upload for tasks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attachment'])) {
    handleFileUpload();
}

// Handle regular API requests
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

function handleFileUpload() {
    global $db, $user_id;
    
    $taskId = $_POST['task_id'] ?? 0;
    
    if (!$taskId) {
        jsonResponse(['success' => false, 'message' => 'Task ID required'], 400);
    }
    
    // Verify task exists and user has access
    $task = $db->fetch("SELECT * FROM tasks WHERE id = ?", [$taskId]);
    if (!$task || ($task['assigned_to'] != $user_id && !in_array($_SESSION['role'], ['admin', 'manager']))) {
        jsonResponse(['success' => false, 'message' => 'Task not found or access denied'], 403);
    }
    
    $uploader = new FileUploader();
    $result = $uploader->uploadTaskAttachment($taskId, $_FILES['attachment']);
    
    if ($result['success']) {
        // Save attachment info to database
        $attachmentData = [
            'filename' => $result['filename'],
            'original_name' => $result['original_name'],
            'size' => $result['size'],
            'uploaded_by' => $user_id,
            'uploaded_at' => date('Y-m-d H:i:s')
        ];
        
        // Update task attachments
        $currentAttachments = $task['attachments'] ? json_decode($task['attachments'], true) : [];
        $currentAttachments[] = $attachmentData;
        
        $db->query(
            "UPDATE tasks SET attachments = ? WHERE id = ?",
            [json_encode($currentAttachments), $taskId]
        );
        
        jsonResponse([
            'success' => true,
            'message' => 'File uploaded successfully',
            'attachment' => $attachmentData
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => $result['error']], 400);
    }
}

function handleGet() {
    global $db, $user_id, $user_role;
    
    $action = $_GET['action'] ?? 'get_tasks';
    
    switch ($action) {
        case 'test':
            jsonResponse([
                'success' => true,
                'message' => 'Enhanced API is working',
                'user_id' => $user_id,
                'role' => $user_role,
                'timestamp' => date('Y-m-d H:i:s'),
                'features' => ['file_upload', 'enhanced_search', 'categories']
            ]);
            break;
            
        case 'get_tasks':
            getTasks();
            break;
            
        case 'get_task':
            getTask();
            break;
            
        case 'get_categories':
            getCategories();
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getTasks() {
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
        
        if ($user_role === 'mechanic') {
            $where_conditions[] = "t.assigned_to = ?";
            $params[] = $user_id;
        }
        
        if ($status_filter !== 'all') {
            $where_conditions[] = "t.status = ?";
            $params[] = $status_filter;
        }
        
        if ($priority_filter !== 'all') {
            $where_conditions[] = "t.priority = ?";
            $params[] = $priority_filter;
        }
        
        if ($category_filter !== 'all') {
            $where_conditions[] = "t.category = ?";
            $params[] = $category_filter;
        }
        
        if (!empty($search)) {
            $where_conditions[] = "(t.title LIKE ? OR t.description LIKE ? OR t.location LIKE ?)";
            $search_param = "%{$search}%";
            $params = array_merge($params, [$search_param, $search_param, $search_param]);
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $sql = "SELECT t.*, 
                       ua.first_name as assigned_to_name, 
                       ua.last_name as assigned_to_lastname,
                       ub.first_name as assigned_by_name, 
                       ub.last_name as assigned_by_lastname,
                       c.color as category_color
                FROM tasks t 
                LEFT JOIN users ua ON t.assigned_to = ua.id 
                LEFT JOIN users ub ON t.assigned_by = ub.id 
                LEFT JOIN categories c ON t.category = c.name
                {$where_clause}
                ORDER BY 
                    CASE 
                        WHEN t.status = 'in_progress' THEN 1 
                        WHEN t.priority = 'urgent' THEN 2 
                        WHEN t.priority = 'high' THEN 3 
                        ELSE 4 
                    END,
                    t.due_date ASC,
                    t.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
        
        $tasks = $db->fetchAll($sql, $params);
        
        jsonResponse([
            'success' => true,
            'tasks' => $tasks,
            'count' => count($tasks),
            'filters' => [
                'status' => $status_filter,
                'priority' => $priority_filter,
                'category' => $category_filter,
                'search' => $search
            ]
        ]);
        
    } catch (Exception $e) {
        logError('Get tasks failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve tasks'], 500);
    }
}

function getCategories() {
    global $db;
    
    try {
        $categories = $db->fetchAll(
            "SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, name"
        );
        
        jsonResponse([
            'success' => true,
            'categories' => $categories
        ]);
        
    } catch (Exception $e) {
        logError('Get categories failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve categories'], 500);
    }
}

// Include all other functions from original tasks.php
function getTask() {
    global $db, $user_id, $user_role;
    
    $task_id = (int)($_GET['id'] ?? 0);
    
    if (!$task_id) {
        jsonResponse(['success' => false, 'message' => 'Task ID required'], 400);
    }
    
    try {
        $sql = "SELECT t.*, 
                       ua.first_name as assigned_to_name, 
                       ua.last_name as assigned_to_lastname,
                       ub.first_name as assigned_by_name, 
                       ub.last_name as assigned_by_lastname
                FROM tasks t 
                LEFT JOIN users ua ON t.assigned_to = ua.id 
                LEFT JOIN users ub ON t.assigned_by = ub.id 
                WHERE t.id = ?";
        
        $params = [$task_id];
        
        if ($user_role === 'mechanic') {
            $sql .= " AND t.assigned_to = ?";
            $params[] = $user_id;
        }
        
        $task = $db->fetch($sql, $params);
        
        if (!$task) {
            jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
        }
        
        // Parse attachments
        if ($task['attachments']) {
            $task['attachments'] = json_decode($task['attachments'], true);
        }
        
        jsonResponse(['success' => true, 'task' => $task]);
        
    } catch (Exception $e) {
        logError('Get task failed', ['error' => $e->getMessage(), 'task_id' => $task_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve task'], 500);
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
        case 'create_task':
            createTask($input);
            break;
            
        case 'update_status':
            updateTaskStatus($input);
            break;
            
        case 'add_comment':
            addTaskComment($input);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function addTaskComment($input) {
    global $db, $user_id;
    
    $task_id = (int)($input['task_id'] ?? 0);
    $comment = trim($input['comment'] ?? '');
    
    if (!$task_id || !$comment) {
        jsonResponse(['success' => false, 'message' => 'Task ID and comment are required'], 400);
    }
    
    try {
        $task = $db->fetch("SELECT * FROM tasks WHERE id = ?", [$task_id]);
        if (!$task) {
            jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
        }
        
        $db->query(
            "INSERT INTO task_comments (task_id, user_id, comment) VALUES (?, ?, ?)",
            [$task_id, $user_id, $comment]
        );
        
        jsonResponse([
            'success' => true,
            'message' => 'Comment added successfully'
        ]);
        
    } catch (Exception $e) {
        logError('Add comment failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to add comment'], 500);
    }
}

// Copy remaining functions from original tasks.php here...
function createTask($input) {
    global $db, $user_id, $user_role;
    
    if (!in_array($user_role, ['admin', 'manager'])) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    if (empty($input['title']) || empty($input['assigned_to'])) {
        jsonResponse(['success' => false, 'message' => 'Title and assigned user are required'], 400);
    }
    
    try {
        $assigned_user = $db->fetch(
            "SELECT id, first_name, last_name, email FROM users WHERE id = ? AND role = 'mechanic' AND is_active = 1",
            [(int)$input['assigned_to']]
        );
        
        if (!$assigned_user) {
            jsonResponse(['success' => false, 'message' => 'Invalid assigned user'], 400);
        }
        
        $valid_priorities = ['low', 'medium', 'high', 'urgent'];
        $priority = in_array($input['priority'] ?? '', $valid_priorities) ? $input['priority'] : 'medium';
        
        $task_data = [
            'title' => trim($input['title']),
            'description' => trim($input['description'] ?? ''),
            'priority' => $priority,
            'status' => 'pending',
            'assigned_to' => (int)$input['assigned_to'],
            'assigned_by' => $user_id,
            'category' => trim($input['category'] ?? ''),
            'location' => trim($input['location'] ?? ''),
            'equipment' => trim($input['equipment'] ?? ''),
            'estimated_hours' => !empty($input['estimated_hours']) ? (float)$input['estimated_hours'] : null,
            'due_date' => !empty($input['due_date']) ? date('Y-m-d H:i:s', strtotime($input['due_date'])) : null,
            'start_date' => !empty($input['start_date']) ? date('Y-m-d H:i:s', strtotime($input['start_date'])) : null,
            'notes' => trim($input['notes'] ?? ''),
            'progress_percentage' => 0
        ];
        
        $task_data = array_filter($task_data, function($value) {
            return $value !== null && $value !== '';
        });
        
        $fields = array_keys($task_data);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($task_data);
        
        $sql = "INSERT INTO tasks (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $db->query($sql, $values);
        $new_task_id = $db->getConnection()->lastInsertId();
        
        if ($new_task_id) {
            try {
                $notification_sql = "INSERT INTO notifications (user_id, task_id, type, title, message) 
                                    VALUES (?, ?, 'task_assigned', 'New Task Assigned', ?)";
                $notification_params = [
                    $input['assigned_to'],
                    $new_task_id,
                    "New task: '{$task_data['title']}'"
                ];
                
                $db->query($notification_sql, $notification_params);
                
            } catch (Exception $e) {
                logError('Notification creation failed', ['error' => $e->getMessage()]);
            }
            
            logActivity("Task created: {$task_data['title']}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Task created successfully',
                'task_id' => $new_task_id,
                'task' => array_merge($task_data, ['id' => $new_task_id])
            ]);
            
        } else {
            throw new Exception('Failed to get task ID after insert');
        }
        
    } catch (Exception $e) {
        logError('Task creation failed', [
            'error' => $e->getMessage(),
            'input' => $input,
            'user_id' => $user_id
        ]);
        
        jsonResponse([
            'success' => false,
            'message' => 'Failed to create task: ' . $e->getMessage()
        ], 500);
    }
}

function updateTaskStatus($input) {
    global $db, $user_id, $user_role;
    
    $task_id = (int)($input['task_id'] ?? 0);
    $new_status = trim($input['status'] ?? '');
    
    if (!$task_id || !$new_status) {
        jsonResponse(['success' => false, 'message' => 'Task ID and status are required'], 400);
    }
    
    $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled', 'on_hold'];
    if (!in_array($new_status, $valid_statuses)) {
        jsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
    }
    
    try {
        $task = $db->fetch("SELECT * FROM tasks WHERE id = ?", [$task_id]);
        
        if (!$task) {
            jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
        }
        
        if ($user_role === 'mechanic' && $task['assigned_to'] != $user_id) {
            jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
        }
        
        $update_fields = ['status = ?'];
        $update_params = [$new_status];
        
        if ($new_status === 'completed') {
            $update_fields[] = 'completed_date = NOW()';
            $update_fields[] = 'progress_percentage = 100';
        } elseif ($new_status === 'in_progress' && $task['status'] === 'pending') {
            $update_fields[] = 'start_date = NOW()';
        }
        
        $update_sql = "UPDATE tasks SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $update_params[] = $task_id;
        
        $result = $db->query($update_sql, $update_params);
        
        if ($result->rowCount() > 0) {
            try {
                $notify_user_id = ($user_role === 'mechanic') ? $task['assigned_by'] : $task['assigned_to'];
                if ($notify_user_id && $notify_user_id != $user_id) {
                    $db->query(
                        "INSERT INTO notifications (user_id, task_id, type, title, message) 
                         VALUES (?, ?, 'task_updated', 'Task Status Updated', ?)",
                        [$notify_user_id, $task_id, "Task '{$task['title']}' status changed to {$new_status}"]
                    );
                }
            } catch (Exception $e) {
                logError('Status update notification failed', ['error' => $e->getMessage()]);
            }
            
            logActivity("Task status updated: {$task['title']} -> {$new_status}", 'INFO', $user_id);
            
            jsonResponse([
                'success' => true,
                'message' => 'Task status updated successfully',
                'task_id' => $task_id,
                'new_status' => $new_status
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'No changes made'], 400);
        }
        
    } catch (Exception $e) {
        logError('Status update failed', ['error' => $e->getMessage(), 'task_id' => $task_id]);
        jsonResponse(['success' => false, 'message' => 'Failed to update status'], 500);
    }
}

function handlePut() {
    // Copy from original tasks.php
}

function handleDelete() {
    // Copy from original tasks.php
}
?>
