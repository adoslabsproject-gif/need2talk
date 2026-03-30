<!-- ENTERPRISE GALAXY: DM AUDIO WORKERS MONITORING & CONTROL -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-comments mr-3"></i>
    Monitoraggio DM Audio Workers
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(6, 182, 212, 0.2); color: #06b6d4; font-weight: 600;">
        <i class="fas fa-lock mr-1"></i>E2E ENCRYPTED
    </span>
    <span class="text-xs px-2 py-1 rounded ml-2" style="background: rgba(139, 92, 246, 0.2); color: #8b5cf6; font-weight: 600;">
        <i class="fas fa-docker mr-1"></i>DOCKER AUTO-SCALING
    </span>
</h2>

<?php
use Need2Talk\Controllers\AdminDMAudioWorkerController;

$controller = new AdminDMAudioWorkerController();
$status = $controller->getStatus();
$queue = $status['queue'] ?? ['pending' => 0, 'processing' => 0, 'delayed' => 0, 'failed' => 0];
$workerCount = $status['workers'] ?? 0;
$heartbeats = $status['heartbeats'] ?? [];
?>

<!-- Real-Time Status Cards -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
    <!-- Active Workers -->
    <div class="stat-card" style="border-left: 3px solid #06b6d4;">
        <span class="stat-value" style="color: #06b6d4;">
            <?= $workerCount ?> / 4
        </span>
        <div class="stat-label">
            <i class="fas fa-server mr-2"></i>Workers Attivi
        </div>
    </div>

    <!-- Pending Queue -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;">
            <?= number_format($queue['pending']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-clock mr-2"></i>In Coda
        </div>
    </div>

    <!-- Processing -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;">
            <?= number_format($queue['processing']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-cog fa-spin mr-2"></i>In Elaborazione
        </div>
    </div>

    <!-- Delayed -->
    <div class="stat-card" style="border-left: 3px solid #a855f7;">
        <span class="stat-value" style="color: #a855f7;">
            <?= number_format($queue['delayed']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-hourglass-half mr-2"></i>Ritardati
        </div>
    </div>

    <!-- Failed -->
    <div class="stat-card" style="border-left: 3px solid #ef4444;">
        <span class="stat-value" style="color: #ef4444;">
            <?= number_format($queue['failed']) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>Falliti
        </div>
    </div>

    <!-- Health Status -->
    <div class="stat-card" style="border-left: 3px solid <?= ($status['health'] ?? 'unknown') === 'healthy' ? '#10b981' : '#ef4444' ?>;">
        <span class="stat-value" style="color: <?= ($status['health'] ?? 'unknown') === 'healthy' ? '#10b981' : '#ef4444' ?>;">
            <?= strtoupper($status['health'] ?? 'SCONOSCIUTO') ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-heartbeat mr-2"></i>Stato di Salute
        </div>
    </div>
</div>

<!-- Architecture Info Box -->
<div class="card mt-6" style="border: 1px solid rgba(6, 182, 212, 0.3); background: rgba(6, 182, 212, 0.05);">
    <div class="flex items-start gap-4">
        <div class="text-cyan-400">
            <i class="fas fa-info-circle text-2xl"></i>
        </div>
        <div class="flex-1">
            <h4 class="text-white font-semibold mb-2">Architettura DM Audio Worker</h4>
            <div class="text-sm text-gray-400 space-y-1">
                <p><i class="fas fa-shield-alt mr-2 text-cyan-400"></i><strong>Crittografia E2E:</strong> Gli audio DM sono crittografati client-side prima dell'upload. Il worker non vede mai i dati in chiaro.</p>
                <p><i class="fas fa-bolt mr-2 text-cyan-400"></i><strong>Elaborazione Asincrona:</strong> Upload S3 in background, PHP-FPM libero per la chat real-time.</p>
                <p><i class="fas fa-expand-arrows-alt mr-2 text-cyan-400"></i><strong>Scaling Automatico:</strong> 1-4 workers basati sulla profondità della coda (< 10: 1, 10-50: 2, 50-100: 3, > 100: 4).</p>
                <p><i class="fas fa-bell mr-2 text-cyan-400"></i><strong>Notifiche WebSocket:</strong> Notifica sia mittente (dm_audio_uploaded) che destinatario (dm_received).</p>
            </div>
        </div>
    </div>
</div>

<!-- Worker Control Panel -->
<div class="card mt-8" style="border: 2px solid rgba(6, 182, 212, 0.3);">
    <h3 class="flex items-center justify-between mb-6">
        <span>
            <i class="fas fa-sliders-h mr-3"></i>Pannello di Controllo DM Workers
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle; background: rgba(6, 182, 212, 0.3); color: #06b6d4;">ENTERPRISE GALAXY</span>
        </span>
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Start/Stop Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-power-off mr-2 text-cyan-400"></i>
                Controlli di Accensione
            </h4>

            <div class="flex gap-3 mb-4">
                <button id="btn-start-dm-workers" class="btn btn-success flex-1" <?= $status['active'] ? 'disabled' : '' ?>>
                    <i class="fas fa-play mr-2"></i>Avvia Workers
                </button>
                <button id="btn-stop-dm-workers" class="btn btn-danger flex-1" <?= !$status['active'] ? 'disabled' : '' ?>>
                    <i class="fas fa-stop mr-2"></i>Ferma Workers
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
                    Numero Workers: <span id="dm-worker-count-display" class="font-bold text-white"><?= max(1, $workerCount) ?></span>
                </label>
                <input type="range" id="dm-worker-scale-slider" min="1" max="4" value="<?= max(1, $workerCount) ?>"
                       class="w-full" style="accent-color: #06b6d4;">
            </div>

            <button id="btn-scale-dm-workers" class="btn btn-primary w-full">
                <i class="fas fa-arrows-alt-h mr-2"></i>Applica Scaling
            </button>

            <div class="text-xs text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Max workers: 4 (per E2E processing)
            </div>
        </div>

        <!-- Auto-Scaling Controls -->
        <div class="p-4 rounded-lg" style="background: rgba(168, 85, 247, 0.1); border: 1px solid rgba(168, 85, 247, 0.2);">
            <h4 class="text-white mb-4 flex items-center">
                <i class="fas fa-robot mr-2 text-purple-400"></i>
                Auto-Scaling Intelligente
            </h4>

            <button id="btn-autoscale-dm-workers" class="btn w-full mb-3" style="background: linear-gradient(135deg, #a855f7 0%, #6366f1 100%); color: white;">
                <i class="fas fa-magic mr-2"></i>Esegui Auto-Scale
            </button>

            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-check mr-1 text-green-400"></i> &lt; 10 jobs → 1 worker</div>
                <div><i class="fas fa-check mr-1 text-green-400"></i> 10-50 jobs → 2 workers</div>
                <div><i class="fas fa-check mr-1 text-green-400"></i> 50-100 jobs → 3 workers</div>
                <div><i class="fas fa-check mr-1 text-green-400"></i> &gt; 100 jobs → 4 workers</div>
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
                    <input type="checkbox" id="dm-autostart-toggle" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-cyan-600"></div>
                </label>
            </div>

            <div class="text-xs text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>
                Stato: <span id="dm-autostart-status" class="font-semibold">Caricamento...</span>
            </div>
        </div>
    </div>
</div>

<!-- Failed Jobs Management -->
<?php if (($queue['failed'] ?? 0) > 0): ?>
<div class="card mt-8" style="border: 2px solid rgba(239, 68, 68, 0.3);">
    <h3 class="flex items-center justify-between mb-4">
        <span>
            <i class="fas fa-exclamation-triangle mr-3 text-red-400"></i>
            Jobs Falliti (<?= number_format($queue['failed']) ?>)
        </span>
        <button id="btn-cleanup-failed" class="btn btn-danger btn-sm">
            <i class="fas fa-trash-alt mr-2"></i>Pulisci Falliti
        </button>
    </h3>

    <div class="text-sm text-gray-400">
        <p><i class="fas fa-info-circle mr-2"></i>I jobs falliti possono essere ritentati o rimossi dalla coda.</p>
    </div>
</div>
<?php endif; ?>

<!-- Worker Heartbeats -->
<?php if (!empty($heartbeats)): ?>
<div class="card mt-8">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-heartbeat mr-3 text-red-400"></i>
        Heartbeat Workers (Redis)
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($heartbeats as $hb): ?>
        <div class="p-3 rounded-lg" style="background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3);">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-white"><?= htmlspecialchars($hb['worker_id'] ?? 'Sconosciuto') ?></span>
                <span class="text-xs px-2 py-1 rounded" style="background: #10b981; color: white;">ATTIVO</span>
            </div>
            <div class="text-xs text-gray-400 space-y-1">
                <div><i class="fas fa-clock mr-1"></i>Ultimo: <?= date('H:i:s', $hb['last_heartbeat'] ?? time()) ?></div>
                <div><i class="fas fa-upload mr-1"></i>Processati: <?= number_format($hb['metrics']['processed_count'] ?? 0) ?></div>
                <div><i class="fas fa-times-circle mr-1"></i>Falliti: <?= number_format($hb['metrics']['failed_count'] ?? 0) ?></div>
                <div><i class="fas fa-memory mr-1"></i>Memoria: <?= number_format(($hb['metrics']['memory_mb'] ?? 0), 1) ?> MB</div>
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
            Log Recenti (Canale: audio)
        </span>
        <button id="btn-refresh-dm-logs" class="btn btn-sm btn-secondary">
            <i class="fas fa-sync-alt mr-2"></i>Aggiorna
        </button>
    </h3>

    <div id="dm-logs-container" class="p-4 rounded-lg font-mono text-xs" style="background: #0f172a; color: #94a3b8; max-height: 400px; overflow-y: auto;">
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
        <i class="fas fa-terminal mr-3 text-cyan-400"></i>
        Monitor Output Live
    </h3>

    <div id="dm-monitor-output" class="p-4 rounded-lg font-mono text-xs" style="background: #0f172a; color: #94a3b8; max-height: 600px; overflow-y: auto;">
        <div class="text-gray-500 italic">Le conferme delle azioni appariranno qui quando avvii/fermi/scali i workers...</div>
    </div>

    <div class="mt-3 text-xs text-gray-400">
        <i class="fas fa-info-circle mr-1"></i>
        Mostra messaggi di conferma per le azioni sui DM Audio Workers | Auto-scroll abilitato
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
        const monitor = document.getElementById('dm-monitor-output');
        if (!monitor) return;

        const timestamp = new Date().toLocaleTimeString();
        const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
        const color = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#06b6d4';

        const line = document.createElement('div');
        line.style.marginBottom = '4px';
        line.style.color = color;
        line.innerHTML = `[${timestamp}] ${icon} ${message}`;

        monitor.appendChild(line);
        monitor.scrollTop = monitor.scrollHeight;
    }

    // Slider update
    const slider = document.getElementById('dm-worker-scale-slider');
    const display = document.getElementById('dm-worker-count-display');

    slider?.addEventListener('input', function() {
        display.textContent = this.value;
    });

    // Start Workers
    document.getElementById('btn-start-dm-workers')?.addEventListener('click', async function() {
        if (!confirm('Avviare i DM Audio Workers?')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Avvio in corso...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/start`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor('DM Audio Workers avviati con successo', 'success');
                alert('✅ DM Audio Workers avviati con successo');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-play mr-2"></i>Avvia Workers';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile avviare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-play mr-2"></i>Avvia Workers';
        }
    });

    // Stop Workers
    document.getElementById('btn-stop-dm-workers')?.addEventListener('click', async function() {
        if (!confirm('Fermare tutti i DM Audio Workers? L\'elaborazione verrà messa in pausa.')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Arresto in corso...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/stop`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor('DM Audio Workers fermati', 'success');
                alert('✅ DM Audio Workers fermati');
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-stop mr-2"></i>Ferma Workers';
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile fermare i workers');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-stop mr-2"></i>Ferma Workers';
        }
    });

    // Scale Workers
    document.getElementById('btn-scale-dm-workers')?.addEventListener('click', async function() {
        const workerCount = slider.value;
        if (!confirm(`Scalare a ${workerCount} DM Audio Workers?`)) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Scaling in corso...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/scale`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ worker_count: parseInt(workerCount) })
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor(`Scalato a ${workerCount} workers`, 'success');
                alert(`✅ Scalato a ${workerCount} DM Audio Workers`);
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
    document.getElementById('btn-autoscale-dm-workers')?.addEventListener('click', async function() {
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Auto-scaling...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/auto-scale`, {
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

    // Cleanup Failed Jobs
    document.getElementById('btn-cleanup-failed')?.addEventListener('click', async function() {
        if (!confirm('Rimuovere tutti i jobs falliti dalla coda? Questa azione non può essere annullata.')) return;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Pulizia...';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const headers = {'X-Requested-With': 'XMLHttpRequest'};
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/cleanup-failed`, {
                method: 'POST',
                headers: headers
            });
            const data = await response.json();

            if (data.success) {
                appendToMonitor(`Rimossi ${data.cleaned_count} jobs falliti`, 'success');
                alert(`✅ Rimossi ${data.cleaned_count} jobs falliti`);
                setTimeout(() => location.reload(), 2000);
            } else {
                appendToMonitor(data.message, 'error');
                alert('❌ ' + data.message);
            }
        } catch (error) {
            appendToMonitor('Errore di connessione', 'error');
            alert('❌ Impossibile pulire i jobs falliti');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-trash-alt mr-2"></i>Pulisci Falliti';
        }
    });

    // Refresh Logs
    document.getElementById('btn-refresh-dm-logs')?.addEventListener('click', function() {
        location.reload();
    });

    // ============================================================================
    // AUTOSTART CONTROLS
    // ============================================================================

    const autostartToggle = document.getElementById('dm-autostart-toggle');
    const autostartStatus = document.getElementById('dm-autostart-status');

    // Load autostart status on page load
    async function loadAutostartStatus() {
        try {
            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/autostart-status`);
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

            const response = await fetch(`${ADMIN_BASE_URL}/api/dm-audio-workers/set-autostart`, {
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

    // Auto-refresh every 15 seconds (faster for real-time chat)
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
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.05) 0%, rgba(59, 130, 246, 0.05) 100%);
    border: 1px solid rgba(6, 182, 212, 0.2);
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
    background: rgba(6, 182, 212, 0.2);
    border-radius: 3px;
    outline: none;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 20px;
    height: 20px;
    background: #06b6d4;
    border-radius: 50%;
    cursor: pointer;
}

input[type="range"]::-moz-range-thumb {
    width: 20px;
    height: 20px;
    background: #06b6d4;
    border-radius: 50%;
    cursor: pointer;
    border: none;
}
</style>
