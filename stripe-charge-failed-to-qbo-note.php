<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/stripe-invoice-created-to-qbo-invoice.php'; // Include the file with customer functions

function process_stripe_charge_failed_to_qbo_note($event, $user_id) {
    global $db;
    
    error_log("Starting charge.failed processing for event: " . $event['id']);
    $history_id = log_sync_history($user_id, 'charge.failed', $event['id'], 'pending', 'Processing started', $event);
    
    try {
        $charge = $event['data']['object'];
        $charge_id = $charge['id'];
        error_log("Processing charge ID: $charge_id");
        
        // 1. Check if already processed
        error_log("Checking if charge already processed...");
        $stmt = $db->prepare("SELECT id FROM charge_failure_logs WHERE user_id = ? AND stripe_charge_id = ?");
        $stmt->bind_param("is", $user_id, $charge_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            error_log("Charge already processed, skipping");
            update_sync_history($history_id, 'skipped', 'Charge failure already logged');
            return;
        }

        // 2. Get accounts
        error_log("Getting Stripe and QBO accounts...");
        $stripe_account = get_stripe_account($user_id);
        $qbo_account = get_quickbooks_account($user_id);
        
        if (!$stripe_account) {
            throw new Exception("Stripe account not connected");
        }
        if (!$qbo_account) {
            throw new Exception("QuickBooks account not connected");
        }
        
        // 3. Get customer details
        error_log("Extracting customer details from charge...");
        $customer_email = $charge['billing_details']['email'] ?? null;
        $customer_name = $charge['billing_details']['name'] ?? 'Unknown Customer';
        
        error_log("Customer details - Name: $customer_name, Email: " . ($customer_email ?? 'none'));
        
        if (empty($customer_email)) {
            // Try to get email from receipt_email if billing_details email is empty
            $customer_email = $charge['receipt_email'] ?? null;
            error_log("Trying receipt_email: " . ($customer_email ?? 'none'));
            
            if (empty($customer_email)) {
                throw new Exception("Customer email is required but missing");
            }
        }

        // 4. Find or create customer (using function from invoice file)
        error_log("Finding or creating QBO customer...");
        $qbo_customer_id = find_or_create_qbo_customer($customer_name, $customer_email, $user_id);
        if (!$qbo_customer_id) {
            throw new Exception("Failed to find or create customer");
        }
        error_log("Using QBO customer ID: $qbo_customer_id");

        // 5. Prepare note content
        error_log("Preparing note content...");
        $note_content = "Stripe Charge Failed\n";
        $note_content .= "-------------------\n";
        $note_content .= "Date: " . date('Y-m-d H:i:s', $charge['created']) . "\n";
        $note_content .= "Amount: " . ($charge['amount'] / 100) . " " . strtoupper($charge['currency']) . "\n";
        $note_content .= "Failure Reason: " . $charge['failure_message'] . "\n";
        $note_content .= "Failure Code: " . $charge['failure_code'] . "\n";
        $note_content .= "Charge ID: " . $charge_id . "\n";
        
        if (!empty($charge['payment_method_details']['card']['last4'])) {
            $note_content .= "Card: **** **** **** " . $charge['payment_method_details']['card']['last4'] . "\n";
        }

        // 6. Get existing customer to append note
        error_log("Fetching existing customer record...");
        $customer_result = make_qbo_api_request($user_id, '/customer/' . $qbo_customer_id, 'GET');
        
        if ($customer_result['code'] !== 200 || !isset($customer_result['body']['Customer'])) {
            error_log("Failed to fetch customer. Response: " . json_encode($customer_result));
            throw new Exception("Failed to fetch customer details");
        }
        
        $customer = $customer_result['body']['Customer'];
        error_log("Successfully fetched customer record");

        // 7. Update customer notes
        error_log("Preparing customer update...");
        $existing_notes = $customer['Notes'] ?? '';
        $updated_notes = trim($existing_notes . "\n\n" . $note_content);
        
        $update_data = [
            'Id' => $qbo_customer_id,
            'sparse' => true, // Only update the fields we specify
            'Notes' => $updated_notes
        ];
        
        error_log("Sending customer update to QBO...");
        $update_result = make_qbo_api_request($user_id, '/customer', 'POST', $update_data);
        
        // 8. Log the failure regardless of note update success
        error_log("Logging charge failure in database...");
        $stmt = $db->prepare("INSERT INTO charge_failure_logs 
                            (user_id, stripe_charge_id, failure_message, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $charge_id, $charge['failure_message']);
        $stmt->execute();
        
        // 9. Check update result
        if ($update_result['code'] !== 200 || !isset($update_result['body']['Customer']['Id'])) {
            error_log("Note update failed. Response: " . json_encode($update_result));
            update_sync_history($history_id, 'partial_success', 'Failed charge logged but note update failed', [
                'qbo_response' => $update_result
            ]);
            return;
        }
        
        error_log("Successfully updated customer notes");
        update_sync_history($history_id, 'success', 'Charge failure note added to customer', [
            'qbo_customer_id' => $qbo_customer_id,
            'stripe_charge_id' => $charge_id,
            'note_content' => $note_content
        ]);
        
    } catch (Exception $e) {
        error_log("Error processing charge.failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'charge_data' => $charge ?? null
        ]);
    }
}