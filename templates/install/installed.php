<div class="container">
    <h2>Installation Complete</h2>
    <p class="success">Installation completed successfully! Please check your email to verify your account.</p>
    <p><strong>Post-Installation Steps:</strong></p>
    <ol style="text-align: left;">
        <li><strong>Verify your email</strong> by clicking the link sent to your email address.</li>
        <li><strong>Delete install.php</strong> from the public/ directory (optional — it locks itself, but removing it is best practice).</li>
        <li><strong>Set up a cron job</strong> to run the reminder sender every minute:<br>
            <code>* * * * * php <?= e($cronPath) ?></code></li>
    </ol>
    <a href="<?= e(url('/login')) ?>" class="btn">Go to Login</a>
</div>
