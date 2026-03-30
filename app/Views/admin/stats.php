<!-- ENTERPRISE GALAXY: Performance Analytics Dashboard -->
<h2 class="enterprise-title mb-8 flex items-center justify-between">
    <span class="flex items-center">
        <i class="fas fa-chart-line mr-3"></i>
        Analisi Prestazioni Enterprise
    </span>
    <div class="flex items-center gap-3">
        <!-- Export Selector (Native Select like timeframe) -->
        <select id="export-selector" style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #e2e8f0; padding: 0.375rem 0.75rem; font-size: 0.875rem; border: 1px solid #047857; border-radius: 0.5rem; font-weight: 500; cursor: pointer;" class="focus:outline-none focus:border-emerald-600">
            <option value="" selected disabled style="background-color: #334155; color: #9ca3af;">📥 Esporta Dati...</option>
            <option value="json" style="background-color: #334155; color: #e2e8f0;">📄 Esporta JSON</option>
            <option value="csv" style="background-color: #334155; color: #e2e8f0;">📊 Esporta CSV</option>
            <option value="txt" style="background-color: #334155; color: #e2e8f0;">📝 Esporta TXT</option>
        </select>

        <button id="refresh-stats-btn" style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-3 py-1.5 text-sm text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Aggiorna
        </button>
        <select id="timeframe-selector" style="background-color: #334155; color: #e2e8f0; padding: 0.375rem 0.75rem; font-size: 0.875rem; border: 1px solid #475569; border-radius: 0.5rem; font-weight: 500;" class="focus:outline-none focus:border-purple-500">
            <option value="24h" selected style="background-color: #334155; color: #e2e8f0;">Ultime 24 Ore</option>
            <option value="7d" style="background-color: #334155; color: #e2e8f0;">Ultimi 7 Giorni</option>
            <option value="30d" style="background-color: #334155; color: #e2e8f0;">Ultimi 30 Giorni</option>
        </select>
    </div>
</h2>

<?php if (isset($error)): ?>
<div class="bg-red-900/30 border border-red-700 text-red-200 px-6 py-4 rounded-lg mb-6">
    <p class="font-medium">⚠️ Errore nel caricamento delle statistiche</p>
    <p class="text-sm mt-1"><?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>

<!-- ENTERPRISE ALERTS SYSTEM -->
<?php if (!empty($alerts)): ?>
<div class="mb-6">
    <h3 class="text-lg font-semibold mb-4 flex items-center">
        <i class="fas fa-exclamation-triangle mr-2 text-yellow-400"></i>
        Avvisi Attivi (<?= count($alerts) ?>)
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($alerts as $alert): ?>
        <div class="p-4 rounded-lg border-2 <?= $alert['level'] === 'critical' ? 'bg-red-900/20 border-red-700' : 'bg-yellow-900/20 border-yellow-700' ?>">
            <div class="flex items-start justify-between mb-2">
                <span class="text-xs font-mono uppercase tracking-wider <?= $alert['level'] === 'critical' ? 'text-red-400' : 'text-yellow-400' ?>">
                    <?= htmlspecialchars($alert['level']) ?>
                </span>
                <span class="text-xs text-slate-400"><?= htmlspecialchars($alert['type']) ?></span>
            </div>
            <p class="text-sm font-medium"><?= htmlspecialchars($alert['message']) ?></p>
            <?php if (isset($alert['value'])): ?>
            <p class="text-xl font-bold mt-2 <?= $alert['level'] === 'critical' ? 'text-red-400' : 'text-yellow-400' ?>">
                <?= htmlspecialchars($alert['value']) ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ENTERPRISE PERFORMANCE OVERVIEW -->
<div class="dashboard-grid mb-8">
    <!-- Database Performance -->
    <div class="card">
        <h3>🗄️ Prestazioni Database</h3>
        <?php if (isset($dashboard_stats['database'])): ?>
            <?php $db = $dashboard_stats['database']; ?>
            <div class="metric">
                <span>Stato</span>
                <span class="metric-value">
                    <span class="status-indicator status-<?= $db['status'] === 'healthy' ? 'green' : 'red' ?>"></span>
                    <?= htmlspecialchars($db['status'] === 'healthy' ? 'sano' : 'degradato') ?>
                </span>
            </div>
            <div class="metric">
                <span>Query/Secondo</span>
                <span class="metric-value"><?= number_format($db['queries_per_second'] ?? 0, 2) ?></span>
            </div>
            <div class="metric">
                <span>Query Lente</span>
                <span class="metric-value text-<?= ($db['slow_queries'] ?? 0) > 10 ? 'red' : 'green' ?>-400">
                    <?= number_format($db['slow_queries'] ?? 0) ?>
                </span>
            </div>
            <div class="metric">
                <span>Connessioni Attive</span>
                <span class="metric-value"><?= $db['threads_connected'] ?? 0 ?></span>
            </div>
            <?php if (isset($db['connection_pool'])): ?>
            <div class="metric">
                <span>Utilizzo Pool</span>
                <span class="metric-value text-<?= $db['connection_pool']['utilization_percent'] > 80 ? 'yellow' : 'green' ?>-400">
                    <?= $db['connection_pool']['utilization_percent'] ?>%
                </span>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-slate-400">Caricamento statistiche database...</p>
        <?php endif; ?>
    </div>

    <!-- Cache Performance -->
    <div class="card">
        <h3>⚡ Prestazioni Cache</h3>
        <?php if (isset($dashboard_stats['cache'])): ?>
            <?php $cache = $dashboard_stats['cache']; ?>
            <?php if (isset($cache['performance'])): ?>
            <div class="metric">
                <span>Rapporto Hit</span>
                <span class="metric-value text-<?= ($cache['performance']['hit_ratio'] ?? 0) > 70 ? 'green' : 'yellow' ?>-400">
                    <?= number_format($cache['performance']['hit_ratio'] ?? 0, 1) ?>%
                </span>
            </div>
            <div class="metric">
                <span>Hit Totali</span>
                <span class="metric-value"><?= number_format(is_array($cache['performance']['hits'] ?? 0) ? array_sum($cache['performance']['hits']) : ($cache['performance']['hits'] ?? 0)) ?></span>
            </div>
            <div class="metric">
                <span>Miss Totali</span>
                <span class="metric-value"><?= number_format($cache['performance']['misses'] ?? 0) ?></span>
            </div>
            <?php endif; ?>
            <?php if (isset($cache['health'])): ?>
            <div class="metric">
                <span>Stato Salute</span>
                <span class="metric-value">
                    <?php $healthStatus = ($cache['health']['overall'] ?? false) ? 'sano' : 'degradato'; ?>
                    <span class="status-indicator status-<?= ($cache['health']['overall'] ?? false) ? 'green' : 'yellow' ?>"></span>
                    <?= htmlspecialchars($healthStatus) ?>
                </span>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-slate-400">Caricamento statistiche cache...</p>
        <?php endif; ?>
    </div>

    <!-- Security Stats -->
    <div class="card">
        <h3>🔒 Panoramica Sicurezza</h3>
        <?php if (isset($dashboard_stats['security'])): ?>
            <?php $sec = $dashboard_stats['security']; ?>
            <div class="metric">
                <span>Livello Minaccia</span>
                <span class="metric-value">
                    <span class="px-2 py-1 rounded text-xs font-bold <?=
                        $sec['threat_level'] === 'high' ? 'bg-red-900 text-red-200' :
                        ($sec['threat_level'] === 'medium' ? 'bg-yellow-900 text-yellow-200' : 'bg-green-900 text-green-200')
            ?>">
                        <?= strtoupper($sec['threat_level'] === 'high' ? 'ALTO' : ($sec['threat_level'] === 'medium' ? 'MEDIO' : 'BASSO')) ?>
                    </span>
                </span>
            </div>
            <div class="metric">
                <span>Login Falliti (24h)</span>
                <span class="metric-value text-<?= ($sec['failed_logins_24h'] ?? 0) > 50 ? 'red' : 'green' ?>-400">
                    <?= number_format($sec['failed_logins_24h'] ?? 0) ?>
                </span>
            </div>
            <div class="metric">
                <span>Violazioni Rate Limit (24h)</span>
                <span class="metric-value"><?= number_format($sec['rate_violations_24h'] ?? 0) ?></span>
            </div>
            <div class="metric">
                <span>Ban Attivi</span>
                <span class="metric-value text-red-400"><?= $sec['active_bans'] ?? 0 ?></span>
            </div>
        <?php else: ?>
            <p class="text-slate-400">Caricamento statistiche sicurezza...</p>
        <?php endif; ?>
    </div>

    <!-- User Activity -->
    <div class="card">
        <h3>👥 Attività Utenti</h3>
        <?php if (isset($dashboard_stats['users'])): ?>
            <?php $users = $dashboard_stats['users']; ?>
            <div class="metric">
                <span>Utenti Totali</span>
                <span class="metric-value"><?= number_format($users['total_users'] ?? 0) ?></span>
            </div>
            <div class="metric">
                <span>Online Adesso</span>
                <span class="metric-value text-green-400"><?= number_format($users['online_users'] ?? 0) ?></span>
            </div>
            <div class="metric">
                <span>Nuovi Oggi</span>
                <span class="metric-value text-blue-400"><?= $users['new_registrations_today'] ?? 0 ?></span>
            </div>
            <div class="metric">
                <span>Attivi (7gg)</span>
                <span class="metric-value"><?= number_format($users['active_users_7d'] ?? 0) ?></span>
            </div>
            <div class="metric">
                <span>Upload Audio Oggi</span>
                <span class="metric-value text-purple-400"><?= $users['audio_uploads_today'] ?? 0 ?></span>
            </div>
        <?php else: ?>
            <p class="text-slate-400">Caricamento statistiche utenti...</p>
        <?php endif; ?>
    </div>

    <!-- Performance Metrics -->
    <div class="card">
        <h3>📊 Metriche Prestazioni</h3>
        <?php if (isset($dashboard_stats['performance'])): ?>
            <?php $perf = $dashboard_stats['performance']; ?>
            <div class="metric">
                <span>Tempo Risposta Medio</span>
                <span class="metric-value text-<?= ($perf['avg_response_time_ms'] ?? 0) < 200 ? 'green' : 'yellow' ?>-400">
                    <?= number_format($perf['avg_response_time_ms'] ?? 0, 2) ?>ms
                </span>
            </div>
            <div class="metric">
                <span>Tasso Errori</span>
                <span class="metric-value text-<?= ($perf['error_rate_percent'] ?? 0) < 1 ? 'green' : 'red' ?>-400">
                    <?= number_format($perf['error_rate_percent'] ?? 0, 2) ?>%
                </span>
            </div>
            <div class="metric">
                <span>Richieste/Min</span>
                <span class="metric-value"><?= number_format($perf['requests_per_minute'] ?? 0, 1) ?></span>
            </div>
            <div class="metric">
                <span>Uso Memoria</span>
                <span class="metric-value"><?= number_format($perf['memory_usage_mb'] ?? 0, 2) ?> MB</span>
            </div>
            <div class="metric">
                <span>Picco Memoria</span>
                <span class="metric-value"><?= number_format($perf['peak_memory_mb'] ?? 0, 2) ?> MB</span>
            </div>
        <?php else: ?>
            <p class="text-slate-400">Caricamento metriche prestazioni...</p>
        <?php endif; ?>
    </div>

    <!-- System Resources -->
    <div class="card">
        <h3>💻 Risorse Sistema</h3>
        <?php if (isset($dashboard_stats['resources'])): ?>
            <?php $res = $dashboard_stats['resources']; ?>
            <div class="metric">
                <span>Versione PHP</span>
                <span class="metric-value"><?= htmlspecialchars($res['php_version'] ?? 'sconosciuta') ?></span>
            </div>
            <div class="metric">
                <span>Limite Memoria</span>
                <span class="metric-value"><?= htmlspecialchars($res['memory_limit'] ?? 'sconosciuto') ?></span>
            </div>
            <?php if (isset($res['disk_free_gb'])): ?>
            <div class="metric">
                <span>Spazio Disco Libero</span>
                <span class="metric-value text-<?= $res['disk_free_gb'] < 10 ? 'red' : 'green' ?>-400">
                    <?= number_format($res['disk_free_gb'], 2) ?> GB
                </span>
            </div>
            <?php endif; ?>
            <?php if (isset($res['load_average'])): ?>
            <div class="metric">
                <span>Carico Medio (1m)</span>
                <span class="metric-value"><?= number_format($res['load_average']['1min'] ?? 0, 2) ?></span>
            </div>
            <div class="metric">
                <span>Carico Medio (5m)</span>
                <span class="metric-value"><?= number_format($res['load_average']['5min'] ?? 0, 2) ?></span>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-slate-400">Caricamento risorse sistema...</p>
        <?php endif; ?>
    </div>
</div>

<!-- HISTORICAL PERFORMANCE CHARTS -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Response Time Chart -->
    <div class="card">
        <h3>📈 Tendenza Tempo Risposta (24h)</h3>
        <canvas id="responseTimeChart" style="max-height: 300px;"></canvas>
    </div>

    <!-- Request Rate Chart -->
    <div class="card">
        <h3>📊 Tasso Richieste (24h)</h3>
        <canvas id="requestRateChart" style="max-height: 300px;"></canvas>
    </div>

    <!-- ENTERPRISE GALAXY: Unified Error Rate Chart (Total Errors) -->
    <div class="card">
        <h3>⚠️ Tasso Errori Totale (24h)</h3>
        <canvas id="errorRateChart" style="max-height: 300px;"></canvas>
    </div>

    <!-- User Registrations Chart -->
    <div class="card">
        <h3>👥 Registrazioni Utenti (7gg)</h3>
        <canvas id="registrationsChart" style="max-height: 300px;"></canvas>
    </div>
</div>

<!-- ENTERPRISE GALAXY: Error Type Breakdown Charts -->
<div class="card mb-8">
    <h3 class="mb-6">🔍 Analisi Errori - Suddivisione per Tipo (24h)</h3>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- HTTP Errors Chart (4xx/5xx) -->
        <div>
            <h4 class="text-sm font-semibold mb-3 text-slate-300">🌐 Errori HTTP (Client/Server)</h4>
            <canvas id="httpErrorsChart" style="max-height: 250px;"></canvas>
        </div>

        <!-- JS Errors Chart (Frontend) -->
        <div>
            <h4 class="text-sm font-semibold mb-3 text-slate-300">💻 Errori JavaScript (Frontend)</h4>
            <canvas id="jsErrorsChart" style="max-height: 250px;"></canvas>
        </div>

        <!-- App Errors Chart (PHP Exceptions/Fatal) -->
        <div>
            <h4 class="text-sm font-semibold mb-3 text-slate-300">⚙️ Errori Applicazione (PHP)</h4>
            <canvas id="appErrorsChart" style="max-height: 250px;"></canvas>
        </div>
    </div>
</div>

<!-- DATABASE TABLE STATS -->
<?php if (isset($dashboard_stats['database']['table_stats'])): ?>
<div class="card mb-8">
    <h3>📊 Tabelle Database Principali per Dimensione</h3>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-slate-700">
                    <th class="text-left py-3 px-4 text-slate-300">Nome Tabella</th>
                    <th class="text-right py-3 px-4 text-slate-300">Righe</th>
                    <th class="text-right py-3 px-4 text-slate-300">Dimensione (MB)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($dashboard_stats['database']['table_stats'])): ?>
                    <?php foreach ($dashboard_stats['database']['table_stats'] as $table): ?>
                    <tr class="border-b border-slate-800 hover:bg-slate-800/50 transition-colors">
                        <td class="py-3 px-4 font-mono text-sm"><?= htmlspecialchars($table['table_name'] ?? $table['TABLE_NAME'] ?? 'N/A') ?></td>
                        <td class="py-3 px-4 text-right text-slate-300"><?= number_format($table['table_rows'] ?? $table['TABLE_ROWS'] ?? 0) ?></td>
                        <td class="py-3 px-4 text-right text-slate-300"><?= number_format($table['size_mb'] ?? $table['SIZE_MB'] ?? 0, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="py-4 text-center text-slate-400">Nessuna statistica tabella disponibile</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ENTERPRISE GALAXY: CSS Animations -->
<style nonce="<?= csp_nonce() ?>">
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<!-- Chart.js Integration - Self-hosted (Enterprise Galaxy: Auto-updates from Vite manifest) -->
<script src="<?= asset('charts.js') ?>"></script>
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Historical data from PHP
    const historical24h = <?= json_encode($historical_24h ?? []) ?>;
    const historical7d = <?= json_encode($historical_7d ?? []) ?>;
    const userActivityTrend = <?= json_encode($dashboard_stats['users']['activity_trend'] ?? []) ?>;

    // Response Time Chart
    const responseTimeCtx = document.getElementById('responseTimeChart').getContext('2d');
    new Chart(responseTimeCtx, {
        type: 'line',
        data: {
            labels: historical24h.map(d => d.time_slot),
            datasets: [{
                label: 'Tempo Risposta Medio (ms)',
                data: historical24h.map(d => d.avg_response_time),
                borderColor: '#8b5cf6', // purple-500
                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Millisecondi' }
                }
            }
        }
    });

    // Request Rate Chart
    const requestRateCtx = document.getElementById('requestRateChart').getContext('2d');
    new Chart(requestRateCtx, {
        type: 'bar',
        data: {
            labels: historical24h.map(d => d.time_slot),
            datasets: [{
                label: 'Richieste',
                data: historical24h.map(d => d.requests),
                backgroundColor: '#3b82f6', // blue-500
                borderColor: '#2563eb',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Richieste' }
                }
            }
        }
    });

    // ENTERPRISE GALAXY: Total Error Rate Chart (HTTP + JS + App)
    const errorRateCtx = document.getElementById('errorRateChart').getContext('2d');
    new Chart(errorRateCtx, {
        type: 'line',
        data: {
            labels: historical24h.map(d => d.time_slot),
            datasets: [{
                label: 'Errori Totali',
                data: historical24h.map(d => d.errors || 0),
                borderColor: '#ef4444', // red-500
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Conteggio Errori Totale' }
                }
            }
        }
    });

    // ENTERPRISE GALAXY: HTTP Errors Chart (4xx vs 5xx breakdown)
    const httpErrorsCtx = document.getElementById('httpErrorsChart').getContext('2d');
    new Chart(httpErrorsCtx, {
        type: 'bar',
        data: {
            labels: historical24h.map(d => d.time_slot),
            datasets: [
                {
                    label: '4xx (Client)',
                    data: historical24h.map(d => d.client_errors_4xx || 0),
                    backgroundColor: '#f59e0b', // amber-500
                    borderColor: '#d97706',
                    borderWidth: 1
                },
                {
                    label: '5xx (Server)',
                    data: historical24h.map(d => d.server_errors_5xx || 0),
                    backgroundColor: '#ef4444', // red-500
                    borderColor: '#dc2626',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { color: '#cbd5e1', font: { size: 10 } }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { display: false },
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { color: '#94a3b8', font: { size: 10 } },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

    // ENTERPRISE GALAXY: JS Errors Chart (Frontend errors)
    const jsErrorsCtx = document.getElementById('jsErrorsChart').getContext('2d');
    new Chart(jsErrorsCtx, {
        type: 'line',
        data: {
            labels: historical24h.map(d => d.time_slot),
            datasets: [{
                label: 'JS Errors',
                data: historical24h.map(d => d.js_errors || 0),
                borderColor: '#f59e0b', // amber-500
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                fill: true,
                tension: 0.4,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    ticks: { display: false },
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: '#94a3b8', font: { size: 10 } },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

    // ENTERPRISE GALAXY: App Errors Chart (PHP Exceptions/Fatal)
    const appErrorsCtx = document.getElementById('appErrorsChart').getContext('2d');
    new Chart(appErrorsCtx, {
        type: 'bar',
        data: {
            labels: historical24h.map(d => d.time_slot),
            datasets: [
                {
                    label: 'Fatal',
                    data: historical24h.map(d => d.fatal_errors || 0),
                    backgroundColor: '#dc2626', // red-600
                    borderWidth: 0
                },
                {
                    label: 'Exception',
                    data: historical24h.map(d => d.exceptions || 0),
                    backgroundColor: '#ef4444', // red-500
                    borderWidth: 0
                },
                {
                    label: 'Critical',
                    data: historical24h.map(d => d.critical_errors || 0),
                    backgroundColor: '#f97316', // orange-500
                    borderWidth: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { color: '#cbd5e1', font: { size: 10 } }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { display: false },
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { color: '#94a3b8', font: { size: 10 } },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

    // User Registrations Chart
    const registrationsCtx = document.getElementById('registrationsChart').getContext('2d');
    new Chart(registrationsCtx, {
        type: 'bar',
        data: {
            labels: userActivityTrend.map(d => d.date),
            datasets: [{
                label: 'Nuove Registrazioni',
                data: userActivityTrend.map(d => d.registrations),
                backgroundColor: '#10b981', // green-500
                borderColor: '#059669',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    title: { display: true, text: 'Nuovi Utenti' }
                }
            }
        }
    });

    // ENTERPRISE GALAXY: Refresh button with GRANULAR cache invalidation
    document.getElementById('refresh-stats-btn').addEventListener('click', async function() {
        const btn = this;
        const icon = btn.querySelector('svg');

        // Add spinning animation
        icon.style.animation = 'spin 1s linear infinite';
        btn.disabled = true;
        btn.style.opacity = '0.7';

        // ENTERPRISE: Extract admin URL for secure routing
        const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
        const adminHash = pathMatch ? pathMatch[1] : '';

        if (!adminHash) {
            console.error('❌ Admin URL hash not found');
            window.location.reload();
            return;
        }

        try {
            // STEP 1: Invalidate ONLY stats cache (GRANULAR - not entire site!)
            const response = await fetch(`/admin_${adminHash}/api/stats/invalidate-cache`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            const result = await response.json();

            if (result.success) {
                console.info('✅ Stats cache invalidated:', result.invalidated_keys, 'keys');
            } else {
                console.warn('⚠️ Cache invalidation failed:', result.error);
            }
        } catch (error) {
            console.error('❌ Cache invalidation request failed:', error);
        }

        // STEP 2: Reload page to fetch fresh data
        const timestamp = new Date().getTime();
        window.location.href = window.location.pathname + '?refresh=' + timestamp;
    });

    // ENTERPRISE GALAXY: Timeframe selector with dynamic chart updates
    document.getElementById('timeframe-selector').addEventListener('change', function() {
        const timeframe = this.value;

        // ENTERPRISE TIPS: Use simple page reload with query parameter
        // This avoids 404 errors and ensures data is properly loaded from PHP backend
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('timeframe', timeframe);
        currentUrl.searchParams.set('ts', Date.now()); // Cache-busting

        window.location.href = currentUrl.toString();
    });

    // ENTERPRISE: Export selector (native select - always works!)
    document.getElementById('export-selector').addEventListener('change', function() {
        const format = this.value;
        if (format) {
            exportMetrics(format);
            // Reset selector
            this.value = '';
        }
    });
});

// ENTERPRISE: Export metrics to different formats
function exportMetrics(format) {
    const timeframe = document.getElementById('timeframe-selector')?.value || '24h';

    // ENTERPRISE TIPS: Extract admin hash from URL for secure routing
    const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
    const adminHash = pathMatch ? pathMatch[1] : '';

    if (!adminHash) {
        console.error('❌ Admin URL hash not found');
        alert('⚠️ Error: Admin URL not detected. Please refresh the page.');
        return;
    }

    // Build secure export URL
    const protocol = window.location.protocol;
    const host = window.location.host;
    const exportUrl = `${protocol}//${host}/admin_${adminHash}/api/metrics/export?format=${format}&timeframe=${timeframe}`;

    console.debug('📥 Exporting metrics:', { format, timeframe, url: exportUrl });

    // ENTERPRISE TIPS: Use fetch to detect errors before triggering download
    fetch(exportUrl, {
        method: 'GET',
        headers: {
            'Cache-Control': 'no-cache'
        },
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        // Success - trigger download
        window.location.href = exportUrl;
        console.info('✅ Export initiated successfully');
    })
    .catch(error => {
        console.error('❌ Export failed:', error);
        alert(`⚠️ Export failed: ${error.message}\n\nThe export endpoint may not be implemented yet.`);
    });
}
</script>
