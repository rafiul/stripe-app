<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

// Check if connected
if (!has_quickbooks_account($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// Process disconnect
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: " . BASE_URL . "/dashboard.php");
        exit();
    }
    
    // Delete from database
    $stmt = $db->prepare("DELETE FROM quickbooks_accounts WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "QuickBooks account disconnected successfully!";
    } else {
        $_SESSION['error'] = "Failed to disconnect QuickBooks account";
    }
    
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm mt-5">
                <div class="card-body">
                    <h2 class="card-title text-center mb-4">Disconnect QuickBooks</h2>
                    
                    <div class="alert alert-warning">
                        <p>Are you sure you want to disconnect your QuickBooks account?</p>
                        <p>This will remove the connection from our system.</p>
                        <p class="mb-0"><strong>Note:</strong> To fully revoke access, you'll need to manage the connection in your QuickBooks account settings.</p>
                    </div>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/quickbooks-disconnect.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger">Disconnect QuickBooks</button>
                            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>