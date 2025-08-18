<?php
require_once __DIR__ . '/functions.php';

function process_stripe_credit_note_created_to_qbo_credit_memo($event, $user_id) {
    global $db;
    
    // Start logging this sync attempt
    $history_id = log_sync_history($user_id, 'credit_note.created', $event['id'], 'pending', 'Processing started', $event);
    error_log("Starting credit note creation process for event: " . $event['id']);
    
    try {
        // First check if this credit note has already been processed
        $stripe_credit_note_id = $event['data']['object']['id'];
        $stmt = $db->prepare("SELECT qbo_credit_memo_id FROM credit_note_mappings WHERE user_id = ? AND stripe_credit_note_id = ?");
        $stmt->bind_param("is", $user_id, $stripe_credit_note_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            update_sync_history($history_id, 'skipped', 'Credit note already processed', [
                'qbo_credit_memo_id' => $row['qbo_credit_memo_id']
            ]);
            error_log("Credit note already processed. QBO Credit Memo ID: " . $row['qbo_credit_memo_id']);
            return;
        }

        // Get the Stripe account
        $stripe_account = get_stripe_account($user_id);
        if (!$stripe_account) {
            throw new Exception("Stripe account not connected");
        }
        
        // Get the complete credit note details from Stripe
        $stripe_credit_note = fetch_stripe_credit_note($stripe_account['api_key'], $stripe_credit_note_id);
        if (!$stripe_credit_note) {
            throw new Exception("Failed to fetch Stripe credit note details");
        }
        
        error_log("Fetched Stripe credit note: " . print_r($stripe_credit_note, true));
        
        // Check if credit note has line items
        if (empty($stripe_credit_note['lines']['data'])) {
            throw new Exception("No line items in the credit note, nothing to process.");
        }
        
        // Get user's QuickBooks account
        $qbo_account = get_quickbooks_account($user_id);
        if (!$qbo_account) {
            throw new Exception("QuickBooks account not connected");
        }
        
        // Check if this is a USA account or international
        $is_usa_account = is_usa_quickbooks_account($user_id);
        
        // Get tax exempt/zero tax code from sync settings if not USA
        $tax_exempt_code = null;
        if (!$is_usa_account) {
            $tax_exempt_code = get_tax_exempt_code($user_id);
        }
        
        // Get invoice to get customer details
        $stripe_invoice = fetch_stripe_invoice($stripe_account['api_key'], $stripe_credit_note['invoice']);
        if (!$stripe_invoice) {
            throw new Exception("Failed to fetch associated invoice");
        }
        
        // Prepare customer data
        $customer_email = $stripe_invoice['customer_email'] ?? '';
        $customer_name = $stripe_invoice['customer_name'] ?? $customer_email;
        
        if (empty($customer_email)) {
            throw new Exception("Customer email is required but missing");
        }
        
        // Find or create customer in QuickBooks
        $qbo_customer_id = find_or_create_qbo_customer($customer_name, $customer_email, $user_id);
        if (!$qbo_customer_id) {
            throw new Exception("Failed to find or create customer in QuickBooks");
        }
        
        error_log("Using QBO customer ID: " . $qbo_customer_id);
        
        // Prepare line items for QuickBooks credit memo
        $line_items = [];
        $total_tax = 0;
        
        foreach ($stripe_credit_note['lines']['data'] as $line_item) {
            $product_name = $line_item['description'] ?? 'Credit';
            $product_sku = $line_item['price']['product'] ?? null;
            
            // Find or create product in QuickBooks
            $item_id = find_or_create_qbo_item($product_name, $product_sku, $user_id);
            if (!$item_id) {
                error_log("Skipping line item - failed to create product");
                continue;
            }
            
            // Calculate amounts
            $unit_price = $line_item['amount_excluding_tax'] / 100;
            $quantity = $line_item['quantity'] ?? 1;
            $line_total = $line_item['amount_excluding_tax'] / 100;
            $tax_amount = !empty($line_item['tax_amounts']) ? ($line_item['tax_amounts'][0]['amount'] / 100) : 0;
            $total_tax += $tax_amount;
            
            $line_item_data = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount' => $line_total,
                'Description' => $product_name,
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => $item_id],
                    'UnitPrice' => $unit_price,
                    'Qty' => $quantity
                ]
            ];
            
            // Handle tax based on account type
            if ($is_usa_account) {
                // USA account - use existing logic
                if ($tax_amount > 0) {
                    $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
                }
            } else {
                // International account - match tax rates and apply appropriate tax code
                $tax_code_ref = get_matching_tax_code($line_item, $user_id, $tax_exempt_code);
                if ($tax_code_ref) {
                    $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code_ref];
                }
            }
            
            $line_items[] = $line_item_data;
        }
        
        if (empty($line_items)) {
            throw new Exception("No valid line items could be processed");
        }
        
        // Create QuickBooks credit memo
        $credit_memo_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'Line' => $line_items,
            'TxnDate' => date('Y-m-d', $stripe_credit_note['created']),
            'TotalAmt' => ($stripe_credit_note['amount'] / 100),
            'PrivateNote' => 'Stripe Credit Note ID: ' . $stripe_credit_note['id'],
            'DocNumber' => $stripe_credit_note['number'] ?? null
        ];
        
        // Add tax details based on account type
        if ($is_usa_account) {
            // USA account - use existing tax structure
            $credit_memo_data['TxnTaxDetail'] = [
                'TxnTaxCodeRef' => ['value' => 'TAX'],
                'TotalTax' => $total_tax
            ];
        } else {
            // International account - only add tax details if there's tax
            if ($total_tax > 0) {
                // Find the appropriate tax code for the total transaction
                $total_tax_code = get_transaction_tax_code($stripe_credit_note, $user_id, $tax_exempt_code);
                if ($total_tax_code) {
                    $credit_memo_data['TxnTaxDetail'] = [
                        'TxnTaxCodeRef' => ['value' => $total_tax_code],
                        'TotalTax' => $total_tax
                    ];
                }
            }
        }
        
        error_log("Credit memo data prepared: " . print_r($credit_memo_data, true));
        
        $result = make_qbo_api_request($user_id, '/creditmemo', 'POST', $credit_memo_data);
        error_log("QBO API response: " . print_r($result, true));
        
        if ($result['code'] !== 200 || !isset($result['body']['CreditMemo']['Id'])) {
            $error_msg = "Failed to create credit memo in QuickBooks";
            if (isset($result['body']['fault']['error'][0]['message'])) {
                $error_msg .= ": " . $result['body']['fault']['error'][0]['message'];
            }
            throw new Exception($error_msg);
        }
        
        $qbo_credit_memo_id = $result['body']['CreditMemo']['Id'];
        error_log("Credit memo created successfully in QBO. Credit Memo ID: " . $qbo_credit_memo_id);
        
        // Store the mapping between Stripe and QBO credit memos
        $stmt = $db->prepare("INSERT INTO credit_note_mappings 
                            (user_id, stripe_credit_note_id, qbo_credit_memo_id, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $stripe_credit_note['id'], $qbo_credit_memo_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to save credit note mapping: " . $stmt->error);
        }
        
        // Log success
        update_sync_history($history_id, 'success', 'Credit memo created in QuickBooks', [
            'qbo_credit_memo_id' => $qbo_credit_memo_id,
            'stripe_credit_note_id' => $stripe_credit_note['id'],
            'qbo_response' => $result['body']
        ]);
        
    } catch (Exception $e) {
        error_log("Credit memo creation failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'credit_memo_data' => $credit_memo_data ?? null,
            'qbo_response' => $result['body'] ?? null
        ]);
    }
}

/**
 * Check if this is a USA QuickBooks account
 */
function is_usa_quickbooks_account($user_id) {
    // Get company info to determine country
    $result = make_qbo_api_request($user_id, '/companyinfo/1', 'GET');
    
    if ($result['code'] === 200 && isset($result['body']['CompanyInfo'])) {
        $company_info = $result['body']['CompanyInfo'];
        $country = $company_info['Country'] ?? '';
        return (strtoupper($country) === 'US' || strtoupper($country) === 'USA');
    }
    
    // Default to false for safety (assume international)
    return false;
}

/**
 * Get tax exempt code from sync settings
 */
function get_tax_exempt_code($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT tax_exempt_id FROM sync_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['tax_exempt_id'];
    }
    
    return null;
}

/**
 * Get matching tax code for a line item
 */
function get_matching_tax_code($line_item, $user_id, $tax_exempt_code) {
    global $db;
    
    // If no tax amounts, use tax exempt code
    if (empty($line_item['tax_amounts']) || $line_item['tax_amounts'][0]['amount'] == 0) {
        return $tax_exempt_code;
    }
    
    // Calculate tax rate from Stripe
    $tax_amount = $line_item['tax_amounts'][0]['amount'] / 100;
    $amount_excluding_tax = $line_item['amount_excluding_tax'] / 100;
    
    if ($amount_excluding_tax > 0) {
        $stripe_tax_rate = ($tax_amount / $amount_excluding_tax) * 100;
        
        // Find matching tax rate in database (with some tolerance for rounding differences)
        $stmt = $db->prepare("
            SELECT tax_code_ref 
            FROM qb_tax_rates 
            WHERE ABS(rate_value - ?) < 0.01 
            AND rate_type = 'Sales'
            ORDER BY ABS(rate_value - ?) ASC 
            LIMIT 1
        ");
        $stmt->bind_param("dd", $stripe_tax_rate, $stripe_tax_rate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['tax_code_ref'];
        }
    }
    
    // If no match found and there's tax, try to find a general tax code
    // or fallback to tax exempt
    return $tax_exempt_code;
}

/**
 * Get tax code for the overall transaction
 */
function get_transaction_tax_code($stripe_credit_note, $user_id, $tax_exempt_code) {
    global $db;
    
    // Calculate overall tax rate
    $total_amount_excluding_tax = 0;
    $total_tax = 0;
    
    foreach ($stripe_credit_note['lines']['data'] as $line_item) {
        $total_amount_excluding_tax += $line_item['amount_excluding_tax'];
        if (!empty($line_item['tax_amounts'])) {
            $total_tax += $line_item['tax_amounts'][0]['amount'];
        }
    }
    
    if ($total_tax == 0) {
        return $tax_exempt_code;
    }
    
    if ($total_amount_excluding_tax > 0) {
        $overall_tax_rate = ($total_tax / $total_amount_excluding_tax) * 100;
        
        // Find matching tax rate
        $stmt = $db->prepare("
            SELECT tax_code_ref 
            FROM qb_tax_rates 
            WHERE ABS(rate_value - ?) < 0.01 
            AND rate_type = 'Sales'
            ORDER BY ABS(rate_value - ?) ASC 
            LIMIT 1
        ");
        $stmt->bind_param("dd", $overall_tax_rate, $overall_tax_rate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['tax_code_ref'];
        }
    }
    
    return $tax_exempt_code;
}

/**
 * Fetch complete credit note details from Stripe
 */
function fetch_stripe_credit_note($api_key, $credit_note_id) {
    error_log("Fetching Stripe credit note: " . $credit_note_id);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/credit_notes/" . $credit_note_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch Stripe credit note. HTTP code: " . $http_code);
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Fetch complete invoice details from Stripe
 */
function fetch_stripe_invoice($api_key, $invoice_id) {
    error_log("Fetching Stripe invoice: " . $invoice_id);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/invoices/" . $invoice_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Failed to fetch Stripe invoice. HTTP code: " . $http_code);
        return false;
    }
    
    return json_decode($response, true);
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
 * Find or create item in QuickBooks
 */
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
?>