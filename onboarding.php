<?php
// Start session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define onboarding steps
$steps = [
    'welcome' => 'Welcome',
    'database' => 'Database Setup',
    'create_tables' => 'Create Tables',
    'admin_account' => 'Admin Account',
    'stripe' => 'Stripe Configuration',
    'quickbooks' => 'QuickBooks Configuration',
    'complete' => 'Complete Setup'
];

// Get current step from URL or default to first step
$current_step = isset($_GET['step']) ? $_GET['step'] : 'welcome';
if (!array_key_exists($current_step, $steps)) {
    $current_step = 'welcome';
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($current_step) {
        case 'database':
            process_database_setup();
            break;
        case 'admin_account':
            process_admin_account();
            break;
        case 'stripe':
            process_stripe_config();
            break;
        case 'quickbooks':
            process_quickbooks_config();
            break;
    }
}

// Function to process database setup
function process_database_setup() {
    // Validate inputs
    $host = trim($_POST['db_host']);
    $name = trim($_POST['db_name']);
    $user = trim($_POST['db_user']);
    $pass = trim($_POST['db_pass']);
    $base_url = trim($_POST['base_url']);
    
    if (empty($host) || empty($name) || empty($user) || empty($base_url)) {
        $_SESSION['error'] = "Please fill in all required fields";
        return;
    }
    
    // Test database connection
    try {
        $db = new mysqli($host, $user, $pass, $name);
        if ($db->connect_error) {
            throw new Exception("Database connection failed: " . $db->connect_error);
        }
        
        // Store database config in session
        $_SESSION['db_config'] = [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'base_url' => $base_url
        ];
        
        // Create initial config file with database details
        create_initial_config();
        
        // Proceed to create tables
        header("Location: onboarding.php?step=create_tables");
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = "Database connection failed: " . $e->getMessage();
    }
}

// Function to create initial config file with database details
function create_initial_config() {
    if (!isset($_SESSION['db_config'])) {
        throw new Exception("Database configuration not found in session");
    }
    
    $config_content = "<?php
// Application configuration
define('APP_NAME', 'Stripe Integration App');
define('BASE_URL', '{$_SESSION['db_config']['base_url']}');
define('ENCRYPTION_KEY', '" . bin2hex(random_bytes(32)) . "');

// Database configuration
define('DB_HOST', '{$_SESSION['db_config']['host']}');
define('DB_NAME', '{$_SESSION['db_config']['name']}');
define('DB_USER', '{$_SESSION['db_config']['user']}');
define('DB_PASS', '{$_SESSION['db_config']['pass']}');

// Stripe configuration
define('STRIPE_API_VERSION', '2023-08-16');
define('STRIPE_REDIRECT_URI', BASE_URL . '/stripe-connect.php');

define('STRIPE_WEBHOOK_EVENTS', [
    'invoice.paid',
    'invoice.finalized',
    'payment_intent.succeeded',
    'charge.succeeded',
    'charge.failed',
    'refund.created',
    'credit_note.created',
    'product.created',
    'quote.accepted'
]);

// QuickBooks Configuration - Will be set during onboarding
define('QBO_CLIENT_ID', '');
define('QBO_CLIENT_SECRET', '');
define('QBO_REDIRECT_URI', BASE_URL . '/quickbooks-callback.php');
define('QBO_SCOPE', 'com.intuit.quickbooks.accounting');
define('QBO_ENVIRONMENT', 'production');

// Session configuration
define('SESSION_EXPIRE_DAYS', 7);

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>";

    // Write config file
    if (file_put_contents(__DIR__ . '/config.php', $config_content) === false) {
        throw new Exception("Failed to write config file. Please check directory permissions.");
    }
}

// Function to create database tables
function create_database_tables() {
    require_once __DIR__ . '/config.php';
    
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            throw new Exception("Database connection failed: " . $db->connect_error);
        }
        
        // SQL to create tables
        $sql = "
        CREATE TABLE IF NOT EXISTS `charge_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_charge_id` varchar(255) NOT NULL,
          `qbo_payment_id` varchar(255) NOT NULL,
          `qbo_invoice_id` varchar(255) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_charge_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `credit_note_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_credit_note_id` varchar(255) NOT NULL,
          `qbo_credit_memo_id` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_credit_note_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `invoice_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_invoice_id` varchar(255) NOT NULL,
          `qbo_invoice_id` varchar(255) NOT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_mapping` (`user_id`,`stripe_invoice_id`),
          KEY `user_id` (`user_id`),
          KEY `stripe_invoice_id` (`stripe_invoice_id`),
          KEY `qbo_invoice_id` (`qbo_invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `payment_intent_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_payment_intent_id` varchar(255) NOT NULL,
          `qbo_sales_receipt_id` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_payment_intent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `payment_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_invoice_id` varchar(255) NOT NULL,
          `qbo_payment_id` varchar(255) NOT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `product_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_product_id` varchar(255) NOT NULL,
          `qbo_item_id` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `qb_tax_rates` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `tax_rate_id` varchar(50) NOT NULL,
          `name` varchar(255) NOT NULL,
          `rate_value` decimal(10,2) NOT NULL,
          `tax_code_ref` varchar(50) NOT NULL,
          `tax_code_name` varchar(255) NOT NULL,
          `rate_type` enum('Sales','Purchase') NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_tax_rate` (`tax_rate_id`,`tax_code_ref`,`rate_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `quickbooks_accounts` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `realm_id` varchar(255) NOT NULL,
          `access_token` varchar(2048) NOT NULL,
          `refresh_token` varchar(512) NOT NULL,
          `access_token_expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          `refresh_token_expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `quote_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_quote_id` varchar(255) NOT NULL,
          `qbo_sales_order_id` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_quote_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `refund_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_refund_id` varchar(255) NOT NULL,
          `qbo_refund_receipt_id` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`,`stripe_refund_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `sessions` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `session_token` varchar(255) NOT NULL,
          `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `stripe_accounts` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_user_id` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `api_key` varchar(255) NOT NULL,
          `is_live` tinyint(1) NOT NULL DEFAULT 0,
          `last_verified_at` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

        CREATE TABLE IF NOT EXISTS `sync_history` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `event_type` varchar(50) NOT NULL,
          `stripe_event_id` varchar(255) DEFAULT NULL,
          `qbo_entity_id` varchar(255) DEFAULT NULL,
          `status` enum('success','failed','pending') NOT NULL,
          `message` text DEFAULT NULL,
          `data` text DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`),
          KEY `event_type` (`event_type`),
          KEY `stripe_event_id` (`stripe_event_id`),
          KEY `qbo_entity_id` (`qbo_entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `sync_settings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `stripe_invoice_paid_to_qbo_payment` tinyint(1) NOT NULL DEFAULT 0,
          `stripe_invoice_created_to_qbo_invoice` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          `stripe_payment_intent_succeeded_to_qbo_sales_receipt` tinyint(1) DEFAULT 0,
          `stripe_charge_succeeded_to_qbo_payment` tinyint(1) DEFAULT 0,
          `stripe_charge_failed_to_qbo_note` tinyint(1) DEFAULT 0,
          `stripe_refund_created_to_qbo_refund_receipt` tinyint(1) DEFAULT 0,
          `stripe_credit_note_created_to_qbo_credit_memo` tinyint(1) DEFAULT 0,
          `stripe_product_created_to_qbo_item` tinyint(1) DEFAULT 0,
          `stripe_quote_accepted_to_qbo_sales_order` tinyint(1) DEFAULT 0,
          `tax_exempt_id` varchar(255) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `users` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `username` varchar(50) NOT NULL,
          `email` varchar(100) NOT NULL,
          `password_hash` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `username` (`username`),
          UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
        
        CREATE TABLE IF NOT EXISTS `qb_deposit_accounts` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `account_id` VARCHAR(255) NOT NULL,
          `name` VARCHAR(255) NOT NULL,
          `account_type` VARCHAR(255) NOT NULL,
          `fully_qualified_name` VARCHAR(255) NOT NULL,
          UNIQUE KEY (account_id)
        );

        ALTER TABLE `sync_settings` ADD COLUMN `deposit_account_id` VARCHAR(255) NULL AFTER `tax_exempt_id`;

        ALTER TABLE `payment_mappings`
          ADD CONSTRAINT `payment_mappings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

        ALTER TABLE `quickbooks_accounts`
          ADD CONSTRAINT `quickbooks_accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

        ALTER TABLE `sessions`
          ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

        ALTER TABLE `stripe_accounts`
          ADD CONSTRAINT `stripe_accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
        ";
        
        // Execute the SQL to create tables
        if ($db->multi_query($sql)) {
            // Flush multi queries
            while ($db->more_results()) {
                $db->next_result();
            }
            
            // Proceed to admin account creation
            header("Location: onboarding.php?step=admin_account");
            exit();
        } else {
            throw new Exception("Failed to create tables: " . $db->error);
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: onboarding.php?step=database");
        exit();
    }
}

// Function to process admin account creation
function process_admin_account() {
    // Validate inputs
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $_SESSION['error'] = "Please fill in all fields";
        return;
    }
    
    if ($password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match";
        return;
    }
    
    if (strlen($password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters";
        return;
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Store admin account info in session
    $_SESSION['admin_account'] = [
        'username' => $username,
        'email' => $email,
        'password_hash' => $password_hash
    ];
    
    // Create the admin user in database
    try {
        require_once __DIR__ . '/config.php';
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            throw new Exception("Database connection failed: " . $db->connect_error);
        }
        
        // Create admin user (ID 1)
        $stmt = $db->prepare("INSERT INTO users (id, username, email, password_hash) VALUES (1, ?, ?, ?)");
        $stmt->bind_param("sss", $username, $email, $password_hash);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create admin user: " . $stmt->error);
        }
        
        // Create sync settings for admin user
        $stmt = $db->prepare("INSERT INTO sync_settings (user_id) VALUES (1)");
        if (!$stmt->execute()) {
            throw new Exception("Failed to create sync settings: " . $stmt->error);
        }
        
        // Proceed to Stripe configuration
        header("Location: onboarding.php?step=stripe");
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// Function to process Stripe configuration
function process_stripe_config() {
    $api_key = trim($_POST['api_key']);
    $is_live = isset($_POST['is_live']) ? true : false;
    
    if (empty($api_key)) {
        $_SESSION['error'] = "Please enter your Stripe API key";
        return;
    }
    
    // Store Stripe config in session
    $_SESSION['stripe_config'] = [
        'api_key' => $api_key,
        'is_live' => $is_live
    ];
    
    try {
        require_once __DIR__ . '/config.php';
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            throw new Exception("Database connection failed: " . $db->connect_error);
        }
        
        // Create admin user's stripe account (user_id = 1)
        $stmt = $db->prepare("INSERT INTO stripe_accounts (user_id, stripe_user_id, api_key, is_live, last_verified_at) VALUES (1, 'admin_setup', ?, ?, NOW())");
        $stmt->bind_param("si", $api_key, $is_live);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save Stripe API key: " . $stmt->error);
        }
        
        // Update config file with Stripe details
        update_config_with_stripe();
        
        // Proceed to QuickBooks configuration
        header("Location: onboarding.php?step=quickbooks");
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// Function to update config file with Stripe details
function update_config_with_stripe() {
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new Exception("Config file not found");
    }
    
    $config_content = file_get_contents(__DIR__ . '/config.php');
    
    // Update Stripe API key in config
    $config_content = preg_replace(
        "/define\('STRIPE_API_VERSION', '.*?'\);/",
        "define('STRIPE_API_VERSION', '2023-08-16');",
        $config_content
    );
    
    // Write updated config file
    if (file_put_contents(__DIR__ . '/config.php', $config_content) === false) {
        throw new Exception("Failed to update config file with Stripe details");
    }
}

// Function to process QuickBooks configuration
function process_quickbooks_config() {
    $client_id = trim($_POST['qbo_client_id']);
    $client_secret = trim($_POST['qbo_client_secret']);
    $environment = trim($_POST['qbo_environment']);
    
    if (empty($client_id) || empty($client_secret)) {
        $_SESSION['error'] = "Please fill in all required fields";
        return;
    }
    
    // Store QuickBooks config in session
    $_SESSION['quickbooks_config'] = [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'environment' => $environment
    ];
    
    // Update config file with QuickBooks details
    update_config_with_quickbooks();
    
    // Proceed to complete step
    header("Location: onboarding.php?step=complete");
    exit();
}

// Function to update config file with QuickBooks details
function update_config_with_quickbooks() {
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new Exception("Config file not found");
    }
    
    $config_content = file_get_contents(__DIR__ . '/config.php');
    
    // Update QuickBooks settings in config
    $config_content = preg_replace(
        "/define\('QBO_CLIENT_ID', '.*?'\);/",
        "define('QBO_CLIENT_ID', '" . $_SESSION['quickbooks_config']['client_id'] . "');",
        $config_content
    );
    
    $config_content = preg_replace(
        "/define\('QBO_CLIENT_SECRET', '.*?'\);/",
        "define('QBO_CLIENT_SECRET', '" . $_SESSION['quickbooks_config']['client_secret'] . "');",
        $config_content
    );
    
    $config_content = preg_replace(
        "/define\('QBO_ENVIRONMENT', '.*?'\);/",
        "define('QBO_ENVIRONMENT', '" . $_SESSION['quickbooks_config']['environment'] . "');",
        $config_content
    );
    
    // Write updated config file
    if (file_put_contents(__DIR__ . '/config.php', $config_content) === false) {
        throw new Exception("Failed to update config file with QuickBooks details");
    }
}

// Function to complete setup
function complete_setup() {
    try {
        // Verify all required data is present
        $required_data = [
            'db_config' => ['host', 'name', 'user', 'pass', 'base_url'],
            'admin_account' => ['username', 'email', 'password_hash'],
            'stripe_config' => ['api_key', 'is_live'],
            'quickbooks_config' => ['client_id', 'client_secret', 'environment']
        ];
        
        foreach ($required_data as $section => $keys) {
            if (!isset($_SESSION[$section])) {
                throw new Exception("Missing $section configuration");
            }
            foreach ($keys as $key) {
                if (!isset($_SESSION[$section][$key])) {
                    throw new Exception("Missing $key in $section configuration");
                }
            }
        }
        
        // Create final config file with all details
        create_final_config();
        
        // Create admin user if not already created
        if (!isset($_SESSION['admin_created'])) {
            require_once __DIR__ . '/config.php';
            $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            // Check if admin user exists
            $result = $db->query("SELECT id FROM users WHERE id = 1");
            if ($result->num_rows === 0) {
                // Create admin user (ID 1)
                $stmt = $db->prepare("INSERT INTO users (id, username, email, password_hash) VALUES (1, ?, ?, ?)");
                $stmt->bind_param(
                    "sss",
                    $_SESSION['admin_account']['username'],
                    $_SESSION['admin_account']['email'],
                    $_SESSION['admin_account']['password_hash']
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create admin user: " . $stmt->error);
                }
                
                // Create sync settings for admin user
                $stmt = $db->prepare("INSERT INTO sync_settings (user_id) VALUES (1)");
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create sync settings: " . $stmt->error);
                }
            }
            
            $_SESSION['admin_created'] = true;
        }
        
        return true;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        return false;
    }
}

// Function to create final config file
function create_final_config() {
    $config_content = "<?php
// Application configuration
define('APP_NAME', 'Stripe Integration App');
define('BASE_URL', '{$_SESSION['db_config']['base_url']}');
define('ENCRYPTION_KEY', '" . bin2hex(random_bytes(32)) . "');

// Database configuration
define('DB_HOST', '{$_SESSION['db_config']['host']}');
define('DB_NAME', '{$_SESSION['db_config']['name']}');
define('DB_USER', '{$_SESSION['db_config']['user']}');
define('DB_PASS', '{$_SESSION['db_config']['pass']}');

// Stripe configuration
define('STRIPE_API_VERSION', '2023-08-16');
define('STRIPE_REDIRECT_URI', BASE_URL . '/stripe-connect.php');

define('STRIPE_WEBHOOK_EVENTS', [
    'invoice.paid',
    'invoice.finalized',
    'payment_intent.succeeded',
    'charge.succeeded',
    'charge.failed',
    'refund.created',
    'credit_note.created',
    'product.created',
    'quote.accepted'
]);

// QuickBooks Configuration
define('QBO_CLIENT_ID', '{$_SESSION['quickbooks_config']['client_id']}');
define('QBO_CLIENT_SECRET', '{$_SESSION['quickbooks_config']['client_secret']}');
define('QBO_REDIRECT_URI', BASE_URL . '/quickbooks-callback.php');
define('QBO_SCOPE', 'com.intuit.quickbooks.accounting');
define('QBO_ENVIRONMENT', '{$_SESSION['quickbooks_config']['environment']}');

// Session configuration
define('SESSION_EXPIRE_DAYS', 7);

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>";

    // Write config file
    if (file_put_contents(__DIR__ . '/config.php', $config_content) === false) {
        throw new Exception("Failed to write final config file. Please check directory permissions.");
    }
}

// Handle create tables step
if ($current_step === 'create_tables' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        create_database_tables();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: onboarding.php?step=database");
        exit();
    }
}

// Include header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboarding - <?php echo htmlspecialchars($steps[$current_step]); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/stripe-app/assets/css/style.css">
    <style>
        .onboarding-container {
            max-width: 800px;
            margin: 2rem auto;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }
        .step {
            text-align: center;
            flex: 1;
            position: relative;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: bold;
        }
        .step.active .step-number {
            background-color:var(--primary-color);
            color: white;
        }
        .step.completed .step-number {
            background-color: #47cf47;
            color: white;
        }
    .text-success {
        color: #47cf47 !important;
    }

        .step-title {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .step.active .step-title {
            color: var(--primary-color);
            font-weight: bold;
        }
        .step.completed .step-title {
            color: #198754;
        }
        .step-connector {
            position: absolute;
            top: 20px;
            left: 50%;
            right: -50%;
            height: 2px;
            background-color: #e9ecef;
            z-index: -1;
        }
        .step.completed .step-connector {
            background-color: #198754;
        }
        .card {
            border-radius: 10px;
            box-shadow: rgba(0, 0, 0, 0.1) 0px 10px 50px;
            padding: 30px;
        }
        .loading-spinner {
            display: inline-block;
            width: 2rem;
            height: 2rem;
            vertical-align: text-bottom;
            border: 0.25em solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border 0.75s linear infinite;
        }
        @keyframes spinner-border {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="container onboarding-container">
    <div class="text-center mb-4">
        <h1>Stripe to QuickBooks Integration</h1>
        <p class="lead">Let's get your integration set up</p>
    </div>
    
    <!-- Step Indicator -->
    <div class="step-indicator">
        <?php 
        $step_count = 1;
        foreach ($steps as $step => $title): 
            $is_active = $step === $current_step;
            $is_completed = array_search($current_step, array_keys($steps)) > array_search($step, array_keys($steps));
        ?>
            <div class="step <?php echo $is_active ? 'active' : ''; ?> <?php echo $is_completed ? 'completed' : ''; ?>">
                <div class="step-number"><?php echo $step_count; ?></div>
                <div class="step-title"><?php echo htmlspecialchars($title); ?></div>
                <?php if ($step_count < count($steps)): ?>
                    <div class="step-connector"></div>
                <?php endif; ?>
            </div>
        <?php 
            $step_count++;
        endforeach; 
        ?>
    </div>
    
    <!-- Error/Success Messages -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    
    <!-- Step Content -->
    <div class="card">
        <div class="card-body">
            <?php 
            switch ($current_step) {
                case 'welcome':
                    include __DIR__ . '/includes/onboarding/welcome.php';
                    break;
                case 'database':
                    include __DIR__ . '/includes/onboarding/database.php';
                    break;
                case 'create_tables':
                    include __DIR__ . '/includes/onboarding/create_tables.php';
                    break;
                case 'admin_account':
                    include __DIR__ . '/includes/onboarding/admin_account.php';
                    break;
                case 'stripe':
                    include __DIR__ . '/includes/onboarding/stripe.php';
                    break;
                case 'quickbooks':
                    include __DIR__ . '/includes/onboarding/quickbooks.php';
                    break;
                case 'complete':
                    include __DIR__ . '/includes/onboarding/complete.php';
                    break;
            }
            ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>