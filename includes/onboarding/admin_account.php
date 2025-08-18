<form method="POST" action="onboarding.php?step=admin_account">
    <h2 class="card-title mb-4">Create Admin Account</h2>
    <p>Create your administrator account for the application.</p>
    
    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" class="form-control" id="username" name="username" 
               value="<?php echo isset($_SESSION['admin_account']['username']) ? htmlspecialchars($_SESSION['admin_account']['username']) : ''; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control" id="email" name="email" 
               value="<?php echo isset($_SESSION['admin_account']['email']) ? htmlspecialchars($_SESSION['admin_account']['email']) : ''; ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required>
        <div class="form-text">Password must be at least 8 characters</div>
    </div>
    
    <div class="mb-3">
        <label for="confirm_password" class="form-label">Confirm Password</label>
        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
    </div>
    
    <div class="d-flex justify-content-between mt-4">
        <a href="onboarding.php?step=create_tables" class="btn btn-secondary">Back</a>
        <button type="submit" class="btn btn-primary">Continue</button>
    </div>
</form>