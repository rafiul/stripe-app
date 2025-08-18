<?php
require_once __DIR__ . '/functions.php';

function process_stripe_refund_created_to_qbo_refund_receipt($event, $user_id) {
    global $db;
    
    $history_id = log_sync_history($user_id, 'refund.created', $event['id'], 'pending', 'Processing started', $event);
    error_log("Starting refund receipt creation process for event: " . $event['id']);
    
    try {
        $refund = $event['data']['object'];
        $refund_id = $refund['id'];
        $charge_id = $refund['charge'];
        $payment_intent_id = $refund['payment_intent'];
        $amount = $refund['amount'] / 100;
        $currency = strtoupper($refund['currency']);
        
        error_log("Refund details:");
        error_log(" - ID: $refund_id");
        error_log(" - Amount: $amount");
        error_log(" - Currency: $currency");
        error_log(" - Charge ID: $charge_id");
        error_log(" - Payment Intent ID: $payment_intent_id");
        
        // Check if already processed
        $stmt = $db->prepare("SELECT qbo_refund_receipt_id FROM refund_mappings WHERE user_id = ? AND stripe_refund_id = ?");
        $stmt->bind_param("is", $user_id, $refund_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            update_sync_history($history_id, 'skipped', 'Refund already processed', [
                'qbo_refund_receipt_id' => $row['qbo_refund_receipt_id']
            ]);
            error_log("Refund already processed. QBO Refund Receipt ID: " . $row['qbo_refund_receipt_id']);
            return;
        }
        
        // Get accounts
        $stripe_account = get_stripe_account($user_id);
        $qbo_account = get_quickbooks_account($user_id);
        if (!$stripe_account || !$qbo_account) {
            throw new Exception("Stripe or QuickBooks account not connected");
        }
        
        error_log("Accounts verified successfully");
        
        // Get payment intent details
        $payment_intent = get_stripe_payment_intent($stripe_account['api_key'], $payment_intent_id);
        if (!$payment_intent) {
            throw new Exception("Failed to fetch payment intent details");
        }
        
        error_log("Payment intent retrieved successfully");
        
        // Get charge details
        $charge = get_stripe_charge($stripe_account['api_key'], $charge_id);
        if (!$charge) {
            throw new Exception("Failed to fetch charge details");
        }
        
        // Get customer details - first try from payment intent
        $customer_id = $payment_intent['customer'] ?? null;
        $customer_email = '';
        $customer_name = 'Customer';
        
        if ($customer_id) {
            error_log("Fetching customer details for ID: $customer_id");
            $customer = get_stripe_customer($customer_id, $stripe_account['api_key']);
            if ($customer) {
                $customer_email = $customer['email'] ?? '';
                $customer_name = $customer['name'] ?? ($customer_email ?: "Customer $customer_id");
                error_log("Customer details from Stripe:");
                error_log(" - Name: $customer_name");
                error_log(" - Email: $customer_email");
            }
        }
        
        // If still no email, try to get from charge
        if (empty($customer_email)) {
            error_log("No customer email from payment intent, trying charge");
            $customer_email = $charge['billing_details']['email'] ?? '';
            if (!empty($charge['billing_details']['name'])) {
                $customer_name = $charge['billing_details']['name'];
            }
            error_log("Customer details from charge:");
            error_log(" - Name: $customer_name");
            error_log(" - Email: $customer_email");
        }
        
        // If still no email, use a default with the customer ID
        if (empty($customer_email)) {
            if ($customer_id) {
                $customer_email = "customer_$customer_id@stripe.com";
                error_log("Using generated email: $customer_email");
            } else {
                throw new Exception("Customer information could not be determined");
            }
        }
        
        // Find or create customer in QuickBooks
        $qbo_customer_id = find_or_create_qbo_customer($customer_name, $customer_email, $user_id);
        if (!$qbo_customer_id) {
            throw new Exception("Failed to find or create customer in QuickBooks");
        }
        
        error_log("Using QBO customer ID: " . $qbo_customer_id);
        
        // Get sync settings to check for tax exempt ID
        $sync_settings = get_sync_settings($user_id);
        $tax_exempt_id = $sync_settings['tax_exempt_id'] ?? null;
        
        // Check if this is a US account or international account
        $is_us_account = check_if_us_quickbooks_account($user_id);
        
        error_log("Account type: " . ($is_us_account ? 'US' : 'International'));
        error_log("Tax exempt ID from settings: " . ($tax_exempt_id ?: 'None'));
        
        // Prepare line items - we'll use a single "Refund" product
        $product_name = 'Refund';
        $item_id = find_or_create_qbo_refund_product($user_id);
        if (!$item_id) {
            throw new Exception("Failed to create refund product in QuickBooks");
        }
        
        // Build line item with or without tax based on account type
        $line_item = [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => $amount,
            'Description' => $product_name,
            'SalesItemLineDetail' => [
                'ItemRef' => ['value' => $item_id],
                'UnitPrice' => $amount,
                'Qty' => 1
            ]
        ];
        
        // Add tax information based on account type
        if ($is_us_account) {
            // For US accounts, use NON (non-taxable) for refunds
            $line_item['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'NON'];
            error_log("Applied NON tax code for US account");
        } else {
            // For non-US accounts, use the configured tax exempt rate
            if ($tax_exempt_id) {
                $tax_code_ref = get_tax_code_ref_from_tax_rate_id($tax_exempt_id, $user_id);
                if ($tax_code_ref) {
                    $line_item['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code_ref];
                    error_log("Applied tax code ref: $tax_code_ref for non-US account");
                } else {
                    error_log("Warning: Could not find tax code ref for tax rate ID: $tax_exempt_id");
                    // Fallback to a common tax-exempt code
                    $line_item['SalesItemLineDetail']['TaxCodeRef'] = ['value' => '1'];
                }
            } else {
                error_log("Warning: Non-US account detected but no tax exempt ID configured in sync settings");
                throw new Exception("Tax exempt rate must be configured for non-US QuickBooks accounts. Please set it in sync settings.");
            }
        }
        
        $line_items = [$line_item];
        
        error_log("Using refund line item with product: $product_name");
        
        // Get payment method from the original charge
        $payment_method_type = 'card'; // Default to card
        if (isset($charge['payment_method_details']['type'])) {
            $payment_method_type = $charge['payment_method_details']['type'];
        } elseif (isset($payment_intent['payment_method_types'][0])) {
            $payment_method_type = $payment_intent['payment_method_types'][0];
        }
        
        $payment_method_id = get_qbo_payment_method($payment_method_type, $user_id);
        error_log("Using payment method: $payment_method_type (QBO ID: $payment_method_id)");
        
        // Get deposit account
        $deposit_account_id = get_qbo_account_id('Undeposited Funds', $user_id) ?: '1';
        error_log("Using deposit account ID: $deposit_account_id");
        
        // Create refund receipt in QuickBooks
        $refund_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'TotalAmt' => $amount,
            'TxnDate' => date('Y-m-d', $refund['created']),
            'PaymentMethodRef' => ['value' => $payment_method_id],
            'PaymentRefNum' => $refund_id,
            'PrivateNote' => 'Stripe Refund for Charge: ' . $charge_id . ' (Payment Intent: ' . $payment_intent_id . ')',
            'Line' => $line_items,
            'DepositToAccountRef' => ['value' => $deposit_account_id]
        ];
        
        // Add currency for non-USD transactions
        if ($currency !== 'USD') {
            $refund_data['CurrencyRef'] = ['value' => $currency];
        }
        
        error_log("Prepared refund receipt data:");
        error_log(print_r($refund_data, true));
        
        $result = make_qbo_api_request($user_id, '/refundreceipt', 'POST', $refund_data);
        
        error_log("QBO API response:");
        error_log(print_r($result, true));
        
        if ($result['code'] !== 200 || !isset($result['body']['RefundReceipt']['Id'])) {
            $error_msg = "Failed to create refund receipt in QuickBooks";
            if (isset($result['body']['Fault']['Error'][0]['Message'])) {
                $error_msg .= ": " . $result['body']['Fault']['Error'][0]['Message'];
            }
            throw new Exception($error_msg);
        }
        
        $qbo_refund_receipt_id = $result['body']['RefundReceipt']['Id'];
        error_log("Refund receipt created successfully in QBO. Refund Receipt ID: " . $qbo_refund_receipt_id);
        
        // Store mapping
        $stmt = $db->prepare("INSERT INTO refund_mappings (user_id, stripe_refund_id, qbo_refund_receipt_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $refund_id, $qbo_refund_receipt_id);
        if (!$stmt->execute()) {
            error_log("Failed to save refund mapping: " . $stmt->error);
        }
        
        // Log success
        update_sync_history($history_id, 'success', 'Refund receipt created in QuickBooks', [
            'qbo_refund_receipt_id' => $qbo_refund_receipt_id,
            'stripe_refund_id' => $refund_id,
            'qbo_response' => $result['body']
        ]);
        
    } catch (Exception $e) {
        error_log("Refund receipt creation failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'refund_data' => $refund_data ?? null,
            'qbo_response' => $result['body'] ?? null
        ]);
    }
}

/**
 * Check if the QuickBooks account is a US account
 */
function check_if_us_quickbooks_account($user_id) {
    // Get company info to determine country
    $result = make_qbo_api_request($user_id, '/companyinfo/1', 'GET');
    
    error_log("Company info API response: " . print_r($result, true));
    
    if ($result['code'] === 200) {
        // Try different possible locations for country info
        $country = null;
        
        // Check in QueryResponse first
        if (isset($result['body']['QueryResponse']['CompanyInfo'][0]['Country'])) {
            $country = $result['body']['QueryResponse']['CompanyInfo'][0]['Country'];
        }
        // Check directly in CompanyInfo
        elseif (isset($result['body']['CompanyInfo']['Country'])) {
            $country = $result['body']['CompanyInfo']['Country'];
        }
        // Check in CompanyInfo array
        elseif (isset($result['body']['CompanyInfo'][0]['Country'])) {
            $country = $result['body']['CompanyInfo'][0]['Country'];
        }
        // Check for CompanyAddr country
        elseif (isset($result['body']['QueryResponse']['CompanyInfo'][0]['CompanyAddr']['Country'])) {
            $country = $result['body']['QueryResponse']['CompanyInfo'][0]['CompanyAddr']['Country'];
        }
        elseif (isset($result['body']['CompanyInfo']['CompanyAddr']['Country'])) {
            $country = $result['body']['CompanyInfo']['CompanyAddr']['Country'];
        }
        
        if ($country) {
            error_log("QuickBooks company country: $country");
            return ($country === 'US' || $country === 'USA' || $country === 'United States');
        }
        
        // Alternative approach: Check base URL or make a test tax query
        error_log("Country not found in company info, trying alternative detection");
        return detect_us_account_by_tax_codes($user_id);
    }
    
    // If we can't determine, try alternative method
    error_log("Could not get company info, trying alternative detection");
    return detect_us_account_by_tax_codes($user_id);
}

/**
 * Alternative method to detect US account by checking available tax codes
 */
function detect_us_account_by_tax_codes($user_id) {
    // Query for tax codes - US accounts typically have NON and TAX
    $result = make_qbo_api_request($user_id, '/query?query=' . urlencode("SELECT * FROM TaxCode"));
    
    error_log("Tax codes query result: " . print_r($result, true));
    
    if ($result['code'] === 200 && isset($result['body']['QueryResponse']['TaxCode'])) {
        $tax_codes = $result['body']['QueryResponse']['TaxCode'];
        $code_names = array_column($tax_codes, 'Name');
        
        error_log("Available tax codes: " . implode(', ', $code_names));
        
        // US accounts typically have NON and TAX codes
        if (in_array('NON', $code_names) && in_array('TAX', $code_names)) {
            error_log("Detected US account based on NON/TAX tax codes");
            return true;
        }
        
        // Check for GST codes which indicate non-US
        $gst_indicators = ['GST', 'VAT', 'HST', 'PST', 'QST'];
        foreach ($gst_indicators as $indicator) {
            if (in_array($indicator, $code_names)) {
                error_log("Detected non-US account based on $indicator tax code");
                return false;
            }
        }
    }
    
    // Default to US if we can't determine (safer for US customers)
    error_log("Could not determine account type, defaulting to US");
    return true;
}

/**
 * Get tax code reference from tax rate ID
 */
function get_tax_code_ref_from_tax_rate_id($tax_rate_id, $user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT tax_code_ref FROM qb_tax_rates WHERE tax_rate_id = ? LIMIT 1");
    $stmt->bind_param("s", $tax_rate_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['tax_code_ref'];
    }
    
    return null;
}

/**
 * Find or create the standard Refund product in QuickBooks
 */
function find_or_create_qbo_refund_product($user_id) {
    $product_name = 'Refund';
    
    // Try to find by name first
    $query = "SELECT * FROM Item WHERE Name = '" . addslashes($product_name) . "'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Item'])) {
        return $result['body']['QueryResponse']['Item'][0]['Id'];
    }
    
    // Create new refund product
    $income_account_id = get_qbo_account_id('Sales of Product Income', $user_id);
    if (!$income_account_id) {
        $income_account_id = '1'; // Fallback to default account
    }
    
    $item_data = [
        'Name' => $product_name,
        'Type' => 'Service',
        'IncomeAccountRef' => ['value' => $income_account_id],
        'Taxable' => false, // Make refund non-taxable
        'Description' => 'Refund for returned products or services'
    ];
    
    $result = make_qbo_api_request($user_id, '/item', 'POST', $item_data);
    
    if ($result['code'] !== 200 || !isset($result['body']['Item']['Id'])) {
        error_log("Failed to create refund product: " . print_r($result, true));
        return false;
    }
    
    return $result['body']['Item']['Id'];
}

/**
 * Find or create customer in QuickBooks
 */
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
    $name1 = $name . "(". $email. ")";
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

/**
 * Get QBO payment method based on Stripe payment method type
 */
function get_qbo_payment_method($stripe_payment_method_type, $user_id) {
    $payment_method_map = [
        'card' => 'Credit Card',
        'ach_debit' => 'ACH',
        'sepa_debit' => 'Bank Transfer',
        'acss_debit' => 'Bank Transfer',
        'us_bank_account' => 'ACH',
        'link' => 'Credit Card'
    ];
    
    $method_name = $payment_method_map[strtolower($stripe_payment_method_type)] ?? 'Credit Card';
    
    // Try to find the payment method in QBO
    $query = "SELECT * FROM PaymentMethod WHERE Name = '$method_name'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query, 'GET');
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['PaymentMethod'])) {
        return $result['body']['QueryResponse']['PaymentMethod'][0]['Id'];
    }
    
    // If not found, return default payment method ID (1 is usually Cash in QBO)
    return '1';
}

/**
 * Get QBO account ID by name
 */
function get_qbo_account_id($account_name, $user_id) {
    $query = "SELECT * FROM Account WHERE Name = '$account_name'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Account'])) {
        return $result['body']['QueryResponse']['Account'][0]['Id'];
    }
    
    return false;
}

/**
 * Get Stripe charge details
 */
function get_stripe_charge($api_key, $charge_id) {
    error_log("Fetching charge details for ID: $charge_id");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/charges/" . $charge_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch charge. HTTP Code: $http_code");
        return false;
    }
    
    $charge = json_decode($response, true);
    error_log("Successfully retrieved charge details");
    return $charge;
}

/**
 * Get Stripe payment intent
 */
function get_stripe_payment_intent($api_key, $payment_intent_id) {
    error_log("Fetching payment intent details for ID: $payment_intent_id");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/payment_intents/" . $payment_intent_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch payment intent. HTTP Code: $http_code");
        return false;
    }
    
    $payment_intent = json_decode($response, true);
    error_log("Successfully retrieved payment intent details");
    return $payment_intent;
}
?>