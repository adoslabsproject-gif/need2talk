<?php
/**
 * Moderation Portal Layout - need2talk Enterprise
 *
 * Layout base per il Portale Moderazione (separato dall'Admin Panel)
 * Uses: base.css (Tailwind compiled) + moderation-portal.css component
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;
use Need2Talk\Middleware\ModerationAuthMiddleware;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$session = ModerationAuthMiddleware::getSession();
?>
<!DOCTYPE html>
<html lang="it" class="mod-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>need2talk Moderation - <?= htmlspecialchars($title ?? 'Dashboard') ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico">

    <!-- CSRF Token for AJAX requests -->
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
<body class="mod-theme" style="background: var(--mod-bg-primary); min-height: 100vh;">

    <!-- Header -->
    <header class="mod-header">
        <div class="mod-header-inner">
            <a href="<?= htmlspecialchars($modBaseUrl) ?>/dashboard" class="mod-logo">
                Moderation Portal
            </a>

            <?php if ($session): ?>
            <div class="mod-user-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/>
                </svg>
                <?= htmlspecialchars($session['display_name'] ?? $session['username'] ?? 'Moderator') ?>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($session): ?>
    <!-- Sidebar Navigation -->
    <aside class="mod-sidebar">
        <nav>
            <a href="<?= htmlspecialchars($modBaseUrl) ?>/dashboard"
               class="mod-nav-link <?= ($view ?? '') === 'dashboard' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4.5a.5.5 0 0 0 .5-.5v-4h2v4a.5.5 0 0 0 .5.5H14a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.354 1.146z"/>
                </svg>
                Dashboard
            </a>

            <a href="<?= htmlspecialchars($modBaseUrl) ?>/live"
               class="mod-nav-link <?= ($view ?? '') === 'live' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M14 10.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3zm-1 2.5h-2v-2h2v2zm1-6.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3zm-1 2.5h-2v-2h2v2zM8 10.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3zm-1 2.5H5v-2h2v2zm1-6.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3zm-1 2.5H5v-2h2v2z"/>
                    <path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/>
                    <circle cx="5" cy="14" r="1"/>
                    <circle cx="1" cy="10" r="1"/>
                </svg>
                Live Monitoring
            </a>

            <a href="<?= htmlspecialchars($modBaseUrl) ?>/bans"
               class="mod-nav-link <?= ($view ?? '') === 'bans' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                    <path d="M11.354 4.646a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708l6-6a.5.5 0 0 1 .708 0z"/>
                </svg>
                Ban Management
            </a>

            <a href="<?= htmlspecialchars($modBaseUrl) ?>/keywords"
               class="mod-nav-link <?= ($view ?? '') === 'keywords' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M2.5 1a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1H3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V4h.5a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1H2.5zm3 4a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 .5-.5zM8 5a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7A.5.5 0 0 1 8 5zm3 .5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 1 0z"/>
                </svg>
                Keywords
            </a>

            <a href="<?= htmlspecialchars($modBaseUrl) ?>/reports"
               class="mod-nav-link <?= ($view ?? '') === 'reports' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M14.778.085A.5.5 0 0 1 15 .5V8a.5.5 0 0 1-.314.464L14.5 8l.186.464-.003.001-.006.003-.023.009a12.435 12.435 0 0 1-.397.15c-.264.095-.631.223-1.047.35-.816.252-1.879.523-2.71.523-.847 0-1.548-.28-2.158-.525l-.028-.01C7.68 8.71 7.14 8.5 6.5 8.5c-.7 0-1.638.23-2.437.477A19.626 19.626 0 0 0 3 9.342V15.5a.5.5 0 0 1-1 0V.5a.5.5 0 0 1 1 0v.282c.226-.079.496-.17.79-.26C4.606.272 5.67 0 6.5 0c.84 0 1.524.277 2.121.519l.043.018C9.286.788 9.828 1 10.5 1c.7 0 1.638-.23 2.437-.477a19.587 19.587 0 0 0 1.349-.476l.019-.007.004-.002h.001"/>
                </svg>
                Reports
            </a>

            <a href="<?= htmlspecialchars($modBaseUrl) ?>/log"
               class="mod-nav-link <?= ($view ?? '') === 'log' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M14.5 3a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h13zm-13-1A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2h-13z"/>
                    <path d="M3 5.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5zM3 8a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 8zm0 2.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5z"/>
                </svg>
                Action Log
            </a>

            <a href="<?= htmlspecialchars($modBaseUrl) ?>/team"
               class="mod-nav-link <?= ($view ?? '') === 'team' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002a.274.274 0 0 1-.014.002H7.022zM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM6.936 9.28a5.88 5.88 0 0 0-1.23-.247A7.35 7.35 0 0 0 5 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816zM4.92 10A5.493 5.493 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275zM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0zm3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/>
                </svg>
                Team
            </a>

            <div class="mod-nav-separator"></div>

            <form action="<?= htmlspecialchars($modBaseUrl) ?>/logout" method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="mod-nav-link w-full text-left" style="border:none;background:none;cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                        <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                    </svg>
                    Logout
                </button>
            </form>
        </nav>
    </aside>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="mod-main" <?= !$session ? 'style="margin-left:0;"' : '' ?>>
        <div class="mod-container">
            <?php
            // Include the view content
            if (isset($content)) {
                echo $content;
            } elseif (isset($view)) {
                include __DIR__ . '/' . $view . '.php';
            }
            ?>
        </div>
    </main>

    <script nonce="<?= csp_nonce() ?>">
        // Moderation Portal JavaScript

        // CSRF token for AJAX requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        /**
         * Show toast notification
         */
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'mod-toast mod-toast-' + type;

            toast.innerHTML = `
                <div class="flex items-center gap-3">
                    <span style="color: var(--mod-text-primary);">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()"
                            style="background:none;border:none;color:var(--mod-text-muted);cursor:pointer;padding:0.25rem;font-size:1.25rem;">
                        &times;
                    </button>
                </div>
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.remove();
            }, 5000);
        }

        /**
         * Confirm action with custom dialog
         */
        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }

        /**
         * Make authenticated API request
         */
        async function modApiRequest(endpoint, method = 'GET', data = null) {
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };

            if (data && method !== 'GET') {
                options.body = JSON.stringify(data);
            }

            try {
                const response = await fetch('<?= htmlspecialchars($modBaseUrl) ?>' + endpoint, options);
                const result = await response.json();

                if (!result.success && result.redirect) {
                    window.location.href = result.redirect;
                    return null;
                }

                return result;
            } catch (error) {
                console.error('API request failed:', error);
                showToast('Request failed. Please try again.', 'error');
                return null;
            }
        }

        // Auto-refresh session activity (heartbeat)
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                modApiRequest('/api/rooms/heartbeat', 'POST').catch(() => {});
            }
        }, 60000); // Every minute
    </script>

    <?php
    // ENTERPRISE: Inject debugbar BODY assets
    if (function_exists('debugbar_render')) {
        echo debugbar_render();
    }
    ?>
</body>
</html>
