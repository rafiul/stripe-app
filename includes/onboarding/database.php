<form method="POST" action="onboarding.php?step=database">
    <h2 class="card-title mb-4">Database Setup</h2>
    <p>Please provide your database connection details. The database should already be created.</p>
    
    <div class="mb-3">
        <label for="base_url" class="form-label">Base URL</label>
        <input type="text" class="form-control" id="base_url" name="base_url" 
               value="<?php 
                   $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                   $host = $_SERVER['HTTP_HOST'];
                   $path = dirname($_SERVER['SCRIPT_NAME']);
                   $base_url = $protocol . '://' . $host . rtrim($path, '/');
                   echo isset($_SESSION['db_config']['base_url']) ? htmlspecialchars($_SESSION['db_config']['base_url']) : htmlspecialchars($base_url); 
               ?>" 
               required readonly>
        <div class="form-text">The full URL where your application will be installed</div>
    </div>
    
    <div class="mb-3">
        <label for="db_host" class="form-label">Database Host</label>
        <input type="text" class="form-control" id="db_host" name="db_host" 
               value="<?php echo isset($_SESSION['db_config']['host']) ? htmlspecialchars($_SESSION['db_config']['host']) : 'localhost'; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_name" class="form-label">Database Name</label>
        <input type="text" class="form-control" id="db_name" name="db_name" 
               value="<?php echo isset($_SESSION['db_config']['name']) ? htmlspecialchars($_SESSION['db_config']['name']) : ''; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_user" class="form-label">Database Username</label>
        <input type="text" class="form-control" id="db_user" name="db_user" 
               value="<?php echo isset($_SESSION['db_config']['user']) ? htmlspecialchars($_SESSION['db_config']['user']) : ''; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_pass" class="form-label">Database Password</label>
        <input type="password" class="form-control" id="db_pass" name="db_pass" 
               value="<?php echo isset($_SESSION['db_config']['pass']) ? htmlspecialchars($_SESSION['db_config']['pass']) : ''; ?>">
    </div>
    
    <div class="d-flex justify-content-between mt-4">
        <a href="onboarding.php?step=welcome" class="btn btn-secondary">Back</a>
        <button type="submit" class="btn btn-primary">Continue</button>
    </div>
</form>