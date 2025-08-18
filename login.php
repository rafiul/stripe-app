<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo sanitize_output($error); ?></div>
<?php endif; ?>

<div class="login-container">
    <div class="login-left">
        <div class="login-header">
            <img src="logo.png" alt="TheCubeFactory Logo" class="logo">
            <h2>Welcome back</h2>
            <p>Please enter your details</p>
        </div>

        <form method="POST" action="<?php echo BASE_URL; ?>/login.php">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

            <div class="form-group">
                <label>Email address</label>
                <input type="text" name="username" placeholder="Enter your email" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>

            <div class="form-options">
                <label><input type="checkbox" name="remember"> Remember for 30 days</label>
                <a href="#">Forgot password</a>
            </div>

            <button type="submit" class="btn primary">Sign in</button>

            <button type="button" class="btn google">
                <img src="google-icon.png" alt="Google"> Sign in with Google
            </button>

            <p class="signup">Don’t have an account? <a href="signup.php">Sign up</a></p>
        </form>
    </div>

    <div class="login-right">
        <img src="illustration.svg" alt="Illustration">
    </div>
</div>