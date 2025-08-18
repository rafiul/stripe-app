<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Retrieve the request's body and parse it as JSON
$payload = @file_get_contents('php://input');
$event = json_decode($payload, true);

// Verify this is a valid Stripe webhook
if (!isset($event['type']) || !isset($event['id'])) {
    http_response_code(400);
    die("Invalid event data");
}

// Log the event for debugging
error_log("Stripe Webhook Received: " . $event['type'] . " (ID: " . $event['id'] . ")");

// Get the single Stripe account
$stripe_account = get_single_stripe_account();

if (!$stripe_account) {
    http_response_code(500);
    die("Stripe account not configured");
}


// Get sync settings (assuming you have them in a table)
$sync_settings = get_sync_settings(1); // Use user_id 1 or your main user

// Log the event for debugging
error_log(print_r($sync_settings) );

// Process based on event type

switch ($event['type']) {
    case 'invoice.paid':
        if ($sync_settings && $sync_settings['stripe_invoice_paid_to_qbo_payment']) {
            require_once __DIR__ . '/stripe-invoice-paid-to-qbo-payment.php';
            process_stripe_invoice_paid_to_qbo_payment($event, 1);
        }
        break;
        
    case 'invoice.finalized':
        echo 'Success';
        if ($sync_settings && $sync_settings['stripe_invoice_created_to_qbo_invoice']) {
            require_once __DIR__ . '/stripe-invoice-created-to-qbo-invoice.php';
            process_stripe_invoice_created_to_qbo_invoice($event, 1);
        }
        break;
        
    case 'payment_intent.succeeded':
        if ($sync_settings && $sync_settings['stripe_payment_intent_succeeded_to_qbo_sales_receipt']) {
            require_once __DIR__ . '/stripe-payment-intent-succeeded-to-qbo-sales-receipt.php';
            process_stripe_payment_intent_succeeded_to_qbo_sales_receipt($event, 1);
        }
        break;
        
    case 'charge.succeeded':
        if ($sync_settings && $sync_settings['stripe_charge_succeeded_to_qbo_payment']) {
            require_once __DIR__ . '/stripe-charge-succeeded-to-qbo-payment.php';
            process_stripe_charge_succeeded_to_qbo_payment($event, 1);
        }
        break;
        
    case 'charge.failed':
        if ($sync_settings && $sync_settings['stripe_charge_failed_to_qbo_note']) {
            require_once __DIR__ . '/stripe-charge-failed-to-qbo-note.php';
            process_stripe_charge_failed_to_qbo_note($event, 1);
        }
        break;
        
    case 'refund.created':
        if ($sync_settings && $sync_settings['stripe_refund_created_to_qbo_refund_receipt']) {
            require_once __DIR__ . '/stripe-refund-created-to-qbo-refund-receipt.php';
            process_stripe_refund_created_to_qbo_refund_receipt($event, 1);
        }
        break;
        
    case 'credit_note.created':
        if ($sync_settings && $sync_settings['stripe_credit_note_created_to_qbo_credit_memo']) {
            require_once __DIR__ . '/stripe-credit-note-created-to-qbo-credit-memo.php';
            process_stripe_credit_note_created_to_qbo_credit_memo($event, 1);
        }
        break;
        
    case 'product.created':
        if ($sync_settings && $sync_settings['stripe_product_created_to_qbo_item']) {
            require_once __DIR__ . '/stripe-product-created-to-qbo-item.php';
            process_stripe_product_created_to_qbo_item($event, 1);
        }
        break;
        
    case 'quote.accepted':
        if ($sync_settings && $sync_settings['stripe_quote_accepted_to_qbo_sales_order']) {
            require_once __DIR__ . '/stripe-quote-accepted-to-qbo-sales-order.php';
            process_stripe_quote_accepted_to_qbo_sales_order($event, 1);
        }
        break;
        
    default:
        // Event type not handled
        http_response_code(200);
        die("Event type not handled");
}

http_response_code(200);
echo "Webhook processed successfully";

/**
 * Get the single Stripe account from database
 */
function get_single_stripe_account() {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM stripe_accounts LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    $account = $result->fetch_assoc();
    
    // Decrypt the API key
    $account['api_key'] = decrypt_api_key($account['api_key']);
    
    return $account;
}

/**
 * Verify a Stripe event by fetching it from the API
 */
function verify_stripe_event($event_id, $api_key) {
    if (empty($api_key)) {
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/events/" . $event_id);
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