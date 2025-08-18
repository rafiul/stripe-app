<?php
require_once __DIR__ . '/config.php';

// Start session
session_start();

/**
 * Require user to be logged in
 */
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        if (!verify_user_session()) {
            header("Location: " . BASE_URL . "/login.php");
            exit();
        }
    }
}

// Hash password
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Verify password
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Generate CSRF token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Verify Stripe API key
function verify_stripe_api_key($api_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/balance");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}


// Get Stripe account details
function get_stripe_account_details($api_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/account");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

/**
 * Get Stripe customer by ID
 */
function get_stripe_customer($customer_id, $api_key) {
    error_log("Fetching Stripe customer: " . $customer_id);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/customers/" . $customer_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch Stripe customer. HTTP code: " . $http_code);
        return null;
    }
    
    return json_decode($response, true);
}

// Get current user's Stripe account info
function get_stripe_account($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM stripe_accounts WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Check if user has connected Stripe account
function has_stripe_account($user_id) {
    return get_stripe_account($user_id) !== null;
}

// Sanitize output
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Encryption functions
function encrypt_api_key($api_key) {
    if (empty($api_key)) {
        return null;
    }
    
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($api_key, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt_api_key($encrypted_api_key) {
    if (empty($encrypted_api_key)) {
        return null;
    }
    
    $data = base64_decode($encrypted_api_key);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}

// QuickBooks functions
function has_quickbooks_account($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT id FROM quickbooks_accounts WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

function get_quickbooks_account($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM quickbooks_accounts WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

function get_qbo_auth_url() {
    $base_url = QBO_ENVIRONMENT === 'sandbox' 
        ? 'https://appcenter.intuit.com/connect/oauth2'
        : 'https://appcenter.intuit.com/connect/oauth2';
    
    return $base_url . '?' . http_build_query([
        'client_id' => QBO_CLIENT_ID,
        'response_type' => 'code',
        'scope' => QBO_SCOPE,
        'redirect_uri' => QBO_REDIRECT_URI,
        'state' => generate_csrf_token()
    ]);
}

/**
 * Verify user session from cookie
 */
function verify_user_session() {
    global $db;
    
    if (isset($_COOKIE['session_token'])) {
        // Clean expired sessions first
        clean_expired_sessions();
        
        // Check database for valid session
        $stmt = $db->prepare("SELECT user_id FROM sessions WHERE session_token = ? AND expires_at > NOW()");
        $stmt->bind_param("s", $_COOKIE['session_token']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $session = $result->fetch_assoc();
            
            // Get user details
            $user_stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $session['user_id']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            
            if ($user_result->num_rows === 1) {
                $user = $user_result->fetch_assoc();
                
                // Set session variables
                $_SESSION['user_id'] = $session['user_id'];
                $_SESSION['username'] = $user['username'];
                
                // Update session expiration
                $new_expires = date('Y-m-d H:i:s', time() + (86400 * SESSION_EXPIRE_DAYS));
                $update_stmt = $db->prepare("UPDATE sessions SET expires_at = ? WHERE session_token = ?");
                $update_stmt->bind_param("ss", $new_expires, $_COOKIE['session_token']);
                $update_stmt->execute();
                
                // Refresh cookie
                setcookie('session_token', $_COOKIE['session_token'], [
                    'expires' => time() + (86400 * SESSION_EXPIRE_DAYS),
                    'path' => '/',
                    'domain' => $_SERVER['HTTP_HOST'],
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
                
                return true;
            }
        }
    }
    return false;
}

/**
 * Clean expired sessions from database
 */
function clean_expired_sessions() {
    global $db;
    $db->query("DELETE FROM sessions WHERE expires_at <= NOW()");
}

function refresh_qbo_token_cron($user_id) {
    global $db;
    
    // Get the current token
    $qbo_account = get_quickbooks_account($user_id);
    if (!$qbo_account) return false;
    
    try {
        $token_url = QBO_ENVIRONMENT === 'sandbox'
            ? 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
            : 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        
        $auth_header = 'Basic ' . base64_encode(QBO_CLIENT_ID . ':' . QBO_CLIENT_SECRET);
        
        $data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $qbo_account['refresh_token']
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: ' . $auth_header,
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $token_data = json_decode($response, true);
            
            // Calculate new expiration times
            $access_token_expires = time() + $token_data['expires_in'];
            $refresh_token_expires = time() + $token_data['x_refresh_token_expires_in'];
            
            // Update database
            $stmt = $db->prepare("UPDATE quickbooks_accounts SET 
                access_token = ?,
                refresh_token = ?,
                access_token_expires_at = FROM_UNIXTIME(?),
                refresh_token_expires_at = FROM_UNIXTIME(?),
                updated_at = NOW()
                WHERE user_id = ?");
            
            $stmt->bind_param(
                "sssii",
                $token_data['access_token'],
                $token_data['refresh_token'],
                $access_token_expires,
                $refresh_token_expires,
                $user_id
            );
            
            return $stmt->execute();
        }
    } catch (Exception $e) {
        error_log("QBO Token refresh failed: " . $e->getMessage());
    }
    
    return false;
}

function make_qbo_api_request($user_id, $endpoint, $method = 'GET', $data = null) {
    $qbo_account = get_quickbooks_account($user_id);
    if (!$qbo_account) {
        return false;
    }

   

    $base_url = QBO_ENVIRONMENT === 'sandbox'
        ? 'https://sandbox-quickbooks.api.intuit.com/v3/company/'
        : 'https://quickbooks.api.intuit.com/v3/company/';
    
    $url = $base_url . $qbo_account['realm_id'] . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $qbo_account['access_token'],
        'Content-Type: application/json'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $http_code,
        'body' => json_decode($response, true)
    ];
}


/**
 * Get user ID by Stripe account ID
 */
function get_user_id_by_stripe_account_id($stripe_user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT user_id FROM stripe_accounts WHERE stripe_user_id = ?");
    $stmt->bind_param("s", $stripe_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0 ? $result->fetch_row()[0] : null;
}

/**
 * Log sync history
 */
function log_sync_history($user_id, $event_type, $stripe_event_id, $status, $message, $data) {
    global $db;
    
    $stmt = $db->prepare("INSERT INTO sync_history 
                         (user_id, event_type, stripe_event_id, status, message, data) 
                         VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $user_id, $event_type, $stripe_event_id, $status, $message, json_encode($data));
    $stmt->execute();
    
    return $db->insert_id;
}

/**
 * Update sync history
 */
function update_sync_history($id, $status, $message, $data = null) {
    global $db;
    
    if ($data) {
        $stmt = $db->prepare("UPDATE sync_history 
                             SET status = ?, message = ?, data = ?
                             WHERE id = ?");
        $stmt->bind_param("sssi", $status, $message, json_encode($data), $id);
    } else {
        $stmt = $db->prepare("UPDATE sync_history 
                             SET status = ?, message = ?
                             WHERE id = ?");
        $stmt->bind_param("ssi", $status, $message, $id);
    }
    
    $stmt->execute();
}

/**
 * Update Stripe webhooks based on user's sync settings
 */
function update_stripe_webhooks($user_id) {
    $sync_settings = get_sync_settings($user_id);
    $stripe_account = get_stripe_account($user_id);
    
    if (!$sync_settings || !$stripe_account) {
        return false;
    }
    
    // Get current webhooks
    $webhooks = get_stripe_webhooks($stripe_account['api_key']);
    
    // Build list of events we need based on settings
    $needed_events = [];
    
    if ($sync_settings['stripe_invoice_paid_to_qbo_payment']) {
        $needed_events[] = 'invoice.paid';
    }
    if ($sync_settings['stripe_invoice_created_to_qbo_invoice']) {
        $needed_events[] = 'invoice.finalized';
    }
    if ($sync_settings['stripe_payment_intent_succeeded_to_qbo_sales_receipt']) {
        $needed_events[] = 'payment_intent.succeeded';
    }
    if ($sync_settings['stripe_charge_succeeded_to_qbo_payment']) {
        $needed_events[] = 'charge.succeeded';
    }
    if ($sync_settings['stripe_charge_failed_to_qbo_note']) {
        $needed_events[] = 'charge.failed';
    }
    if ($sync_settings['stripe_refund_created_to_qbo_refund_receipt']) {
        $needed_events[] = 'refund.created';
    }
    if ($sync_settings['stripe_credit_note_created_to_qbo_credit_memo']) {
        $needed_events[] = 'credit_note.created';
    }
    if ($sync_settings['stripe_product_created_to_qbo_item']) {
        $needed_events[] = 'product.created';
    }
    if ($sync_settings['stripe_quote_accepted_to_qbo_sales_order']) {
        $needed_events[] = 'quote.accepted';
    }
    
    // Find existing webhook for our endpoint
    $webhook_url = BASE_URL . '/stripe-webhook.php';
    $existing_webhook = null;
    
    foreach ($webhooks['data'] as $webhook) {
        if ($webhook['url'] === $webhook_url) {
            $existing_webhook = $webhook;
            break;
        }
    }
    
    if (empty($needed_events)) {
        // If no events needed, delete existing webhook if it exists
        if ($existing_webhook) {
            delete_stripe_webhook($existing_webhook['id'], $stripe_account['api_key']);
        }
        return true;
    }
    
    // Create or update webhook
    $webhook_data = [
        'url' => $webhook_url,
        'enabled_events' => $needed_events
    ];
    
    if ($existing_webhook) {
        // Update existing webhook
        $result = update_stripe_webhook($existing_webhook['id'], $webhook_data, $stripe_account['api_key']);
    } else {
        // Create new webhook
        $result = create_stripe_webhook($webhook_data, $stripe_account['api_key']);
    }
    
    return $result !== null;
}

/**
 * Get Stripe webhooks
 */
function get_stripe_webhooks($api_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/webhook_endpoints");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200 ? json_decode($response, true) : [];
}

/**
 * Create Stripe webhook
 */
function create_stripe_webhook($data, $api_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/webhook_endpoints");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION,
        "Content-Type: application/x-www-form-urlencoded"
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200 ? json_decode($response, true) : null;
}

/**
 * Update Stripe webhook
 */
function update_stripe_webhook($webhook_id, $data, $api_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/webhook_endpoints/" . $webhook_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION,
        "Content-Type: application/x-www-form-urlencoded"
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200 ? json_decode($response, true) : null;
}

/**
 * Delete Stripe webhook
 */
function delete_stripe_webhook($webhook_id, $api_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/webhook_endpoints/" . $webhook_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

/**
 * Get sync settings for the main user
 */
function get_sync_settings($user_id = 1) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM sync_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}


/**
 * Get authenticated QuickBooks client
 */
function get_quickbooks_client($user_id) {
    $qbo_account = get_quickbooks_account($user_id);
    if (!$qbo_account) {
        return false;
    }

    // Check if token needs refreshing
    if (strtotime($qbo_account['access_token_expires_at']) < time()) {
        if (!refresh_qbo_token_cron($user_id)) {
            return false;
        }
        // Refresh the account data after token refresh
        $qbo_account = get_quickbooks_account($user_id);
    }

    return [
        'realm_id' => $qbo_account['realm_id'],
        'access_token' => $qbo_account['access_token'],
        'environment' => QBO_ENVIRONMENT
    ];
}

?>