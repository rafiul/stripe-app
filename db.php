<?php
require_once __DIR__ . '/config.php';

// Create database connection
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Set charset
$db->set_charset("utf8mb4");
?>