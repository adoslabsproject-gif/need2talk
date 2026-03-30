<!-- ENTERPRISE GALAXY V11.6: NOTIFICATION WORKERS MONITORING & CONTROL -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-bell mr-3"></i>
    Monitoraggio Notification Workers
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(249, 115, 22, 0.2); color: #f97316; font-weight: 600;">
        <i class="fas fa-bolt mr-1"></i>ASYNC QUEUE
    </span>
    <span class="text-xs px-2 py-1 rounded ml-2" style="background: rgba(139, 92, 246, 0.2); color: #8b5cf6; font-weight: 600;">
        <i class="fas fa-layer-group mr-1"></i>BATCHING + DEDUP
    </span>
</h2>

<?php
use Need2Talk\Controllers\AdminNotificationWorkerController;

$controller = new AdminNotificationWorkerController();
$status = $controller->getStatus();
$workers = $status['workers'] ?? ['running' => 0, 'max' => 4, 'status' => 'unknown'];
$queueDetails = $status['queue_details'] ?? ['pending' => 0, 'processing' => 0, 'failed' => 0, 'dead_letter' => 0];
$asyncEnabled = $status['async_enabled'] ?? true;
$metrics = $queueDetails['metrics'] ?? [];
$activeWorkers = $queueDetails['active_workers'] ?? [];
?>

<!-- Real-Time Status Cards -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
    <!-- Active Workers -->
    <div class="stat-card" style="border-left: 3px solid #f97316;">
        <span class="stat-value" style="color: #f97316;">
            <?= $workers['running'] ?> / <?= $workers['max'] ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-server mr-2"></i>Workers Attivi
        </div>
    </div>

    <!-- Async Mode Status -->
    <div class="stat-card" style="border-left: 3px solid <?= $asyncEnabled ? '#10b981' : '#6b7280' ?>;">
        <span class="stat-value" style="color: <?= $asyncEnabled ? '#10b981' : '#6b7280' ?>;">
            <?= $asyncEnabled ? 'ON' : 'OFF' ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-toggle-<?= $asyncEnabled ? 'on' : 'off' ?> mr-2"></i>Modalita Async
        </div>
    </div>

    <!-- Pending Queue -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;">
            <?= number_format($queueDetails['pending'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-clock mr-2"></i>In Coda
        </div>
    </div>

    <!-- Processing -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;">
            <?= number_format($queueDetails['processing'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-cog fa-spin mr-2"></i>In Elaborazione
        </div>
    </div>

    <!-- Failed -->
    <div class="stat-card" style="border-left: 3px solid #ef4444;">
        <span class="stat-value" style="color: #ef4444;">
            <?= number_format($queueDetails['failed'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>Fallite (Retry)
        </div>
    </div>

    <!-- Dead Letter -->
    <div class="stat-card" style="border-left: 3px solid #7c3aed;">
        <span class="stat-value" style="color: #7c3aed;">
            <?= number_format($queueDetails['dead_letter'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-skull-crossbones mr-2"></i>Dead Letter
        </div>
    </div>

    <!-- Total Processed (from metrics) -->
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;">
            <?= number_format((int)($metrics['processed'] ?? 0)) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-check-double mr-2"></i>Processate Totali
        </div>
    </div>

    <!-- Worker Status -->
    <div class="stat-card" style="border-left: 3px solid <?= $workers['status'] === 'active' ? '#10b981' : '#ef4444' ?>;">
        <span class="stat-value" style="color: <?= $workers['status'] === 'active' ? '#10b981' : '#ef4444' ?>;">
            <?= strtoupper($workers['status'] ?? 'UNKNOWN') ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-heartbeat mr-2"></i>Stato Workers
        </div>
    </div>
</div>

<!-- Architecture Info Box -->
<div class="card mt-6" style="border: 1px solid rgba(249, 115, 22, 0.3); background: rgba(249, 115, 22, 0.05);">
    <div class="flex items-start gap-4">
        <div class="text-orange-400">
            <i class="fas fa-info-circle text-2xl"></i>
        </div>
        <div class="flex-1">
            <h4 class="text-white font-semibold mb-2">Architettura Notification Worker V11.6</h4>
            <div class="text-sm text-gray-400 space-y-1">
                <p><i class="fas fa-bolt mr-2 text-orange-400"></i><strong>Queue Asincrona:</strong> Le notifiche vengono accodate in Redis (0.1ms) e processate in background. HTTP response immediata.</p>
                <p><i class="fas fa-layer-group mr-2 text-orange-400"></i><strong>Batching Intelligente:</strong> Fino a 50 notifiche processate per batch, riducendo le query DB da N a 1.</p>
                <p><i class="fas fa-compress-arrows-alt mr-2 text-orange-400"></i><strong>Deduplicazione:</strong> 50 reactions in 2s → 1 notifica aggregata "Hai 50 nuove reazioni".</p>
                <p><i class="fas fa-expand-arrows-alt mr-2 text-orange-400"></i><strong>Scaling:</strong> 1-4 workers scalabili. Progressive backoff quando la coda e vuota.</p>
                <p><i class="fas fa-sync-alt mr-2 text-orange-400"></i><strong>Fallback:</strong> Se Redis fallisce, automatico fallback a modalita sincrona (legacy).</p>
            </div>
        </div>
    </div>
</div>

<!-- Worker Control Panel -->
<div class="card mt-8" style="border: 2px solid rgba(249, 115, 22, 0.3);">
    <h3 class="flex items-center justify-between mb-6">
        <span>
            <i class="fas fa-sliders-h mr-3"></i>Pannello di Controllo Notification Workers
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle; background: rgba(249, 115, 22, 0.3); color: #f97316;">ENTERPRISE GALAXY V11.6</span>
        </span>
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Start/Stop Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-power-off mr-2 text-orange-400"></i>
                Controlli di Accensione
            </h4>

            <div class="flex gap-3 mb-4">
                <button id="btn-start-notif-workers" class="btn btn-success flex-1" <?= $workers['running'] > 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-play mr-2"></i>Avvia Workers
                </button>
                <button id="btn-stop-notif-workers" class="btn btn-danger flex-1" <?= $workers['running'] === 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-stop mr-2"></i>Ferma Workers
                </button>
            </div>

            <button id="btn-restart-notif-workers" class="btn btn-warning w-full mb-3">
                <i class="fas fa-redo mr-2"></i>Restart Workers
            </button>

            <div class="text-xs text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>
                Stato: <span class="font-semibold <?= $workers['running'] > 0 ? 'text-green-400' : 'text-red-400' ?>">
                    <?= $workers['running'] > 0 ? 'IN ESECUZIONE (' . $workers['running'] . ' workers)' : 'FERMO' ?>
                </span>
            </div>
        </div>

        <!-- Scaling Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-expand-arrows-alt mr-2 text-blue-400"></i>
                Scaling Manuale
            </h4>

            <div class="mb-3">
                <label class="text-sm text-gray-300 mb-2 block">
                    Numero Workers: <span id="notif-worker-count-display" class="font-bold text-white"><?= max(1, $workers['running']) ?></span>
                </label>
                <input type="range" id="notif-worker-scale-slider" min="1" max="4" value="<?= max(1, $workers['running']) ?>"
                       class="w-full" style="accent-color: #f97316;">
            </div>

            <button id="btn-scale-notif-workers" class="btn btn-primary w-full">
                <i class="fas fa-arrows-alt-h mr-2"></i>Applica Scaling
            </button>

            <div class="text-xs text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Max workers: 4 | Consigliato: 2 per carichi normali
            </div>
        </div>

        <!-- Async Mode Toggle -->
        <div class="p-4 rounded-lg" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-toggle-on mr-2 text-green-400"></i>
                Modalita Async
            </h4>

            <div class="flex items-center justify-between mb-3">
                <span class="text-sm text-gray-300">Abilita processamento asincrono</span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="notif-async-toggle" class="sr-only peer" <?= $asyncEnabled ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-600"></div>
                </label>
            </div>

            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-check mr-1 text-green-400"></i> <strong>ON:</strong> Notifiche in coda Redis, processate in batch</div>
                <div><i class="fas fa-times mr-1 text-red-400"></i> <strong>OFF:</strong> Notifiche sincrone (legacy, piu lento)</div>
            </div>

            <div class="text-xs text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Stato: <span id="notif-async-status" class="font-semibold <?= $asyncEnabled ? 'text-green-400' : 'text-gray-400' ?>">
                    <?= $asyncEnabled ? 'ASYNC ABILITATO' : 'SYNC (LEGACY)' ?>
                </span>
            </div>
        </div>

        <!-- Autostart Toggle -->
        <?php
        $autostartData = $controller->getAutostartStatus();
        $autostartEnabled = $autostartData['autostart_enabled'] ?? false;
        $autostartWorkerCount = $autostartData['autostart_worker_count'] ?? 2;
        ?>
        <div class="p-4 rounded-lg" style="background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-rocket mr-2 text-purple-400"></i>
                Autostart al Boot
            </h4>

            <div class="flex items-center justify-between mb-3">
                <span class="text-sm text-gray-300">Avvia workers automaticamente</span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="notif-autostart-toggle" class="sr-only peer" <?= $autostartEnabled ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                </label>
            </div>

            <div class="mb-3">
                <label class="text-sm text-gray-300 mb-2 block">
                    Workers all'avvio: <span id="autostart-count-display" class="font-bold text-white"><?= $autostartWorkerCount ?></span>
                </label>
                <input type="range" id="autostart-worker-count" min="1" max="4" value="<?= $autostartWorkerCount ?>"
                       class="w-full" style="accent-color: #8b5cf6;">
            </div>

            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-check mr-1 text-purple-400"></i> <strong>ON:</strong> Workers avviati al restart container</div>
                <div><i class="fas fa-times mr-1 text-gray-400"></i> <strong>OFF:</strong> Avvio manuale richiesto</div>
            </div>

            <div class="text-xs text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Stato: <span id="notif-autostart-status" class="font-semibold <?= $autostartEnabled ? 'text-purple-400' : 'text-gray-400' ?>">
                    <?= $autostartEnabled ? 'AUTOSTART ABILITATO' : 'AUTOSTART DISABILITATO' ?>
                </span>
            </div>
        </div>

        <!-- Emergency Actions -->
        <div class="p-4 rounded-lg" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-exclamation-triangle mr-2 text-red-400"></i>
                Azioni di Emergenza
            </h4>

            <button id="btn-process-failed" class="btn w-full mb-3" style="background: rgba(139, 92, 246, 0.3); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.5);">
                <i class="fas fa-redo mr-2"></i>Riprocessa Fallite (<?= number_format($queueDetails['failed'] ?? 0) ?>)
            </button>

            <button id="btn-clear-queues" class="btn btn-danger w-full">
                <i class="fas fa-trash-alt mr-2"></i>Svuota Tutte le Code
            </button>

            <div class="text-xs text-gray-400 mt-2">
                <i class="fas fa-exclamation-circle mr-1 text-red-400"></i>
                Attenzione: "Svuota Code" elimina tutte le notifiche in attesa!
            </div>
        </div>
    </div>
</div>

<!-- Metrics Dashboard -->
<?php if (!empty($metrics)): ?>
<div class="card mt-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-chart-bar mr-3 text-orange-400"></i>
        Metriche Queue (Redis)
    </h3>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="p-3 rounded-lg" style="background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.3);">
            <div class="text-xl font-bold text-orange-400"><?= number_format((int)($metrics['queued'] ?? 0)) ?></div>
            <div class="text-xs text-gray-400">Accodate Totali</div>
        </div>
        <div class="p-3 rounded-lg" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);">
            <div class="text-xl font-bold text-green-400"><?= number_format((int)($metrics['processed'] ?? 0)) ?></div>
            <div class="text-xs text-gray-400">Processate</div>
        </div>
        <div class="p-3 rounded-lg" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);">
            <div class="text-xl font-bold text-red-400"><?= number_format((int)($metrics['failed'] ?? 0)) ?></div>
            <div class="text-xs text-gray-400">Fallite</div>
        </div>
        <div class="p-3 rounded-lg" style="background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.3);">
            <div class="text-xl font-bold text-purple-400"><?= number_format((int)($metrics['dead_letter'] ?? 0)) ?></div>
            <div class="text-xs text-gray-400">Dead Letter</div>
        </div>
    </div>

    <?php if (!empty($metrics['last_updated'])): ?>
    <div class="text-xs text-gray-500 mt-3">
        <i class="fas fa-clock mr-1"></i>
        Ultimo aggiornamento: <?= date('Y-m-d H:i:s', (int)$metrics['last_updated']) ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Active Worker Heartbeats -->
<?php if (!empty($activeWorkers)): ?>
<div class="card mt-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-heartbeat mr-3 text-red-400"></i>
        Heartbeat Workers Attivi
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($activeWorkers as $workerId => $workerData):
            $data = is_string($workerData) ? json_decode($workerData, true) : $workerData;
            if (!$data) continue;
        ?>
        <div class="p-3 rounded-lg" style="background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.3);">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-white truncate" title="<?= htmlspecialchars($workerId) ?>">
                    <?= htmlspecialchars(substr($workerId, 0, 20)) ?>...
                </span>
                <span class="text-xs px-2 py-1 rounded" style="background: #10b981; color: white;">ATTIVO</span>
            </div>
            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-clock mr-1"></i>Ultimo: <?= date('H:i:s', $data['last_heartbeat'] ?? time()) ?></div>
                <div><i class="fas fa-microchip mr-1"></i>PID: <?= $data['pid'] ?? 'N/A' ?></div>
                <div><i class="fas fa-memory mr-1"></i>Memoria: <?= number_format(($data['memory'] ?? 0) / 1024 / 1024, 1) ?> MB</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Monitor Live Output -->
<div class="card mt-8">
    <h3 class="flex items-center justify-between mb-4">
        <span>
            <i class="fas fa-terminal mr-3 text-orange-400"></i>
            Monitor Output Live
        </span>
        <button id="btn-refresh-status" class="btn btn-sm btn-secondary">
            <i class="fas fa-sync-alt mr-2"></i>Aggiorna Stato
        </button>
    </h3>

    <div id="notif-monitor-output" class="p-4 rounded-lg font-mono text-xs" style="background: #0f172a; color: #94a3b8; max-height: 600px; overflow-y: auto;">
        <div class="text-gray-500 italic">Le conferme delle azioni appariranno qui quando avvii/fermi/scali i workers...</div>
    </div>

    <div class="mt-3 text-xs text-gray-400">
        <i class="fas fa-info-circle mr-1"></i>
        Mostra messaggi di conferma per le azioni sui Notification Workers | Auto-scroll abilitato
    </div>
</div>

<!-- Performance Comparison Box -->
<div class="card mt-8" style="border: 1px solid rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.05);">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-tachometer-alt mr-3 text-green-400"></i>
        Confronto Performance: Sync vs Async
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="p-4 rounded-lg" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);">
            <h4 class="text-red-400 font-semibold mb-3"><i class="fas fa-clock mr-2"></i>Modalita Sync (Legacy)</h4>
            <ul class="text-sm text-gray-400 space-y-1">
                <li><i class="fas fa-times mr-2 text-red-400"></i>~15ms per notifica</li>
                <li><i class="fas fa-times mr-2 text-red-400"></i>4 query DB per notifica</li>
                <li><i class="fas fa-times mr-2 text-red-400"></i>N WebSocket push singoli</li>
                <li><i class="fas fa-times mr-2 text-red-400"></i>50 reactions = 200 operazioni</li>
            </ul>
        </div>

        <div class="p-4 rounded-lg" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);">
            <h4 class="text-green-400 font-semibold mb-3"><i class="fas fa-bolt mr-2"></i>Modalita Async (Enterprise)</h4>
            <ul class="text-sm text-gray-400 space-y-1">
                <li><i class="fas fa-check mr-2 text-green-400"></i>~0.5ms per notifica</li>
                <li><i class="fas fa-check mr-2 text-green-400"></i>1 query DB batch (N rows)</li>
                <li><i class="fas fa-check mr-2 text-green-400"></i>WebSocket aggregato per user</li>
                <li><i class="fas fa-check mr-2 text-green-400"></i>50 reactions = ~5 operazioni</li>
            </ul>
        </div>
    </div>
</div>

<!-- JavaScript for Controls -->
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    // ENTERPRISE SECURITY: Use server-generated admin URL (more reliable than regex extraction)
    const ADMIN_BASE_URL = '<?= \Need2Talk\Services\AdminSecurityService::generateSecureAdminUrl() ?>';

    // Helper function to append messages to monitor
    function appendToMonitor(message, type = 'info') {
        const monitor = document.getElementById('notif-monitor-output');
        if (!monitor) return;

        const timestamp = new Date().toLocaleTimeString();
        const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️';
        const color = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#f97316';

        const line = document.createElement('div');
        line.style.marginBottom = '4px';
        line.style.color = color;
        line.innerHTML = `[${timestamp}] ${icon} ${message}`;

        // Remove placeholder text on first real message
        if (monitor.querySelector('.italic')) {
            monitor.innerHTML = '';
        }

        monitor.appendChild(line);
        monitor.scrollTop = monitor.scrollHeight;
    }

    // Helper function for API calls
    async function apiCall(endpoint, method = 'POST', body = null) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        const options = { method, headers };
        if (body) options.body = JSON.stringify(body);

        const response = await fetch(`${ADMIN_BASE_URL}${endpoint}`, options);
        return response.json();
    }

    // Slider update
    const slider = document.getElementById('notif-worker-scale-slider');
    const display = document.getElementById('notif-worker-count-display');

    slider?.addEventListener('input', function() {
        display.textContent = this.value;
    });

    // Start Workers
    document.getElementById('btn-start-notif-workers')?.addEventListener('click', async function() {
        const workerCount = parseInt(slider.value) || 2;
        if (!confirm(`Avviare ${workerCount} Notification Workers?`)) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Avvio in corso...';

        try {
            const data = await apiCall('/api/notification-workers/start', 'POST', { count: workerCount });

            if (data.success) {
                appendToMonitor(`Notification Workers avviati: ${data.started || workerCount} workers`, 'success');
                if (data.output) appendToMonitor(data.output.replace(/\n/g, '<br>'), 'info');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.error || 'Errore sconosciuto', 'error');
                alert('❌ ' + (data.error || 'Errore durante l\'avvio'));
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-play mr-2"></i>Avvia Workers';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
            alert('❌ Impossibile avviare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-play mr-2"></i>Avvia Workers';
        }
    });

    // Stop Workers
    document.getElementById('btn-stop-notif-workers')?.addEventListener('click', async function() {
        if (!confirm('Fermare tutti i Notification Workers? Le notifiche verranno accodate fino al riavvio.')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Arresto in corso...';

        try {
            const data = await apiCall('/api/notification-workers/stop', 'POST');

            if (data.success) {
                appendToMonitor('Notification Workers fermati', 'success');
                if (data.output) appendToMonitor(data.output.replace(/\n/g, '<br>'), 'info');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.error || 'Errore sconosciuto', 'error');
                alert('❌ ' + (data.error || 'Errore durante l\'arresto'));
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-stop mr-2"></i>Ferma Workers';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
            alert('❌ Impossibile fermare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-stop mr-2"></i>Ferma Workers';
        }
    });

    // Restart Workers
    document.getElementById('btn-restart-notif-workers')?.addEventListener('click', async function() {
        const workerCount = parseInt(slider.value) || 2;
        if (!confirm(`Riavviare con ${workerCount} Notification Workers?`)) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Riavvio in corso...';

        try {
            const data = await apiCall('/api/notification-workers/restart', 'POST', { count: workerCount });

            if (data.success) {
                appendToMonitor(`Notification Workers riavviati con ${workerCount} workers`, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.error || 'Errore sconosciuto', 'error');
                alert('❌ ' + (data.error || 'Errore durante il riavvio'));
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
            alert('❌ Impossibile riavviare i workers');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-redo mr-2"></i>Restart Workers';
        }
    });

    // Scale Workers
    document.getElementById('btn-scale-notif-workers')?.addEventListener('click', async function() {
        const workerCount = parseInt(slider.value);
        if (!confirm(`Scalare a ${workerCount} Notification Workers?`)) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Scaling in corso...';

        try {
            const data = await apiCall('/api/notification-workers/scale', 'POST', { count: workerCount });

            if (data.success) {
                appendToMonitor(`Scalato a ${workerCount} workers`, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.error || data.message || 'Errore sconosciuto', 'error');
                alert('❌ ' + (data.error || data.message || 'Errore durante lo scaling'));
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
            alert('❌ Impossibile scalare i workers');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-arrows-alt-h mr-2"></i>Applica Scaling';
        }
    });

    // Async Mode Toggle
    document.getElementById('notif-async-toggle')?.addEventListener('change', async function() {
        const enabled = this.checked;
        const action = enabled ? 'enable-async' : 'disable-async';

        try {
            const data = await apiCall(`/api/notification-workers/${action}`, 'POST');

            if (data.success) {
                const statusEl = document.getElementById('notif-async-status');
                statusEl.textContent = enabled ? 'ASYNC ABILITATO' : 'SYNC (LEGACY)';
                statusEl.className = enabled ? 'font-semibold text-green-400' : 'font-semibold text-gray-400';

                appendToMonitor(data.message || `Modalita ${enabled ? 'async' : 'sync'} attivata`, 'success');
            } else {
                appendToMonitor(data.error || 'Errore nel cambio modalita', 'error');
                this.checked = !enabled; // Revert toggle
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
            this.checked = !enabled; // Revert toggle
        }
    });

    // Process Failed Queue
    document.getElementById('btn-process-failed')?.addEventListener('click', async function() {
        if (!confirm('Riprocessare le notifiche fallite?')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Riprocessamento...';

        try {
            const data = await apiCall('/api/notification-workers/process-failed', 'POST', { limit: 50 });

            if (data.success) {
                appendToMonitor(`Riprocessate ${data.processed || 0} notifiche fallite`, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.error || 'Errore nel riprocessamento', 'error');
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
        } finally {
            this.disabled = false;
            this.innerHTML = `<i class="fas fa-redo mr-2"></i>Riprocessa Fallite`;
        }
    });

    // Clear Queues (Emergency)
    document.getElementById('btn-clear-queues')?.addEventListener('click', async function() {
        if (!confirm('⚠️ ATTENZIONE: Questa azione elimina TUTTE le notifiche in coda!\n\nSei sicuro di voler procedere?')) return;
        if (!confirm('⚠️ CONFERMA FINALE: Le notifiche eliminate NON possono essere recuperate.\n\nConfermi?')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Pulizia...';

        try {
            const data = await apiCall('/api/notification-workers/clear-queues', 'POST');

            if (data.success) {
                appendToMonitor('Tutte le code sono state svuotate', 'warning');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.error || 'Errore nella pulizia', 'error');
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-trash-alt mr-2"></i>Svuota Tutte le Code';
        }
    });

    // Autostart Toggle
    const autostartToggle = document.getElementById('notif-autostart-toggle');
    const autostartCountSlider = document.getElementById('autostart-worker-count');
    const autostartCountDisplay = document.getElementById('autostart-count-display');

    autostartCountSlider?.addEventListener('input', function() {
        autostartCountDisplay.textContent = this.value;
    });

    autostartToggle?.addEventListener('change', async function() {
        const enabled = this.checked;
        const workerCount = parseInt(autostartCountSlider?.value) || 2;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/notification-workers/set-autostart`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `enabled=${enabled ? '1' : '0'}&worker_count=${workerCount}`
            });

            const data = await response.json();

            if (data.success) {
                const statusEl = document.getElementById('notif-autostart-status');
                statusEl.textContent = enabled ? 'AUTOSTART ABILITATO' : 'AUTOSTART DISABILITATO';
                statusEl.className = enabled ? 'font-semibold text-purple-400' : 'font-semibold text-gray-400';

                appendToMonitor(data.message || `Autostart ${enabled ? 'abilitato' : 'disabilitato'}`, 'success');
            } else {
                appendToMonitor(data.error || 'Errore nel cambio autostart', 'error');
                this.checked = !enabled; // Revert toggle
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
            this.checked = !enabled; // Revert toggle
        }
    });

    // Update autostart when slider changes (if autostart is enabled)
    autostartCountSlider?.addEventListener('change', async function() {
        if (!autostartToggle?.checked) return; // Only update if autostart is enabled

        const workerCount = parseInt(this.value);

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/notification-workers/set-autostart`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `enabled=1&worker_count=${workerCount}`
            });

            const data = await response.json();

            if (data.success) {
                appendToMonitor(`Autostart aggiornato: ${workerCount} workers`, 'success');
            } else {
                appendToMonitor(data.error || 'Errore nell\'aggiornamento', 'error');
            }
        } catch (error) {
            appendToMonitor('Errore di connessione: ' + error.message, 'error');
        }
    });

    // Refresh Status
    document.getElementById('btn-refresh-status')?.addEventListener('click', function() {
        location.reload();
    });

    // ============================================================================
    // AUTO-REFRESH PAGE DATA
    // ============================================================================

    // Auto-refresh every 15 seconds
    setInterval(() => {
        if (!document.hidden) {
            fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(r => r.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newStats = doc.querySelector('.stats-grid');
                    if (newStats) {
                        document.querySelector('.stats-grid').innerHTML = newStats.innerHTML;
                    }
                });
        }
    }, 15000);
});
</script>

<style nonce="<?= csp_nonce() ?>">
.stat-card {
    background: linear-gradient(135deg, rgba(249, 115, 22, 0.05) 0%, rgba(59, 130, 246, 0.05) 100%);
    border: 1px solid rgba(249, 115, 22, 0.2);
    border-radius: 0.5rem;
    padding: 1.5rem;
}

.stat-value {
    display: block;
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.875rem;
    color: #9ca3af;
    display: flex;
    align-items: center;
}

input[type="range"] {
    width: 100%;
    height: 6px;
    background: rgba(249, 115, 22, 0.2);
    border-radius: 3px;
    outline: none;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 20px;
    height: 20px;
    background: #f97316;
    border-radius: 50%;
    cursor: pointer;
}

input[type="range"]::-moz-range-thumb {
    width: 20px;
    height: 20px;
    background: #f97316;
    border-radius: 50%;
    cursor: pointer;
    border: none;
}
</style>
