<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function start_secure_session() {
    // Only initialize if session isn't already active
    if (session_status() === PHP_SESSION_NONE) {
        // Set session name and parameters first
        $session_name = 'secure_session';
        $secure = true; // Only send cookies over HTTPS
        $httponly = true; // Prevent JavaScript access to session ID
        
        // Set session cookie parameters before starting session
        session_set_cookie_params([
            'lifetime' => 86400 * SESSION_EXPIRE_DAYS,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => 'Strict'
        ]);
        
        session_name($session_name);
        session_start();
        
        // Regenerate session ID to prevent fixation
        if (empty($_SESSION['initiated'])) {
            session_regenerate_id();
            $_SESSION['initiated'] = true;
        }
    }
    
    // Check for existing session token
    if (isset($_COOKIE['session_token'])) {
        validate_session($_COOKIE['session_token']);
    }
}

function validate_session($session_token) {
    global $db;
    
    // Clean expired sessions first
    clean_expired_sessions1();
    
    // Check database for valid session
    $stmt = $db->prepare("SELECT user_id FROM sessions WHERE session_token = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $session_token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $session = $result->fetch_assoc();
        $_SESSION['user_id'] = $session['user_id'];
        
        // Get user details
        $user_stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $session['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        
        if ($user_result->num_rows === 1) {
            $user = $user_result->fetch_assoc();
            $_SESSION['username'] = $user['username'];
        }
        
        // Update session expiration
        $new_expires = date('Y-m-d H:i:s', time() + (86400 * SESSION_EXPIRE_DAYS));
        $update_stmt = $db->prepare("UPDATE sessions SET expires_at = ? WHERE session_token = ?");
        $update_stmt->bind_param("ss", $new_expires, $session_token);
        $update_stmt->execute();
        
        // Refresh cookie
        setcookie('session_token', $session_token, time() + (86400 * SESSION_EXPIRE_DAYS), "/");
        
        return true;
    }
    
    return false;
}

function clean_expired_sessions1() {
    global $db;
    $db->query("DELETE FROM sessions WHERE expires_at <= NOW()");
}

function destroy_session() {
    global $db;
    
    if (isset($_COOKIE['session_token'])) {
        // Delete from database
        $stmt = $db->prepare("DELETE FROM sessions WHERE session_token = ?");
        $stmt->bind_param("s", $_COOKIE['session_token']);
        $stmt->execute();
        
        // Clear cookie
        setcookie('session_token', '', time() - 3600, "/");
    }
    
    // Unset all session variables
    $_SESSION = array();
    
    // Destroy session
    session_destroy();
}
?>