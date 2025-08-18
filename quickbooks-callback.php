<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

date_default_timezone_set('America/New_York');

// Handle errors
if (isset($_GET['error'])) {
    $_SESSION['error'] = "QuickBooks connection failed: " . $_GET['error'];
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// Verify state (CSRF protection)
if (!isset($_GET['state']) || !validate_csrf_token($_GET['state'])) {
    $_SESSION['error'] = "Invalid state parameter";
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// Exchange authorization code for tokens
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $realm_id = $_GET['realmId'];
    
    $token_url = QBO_ENVIRONMENT === 'sandbox'
        ? 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
        : 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    
    $auth_header = 'Basic ' . base64_encode(QBO_CLIENT_ID . ':' . QBO_CLIENT_SECRET);
    
    $data = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => QBO_REDIRECT_URI
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
    
    if ($http_code !== 200) {
        $_SESSION['error'] = "Failed to get access token from QuickBooks";
        header("Location: " . BASE_URL . "/dashboard.php");
        exit();
    }
    
    $token_data = json_decode($response, true);
    
    // Calculate expiration times
 // Get current time in UTC (QuickBooks uses UTC)
$now = time();

// Calculate expiration timestamps
$access_token_expires = $now + $token_data['expires_in'];
$refresh_token_expires = $now + $token_data['x_refresh_token_expires_in'];
    
    // Verify the user exists before proceeding
    $user_check = $db->prepare("SELECT id FROM users WHERE id = ?");
    $user_check->bind_param("i", $_SESSION['user_id']);
    $user_check->execute();
    $user_result = $user_check->get_result();
    
    if ($user_result->num_rows === 0) {
        $_SESSION['error'] = "User account not found. Please login again.";
        header("Location: " . BASE_URL . "/login.php");
        exit();
    }
    
    try {
        // Begin transaction for atomic operations
        $db->begin_transaction();
        
        if (has_quickbooks_account($_SESSION['user_id'])) {
            $stmt = $db->prepare("UPDATE quickbooks_accounts SET 
                realm_id = ?,
                access_token = ?,
                refresh_token = ?,
                access_token_expires_at = FROM_UNIXTIME(?),
                refresh_token_expires_at = FROM_UNIXTIME(?),
                updated_at = NOW()
                WHERE user_id = ?");
                
            $stmt->bind_param(
                "sssssi",
                $realm_id,
                $token_data['access_token'],
                $token_data['refresh_token'],
                $access_token_expires,
                $refresh_token_expires,
                $_SESSION['user_id']
            );
        } else {
            $stmt = $db->prepare("INSERT INTO quickbooks_accounts (
                user_id, realm_id, access_token, refresh_token, 
                access_token_expires_at, refresh_token_expires_at
                ) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))");
                
            $stmt->bind_param(
                "isssii",
                $_SESSION['user_id'],
                $realm_id,
                $token_data['access_token'],
                $token_data['refresh_token'],
                $access_token_expires,
                $refresh_token_expires
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Database operation failed: " . $stmt->error);
        }
        
        $db->commit();
        $_SESSION['success'] = "QuickBooks connection established successfully!";
    } catch (Exception $e) {
        $db->rollback();
        error_log("QuickBooks connection error: " . $e->getMessage());
        $_SESSION['error'] = "Failed to save QuickBooks connection. Please try again.";
    }
    
    header("Location: " . BASE_URL . "/dashboard.php");
    exit();
}

// If no code parameter
$_SESSION['error'] = "No authorization code received from QuickBooks";
header("Location: " . BASE_URL . "/dashboard.php");
exit();
?>