<!-- MODULAR VIEW SYSTEM ACTIVE -->
<h2>📊 Dashboard Sistema (Modular Views)</h2>

<div class="dashboard-vertical">
    <div class="card">
        <h3>🚀 Stato Sistema</h3>
        <?php if (isset($stats) && is_array($stats)) { ?>
            <?php foreach ($stats as $key => $value) { ?>
                <div class="metric">
                    <span><?= ucfirst(str_replace('_', ' ', $key)) ?></span>
                    <span class="metric-value">
                        <?php
                        if (is_array($value)) {
                            echo $controller->formatMetricValue($key, $value);
                        } else {
                            echo htmlspecialchars((string)$value);
                        }
                ?>
                    </span>
                </div>
            <?php } ?>
        <?php } else { ?>
            <p>Caricamento metriche dashboard...</p>
        <?php } ?>
    </div>

    <div class="card">
        <h3>⚡ Prestazioni Real-time</h3>
        <div class="metric">
            <span>Tempo di Risposta</span>
            <span class="metric-value"><?= isset($realtime_stats['response_time']) ? $realtime_stats['response_time'] . 'ms' : '<span class="text-muted">Caricamento...</span>' ?></span>
        </div>
        <div class="metric">
            <span>Uso Memoria</span>
            <span class="metric-value"><?= isset($realtime_stats['memory_usage']) ? $realtime_stats['memory_usage'] . '%' : '<span class="text-muted">Caricamento...</span>' ?></span>
        </div>
        <div class="metric">
            <span>Workers Attivi</span>
            <span class="metric-value"><?= isset($realtime_stats['workers_active']) ? $realtime_stats['workers_active'] : '<span class="text-muted">Caricamento...</span>' ?></span>
        </div>
        <div class="metric">
            <span>Uso CPU</span>
            <span class="metric-value"><?= isset($realtime_stats['cpu_usage']) ? $realtime_stats['cpu_usage'] . '%' : '<span class="text-muted">Caricamento...</span>' ?></span>
        </div>
        <div class="metric">
            <span>Uso Disco</span>
            <span class="metric-value"><?= isset($realtime_stats['disk_usage']) ? $realtime_stats['disk_usage'] . '%' : '<span class="text-muted">Caricamento...</span>' ?></span>
        </div>
        <div class="metric">
            <span>Stato Redis</span>
            <span class="metric-value">
                <?php if (isset($realtime_stats['redis']['status'])) { ?>
                    <span class="status-indicator status-<?= $realtime_stats['redis']['status'] === 'connected' ? 'green' : 'red' ?>"></span>
                    <?= ucfirst($realtime_stats['redis']['status']) ?>
                <?php } else { ?>
                    <span class="text-muted">Caricamento...</span>
                <?php } ?>
            </span>
        </div>
        <div class="metric">
            <span>OpCache</span>
            <span class="metric-value">
                <?php if (isset($realtime_stats['opcache']['enabled'])) { ?>
                    <span class="status-indicator status-<?= $realtime_stats['opcache']['enabled'] ? 'green' : 'red' ?>"></span>
                    <?= $realtime_stats['opcache']['enabled'] ? 'Attivo' : 'Disabilitato' ?>
                    <?php if ($realtime_stats['opcache']['enabled'] && isset($realtime_stats['opcache']['hit_rate'])) { ?>
                        (<?= $realtime_stats['opcache']['hit_rate'] ?>% hit rate)
                    <?php } ?>
                <?php } else { ?>
                    <span class="text-muted">Caricamento...</span>
                <?php } ?>
            </span>
        </div>
    </div>

    <!-- Quick Actions (2025-01-10) -->
    <div class="card">
        <h3>⚡ Azioni Rapide</h3>
        <div style="display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;">
            <!-- Terminal -->
            <a href="terminal" style="background: linear-gradient(135deg, #111827 0%, #000000 100%);" class="px-6 py-3 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2 no-underline">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink: 0;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span>Terminale Sistema</span>
            </a>
        </div>
    </div>
</div>