<?php
/**
 * Moderation Portal 2FA Verification Page - need2talk Enterprise
 *
 * Verifica codice 2FA inviato via email
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
    <title>need2talk - Verify 2FA</title>

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
            <h1 class="mod-auth-logo">Two-Factor Auth</h1>
            <p class="mod-auth-subtitle">Security Verification</p>
        </div>

        <!-- 2FA Card -->
        <div class="mod-auth-card">
            <!-- Icon -->
            <div class="flex justify-center mb-6">
                <div class="w-16 h-16 rounded-full flex items-center justify-center" style="background: rgba(217, 70, 239, 0.1); border: 1px solid rgba(217, 70, 239, 0.2);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" style="color: #d946ef;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
            </div>

            <?php if (!empty($error)): ?>
            <div class="mod-alert mod-alert-danger mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <p class="text-center text-sm mb-6" style="color: var(--mod-text-secondary); line-height: 1.6;">
                A 6-digit verification code has been sent to your email at<br>
                <strong style="color: #d946ef;"><?= htmlspecialchars($email ?? '***@***') ?></strong>
            </p>

            <form class="mod-auth-form" method="POST" action="<?= htmlspecialchars($modBaseUrl) ?>/verify-2fa" id="verifyForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="code" id="fullCode" value="">

                <!-- 6-digit code input -->
                <div class="mod-2fa-inputs">
                    <input type="text" class="mod-2fa-input" maxlength="1" data-index="0" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" class="mod-2fa-input" maxlength="1" data-index="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" class="mod-2fa-input" maxlength="1" data-index="2" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" class="mod-2fa-input" maxlength="1" data-index="3" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" class="mod-2fa-input" maxlength="1" data-index="4" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" class="mod-2fa-input" maxlength="1" data-index="5" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                </div>

                <button type="submit" class="mod-auth-btn" id="verifyBtn" disabled>
                    Verify Code
                </button>
            </form>

            <div class="mod-2fa-timer" id="timer">
                Code expires in <span id="countdown">5:00</span>
            </div>

            <div class="text-center mt-4">
                <a href="<?= htmlspecialchars($modBaseUrl) ?>/login" class="text-sm" style="color: #d946ef;">
                    Request new code
                </a>
            </div>
        </div>

        <a href="<?= htmlspecialchars($modBaseUrl) ?>/login" class="block text-center mt-6 text-sm" style="color: var(--mod-text-muted);">
            &larr; Back to Login
        </a>
    </div>

    <script nonce="<?= csp_nonce() ?>">
        const inputs = document.querySelectorAll('.mod-2fa-input');
        const fullCodeInput = document.getElementById('fullCode');
        const verifyBtn = document.getElementById('verifyBtn');
        const form = document.getElementById('verifyForm');

        // Focus first input on load
        inputs[0].focus();

        // Handle input
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                const value = e.target.value.replace(/[^0-9]/g, '');
                e.target.value = value;

                if (value && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }

                updateFullCode();
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });

            // Handle paste
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text');
                const digits = paste.replace(/[^0-9]/g, '').split('').slice(0, 6);

                digits.forEach((digit, i) => {
                    if (inputs[i]) {
                        inputs[i].value = digit;
                    }
                });

                if (digits.length > 0) {
                    inputs[Math.min(digits.length, 5)].focus();
                }

                updateFullCode();
            });
        });

        function updateFullCode() {
            const code = Array.from(inputs).map(input => input.value).join('');
            fullCodeInput.value = code;
            verifyBtn.disabled = code.length !== 6;
        }

        // Countdown timer
        let timeLeft = 5 * 60; // 5 minutes
        const countdownEl = document.getElementById('countdown');

        const timer = setInterval(() => {
            timeLeft--;

            if (timeLeft <= 0) {
                clearInterval(timer);
                countdownEl.textContent = 'Expired';
                verifyBtn.disabled = true;
                inputs.forEach(input => input.disabled = true);
            } else {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                countdownEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }
        }, 1000);
    </script>

    <?php
    // ENTERPRISE: Inject debugbar BODY assets
    if (function_exists('debugbar_render')) {
        echo debugbar_render();
    }
    ?>
</body>
</html>
