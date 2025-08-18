<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

// Check if already connected
if (has_stripe_account($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error = "Invalid CSRF token";
    } else {
        $api_key = trim($_POST['api_key']);
        $is_live = isset($_POST['is_live']) ? true : false;
        
        if (empty($api_key)) {
            $error = "Please enter your Stripe API key";
        } else {
            // Verify the API key
            if (verify_stripe_api_key($api_key)) {
                // Get account details
                $account_details = get_stripe_account_details($api_key);
                
                if ($account_details && isset($account_details['id'])) {
                    // Save to database
                    $stmt = $db->prepare("INSERT INTO stripe_accounts (user_id, stripe_user_id, api_key, is_live, last_verified_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->bind_param("issi", 
                        $_SESSION['user_id'],
                        $account_details['id'],
                        $api_key,
                        $is_live
                    );
                    
                    if ($stmt->execute()) {
                        $_SESSION['success'] = "Stripe account connected successfully!";
                        header("Location: " . BASE_URL . "/dashboard.php");
                        exit();
                    } else {
                        $error = "Failed to save Stripe account details.";
                    }
                } else {
                    $error = "Failed to retrieve Stripe account details.";
                }
            } else {
                $error = "Invalid Stripe API key. Please check and try again.";
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
                    <h2 class="card-title text-center mb-4">Connect Stripe Account</h2>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo sanitize_output($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/stripe-connect.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="mb-3">
                            <label for="api_key" class="form-label">Stripe API Key</label>
                            <input type="password" class="form-control" id="api_key" name="api_key" required placeholder="sk_test_... or sk_live_...">
                            <div class="form-text">We'll encrypt your key before storing it.</div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_live" name="is_live">
                            <label class="form-check-label" for="is_live">This is a live production key</label>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Connect Stripe Account</button>
                            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    
                    <div class="mt-4">
                        <h5>Where to find your API key:</h5>
                        <ol>
                            <li>Log in to your <a href="https://dashboard.stripe.com" target="_blank">Stripe Dashboard</a></li>
                            <li>Go to Developers → API keys</li>
                            <li>Copy your Secret Key (starts with sk_test_ or sk_live_)</li>
                        </ol>
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