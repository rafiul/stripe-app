<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

// Check if connected
if (!has_stripe_account($_SESSION['user_id'])) {
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
    
    // Get Stripe account info
    $stripe_account = get_stripe_account($_SESSION['user_id']);
    
    if ($stripe_account) {
        // Delete from database
        $stmt = $db->prepare("DELETE FROM stripe_accounts WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Stripe account disconnected successfully!";
        } else {
            $_SESSION['error'] = "Failed to disconnect Stripe account.";
        }
    } else {
        $_SESSION['error'] = "No Stripe account found to disconnect.";
    }
    
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="inner-wrap">
    <div class="row justify-content-center">
        <div class="custom-col-20 d-none d-sm-block">
            <div class="card">
                <div class="card-body sidebar">
                    <?php include __DIR__ . '/includes/nav.php'; ?>
                </div>
            </div>
        </div>
        <div class="col-md-7 col-lg-7">
            <div class="card shadow-sm mt-5">
                <div class="card-body">
                    <h2 class="card-title mb-4"><i class="bi bi-credit-card me-2 me-2"></i> Disconnect Stripe <hr></h2>
                    <div class="alert alert-warning">
                        <p>Are you sure you want to disconnect your Stripe account?</p>
                        <p>This will remove your API key from our system.</p>
                        <p class="mb-0"><strong>Note:</strong> This action won't revoke API access on Stripe's side. You should rotate your API key in the Stripe Dashboard if needed.</p>
                    </div>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/stripe-disconnect.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="btn-group gap-1 w-100">
                            <button type="submit" class="btn btn-danger">Disconnect Stripe</button>
                            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-danger">Cancel</a>
                        </div>
                    </form>
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