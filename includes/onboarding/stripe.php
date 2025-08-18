<form method="POST" action="onboarding.php?step=stripe">
    <h2 class="card-title mb-4">Stripe Configuration</h2>
    <p>Connect your Stripe account to enable payment processing.</p>
    
    <div class="mb-3">
        <label for="api_key" class="form-label">Stripe API Key</label>
        <input type="password" class="form-control" id="api_key" name="api_key" 
               value="<?php echo isset($_SESSION['stripe_config']['api_key']) ? htmlspecialchars($_SESSION['stripe_config']['api_key']) : ''; ?>" 
               required placeholder="sk_test_... or sk_live_...">
        <div class="form-text">We'll encrypt your key before storing it.</div>
    </div>
    
    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="is_live" name="is_live" 
               <?php echo isset($_SESSION['stripe_config']['is_live']) && $_SESSION['stripe_config']['is_live'] ? 'checked' : ''; ?>>
        <label class="form-check-label" for="is_live">This is a live production key</label>
    </div>
    
    <div class="mt-4">
        <h5>Where to find your API key:</h5>
        <ol>
            <li>Log in to your <a href="https://dashboard.stripe.com" target="_blank">Stripe Dashboard</a></li>
            <li>Go to Developers → API keys</li>
            <li>Copy your Secret Key (starts with sk_test_ or sk_live_)</li>
        </ol>
    </div>
    
    <div class="d-flex justify-content-between mt-4">
        <a href="onboarding.php?step=admin_account" class="btn btn-secondary">Back</a>
        <button type="submit" class="btn btn-primary">Continue</button>
    </div>
</form>