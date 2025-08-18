<?php
require_once __DIR__ . '/functions.php';


function process_stripe_invoice_created_to_qbo_invoice($event, $user_id) {
    global $db;
    
    // Start logging this sync attempt
    $history_id = log_sync_history($user_id, 'invoice.created', $event['id'], 'pending', 'Processing started', $event);
    error_log("Starting invoice creation process for event: " . $event['id']);
    
    try {
        // First check if this invoice has already been processed
        $stripe_invoice_id = $event['data']['object']['id'];
        $stmt = $db->prepare("SELECT qbo_invoice_id FROM invoice_mappings WHERE user_id = ? AND stripe_invoice_id = ?");
        $stmt->bind_param("is", $user_id, $stripe_invoice_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            update_sync_history($history_id, 'skipped', 'Invoice already processed', [
                'qbo_invoice_id' => $row['qbo_invoice_id']
            ]);
            error_log("Invoice already processed. QBO Invoice ID: " . $row['qbo_invoice_id']);
            return;
        }

        // Get the Stripe account
        $stripe_account = get_stripe_account($user_id);
        if (!$stripe_account) {
            throw new Exception("Stripe account not connected");
        }
        
        // Get the complete invoice details from Stripe
        $stripe_invoice = fetch_stripe_invoice($stripe_account['api_key'], $stripe_invoice_id);
        if (!$stripe_invoice) {
            throw new Exception("Failed to fetch Stripe invoice details");
        }
        
        error_log("Fetched Stripe invoice: " . print_r($stripe_invoice, true));
        
        // Check if invoice has line items
        if (empty($stripe_invoice['lines']['data'])) {
            throw new Exception("No line items in the invoice, nothing to process.");
        }
        
        // Get user's QuickBooks account
        $qbo_account = get_quickbooks_account($user_id);
        if (!$qbo_account) {
            throw new Exception("QuickBooks account not connected");
        }
        
        // Get user's sync settings for tax exempt ID
        $sync_settings = get_sync_settings1($user_id);
        $tax_exempt_id = $sync_settings['tax_exempt_id'] ?? null;
        
        // Determine if this is a non-US QuickBooks account
        $is_global_account = is_global_quickbooks_account($user_id);
        
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
        
        // Prepare line items for QuickBooks invoice
        $line_items = [];
        $total_tax = 0;
        
        foreach ($stripe_invoice['lines']['data'] as $line_item) {
            $product_name = $line_item['description'] ?? 'Unnamed Product';
            $product_sku = $line_item['price']['product'] ?? null;
            
            // Find or create product in QuickBooks
            $item_id = find_or_create_qbo_item($product_name, $product_sku, $user_id);
            if (!$item_id) {
                error_log("Skipping line item - failed to create product");
                continue;
            }
            
            // Calculate amounts
            $unit_price = $line_item['price']['unit_amount'] / 100;
            $quantity = $line_item['quantity'] ?? 1;
            $line_total = $line_item['amount_excluding_tax'] / 100; // Use amount excluding tax
            
            $line_item_data = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount' => $line_total, // Amount excluding tax
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => $item_id],
                    'UnitPrice' => $unit_price,
                    'Qty' => $quantity
                ]
            ];
            
            // Handle tax for this line item
            $line_tax_amount = 0;
            
            if (!empty($line_item['tax_amounts'])) {
                $line_tax_amount = $line_item['tax_amounts'][0]['amount'] / 100;
                $total_tax += $line_tax_amount;
                
                if ($is_global_account) {
                    // For global accounts, find matching tax code
                    $effective_percentage = $line_item['tax_amounts'][0]['taxability_reason']['effective_percentage'] ?? 
                                          ($line_item['tax_amounts'][0]['amount'] / $line_item['amount_excluding_tax'] * 100);
                    
                    $tax_code_ref = find_matching_tax_code($effective_percentage, $user_id);
                    
                    if ($tax_code_ref) {
                        $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code_ref];
                    }
                } else {
                    // For US accounts, use standard tax code
                    $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
                }
            } else {
                // No tax applied - use tax exempt
                if ($is_global_account) {
                    if ($tax_exempt_id) {
                        $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_exempt_id];
                    }
                } else {
                    $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'NON'];
                }
            }
            
            $line_items[] = $line_item_data;
        }
        
        if (empty($line_items)) {
            throw new Exception("No valid line items could be processed");
        }
        
        // Create QuickBooks invoice
        $invoice_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'Line' => $line_items,
            'TxnDate' => date('Y-m-d', $stripe_invoice['created']),
            'DueDate' => date('Y-m-d', $stripe_invoice['due_date'] ?? strtotime('+30 days', $stripe_invoice['created'])),
            'TotalAmt' => ($stripe_invoice['total'] / 100),
            'PrivateNote' => 'Stripe Invoice ID: ' . $stripe_invoice['id'],
            'AllowOnlineACHPayment' => false,
            'AllowOnlineCreditCardPayment' => false
        ];
        
        // Add tax details based on account type
        if ($is_global_account) {
            // For global accounts, use GlobalTaxCalculation
            $invoice_data['GlobalTaxCalculation'] = 'TaxExcluded';
            if ($total_tax > 0) {
                $invoice_data['TxnTaxDetail'] = [
                    'TotalTax' => $total_tax
                ];
            }
        } else {
            // For US accounts, use the traditional tax structure
            if ($total_tax > 0) {
                $invoice_data['TxnTaxDetail'] = [
                    'TxnTaxCodeRef' => ['value' => 'TAX'],
                    'TotalTax' => $total_tax
                ];
            }
        }
        
        error_log("Invoice data prepared: " . print_r($invoice_data, true));

        
        $result = make_qbo_api_request($user_id, '/invoice', 'POST', $invoice_data);
        error_log("QBO API response: " . print_r($result, true));
        
        if ($result['code'] !== 200 || !isset($result['body']['Invoice']['Id'])) {
            $error_msg = "Failed to create invoice in QuickBooks";
            if (isset($result['body']['fault']['error'][0]['message'])) {
                $error_msg .= ": " . $result['body']['fault']['error'][0]['message'];
            }
            throw new Exception($error_msg);
        }
        
        $qbo_invoice_id = $result['body']['Invoice']['Id'];
        error_log("Invoice created successfully in QBO. Invoice ID: " . $qbo_invoice_id);
        
        // Store the mapping between Stripe and QBO invoices
        $stmt = $db->prepare("INSERT INTO invoice_mappings 
                            (user_id, stripe_invoice_id, qbo_invoice_id, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $stripe_invoice['id'], $qbo_invoice_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to save invoice mapping: " . $stmt->error);
        }
        
        // Log success
        update_sync_history($history_id, 'success', 'Invoice created in QuickBooks', [
            'qbo_invoice_id' => $qbo_invoice_id,
            'stripe_invoice_id' => $stripe_invoice['id'],
            'qbo_response' => $result['body']
        ]);
        
    } catch (Exception $e) {
        error_log("Invoice creation failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'invoice_data' => $invoice_data ?? null,
            'qbo_response' => $result['body'] ?? null
        ]);
    }
}

/**
 * Find matching tax code based on effective percentage
 */
function find_matching_tax_code($effective_percentage, $user_id) {
    global $db;
    
    // Round to 2 decimal places for comparison
    $effective_percentage = round($effective_percentage, 2);
    
    // Query to find matching tax code from database
    $stmt = $db->prepare("SELECT tax_code_ref FROM qb_tax_rates 
                         WHERE ABS(rate_value - ?) < 0.01 
                         AND rate_type = 'Sales'
                         ORDER BY ABS(rate_value - ?) ASC 
                         LIMIT 1");
    $stmt->bind_param("dd", $effective_percentage, $effective_percentage);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        error_log("Found matching tax code: " . $row['tax_code_ref'] . " for rate: " . $effective_percentage . "%");
        return $row['tax_code_ref'];
    }
    
    error_log("No matching tax code found for rate: " . $effective_percentage . "%");
    return null;
}

/**
 * Determine if this is a global (non-US) QuickBooks account
 */
function is_global_quickbooks_account($user_id) {
    // Try to get company info to determine the country
    $result = make_qbo_api_request($user_id, '/companyinfo/1', 'GET');
    
    if ($result['code'] === 200 && isset($result['body']['CompanyInfo']['Country'])) {
        $country = $result['body']['CompanyInfo']['Country'];
        // US accounts typically don't have Country field or have 'US'
        return !empty($country) && $country !== 'US';
    }
    
    // Alternative method: Check if TaxCode exists (US feature)
    $tax_code_result = make_qbo_api_request($user_id, '/query?query=' . urlencode("SELECT * FROM TaxCode MAXRESULTS 1"), 'GET');
    
    if ($tax_code_result['code'] === 200) {
        // If TaxCode query fails or returns empty, it's likely a global account
        return empty($tax_code_result['body']['QueryResponse']['TaxCode']);
    }
    
    // Default to US account behavior
    return false;
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

/**
 * Get sync settings for a user
 */
function get_sync_settings1($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM sync_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return [];
}