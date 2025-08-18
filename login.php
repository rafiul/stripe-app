<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

$error = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error = "Invalid CSRF token";
    } else {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        
        if (empty($username) || empty($password)) {
            $error = "Please fill in all fields";
        } else {
            // Get user from database
            $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user && verify_password($password, $user['password_hash'])) {
                // Login successful - create session
                $session_token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', time() + (86400 * SESSION_EXPIRE_DAYS));
                
                // Store session in database
                $stmt = $db->prepare("INSERT INTO sessions (user_id, session_token, expires_at) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $user['id'], $session_token, $expires_at);
                
                if ($stmt->execute()) {
                    // Set session cookie and variables
                    setcookie('session_token', $session_token, [
                        'expires' => time() + (86400 * SESSION_EXPIRE_DAYS),
                        'path' => '/',
                        'domain' => $_SERVER['HTTP_HOST'],
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ]);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    
                    header("Location: " . BASE_URL . "/dashboard.php");
                    exit();
                } else {
                    $error = "Failed to create session. Please try again.";
                }
            } else {
                $error = "Invalid username or password";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Stripe Integration for QuickBooks</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-left">
            <div class="login-header">
                <img src="<?php echo BASE_URL; ?>/assets/images/Stripe_logo.png" alt="Stripe Logo" class="logo">
                <h2>Welcome back</h2>
                <p>Please enter your details</p>
            </div>

            <?php if ($error): ?>
                <div class="alert"><?php echo sanitize_output($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo BASE_URL; ?>/login.php">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                <div class="form-group">
                    <label>Email address</label>
                    <input type="text" name="username" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>

                <div class="form-options">
                    <label><input type="checkbox" name="remember"> Remember for 30 days</label>
                    <a href="#">Forgot password</a>
                </div>

                <button type="submit" class="btn primary">Sign in</button>
            </form>
        </div>

        <div class="login-right">
            <img src="<?php echo BASE_URL; ?>/assets/images/login-ill.png" alt="Illustration">
        </div>
    </div>
</body>
</html>