<?php
require_once __DIR__ . '/../session.php';
// Start secure session
start_secure_session();

// Verify session from cookie if no session vars exist
if (!isset($_SESSION['user_id']) && isset($_COOKIE['session_token'])) {
    verify_user_session();
}

// Clear any existing output buffering
if (ob_get_level() > 0) {
    ob_end_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav d-block d-sm-none">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>" 
                        href="<?php echo BASE_URL; ?>/dashboard.php">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'sync-settings.php' ? 'active' : ''; ?>" 
                        href="<?php echo BASE_URL; ?>/sync-settings.php">
                        <i class="bi bi-gear me-2"></i>Sync Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'sync-history.php' ? 'active' : ''; ?>" 
                        href="<?php echo BASE_URL; ?>/sync-history.php">
                        <i class="bi bi-clock-history me-2"></i>Sync History
                        </a>
                    </li>
                    <li class="nav-item">
                        <?php if (has_stripe_account($_SESSION['user_id'])): ?>
                            <a class="nav-link <?php echo $current_page === 'stripe-disconnect.php' ? 'active' : ''; ?>" 
                            href="<?php echo BASE_URL; ?>/stripe-disconnect.php">
                            <i class="bi bi-credit-card me-2"></i>Disconnect Stripe
                            </a>
                        <?php else: ?>
                            <a class="nav-link <?php echo $current_page === 'stripe-connect.php' ? 'active' : ''; ?>" 
                            href="<?php echo BASE_URL; ?>/stripe-connect.php">
                            <i class="bi bi-credit-card me-2"></i>Connect Stripe
                            </a>
                        <?php endif; ?>
                    </li>
                    <li class="nav-item">
                        <?php if (has_quickbooks_account($_SESSION['user_id'])): ?>
                            <a class="nav-link <?php echo $current_page === 'quickbooks-disconnect.php' ? 'active' : ''; ?>" 
                            href="<?php echo BASE_URL; ?>/quickbooks-disconnect.php">
                            <i class="bi bi-cash-stack me-2"></i>Disconnect QuickBooks
                            </a>
                        <?php else: ?>
                            <a class="nav-link <?php echo $current_page === 'quickbooks-connect.php' ? 'active' : ''; ?>" 
                            href="<?php echo BASE_URL; ?>/quickbooks-connect.php">
                            <i class="bi bi-cash-stack me-2"></i>Connect QuickBooks
                            </a>
                        <?php endif; ?>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <span class="nav-link">Welcome, <?php echo sanitize_output($_SESSION['username']); ?></span>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>/login.php">Login</a>
                        </li>
                    <?php endif; ?>
                    </ul>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-0">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible text-center fade show" role="alert">
                <?php echo sanitize_output($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible text-center fade show" role="alert">
                <?php echo sanitize_output($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>