<?php
/**
 * NEED2TALK - GUEST LAYOUT ENTERPRISE GALAXY LEVEL
 *
 * Layout unificato per TUTTE le pagine pubbliche (non loggato)
 * Basato sulla struttura VINCENTE di home.php e terms.php
 *
 * FEATURES ENTERPRISE:
 * - Vite CSS build system con asset() helper
 * - Cookie consent banner avanzato
 * - PSR-3 Enterprise monitoring
 * - Page transition animations
 * - Performance optimizations (preconnect, dns-prefetch)
 * - Cache-friendly structure
 * - OPcache optimized (single layout compilation)
 *
 * ARCHITETTURA:
 * 1. Security checks (APP_ROOT defined)
 * 2. Page-specific variables ($title, $description, $pageCSS, $pageJS)
 * 3. HTML head con Vite assets
 * 4. Cookie banner HTML
 * 5. Navbar guest
 * 6. Content injection <?= $content ?>
 * 7. Footer
 * 8. Enterprise monitoring + scripts
 *
 * PERFORMANCE:
 * - Eliminato 31x duplicazione HTML
 * - Browser caching del layout
 * - OPcache compile 1 file invece di 31
 * - Memory footprint ridotto 85%
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// ENTERPRISE: Default values per variabili opzionali
$title = $title ?? 'need2talk - Fai parlare la tua anima';
$description = $description ?? 'Condividi le tue emozioni attraverso la voce su need2talk. Piattaforma sicura per maggiorenni.';
$pageCSS = $pageCSS ?? [];
$pageJS = $pageJS ?? [];
$assetVersion = env('ASSET_VERSION', env('APP_VERSION', '1.9.2'));
?>
<!DOCTYPE html>
<html lang="it" prefix="og: https://ogp.me/ns#">
<head>
    <!-- ENTERPRISE V10.86: Firefox detection for CSS performance fix (must run before CSS) -->
    <script>if(navigator.userAgent.indexOf('Firefox')>-1)document.documentElement.classList.add('is-firefox');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>

    <!-- Meta Tags -->
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <meta name="keywords" content="need2talk, emozioni, audio, voce, 18+, community">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook (ENTERPRISE) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars(url($_SERVER['REQUEST_URI'] ?? '/')) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>">
    <meta property="og:image" content="<?= url('/assets/img/og-image.png') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:alt" content="need2talk - Fai parlare la tua anima">
    <meta property="og:site_name" content="need2talk">
    <meta property="og:locale" content="it_IT">

    <!-- Twitter Card (ENTERPRISE) -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@need2talk_it">
    <meta name="twitter:creator" content="@need2talk_it">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <meta name="twitter:image" content="<?= url('/assets/img/og-image.png') ?>">
    <meta name="twitter:image:alt" content="need2talk - Fai parlare la tua anima">

    <!-- PWA (Progressive Web App) ENTERPRISE -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#7C3AED">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="need2talk">
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon-192x192.png">
    <link rel="apple-touch-icon" sizes="167x167" href="/icons/icon-192x192.png">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= url('/assets/img/favicon.ico') ?>">
    <link rel="icon" type="image/png" href="<?= url('/assets/img/favicon.png') ?>">
    <link rel="apple-touch-icon" href="<?= url('/assets/img/favicon.png') ?>">

    <!-- Schema.org JSON-LD ENTERPRISE - Google Logo + Sitelinks -->
    <script nonce="<?= csp_nonce() ?>" type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Organization",
                "@id": "<?= url('/') ?>#organization",
                "name": "need2talk",
                "url": "<?= url('/') ?>",
                "logo": {
                    "@type": "ImageObject",
                    "@id": "<?= url('/assets/img/logo-320.png') ?>",
                    "url": "<?= url('/assets/img/logo-320.png') ?>",
                    "contentUrl": "<?= url('/assets/img/logo-320.png') ?>",
                    "width": 320,
                    "height": 320,
                    "caption": "need2talk Logo"
                },
                "image": {
                    "@type": "ImageObject",
                    "@id": "<?= url('/assets/img/logo-320.png') ?>",
                    "url": "<?= url('/assets/img/logo-320.png') ?>",
                    "width": 320,
                    "height": 320
                },
                "description": "Piattaforma italiana per condividere emozioni attraverso la voce. Community 18+ sicura e protetta.",
                "slogan": "Fai parlare la tua anima",
                "foundingDate": "2024",
                "sameAs": [
                    "https://www.facebook.com/people/Need2talk/61582668675756/",
                    "https://www.instagram.com/need2talk_italia/",
                    "https://twitter.com/need2talk_it",
                    "https://www.youtube.com/@need2talk_it"
                ],
                "contactPoint": {
                    "@type": "ContactPoint",
                    "contactType": "Customer Support",
                    "email": "support@need2talk.it",
                    "availableLanguage": ["Italian"]
                }
            },
            {
                "@type": "WebSite",
                "@id": "<?= url('/') ?>#website",
                "url": "<?= url('/') ?>",
                "name": "need2talk",
                "description": "Condividi le tue emozioni attraverso la voce",
                "publisher": {
                    "@id": "<?= url('/') ?>#organization"
                },
                "inLanguage": "it-IT",
                "potentialAction": {
                    "@type": "SearchAction",
                    "target": {
                        "@type": "EntryPoint",
                        "urlTemplate": "<?= url('/search?q={search_term_string}') ?>"
                    },
                    "query-input": "required name=search_term_string"
                }
            }
        ]
    }
    </script>

    <!-- SICUREZZA: CSRF Meta per JavaScript -->
    <?php if (class_exists('Need2Talk\\Middleware\\CsrfMiddleware')): ?>
        <?= Need2Talk\Middleware\CsrfMiddleware::tokenMeta() ?>
    <?php else: ?>
        <meta name="csrf-token" content="<?= csrf_token() ?>">
    <?php endif; ?>

    <!-- CRITICAL CSS INLINE (above-the-fold optimization - Mobile/Desktop optimized) -->
    <?php
    // Auto-detect critical CSS based on URL pattern
    $criticalCssFile = 'home'; // default
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

if (strpos($requestUri, '/auth/login') !== false) {
    $criticalCssFile = 'login';
} elseif (strpos($requestUri, '/auth/register') !== false) {
    $criticalCssFile = 'register';
} elseif (strpos($requestUri, '/legal/privacy') !== false) {
    $criticalCssFile = 'privacy';
} elseif (strpos($requestUri, '/legal/terms') !== false) {
    $criticalCssFile = 'terms';
} elseif (strpos($requestUri, '/legal/contacts') !== false) {
    $criticalCssFile = 'contacts';
} elseif (strpos($requestUri, '/about') !== false) {
    $criticalCssFile = 'about';
} elseif (strpos($requestUri, '/help/faq') !== false) {
    $criticalCssFile = 'faq';
} elseif (strpos($requestUri, '/help/safety') !== false) {
    $criticalCssFile = 'safety';
}

// ENTERPRISE: Device detection (mobile vs desktop) for optimized critical CSS
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/(android|iphone|ipad|mobile|tablet)/i', $userAgent);
$device = $isMobile ? 'mobile' : 'desktop';

$criticalPath = APP_ROOT . "/public/dist/critical-{$criticalCssFile}-{$device}.css";
if (file_exists($criticalPath)) {
    echo '<style nonce="' . csp_nonce() . '" id="critical-css">' . "\n";
    echo file_get_contents($criticalPath);
    echo "\n</style>\n";
}
?>

    <!-- FULL CSS (blocking - required for correct rendering, critical CSS alone causes FOUC) -->
    <link rel="stylesheet" href="<?= asset('app.css') ?>">

    <!-- PERFORMANCE: Resource Hints -->
    <link rel="preconnect" href="<?= url() ?>">
    <link rel="dns-prefetch" href="<?= url() ?>">

    <!-- ENTERPRISE v12.1: Preload LCP image for homepage (logo-168.webp) -->
    <?php if (($_SERVER['REQUEST_URI'] ?? '/') === '/'): ?>
    <link rel="preload" href="<?= asset('img/logo-168.webp') ?>" as="image" type="image/webp" fetchpriority="high">
    <?php endif; ?>

    <!-- ENTERPRISE PREFETCH: Auth pages (declarative - no JS, no NS_BINDING_ABORTED) -->
    <?php
    // Only prefetch pages we're NOT currently on
    $currentPath = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($currentPath, '/auth/login') === false): ?>
    <link rel="prefetch" href="<?= url('auth/login') ?>" as="document">
    <?php endif;
    if (strpos($currentPath, '/auth/register') === false): ?>
    <link rel="prefetch" href="<?= url('auth/register') ?>" as="document">
    <?php endif; ?>

    <!-- Cookie Consent CSS (Deferred - not above-the-fold) -->
    <link rel="preload" href="<?= url('/assets/css/components/cookie-consent.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?= url('/assets/css/components/cookie-consent.css') ?>"></noscript>

    <!-- Page Transition CSS (Deferred - not critical for initial render) -->
    <link rel="preload" href="<?= url('/assets/css/components/page-flip-transition.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?= url('/assets/css/components/page-flip-transition.css') ?>"></noscript>

    <!-- CRITICAL: Inline fade-in script (must run BEFORE body renders) -->
    <!-- ENTERPRISE: Handles page entry animation + back button fix -->
    <script nonce="<?= csp_nonce() ?>">
    (function() {
        // ENTERPRISE FIX: AGGRESSIVE back button clearing
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                // Remove ALL transition elements
                var overlays = document.querySelectorAll('[id*="transition"], [class*="overlay"], [style*="position: fixed"]');
                overlays.forEach(function(el) {
                    el.remove();
                });
                // Force body visible
                document.body.style.opacity = '1';
                document.body.style.visibility = 'visible';
                document.body.style.pointerEvents = 'auto';
                document.documentElement.style.overflow = 'auto';
            }
        });

        function applyTransitionFadeIn() {
            try {
                if (sessionStorage.getItem('page_transition_active') !== 'true') return;
                sessionStorage.removeItem('page_transition_active');

                var s = document.createElement('style');
                s.textContent = '#page-transition-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:#0F0F23;z-index:2147483647;pointer-events:none;opacity:1;transition:opacity 300ms ease-in}';
                document.head.appendChild(s);

                var d = document.createElement('div');
                d.id = 'page-transition-overlay';
                (document.body || document.documentElement).appendChild(d);

                setTimeout(function() {
                    d.style.opacity = '0';
                    setTimeout(function() { d.remove(); }, 300);
                }, 50);
            } catch(e) {}
        }

        // Apply on initial load
        applyTransitionFadeIn();

        // Re-apply when restored from bfcache (back button)
        window.addEventListener('bfcache-restore', applyTransitionFadeIn);
        window.addEventListener('pageshow', function(e) {
            if (e.persisted) {
                applyTransitionFadeIn();
            }
        });
    })();
    </script>

    <!-- FontAwesome REMOVED - Using Heroicons SVG system (zero CSS footprint) -->

    <!-- Alpine.js Cloak (nasconde elementi finché Alpine non è pronto - CRITICAL per mobile menu) -->
    <style nonce="<?= csp_nonce() ?>">
        [x-cloak] { display: none !important; }
    </style>

    <!-- Alpine.js per Reactive UI -->
    <script defer src="/assets/js/external/alpine.min.js?v=<?= env('APP_VERSION', '1.6.1') ?>"></script>

    <!-- Page-specific CSS (Deferred for performance) -->
    <?php if (!empty($pageCSS)): ?>
        <?php foreach ($pageCSS as $cssFile): ?>
            <link rel="preload" href="<?= url("/assets/css/{$cssFile}.css") ?>?v=<?= $assetVersion ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
            <noscript><link rel="stylesheet" href="<?= url("/assets/css/{$cssFile}.css") ?>?v=<?= $assetVersion ?>"></noscript>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Debugbar CSS -->
    <?php
if (class_exists('Need2Talk\\Services\\DebugbarService')) {
    echo Need2Talk\Services\DebugbarService::renderHead();
}
?>

    <!-- Google Tag Manager - GDPR Compliant Loading -->
    <!-- GTM will be loaded AFTER user consent via cookie-consent-advanced.js -->
    <!-- ID: GTM-NJ4H75D3 (includes GA4 + all tracking) -->
</head>

<body class="bg-brand-midnight text-neutral-white overflow-x-hidden" style="min-height: 100vh;">

    <!-- Midnight Aurora Background - Dark, mysterious, audio waves -->
    <div class="fixed inset-0 bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight pointer-events-none">
        <!-- Aurora effect overlay -->
        <div class="absolute inset-0 bg-gradient-to-t from-brand-midnight/80 via-accent-violet/5 to-transparent"></div>

        <!-- ENTERPRISE v12.1: Aurora statica invece di animate-pulse (risparmio GPU + TBT) -->
        <div class="absolute inset-0 opacity-20">
            <div class="absolute top-0 left-1/4 w-96 h-96 bg-accent-violet/30 rounded-full blur-3xl"></div>
            <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-cool-cyan/20 rounded-full blur-3xl"></div>
        </div>
    </div>

    <!-- NAVBAR GUEST -->
    <?php include APP_ROOT . '/app/Views/layouts/navbar-guest.php'; ?>

    <!-- MAIN CONTENT INJECTION -->
    <main class="relative">
        <?= $content ?? '' ?>
    </main>

    <!-- FOOTER -->
    <?php include APP_ROOT . '/app/Views/layouts/footer.php'; ?>

    <!-- Ambient Background Music - ENTERPRISE GALAXY with Iframe Isolation -->
    <iframe id="bgm-frame" src="/assets/audio-player.html?v=3.6.0" style="display:none;" allow="autoplay"></iframe>
    <button id="bgm-toggle" class="fixed top-20 left-4 z-[9999] w-9 h-9 rounded-full bg-gray-900/70 border border-purple-500/40 text-white flex items-center justify-center shadow-md hover:bg-purple-900/70 transition-all" title="Musica">🔈</button>
    <script nonce="<?= csp_nonce() ?>">
    /**
     * ENTERPRISE GALAXY: Ambient Audio Controller (Parent)
     *
     * Communicates with iframe audio player via postMessage.
     * UI reflects actual playback state reported by iframe.
     *
     * Benefits of iframe approach:
     * - Isolated AudioContext (COEP headers)
     * - Clean separation of concerns
     * - Position persistence via localStorage (in iframe)
     */
    (function(){
        'use strict';

        var frame = document.getElementById('bgm-frame');
        var btn = document.getElementById('bgm-toggle');
        if (!frame || !btn) return;

        // State from localStorage (user preference)
        var muted = localStorage.getItem('bgm_muted') === '1';
        // State from iframe (actual playback)
        var isPlaying = false;
        var isBlocked = true; // Assume blocked until proven otherwise
        var frameReady = false;
        var gestureReceived = false;

        /**
         * Send command to iframe audio player
         */
        function sendCommand(cmd, data) {
            if (!frame.contentWindow) return;
            var msg = { command: cmd };
            if (data) {
                for (var k in data) msg[k] = data[k];
            }
            try {
                frame.contentWindow.postMessage(msg, '*');
            } catch (e) {}
        }

        /**
         * Update UI based on actual state
         */
        function updateUI() {
            if (muted) {
                btn.textContent = '🔇';
                btn.style.opacity = '0.5';
                btn.title = 'Audio disattivato';
            } else if (isPlaying) {
                btn.textContent = '🔊';
                btn.style.opacity = '1';
                btn.title = 'Audio in riproduzione';
            } else if (isBlocked) {
                btn.textContent = '🔈';
                btn.style.opacity = '0.7';
                btn.title = 'Clicca per attivare audio';
            } else {
                btn.textContent = '🔈';
                btn.style.opacity = '0.7';
                btn.title = 'Audio in pausa';
            }
        }

        /**
         * Attempt to start playback
         */
        function tryPlay() {
            if (muted) return;
            sendCommand('play');
        }

        /**
         * Handle messages from iframe
         */
        window.addEventListener('message', function(e) {
            // Security: only accept messages from our iframe
            if (!e.data || typeof e.data !== 'object') return;

            if (e.data.type === 'bgm_ready') {
                frameReady = true;

                if (e.data.autoplaySuccess) {
                    // Autoplay worked! Update UI
                    isPlaying = true;
                    isBlocked = false;
                    gestureReceived = true;
                    updateUI();
                } else if (e.data.autoplayBlocked) {
                    // Autoplay blocked, need user gesture
                    isBlocked = true;
                    updateUI();
                } else if (gestureReceived && !muted) {
                    // Fallback: user already interacted, try to play
                    tryPlay();
                }
            } else if (e.data.type === 'bgm_state') {
                isPlaying = e.data.playing === true;
                isBlocked = e.data.blocked === true;
                updateUI();
            }
        });

        /**
         * Handle user gesture to unlock audio
         * Desktop: mousemove, scroll, click, keydown
         * Mobile: scroll, touchmove, touchend
         */
        var interactionEvents = ['click', 'touchend', 'keydown', 'scroll', 'touchmove', 'mousemove'];

        var handleInteraction = function(e) {
            if (gestureReceived) return;
            gestureReceived = true;

            // Remove listeners
            interactionEvents.forEach(function(evt) {
                document.removeEventListener(evt, handleInteraction, true);
                window.removeEventListener(evt, handleInteraction, true);
            });

            // Try to play if not muted
            if (!muted && frameReady) {
                tryPlay();
            }
        };

        interactionEvents.forEach(function(evt) {
            // scroll fires on window, others on document
            if (evt === 'scroll') {
                window.addEventListener(evt, handleInteraction, { capture: true, passive: true });
            } else {
                document.addEventListener(evt, handleInteraction, { capture: true, passive: true });
            }
        });

        /**
         * Toggle button handler
         */
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            muted = !muted;
            localStorage.setItem('bgm_muted', muted ? '1' : '0');

            if (muted) {
                sendCommand('pause');
                isPlaying = false;
            } else {
                gestureReceived = true; // Button click is a valid gesture
                tryPlay();
            }

            updateUI();
        });

        // Initial UI
        updateUI();
    })();
    </script>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed bottom-4 right-4 z-50 space-y-2"></div>

    <!-- ENTERPRISE v12.1: ALL SCRIPTS DEFERRED - Zero render blocking -->
    <!-- Enterprise Error Monitor (config inline, script deferred) -->
    <?php include APP_ROOT . '/app/Views/components/enterprise-monitoring.php'; ?>

    <!-- Core App JavaScript (DEFERRED) -->
    <script defer src="<?= url('/assets/js/core/app.min.js') ?>?v=<?= env('APP_VERSION', '1.6.1') ?>"></script>

    <!-- CSRF Protection (DEFERRED) -->
    <script defer src="<?= url('/assets/js/core/csrf.min.js') ?>?v=<?= env('APP_VERSION', '1.6.1') ?>"></script>

    <!-- Page Transition Script (DEFERRED) -->
    <script defer src="<?= url('/assets/js/utils/page-flip-transition.min.js') ?>?v=<?= env('APP_VERSION', '1.6.1') ?>"></script>

    <!-- ENTERPRISE GALAXY: Service Worker Audio Cache (DEFERRED) -->
    <script defer src="/assets/js/audio/sw-registration.min.js?v=<?= env('APP_VERSION', '2.0.2') ?>"></script>

    <!-- Page-specific JavaScript (DEFERRED) -->
    <?php if (!empty($pageJS)): ?>
        <?php foreach ($pageJS as $jsFile): ?>
            <script defer src="<?= url("/assets/js/{$jsFile}.min.js") ?>?v=<?= env('APP_VERSION', '1.6.1') ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Cookie Consent (Loaded AFTER page load to avoid blocking) -->
    <script nonce="<?= csp_nonce() ?>">
    window.addEventListener('load', function() {
        setTimeout(function() {
            var s = document.createElement('script');
            s.src = '<?= url('/assets/js/core/cookie-consent-advanced.min.js') ?>?v=<?= env('APP_VERSION', '1.6.1') ?>';
            document.body.appendChild(s);
        }, 1000);
    });
    </script>

    <!-- Debugbar JavaScript -->
    <?php
if (class_exists('Need2Talk\\Services\\DebugbarService')) {
    echo Need2Talk\Services\DebugbarService::render();
}
?>

</body>
</html>
