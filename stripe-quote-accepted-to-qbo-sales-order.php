<?php
require_once __DIR__ . '/functions.php';

function process_stripe_quote_accepted_to_qbo_sales_order($event, $user_id) {
    global $db;
    
    error_log("[QUOTE PROCESSING] Starting processing for event: " . $event['id']);
    $history_id = log_sync_history($user_id, 'quote.accepted', $event['id'], 'pending', 'Processing started', $event);
    
    try {
        $quote = $event['data']['object'];
        $quote_id = $quote['id'];
        error_log("[QUOTE PROCESSING] Processing quote ID: $quote_id");
        
        // Check if already processed
        error_log("[QUOTE PROCESSING] Checking if quote already processed...");
        $stmt = $db->prepare("SELECT qbo_sales_order_id FROM quote_mappings WHERE user_id = ? AND stripe_quote_id = ?");
        $stmt->bind_param("is", $user_id, $quote_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            error_log("[QUOTE PROCESSING] Quote already processed. QBO Sales Order ID: " . $row['qbo_sales_order_id']);
            update_sync_history($history_id, 'skipped', 'Quote already processed', [
                'qbo_sales_order_id' => $row['qbo_sales_order_id']
            ]);
            return;
        }

        // Get accounts
        error_log("[QUOTE PROCESSING] Retrieving Stripe and QBO accounts...");
        $stripe_account = get_stripe_account($user_id);
        $qbo_account = get_quickbooks_account($user_id);
        
        if (!$stripe_account) {
            error_log("[QUOTE PROCESSING] ERROR: Stripe account not connected for user $user_id");
            throw new Exception("Stripe account not connected");
        }
        if (!$qbo_account) {
            error_log("[QUOTE PROCESSING] ERROR: QuickBooks account not connected for user $user_id");
            throw new Exception("QuickBooks account not connected");
        }
        
        // Get customer details
        error_log("[QUOTE PROCESSING] Fetching customer details from Stripe...");
        $customer = get_stripe_customer($quote['customer'], $stripe_account['api_key']);
        if (!$customer) {
            error_log("[QUOTE PROCESSING] ERROR: Failed to fetch customer details from Stripe");
            throw new Exception("Failed to fetch customer details");
        }
        
        $customer_email = $customer['email'] ?? '';
        $customer_name = $customer['name'] ?? $customer_email;
        error_log("[QUOTE PROCESSING] Customer details - Name: $customer_name, Email: $customer_email");
        
        if (empty($customer_email)) {
            error_log("[QUOTE PROCESSING] ERROR: Customer email is missing");
            throw new Exception("Customer email is required but missing");
        }
        
        // Find or create customer in QuickBooks
        error_log("[QUOTE PROCESSING] Finding/creating customer in QBO...");
        $qbo_customer_id = find_or_create_qbo_customer($customer_name, $customer_email, $user_id);
        if (!$qbo_customer_id) {
            error_log("[QUOTE PROCESSING] ERROR: Failed to find or create customer in QBO");
            throw new Exception("Failed to find or create customer in QuickBooks");
        }
        
        error_log("[QUOTE PROCESSING] Using QBO customer ID: $qbo_customer_id");
        
        // Prepare line items
        error_log("[QUOTE PROCESSING] Preparing line items...");
        $line_items = [];
        $total_tax = 0;
        
        // Fetch quote line items from Stripe
        error_log("[QUOTE PROCESSING] Fetching quote line items from Stripe...");
        $quote_line_items = fetch_stripe_quote_line_items($stripe_account['api_key'], $quote_id);
        
        if (empty($quote_line_items['data'])) {
            error_log("[QUOTE PROCESSING] ERROR: No line items found in quote");
            throw new Exception("No line items in the quote");
        }
        
        error_log("[QUOTE PROCESSING] Processing " . count($quote_line_items['data']) . " line items");
        
        foreach ($quote_line_items['data'] as $index => $line) {
            error_log("[QUOTE PROCESSING] Processing line item #$index");
            $product_name = $line['description'] ?? 'Product';
            $product_sku = $line['price']['product'] ?? null;
            
            error_log("[QUOTE PROCESSING] Product: $product_name, SKU: " . ($product_sku ?? 'N/A'));
            
            // Find or create product in QuickBooks
            $item_id = find_or_create_qbo_item($product_name, $product_sku, $user_id);
            if (!$item_id) {
                error_log("[QUOTE PROCESSING] WARNING: Failed to create product, skipping line item");
                continue;
            }
            
            // Calculate amounts
            $unit_price = ($line['amount_total'] / 100) / ($line['quantity'] ?? 1);
            $quantity = $line['quantity'] ?? 1;
            $line_total = $line['amount_total'] / 100;
            $tax_amount = isset($line['tax_amounts']) && !empty($line['tax_amounts']) ? 
                ($line['tax_amounts'][0]['amount'] / 100) : 0;
            $total_tax += $tax_amount;
            
            error_log("[QUOTE PROCESSING] Line item details - Qty: $quantity, Unit Price: $unit_price, Total: $line_total, Tax: $tax_amount");
            
            $line_item = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount' => $line_total,
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => $item_id],
                    'UnitPrice' => $unit_price,
                    'Qty' => $quantity
                ]
            ];
            
            // Add tax if applicable
            if ($tax_amount > 0) {
                $line_item['SalesItemLineDetail']['TaxCodeRef'] = ['value' => 'TAX'];
                error_log("[QUOTE PROCESSING] Added tax to line item");
            }
            
            $line_items[] = $line_item;
        }
        
        if (empty($line_items)) {
            error_log("[QUOTE PROCESSING] ERROR: No valid line items after processing");
            throw new Exception("No valid line items could be processed");
        }
        
        error_log("[QUOTE PROCESSING] Processed " . count($line_items) . " valid line items");
        error_log("[QUOTE PROCESSING] Total Tax: $total_tax");
        
        // Create sales order in QuickBooks
        $sales_order_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'Line' => $line_items,
            'TxnDate' => date('Y-m-d', $quote['created']),
            'TotalAmt' => $quote['amount_total'] / 100,
            'PrivateNote' => 'Stripe Quote: ' . $quote_id,
            'TxnTaxDetail' => [
                'TxnTaxCodeRef' => ['value' => 'TAX'],
                'TotalTax' => $total_tax
            ]
        ];
        
        error_log("[QUOTE PROCESSING] Prepared QBO Sales Order data: " . json_encode($sales_order_data));
        error_log("[QUOTE PROCESSING] Creating sales order in QBO...");
        
        $result = make_qbo_api_request($user_id, '/salesorder', 'POST', $sales_order_data);
        error_log("[QUOTE PROCESSING] QBO API Response: " . json_encode($result));
        
        if ($result['code'] !== 200 || !isset($result['body']['SalesOrder']['Id'])) {
            $error_msg = "Failed to create sales order in QuickBooks";
            if (isset($result['body']['fault']['error'][0]['message'])) {
                $error_msg .= ": " . $result['body']['fault']['error'][0]['message'];
            }
            error_log("[QUOTE PROCESSING] ERROR: $error_msg");
            throw new Exception($error_msg);
        }
        
        $qbo_sales_order_id = $result['body']['SalesOrder']['Id'];
        error_log("[QUOTE PROCESSING] Successfully created QBO Sales Order ID: $qbo_sales_order_id");
        
        // Store mapping
        error_log("[QUOTE PROCESSING] Storing quote mapping in database...");
        $stmt = $db->prepare("INSERT INTO quote_mappings 
                            (user_id, stripe_quote_id, qbo_sales_order_id, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $quote_id, $qbo_sales_order_id);
        $stmt->execute();
        
        error_log("[QUOTE PROCESSING] Successfully stored mapping");
        
        update_sync_history($history_id, 'success', 'Sales order created in QuickBooks', [
            'qbo_sales_order_id' => $qbo_sales_order_id,
            'stripe_quote_id' => $quote_id,
            'qbo_response' => $result['body']
        ]);
        
        error_log("[QUOTE PROCESSING] Processing completed successfully");
        
    } catch (Exception $e) {
        error_log("[QUOTE PROCESSING] ERROR: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'sales_order_data' => $sales_order_data ?? null,
            'qbo_response' => $result['body'] ?? null
        ]);
    }
}

/**
 * Find or create customer in QuickBooks
 */
function find_or_create_qbo_customer($name, $email, $user_id) {
    error_log("[CUSTOMER] Searching for customer with email: $email");
    
    // First try to find by email
    if (!empty($email)) {
        $query = "SELECT * FROM Customer WHERE PrimaryEmailAddr = '$email'";
        $encoded_query = urlencode($query);
        $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query, 'GET');
        
        if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Customer'])) {
            $customer_id = $result['body']['QueryResponse']['Customer'][0]['Id'];
            error_log("[CUSTOMER] Found existing customer ID: $customer_id");
            return $customer_id;
        }
    }
    
    // Create new customer
    error_log("[CUSTOMER] Creating new customer for: $name ($email)");
    $display_name = substr($name . " (" . $email . ")", 0, 100);
    $customer_data = [
        'DisplayName' => $display_name,
        'GivenName' => substr($name, 0, 25),
        'FamilyName' => substr($name, 0, 25),
        'PrimaryEmailAddr' => [
            'Address' => substr($email, 0, 100)
        ],
        'Notes' => 'Created via Stripe integration'
    ];
    
    $result = make_qbo_api_request($user_id, '/customer', 'POST', $customer_data);
    if ($result['code'] !== 200 || !isset($result['body']['Customer']['Id'])) {
        error_log("[CUSTOMER] ERROR: Failed to create customer: " . json_encode($result));
        return false;
    }
    
    $customer_id = $result['body']['Customer']['Id'];
    error_log("[CUSTOMER] Successfully created new customer ID: $customer_id");
    return $customer_id;
}

/**
 * Find or create item in QuickBooks
 */
function find_or_create_qbo_item($name, $sku, $user_id) {
    error_log("[ITEM] Searching for item: $name");
    
    // Try to find by name first
    $query = "SELECT * FROM Item WHERE Name = '" . addslashes($name) . "'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Item'])) {
        $item_id = $result['body']['QueryResponse']['Item'][0]['Id'];
        error_log("[ITEM] Found existing item ID: $item_id");
        return $item_id;
    }
    
    // Create new item
    error_log("[ITEM] Creating new item: $name");
    $income_account_id = get_qbo_account_id('Sales of Product Income', $user_id);
    if (!$income_account_id) {
        $income_account_id = '1'; // Fallback to default account
        error_log("[ITEM] Using default income account ID");
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
        error_log("[ITEM] ERROR: Failed to create item: " . json_encode($result));
        return false;
    }
    
    $item_id = $result['body']['Item']['Id'];
    error_log("[ITEM] Successfully created new item ID: $item_id");
    return $item_id;
}

/**
 * Get QBO account ID by name
 */
function get_qbo_account_id($account_name, $user_id) {
    error_log("[ACCOUNT] Searching for account: $account_name");
    
    $query = "SELECT * FROM Account WHERE Name = '$account_name'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Account'])) {
        $account_id = $result['body']['QueryResponse']['Account'][0]['Id'];
        error_log("[ACCOUNT] Found account ID: $account_id");
        return $account_id;
    }
    
    error_log("[ACCOUNT] Account not found: $account_name");
    return false;
}

/**
 * Fetch quote line items from Stripe
 */
function fetch_stripe_quote_line_items($api_key, $quote_id) {
    error_log("[STRIPE] Fetching line items for quote: $quote_id");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/quotes/" . $quote_id . "/line_items");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Stripe-Version: " . STRIPE_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("[STRIPE] ERROR: Failed to fetch quote line items. HTTP code: $http_code");
        return false;
    }
    
    $data = json_decode($response, true);
    error_log("[STRIPE] Retrieved " . count($data['data']) . " line items");
    return $data;
}