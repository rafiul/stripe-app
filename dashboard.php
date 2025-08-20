<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

date_default_timezone_set('America/New_York');

// Get current time in UTC (QuickBooks uses UTC)
$now = time();

// Get user's Stripe account info
$stripe_account = get_stripe_account($_SESSION['user_id']);

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 mb-3">
            <div class="card h-100">
                <div class="card-body sidebar">
                    <?php include __DIR__ . '/includes/nav.php'; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <!-- Dashboard Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </h2>
                    <hr>
                    <h5 class="card-title">Welcome, admin</h5>
                </div>
            </div>

            <!-- Integrations Row -->
            <div class="row">
                <!-- Stripe Integration -->
                <div class="col-md-6 mb-3">
                    <?php if ($stripe_account): ?>
                        <div class="card h-100 border-<?php echo $stripe_account['is_live'] ? 'danger' : 'success'; ?>">
                            <div class="card-body">
                                <h3 class="card-title">Stripe Integration</h3>
                                <h5 class="alert-heading">
                                    Stripe Account Connected
                                    <?php if ($stripe_account['is_live']): ?>
                                        <span class="badge bg-danger">LIVE MODE</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">TEST MODE</span>
                                    <?php endif; ?>
                                </h5>
                                <p>Your Stripe account is connected and ready to use.</p>
                                <hr>
                                <p><strong>Account ID:</strong> <?php echo sanitize_output($stripe_account['stripe_user_id']); ?></p>
                                <p><strong>Last verified:</strong> <?php echo $stripe_account['last_verified_at'] ? date('M j, Y g:i a', strtotime($stripe_account['last_verified_at'])) : 'Never'; ?></p>
                                <p><strong>Key type:</strong> <?php echo $stripe_account['is_live'] ? 'Live production key' : 'Test key'; ?></p>

                                <div class="d-flex gap-2 mt-3">
                                    <a href="<?php echo BASE_URL; ?>/stripe-disconnect.php" class="btn btn-danger">Disconnect Stripe</a>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/stripe-verify.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <button type="submit" class="btn btn-primary">Verify Connection</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <h3 class="card-title">Stripe Integration</h3>
                                <div class="alert alert-warning">
                                    <h5>No Stripe Account Connected</h5>
                                    <p>You need to connect your Stripe account to use payment features.</p>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/stripe-connect.php" class="btn btn-primary">Connect Stripe</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- QuickBooks Integration -->
                <div class="col-md-6 mb-3">
                    <?php 
                    $qbo_account = get_quickbooks_account($_SESSION['user_id']);
                    $is_token_expired = $qbo_account && (strtotime($qbo_account['access_token_expires_at']) < $now);
                    ?>
                    <?php if ($qbo_account): ?>
                        <div class="card h-100 border-<?php echo $is_token_expired ? 'danger' : 'success'; ?>">
                            <div class="card-body">
                                <h3 class="card-title">QuickBooks Integration</h3>
                                <h5 class="alert-heading">
                                    QuickBooks Account Connected
                                    <span class="badge bg-<?php echo QBO_ENVIRONMENT === 'sandbox' ? 'warning text-dark' : 'primary'; ?>">
                                        <?php echo QBO_ENVIRONMENT === 'sandbox' ? 'SANDBOX' : 'PRODUCTION'; ?>
                                    </span>
                                </h5>
                                <p>Your QuickBooks account is <?php echo $is_token_expired ? 'expired' : 'connected'; ?>.</p>
                                <hr>
                                <p><strong>Realm ID:</strong> <?php echo sanitize_output($qbo_account['realm_id']); ?></p>
                                <p><strong>Connected since:</strong> <?php echo date('M j, Y', strtotime($qbo_account['created_at'])); ?></p>
                                <p><strong>Token expires:</strong> <?php echo date('M j, Y g:i a', strtotime($qbo_account['access_token_expires_at'])); ?></p>
                                <p><strong>Status:</strong> <?php echo $is_token_expired ? 'Expired' : 'Active'; ?></p>

                                <div class="d-flex gap-2 mt-3">
                                    <a href="<?php echo BASE_URL; ?>/quickbooks-disconnect.php" class="btn btn-danger">Disconnect QuickBooks</a>
                                    <?php if ($is_token_expired): ?>
                                        <a href="<?php echo get_qbo_auth_url(); ?>" class="btn btn-primary">Reconnect</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <h3 class="card-title">QuickBooks Integration</h3>
                                <div class="alert alert-warning">
                                    <h5>No QuickBooks Account Connected</h5>
                                    <p>Connect your QuickBooks account to sync financial data.</p>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/quickbooks-connect.php" class="btn btn-primary">Connect QuickBooks</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- row -->
        </div>
    </div>
</div>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>