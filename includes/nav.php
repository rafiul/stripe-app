<?php
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    return;
}

// Get current page for active state highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
 <a class="navbar-brand" href="<?php echo BASE_URL; ?>/dashboard.php">
    <img src="<?php echo BASE_URL; ?>/assets/images/Stripe_logo-W.png" alt="Stripe Logo" class="brand-logo">
</a>
<ul class="nav flex-column">
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
    <li class="nav-item mt-3">
        <a class="nav-link text-danger" href="<?php echo BASE_URL; ?>/logout.php">
            <i class="bi bi-box-arrow-right me-2"></i>Logout
        </a>
    </li>
</ul>