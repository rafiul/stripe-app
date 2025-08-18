<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Require login
require_login();

// Redirect to QBO authorization
header("Location: " . get_qbo_auth_url());
exit();
?>