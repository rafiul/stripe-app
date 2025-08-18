<form method="POST" action="onboarding.php?step=quickbooks">
    <h2 class="card-title mb-4">QuickBooks Configuration</h2>
    <p>Connect your QuickBooks account to enable accounting integration.</p>
    
    <div class="mb-3">
        <label for="qbo_client_id" class="form-label">QuickBooks Client ID</label>
        <input type="text" class="form-control" id="qbo_client_id" name="qbo_client_id" 
               value="<?php echo isset($_SESSION['quickbooks_config']['client_id']) ? htmlspecialchars($_SESSION['quickbooks_config']['client_id']) : ''; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="qbo_client_secret" class="form-label">QuickBooks Client Secret</label>
        <input type="password" class="form-control" id="qbo_client_secret" name="qbo_client_secret" 
               value="<?php echo isset($_SESSION['quickbooks_config']['client_secret']) ? htmlspecialchars($_SESSION['quickbooks_config']['client_secret']) : ''; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="qbo_environment" class="form-label">Environment</label>
        <select class="form-select" id="qbo_environment" name="qbo_environment" required>
            <!-- <option value="sandbox" <?php echo isset($_SESSION['quickbooks_config']['environment']) && $_SESSION['quickbooks_config']['environment'] === 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testing)</option> -->
            <option value="production" <?php echo isset($_SESSION['quickbooks_config']['environment']) && $_SESSION['quickbooks_config']['environment'] === 'production' ? 'selected' : ''; ?>>Production</option>
        </select>
    </div>
    
    <div class="alert alert-info">
        <strong>QuickBooks Redirect URI:</strong><br>
        <?php 
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['SCRIPT_NAME']);
            $redirect_uri = $protocol . '://' . $host . rtrim($path, '/') . '/quickbooks-callback.php';
            echo htmlspecialchars($redirect_uri);
        ?>
        <div class="mt-2">You must add this URL to your QuickBooks app's allowed redirect URIs.</div>
    </div>
    
    <div class="mt-4">
        <h5>How to get your credentials:</h5>
        <ol>
            <li>Go to the <a href="https://developer.intuit.com/" target="_blank">QuickBooks Developer Portal</a></li>
            <li>Create or select your app</li>
            <li>Find your OAuth 2.0 credentials (Client ID and Client Secret)</li>
            <li>Add the Redirect URI shown above to your app's allowed redirect URIs</li>
        </ol>
    </div>
    
    <div class="d-flex justify-content-between mt-4">
        <a href="onboarding.php?step=stripe" class="btn btn-secondary">Back</a>
        <button type="submit" class="btn btn-primary">Continue</button>
    </div>
</form>