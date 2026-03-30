<!-- ENTERPRISE GALAXY V8.0: DISTRIBUTED WORKERS & FEED PRE-COMPUTATION MONITORING -->
<h2 class="enterprise-title mb-8 flex items-center">
    <svg class="w-8 h-8 mr-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
    </svg>
    Monitor Enterprise V8.0
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(139, 92, 246, 0.2); color: #8b5cf6; font-weight: 600;">
        ARCHITETTURA DISTRIBUITA
    </span>
</h2>

<?php
// Initial status will be loaded via AJAX
$initialStatus = $status ?? [];
?>

<!-- Overall Health Status -->
<div id="overall-health-banner" class="mb-6 p-4 rounded-lg flex items-center justify-between" style="background: rgba(16, 185, 129, 0.1); border: 2px solid rgba(16, 185, 129, 0.3);">
    <div class="flex items-center">
        <svg class="w-6 h-6 mr-3 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="text-lg font-semibold text-green-400" id="overall-health-text">Caricamento...</span>
    </div>
    <span class="text-sm text-gray-400" id="last-update">Ultimo aggiornamento: --</span>
</div>

<!-- Services Status Cards -->
<div class="stats-grid mb-8" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <!-- Active User Tracker -->
    <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
        <span class="stat-value" style="color: #8b5cf6;" id="active-users-count">--</span>
        <div class="stat-label">
            <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            Utenti Attivi Tracciati
        </div>
        <div class="text-xs mt-2" id="active-users-status">
            <span class="px-2 py-1 rounded" style="background: rgba(139, 92, 246, 0.2); color: #a78bfa;">Caricamento...</span>
        </div>
    </div>

    <!-- Feed Queue -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;" id="feed-queue-size">--</span>
        <div class="stat-label">
            <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
            Coda Pre-calcolo Feed
        </div>
        <div class="text-xs mt-2" id="circuit-breaker-status">
            <span class="px-2 py-1 rounded" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">Caricamento...</span>
        </div>
    </div>

    <!-- Partition Locks -->
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;" id="partitions-available">--</span>
        <div class="stat-label">
            <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Partizioni Disponibili
        </div>
        <div class="text-xs mt-2" id="partitions-status">
            <span class="px-2 py-1 rounded" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">Caricamento...</span>
        </div>
    </div>

    <!-- Overlay Workers -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;" id="overlay-workers-count">--</span>
        <div class="stat-label">
            <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
            Overlay Workers
        </div>
        <div class="text-xs mt-2" id="overlay-workers-status">
            <span class="px-2 py-1 rounded" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">Caricamento...</span>
        </div>
    </div>

    <!-- Feed Workers -->
    <div class="stat-card" style="border-left: 3px solid #ec4899;">
        <span class="stat-value" style="color: #ec4899;" id="feed-workers-count">--</span>
        <div class="stat-label">
            <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
            </svg>
            Feed Workers
        </div>
        <div class="text-xs mt-2" id="feed-workers-status">
            <span class="px-2 py-1 rounded" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">Caricamento...</span>
        </div>
    </div>

    <!-- Invalidation Rate -->
    <div class="stat-card" style="border-left: 3px solid #6366f1;">
        <span class="stat-value" style="color: #6366f1;" id="invalidation-rate">--</span>
        <div class="stat-label">
            <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Max Invalidazioni/sec
        </div>
        <div class="text-xs mt-2" id="invalidation-status">
            <span class="px-2 py-1 rounded" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">Caricamento...</span>
        </div>
    </div>
</div>

<!-- Worker Control Panels -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Overlay Workers Control -->
    <div class="card" style="border: 2px solid rgba(59, 130, 246, 0.3);">
        <h3 class="flex items-center justify-between mb-6">
            <span>
                <svg class="w-5 h-5 mr-2 inline text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                </svg>
                Overlay Workers
                <span class="badge badge-info ml-2" style="font-size: 0.65rem;">Reactions/Views/Plays</span>
            </span>
        </h3>

        <div class="p-4 rounded-lg mb-4" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2);">
            <label class="text-sm text-gray-300 mb-2 block">
                Numero Worker: <span id="overlay-scale-display" class="font-bold text-white">1</span>
            </label>
            <input type="range" id="overlay-scale-slider" min="1" max="8" value="1"
                   class="w-full mb-4" style="accent-color: #3b82f6;">

            <button id="btn-scale-overlay" class="btn btn-primary w-full">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
                Scala Overlay Workers
            </button>
        </div>

        <div class="text-xs text-gray-400">
            <strong>Scopo:</strong> Sincronizza reazioni, visualizzazioni, riproduzioni da Redis a PostgreSQL usando buffer write-behind partizionato.
            <br><strong>Lock TTL:</strong> 10s con heartbeat refresh 3s.
        </div>
    </div>

    <!-- Feed Workers Control -->
    <div class="card" style="border: 2px solid rgba(236, 72, 153, 0.3);">
        <h3 class="flex items-center justify-between mb-6">
            <span>
                <svg class="w-5 h-5 mr-2 inline text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                </svg>
                Feed Workers
                <span class="badge badge-pink ml-2" style="font-size: 0.65rem; background: rgba(236, 72, 153, 0.2); color: #f472b6;">Pre-Computation</span>
            </span>
        </h3>

        <div class="p-4 rounded-lg mb-4" style="background: rgba(236, 72, 153, 0.1); border: 1px solid rgba(236, 72, 153, 0.2);">
            <label class="text-sm text-gray-300 mb-2 block">
                Numero Worker: <span id="feed-scale-display" class="font-bold text-white">1</span>
            </label>
            <input type="range" id="feed-scale-slider" min="1" max="4" value="1"
                   class="w-full mb-4" style="accent-color: #ec4899;">

            <button id="btn-scale-feed" class="btn w-full" style="background: linear-gradient(135deg, #ec4899, #8b5cf6); border: none;">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
                Scala Feed Workers
            </button>
        </div>

        <div class="text-xs text-gray-400">
            <strong>Scopo:</strong> Pre-calcola i feed per i top 1000 utenti attivi usando pattern stale-while-revalidate.
            <br><strong>Circuit Breaker:</strong> Si apre a 10K in coda, si chiude a 5K.
        </div>
    </div>
</div>

<!-- Partition Lock Status -->
<div class="card mb-8" style="border: 2px solid rgba(16, 185, 129, 0.3);">
    <h3 class="flex items-center mb-6">
        <svg class="w-5 h-5 mr-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        Stato Lock Partizioni (16 partizioni x 4 tipi = 64 totali)
    </h3>

    <div id="partition-locks-grid" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- Will be populated by JavaScript -->
        <div class="p-3 rounded-lg text-center" style="background: rgba(255, 255, 255, 0.05);">
            <div class="text-sm text-gray-400">Reactions</div>
            <div class="text-xl font-bold text-green-400" id="locks-reactions">--/16</div>
        </div>
        <div class="p-3 rounded-lg text-center" style="background: rgba(255, 255, 255, 0.05);">
            <div class="text-sm text-gray-400">Views</div>
            <div class="text-xl font-bold text-blue-400" id="locks-views">--/16</div>
        </div>
        <div class="p-3 rounded-lg text-center" style="background: rgba(255, 255, 255, 0.05);">
            <div class="text-sm text-gray-400">Plays</div>
            <div class="text-xl font-bold text-purple-400" id="locks-plays">--/16</div>
        </div>
        <div class="p-3 rounded-lg text-center" style="background: rgba(255, 255, 255, 0.05);">
            <div class="text-sm text-gray-400">Comments</div>
            <div class="text-xl font-bold text-pink-400" id="locks-comments">--/16</div>
        </div>
    </div>
</div>

<!-- Recommendations & Alerts -->
<div id="recommendations-section" class="card mb-8" style="border: 2px solid rgba(245, 158, 11, 0.3); display: none;">
    <h3 class="flex items-center mb-4">
        <svg class="w-5 h-5 mr-2 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Raccomandazioni
    </h3>
    <ul id="recommendations-list" class="space-y-2 text-sm">
        <!-- Will be populated by JavaScript -->
    </ul>
</div>

<!-- Actions Log -->
<div class="card" style="border: 2px solid rgba(139, 92, 246, 0.3);">
    <h3 class="flex items-center justify-between mb-4">
        <span>
            <svg class="w-5 h-5 mr-2 inline text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Log Azioni
        </span>
        <button id="btn-reset-metrics" class="btn btn-sm" style="background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);">
            <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Reimposta Metriche
        </button>
    </h3>
    <div id="actions-log" class="space-y-2 max-h-48 overflow-y-auto text-sm font-mono" style="background: rgba(0, 0, 0, 0.3); padding: 1rem; border-radius: 0.5rem;">
        <div class="text-gray-500">[In attesa di azioni...]</div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
(function() {
    const adminUrl = '<?= \Need2Talk\Services\AdminSecurityService::generateSecureAdminUrl() ?>';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Elements
    const overlaySlider = document.getElementById('overlay-scale-slider');
    const feedSlider = document.getElementById('feed-scale-slider');
    const overlayDisplay = document.getElementById('overlay-scale-display');
    const feedDisplay = document.getElementById('feed-scale-display');
    const actionsLog = document.getElementById('actions-log');

    // Update slider displays
    overlaySlider?.addEventListener('input', () => {
        overlayDisplay.textContent = overlaySlider.value;
    });
    feedSlider?.addEventListener('input', () => {
        feedDisplay.textContent = feedSlider.value;
    });

    // Log action
    function logAction(message, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const colors = {
            info: 'text-blue-400',
            success: 'text-green-400',
            error: 'text-red-400',
            warning: 'text-yellow-400'
        };
        const entry = document.createElement('div');
        entry.className = colors[type] || 'text-gray-400';
        entry.textContent = `[${time}] ${message}`;

        // Clear "waiting" message if present
        if (actionsLog.querySelector('.text-gray-500')) {
            actionsLog.innerHTML = '';
        }

        actionsLog.insertBefore(entry, actionsLog.firstChild);

        // Keep only last 20 entries
        while (actionsLog.children.length > 20) {
            actionsLog.removeChild(actionsLog.lastChild);
        }
    }

    // Fetch status
    async function fetchStatus() {
        try {
            const response = await fetch(`${adminUrl}/api/enterprise/status`, {
                headers: { 'X-CSRF-TOKEN': csrfToken }
            });
            const data = await response.json();
            updateUI(data);
        } catch (error) {
            console.error('Failed to fetch status:', error);
            logAction('Errore recupero stato: ' + error.message, 'error');
        }
    }

    // Update UI with status data
    function updateUI(data) {
        // Overall health
        const healthBanner = document.getElementById('overall-health-banner');
        const healthText = document.getElementById('overall-health-text');
        const isHealthy = data.overall_health === 'healthy';

        healthBanner.style.background = isHealthy
            ? 'rgba(16, 185, 129, 0.1)'
            : 'rgba(245, 158, 11, 0.1)';
        healthBanner.style.borderColor = isHealthy
            ? 'rgba(16, 185, 129, 0.3)'
            : 'rgba(245, 158, 11, 0.3)';
        healthText.textContent = isHealthy ? 'Tutti i Sistemi Operativi' : 'Sistema Degradato';
        healthText.className = isHealthy ? 'text-lg font-semibold text-green-400' : 'text-lg font-semibold text-yellow-400';

        // Last update
        document.getElementById('last-update').textContent = `Ultimo aggiornamento: ${data.timestamp}`;

        // Services
        const services = data.services || {};

        // Active Users
        const activeUsers = services.active_user_tracker || {};
        document.getElementById('active-users-count').textContent = activeUsers.active_users_tracked || 0;
        updateStatusBadge('active-users-status', activeUsers.status);

        // Feed Queue
        const feedPrecompute = services.feed_precompute || {};
        document.getElementById('feed-queue-size').textContent = formatNumber(feedPrecompute.queue_size || 0);
        const circuitStatus = feedPrecompute.circuit_breaker === 'OPEN' ? 'paused' : 'healthy';
        updateStatusBadge('circuit-breaker-status', circuitStatus, `Circuit: ${feedPrecompute.circuit_breaker || 'UNKNOWN'}`);

        // Partition Locks
        const locks = services.partition_locks || {};
        const lockStats = locks.active_locks || {};
        document.getElementById('partitions-available').textContent = `${lockStats.total_available || 0}/64`;
        updateStatusBadge('partitions-status', locks.status, `${lockStats.total_locked || 0} locked`);

        // Update individual lock types
        const byType = lockStats.by_type || {};
        ['reactions', 'views', 'plays', 'comments'].forEach(type => {
            const typeData = byType[type] || {};
            const available = typeData.available || 0;
            document.getElementById(`locks-${type}`).textContent = `${available}/16`;
        });

        // Workers
        const workers = data.workers || {};

        // Overlay Workers
        const overlay = workers.overlay_workers || {};
        document.getElementById('overlay-workers-count').textContent = overlay.active_count || 0;
        updateStatusBadge('overlay-workers-status', overlay.active_count > 0 ? 'healthy' : 'warning',
            overlay.active_count > 0 ? 'In Esecuzione' : 'Non Attivo');
        overlaySlider.value = Math.max(1, overlay.active_count || 1);
        overlayDisplay.textContent = overlaySlider.value;

        // Feed Workers
        const feed = workers.feed_workers || {};
        document.getElementById('feed-workers-count').textContent = feed.active_count || 0;
        updateStatusBadge('feed-workers-status', feed.active_count > 0 ? 'healthy' : 'warning',
            feed.active_count > 0 ? 'In Esecuzione' : 'Non Attivo');
        feedSlider.value = Math.max(1, feed.active_count || 1);
        feedDisplay.textContent = feedSlider.value;

        // Invalidation config
        const invalidation = services.feed_invalidation || {};
        const config = invalidation.config || {};
        document.getElementById('invalidation-rate').textContent = config.max_invalidations_per_second || 100;
        updateStatusBadge('invalidation-status', invalidation.status);

        // Recommendations
        const recommendations = data.recommendations || [];
        const recSection = document.getElementById('recommendations-section');
        const recList = document.getElementById('recommendations-list');

        if (recommendations.length > 0) {
            recSection.style.display = 'block';
            recList.innerHTML = recommendations.map(rec =>
                `<li class="flex items-start text-yellow-300">
                    <svg class="w-4 h-4 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    ${escapeHtml(rec)}
                </li>`
            ).join('');
        } else {
            recSection.style.display = 'none';
        }
    }

    function updateStatusBadge(elementId, status, customText = null) {
        const element = document.getElementById(elementId);
        if (!element) return;

        const colors = {
            healthy: { bg: 'rgba(16, 185, 129, 0.2)', text: '#34d399' },
            warning: { bg: 'rgba(245, 158, 11, 0.2)', text: '#fbbf24' },
            paused: { bg: 'rgba(245, 158, 11, 0.2)', text: '#fbbf24' },
            error: { bg: 'rgba(239, 68, 68, 0.2)', text: '#f87171' },
            unavailable: { bg: 'rgba(107, 114, 128, 0.2)', text: '#9ca3af' }
        };

        const color = colors[status] || colors.unavailable;
        element.innerHTML = `<span class="px-2 py-1 rounded" style="background: ${color.bg}; color: ${color.text};">${customText || status?.toUpperCase() || 'UNKNOWN'}</span>`;
    }

    function formatNumber(num) {
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
        return num.toString();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Scale Overlay Workers
    document.getElementById('btn-scale-overlay')?.addEventListener('click', async () => {
        const count = overlaySlider.value;
        logAction(`Scalando overlay workers a ${count}...`, 'info');

        try {
            const response = await fetch(`${adminUrl}/api/enterprise/scale-overlay-workers`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: `count=${count}`
            });
            const data = await response.json();

            if (data.success) {
                logAction(`Overlay workers scalati a ${count}`, 'success');
            } else {
                logAction(`Errore scaling: ${data.output || 'Errore sconosciuto'}`, 'error');
            }

            // Refresh status after 3 seconds
            setTimeout(fetchStatus, 3000);
        } catch (error) {
            logAction(`Errore: ${error.message}`, 'error');
        }
    });

    // Scale Feed Workers
    document.getElementById('btn-scale-feed')?.addEventListener('click', async () => {
        const count = feedSlider.value;
        logAction(`Scalando feed workers a ${count}...`, 'info');

        try {
            const response = await fetch(`${adminUrl}/api/enterprise/scale-feed-workers`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: `count=${count}`
            });
            const data = await response.json();

            if (data.success) {
                logAction(`Feed workers scalati a ${count}`, 'success');
            } else {
                logAction(`Errore scaling: ${data.output || 'Errore sconosciuto'}`, 'error');
            }

            // Refresh status after 3 seconds
            setTimeout(fetchStatus, 3000);
        } catch (error) {
            logAction(`Errore: ${error.message}`, 'error');
        }
    });

    // Reset Metrics
    document.getElementById('btn-reset-metrics')?.addEventListener('click', async () => {
        if (!confirm('Reimpostare tutte le metriche di invalidazione feed?')) return;

        logAction('Reimpostando metriche...', 'info');

        try {
            const response = await fetch(`${adminUrl}/api/enterprise/reset-metrics`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken
                }
            });
            const data = await response.json();

            if (data.success) {
                logAction('Metriche reimpostate con successo', 'success');
                fetchStatus();
            } else {
                logAction(`Errore reimpostazione: ${data.error || 'Errore sconosciuto'}`, 'error');
            }
        } catch (error) {
            logAction(`Errore: ${error.message}`, 'error');
        }
    });

    // Initial fetch
    fetchStatus();

    // Auto-refresh every 10 seconds
    setInterval(fetchStatus, 10000);

    logAction('Monitor Enterprise V8.0 inizializzato', 'success');
})();
</script>
