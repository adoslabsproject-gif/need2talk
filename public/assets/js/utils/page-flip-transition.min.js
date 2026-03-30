/**
 * Page Flip Transition - 3D Card Flip Effect
 *
 * PERFORMANCE TARGET: <10ms overhead, 600ms animation
 * BROWSER SUPPORT: Chrome 111+, Safari 18+, Firefox (fallback)
 *
 * USAGE:
 * - Auto-enabled for configured routes
 * - Add routes to TRANSITION_CONFIG to expand
 * - Hardware-accelerated (GPU transform)
 *
 * ARCHITECTURE:
 * - Modular: Easy to enable/disable per route group
 * - Progressive Enhancement: View Transitions API + CSS fallback
 * - Zero dependencies
 */

class PageFlipTransition {
    constructor() {
        this.duration = 600; // Animation duration in ms
        this.supportsViewTransitions = 'startViewTransition' in document;

        // CONFIGURATION: Routes that support smooth fade transition
        // ENTERPRISE: Page flip enabled for ALL routes (guest + authenticated)
        this.config = {
            // Enable/disable per route group
            enabled: {
                auth: true,        // /auth/* routes - ✅ GUEST (page flip enabled)
                legal: true,       // /legal/* routes - ✅ GUEST (page flip enabled)
                home: true,        // / and /pages/* - ✅ GUEST (page flip enabled)
                postLogin: true,   // /feed, /profile/* - ✅ POST-LOGIN (page flip enabled)
            },

            // Specific routes that support transitions
            // Format: { path: '/route', group: 'group_name' }
            routes: [
                // GUEST ROUTES - DISABLED (normal browser navigation)
                // Auth routes (login/register flow)
                { path: '/auth/login', group: 'auth', name: 'Login' },
                { path: '/auth/register', group: 'auth', name: 'Register' },
                { path: '/auth/resend-verification-form', group: 'auth', name: 'Resend Verification' },
                { path: '/auth/verify-email-sent', group: 'auth', name: 'Email Sent' },
                { path: '/auth/forgot-password', group: 'auth', name: 'Forgot Password' },
                { path: '/auth/reset-password', group: 'auth', name: 'Reset Password' },

                // Legal routes
                { path: '/legal/terms', group: 'legal', name: 'Terms' },
                { path: '/legal/privacy', group: 'legal', name: 'Privacy' },
                { path: '/legal/contacts', group: 'legal', name: 'Contacts' },

                // Public pages
                { path: '/', group: 'home', name: 'Home' },
                { path: '/pages/about', group: 'home', name: 'About' },

                // POST-LOGIN ROUTES - ENABLED (page flip transitions)
                { path: '/feed', group: 'postLogin', name: 'Feed' },
                { path: '/profile', group: 'postLogin', name: 'Profile' },
            ],
        };

        // Performance tracking
        this.metrics = {
            transitionsTriggered: 0,
            averageOverhead: 0,
        };
    }

    /**
     * Initialize transition system
     */
    init() {
        // Check if we're on a supported route
        if (!this.isCurrentRouteSupported()) {
            return;
        }

        // Note: Page entry fade-in is now handled immediately at script load
        // See handleImmediateFadeIn() at bottom of file

        // Log initialization for debugging
        if (window.Logger) {
            window.Logger.debug('PageFlipTransition initialized', {
                currentPath: window.location.pathname,
                enabledGroups: this.config.enabled,
            });
        }

        // Intercept navigation clicks
        this.interceptTransitionLinks();

        // Handle browser back/forward
        this.handlePopState();
    }

    /**
     * Check if current route supports transitions
     */
    isCurrentRouteSupported() {
        const currentPath = window.location.pathname;

        return this.config.routes.some(route => {
            const isMatch = currentPath === route.path || currentPath.startsWith(route.path);
            const isEnabled = this.config.enabled[route.group];
            return isMatch && isEnabled;
        });
    }

    /**
     * Check if target URL is a supported transition route
     */
    isSupportedTransition(targetUrl) {
        try {
            const url = new URL(targetUrl, window.location.origin);
            const targetPath = url.pathname;

            // Find target route config
            const targetRoute = this.config.routes.find(route =>
                targetPath === route.path || targetPath.startsWith(route.path)
            );

            if (!targetRoute) return false;

            // Check if target group is enabled
            return this.config.enabled[targetRoute.group];

        } catch (e) {
            return false;
        }
    }

    /**
     * Intercept link clicks for transition-enabled routes
     */
    interceptTransitionLinks() {
        document.addEventListener('click', (e) => {
            const startTime = performance.now();

            // Find clicked link
            const link = e.target.closest('a[href]');
            if (!link) return;

            // Skip if link has target="_blank" or external
            if (link.target === '_blank' || link.hostname !== window.location.hostname) {
                return;
            }

            // Check if it's a supported transition
            const targetUrl = link.href;
            if (!this.isSupportedTransition(targetUrl)) {
                return;
            }

            // Prevent default navigation
            e.preventDefault();

            // Track performance overhead
            const overhead = performance.now() - startTime;
            this.updateMetrics(overhead);

            // Perform flip transition
            this.performFlipTransition(targetUrl);
        }, { passive: false });
    }

    /**
     * Handle browser back/forward buttons
     */
    handlePopState() {
        window.addEventListener('popstate', (e) => {
            // Clear any leftover transition flags
            try {
                sessionStorage.removeItem('page_transition_active');
            } catch (e) {
                // Ignore if sessionStorage is disabled
            }

            // Remove any orphaned overlays from previous transitions
            const orphanedOverlays = document.querySelectorAll('[style*="z-index: 2147483647"]');
            orphanedOverlays.forEach(overlay => {
                if (overlay.style.position === 'fixed' && overlay.style.background.includes('17, 24, 39')) {
                    overlay.remove();
                }
            });

            // Apply fade-in transition to incoming page (back navigation)
            this.applyBackNavigationFadeIn();
        });
    }

    /**
     * Apply fade-in effect when navigating back
     */
    applyBackNavigationFadeIn() {
        // Create temporary overlay that fades out
        const overlay = document.createElement('div');
        overlay.id = 'back-nav-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgb(17, 24, 39);
            opacity: 1;
            z-index: 2147483647;
            pointer-events: none;
            transition: opacity 400ms ease-in;
        `;

        document.body.appendChild(overlay);

        // Fade out overlay to reveal page
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                overlay.style.opacity = '0';
            });
        });

        // Remove overlay after fade completes
        setTimeout(() => {
            if (overlay && overlay.parentNode) {
                overlay.remove();
            }
        }, 450);
    }

    /**
     * Perform card flip transition
     */
    performFlipTransition(targetUrl) {
        this.metrics.transitionsTriggered++;

        if (window.Logger) {
            window.Logger.debug('Performing card flip transition', {
                from: window.location.pathname,
                to: new URL(targetUrl).pathname,
            });
        }

        // Use simple fade approach (reliable, no side effects)
        this.simpleFade(targetUrl);
    }

    /**
     * Simple fade transition (most reliable)
     * Fades current page to black, then navigates
     */
    simpleFade(targetUrl) {
        // Set flag for next page to fade-in
        try {
            sessionStorage.setItem('page_transition_active', 'true');
        } catch (e) {
            // sessionStorage might be disabled
        }

        // Hide cookie banner during transition to prevent z-index conflicts
        const cookieBanner = document.getElementById('cookie-consent-banner');
        if (cookieBanner) {
            cookieBanner.style.visibility = 'hidden';
        }

        // Create full-screen black overlay
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgb(17, 24, 39);
            opacity: 0;
            z-index: 2147483647;
            pointer-events: none;
            transition: opacity 400ms ease-out;
        `;

        document.body.appendChild(overlay);

        // Trigger fade-in of overlay (fade-out of page)
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
            });
        });

        // Navigate when overlay is fully opaque
        setTimeout(() => {
            window.location.href = targetUrl;
        }, 400);
    }

    /**
     * Update performance metrics
     */
    updateMetrics(overhead) {
        const count = this.metrics.transitionsTriggered;
        this.metrics.averageOverhead = (this.metrics.averageOverhead * count + overhead) / (count + 1);

        // Log if overhead is high (>10ms)
        if (overhead > 10 && window.Logger) {
            window.Logger.warning('PageFlipTransition overhead high', {
                overhead: overhead.toFixed(2) + 'ms',
                average: this.metrics.averageOverhead.toFixed(2) + 'ms',
            });
        }
    }

    /**
     * Get current metrics for debugging
     */
    getMetrics() {
        return {
            transitionsTriggered: this.metrics.transitionsTriggered,
            averageOverhead: this.metrics.averageOverhead.toFixed(2) + 'ms',
            supportsViewTransitions: this.supportsViewTransitions,
        };
    }
}

// NOTE: Page entry fade-in is now handled by inline <script> in <head>
// This ensures overlay is created BEFORE body renders (prevents flash on slow loads)

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.PageFlipTransition = new PageFlipTransition();
        window.PageFlipTransition.init();
    });
} else {
    window.PageFlipTransition = new PageFlipTransition();
    window.PageFlipTransition.init();
}

// Export for debugging
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PageFlipTransition;
}
