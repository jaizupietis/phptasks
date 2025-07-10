<?php
/**
 * Main Entry Point - Login Page - FIXED VERSION
 * Task Management System
 * Replace: /var/www/tasks/index.php
 */

define('SECURE_ACCESS', true);
require_once 'config/config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            break;
        case 'manager':
            header('Location: manager/dashboard.php');
            break;
        case 'mechanic':
            header('Location: mechanic/dashboard.php');
            break;
        case 'operator':  // FIXED: Added operator role
            header('Location: operator/dashboard.php');
            break;
        default:
            session_destroy();
            break;
    }
    exit;
}

$error_message = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        try {
            $db = Database::getInstance();
            
            // Fetch user from database
            $user = $db->fetch(
                "SELECT * FROM users WHERE username = ? AND is_active = 1",
                [$username]
            );
            
            if ($user && ($password === $user['password'] || password_verify($password, $user['password']))) {
                // Successful login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['login_time'] = time();
                
                // Update last login
                $db->query(
                    "UPDATE users SET last_login = NOW() WHERE id = ?",
                    [$user['id']]
                );
                
                logActivity("User {$username} logged in successfully", 'INFO', $user['id']);
                
                // FIXED: Redirect based on role including operator
                switch ($user['role']) {
                    case 'admin':
                        header('Location: admin/dashboard.php');
                        break;
                    case 'manager':
                        header('Location: manager/dashboard.php');
                        break;
                    case 'mechanic':
                        header('Location: mechanic/dashboard.php');
                        break;
                    case 'operator':
                        header('Location: operator/dashboard.php');
                        break;
                    default:
                        $error_message = 'Invalid user role: ' . $user['role'];
                        logActivity("Invalid role for user: {$username} - {$user['role']}", 'ERROR');
                        session_destroy();
                        break;
                }
                exit;
            } else {
                $error_message = 'Invalid username or password.';
                logActivity("Failed login attempt for username: {$username}", 'WARNING');
            }
        } catch (Exception $e) {
            logActivity("Login error: " . $e->getMessage(), 'ERROR');
            $error_message = 'Login system temporarily unavailable. Please try again later.';
        }
    }
}

$is_mobile = isMobile();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Login</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
            margin: 1rem;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-icon {
            font-size: 3rem;
            color: #007bff;
            margin-bottom: 1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
        }
        .form-control {
            border-radius: 10px;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
        }
        .form-control:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        .demo-accounts {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e9ecef;
        }
        .demo-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 0.5rem;
        }
        .demo-card:hover {
            background: #007bff;
            color: white;
            transform: translateY(-2px);
        }
        <?php if ($is_mobile): ?>
        .login-container {
            margin: 0.5rem;
            padding: 1.5rem;
        }
        .form-control {
            font-size: 16px; /* Prevents zoom on iOS */
        }
        <?php endif; ?>
    </style>
</head>
<body class="<?php echo $is_mobile ? 'mobile-device' : ''; ?>">
    <div class="login-container">
        <div class="login-header">
            <div class="login-icon">
                <i class="fas fa-tools"></i>
            </div>
            <h2><?php echo APP_NAME; ?></h2>
            <p class="text-muted">Streamlined Task Management</p>
        </div>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label">
                    <i class="fas fa-user"></i> Username
                </label>
                <input type="text" class="form-control" id="username" name="username" 
                       placeholder="Enter your username" required autofocus>
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">
                    <i class="fas fa-lock"></i> Password
                </label>
                <input type="password" class="form-control" id="password" name="password" 
                       placeholder="Enter your password" required>
            </div>
            
            <button type="submit" name="login" class="btn btn-primary w-100">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
        
        <div class="demo-accounts">
            <h6 class="text-muted mb-3">Demo Accounts</h6>
            <div class="demo-card" onclick="fillCredentials('admin', 'admin123')">
                <i class="fas fa-user-shield text-danger"></i>
                <small>Administrator</small>
            </div>
            <div class="demo-card" onclick="fillCredentials('manager1', 'manager123')">
                <i class="fas fa-users-cog text-primary"></i>
                <small>Manager</small>
            </div>
            <div class="demo-card" onclick="fillCredentials('mechanic1', 'mechanic123')">
                <i class="fas fa-tools text-success"></i>
                <small>Mechanic</small>
            </div>
            <div class="demo-card" onclick="fillCredentials('operator1', 'operator123')">
                <i class="fas fa-exclamation-triangle text-warning"></i>
                <small>Operator</small>
            </div>
        </div>
    </div>
    
    <script>
    function fillCredentials(username, password) {
        document.getElementById('username').value = username;
        document.getElementById('password').value = password;
    }
    
    // Auto-dismiss alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (alert.classList.contains('alert-dismissible')) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        });
    }, 5000);
    </script>
</body>
</html>