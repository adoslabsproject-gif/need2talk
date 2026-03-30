<!-- ENTERPRISE GALAXY: CRON WORKER MANAGEMENT VIEW -->
<?php
/**
 * ENTERPRISE GALAXY V4.7: Complete Cron Worker Dashboard
 *
 * Features:
 * - Worker container status (uptime, memory, health)
 * - Autostart toggle
 * - Job management by category
 * - Real-time logs
 * - Execution history
 */

$jobs = $jobs ?? [];
$stats = $stats ?? [];

// Group jobs by category
$categories = [
    'system' => ['name' => 'Sistema', 'icon' => 'cog-6-tooth', 'color' => '#6b7280', 'jobs' => []],
    'maintenance' => ['name' => 'Manutenzione', 'icon' => 'wrench-screwdriver', 'color' => '#f59e0b', 'jobs' => []],
    'analytics' => ['name' => 'Analytics', 'icon' => 'chart-bar', 'color' => '#3b82f6', 'jobs' => []],
    'security' => ['name' => 'Sicurezza', 'icon' => 'shield-check', 'color' => '#ef4444', 'jobs' => []],
    'email' => ['name' => 'Email', 'icon' => 'envelope', 'color' => '#8b5cf6', 'jobs' => []],
    'chat' => ['name' => 'Chat', 'icon' => 'chat-bubble-left-right', 'color' => '#10b981', 'jobs' => []],
    'alerts' => ['name' => 'Alerts', 'icon' => 'bell-alert', 'color' => '#ec4899', 'jobs' => []],
    'audio' => ['name' => 'Audio', 'icon' => 'musical-note', 'color' => '#06b6d4', 'jobs' => []],
    'journal' => ['name' => 'Diario', 'icon' => 'book-open', 'color' => '#84cc16', 'jobs' => []],
];

foreach ($jobs as $job) {
    $cat = $job['category'] ?? 'system';
    if (isset($categories[$cat])) {
        $categories[$cat]['jobs'][] = $job;
    } else {
        $categories['system']['jobs'][] = $job;
    }
}

// Remove empty categories
$categories = array_filter($categories, fn($cat) => !empty($cat['jobs']));
?>

<h2 class="enterprise-title mb-8 flex items-center justify-between">
    <span class="flex items-center gap-3">
        <!-- Heroicon: clock (solid) -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
            <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM12.75 6a.75.75 0 0 0-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-3.75V6Z" clip-rule="evenodd" />
        </svg>
        Cron Worker Management
        <span class="text-xs px-2 py-1 rounded flex items-center gap-1" style="background: rgba(249, 115, 22, 0.2); color: #f97316; font-weight: 600;">
            <!-- Heroicon: cpu-chip (solid) -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3">
                <path d="M16.5 7.5h-9v9h9v-9Z" />
                <path fill-rule="evenodd" d="M8.25 2.25A.75.75 0 0 1 9 3v1.5h2.25V3a.75.75 0 0 1 1.5 0v1.5H15V3a.75.75 0 0 1 1.5 0v1.5h1.5A2.25 2.25 0 0 1 20.25 6.75v1.5H21a.75.75 0 0 1 0 1.5h-.75v2.25H21a.75.75 0 0 1 0 1.5h-.75v2.25H21a.75.75 0 0 1 0 1.5h-.75v1.5a2.25 2.25 0 0 1-2.25 2.25h-1.5V21a.75.75 0 0 1-1.5 0v-.75h-2.25V21a.75.75 0 0 1-1.5 0v-.75H9V21a.75.75 0 0 1-1.5 0v-.75h-1.5a2.25 2.25 0 0 1-2.25-2.25v-1.5H3a.75.75 0 0 1 0-1.5h.75v-2.25H3a.75.75 0 0 1 0-1.5h.75v-2.25H3a.75.75 0 0 1 0-1.5h.75v-1.5A2.25 2.25 0 0 1 6 4.5h1.5V3a.75.75 0 0 1 .75-.75ZM6 6.75A.75.75 0 0 1 6.75 6h10.5a.75.75 0 0 1 .75.75v10.5a.75.75 0 0 1-.75.75H6.75a.75.75 0 0 1-.75-.75V6.75Z" clip-rule="evenodd" />
            </svg>
            DOCKER CONTAINER
        </span>
    </span>
    <button onclick="refreshJobs()" class="btn btn-primary btn-sm flex items-center gap-2">
        <!-- Heroicon: arrow-path (solid) -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
            <path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z" clip-rule="evenodd" />
        </svg>
        Aggiorna
    </button>
</h2>

<!-- Stats Cards Row 1: Jobs Overview -->
<div class="stats-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;"><?= $stats['total'] ?? 0 ?></span>
        <div class="stat-label flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                <path d="M5.625 3.75a2.625 2.625 0 1 0 0 5.25h12.75a2.625 2.625 0 0 0 0-5.25H5.625ZM3.75 11.25a.75.75 0 0 0 0 1.5h16.5a.75.75 0 0 0 0-1.5H3.75ZM3 15.75a.75.75 0 0 1 .75-.75h16.5a.75.75 0 0 1 0 1.5H3.75a.75.75 0 0 1-.75-.75ZM3.75 18.75a.75.75 0 0 0 0 1.5h16.5a.75.75 0 0 0 0-1.5H3.75Z" />
            </svg>
            Totale Job
        </div>
    </div>
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;"><?= $stats['enabled'] ?? 0 ?></span>
        <div class="stat-label flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" />
            </svg>
            Abilitati
        </div>
    </div>
    <div class="stat-card" style="border-left: 3px solid #6b7280;">
        <span class="stat-value" style="color: #6b7280;"><?= $stats['disabled'] ?? 0 ?></span>
        <div class="stat-label flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12ZM9 8.25a.75.75 0 0 0-.75.75v6c0 .414.336.75.75.75h.75a.75.75 0 0 0 .75-.75V9a.75.75 0 0 0-.75-.75H9Zm5.25 0a.75.75 0 0 0-.75.75v6c0 .414.336.75.75.75H15a.75.75 0 0 0 .75-.75V9a.75.75 0 0 0-.75-.75h-.75Z" clip-rule="evenodd" />
            </svg>
            Disabilitati
        </div>
    </div>
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;"><?= $stats['healthy'] ?? 0 ?></span>
        <div class="stat-label flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                <path d="m11.645 20.91-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z" />
            </svg>
            Healthy
        </div>
    </div>
    <div class="stat-card" style="border-left: 3px solid #ef4444;">
        <span class="stat-value" style="color: #ef4444;"><?= $stats['error'] ?? 0 ?></span>
        <div class="stat-label flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                <path fill-rule="evenodd" d="M9.401 3.003c1.155-2 4.043-2 5.197 0l7.355 12.748c1.154 2-.29 4.5-2.599 4.5H4.645c-2.309 0-3.752-2.5-2.598-4.5L9.4 3.003ZM12 8.25a.75.75 0 0 1 .75.75v3.75a.75.75 0 0 1-1.5 0V9a.75.75 0 0 1 .75-.75Zm0 8.25a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z" clip-rule="evenodd" />
            </svg>
            Errori
        </div>
    </div>
</div>

<!-- Worker Control Panel -->
<div class="card mb-8" style="border: 2px solid rgba(249, 115, 22, 0.3);">
    <h3 class="flex items-center justify-between mb-6">
        <span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 inline mr-3 text-orange-400">
                <path fill-rule="evenodd" d="M2.25 6a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3V6Zm3.97.97a.75.75 0 0 1 1.06 0l2.25 2.25a.75.75 0 0 1 0 1.06l-2.25 2.25a.75.75 0 0 1-1.06-1.06l1.72-1.72-1.72-1.72a.75.75 0 0 1 0-1.06Zm4.28 4.28a.75.75 0 0 0 0 1.5h3a.75.75 0 0 0 0-1.5h-3Z" clip-rule="evenodd" />
            </svg>
            Cron Worker Container
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle;">ENTERPRISE GALAXY</span>
        </span>
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Container Status -->
        <div class="p-4 rounded-lg" style="background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2 text-orange-400">
                    <path d="M21 6.375c0 2.692-4.03 4.875-9 4.875S3 9.067 3 6.375 7.03 1.5 12 1.5s9 2.183 9 4.875Z" />
                    <path d="M12 12.75c2.685 0 5.19-.586 7.078-1.609a8.283 8.283 0 0 0 1.897-1.384c.016.121.025.244.025.368C21 12.817 16.97 15 12 15s-9-2.183-9-4.875c0-.124.009-.247.025-.368a8.285 8.285 0 0 0 1.897 1.384C6.809 12.164 9.315 12.75 12 12.75Z" />
                    <path d="M12 16.5c2.685 0 5.19-.586 7.078-1.609a8.282 8.282 0 0 0 1.897-1.384c.016.121.025.244.025.368 0 2.692-4.03 4.875-9 4.875s-9-2.183-9-4.875c0-.124.009-.247.025-.368a8.284 8.284 0 0 0 1.897 1.384C6.809 15.914 9.315 16.5 12 16.5Z" />
                    <path d="M12 20.25c2.685 0 5.19-.586 7.078-1.609a8.282 8.282 0 0 0 1.897-1.384c.016.121.025.244.025.368 0 2.692-4.03 4.875-9 4.875s-9-2.183-9-4.875c0-.124.009-.247.025-.368a8.284 8.284 0 0 0 1.897 1.384C6.809 19.664 9.315 20.25 12 20.25Z" />
                </svg>
                Container Status
            </h4>

            <div id="worker-status-loading" class="text-center py-4">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 mx-auto text-orange-500 animate-spin">
                    <path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z" clip-rule="evenodd" />
                </svg>
                <p class="mt-2 text-sm">Caricamento...</p>
            </div>

            <div id="worker-status-content" style="display: none;">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-400">Stato:</span>
                        <span id="worker-status-badge" class="font-semibold"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Uptime:</span>
                        <span id="worker-uptime" class="text-white"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Memory:</span>
                        <span id="worker-memory" class="text-white"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">CPU:</span>
                        <span id="worker-cpu" class="text-white"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Health:</span>
                        <span id="worker-health" class="text-white"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Start/Stop Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2 text-purple-400">
                    <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.75a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .913-.143Z" clip-rule="evenodd" />
                </svg>
                Controlli Worker
            </h4>

            <div class="flex gap-3 mb-4">
                <button id="btn-start-worker" class="btn btn-success flex-1" onclick="startCronWorker()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2">
                        <path fill-rule="evenodd" d="M4.5 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.28 19.99c-1.25.687-2.779-.217-2.779-1.643V5.653Z" clip-rule="evenodd" />
                    </svg>
                    Avvia
                </button>
                <button id="btn-stop-worker" class="btn btn-danger flex-1" onclick="stopCronWorker()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2">
                        <path fill-rule="evenodd" d="M4.5 7.5a3 3 0 0 1 3-3h9a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3h-9a3 3 0 0 1-3-3v-9Z" clip-rule="evenodd" />
                    </svg>
                    Ferma
                </button>
            </div>

            <button id="btn-restart-worker" class="btn btn-warning w-full" onclick="restartCronWorker()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2">
                    <path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z" clip-rule="evenodd" />
                </svg>
                Riavvia Worker
            </button>
        </div>

        <!-- Autostart Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(168, 85, 247, 0.1); border: 1px solid rgba(168, 85, 247, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2 text-purple-400">
                    <path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 0 1 6.775-5.025.75.75 0 0 1 .313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 0 1 1.248.313 5.25 5.25 0 0 1-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 1 1 2.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309A5.342 5.342 0 0 1 12 6.75ZM4.117 19.125a.75.75 0 0 1 .75-.75h.008a.75.75 0 0 1 .75.75v.008a.75.75 0 0 1-.75.75h-.008a.75.75 0 0 1-.75-.75v-.008Z" clip-rule="evenodd" />
                </svg>
                Avvio Automatico
            </h4>

            <div class="flex items-center justify-between mb-3">
                <span class="text-sm text-gray-300">Autostart al boot server</span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="cron-autostart-toggle" class="sr-only peer" checked>
                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                </label>
            </div>

            <div class="text-xs text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3 inline mr-1">
                    <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm8.706-1.442c1.146-.573 2.437.463 2.126 1.706l-.709 2.836.042-.02a.75.75 0 0 1 .67 1.34l-.04.022c-1.147.573-2.438-.463-2.127-1.706l.71-2.836-.042.02a.75.75 0 1 1-.671-1.34l.041-.022ZM12 9a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Z" clip-rule="evenodd" />
                </svg>
                Il cron worker parte automaticamente con <code>docker compose up -d</code>
            </div>

            <div class="mt-3 text-xs">
                <span class="text-gray-400">Stato:</span>
                <span id="cron-autostart-status" class="font-semibold text-green-400 ml-1">Abilitato (docker-compose)</span>
            </div>
        </div>
    </div>
</div>

<!-- Category Filter -->
<div class="card mb-4">
    <div class="flex flex-wrap gap-2">
        <button class="category-filter-btn active" data-category="all" onclick="filterByCategory('all')">
            Tutti (<?= count($jobs) ?>)
        </button>
        <?php foreach ($categories as $catKey => $cat): ?>
        <button class="category-filter-btn" data-category="<?= $catKey ?>" onclick="filterByCategory('<?= $catKey ?>')" style="--cat-color: <?= $cat['color'] ?>;">
            <?= $cat['name'] ?> (<?= count($cat['jobs']) ?>)
        </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Jobs Table by Category -->
<div class="enterprise-card p-6">
    <?php foreach ($categories as $catKey => $cat): ?>
    <div class="category-section" data-category="<?= $catKey ?>">
        <h4 class="flex items-center gap-2 mb-4 pb-2" style="border-bottom: 2px solid <?= $cat['color'] ?>30;">
            <span class="w-3 h-3 rounded-full" style="background: <?= $cat['color'] ?>;"></span>
            <span style="color: <?= $cat['color'] ?>;"><?= $cat['name'] ?></span>
            <span class="text-xs text-gray-500">(<?= count($cat['jobs']) ?> job)</span>
        </h4>

        <div class="table-wrapper mb-6">
            <table class="enterprise-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">Stato</th>
                        <th>Nome Job</th>
                        <th>Descrizione</th>
                        <th>Schedule</th>
                        <th>Ultima Esecuzione</th>
                        <th>Success Rate</th>
                        <th>Tempo Medio</th>
                        <th style="width: 180px;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cat['jobs'] as $job): ?>
                    <tr data-job-id="<?= $job['id'] ?>" data-job-name="<?= htmlspecialchars($job['name']) ?>">
                        <td class="text-center">
                            <?php if ($job['health_status'] === 'healthy'): ?>
                                <span class="text-2xl" title="Healthy">✅</span>
                            <?php elseif ($job['health_status'] === 'error'): ?>
                                <span class="text-2xl" title="Errore">❌</span>
                            <?php elseif ($job['health_status'] === 'pending'): ?>
                                <span class="text-2xl" title="In Attesa">⏳</span>
                            <?php else: ?>
                                <span class="text-2xl" title="Disabilitato">⏸️</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code class="text-sm font-bold"><?= htmlspecialchars($job['name']) ?></code>
                        </td>
                        <td class="text-sm text-gray-300"><?= htmlspecialchars($job['description'] ?? '-') ?></td>
                        <td>
                            <code class="text-xs px-2 py-1 rounded" style="background: rgba(255,255,255,0.1);"><?= htmlspecialchars($job['schedule']) ?></code>
                        </td>
                        <td class="text-sm">
                            <?php if ($job['last_run']): ?>
                                <?= date('Y-m-d H:i:s', strtotime($job['last_run'])) ?>
                            <?php else: ?>
                                <span class="text-gray-500">Mai</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($job['total_runs'] ?? 0) > 0): ?>
                                <span class="badge badge-<?= $job['success_rate'] >= 90 ? 'success' : ($job['success_rate'] >= 70 ? 'warning' : 'danger') ?>">
                                    <?= $job['success_rate'] ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-gray-500">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm">
                            <?= isset($job['avg_execution_time']) && $job['avg_execution_time'] ? round($job['avg_execution_time'], 2) . 'ms' : '-' ?>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button
                                    onclick="toggleJob('<?= htmlspecialchars($job['name']) ?>', <?= $job['enabled'] ? 'false' : 'true' ?>)"
                                    class="btn btn-sm <?= $job['enabled'] ? 'btn-secondary' : 'btn-success' ?>"
                                    title="<?= $job['enabled'] ? 'Disabilita' : 'Abilita' ?>"
                                >
                                    <?php if ($job['enabled']): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                                            <path fill-rule="evenodd" d="M6.75 5.25a.75.75 0 0 1 .75-.75H9a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H7.5a.75.75 0 0 1-.75-.75V5.25Zm7.5 0A.75.75 0 0 1 15 4.5h1.5a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H15a.75.75 0 0 1-.75-.75V5.25Z" clip-rule="evenodd" />
                                        </svg>
                                    <?php else: ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                                            <path fill-rule="evenodd" d="M4.5 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.28 19.99c-1.25.687-2.779-.217-2.779-1.643V5.653Z" clip-rule="evenodd" />
                                        </svg>
                                    <?php endif; ?>
                                </button>
                                <button
                                    onclick="executeJob('<?= htmlspecialchars($job['name']) ?>')"
                                    class="btn btn-sm btn-primary"
                                    title="Esegui Ora"
                                    <?= !$job['enabled'] ? 'disabled' : '' ?>
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                                        <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm14.024-.983a1.125 1.125 0 0 1 0 1.966l-5.603 3.113A1.125 1.125 0 0 1 9 15.113V8.887c0-.857.921-1.4 1.671-.983l5.603 3.113Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                <button
                                    onclick="viewHistory('<?= htmlspecialchars($job['name']) ?>')"
                                    class="btn btn-sm btn-ghost"
                                    title="Storico"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                                        <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM12.75 6a.75.75 0 0 0-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-3.75V6Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Worker Logs -->
<div class="card mt-8">
    <h3 class="flex items-center justify-between mb-4">
        <span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 inline mr-3">
                <path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0 0 1-1.875-1.875V5.25A3.75 3.75 0 0 0 9 1.5H5.625ZM7.5 15a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 7.5 15Zm.75 2.25a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H8.25Z" clip-rule="evenodd" />
                <path d="M12.971 1.816A5.23 5.23 0 0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 0 1 3.434 1.279 9.768 9.768 0 0 0-6.963-6.963Z" />
            </svg>
            Log Worker Recenti
        </span>
        <button id="btn-refresh-logs" class="btn btn-sm btn-secondary" onclick="refreshWorkerLogs()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-2">
                <path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z" clip-rule="evenodd" />
            </svg>
            Aggiorna Log
        </button>
    </h3>

    <div id="worker-logs" class="bg-black/50 rounded-lg p-4 font-mono text-xs overflow-auto" style="max-height: 400px;">
        <p class="text-gray-500">Caricamento log...</p>
    </div>
</div>

<!-- History Modal -->
<div id="history-modal" class="modal hidden">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3 class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM12.75 6a.75.75 0 0 0-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-3.75V6Z" clip-rule="evenodd" />
                </svg>
                Storico: <span id="history-job-name"></span>
            </h3>
            <button onclick="closeHistoryModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="history-content">
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-10 h-10 mx-auto text-purple-500 animate-spin">
                    <path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z" clip-rule="evenodd" />
                </svg>
                <p class="mt-4">Caricamento...</p>
            </div>
        </div>
    </div>
</div>

<!-- Output Modal -->
<div id="output-modal" class="modal hidden">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3 class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M2.25 6a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3V6Zm3.97.97a.75.75 0 0 1 1.06 0l2.25 2.25a.75.75 0 0 1 0 1.06l-2.25 2.25a.75.75 0 0 1-1.06-1.06l1.72-1.72-1.72-1.72a.75.75 0 0 1 0-1.06Zm4.28 4.28a.75.75 0 0 0 0 1.5h3a.75.75 0 0 0 0-1.5h-3Z" clip-rule="evenodd" />
                </svg>
                Output: <span id="output-job-name"></span>
            </h3>
            <button onclick="closeOutputModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="output-result" class="mb-4" style="display: none;">
                <div class="flex items-center gap-3 p-4 rounded" style="background: rgba(16, 185, 129, 0.2);">
                    <span id="output-status-icon" class="text-2xl"></span>
                    <div class="flex-1">
                        <div id="output-message" class="font-bold"></div>
                        <div id="output-time" class="text-sm text-gray-300"></div>
                    </div>
                </div>
            </div>
            <div id="output-content-wrapper">
                <div class="text-center py-8">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-10 h-10 mx-auto text-purple-500 animate-spin">
                        <path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z" clip-rule="evenodd" />
                    </svg>
                    <p class="mt-4">Esecuzione...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="closeOutputModal()" class="btn btn-secondary">Chiudi</button>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
.badge-warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
.badge-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }

.category-filter-btn {
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background: rgba(255, 255, 255, 0.05);
    color: #9ca3af;
    border: 1px solid rgba(255, 255, 255, 0.1);
    transition: all 0.2s;
    cursor: pointer;
}
.category-filter-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}
.category-filter-btn.active {
    background: rgba(249, 115, 22, 0.2);
    color: #f97316;
    border-color: rgba(249, 115, 22, 0.5);
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.modal.hidden { display: none; }
.modal-content {
    background: linear-gradient(135deg, rgba(30, 30, 30, 0.98), rgba(20, 20, 30, 0.98));
    border-radius: 12px;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid rgba(147, 51, 234, 0.3);
    width: 90%;
}
.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-body { padding: 1.5rem; }
.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    justify-content: flex-end;
}
.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    opacity: 0.7;
}
.modal-close:hover { opacity: 1; }

.log-line { line-height: 1.6; }
.log-line.success { color: #10b981; }
.log-line.error { color: #ef4444; }
.log-line.info { color: #3b82f6; }
.log-line.warn { color: #f59e0b; }
</style>

<script nonce="<?= csp_nonce() ?>">
// Get admin path prefix
const adminPath = window.location.pathname.split('/').slice(0, 2).join('/');

// Load worker status on page load
document.addEventListener('DOMContentLoaded', function() {
    loadWorkerStatus();
    refreshWorkerLogs();
});

async function loadWorkerStatus() {
    try {
        const response = await fetch(`${adminPath}/api/cron/worker-status`);
        const data = await response.json();

        document.getElementById('worker-status-loading').style.display = 'none';
        document.getElementById('worker-status-content').style.display = 'block';

        if (data.success) {
            const status = data.status;

            // Status badge
            const statusBadge = document.getElementById('worker-status-badge');
            if (status.running) {
                statusBadge.innerHTML = '<span class="px-2 py-1 rounded text-xs" style="background: #10b981; color: white;">RUNNING</span>';
                document.getElementById('btn-start-worker').disabled = true;
                document.getElementById('btn-stop-worker').disabled = false;
            } else {
                statusBadge.innerHTML = '<span class="px-2 py-1 rounded text-xs" style="background: #ef4444; color: white;">STOPPED</span>';
                document.getElementById('btn-start-worker').disabled = false;
                document.getElementById('btn-stop-worker').disabled = true;
            }

            document.getElementById('worker-uptime').textContent = status.uptime || '-';
            document.getElementById('worker-memory').textContent = status.memory || '-';
            document.getElementById('worker-cpu').textContent = status.cpu || '-';
            document.getElementById('worker-health').innerHTML = status.healthy
                ? '<span class="text-green-400">Healthy</span>'
                : '<span class="text-red-400">Unhealthy</span>';
        }
    } catch (error) {
        console.error('Failed to load worker status:', error);
        document.getElementById('worker-status-loading').innerHTML = '<p class="text-red-400">Errore caricamento</p>';
    }
}

async function refreshWorkerLogs() {
    const logsDiv = document.getElementById('worker-logs');

    try {
        const response = await fetch(`${adminPath}/api/cron/worker-logs?lines=100`);
        const data = await response.json();

        if (data.success && data.logs) {
            const lines = data.logs.split('\n').map(line => {
                let className = 'log-line';
                if (line.includes('✅') || line.includes('SUCCESS') || line.includes('Completed')) {
                    className += ' success';
                } else if (line.includes('❌') || line.includes('ERROR') || line.includes('Failed')) {
                    className += ' error';
                } else if (line.includes('INFO') || line.includes('🚀')) {
                    className += ' info';
                } else if (line.includes('⚠️') || line.includes('WARNING')) {
                    className += ' warn';
                }
                return `<div class="${className}">${escapeHtml(line)}</div>`;
            }).join('');

            logsDiv.innerHTML = lines || '<p class="text-gray-500">Nessun log disponibile</p>';
            logsDiv.scrollTop = logsDiv.scrollHeight;
        } else {
            logsDiv.innerHTML = '<p class="text-gray-500">Nessun log disponibile</p>';
        }
    } catch (error) {
        console.error('Failed to load logs:', error);
        logsDiv.innerHTML = '<p class="text-red-400">Errore caricamento log</p>';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function startCronWorker() {
    if (!confirm('Avviare il cron worker?')) return;
    await workerAction('start');
}

async function stopCronWorker() {
    if (!confirm('Fermare il cron worker? I job non verranno eseguiti.')) return;
    await workerAction('stop');
}

async function restartCronWorker() {
    if (!confirm('Riavviare il cron worker?')) return;
    await workerAction('restart');
}

async function workerAction(action) {
    try {
        const formData = new FormData();
        formData.append('action', action);

        const response = await fetch(`${adminPath}/api/cron/worker-control`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message);
            setTimeout(loadWorkerStatus, 2000);
        } else {
            alert('Errore: ' + data.message);
        }
    } catch (error) {
        console.error('Worker action failed:', error);
        alert('Errore durante l\'operazione');
    }
}

function filterByCategory(category) {
    // Update buttons
    document.querySelectorAll('.category-filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.category === category) {
            btn.classList.add('active');
        }
    });

    // Show/hide sections
    document.querySelectorAll('.category-section').forEach(section => {
        if (category === 'all' || section.dataset.category === category) {
            section.style.display = 'block';
        } else {
            section.style.display = 'none';
        }
    });
}

async function refreshJobs() {
    try {
        const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
        const adminHash = pathMatch ? pathMatch[1] : '';
        if (!adminHash) throw new Error('Admin URL hash not found');

        const url = `${window.location.protocol}//${window.location.host}/admin_${adminHash}/api/cron/jobs?_=${Date.now()}`;

        const response = await fetch(url, {
            headers: { 'Cache-Control': 'no-cache' },
            credentials: 'same-origin'
        });

        const text = await response.text();
        if (!text) throw new Error('Empty response');

        const data = JSON.parse(text);

        if (data.success && data.jobs) {
            data.jobs.forEach(job => {
                const row = document.querySelector(`tr[data-job-name="${job.name}"]`);
                if (!row) return;

                // Update status
                const statusCell = row.cells[0];
                const icons = {
                    'healthy': '✅',
                    'error': '❌',
                    'pending': '⏳',
                    'disabled': '⏸️'
                };
                statusCell.innerHTML = `<span class="text-2xl">${icons[job.health_status] || '⏳'}</span>`;

                // Update last run
                row.cells[4].textContent = job.last_run ? job.last_run.substring(0, 19) : 'Mai';

                // Update success rate
                if (job.total_runs > 0) {
                    const badgeClass = job.success_rate >= 90 ? 'success' : (job.success_rate >= 70 ? 'warning' : 'danger');
                    row.cells[5].innerHTML = `<span class="badge badge-${badgeClass}">${job.success_rate}%</span>`;
                }

                // Update avg time
                row.cells[6].textContent = job.avg_execution_time ? Math.round(job.avg_execution_time * 100) / 100 + 'ms' : '-';
            });
        }

        // Also refresh worker status
        loadWorkerStatus();

    } catch (error) {
        console.error('Refresh failed:', error);
        alert('Errore nel refresh: ' + error.message);
    }
}

async function toggleJob(jobName, enable) {
    if (!confirm(`${enable ? 'Abilitare' : 'Disabilitare'} il job "${jobName}"?`)) return;

    try {
        const formData = new FormData();
        formData.append('job_name', jobName);
        formData.append('enabled', enable ? '1' : '0');

        const response = await fetch(`${adminPath}/api/cron/toggle`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Errore: ' + data.message);
        }
    } catch (error) {
        alert('Errore: ' + error.message);
    }
}

async function executeJob(jobName) {
    if (!confirm(`Eseguire "${jobName}" ora?`)) return;

    const button = event.target.closest('button');
    const originalHTML = button.innerHTML;
    button.innerHTML = '<svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="currentColor"><path d="M4.755 10.059a7.5 7.5 0 0 1 12.548-3.364l1.903 1.903h-3.183a.75.75 0 1 0 0 1.5h4.992a.75.75 0 0 0 .75-.75V4.356a.75.75 0 0 0-1.5 0v3.18l-1.9-1.9A9 9 0 0 0 3.306 9.67a.75.75 0 1 0 1.45.388Zm15.408 3.352a.75.75 0 0 0-.919.53 7.5 7.5 0 0 1-12.548 3.364l-1.902-1.903h3.183a.75.75 0 0 0 0-1.5H2.984a.75.75 0 0 0-.75.75v4.992a.75.75 0 0 0 1.5 0v-3.18l1.9 1.9a9 9 0 0 0 15.059-4.035.75.75 0 0 0-.53-.918Z"/></svg>';
    button.disabled = true;

    // Show modal
    document.getElementById('output-modal').classList.remove('hidden');
    document.getElementById('output-job-name').textContent = jobName;
    document.getElementById('output-result').style.display = 'none';

    try {
        const formData = new FormData();
        formData.append('job_name', jobName);

        const response = await fetch(`${adminPath}/api/cron/execute`, {
            method: 'POST',
            body: formData
        });

        const text = await response.text();
        if (!text) throw new Error('Empty response');

        const data = JSON.parse(text);

        // Show result
        const resultDiv = document.getElementById('output-result');
        resultDiv.style.display = 'block';
        resultDiv.style.background = data.success ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)';

        document.getElementById('output-status-icon').textContent = data.success ? '✅' : '❌';
        document.getElementById('output-message').textContent = data.message || (data.success ? 'Completato' : 'Fallito');
        document.getElementById('output-time').textContent = `Tempo: ${data.execution_time}ms`;

        document.getElementById('output-content-wrapper').innerHTML = data.output
            ? `<pre class="text-xs bg-black/50 p-4 rounded overflow-auto" style="max-height: 400px;">${escapeHtml(data.output)}</pre>`
            : '<p class="text-gray-500 text-center">Nessun output</p>';

        refreshJobs();

    } catch (error) {
        document.getElementById('output-result').style.display = 'block';
        document.getElementById('output-result').style.background = 'rgba(239, 68, 68, 0.2)';
        document.getElementById('output-status-icon').textContent = '❌';
        document.getElementById('output-message').textContent = 'Errore esecuzione';
        document.getElementById('output-time').textContent = error.message;
        document.getElementById('output-content-wrapper').innerHTML = '';
    } finally {
        button.innerHTML = originalHTML;
        button.disabled = false;
    }
}

async function viewHistory(jobName) {
    document.getElementById('history-modal').classList.remove('hidden');
    document.getElementById('history-job-name').textContent = jobName;

    try {
        const response = await fetch(`${adminPath}/api/cron/history?job_name=${encodeURIComponent(jobName)}&limit=50`);
        const data = await response.json();

        if (data.success) {
            renderHistory(data.history);
        } else {
            document.getElementById('history-content').innerHTML = '<p class="text-red-500">Errore caricamento</p>';
        }
    } catch (error) {
        document.getElementById('history-content').innerHTML = '<p class="text-red-500">Errore: ' + error.message + '</p>';
    }
}

function renderHistory(history) {
    if (!history || history.length === 0) {
        document.getElementById('history-content').innerHTML = '<p class="text-gray-500 text-center">Nessuno storico</p>';
        return;
    }

    let html = '<div class="space-y-3">';
    history.forEach(exec => {
        const statusIcon = exec.success ? '✅' : '❌';
        const statusColor = exec.success ? 'text-green-500' : 'text-red-500';
        html += `
            <div class="p-4 rounded" style="background: rgba(255, 255, 255, 0.05);">
                <div class="flex justify-between items-start mb-2">
                    <span class="${statusColor} font-bold">${statusIcon} ${exec.success ? 'Successo' : 'Fallito'}</span>
                    <span class="text-sm text-gray-400">${new Date(exec.executed_at).toLocaleString()}</span>
                </div>
                <div class="text-sm text-gray-300">
                    Tempo: <strong>${exec.execution_time}ms</strong>
                    ${exec.return_code !== null ? ` | Codice: ${exec.return_code}` : ''}
                </div>
                ${exec.output ? `<pre class="mt-2 text-xs bg-black/30 p-2 rounded overflow-auto max-h-32">${escapeHtml(exec.output)}</pre>` : ''}
            </div>
        `;
    });
    html += '</div>';
    document.getElementById('history-content').innerHTML = html;
}

function closeHistoryModal() {
    document.getElementById('history-modal').classList.add('hidden');
}

function closeOutputModal() {
    document.getElementById('output-modal').classList.add('hidden');
}

// Modal close on outside click
document.getElementById('history-modal')?.addEventListener('click', (e) => {
    if (e.target.id === 'history-modal') closeHistoryModal();
});
document.getElementById('output-modal')?.addEventListener('click', (e) => {
    if (e.target.id === 'output-modal') closeOutputModal();
});
</script>
