<?php
function process_stripe_charge_succeeded_to_qbo_payment($event, $user_id) {
    global $db;
    
    $history_id = log_sync_history($user_id, 'charge.succeeded', $event['id'], 'pending', 'Processing started', $event);
    error_log("Starting charge processing for Stripe charge: " . $event['id']);
    
    try {
        $charge = $event['data']['object'];
        $charge_id = $charge['id'];
        $payment_intent_id = $charge['payment_intent'] ?? null;
        
        // Check if already processed
        $stmt = $db->prepare("SELECT qbo_payment_id FROM charge_mappings WHERE user_id = ? AND stripe_charge_id = ?");
        $stmt->bind_param("is", $user_id, $charge_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            update_sync_history($history_id, 'skipped', 'Charge already processed');
            return;
        }

        // Get accounts
        $stripe_account = get_stripe_account($user_id);
        $qbo_account = get_quickbooks_account($user_id);
        if (!$stripe_account || !$qbo_account) {
            throw new Exception("Accounts not connected");
        }
        
        // Get customer details
        $customer_email = $charge['billing_details']['email'] ?? '';
        $customer_name = $charge['billing_details']['name'] ?? $customer_email;
        
        if (empty($customer_email)) {
            throw new Exception("Customer email is required but missing");
        }
        
        // Find or create customer
        $qbo_customer_id = find_or_create_qbo_customer($customer_name, $customer_email, $user_id);
        if (!$qbo_customer_id) {
            throw new Exception("Failed to find or create customer");
        }
        
        // Try to get product details from payment intent if available
        $product_name = 'Payment';
        $line_items = [];
        
        if ($payment_intent_id) {
            $payment_intent = get_stripe_payment_intent($stripe_account['api_key'], $payment_intent_id);
            
            if ($payment_intent && isset($payment_intent['id'])) {
                $checkout_session = get_stripe_checkout_session($payment_intent_id, $stripe_account['api_key']);
                
                if ($checkout_session && isset($checkout_session['id'])) {
                    $line_items_response = get_stripe_checkout_session_line_items($checkout_session['id'], $stripe_account['api_key']);
                    
                    if ($line_items_response && isset($line_items_response['data'])) {
                        foreach ($line_items_response['data'] as $line_item) {
                            $product_name = $line_item['description'] ?? 'Product';
                            break;
                        }
                    }
                }
            }
        }
        
        if (empty($product_name) && !empty($charge['description'])) {
            $product_name = $charge['description'];
        }
        
        // Find or create product in QuickBooks
        $item_id = find_or_create_qbo_item($product_name, null, $user_id);
        if (!$item_id) {
            throw new Exception("Failed to create product in QuickBooks");
        }
        
        // Create invoice in QuickBooks
        $invoice_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'Line' => [
                [
                    'DetailType' => 'SalesItemLineDetail',
                    'Amount' => $charge['amount'] / 100,
                    'Description' => $product_name,
                    'SalesItemLineDetail' => [
                        'ItemRef' => ['value' => $item_id],
                        'UnitPrice' => $charge['amount'] / 100,
                        'Qty' => 1
                    ]
                ]
            ],
            'TxnDate' => date('Y-m-d', $charge['created']),
            'DueDate' => date('Y-m-d', $charge['created']),
            'TotalAmt' => $charge['amount'] / 100,
            'PrivateNote' => 'Stripe Charge: ' . $charge_id,
            'AllowOnlineACHPayment' => false,
            'AllowOnlineCreditCardPayment' => false
        ];
        
        error_log("Creating QBO invoice with data: " . print_r($invoice_data, true));
        
        $invoice_result = make_qbo_api_request($user_id, '/invoice', 'POST', $invoice_data);
        error_log("QBO Invoice API response: " . print_r($invoice_result, true));
        
        if ($invoice_result['code'] !== 200 || !isset($invoice_result['body']['Invoice']['Id'])) {
            $error_msg = "Failed to create invoice in QuickBooks";
            if (isset($invoice_result['body']['fault']['error'][0]['message'])) {
                $error_msg .= ": " . $invoice_result['body']['fault']['error'][0]['message'];
            }
            throw new Exception($error_msg);
        }
        
        $qbo_invoice_id = $invoice_result['body']['Invoice']['Id'];
        error_log("Invoice created successfully in QBO. Invoice ID: " . $qbo_invoice_id);
        
        // Create payment in QuickBooks for the invoice
        $payment_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'TotalAmt' => $charge['amount'] / 100,
            'TxnDate' => date('Y-m-d', $charge['created']),
            'PaymentRefNum' => substr('stripe_' . $charge_id, 0, 21), // Fixed: Ensure max 21 characters
            'PaymentMethodRef' => ['value' => get_qbo_payment_method_id('Stripe', $user_id)],
            'PrivateNote' => 'Stripe Charge: ' . $charge_id,
            'DepositToAccountRef' => ['value' => get_qbo_account_id('Undeposited Funds', $user_id)],
            'Line' => [
                [
                    'Amount' => $charge['amount'] / 100,
                    'LinkedTxn' => [
                        [
                            'TxnId' => $qbo_invoice_id,
                            'TxnType' => 'Invoice'
                        ]
                    ]
                ]
            ]
        ];
        
        error_log("Creating QBO payment with data: " . print_r($payment_data, true));
        
        $payment_result = make_qbo_api_request($user_id, '/payment', 'POST', $payment_data);
        error_log("QBO Payment API response: " . print_r($payment_result, true));
        
        if ($payment_result['code'] !== 200 || !isset($payment_result['body']['Payment']['Id'])) {
            $error_msg = "Failed to create payment in QuickBooks";
            if (isset($payment_result['body']['fault']['error'][0]['message'])) {
                $error_msg .= ": " . $payment_result['body']['fault']['error'][0]['message'];
            }
            throw new Exception($error_msg);
        }
        
        $qbo_payment_id = $payment_result['body']['Payment']['Id'];
        error_log("Payment created successfully in QBO. Payment ID: " . $qbo_payment_id);
        
        // Store mapping
        $stmt = $db->prepare("INSERT INTO charge_mappings 
                            (user_id, stripe_charge_id, qbo_payment_id, qbo_invoice_id, created_at) 
                            VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("issi", $user_id, $charge_id, $qbo_payment_id, $qbo_invoice_id);
        $stmt->execute();
        
        update_sync_history($history_id, 'success', 'Payment created in QuickBooks', [
            'qbo_payment_id' => $qbo_payment_id,
            'qbo_invoice_id' => $qbo_invoice_id,
            'stripe_charge_id' => $charge_id
        ]);
        
    } catch (Exception $e) {
        error_log("Payment creation failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'invoice_data' => $invoice_data ?? null,
            'payment_data' => $payment_data ?? null,
            'qbo_response' => $payment_result['body'] ?? $invoice_result['body'] ?? null
        ]);
    }
}

function get_stripe_payment_intent($api_key, $payment_intent_id) {
    if (empty($api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/payment_intents/" . $payment_intent_id;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch Payment Intent: " . $payment_intent_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

function get_stripe_checkout_session($payment_intent_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/checkout/sessions?payment_intent=" . urlencode($payment_intent_id);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripe_api_key,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch Checkout Session for Payment Intent: " . $payment_intent_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (empty($data['data'])) {
        error_log("No Checkout Session found for Payment Intent: " . $payment_intent_id);
        return null;
    }
    
    return $data['data'][0];
}

function get_stripe_checkout_session_line_items($checkout_session_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/checkout/sessions/" . $checkout_session_id . "/line_items";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripe_api_key,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch line items for Checkout Session: " . $checkout_session_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

function find_or_create_qbo_customer($name, $email, $user_id) {
    // First try to find by email
    if (!empty($email)) {
        $query = "SELECT * FROM Customer WHERE PrimaryEmailAddr = '$email'";
        $encoded_query = urlencode($query);
        $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query, 'GET');
        
        error_log("Customer lookup result: " . print_r($result, true)); 
        if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Customer'])) {
            return $result['body']['QueryResponse']['Customer'][0]['Id'];
        }
    }
    
    // Create new customer
    $name1 = $name . "(" . $email . ")";
    $customer_data = [
        'DisplayName' => substr($name1, 0, 100),
        'GivenName' => substr($name, 0, 25),
        'FamilyName' => substr($name, 0, 25),
        'PrimaryEmailAddr' => [
            'Address' => substr($email, 0, 100)
        ],
        'Notes' => 'Created via Stripe integration'
    ];
    
    $result = make_qbo_api_request($user_id, '/customer', 'POST', $customer_data);
    if ($result['code'] !== 200 || !isset($result['body']['Customer']['Id'])) {
        error_log("Failed to create customer: " . print_r($result, true));
        return false;
    }
    
    return $result['body']['Customer']['Id'];
}

function find_or_create_qbo_item($name, $sku, $user_id) {
    // Try to find by name first
    $query = "SELECT * FROM Item WHERE Name = '" . addslashes($name) . "'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Item'])) {
        return $result['body']['QueryResponse']['Item'][0]['Id'];
    }
    
    // Create new item
    $income_account_id = get_qbo_account_id('Sales of Product Income', $user_id);
    if (!$income_account_id) {
        $income_account_id = '1'; // Fallback to default account
    }
    
    $item_data = [
        'Name' => substr($name, 0, 100),
        'Type' => 'Service',
        'IncomeAccountRef' => ['value' => $income_account_id],
        'Taxable' => true
    ];
    
    if ($sku) {
        $item_data['Sku'] = substr($sku, 0, 31);
    }
    
    $result = make_qbo_api_request($user_id, '/item', 'POST', $item_data);
    if ($result['code'] !== 200 || !isset($result['body']['Item']['Id'])) {
        error_log("Failed to create item: " . print_r($result, true));
        return false;
    }
    
    return $result['body']['Item']['Id'];
}

function get_qbo_payment_method_id($name, $user_id) {
    // First try to find existing payment method
    $query = "SELECT * FROM PaymentMethod WHERE Name = '$name'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['PaymentMethod'])) {
        return $result['body']['QueryResponse']['PaymentMethod'][0]['Id'];
    }
    
    // Create new payment method if not found
    $payment_method_data = [
        'Name' => $name,
        'Type' => 'OTHER'
    ];
    
    $result = make_qbo_api_request($user_id, '/paymentmethod', 'POST', $payment_method_data);
    if ($result['code'] !== 200 || !isset($result['body']['PaymentMethod']['Id'])) {
        error_log("Failed to create payment method: " . print_r($result, true));
        return '1'; // Fallback to default payment method
    }
    
    return $result['body']['PaymentMethod']['Id'];
}

function get_qbo_account_id($name, $user_id) {
    $query = "SELECT * FROM Account WHERE Name = '$name'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Account'])) {
        return $result['body']['QueryResponse']['Account'][0]['Id'];
    }
    
    // Fallback account IDs based on common QBO setups
    $fallback_ids = [
        'Undeposited Funds' => '4',
        'Accounts Receivable' => '2',
        'Bank Account' => '1'
    ];
    
    return $fallback_ids[$name] ?? '1'; // Default to first account if not found
}