<?php
// Attempt to complete setup
$success = false;
if (!isset($_SESSION['setup_complete'])) {
    $success = complete_setup();
    $_SESSION['setup_complete'] = $success;
} else {
    $success = $_SESSION['setup_complete'];
}
?>

<?php if ($success): ?>
    <div class="text-center">
        <div class="mb-4">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
        </div>
        <h2 class="card-title mb-3">Setup Complete!</h2>
        <p class="mb-4">Your Stripe to QuickBooks integration has been successfully configured.</p>
        
        <div class="alert alert-info text-start">
            <h5 class="alert-heading">Important Information</h5>
            <ul class="mb-0">
                <li>Your admin username: <strong><?php echo htmlspecialchars($_SESSION['admin_account']['username']); ?></strong></li>
                <li>Configuration file has been created at <code>config.php</code></li>
                <li>Database tables have been created</li>
                <li>Admin user (ID 1) has been created</li>
            </ul>
        </div>
        
        <div class="d-grid gap-2 mt-4">
            <a href="login.php" class="btn btn-success btn-lg">Login to Your Account</a>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-danger">
        <h4 class="alert-heading">Setup Failed</h4>
        <p>There was an error completing the setup process.</p>
        <hr>
        <p class="mb-0">Please check the error message above and try again. If the problem persists, you may need to manually configure the application.</p>
    </div>
    
    <div class="d-flex justify-content-between mt-4">
        <a href="onboarding.php?step=quickbooks" class="btn btn-secondary">Back</a>
        <button type="button" class="btn btn-primary" onclick="window.location.reload()">Try Again</button>
    </div>
<?php endif; ?>