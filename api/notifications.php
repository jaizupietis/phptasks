<?php
/**
 * Notifications API
 * Create this as: /var/www/tasks/api/notifications.php
 */

// Set timezone for Latvia
date_default_timezone_set('Europe/Riga');

// Security check
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// Include config
require_once dirname(__DIR__) . '/config/config.php';

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['success' => true]));
}

// JSON response helper
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Log function
function logError($message, $context = []) {
    $log_message = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($context)) {
        $log_message .= ' - Context: ' . json_encode($context);
    }
    error_log($log_message);
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'mechanic';

// Initialize database
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    logError('Database connection failed', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

// Route requests
try {
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
} catch (Exception $e) {
    logError('Request handling failed', ['error' => $e->getMessage(), 'method' => $_SERVER['REQUEST_METHOD']]);
    jsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
}

function handleGet() {
    global $db, $user_id, $user_role;
    
    $action = $_GET['action'] ?? 'get_notifications';
    
    switch ($action) {
        case 'get_notifications':
            getNotifications();
            break;
            
        case 'get_unread_count':
            getUnreadCount();
            break;
            
        case 'get_all':
            getAllNotifications();
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
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
        case 'create_notification':
            createNotification($input);
            break;
            
        case 'mark_read':
            markAsRead($input);
            break;
            
        case 'mark_all_read':
            markAllAsRead();
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePut() {
    global $db, $user_id, $user_role;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }
    
    $action = $input['action'] ?? 'update_notification';
    
    switch ($action) {
        case 'update_notification':
            updateNotification($input);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handleDelete() {
    global $db, $user_id, $user_role;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }
    
    deleteNotification($input);
}

function getNotifications() {
    global $db, $user_id;
    
    try {
        $limit = min(50, (int)($_GET['limit'] ?? 20));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $include_read = isset($_GET['include_read']) ? (bool)$_GET['include_read'] : false;
        
        $where_clause = "n.user_id = ?";
        $params = [$user_id];
        
        if (!$include_read) {
            $where_clause .= " AND n.is_read = 0";
        }
        
        $sql = "SELECT n.*, 
                       t.title as task_title,
                       t.status as task_status,
                       u.first_name as from_user_name,
                       u.last_name as from_user_lastname
                FROM notifications n
                LEFT JOIN tasks t ON n.task_id = t.id
                LEFT JOIN users u ON t.assigned_by = u.id
                WHERE {$where_clause}
                ORDER BY n.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
        
        $notifications = $db->fetchAll($sql, $params);
        
        // Get total count
        $total_count = $db->fetchCount(
            "SELECT COUNT(*) FROM notifications n WHERE {$where_clause}",
            $params
        );
        
        jsonResponse([
            'success' => true,
            'notifications' => $notifications,
            'total_count' => $total_count,
            'has_more' => ($offset + $limit) < $total_count
        ]);
        
    } catch (Exception $e) {
        logError('Get notifications failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve notifications'], 500);
    }
}

function getUnreadCount() {
    global $db, $user_id;
    
    try {
        $check_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $user_id;
        
        // Security check - only allow users to check their own notifications
        if ($check_user_id !== $user_id && $_SESSION['role'] !== 'admin') {
            jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $count = $db->fetchCount(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
            [$check_user_id]
        );
        
        jsonResponse([
            'success' => true,
            'count' => $count,
            'user_id' => $check_user_id
        ]);
        
    } catch (Exception $e) {
        logError('Get unread count failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to get unread count'], 500);
    }
}

function getAllNotifications() {
    global $db, $user_id;
    
    try {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $notifications = $db->fetchAll(
            "SELECT n.*, 
                    t.title as task_title,
                    t.status as task_status,
                    u.first_name as from_user_name,
                    u.last_name as from_user_lastname
             FROM notifications n
             LEFT JOIN tasks t ON n.task_id = t.id
             LEFT JOIN users u ON t.assigned_by = u.id
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}",
            [$user_id]
        );
        
        $total_count = $db->fetchCount(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ?",
            [$user_id]
        );
        
        jsonResponse([
            'success' => true,
            'notifications' => $notifications,
            'total_count' => $total_count,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total_count / $per_page)
        ]);
        
    } catch (Exception $e) {
        logError('Get all notifications failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to retrieve notifications'], 500);
    }
}

function createNotification($input) {
    global $db, $user_id, $user_role;
    
    // Only managers and admins can create notifications for others
    if (!in_array($user_role, ['admin', 'manager'])) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    // Validate required fields
    if (empty($input['user_id']) || empty($input['title']) || empty($input['message'])) {
        jsonResponse(['success' => false, 'message' => 'User ID, title, and message are required'], 400);
    }
    
    try {
        $notification_data = [
            'user_id' => (int)$input['user_id'],
            'task_id' => isset($input['task_id']) ? (int)$input['task_id'] : null,
            'type' => $input['type'] ?? 'task_updated',
            'title' => trim($input['title']),
            'message' => trim($input['message']),
            'is_read' => 0
        ];
        
        // Validate user exists
        $user_exists = $db->fetch("SELECT id FROM users WHERE id = ? AND is_active = 1", [$notification_data['user_id']]);
        if (!$user_exists) {
            jsonResponse(['success' => false, 'message' => 'Invalid user ID'], 400);
        }
        
        // Validate task exists if provided
        if ($notification_data['task_id']) {
            $task_exists = $db->fetch("SELECT id FROM tasks WHERE id = ?", [$notification_data['task_id']]);
            if (!$task_exists) {
                jsonResponse(['success' => false, 'message' => 'Invalid task ID'], 400);
            }
        }
        
        // Insert notification
        $fields = array_keys($notification_data);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = "INSERT INTO notifications (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $db->query($sql, array_values($notification_data));
        $notification_id = $db->getConnection()->lastInsertId();
        
        if ($notification_id) {
            jsonResponse([
                'success' => true,
                'message' => 'Notification created successfully',
                'notification_id' => $notification_id
            ]);
        } else {
            throw new Exception('Failed to create notification');
        }
        
    } catch (Exception $e) {
        logError('Create notification failed', ['error' => $e->getMessage(), 'input' => $input]);
        jsonResponse(['success' => false, 'message' => 'Failed to create notification'], 500);
    }
}

function markAsRead($input) {
    global $db, $user_id;
    
    $notification_id = (int)($input['notification_id'] ?? 0);
    
    if (!$notification_id) {
        jsonResponse(['success' => false, 'message' => 'Notification ID required'], 400);
    }
    
    try {
        // Verify notification belongs to user
        $notification = $db->fetch(
            "SELECT id FROM notifications WHERE id = ? AND user_id = ?",
            [$notification_id, $user_id]
        );
        
        if (!$notification) {
            jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
        }
        
        // Mark as read
        $result = $db->query(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
            [$notification_id, $user_id]
        );
        
        if ($result->rowCount() > 0) {
            jsonResponse([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'No changes made'], 400);
        }
        
    } catch (Exception $e) {
        logError('Mark notification as read failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to mark notification as read'], 500);
    }
}

function markAllAsRead() {
    global $db, $user_id;
    
    try {
        $result = $db->query(
            "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
            [$user_id]
        );
        
        $count = $result->rowCount();
        
        jsonResponse([
            'success' => true,
            'message' => "Marked {$count} notifications as read",
            'count' => $count
        ]);
        
    } catch (Exception $e) {
        logError('Mark all notifications as read failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to mark all notifications as read'], 500);
    }
}

function updateNotification($input) {
    global $db, $user_id;
    
    $notification_id = (int)($input['notification_id'] ?? 0);
    
    if (!$notification_id) {
        jsonResponse(['success' => false, 'message' => 'Notification ID required'], 400);
    }
    
    try {
        // Verify notification belongs to user
        $notification = $db->fetch(
            "SELECT id FROM notifications WHERE id = ? AND user_id = ?",
            [$notification_id, $user_id]
        );
        
        if (!$notification) {
            jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
        }
        
        // Build update fields
        $update_fields = [];
        $update_params = [];
        
        if (isset($input['is_read'])) {
            $update_fields[] = 'is_read = ?';
            $update_params[] = (int)$input['is_read'];
        }
        
        if (empty($update_fields)) {
            jsonResponse(['success' => false, 'message' => 'No fields to update'], 400);
        }
        
        $update_sql = "UPDATE notifications SET " . implode(', ', $update_fields) . " WHERE id = ? AND user_id = ?";
        $update_params[] = $notification_id;
        $update_params[] = $user_id;
        
        $result = $db->query($update_sql, $update_params);
        
        if ($result->rowCount() > 0) {
            jsonResponse([
                'success' => true,
                'message' => 'Notification updated successfully'
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'No changes made'], 400);
        }
        
    } catch (Exception $e) {
        logError('Update notification failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to update notification'], 500);
    }
}

function deleteNotification($input) {
    global $db, $user_id;
    
    $notification_id = (int)($input['notification_id'] ?? 0);
    
    if (!$notification_id) {
        jsonResponse(['success' => false, 'message' => 'Notification ID required'], 400);
    }
    
    try {
        // Verify notification belongs to user
        $notification = $db->fetch(
            "SELECT id FROM notifications WHERE id = ? AND user_id = ?",
            [$notification_id, $user_id]
        );
        
        if (!$notification) {
            jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
        }
        
        // Delete notification
        $result = $db->query(
            "DELETE FROM notifications WHERE id = ? AND user_id = ?",
            [$notification_id, $user_id]
        );
        
        if ($result->rowCount() > 0) {
            jsonResponse([
                'success' => true,
                'message' => 'Notification deleted successfully'
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
        }
        
    } catch (Exception $e) {
        logError('Delete notification failed', ['error' => $e->getMessage()]);
        jsonResponse(['success' => false, 'message' => 'Failed to delete notification'], 500);
    }
}
?>
