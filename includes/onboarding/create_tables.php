<div class="text-center">
    <div class="mb-4">
        <div class="loading-spinner text-primary" style="width: 3rem; height: 3rem;"></div>
    </div>
    <h2 class="card-title mb-3">Creating Database Tables</h2>
    <p class="mb-4">Please wait while we set up your database...</p>
</div>

<script>
// Automatically proceed to create tables
window.onload = function() {
    setTimeout(function() {
        window.location.href = 'onboarding.php?step=create_tables';
    }, 100);
};
</script>