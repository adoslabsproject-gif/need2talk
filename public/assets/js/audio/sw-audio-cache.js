/**
 * SERVICE WORKER - MINIMAL PASS-THROUGH
 * ENTERPRISE V5.0.2 (2026-01-19)
 *
 * Purpose: Keep PWA installable without caching anything
 * All requests go directly to network - no stale data issues
 *
 * v5.0.2: Force update to clear cached HTML pages (title color fix)
 */

const SW_VERSION = 'v5.0.2';

// Install - just skip waiting
self.addEventListener('install', (event) => {
    console.log(`[SW] ${SW_VERSION} Installing (pass-through mode)`);
    self.skipWaiting();
});

// Activate - clear ALL old caches, claim clients
self.addEventListener('activate', (event) => {
    console.log(`[SW] ${SW_VERSION} Activating - clearing all caches`);

    event.waitUntil(
        Promise.all([
            // Delete ALL caches
            caches.keys().then(keys =>
                Promise.all(keys.map(key => {
                    console.log(`[SW] Deleting cache: ${key}`);
                    return caches.delete(key);
                }))
            ),
            // Delete IndexedDB audio cache if exists
            new Promise(resolve => {
                try {
                    const req = indexedDB.deleteDatabase('need2talk-audio-cache');
                    req.onsuccess = () => resolve();
                    req.onerror = () => resolve();
                    req.onblocked = () => resolve();
                } catch (e) {
                    resolve();
                }
            }),
            // Claim all clients
            self.clients.claim()
        ]).then(() => {
            // Notify all clients to reload
            self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'SW_ACTIVATED', version: SW_VERSION });
                });
            });
        })
    );
});

// ENTERPRISE V5.0.1 (2026-01-18): Fetch handler removed
// Chrome warning: "No-op fetch handler may bring overhead during navigation"
// Since we're in pass-through mode (no caching), we don't need fetch listener at all
// Requests go directly to network without Service Worker interception

// Message handler for version checks
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CACHE_VERSION_CHECK') {
        event.ports[0]?.postMessage({
            version: SW_VERSION,
            mode: 'pass-through',
            caching: false
        });
    }
});
