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

<div class="inner-wrap">
    <div class="row">
        <div class="custom-col-20 d-none d-sm-block">
            <div class="card">
                <div class="card-body sidebar">
                    <a class="navbar-brand" href="<?php echo BASE_URL; ?>/dashboard.php"><img src="<?php echo BASE_URL; ?>/assets/images/Stripe_logo-W.png" alt="Stripe Logo" class="brand-logo">
</a>
                    <?php include __DIR__ . '/includes/nav.php'; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="card-title">Welcome, <?php echo sanitize_output($_SESSION['username']); ?></h2>
                    <p class="card-text">This is your dashboard.</p>
                    <hr>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <?php if ($stripe_account): ?>
                    <div class="card h-100 mb-4 alert alert-<?php echo $stripe_account['is_live'] ? 'danger' : 'success'; ?>">
                        <div class="card-body">
                            <h3 class="card-title">Stripe Integration</h3>
                            
                            
                                <div class="">
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
                                    <p class="mb-1"><strong>Account ID:</strong> <?php echo sanitize_output($stripe_account['stripe_user_id']); ?></p>
                                    <p class="mb-1"><strong>Last verified:</strong> <?php echo $stripe_account['last_verified_at'] ? date('M j, Y g:i a', strtotime($stripe_account['last_verified_at'])) : 'Never'; ?></p>
                                    <p class="mb-0"><strong>Key type:</strong> <?php echo $stripe_account['is_live'] ? 'Live production key' : 'Test key'; ?></p>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <a href="<?php echo BASE_URL; ?>/stripe-disconnect.php" class="btn btn-danger">Disconnect Stripe</a>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/stripe-verify.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <button type="submit" class="btn btn-primary">Verify Connection</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <h5 class="alert-heading">No Stripe Account Connected</h5>
                                    <p>You need to connect your Stripe account to use payment features.</p>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/stripe-connect.php" class="btn btn-primary">Connect Stripe</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <?php 
                    $qbo_account = get_quickbooks_account($_SESSION['user_id']);
                    $is_token_expired = $qbo_account && (strtotime($qbo_account['access_token_expires_at']) < $now);
                    ?>
                    <div class="card h-100 mb-4 alert alert-<?php echo $is_token_expired ? 'danger' : 'success'; ?>">
                        <div class="card-body">
                            <h3 class="card-title">QuickBooks Integration</h3>
                            <?php if ($qbo_account): ?>
                                <div class="">
                                    <h5 class="alert-heading">
                                        QuickBooks Account Connected
                                        <span class="badge bg-<?php echo QBO_ENVIRONMENT === 'sandbox' ? 'warning text-dark' : 'primary'; ?>">
                                            <?php echo QBO_ENVIRONMENT === 'sandbox' ? 'SANDBOX' : 'PRODUCTION'; ?>
                                        </span>
                                    </h5>
                                    <p>Your QuickBooks account is <?php echo $is_token_expired ? 'expired' : 'connected'; ?>.</p>
                                    <hr>
                                    <p class="mb-1"><strong>Realm ID:</strong> <?php echo sanitize_output($qbo_account['realm_id']); ?></p>
                                    <p class="mb-1"><strong>Connected since:</strong> <?php echo date('M j, Y', strtotime($qbo_account['created_at'])); ?></p>
                                    <p class="mb-1"><strong>Token expires:</strong> <?php echo date('M j, Y g:i a', strtotime($qbo_account['access_token_expires_at'])); ?></p>
                                    <p class="mb-0"><strong>Status:</strong> <?php echo $is_token_expired ? 'Expired' : 'Active'; ?></p>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <a href="<?php echo BASE_URL; ?>/quickbooks-disconnect.php" class="btn btn-danger">Disconnect QuickBooks</a>
                                    <?php if ($is_token_expired): ?>
                                        <a href="<?php echo get_qbo_auth_url(); ?>" class="btn btn-primary">Reconnect</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <h5 class="alert-heading">No QuickBooks Account Connected</h5>
                                    <p>Connect your QuickBooks account to sync financial data.</p>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/quickbooks-connect.php" class="btn btn-primary">Connect QuickBooks</a>
                            <?php endif; ?>
                        </div>
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