<?php
// Application configuration
define('APP_NAME', 'Stripe Integration App');
define('BASE_URL', 'http://localhost/stripe-app');
define('ENCRYPTION_KEY', '98170b14d884c317784b5570975c8e45e19d6b63f6c94d0c08cf2bd2a9b3bbc9');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'stripe_app');
define('DB_USER', 'root');
define('DB_PASS', '');

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
?>