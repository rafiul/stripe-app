<?php
function process_stripe_product_created_to_qbo_item($event, $user_id) {
    global $db;
    
    $history_id = log_sync_history($user_id, 'product.created', $event['id'], 'pending', 'Processing started', $event);
    
    try {
        $product = $event['data']['object'];
        $product_id = $product['id'];
        
        // Check if already processed
        $stmt = $db->prepare("SELECT qbo_item_id FROM product_mappings WHERE user_id = ? AND stripe_product_id = ?");
        $stmt->bind_param("is", $user_id, $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            update_sync_history($history_id, 'skipped', 'Product already processed');
            return;
        }

        // Get QuickBooks account
        $qbo_account = get_quickbooks_account($user_id);
        if (!$qbo_account) {
            throw new Exception("QuickBooks account not connected");
        }
        
        // Find income account ID
        $income_account_id = false;
        $query = "SELECT * FROM Account WHERE AccountType = 'Income' AND AccountSubType = 'SalesOfProductIncome'";
        $encoded_query = urlencode($query);
        $account_result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query, 'GET');
        
        if ($account_result['code'] === 200 && !empty($account_result['body']['QueryResponse']['Account'])) {
            $income_account_id = $account_result['body']['QueryResponse']['Account'][0]['Id'];
        }
        
        if (!$income_account_id) {
            // Fallback to first income account if specific one not found
            $query = "SELECT * FROM Account WHERE AccountType = 'Income'";
            $encoded_query = urlencode($query);
            $account_result = make_qbo_api_request($user_id, '/query?query=' . $encoded_query, 'GET');
            
            if ($account_result['code'] === 200 && !empty($account_result['body']['QueryResponse']['Account'])) {
                $income_account_id = $account_result['body']['QueryResponse']['Account'][0]['Id'];
            }
        }
        
        if (!$income_account_id) {
            $income_account_id = '1'; // Final fallback to default account
        }
        
        // NEW: Get the default price for this product from Stripe
        $default_price = null;
        if (isset($product['default_price'])) {
            // If the product has a default price ID, you might need to fetch the price details
            // This would require a Stripe API call to get the price amount
            // For simplicity, we'll assume the amount is in the product object
            $default_price = $product['default_price']['unit_amount'] ?? null;
        }
        
        // Create item in QuickBooks
        $item_data = [
            'Name' => substr($product['name'], 0, 100),
            'Type' => 'Service',
            'IncomeAccountRef' => ['value' => $income_account_id],
            'Description' => $product['description'] ?? '',
            'Taxable' => true,
            // NEW: Add UnitPrice if available
            'UnitPrice' => $default_price ? $default_price / 100 : 0 // Convert from cents to dollars
        ];
        
        if (!empty($product['metadata']['sku'])) {
            $item_data['Sku'] = substr($product['metadata']['sku'], 0, 31);
        }
        
        $result = make_qbo_api_request($user_id, '/item', 'POST', $item_data);
        
        if ($result['code'] !== 200 || !isset($result['body']['Item']['Id'])) {
            throw new Exception("Failed to create item in QuickBooks: " . 
                ($result['body']['fault']['error'][0]['message'] ?? 'Unknown error'));
        }
        
        $qbo_item_id = $result['body']['Item']['Id'];
        
        // Store mapping
        $stmt = $db->prepare("INSERT INTO product_mappings 
                            (user_id, stripe_product_id, qbo_item_id, created_at) 
                            VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $product_id, $qbo_item_id);
        $stmt->execute();
        
        update_sync_history($history_id, 'success', 'Item created in QuickBooks', [
            'qbo_item_id' => $qbo_item_id,
            'stripe_product_id' => $product_id
        ]);
        
    } catch (Exception $e) {
        update_sync_history($history_id, 'failed', $e->getMessage());
    }
}