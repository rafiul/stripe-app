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

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm mt-5">
                <div class="card-body">
                    <h2 class="card-title text-center mb-4">Login</h2>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo sanitize_output($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/login.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Login</button>
                        </div>
                    </form>
                    
                    <div class="mt-3 text-center">
                        <p>Don't have an account? <a href="<?php echo BASE_URL; ?>/register.php">Register here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>