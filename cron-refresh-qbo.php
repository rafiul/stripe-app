<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// This script should only be run from command line or cron
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line");
}

// Get all QuickBooks accounts that need refreshing
// We'll refresh tokens that expire within the next 24 hours
$query = "SELECT user_id FROM quickbooks_accounts 
          WHERE refresh_token_expires_at > NOW() 
          AND access_token_expires_at < DATE_ADD(NOW(), INTERVAL 24 HOUR)";
$result = $db->query($query);

$success_count = 0;
$fail_count = 0;

while ($row = $result->fetch_assoc()) {
    if (refresh_qbo_token_cron($row['user_id'])) {
        $success_count++;
    } else {
        $fail_count++;
    }
}

echo "QuickBooks token refresh completed.\n";
echo "Successfully refreshed: $success_count\n";
echo "Failed to refresh: $fail_count\n";

// Log the results
error_log("QBO Token Refresh Cron: Success=$success_count, Fail=$fail_count");