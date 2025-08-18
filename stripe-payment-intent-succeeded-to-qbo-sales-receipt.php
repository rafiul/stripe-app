<?php
require_once __DIR__ . '/functions.php';

/**
 * Detect if the QuickBooks account is a US account by checking available tax codes
 */
function is_qbo_us_account($user_id) {
    // Try to get the list of tax codes from QuickBooks
    $result = make_qbo_api_request($user_id, '/query?query=SELECT%20*%20FROM%20TaxCode', 'GET');
    
    if ($result['code'] === 200 && isset($result['body']['QueryResponse']['TaxCode'])) {
        foreach ($result['body']['QueryResponse']['TaxCode'] as $tax_code) {
            // US accounts typically have TAX and NON codes
            if ($tax_code['Name'] === 'TAX' || $tax_code['Name'] === 'NON') {
                return true;
            }
        }
    }
    
    // If we can't determine, assume it's not a US account
    return false;
}

/**
 * Get tax code by percentage for international tax handling
 */
function get_tax_code_by_percentage($tax_percentage, $user_id) {
    global $db;
    
    // Check if this is a US account
    $is_us = is_qbo_us_account($user_id);
    
    // Round tax percentage to match database values
    $rounded_percentage = round($tax_percentage, 2);
    
    // If tax percentage is 0, use the default non-taxable code
    if ($rounded_percentage == 0) {
        return $is_us ? 'NON' : get_default_non_taxable_code($user_id);
    }
    
    // For US accounts, just return 'TAX' for any taxable amount
    if ($is_us) {
        return 'TAX';
    }
    
    // For non-US accounts, proceed with the existing logic
    $stmt = $db->prepare("SELECT tax_code_ref FROM qb_tax_rates 
                         WHERE ABS(rate_value - ?) <= 0.1 
                         ORDER BY ABS(rate_value - ?) ASC 
                         LIMIT 1");
    $stmt->bind_param("dd", $rounded_percentage, $rounded_percentage);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        error_log("Found tax code for {$rounded_percentage}%: " . $row['tax_code_ref']);
        return $row['tax_code_ref'];
    }
    
    error_log("No tax code found for {$rounded_percentage}%, using default non-taxable");
    return get_default_non_taxable_code($user_id);
}

/**
 * Get default non-taxable code based on user's country
 */
function get_default_non_taxable_code($user_id) {
    global $db;
    
    // Check if this is a US account
    $is_us = is_qbo_us_account($user_id);
    
    // For US accounts, always return 'NON'
    if ($is_us) {
        return 'NON';
    }
    
    // For non-US accounts, proceed with the existing logic
    $sync_settings = get_sync_settings($user_id);
    if (!empty($sync_settings['tax_exempt_id'])) {
        error_log("Using tax exempt ID from sync settings: " . $sync_settings['tax_exempt_id']);
        return $sync_settings['tax_exempt_id'];
    }
    
    $result = $db->query("SELECT tax_code_ref FROM qb_tax_rates WHERE rate_value = 0 LIMIT 1");
    if ($result === false) {
        error_log("Database query failed: " . $db->error);
        return 'NON';
    }
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['tax_code_ref'];
    }
    
    return 'NON'; // Fallback for non-US accounts
}

/**
 * Get Stripe product details by ID
 */
function get_stripe_product($product_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/products/" . $product_id;
    
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
        error_log("Failed to fetch Product: " . $product_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Get Stripe tax rate details by ID
 */
function get_stripe_tax_rate($tax_rate_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/tax_rates/" . $tax_rate_id;
    
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
        error_log("Failed to fetch Tax Rate: " . $tax_rate_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Process subscription line items from Stripe invoice with proper product name handling
 */
function process_subscription_line_items($line_items, $user_id, $stripe_api_key) {
    $processed_items = [];
    $total_tax = 0;
    $tax_percentage = 0;
    
    foreach ($line_items['data'] as $line_item) {
        // Skip non-subscription line items
        if ($line_item['type'] !== 'subscription') {
            continue;
        }
        
        // Initialize with default values
        $product_name = 'Subscription';
        $product_description = $line_item['description'] ?? 'Subscription Payment';
        
        // Get product details from Stripe if product ID is available
        if (!empty($line_item['price']['product'])) {
            $product = get_stripe_product($line_item['price']['product'], $stripe_api_key);
            if ($product && !empty($product['name'])) {
                // Always use the product name from Stripe
                $product_name = $product['name'];
                error_log("Using product name from Stripe: " . $product_name);
            }
        }
        
        // Use line item description if available (contains quantity and price info)
        if (!empty($line_item['description'])) {
            $product_description = $line_item['description'];
        }
        
        // Get amount details
        $amount_excluding_tax = $line_item['amount_excluding_tax'] / 100;
        $amount_including_tax = $line_item['amount'] / 100;
        $quantity = $line_item['quantity'] ?? 1;
        
        // Initialize tax variables
        $line_item_tax_amount = 0;
        $line_item_tax_percentage = 0;
        $is_tax_inclusive = false;
        
        // Process tax rates if present
        if (!empty($line_item['tax_amounts'])) {
            foreach ($line_item['tax_amounts'] as $tax_amount) {
                $tax_rate = get_stripe_tax_rate($tax_amount['tax_rate'], $stripe_api_key);
                if ($tax_rate) {
                    $line_item_tax_percentage += $tax_rate['percentage'];
                    $is_tax_inclusive = $tax_rate['inclusive'] ?? false;
                    $line_item_tax_amount += $tax_amount['amount'] / 100;
                }
            }
        }
        
        // Calculate unit price based on tax behavior
        $unit_price = $is_tax_inclusive 
            ? $amount_excluding_tax / $quantity 
            : $amount_excluding_tax / $quantity;
        
        $total_tax += $line_item_tax_amount;
        $tax_percentage = max($tax_percentage, $line_item_tax_percentage);
        
        // Find or create product in QuickBooks - use the actual product name
        $item_id = find_or_create_qbo_item($product_name, $line_item['price']['product'] ?? null, $user_id, $product_description);
        if (!$item_id) {
            throw new Exception("Failed to create subscription product in QuickBooks");
        }
        
        $line_item_data = [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => $amount_excluding_tax,
            'Description' => $product_description,
            'SalesItemLineDetail' => [
                'ItemRef' => ['value' => $item_id],
                'UnitPrice' => $unit_price,
                'Qty' => $quantity
            ]
        ];
        
        // Apply tax code
        if ($line_item_tax_amount > 0) {
            $tax_code = get_tax_code_by_percentage($line_item_tax_percentage, $user_id);
            $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
        } else {
            $tax_code = get_default_non_taxable_code($user_id);
            $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
        }
        
        $processed_items[] = $line_item_data;
    }
    
    return [
        'line_items' => $processed_items,
        'total_tax' => $total_tax,
        'tax_percentage' => $tax_percentage
    ];
}

/**
 * Process Stripe payment intent succeeded event and create QBO sales receipt
 */
function process_stripe_payment_intent_succeeded_to_qbo_sales_receipt($event, $user_id) {
    global $db;
    
    // Start logging this sync attempt
    $history_id = log_sync_history($user_id, 'payment_intent.succeeded', $event['id'], 'pending', 'Processing started', $event);
    error_log("Starting sales receipt creation process for event: " . $event['id']);
    
    try {
        // First check if this payment intent has already been processed
        $stripe_payment_intent_id = $event['data']['object']['id'];
        $stmt = $db->prepare("SELECT qbo_sales_receipt_id FROM payment_intent_mappings WHERE user_id = ? AND stripe_payment_intent_id = ?");
        $stmt->bind_param("is", $user_id, $stripe_payment_intent_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            update_sync_history($history_id, 'skipped', 'Payment intent already processed', [
                'qbo_sales_receipt_id' => $row['qbo_sales_receipt_id']
            ]);
            error_log("Payment intent already processed. QBO Sales Receipt ID: " . $row['qbo_sales_receipt_id']);
            return;
        }

        // Get the Stripe account
        $stripe_account = get_stripe_account($user_id);
        if (!$stripe_account || empty($stripe_account['api_key'])) {
            throw new Exception("Stripe account not connected or API key missing");
        }
        
        // Get the payment intent data from the event
        $payment_intent = $event['data']['object'];
        
        // Get charge with improved handling
        $charge = null;
        if (!empty($payment_intent['charges']['data'])) {
            $charge = $payment_intent['charges']['data'][0];
        } elseif (!empty($payment_intent['latest_charge'])) {
            $charge = get_stripe_charge($payment_intent['latest_charge'], $stripe_account['api_key']);
        }
        
        if (!$charge) {
            throw new Exception("No charge found in payment intent and couldn't retrieve latest charge");
        }
        
        // Get user's QuickBooks account
        $qbo_account = get_quickbooks_account($user_id);
        if (!$qbo_account) {
            throw new Exception("QuickBooks account not connected");
        }
        
        // Prepare customer data - first try from charge details
        $customer_email = $charge['billing_details']['email'] ?? '';
        $customer_name = $charge['billing_details']['name'] ?? $customer_email;
        
        // If no customer details found, try to get from invoice
        if (empty($customer_email)) {
            error_log("No customer email in charge details, trying to get from invoice");
            
            if (!empty($payment_intent['invoice'])) {
                $invoice = get_stripe_invoice($payment_intent['invoice'], $stripe_account['api_key']);
                
                if ($invoice && !empty($invoice['customer_email'])) {
                    $customer_email = $invoice['customer_email'];
                    $customer_name = $invoice['customer_name'] ?? $customer_email;
                    error_log("Found customer details from invoice: " . $customer_email);
                }
            }
        }
        
        // If still no customer details found, try to get directly from Stripe using customer ID
        if (empty($customer_email) && !empty($payment_intent['customer'])) {
            error_log("No customer details found yet, trying to fetch directly from Stripe using customer ID");
            $customer = get_stripe_customer66($payment_intent['customer'], $stripe_account['api_key']);
            
            if ($customer) {
                $customer_email = $customer['email'] ?? '';
                $customer_name = $customer['name'] ?? $customer_email;
                error_log("Found customer details directly from Stripe: " . $customer_email);
            }
        }
        
        // If we still don't have customer email, throw an exception
        if (empty($customer_email)) {
            throw new Exception("Customer email is required but missing");
        }
        
        // Find or create customer in QuickBooks
        $qbo_customer_id = find_or_create_qbo_customer($customer_name, $customer_email, $user_id);
        if (!$qbo_customer_id) {
            throw new Exception("Failed to find or create customer in QuickBooks");
        }
        
        error_log("Using QBO customer ID: " . $qbo_customer_id);
        
        // Prepare line items for QuickBooks sales receipt
        $line_items = [];
        $total_amount = $payment_intent['amount'] / 100;
        $currency = strtoupper($payment_intent['currency']);
        $total_tax = 0;
        $tax_percentage = 0;
        
        // Try to get the Checkout Session associated with this Payment Intent
        $checkout_session = get_stripe_checkout_session($payment_intent['id'], $stripe_account['api_key']);
        
        if ($checkout_session && isset($checkout_session['id'])) {
            error_log("Found checkout session: " . $checkout_session['id']);
            
            // Get tax information from checkout session
            $session_tax_amount = 0;
            $session_subtotal = 0;
            
            if (isset($checkout_session['total_details']['amount_tax'])) {
                $session_tax_amount = $checkout_session['total_details']['amount_tax'] / 100;
                error_log("Session tax amount: $" . $session_tax_amount);
            }
            
            if (isset($checkout_session['amount_subtotal'])) {
                $session_subtotal = $checkout_session['amount_subtotal'] / 100;
                error_log("Session subtotal: $" . $session_subtotal);
            }
            
            // Calculate tax percentage from session data
            if ($session_subtotal > 0 && $session_tax_amount > 0) {
                $tax_percentage = ($session_tax_amount / $session_subtotal) * 100;
                error_log("Calculated tax percentage from session: " . $tax_percentage . "%");
            }
            
            // Now get the line items for this checkout session
            $line_items_response = get_stripe_checkout_session_line_items($checkout_session['id'], $stripe_account['api_key']);
            
            if ($line_items_response && isset($line_items_response['data'])) {
                error_log("Found checkout session line items: " . count($line_items_response['data']));
                
                $total_line_items_amount = 0;
                
                foreach ($line_items_response['data'] as $line_item) {
                    $product_name = $line_item['description'] ?? 'Product';
                    $amount_total = $line_item['amount_total'] / 100; // Total including tax
                    $amount_subtotal = $line_item['amount_subtotal'] / 100; // Amount excluding tax
                    $quantity = $line_item['quantity'] ?? 1;
                    
                    // Calculate tax for this line item
                    $line_tax_amount = $amount_total - $amount_subtotal;
                    $total_tax += $line_tax_amount;
                    $total_line_items_amount += $amount_subtotal;
                    
                    error_log("Line item: {$product_name}, Subtotal: {$amount_subtotal}, Tax: {$line_tax_amount}, Total: {$amount_total}");
                    
                    // Find or create product in QuickBooks
                    $item_id = find_or_create_qbo_item($product_name, $line_item['price']['product'] ?? null, $user_id, $product_name);
                    if (!$item_id) {
                        throw new Exception("Failed to create product in QuickBooks");
                    }
                    
                    $line_item_data = [
                        'DetailType' => 'SalesItemLineDetail',
                        'Amount' => $amount_subtotal, // Amount excluding tax
                        'Description' => $product_name,
                        'SalesItemLineDetail' => [
                            'ItemRef' => ['value' => $item_id],
                            'UnitPrice' => $amount_subtotal / $quantity,
                            'Qty' => $quantity
                        ]
                    ];
                    
                    // Use international tax code logic
                    if ($line_tax_amount > 0) {
                        $line_tax_percentage = ($line_tax_amount / $amount_subtotal) * 100;
                        $tax_code = get_tax_code_by_percentage($line_tax_percentage, $user_id);
                        $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
                    } else {
                        $tax_code = get_default_non_taxable_code($user_id);
                        $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
                    }
                    
                    $line_items[] = $line_item_data;
                }
                
                error_log("Total line items amount: {$total_line_items_amount}, Total tax: {$total_tax}");
            }
        }
        
        // If no line items were found from checkout session, try to get from invoice
        if (empty($line_items)) {
            error_log("No line items found from checkout session, trying to get from invoice");
            
            $invoice = null;
            if (!empty($payment_intent['invoice'])) {
                $invoice = get_stripe_invoice($payment_intent['invoice'], $stripe_account['api_key']);
            }
            
            if ($invoice && !empty($invoice['lines']['data'])) {
                error_log("Found invoice line items: " . count($invoice['lines']['data']));
                
                // Check if this is a subscription invoice
                if (!empty($invoice['billing_reason']) && 
                    ($invoice['billing_reason'] === 'subscription_create' || 
                     $invoice['billing_reason'] === 'subscription_cycle')) {
                    
                    error_log("Processing subscription invoice");
                    $subscription_result = process_subscription_line_items(
                        $invoice['lines'], 
                        $user_id,
                        $stripe_account['api_key']
                    );
                    
                    $line_items = $subscription_result['line_items'];
                    $total_tax = $subscription_result['total_tax'];
                    $tax_percentage = $subscription_result['tax_percentage'];
                    
                } else {
                    // Existing invoice processing logic for non-subscription invoices
                    if (isset($invoice['effective_percentage'])) {
                        $tax_percentage = $invoice['effective_percentage'];
                        error_log("Using effective_percentage from invoice: " . $tax_percentage . "%");
                    } elseif (isset($invoice['tax_percent'])) {
                        $tax_percentage = $invoice['tax_percent'];
                        error_log("Using tax_percent from invoice: " . $tax_percentage . "%");
                    }
                    
                    foreach ($invoice['lines']['data'] as $line_item) {
                        $product_name = $line_item['description'] ?? 'Product';
                        $amount = $line_item['amount'] / 100;
                        $quantity = $line_item['quantity'] ?? 1;
                        $unit_price = $amount / $quantity;
                        
                        // Calculate tax using the percentage from invoice
                        $line_item_tax_percent = $tax_percentage;
                        
                        // If no invoice-level tax, try line item level
                        if ($line_item_tax_percent == 0 && isset($line_item['tax_rates']) && !empty($line_item['tax_rates'])) {
                            $line_item_tax_percent = $line_item['tax_rates'][0]['percentage'] ?? 0;
                            error_log("Using tax_rates from line item: " . $line_item_tax_percent . "%");
                        }
                        
                        $tax_amount = $amount * ($line_item_tax_percent / 100);
                        $total_tax += $tax_amount;
                        
                        // Find or create product in QuickBooks
                        $item_id = find_or_create_qbo_item($product_name, $line_item['price']['product'] ?? null, $user_id, $product_name);
                        if (!$item_id) {
                            throw new Exception("Failed to create product in QuickBooks");
                        }
                        
                        $line_item_data = [
                            'DetailType' => 'SalesItemLineDetail',
                            'Amount' => $amount - $tax_amount, // Amount excluding tax
                            'Description' => $product_name,
                            'SalesItemLineDetail' => [
                                'ItemRef' => ['value' => $item_id],
                                'UnitPrice' => ($amount - $tax_amount) / $quantity,
                                'Qty' => $quantity
                            ]
                        ];
                        
                        // Use international tax code logic
                        if ($tax_amount > 0) {
                            $tax_code = get_tax_code_by_percentage($line_item_tax_percent, $user_id);
                            $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
                        } else {
                            $tax_code = get_default_non_taxable_code($user_id);
                            $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
                        }
                        
                        $line_items[] = $line_item_data;
                    }
                }
            }
        }
        
        // If still no line items found, create a default line item
        if (empty($line_items)) {
            error_log("No line items found from checkout session or invoice, using default line item");
            
            $product_name = 'Stripe Payment';
            $description = '';
            if (!empty($payment_intent['description'])) {
                $product_name = $payment_intent['description'];
                $description = $payment_intent['description'];
            } elseif (!empty($charge['description'])) {
                $product_name = $charge['description'];
                $description = $charge['description'];
            }
            
            // Find or create product in QuickBooks
            $item_id = find_or_create_qbo_item($product_name, null, $user_id, $description);
            if (!$item_id) {
                throw new Exception("Failed to create default product in QuickBooks");
            }
            
            // Calculate tax for the default line item
            $tax_amount = 0;
            
            // Try to get tax from checkout session first
            if ($checkout_session && isset($checkout_session['total_details']['amount_tax'])) {
                $tax_amount = $checkout_session['total_details']['amount_tax'] / 100;
                $subtotal = $checkout_session['amount_subtotal'] / 100;
                if ($subtotal > 0) {
                    $tax_percentage = ($tax_amount / $subtotal) * 100;
                }
                error_log("Using tax from checkout session: ${tax_amount} ({$tax_percentage}%)");
            } elseif (isset($payment_intent['tax'])) {
                $tax_amount = $payment_intent['tax']['amount'] / 100;
                $tax_percentage = ($tax_amount / ($total_amount - $tax_amount)) * 100;
                error_log("Using tax from payment intent: ${tax_amount} ({$tax_percentage}%)");
            }
            
            $total_tax += $tax_amount;
            
            $line_item_data = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount' => $total_amount - $tax_amount, // Amount excluding tax
                'Description' => $product_name,
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => $item_id],
                    'UnitPrice' => $total_amount - $tax_amount,
                    'Qty' => 1
                ]
            ];
            
            // Use international tax code logic
            if ($tax_amount > 0) {
                $tax_code = get_tax_code_by_percentage($tax_percentage, $user_id);
                $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
            } else {
                $tax_code = get_default_non_taxable_code($user_id);
                $line_item_data['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $tax_code];
            }
            
            $line_items[] = $line_item_data;
        }
        
        // Get payment method
        $payment_method_type = $charge['payment_method_details']['type'] ?? 'card';
        $payment_method_id = get_qbo_payment_method($payment_method_type, $user_id);
        
        // Create QuickBooks sales receipt
        $sales_receipt_data = [
            'CustomerRef' => ['value' => $qbo_customer_id],
            'Line' => $line_items,
            'TxnDate' => date('Y-m-d', $payment_intent['created']),
            'TotalAmt' => $total_amount,
            'PrivateNote' => 'Stripe Payment Intent ID: ' . $payment_intent['id'],
            'PaymentRefNum' => $charge['id'],
            'PaymentMethodRef' => ['value' => $payment_method_id],
            'CurrencyRef' => ['value' => $currency]
        ];
        
        // Only add TxnTaxDetail for non-zero tax amounts and use proper tax code
        if ($total_tax > 0) {
            $is_us = is_qbo_us_account($user_id);
            $main_tax_code = $is_us ? 'TAX' : get_tax_code_by_percentage($tax_percentage, $user_id);
            
            $sales_receipt_data['TxnTaxDetail'] = [
                'TxnTaxCodeRef' => ['value' => $main_tax_code],
                'TotalTax' => $total_tax
            ];
        }
        
        // Add deposit account if available
        $deposit_account_id = get_qbo_account_id('Undeposited Funds', $user_id);
        if ($deposit_account_id) {
            $sales_receipt_data['DepositToAccountRef'] = ['value' => $deposit_account_id];
        }
        
        error_log("Sales receipt data prepared: " . print_r($sales_receipt_data, true));
        
        $result = make_qbo_api_request($user_id, '/salesreceipt', 'POST', $sales_receipt_data);
        error_log("QBO API response: " . print_r($result, true));
        
        if ($result['code'] !== 200 || !isset($result['body']['SalesReceipt']['Id'])) {
            $error_msg = "Failed to create sales receipt in QuickBooks";
            if (isset($result['body']['fault']['error'][0]['message'])) {
                $error_msg .= ": " . $result['body']['fault']['error'][0]['message'];
            }
            throw new Exception($error_msg);
        }
        
        $qbo_sales_receipt_id = $result['body']['SalesReceipt']['Id'];
        error_log("Sales receipt created successfully in QBO. Sales Receipt ID: " . $qbo_sales_receipt_id);
        
        // Store the mapping between Stripe payment intent and QBO sales receipt
        $stmt = $db->prepare("INSERT INTO payment_intent_mappings 
                            (user_id, stripe_payment_intent_id, qbo_sales_receipt_id, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $payment_intent['id'], $qbo_sales_receipt_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to save payment intent mapping: " . $stmt->error);
        }
        
        // Log success
        update_sync_history($history_id, 'success', 'Sales receipt created in QuickBooks', [
            'qbo_sales_receipt_id' => $qbo_sales_receipt_id,
            'stripe_payment_intent_id' => $payment_intent['id'],
            'qbo_response' => $result['body']
        ]);
        
    } catch (Exception $e) {
        error_log("Sales receipt creation failed: " . $e->getMessage());
        update_sync_history($history_id, 'failed', $e->getMessage(), [
            'error' => $e->getMessage(),
            'sales_receipt_data' => $sales_receipt_data ?? null,
            'qbo_response' => $result['body'] ?? null
        ]);
    }
}

/**
 * Get Stripe charge by ID
 */
function get_stripe_charge($charge_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/charges/" . $charge_id;
    
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
        error_log("Failed to fetch Charge: " . $charge_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Get Stripe Checkout Session associated with a Payment Intent
 */
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

/**
 * Get line items for a specific Checkout Session
 */
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

/**
 * Get Stripe invoice by ID
 */
function get_stripe_invoice($invoice_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/invoices/" . $invoice_id;
    
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
        error_log("Failed to fetch Invoice: " . $invoice_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Get Stripe customer by ID
 */
function get_stripe_customer66($customer_id, $stripe_api_key) {
    if (empty($stripe_api_key)) {
        error_log("Stripe API key is empty or invalid");
        return null;
    }

    $url = "https://api.stripe.com/v1/customers/" . $customer_id;
    
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
        error_log("Failed to fetch Customer: " . $customer_id . 
                " HTTP Code: " . $http_code . 
                " Response: " . $response);
        return null;
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
 * Find or create item in QuickBooks with proper product name handling
 */
function find_or_create_qbo_item($name, $stripe_product_id, $user_id, $description = '') {
    // First try to find by exact name match
    $query = "SELECT * FROM Item WHERE Name = '" . addslashes($name) . "'";
    $encoded_query = urlencode($query);
    $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
    
    if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Item'])) {
        return $result['body']['QueryResponse']['Item'][0]['Id'];
    }
    
    // If not found, try to create with the actual product name
    $income_account_id = get_qbo_account_id('Sales of Product Income', $user_id);
    if (!$income_account_id) {
        $income_account_id = '1'; // Fallback to default account
    }
    
    $item_data = [
        'Name' => substr($name, 0, 100), // Use the actual product name
        'Description' => !empty($description) ? substr($description, 0, 4000) : 'Payment received via Stripe',
        'Type' => 'Service',
        'IncomeAccountRef' => ['value' => $income_account_id],
        'Taxable' => true
    ];
    
    $result = make_qbo_api_request($user_id, '/item', 'POST', $item_data);
    
    // If creation with actual name fails, only then fall back to "Stripe Payment"
    if ($result['code'] !== 200 || !isset($result['body']['Item']['Id'])) {
        error_log("Failed to create item with name '{$name}', falling back to default");
        
        // Try to find existing "Stripe Payment" item
        $query = "SELECT * FROM Item WHERE Name = 'Stripe Payment'";
        $encoded_query = urlencode($query);
        $result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query);
        
        if ($result['code'] === 200 && !empty($result['body']['QueryResponse']['Item'])) {
            error_log("Using existing 'Stripe Payment' item");
            return $result['body']['QueryResponse']['Item'][0]['Id'];
        }
        
        // Create new "Stripe Payment" item if needed
        $item_data['Name'] = 'Stripe Payment';
        $result = make_qbo_api_request($user_id, '/item', 'POST', $item_data);
        
        if ($result['code'] !== 200 || !isset($result['body']['Item']['Id'])) {
            error_log("Failed to create default Stripe Payment item: " . print_r($result, true));
            return false;
        }
        
        error_log("Created new 'Stripe Payment' item as fallback");
    }
    
    return $result['body']['Item']['Id'];
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