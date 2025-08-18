<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Delete session from database if cookie exists
if (isset($_COOKIE['session_token'])) {
    $stmt = $db->prepare("DELETE FROM sessions WHERE session_token = ?");
    $stmt->bind_param("s", $_COOKIE['session_token']);
    $stmt->execute();
    
    // Clear the session cookie
    setcookie('session_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: " . BASE_URL . "/login.php");
exit();
?>