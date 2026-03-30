<?php
/**
 * Moderation Portal Login Page - need2talk Enterprise
 *
 * Login form per moderatori (separato dall'admin)
 * Uses: base.css (Tailwind compiled) + moderation-portal.css component
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
?>
<!DOCTYPE html>
<html lang="it" class="mod-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>need2talk - Moderation Login</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico">

    <!-- CSRF Token -->
    <?= \Need2Talk\Middleware\CsrfMiddleware::tokenMeta() ?>

    <!-- Enterprise CSS (Tailwind compiled + Moderation component) -->
    <link rel="stylesheet" href="<?= asset('base.css') ?>">

    <?php
    // ENTERPRISE: Inject debugbar HEAD assets
    if (function_exists('debugbar_render_head')) {
        echo debugbar_render_head();
    }
    ?>
</head>
<body class="mod-theme mod-auth-page">

    <div class="mod-auth-container">
        <!-- Header -->
        <div class="mod-auth-header">
            <h1 class="mod-auth-logo">Moderation Portal</h1>
            <p class="mod-auth-subtitle">need2talk Enterprise</p>
        </div>

        <!-- Login Card -->
        <div class="mod-auth-card">
            <?php if (!empty($error)): ?>
            <div class="mod-alert mod-alert-danger mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form class="mod-auth-form" method="POST" action="<?= htmlspecialchars($modBaseUrl) ?>/login" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="mod-form-group">
                    <label class="mod-form-label" for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="mod-form-input"
                        placeholder="moderator@need2talk.it"
                        required
                        autocomplete="email"
                        value="<?= htmlspecialchars($email ?? '') ?>"
                    >
                </div>

                <div class="mod-form-group">
                    <label class="mod-form-label" for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="mod-form-input"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button type="submit" class="mod-auth-btn" id="loginBtn">
                    <span class="spinner hidden"></span>
                    <span class="btn-text">Login</span>
                </button>
            </form>

            <div class="mod-auth-security-note">
                <strong>Enterprise Security</strong><br>
                This portal is protected by 2FA authentication.<br>
                All access attempts are logged and monitored.
            </div>
        </div>
    </div>

    <script nonce="<?= csp_nonce() ?>">
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const spinner = btn.querySelector('.spinner');
            const text = btn.querySelector('.btn-text');

            spinner.classList.remove('hidden');
            text.textContent = 'Authenticating...';
            btn.disabled = true;
        });
    </script>

    <?php
    // ENTERPRISE: Inject debugbar BODY assets
    if (function_exists('debugbar_render')) {
        echo debugbar_render();
    }
    ?>
</body>
</html>
