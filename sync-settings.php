<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

// Get current sync settings
$sync_settings = get_sync_settings($_SESSION['user_id']);

// Get QuickBooks tax rates if connected
$tax_rates = [];
$deposit_accounts = [];
$qbo_connected = has_quickbooks_account($_SESSION['user_id']);

// Process refresh tax rates request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_tax_rates'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: " . BASE_URL . "/sync-settings.php");
        exit();
    }

    if ($qbo_connected) {
        try {
            $quickbooks = get_quickbooks_client($_SESSION['user_id']);
            if ($quickbooks) {
                // First get all tax rates
                $tax_rate_query = "SELECT * FROM TaxRate";
                $tax_rate_result = make_qbo_api_request($_SESSION['user_id'], '/query?query=' . urlencode($tax_rate_query));
                
                // Store tax rates in an array for reference
                $all_tax_rates = [];
                if ($tax_rate_result['code'] === 200 && isset($tax_rate_result['body']['QueryResponse']['TaxRate'])) {
                    foreach ($tax_rate_result['body']['QueryResponse']['TaxRate'] as $rate) {
                        $all_tax_rates[$rate['Id']] = $rate;
                    }
                }
                
                // Now get tax codes with their references to tax rates
                $query = "SELECT * FROM TaxCode";
                $result = make_qbo_api_request($_SESSION['user_id'], '/query?query=' . urlencode($query));
                
                // Clear existing tax rates
                $db->query("TRUNCATE TABLE qb_tax_rates");
                
                if ($result['code'] === 200 && isset($result['body']['QueryResponse']['TaxCode'])) {
                    foreach ($result['body']['QueryResponse']['TaxCode'] as $tax_code) {
                        // Process sales tax rates
                        if (isset($tax_code['SalesTaxRateList']['TaxRateDetail'])) {
                            foreach ($tax_code['SalesTaxRateList']['TaxRateDetail'] as $detail) {
                                if (isset($detail['TaxRateRef']['value'])) {
                                    $rate_id = $detail['TaxRateRef']['value'];
                                    if (isset($all_tax_rates[$rate_id])) {
                                        $rate_name = $all_tax_rates[$rate_id]['Name'];
                                        $rate_value = $all_tax_rates[$rate_id]['RateValue'] ?? 0;
                                        $tax_code_id = $tax_code['Id'];
                                        $tax_code_name = $tax_code['Name'];
                                        
                                        $stmt = $db->prepare("INSERT INTO qb_tax_rates 
                                            (tax_rate_id, name, rate_value, tax_code_ref, tax_code_name, rate_type) 
                                            VALUES (?, ?, ?, ?, ?, 'Sales')");
                                        $stmt->bind_param("ssdss", 
                                            $rate_id,
                                            $rate_name,
                                            $rate_value,
                                            $tax_code_id,
                                            $tax_code_name);
                                        $stmt->execute();
                                    }
                                }
                            }
                        }
                        
                        // Process purchase tax rates
                        if (isset($tax_code['PurchaseTaxRateList']['TaxRateDetail'])) {
                            foreach ($tax_code['PurchaseTaxRateList']['TaxRateDetail'] as $detail) {
                                if (isset($detail['TaxRateRef']['value'])) {
                                    $rate_id = $detail['TaxRateRef']['value'];
                                    if (isset($all_tax_rates[$rate_id])) {
                                        $rate_name = $all_tax_rates[$rate_id]['Name'];
                                        $rate_value = $all_tax_rates[$rate_id]['RateValue'] ?? 0;
                                        $tax_code_id = $tax_code['Id'];
                                        $tax_code_name = $tax_code['Name'];
                                        
                                        $stmt = $db->prepare("INSERT INTO qb_tax_rates 
                                            (tax_rate_id, name, rate_value, tax_code_ref, tax_code_name, rate_type) 
                                            VALUES (?, ?, ?, ?, ?, 'Purchase')");
                                        $stmt->bind_param("ssdss", 
                                            $rate_id,
                                            $rate_name,
                                            $rate_value,
                                            $tax_code_id,
                                            $tax_code_name);
                                        $stmt->execute();
                                    }
                                }
                            }
                        }
                    }
                    $_SESSION['success'] = "Tax rates refreshed successfully!";
                }
            }
        } catch (Exception $e) {
            error_log("Failed to fetch QuickBooks tax rates: " . $e->getMessage());
            $_SESSION['error'] = "Failed to fetch tax rates from QuickBooks. Please try again.";
        }
    }
    
    header("Location: " . BASE_URL . "/sync-settings.php");
    exit();
}

// Process refresh deposit accounts request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_deposit_accounts'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: " . BASE_URL . "/sync-settings.php");
        exit();
    }

    if ($qbo_connected) {
        try {
            $query = "SELECT * FROM Account WHERE AccountType IN ('Bank', 'Other Current Asset') AND Active = true";
            $result = make_qbo_api_request($_SESSION['user_id'], '/query?query=' . urlencode($query));
            
            // Clear existing deposit accounts
            $db->query("TRUNCATE TABLE qb_deposit_accounts");
            
            if ($result['code'] === 200 && isset($result['body']['QueryResponse']['Account'])) {
                foreach ($result['body']['QueryResponse']['Account'] as $account) {
                    $stmt = $db->prepare("INSERT INTO qb_deposit_accounts 
                        (account_id, name, account_type, fully_qualified_name) 
                        VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", 
                        $account['Id'],
                        $account['Name'],
                        $account['AccountType'],
                        $account['FullyQualifiedName']);
                    $stmt->execute();
                }
                $_SESSION['success'] = "Deposit accounts refreshed successfully!";
            }
        } catch (Exception $e) {
            error_log("Failed to fetch QuickBooks deposit accounts: " . $e->getMessage());
            $_SESSION['error'] = "Failed to fetch deposit accounts from QuickBooks. Please try again.";
        }
    }
    
    header("Location: " . BASE_URL . "/sync-settings.php");
    exit();
}

// Load tax rates for dropdown from database - MODIFIED TO USE tax_code_ref INSTEAD OF tax_rate_id
if ($qbo_connected) {
    $result = $db->query("SELECT * FROM qb_tax_rates ORDER BY name, rate_type");
    while ($row = $result->fetch_assoc()) {
        $tax_rates[$row['tax_code_ref']] = $row['name'] . ' (Rate: ' . $row['rate_value'] . '%, TaxCode: ' . $row['tax_code_ref'] . ', Type: ' . $row['rate_type'] . ')';
    }
    
    // Load deposit accounts for dropdown
    $result = $db->query("SELECT * FROM qb_deposit_accounts ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $deposit_accounts[$row['account_id']] = $row['fully_qualified_name'] . ' (' . $row['account_type'] . ')';
    }
}

// Process form submission for sync settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid CSRF token";
        header("Location: " . BASE_URL . "/sync-settings.php");
        exit();
    }

    $invoice_paid = isset($_POST['stripe_invoice_paid_to_qbo_payment']) ? 1 : 0;
    $invoice_created = isset($_POST['stripe_invoice_created_to_qbo_invoice']) ? 1 : 0;
    $payment_intent_succeeded = isset($_POST['stripe_payment_intent_succeeded_to_qbo_sales_receipt']) ? 1 : 0;
    $charge_succeeded = isset($_POST['stripe_charge_succeeded_to_qbo_payment']) ? 1 : 0;
    $refund_created = isset($_POST['stripe_refund_created_to_qbo_refund_receipt']) ? 1 : 0;
    $credit_note_created = isset($_POST['stripe_credit_note_created_to_qbo_credit_memo']) ? 1 : 0;
    $product_created = isset($_POST['stripe_product_created_to_qbo_item']) ? 1 : 0;
    $tax_exempt_id = isset($_POST['tax_exempt_id']) ? $_POST['tax_exempt_id'] : null;
    $deposit_account_id = isset($_POST['deposit_account_id']) ? $_POST['deposit_account_id'] : null;

    // Update or create sync settings
    if ($sync_settings) {
        $stmt = $db->prepare("UPDATE sync_settings SET 
            stripe_invoice_paid_to_qbo_payment = ?,
            stripe_invoice_created_to_qbo_invoice = ?,
            stripe_payment_intent_succeeded_to_qbo_sales_receipt = ?,
            stripe_charge_succeeded_to_qbo_payment = ?,
            stripe_refund_created_to_qbo_refund_receipt = ?,
            stripe_credit_note_created_to_qbo_credit_memo = ?,
            stripe_product_created_to_qbo_item = ?,
            tax_exempt_id = ?,
            deposit_account_id = ?
            WHERE user_id = ?");
        $stmt->bind_param("iiiiiiiiss", 
            $invoice_paid, $invoice_created, 
            $payment_intent_succeeded, $charge_succeeded, 
            $refund_created, $credit_note_created, 
            $product_created, $tax_exempt_id,
            $deposit_account_id,
            $_SESSION['user_id']);
    } else {
        $stmt = $db->prepare("INSERT INTO sync_settings 
            (user_id, stripe_invoice_paid_to_qbo_payment, stripe_invoice_created_to_qbo_invoice,
             stripe_payment_intent_succeeded_to_qbo_sales_receipt, stripe_charge_succeeded_to_qbo_payment,
             stripe_refund_created_to_qbo_refund_receipt,
             stripe_credit_note_created_to_qbo_credit_memo, stripe_product_created_to_qbo_item, 
             tax_exempt_id, deposit_account_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiiiiiiss", 
            $_SESSION['user_id'], $invoice_paid, $invoice_created,
            $payment_intent_succeeded, $charge_succeeded,
            $refund_created, $credit_note_created, $product_created, 
            $tax_exempt_id, $deposit_account_id);
    }

    if ($stmt->execute()) {
        // Update webhooks based on settings
        update_stripe_webhooks($_SESSION['user_id']);
        
        $_SESSION['success'] = "Sync settings updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update sync settings";
    }

    header("Location: " . BASE_URL . "/sync-settings.php");
    exit();
}

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="inner-wrap">
    <div class="row">
        <div class="custom-col-20 d-none d-sm-block">
            <div class="card">
                <div class="card-body sidebar">
                    <?php include __DIR__ . '/includes/nav.php'; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="card-title mb-4"><i class="bi bi-clock-history me-2"></i> Sync Settings <hr></h2>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?php echo sanitize_output($_SESSION['error']); unset($_SESSION['error']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo sanitize_output($_SESSION['success']); unset($_SESSION['success']); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="<?php echo BASE_URL; ?>/sync-settings.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_invoice_paid_to_qbo_payment" 
                                    name="stripe_invoice_paid_to_qbo_payment" value="1"
                                    <?php echo (isset($sync_settings['stripe_invoice_paid_to_qbo_payment']) && $sync_settings['stripe_invoice_paid_to_qbo_payment']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_invoice_paid_to_qbo_payment">
                                    <strong>Stripe Invoice Paid → QuickBooks Payment</strong>
                                </label>
                                <div class="form-text">
                                    When an invoice is paid in Stripe, create a payment in QuickBooks for the corresponding invoice.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_invoice_created_to_qbo_invoice" 
                                    name="stripe_invoice_created_to_qbo_invoice" value="1"
                                    <?php echo (isset($sync_settings['stripe_invoice_created_to_qbo_invoice']) && $sync_settings['stripe_invoice_created_to_qbo_invoice']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_invoice_created_to_qbo_invoice">
                                    <strong>Stripe Invoice Created → QuickBooks Invoice</strong>
                                </label>
                                <div class="form-text">
                                    When an invoice is created in Stripe, create a corresponding invoice in QuickBooks.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_payment_intent_succeeded_to_qbo_sales_receipt" 
                                    name="stripe_payment_intent_succeeded_to_qbo_sales_receipt" value="1"
                                    <?php echo (isset($sync_settings['stripe_payment_intent_succeeded_to_qbo_sales_receipt']) && $sync_settings['stripe_payment_intent_succeeded_to_qbo_sales_receipt']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_payment_intent_succeeded_to_qbo_sales_receipt">
                                    <strong>Stripe Payment Intent Succeeded → QuickBooks Sales Receipt</strong>
                                </label>
                                <div class="form-text">
                                    When a payment intent succeeds in Stripe, create a sales receipt in QuickBooks.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_charge_succeeded_to_qbo_payment" 
                                    name="stripe_charge_succeeded_to_qbo_payment" value="1"
                                    <?php echo (isset($sync_settings['stripe_charge_succeeded_to_qbo_payment']) && $sync_settings['stripe_charge_succeeded_to_qbo_payment']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_charge_succeeded_to_qbo_payment">
                                    <strong>Stripe Charge Succeeded → QuickBooks Payment</strong>
                                </label>
                                <div class="form-text">
                                    When a charge succeeds in Stripe, create a payment in QuickBooks.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_refund_created_to_qbo_refund_receipt" 
                                    name="stripe_refund_created_to_qbo_refund_receipt" value="1"
                                    <?php echo (isset($sync_settings['stripe_refund_created_to_qbo_refund_receipt']) && $sync_settings['stripe_refund_created_to_qbo_refund_receipt']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_refund_created_to_qbo_refund_receipt">
                                    <strong>Stripe Refund Created → QuickBooks Refund Receipt</strong>
                                </label>
                                <div class="form-text">
                                    When a refund is created in Stripe, create a refund receipt in QuickBooks.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_credit_note_created_to_qbo_credit_memo" 
                                    name="stripe_credit_note_created_to_qbo_credit_memo" value="1"
                                    <?php echo (isset($sync_settings['stripe_credit_note_created_to_qbo_credit_memo']) && $sync_settings['stripe_credit_note_created_to_qbo_credit_memo']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_credit_note_created_to_qbo_credit_memo">
                                    <strong>Stripe Credit Note Created → QuickBooks Credit Memo</strong>
                                </label>
                                <div class="form-text">
                                    When a credit note is created in Stripe, create a credit memo in QuickBooks.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stripe_product_created_to_qbo_item" 
                                    name="stripe_product_created_to_qbo_item" value="1"
                                    <?php echo (isset($sync_settings['stripe_product_created_to_qbo_item']) && $sync_settings['stripe_product_created_to_qbo_item']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stripe_product_created_to_qbo_item">
                                    <strong>Stripe Product Created → QuickBooks Item</strong>
                                </label>
                                <div class="form-text">
                                    When a product is created in Stripe, create an item in QuickBooks.
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="tax_exempt_id" class="form-label"><strong>Zero Tax / Tax Exempt</strong></label>
                            <select class="form-select" id="tax_exempt_id" name="tax_exempt_id">
                                <option value="">-- Select Tax Code --</option>
                                <?php if ($qbo_connected && !empty($tax_rates)): ?>
                                    <?php foreach ($tax_rates as $id => $name): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>" 
                                            <?php echo (isset($sync_settings['tax_exempt_id']) && $sync_settings['tax_exempt_id'] == $id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">
                                Select the tax code to use for tax-exempt transactions in QuickBooks (now using Tax Code instead of Tax Rate).
                                <?php if (!$qbo_connected): ?>
                                    <span class="text-danger">QuickBooks must be connected to load tax codes.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="deposit_account_id" class="form-label"><strong>Account To Deposit</strong></label>
                            <select class="form-select" id="deposit_account_id" name="deposit_account_id">
                                <option value="">-- Select Deposit Account --</option>
                                <?php if ($qbo_connected && !empty($deposit_accounts)): ?>
                                    <?php foreach ($deposit_accounts as $id => $name): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>" 
                                            <?php echo (isset($sync_settings['deposit_account_id']) && $sync_settings['deposit_account_id'] == $id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">
                                Select the account where payments should be deposited in QuickBooks (for payments and sales receipts).
                                <?php if (!$qbo_connected): ?>
                                    <span class="text-danger">QuickBooks must be connected to load deposit accounts.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
                    </form>

                    <?php if ($qbo_connected): ?>
                        <div class="mt-4">
                            <h4>QuickBooks Data Management</h4>
                            
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h5>Tax Rates</h5>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/sync-settings.php" class="mb-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <button type="submit" name="refresh_tax_rates" class="btn btn-secondary">
                                            <i class="fas fa-sync-alt"></i> Refresh Tax Rates
                                        </button>
                                        <div class="form-text">
                                            This will update all tax rate information from QuickBooks.
                                        </div>
                                    </form>

                                    <?php
                                    $tax_rate_count = $db->query("SELECT COUNT(*) as count FROM qb_tax_rates")->fetch_assoc()['count'];
                                    if ($tax_rate_count > 0): ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Rate Value</th>
                                                        <th>Tax Code Ref</th>
                                                        <th>Tax Code Name</th>
                                                        <th>Type</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $result = $db->query("SELECT * FROM qb_tax_rates ORDER BY name, rate_type");
                                                    while ($row = $result->fetch_assoc()): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['rate_value']); ?>%</td>
                                                            <td><?php echo htmlspecialchars($row['tax_code_ref']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['tax_code_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['rate_type']); ?></td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">No tax rates stored yet. Click "Refresh Tax Rates" to load them from QuickBooks.</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5>Deposit Accounts</h5>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/sync-settings.php" class="mb-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <button type="submit" name="refresh_deposit_accounts" class="btn btn-secondary">
                                            <i class="fas fa-sync-alt"></i> Refresh Deposit Accounts
                                        </button>
                                        <div class="form-text">
                                            This will update all deposit account information from QuickBooks.
                                        </div>
                                    </form>

                                    <?php
                                    $account_count = $db->query("SELECT COUNT(*) as count FROM qb_deposit_accounts")->fetch_assoc()['count'];
                                    if ($account_count > 0): ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Account ID</th>
                                                        <th>Name</th>
                                                        <th>Type</th>
                                                        <th>Fully Qualified Name</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $result = $db->query("SELECT * FROM qb_deposit_accounts ORDER BY name");
                                                    while ($row = $result->fetch_assoc()): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['account_id']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['account_type']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['fully_qualified_name']); ?></td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">No deposit accounts stored yet. Click "Refresh Deposit Accounts" to load them from QuickBooks.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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