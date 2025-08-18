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

// Process verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: " . BASE_URL . "/dashboard.php");
        exit();
    }
    
    // Get API key from database
    $stripe_account = get_stripe_account($_SESSION['user_id']);
    
    // Verify the API key
    if (verify_stripe_api_key($stripe_account['api_key'])) {
        // Update last verified time
        $stmt = $db->prepare("UPDATE stripe_accounts SET last_verified_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        
        $_SESSION['success'] = "Stripe connection verified successfully!";
    } else {
        $_SESSION['error'] = "Failed to verify Stripe connection. The API key may be invalid or revoked.";
    }
}

header("Location: " . BASE_URL . "/dashboard.php");
exit();
?>