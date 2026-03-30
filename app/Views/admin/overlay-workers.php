<!-- ENTERPRISE GALAXY: OVERLAY FLUSH WORKERS MONITORING & CONTROL -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-layer-group mr-3"></i>
    Monitoraggio Overlay Flush Workers
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; font-weight: 600;">
        <i class="fas fa-database mr-1"></i>WRITE-BEHIND CACHE
    </span>
    <span class="text-xs px-2 py-1 rounded ml-2" style="background: rgba(139, 92, 246, 0.2); color: #8b5cf6; font-weight: 600;">
        <i class="fas fa-docker mr-1"></i>DISTRIBUTED (16 PARTITIONS)
    </span>
</h2>

<?php
use Need2Talk\Controllers\AdminOverlayWorkerController;

$controller = new AdminOverlayWorkerController();
$status = $controller->getStatus();
$queue = $status['queue'] ?? ['reactions_pending' => 0, 'plays_pending' => 0, 'comments_pending' => 0, 'total_pending' => 0];
$workerCount = $status['workers'] ?? 0;
$heartbeats = $status['heartbeats'] ?? [];
$activityLevel = $status['activity_level'] ?? 'UNKNOWN';
$queueHealth = $status['queue_health'] ?? null;
?>

<!-- Real-Time Status Cards -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
    <!-- Active Workers -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;">
            <?= $workerCount ?> / 8
        </span>
        <div class="stat-label">
            <i class="fas fa-server mr-2"></i>Workers Attivi
        </div>
    </div>

    <!-- Reactions Pending -->
    <div class="stat-card" style="border-left: 3px solid #ef4444;">
        <span class="stat-value" style="color: #ef4444;">
            <?= number_format($queue['reactions_pending']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-heart mr-2"></i>Reactions in Coda
        </div>
    </div>

    <!-- Plays Pending -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;">
            <?= number_format($queue['plays_pending']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-play mr-2"></i>Plays in Coda
        </div>
    </div>

    <!-- Comments Pending -->
    <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
        <span class="stat-value" style="color: #8b5cf6;">
            <?= number_format($queue['comments_pending']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-comment mr-2"></i>Comments in Coda
        </div>
    </div>

    <!-- Total Pending -->
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;">
            <?= number_format($queue['total_pending']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-tasks mr-2"></i>Totale in Coda
        </div>
    </div>

    <!-- Activity Level -->
    <div class="stat-card" style="border-left: 3px solid <?= match($activityLevel) {
        'IDLE' => '#6b7280',
        'LOW' => '#10b981',
        'NORMAL' => '#3b82f6',
        'HIGH' => '#f59e0b',
        'PEAK' => '#ef4444',
        default => '#6b7280',
    } ?>;">
        <span class="stat-value" style="color: <?= match($activityLevel) {
            'IDLE' => '#6b7280',
            'LOW' => '#10b981',
            'NORMAL' => '#3b82f6',
            'HIGH' => '#f59e0b',
            'PEAK' => '#ef4444',
            default => '#6b7280',
        } ?>;">
            <?= $activityLevel ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-tachometer-alt mr-2"></i>Livello Attivita
        </div>
    </div>

    <!-- Health Status -->
    <div class="stat-card" style="border-left: 3px solid <?= match($status['health'] ?? 'unknown') {
        'healthy' => '#10b981',
        'warning' => '#f59e0b',
        'critical' => '#ef4444',
        'overflow' => '#dc2626',
        default => '#6b7280',
    } ?>;">
        <span class="stat-value" style="color: <?= match($status['health'] ?? 'unknown') {
            'healthy' => '#10b981',
            'warning' => '#f59e0b',
            'critical' => '#ef4444',
            'overflow' => '#dc2626',
            default => '#6b7280',
        } ?>;">
            <?= strtoupper($status['health'] ?? 'SCONOSCIUTO') ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-heartbeat mr-2"></i>Stato di Salute
        </div>
    </div>
</div>

<!-- Architecture Info Box -->
<div class="card mt-6" style="border: 1px solid rgba(245, 158, 11, 0.3); background: rgba(245, 158, 11, 0.05);">
    <div class="flex items-start gap-4">
        <div class="text-amber-400">
            <i class="fas fa-info-circle text-2xl"></i>
        </div>
        <div class="flex-1">
            <h4 class="text-white font-semibold mb-2">Architettura Overlay Flush Worker</h4>
            <div class="text-sm text-gray-400 space-y-1">
                <p><i class="fas fa-layer-group mr-2 text-amber-400"></i><strong>Cache Write-Behind:</strong> Reactions, plays e comments vengono scritti prima in Redis, poi flushati in PostgreSQL.</p>
                <p><i class="fas fa-clock mr-2 text-amber-400"></i><strong>Scheduling Adattivo:</strong> Intervallo flush dinamico (5s-60s) basato sul livello di attività (IDLE/LOW/NORMAL/HIGH/PEAK).</p>
                <p><i class="fas fa-cubes mr-2 text-amber-400"></i><strong>16 Partizioni:</strong> Distribuzione carico su partizioni per scaling orizzontale fino a 16 workers.</p>
                <p><i class="fas fa-shield-alt mr-2 text-amber-400"></i><strong>Salute Coda:</strong> Monitoraggio con alert WARNING (1000+), CRITICAL (5000+), OVERFLOW (10000+).</p>
            </div>
        </div>
    </div>
</div>

<!-- Queue Health Alerts -->
<?php if ($queueHealth && !empty($queueHealth['alerts'])): ?>
<div class="card mt-6" style="border: 2px solid rgba(239, 68, 68, 0.5); background: rgba(239, 68, 68, 0.1);">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-exclamation-triangle mr-3 text-red-400"></i>
        Alert Coda (<?= count($queueHealth['alerts']) ?>)
    </h3>

    <div class="space-y-2">
        <?php foreach ($queueHealth['alerts'] as $alert): ?>
        <div class="p-3 rounded-lg" style="background: rgba(0,0,0,0.2); border-left: 3px solid <?= match($alert['level']) {
            'warning' => '#f59e0b',
            'critical' => '#ef4444',
            'alert' => '#dc2626',
            default => '#6b7280',
        } ?>;">
            <div class="flex items-center justify-between">
                <span class="text-sm text-white">
                    <span class="font-semibold uppercase"><?= htmlspecialchars($alert['level']) ?>:</span>
                    <?= htmlspecialchars($alert['queue']) ?>
                </span>
                <span class="text-xs px-2 py-1 rounded" style="background: rgba(255,255,255,0.1);">
                    <?= number_format($alert['size']) ?> items
                </span>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($alert['message']) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Worker Control Panel -->
<div class="card mt-8" style="border: 2px solid rgba(245, 158, 11, 0.3);">
    <h3 class="flex items-center justify-between mb-6">
        <span>
            <i class="fas fa-sliders-h mr-3"></i>Pannello di Controllo Overlay Workers
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle; background: rgba(245, 158, 11, 0.3); color: #f59e0b;">ENTERPRISE GALAXY</span>
        </span>
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Start/Stop Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-power-off mr-2 text-amber-400"></i>
                Controlli di Accensione
            </h4>

            <div class="flex gap-3 mb-4">
                <button id="btn-start-overlay-workers" class="btn btn-success flex-1" <?= $status['active'] ? 'disabled' : '' ?>>
                    <i class="fas fa-play mr-2"></i>Avvia
                </button>
                <button id="btn-stop-overlay-workers" class="btn btn-danger flex-1" <?= !$status['active'] ? 'disabled' : '' ?>>
                    <i class="fas fa-stop mr-2"></i>Ferma
                </button>
            </div>

            <div class="text-xs text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>
                Stato: <span class="font-semibold <?= $status['active'] ? 'text-green-400' : 'text-red-400' ?>">
                    <?= $status['active'] ? 'IN ESECUZIONE' : 'FERMO' ?>
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
                    Numero Workers: <span id="overlay-worker-count-display" class="font-bold text-white"><?= max(1, $workerCount) ?></span>
                </label>
                <input type="range" id="overlay-worker-scale-slider" min="1" max="8" value="<?= max(1, $workerCount) ?>"
                       class="w-full" style="accent-color: #f59e0b;">
            </div>

            <button id="btn-scale-overlay-workers" class="btn btn-primary w-full">
                <i class="fas fa-arrows-alt-h mr-2"></i>Applica Scaling
            </button>

            <div class="text-xs text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Max 8 workers (16 partizioni)
            </div>
        </div>

        <!-- Auto-Scaling Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(168, 85, 247, 0.1); border: 1px solid rgba(168, 85, 247, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-robot mr-2 text-purple-400"></i>
                Auto-Scaling Intelligente
            </h4>

            <button id="btn-autoscale-overlay-workers" class="btn w-full mb-3" style="background: linear-gradient(135deg, #a855f7 0%, #6366f1 100%); color: white;">
                <i class="fas fa-magic mr-2"></i>Esegui Auto-Scale
            </button>

            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-check mr-1 text-green-400"></i> &lt; 50 items → 1 worker</div>
                <div><i class="fas fa-check mr-1 text-green-400"></i> 50-100 items → 2 workers</div>
                <div><i class="fas fa-check mr-1 text-green-400"></i> 100-500 items → 4 workers</div>
                <div><i class="fas fa-check mr-1 text-green-400"></i> &gt; 500 items → 8 workers</div>
            </div>
        </div>

        <!-- Force Flush -->
        <div class="p-4 rounded-lg" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-bolt mr-2 text-red-400"></i>
                Flush Manuale
            </h4>

            <button id="btn-force-flush" class="btn btn-danger w-full mb-3">
                <i class="fas fa-database mr-2"></i>Forza Flush Ora
            </button>

            <div class="text-xs text-gray-400">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Forza scrittura immediata buffer → PostgreSQL
            </div>
        </div>

        <!-- Autostart Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-power-off mr-2 text-green-400"></i>
                Avvio Automatico al Boot
            </h4>

            <div class="flex items-center justify-between mb-3">
                <span class="text-sm text-gray-300">Abilita avvio automatico</span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="overlay-autostart-toggle" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600"></div>
                </label>
            </div>

            <div class="text-xs text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>
                Stato: <span id="overlay-autostart-status" class="font-semibold">Caricamento...</span>
            </div>
        </div>
    </div>
</div>

<!-- Worker Heartbeats -->
<?php if (!empty($heartbeats)): ?>
<div class="card mt-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-heartbeat mr-3 text-red-400"></i>
        Heartbeat Workers (Redis)
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($heartbeats as $hb): ?>
        <div class="p-3 rounded-lg" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3);">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-white"><?= htmlspecialchars(substr($hb['worker_id'] ?? 'N/A', 0, 12)) ?>...</span>
                <span class="text-xs px-2 py-1 rounded" style="background: #10b981; color: white;">ATTIVO</span>
            </div>
            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-clock mr-1"></i>Ultimo: <?= date('H:i:s', $hb['last_heartbeat'] ?? time()) ?></div>
                <?php if ($hb['partition'] !== null): ?>
                <div><i class="fas fa-th mr-1"></i>Partizione: <?= $hb['partition'] ?></div>
                <?php endif; ?>
                <div><i class="fas fa-sync mr-1"></i>Flush: <?= number_format($hb['metrics']['flush_count'] ?? 0) ?></div>
                <div><i class="fas fa-heart mr-1"></i>Reactions: <?= number_format($hb['metrics']['reactions_flushed'] ?? 0) ?></div>
                <div><i class="fas fa-play mr-1"></i>Plays: <?= number_format($hb['metrics']['plays_flushed'] ?? 0) ?></div>
                <div><i class="fas fa-memory mr-1"></i>Memoria: <?= number_format($hb['metrics']['memory_mb'] ?? 0, 1) ?> MB</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Logs -->
<div class="card mt-8">
    <h3 class="flex items-center justify-between mb-4">
        <span>
            <i class="fas fa-file-alt mr-3"></i>
            Log Recenti (Canale: overlay)
        </span>
        <button id="btn-refresh-overlay-logs" class="btn btn-sm btn-secondary">
            <i class="fas fa-sync-alt mr-2"></i>Aggiorna
        </button>
    </h3>

    <div id="overlay-logs-container" class="p-4 rounded-lg font-mono text-xs" style="background: #0f172a; color: #94a3b8; max-height: 400px; overflow-y: auto;">
        <?php foreach (($status['recent_logs'] ?? []) as $log): ?>
        <div class="mb-1"><?= htmlspecialchars($log) ?></div>
        <?php endforeach; ?>
        <?php if (empty($status['recent_logs'])): ?>
        <div class="text-gray-500 italic">Nessun log recente disponibile...</div>
        <?php endif; ?>
    </div>
</div>

<!-- Monitor Live Output -->
<div class="card mt-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-terminal mr-3 text-amber-400"></i>
        Monitor Output Live
    </h3>

    <div id="overlay-monitor-output" class="p-4 rounded-lg font-mono text-xs" style="background: #0f172a; color: #94a3b8; max-height: 600px; overflow-y: auto;">
        <div class="text-gray-500 italic">Le conferme delle azioni appariranno qui quando avvii/fermi/scali i workers o forzi il flush...</div>
    </div>

    <div class="mt-3 text-xs text-gray-400">
        <i class="fas fa-info-circle mr-1"></i>
        Mostra messaggi di conferma per le azioni sugli Overlay Workers | Auto-scroll abilitato
    </div>
</div>

<!-- JavaScript for Controls -->
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Extract dynamic admin URL from current path (ENTERPRISE SECURITY)
    const currentPath = window.location.pathname;
    const adminUrlMatch = currentPath.match(/^(\/admin_[a-f0-9]{16})/);
    const ADMIN_BASE_URL = adminUrlMatch ? adminUrlMatch[1] : '/admin';

    // Helper function to append messages to monitor
    function appendToMonitor(message, type = 'info') {
        const monitor = document.getElementById('overlay-monitor-output');
        if (!monitor) return;

        const timestamp = new Date().toLocaleTimeString();
        const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
        const color = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b';

        const line = document.createElement('div');
        line.style.marginBottom = '4px';
        line.style.color = color;
        line.innerHTML = `[${timestamp}] ${icon} ${message}`;

        monitor.appendChild(line);
        monitor.scrollTop = monitor.scrollHeight;
    }

    // Slider update
    const slider = document.getElementById('overlay-worker-scale-slider');
    const display = document.getElementById('overlay-worker-count-display');

    slider?.addEventListener('input', function() {
        display.textContent = this.value;
    });

    // Start Workers
    document.getElementById('btn-start-overlay-workers')?.addEventListener('click', async function() {
        if (!confirm('Avviare gli Overlay Workers?')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Avvio...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/start`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor('Overlay Workers avviati con successo', 'success');
                alert('✅ Overlay Workers avviati con successo');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-play mr-2"></i>Avvia';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile avviare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-play mr-2"></i>Avvia';
        }
    });

    // Stop Workers
    document.getElementById('btn-stop-overlay-workers')?.addEventListener('click', async function() {
        if (!confirm('Fermare tutti gli Overlay Workers? Il flush verra messo in pausa.')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Arresto...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/stop`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor('Overlay Workers fermati', 'success');
                alert('✅ Overlay Workers fermati');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-stop mr-2"></i>Ferma';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile fermare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-stop mr-2"></i>Ferma';
        }
    });

    // Scale Workers
    document.getElementById('btn-scale-overlay-workers')?.addEventListener('click', async function() {
        const workerCount = slider.value;
        if (!confirm(`Scalare a ${workerCount} Overlay Workers?`)) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Scaling...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/scale`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ worker_count: parseInt(workerCount) })
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor(`Scalato a ${workerCount} workers`, 'success');
                alert(`✅ Scalato a ${workerCount} Overlay Workers`);
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-arrows-alt-h mr-2"></i>Applica Scaling';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile scalare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-arrows-alt-h mr-2"></i>Applica Scaling';
        }
    });

    // Auto-Scale Workers
    document.getElementById('btn-autoscale-overlay-workers')?.addEventListener('click', async function() {
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Auto-scaling...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/auto-scale`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor(`Auto-scale completato: ${data.new_count} workers (queue depth: ${data.queue_depth})`, 'success');
                alert(`✅ Auto-scale completato: ${data.new_count} workers`);
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile eseguire auto-scale');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-magic mr-2"></i>Esegui Auto-Scale';
        }
    });

    // Force Flush
    document.getElementById('btn-force-flush')?.addEventListener('click', async function() {
        if (!confirm('Forzare il flush immediato del buffer verso PostgreSQL?')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Flush in corso...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/force-flush`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                const msg = `Flush completato: ${data.reactions_flushed} reactions, ${data.plays_flushed} plays, ${data.comments_flushed} comments (${data.duration_ms}ms)`;
                appendToMonitor(msg, 'success');
                alert('✅ ' + msg);
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile eseguire il flush');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-database mr-2"></i>Forza Flush Ora';
        }
    });

    // Refresh Logs
    document.getElementById('btn-refresh-overlay-logs')?.addEventListener('click', function() {
        location.reload();
    });

    // ============================================================================
    // AUTOSTART CONTROLS
    // ============================================================================

    const autostartToggle = document.getElementById('overlay-autostart-toggle');
    const autostartStatus = document.getElementById('overlay-autostart-status');

    // Load autostart status on page load
    async function loadAutostartStatus() {
        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/autostart-status`);
            const data = await response.json();

            if (data.success) {
                autostartToggle.checked = data.autostart_enabled;
                autostartStatus.textContent = data.autostart_enabled ? 'ABILITATO' : 'DISABILITATO';
                autostartStatus.className = data.autostart_enabled ? 'font-semibold text-green-400' : 'font-semibold text-gray-400';
            }
        } catch (error) {
            autostartStatus.textContent = 'ERRORE';
            autostartStatus.className = 'font-semibold text-red-400';
        }
    }

    // Toggle autostart
    autostartToggle?.addEventListener('change', async function() {
        const enabled = this.checked;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            const headers = {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };

            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken;
            }

            const response = await fetch(`${ADMIN_BASE_URL}/api/overlay-workers/set-autostart`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ enabled })
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor(data.message, 'success');
                alert('✅ ' + data.message);
                autostartStatus.textContent = enabled ? 'ABILITATO' : 'DISABILITATO';
                autostartStatus.className = enabled ? 'font-semibold text-green-400' : 'font-semibold text-gray-400';
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
                this.checked = !enabled; // Revert toggle
            }
        } catch (error) {
            alert('❌ Impossibile aggiornare l\'autostart: ' + error.message);
            this.checked = !enabled; // Revert toggle
        }
    });

    loadAutostartStatus();

    // ============================================================================
    // AUTO-REFRESH PAGE DATA
    // ============================================================================

    // Auto-refresh every 20 seconds
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
    }, 20000);
});
</script>

<style nonce="<?= csp_nonce() ?>">
.stat-card {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(59, 130, 246, 0.05) 100%);
    border: 1px solid rgba(245, 158, 11, 0.2);
    border-radius: 0.5rem;
    padding: 1rem;
}

.stat-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.8rem;
    color: #9ca3af;
    display: flex;
    align-items: center;
}

input[type="range"] {
    width: 100%;
    height: 6px;
    background: rgba(245, 158, 11, 0.2);
    border-radius: 3px;
    outline: none;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 20px;
    height: 20px;
    background: #f59e0b;
    border-radius: 50%;
    cursor: pointer;
}

input[type="range"]::-moz-range-thumb {
    width: 20px;
    height: 20px;
    background: #f59e0b;
    border-radius: 50%;
    cursor: pointer;
    border: none;
}
</style>
