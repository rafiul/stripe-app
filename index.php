<?php
// Redirect to onboarding if not configured, or to login if configured
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    
    // Check if database connection is working
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            throw new Exception("Database connection failed");
        }
        
        // Check if admin user exists
        $result = $db->query("SELECT id FROM users WHERE id = 1");
        if ($result->num_rows > 0) {
            header("Location: " . BASE_URL . "/login.php");
            exit();
        }
    } catch (Exception $e) {
        // Continue to onboarding if any check fails
    }
}

header("Location: onboarding.php");
exit();
?>