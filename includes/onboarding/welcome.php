<h2 class="card-title mb-4">Welcome to Setup</h2>
<p>This setup wizard will guide you through configuring your Stripe to QuickBooks integration.</p>
<p>You'll need the following information ready:</p>

<div class="accordion mb-4" id="setupRequirements">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#databaseInfo">
                <i class="bi bi-database me-2"></i> Database Credentials
            </button>
        </h2>
        <div id="databaseInfo" class="accordion-collapse collapse show" data-bs-parent="#setupRequirements">
            <div class="accordion-body">
                <p>You'll need the following database details:</p>
                <ul>
                    <li><strong>Host:</strong> Usually 'localhost' or your server's IP address</li>
                    <li><strong>Database Name:</strong> The name of the database you created for this application</li>
                    <li><strong>Username:</strong> Database user with full privileges to the database</li>
                    <li><strong>Password:</strong> The password for the database user</li>
                </ul>
                <p class="mb-0"><strong>Note:</strong> The database must already exist before setup. This wizard will create the necessary tables.</p>
            </div>
        </div>
    </div>
    
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#baseUrlInfo">
                <i class="bi bi-globe me-2"></i> Base URL
            </button>
        </h2>
        <div id="baseUrlInfo" class="accordion-collapse collapse" data-bs-parent="#setupRequirements">
            <div class="accordion-body">
                <p>The Base URL is automatically detected from your current URL:</p>
                <div class="alert alert-secondary">
                    <?php 
                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'];
                        $path = dirname($_SERVER['SCRIPT_NAME']);
                        $base_url = $protocol . '://' . $host . rtrim($path, '/');
                        echo htmlspecialchars($base_url);
                    ?>
                </div>
                <p class="mb-0">This URL should point to the root directory of your application.</p>
            </div>
        </div>
    </div>
    
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#stripeInfo">
                <i class="bi bi-credit-card me-2"></i> Stripe API Keys
            </button>
        </h2>
        <div id="stripeInfo" class="accordion-collapse collapse" data-bs-parent="#setupRequirements">
            <div class="accordion-body">
                <p>You'll need your Stripe Secret API Key:</p>
                <ol>
                    <li>Log in to your <a href="https://dashboard.stripe.com" target="_blank">Stripe Dashboard</a></li>
                    <li>Go to <strong>Developers</strong> → <strong>API keys</strong></li>
                    <li>Copy your <strong>Secret Key</strong> (starts with sk_test_ or sk_live_)</li>
                </ol>
                <div class="alert alert-warning">
                    <strong>Important:</strong> For production, use your live keys (starting with sk_live_). For testing, use test keys (starting with sk_test_).
                </div>
            </div>
        </div>
    </div>
    
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#quickbooksInfo">
                <i class="bi bi-cash-stack me-2"></i> QuickBooks OAuth Credentials
            </button>
        </h2>
        <div id="quickbooksInfo" class="accordion-collapse collapse" data-bs-parent="#setupRequirements">
            <div class="accordion-body">
                <p>You'll need your QuickBooks OAuth 2.0 credentials:</p>
                <ol>
                    <li>Go to the <a href="https://developer.intuit.com/" target="_blank">QuickBooks Developer Portal</a></li>
                    <li>Create or select your app</li>
                    <li>Find your <strong>Client ID</strong> and <strong>Client Secret</strong></li>
                    <li>Make sure to add the redirect URI to your app's allowed redirect URIs</li>
                </ol>
                <p>The redirect URI will be shown to you in the QuickBooks configuration step.</p>
                <div class="alert alert-info">
                    <strong>Note:</strong> You'll need to authenticate with QuickBooks after setup to complete the connection.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-grid gap-2 mt-4">
    <a href="onboarding.php?step=database" class="btn btn-primary">Get Started</a>
</div>