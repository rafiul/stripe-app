<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/stripe-invoice-created-to-qbo-invoice.php';

function process_stripe_invoice_paid_to_qbo_payment($event, $user_id) {
    global $db;
    
    // Start logging this sync attempt
    $history_id = log_sync_history($user_id, 'invoice.paid', $event['id'], 'pending', 'Processing started', $event);
    error_log("Starting payment process for Stripe invoice: " . $event['id']);
    
    try {
        // Get the invoice data from the event
        $stripe_invoice = $event['data']['object'];
        $stripe_invoice_id = $stripe_invoice['id'];
        
        // First check if this payment has already been processed
        $stmt = $db->prepare("SELECT qbo_payment_id FROM payment_mappings WHERE user_id = ? AND stripe_invoice_id = ?");
        $stmt->bind_param("is", $user_id, $stripe_invoice_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            update_sync_history($history_id, 'skipped', 'Payment already processed', [
                'qbo_payment_id' => $row['qbo_payment_id']
            ]);
            error_log("Payment already processed. QBO Payment ID: " . $row['qbo_payment_id']);
            return;
        }

        // Get the Stripe account
        $stripe_account = get_stripe_account($user_id);
        if (!$stripe_account) {
            throw new Exception("Stripe account not connected");
        }
        
        // Get the complete invoice details from Stripe
        $stripe_invoice = fetch_stripe_invoice2($stripe_account['api_key'], $stripe_invoice_id);
        if (!$stripe_invoice) {
            throw new Exception("Failed to fetch Stripe invoice details");
        }
        
        error_log("Fetched Stripe invoice: " . print_r($stripe_invoice, true));
        
        // Get user's QuickBooks account
        $qbo_account = get_quickbooks_account($user_id);
        if (!$qbo_account) {
            throw new Exception("QuickBooks account not connected");
        }
        
        // Get user's sync settings for tax exempt ID and deposit account
        $sync_settings = get_sync_settings2($user_id);
        $tax_exempt_id = $sync_settings['tax_exempt_id'] ?? null;
        $deposit_account_id = $sync_settings['deposit_account_id'] ?? null;
        
        // Determine if this is a non-US QuickBooks account
        $is_global_account = is_global_quickbooks_account2($user_id);
        
        // Get customer email from invoice
        $customer_email = $stripe_invoice['customer_email'] ?? '';
        if (empty($customer_email)) {
            throw new Exception("Customer email is required but missing");
        }
        
        // Find or create the QBO customer
        $qbo_customer_id = find_or_create_qbo_customer(
            $stripe_invoice['customer_name'] ?? $customer_email,
            $customer_email,
            $user_id
        );
        if (!$qbo_customer_id) {
            throw new Exception("Failed to find or create customer in QuickBooks");
        }
        
        error_log("Using QBO customer ID: " . $qbo_customer_id);
        
        // Try to find existing QBO invoice
        $qbo_invoice_id = find_qbo_invoice_for_stripe($stripe_invoice_id, $user_id);
        
        if (!$qbo_invoice_id) {
            // Create new invoice if not found
            error_log("No existing QBO invoice found, creating new one");
            
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
                $line_total = $line_item['amount_excluding_tax'] / 100;
                
                $line_item_data = [
                    'DetailType' => 'SalesItemLineDetail',
                    'Amount' => $line_total,
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
                        
                        $tax_code_ref = find_matching_tax_code2($effective_percentage, $user_id);
                        
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
            
            error_log("Creating new QBO invoice with data: " . print_r($invoice_data, true));
            
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
            error_log("Created new QBO invoice ID: " . $qbo_invoice_id);
            
            // Store the mapping between Stripe and QBO invoices
            $stmt = $db->prepare("INSERT INTO invoice_mappings 
                                (user_id, stripe_invoice_id, qbo_invoice_id, created_at) 
                                VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iss", $user_id, $stripe_invoice['id'], $qbo_invoice_id);
            
            if (!$stmt->execute()) {
                error_log("Failed to save invoice mapping: " . $stmt->error);
            }
        } else {
            error_log("Found existing QBO invoice ID: " . $qbo_invoice_id);
        }
        
        // Verify the invoice exists in QBO
        $qbo_invoice = make_qbo_api_request($user_id, '/invoice/' . $qbo_invoice_id);
        if ($qbo_invoice['code'] !== 200 || !isset($qbo_invoice['body']['Invoice'])) {
            throw new Exception("Failed to fetch QuickBooks invoice: " . $qbo_invoice_id);
        }
        
        // Create the payment in QuickBooks
        $qbo_payment_id = create_qbo_payment($stripe_invoice, $user_id, $qbo_customer_id, $qbo_invoice_id, $deposit_account_id);
        
        if (!$qbo_payment_id) {
            throw new Exception("Failed to create payment in QuickBooks");
        }
        
        error_log("Payment created successfully in QBO. Payment ID: " . $qbo_payment_id);
        
        // Store the mapping between Stripe invoice and QBO payment
        $stmt = $db->prepare("INSERT INTO payment_mappings 
                            (user_id, stripe_invoice_id, qbo_payment_id, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $stripe_invoice['id'], $qbo_payment_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to save payment mapping: " . $stmt->error);
        }
        
        // Log success
        update_sync_history($history_id, 'success', 'Payment created in QuickBooks', [
            'qbo_payment_id' => $qbo_payment_id,
            'stripe_invoice_id' => $stripe_invoice['id'],
            'qbo_invoice_id' => $qbo_invoice_id,
            'qbo_response' => $qbo_invoice['body']
        ]);
        
    } catch (Exception $e) {
        error_log("Payment creation failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'stripe_invoice' => $stripe_invoice ?? null,
            'qbo_response' => $qbo_invoice['body'] ?? null
        ]);
    }
}

/**
 * Find QBO invoice for a Stripe invoice
 */
function find_qbo_invoice_for_stripe($stripe_invoice_id, $user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT qbo_invoice_id FROM invoice_mappings WHERE user_id = ? AND stripe_invoice_id = ?");
    $stmt->bind_param("is", $user_id, $stripe_invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['qbo_invoice_id'];
    }
    
    return null;
}

/**
 * Create QBO payment from Stripe invoice data
 */
function create_qbo_payment($stripe_invoice, $user_id, $qbo_customer_id, $qbo_invoice_id, $deposit_account_id = null) {
    // If no deposit account ID is provided, use Undeposited Funds as default
    if (empty($deposit_account_id)) {
        $deposit_account_id = get_qbo_account_id2('Undeposited Funds', $user_id);
    }
    
    // Prepare payment data for QBO
    $payment_data = [
        'CustomerRef' => ['value' => $qbo_customer_id],
        'TotalAmt' => $stripe_invoice['amount_paid'] / 100, // Convert from cents to dollars
        'TxnDate' => date('Y-m-d', $stripe_invoice['created']),
        'PaymentRefNum' => substr($stripe_invoice['payment_intent'] ?? ('stripe_' . $stripe_invoice['id']), 0, 21),
        'PaymentMethodRef' => ['value' => get_qbo_payment_method_id('Stripe', $user_id)],
        'DepositToAccountRef' => ['value' => $deposit_account_id],
        'PrivateNote' => 'Stripe Invoice ID: ' . $stripe_invoice['id'],
        'Line' => [
            [
                'Amount' => $stripe_invoice['amount_paid'] / 100,
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
    
    $result = make_qbo_api_request($user_id, '/payment', 'POST', $payment_data);
    error_log("QBO API response: " . print_r($result, true));
    
    if ($result['code'] !== 200 || !isset($result['body']['Payment']['Id'])) {
        $error_msg = "Failed to create payment in QuickBooks";
        if (isset($result['body']['fault']['error'][0]['message'])) {
            $error_msg .= ": " . $result['body']['fault']['error'][0]['message'];
        }
        throw new Exception($error_msg);
    }
    
    return $result['body']['Payment']['Id'];
}

/**
 * Get QBO payment method ID by name (create if doesn't exist)
 */
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

/**
 * Get QBO account ID by name
 */
function get_qbo_account_id2($name, $user_id) {
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

/**
 * Find matching tax code based on effective percentage
 */
function find_matching_tax_code2($effective_percentage, $user_id) {
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
function is_global_quickbooks_account2($user_id) {
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
function fetch_stripe_invoice2($api_key, $invoice_id) {
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
 * Get sync settings for a user
 */
function get_sync_settings2($user_id) {
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