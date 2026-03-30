<!-- ENTERPRISE EMAIL METRICS & ANALYTICS VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-envelope-open-text mr-3"></i>
    Metriche & Analytics Email
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(59, 130, 246, 0.2); color: #3b82f6; font-weight: 600;">
        <i class="fas fa-chart-line mr-1"></i>ANALYTICS IN TEMPO REALE
    </span>
</h2>

<?php
$dashboard = $dashboard ?? [];
$verification = $dashboard['verification'] ?? [];
$password_reset = $dashboard['password_reset'] ?? [];
$hourly = $dashboard['hourly'] ?? [];
$daily = $dashboard['daily'] ?? [];
$idempotency = $dashboard['idempotency'] ?? [];
$total_idempotency = $dashboard['total_idempotency'] ?? 0;
?>

<!-- Statistiche Riepilogative (24h) -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <!-- Email Verification Stats -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;">
            <?= number_format($verification['sent_count'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-paper-plane mr-2"></i>Verification Inviate (24h)
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;">
            <?= number_format($verification['verified_count'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-check-circle mr-2"></i>Verificate (24h)
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #ef4444;">
        <span class="stat-value" style="color: #ef4444;">
            <?= number_format($verification['failed_count'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>Fallite (24h)
        </div>
    </div>

    <!-- Password Reset Stats -->
    <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
        <span class="stat-value" style="color: #8b5cf6;">
            <?= number_format($password_reset['sent_count'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-key mr-2"></i>Password Reset Inviate (24h)
        </div>
    </div>

    <!-- Performance Metrics -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;">
            <?= number_format($verification['avg_processing_time'] ?? 0, 2) ?>ms
        </span>
        <div class="stat-label">
            <i class="fas fa-tachometer-alt mr-2"></i>Tempo Medio Elaborazione
        </div>
    </div>

    <!-- Idempotency Protection -->
    <div class="stat-card" style="border-left: 3px solid #06b6d4;">
        <span class="stat-value" style="color: #06b6d4;">
            <?= number_format($total_idempotency) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-shield-alt mr-2"></i>Prevenzione Duplicati (24h)
        </div>
    </div>
</div>

<!-- Navigazione Tab -->
<div class="tabs-container mt-8">
    <div class="tabs-header">
        <button class="tab-btn active" data-tab="verification">
            <i class="fas fa-envelope-circle-check mr-2"></i>Email Verification
        </button>
        <button class="tab-btn" data-tab="password-reset">
            <i class="fas fa-key mr-2"></i>Password Reset
        </button>
        <button class="tab-btn" data-tab="hourly">
            <i class="fas fa-clock mr-2"></i>Metriche Orarie
        </button>
        <button class="tab-btn" data-tab="daily">
            <i class="fas fa-calendar-day mr-2"></i>Metriche Giornaliere
        </button>
        <button class="tab-btn" data-tab="idempotency">
            <i class="fas fa-shield-alt mr-2"></i>Log Idempotency
        </button>
        <button class="tab-btn" data-tab="workers">
            <i class="fas fa-server mr-2"></i>Controllo Worker
        </button>
    </div>

    <!-- Tab: Email Verification Metrics -->
    <div id="tab-verification" class="tab-content active">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-envelope-circle-check mr-2"></i>Metriche Email Verification
            </h3>
            <div class="flex gap-2">
                <button onclick="exportMetrics('verification')" class="btn btn-primary btn-sm">
                    <i class="fas fa-download mr-2"></i>Esporta CSV
                </button>
                <button onclick="refreshVerificationMetrics()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt mr-2"></i>Aggiorna
                </button>
            </div>
        </div>

        <div class="table-controls mb-4">
            <label>
                <span class="mr-2">Limite:</span>
                <select id="verification-limit" onchange="refreshVerificationMetrics()">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                </select>
            </label>
        </div>

        <div id="verification-loading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
            <p class="mt-2">Caricamento metriche verification...</p>
        </div>

        <div id="verification-table-container" style="display: none;">
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User ID</th>
                            <th>Stato</th>
                            <th>Tempo Coda</th>
                            <th>Tempo Elaborazione</th>
                            <th>Worker ID</th>
                            <th>Tentativi</th>
                            <th>Redis L1</th>
                            <th>Carico Server</th>
                            <th>Creato il</th>
                        </tr>
                    </thead>
                    <tbody id="verification-tbody"></tbody>
                </table>
            </div>

            <div id="verification-pagination" class="pagination mt-4"></div>
        </div>
    </div>

    <!-- Tab: Password Reset Metrics -->
    <div id="tab-password-reset" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-key mr-2"></i>Metriche Password Reset
            </h3>
            <div class="flex gap-2">
                <button onclick="exportMetrics('password_reset')" class="btn btn-primary btn-sm">
                    <i class="fas fa-download mr-2"></i>Esporta CSV
                </button>
                <button onclick="refreshPasswordResetMetrics()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt mr-2"></i>Aggiorna
                </button>
            </div>
        </div>

        <div class="table-controls mb-4">
            <label>
                <span class="mr-2">Limite:</span>
                <select id="password-reset-limit" onchange="refreshPasswordResetMetrics()">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                </select>
            </label>
        </div>

        <div id="password-reset-loading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-purple-500"></i>
            <p class="mt-2">Caricamento metriche password reset...</p>
        </div>

        <div id="password-reset-table-container" style="display: none;">
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Azione</th>
                            <th>Indirizzo IP</th>
                            <th>Tempo Coda</th>
                            <th>Tempo Elaborazione</th>
                            <th>Worker ID</th>
                            <th>Tentativi</th>
                            <th>Redis L1</th>
                            <th>Creato il</th>
                        </tr>
                    </thead>
                    <tbody id="password-reset-tbody"></tbody>
                </table>
            </div>

            <div id="password-reset-pagination" class="pagination mt-4"></div>
        </div>
    </div>

    <!-- Tab: Hourly Metrics -->
    <div id="tab-hourly" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-clock mr-2"></i>Metriche Aggregate Orarie
            </h3>
            <div class="flex gap-2">
                <label class="mr-4">
                    <span class="mr-2">Ore:</span>
                    <select id="hourly-hours" onchange="refreshHourlyMetrics()">
                        <option value="6">6 ore</option>
                        <option value="12">12 ore</option>
                        <option value="24" selected>24 ore</option>
                        <option value="48">48 ore</option>
                        <option value="168">7 giorni</option>
                    </select>
                </label>
                <button onclick="exportMetrics('hourly')" class="btn btn-primary btn-sm">
                    <i class="fas fa-download mr-2"></i>Esporta CSV
                </button>
                <button onclick="refreshHourlyMetrics()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt mr-2"></i>Aggiorna
                </button>
            </div>
        </div>

        <div id="hourly-loading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-yellow-500"></i>
            <p class="mt-2">Caricamento metriche orarie...</p>
        </div>

        <div id="hourly-table-container" style="display: none;">
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>Ora</th>
                            <th>Tipo Email</th>
                            <th>Azione</th>
                            <th>Conteggio</th>
                            <th>Dimensione Totale (KB)</th>
                            <th>Tempo Medio Elaborazione (ms)</th>
                        </tr>
                    </thead>
                    <tbody id="hourly-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab: Daily Metrics -->
    <div id="tab-daily" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-calendar-day mr-2"></i>Metriche Aggregate Giornaliere
            </h3>
            <div class="flex gap-2">
                <label class="mr-4">
                    <span class="mr-2">Giorni:</span>
                    <select id="daily-days" onchange="refreshDailyMetrics()">
                        <option value="7">7 giorni</option>
                        <option value="14">14 giorni</option>
                        <option value="30" selected>30 giorni</option>
                        <option value="60">60 giorni</option>
                        <option value="90">90 giorni</option>
                    </select>
                </label>
                <button onclick="exportMetrics('daily')" class="btn btn-primary btn-sm">
                    <i class="fas fa-download mr-2"></i>Esporta CSV
                </button>
                <button onclick="refreshDailyMetrics()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt mr-2"></i>Aggiorna
                </button>
            </div>
        </div>

        <div id="daily-loading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-green-500"></i>
            <p class="mt-2">Caricamento metriche giornaliere...</p>
        </div>

        <div id="daily-table-container" style="display: none;">
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>Giorno</th>
                            <th>Tipo Email</th>
                            <th>Azione</th>
                            <th>Conteggio</th>
                            <th>Dimensione Totale (KB)</th>
                            <th>Tempo Medio Elaborazione (ms)</th>
                        </tr>
                    </thead>
                    <tbody id="daily-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab: Idempotency Log -->
    <div id="tab-idempotency" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-shield-alt mr-2"></i>Log Idempotency Email (Prevenzione Duplicati)
            </h3>
            <div class="flex gap-2">
                <button onclick="exportMetrics('idempotency')" class="btn btn-primary btn-sm">
                    <i class="fas fa-download mr-2"></i>Esporta CSV
                </button>
                <button onclick="refreshIdempotencyLog()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt mr-2"></i>Aggiorna
                </button>
            </div>
        </div>

        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle mr-2"></i>
            <strong>Scopo Log Idempotency:</strong> Questo log traccia tutti i tentativi di invio email per prevenire l'invio di email duplicate allo stesso utente.
            Ogni email riceve una chiave idempotency univoca basata su UUID utente, hash email e tipo di email.
            Se la stessa email viene tentata di essere inviata nuovamente (per retry, problemi di rete o bug),
            il sistema rileverà il duplicato usando questo log e preverrà l'invio duplicato.
        </div>

        <div class="table-controls mb-4">
            <label>
                <span class="mr-2">Limite:</span>
                <select id="idempotency-limit" onchange="refreshIdempotencyLog()">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                </select>
            </label>
        </div>

        <div id="idempotency-loading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-cyan-500"></i>
            <p class="mt-2">Caricamento log idempotency...</p>
        </div>

        <div id="idempotency-table-container" style="display: none;">
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Chiave Idempotency</th>
                            <th>Message ID</th>
                            <th>UUID Utente</th>
                            <th>Hash Email</th>
                            <th>Tipo Email</th>
                            <th>Worker ID</th>
                            <th>Creato il</th>
                        </tr>
                    </thead>
                    <tbody id="idempotency-tbody"></tbody>
                </table>
            </div>

            <div id="idempotency-pagination" class="pagination mt-4"></div>
        </div>
    </div>

    <!-- Tab: Worker Control (Systemd) -->
    <div id="tab-workers" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-server mr-2"></i>Controllo Worker Email (Docker Auto-Restart)
            </h3>
        </div>

        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle mr-2"></i>
            <strong>Enterprise Auto-Restart:</strong> I worker email girano in container Docker dedicato con riavvio automatico in caso di errore.
            I worker si riavviano ogni 4 ore per prevenire memory leak. Docker garantisce zero downtime con recupero automatico.
        </div>
            <div class="stats-grid mb-6" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                <div class="stat-card" style="border-left: 3px solid #10b981;">
                    <span class="stat-value" id="worker-status-indicator" style="color: #10b981;">●</span>
                    <div class="stat-label">
                        <i class="fas fa-heartbeat mr-2"></i><span id="worker-status-text">Attivo</span>
                    </div>
                </div>

                <div class="stat-card" style="border-left: 3px solid #3b82f6;">
                    <span class="stat-value" id="worker-count" style="color: #3b82f6;">0</span>
                    <div class="stat-label">
                        <i class="fas fa-users mr-2"></i>Worker Attivi
                    </div>
                </div>

                <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
                    <span class="stat-value" id="worker-uptime" style="color: #8b5cf6;">-</span>
                    <div class="stat-label">
                        <i class="fas fa-clock mr-2"></i>Uptime
                    </div>
                </div>

                <div class="stat-card" style="border-left: 3px solid #f59e0b;">
                    <span class="stat-value" id="worker-memory" style="color: #f59e0b;">-</span>
                    <div class="stat-label">
                        <i class="fas fa-memory mr-2"></i>Memoria
                    </div>
                </div>

                <div class="stat-card" style="border-left: 3px solid #06b6d4;">
                    <span class="stat-value" id="worker-cpu" style="color: #06b6d4;">-</span>
                    <div class="stat-label">
                        <i class="fas fa-microchip mr-2"></i>CPU
                    </div>
                </div>

                <div class="stat-card" id="autostart-card" style="border-left: 3px solid #10b981;">
                    <span class="stat-value" id="autostart-status" style="color: #10b981;">ON</span>
                    <div class="stat-label">
                        <i class="fas fa-power-off mr-2"></i>Avvio Automatico
                    </div>
                </div>
            </div>

            <!-- Quick Actions (Enterprise Galaxy Complete) -->
            <div class="card mb-6">
                <h4 class="text-lg font-bold mb-4">
                    <i class="fas fa-bolt mr-2"></i>🔧 Azioni Rapide
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <button style="background: linear-gradient(135deg, #166534 0%, #14532d 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="startWorkers()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Avvia Worker
                    </button>
                    <button style="background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="stopWorkers()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                        Ferma Worker
                    </button>
                    <button style="background: linear-gradient(135deg, #7f1d1d 0%, #450a0a 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="stopWorkersClean()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Ferma + Pulisci
                    </button>
                    <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="monitorWorkers()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Salute Worker
                    </button>
                    <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="refreshConnectionPool()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                        Aggiorna Pool DB
                    </button>
                    <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="refreshStats()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Aggiorna Statistiche
                    </button>
                    <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="enableAutoStart()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Abilita Auto-Start
                    </button>
                    <button style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="disableAutoStart()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        Disabilita Auto-Start
                    </button>
                </div>
            </div>

            <!-- Live Monitoring (Enterprise Galaxy Complete) -->
            <div class="card mb-6">
                <h4 class="text-lg font-bold mb-4">
                    <i class="fas fa-chart-line mr-2"></i>📊 Monitoraggio Live
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-4">
                    <button style="background: linear-gradient(135deg, #1e3a8a 0%, #172554 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="clearMonitoringOutput()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Pulisci
                    </button>
                    <button style="background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="runPerformanceTest()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Test Performance
                    </button>
                    <a href="logs" style="background: linear-gradient(135deg, #0f766e 0%, #134e4a 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2 no-underline">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Visualizza Log
                    </a>
                </div>

                <div id="monitoring-output" class="bg-slate-900 text-slate-100 rounded-lg p-4 font-mono text-sm overflow-auto max-h-96 border border-slate-700">
                    <p class="text-slate-400">Clicca un pulsante di monitoraggio per vedere i dati live...</p>
                </div>
            </div>

            <!-- Recent Logs -->
            <div class="card">
                <h4 class="text-lg font-bold mb-4">
                    <i class="fas fa-file-alt mr-2"></i>Log Recenti (Ultime 5 Righe)
                </h4>
                <div id="worker-logs" style="background: #000; padding: 1rem; border-radius: 6px; font-family: monospace; font-size: 12px; color: #0f0; max-height: 300px; overflow-y: auto;">
                    <div class="text-center text-gray-500">Nessun log disponibile</div>
                </div>
            </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
.tabs-container {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    padding: 1.5rem;
}

.tabs-header {
    display: flex;
    gap: 0.5rem;
    border-bottom: 2px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.6);
    cursor: pointer;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
    font-weight: 500;
}

.tab-btn:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.05);
}

.tab-btn.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.table-controls {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.table-controls select, select#hourly-hours, select#daily-days {
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    color: white;
}

/* Fix for label text color */
label {
    color: rgba(255, 255, 255, 0.9);
}

.pagination {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    align-items: center;
}

.pagination button {
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    color: white;
    cursor: pointer;
    transition: all 0.3s;
}

.pagination button:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.2);
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination button.active {
    background: #3b82f6;
    border-color: #3b82f6;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid;
}

.alert-info {
    background: rgba(59, 130, 246, 0.1);
    border-color: #3b82f6;
    color: #93c5fd;
}

/* STICKY TABLE HEADERS */
.table-wrapper {
    max-height: 600px;
    overflow-y: auto;
    overflow-x: auto;
    border-radius: 0.75rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.05);
}

.sticky-header thead {
    position: sticky;
    top: 0;
    z-index: 10;
    background: rgba(30, 30, 30, 0.95);
    backdrop-filter: blur(10px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.sticky-header thead th {
    background: rgba(30, 30, 30, 0.95);
    backdrop-filter: blur(10px);
}

/* Scrollbar styling for table wrapper */
.table-wrapper::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.table-wrapper::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb {
    background: rgba(147, 51, 234, 0.5);
    border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
    background: rgba(147, 51, 234, 0.7);
}
</style>

<script nonce="<?= csp_nonce() ?>">
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;

        // Update button states
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update content visibility
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(`tab-${tabId}`).classList.add('active');

        // Load data for the tab if not already loaded
        loadTabData(tabId);
    });
});

// Load data for specific tab
function loadTabData(tabId) {
    switch(tabId) {
        case 'verification':
            refreshVerificationMetrics();
            break;
        case 'password-reset':
            refreshPasswordResetMetrics();
            break;
        case 'hourly':
            refreshHourlyMetrics();
            break;
        case 'daily':
            refreshDailyMetrics();
            break;
        case 'idempotency':
            refreshIdempotencyLog();
            break;
        case 'workers':
            refreshWorkerStatus();
            break;
    }
}

// Current page trackers
let verificationPage = 1;
let passwordResetPage = 1;
let idempotencyPage = 1;

// Verification Metrics
function refreshVerificationMetrics(page = 1) {
    verificationPage = page;
    const limit = document.getElementById('verification-limit').value;

    document.getElementById('verification-loading').style.display = 'block';
    document.getElementById('verification-table-container').style.display = 'none';

    fetch(`api/email-metrics/verification?limit=${limit}&page=${page}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderVerificationTable(data.metrics);
                renderPagination('verification', data.page, data.total_pages);
                document.getElementById('verification-loading').style.display = 'none';
                document.getElementById('verification-table-container').style.display = 'block';
            }
        })
        .catch(err => console.error('Failed to load verification metrics:', err));
}

function renderVerificationTable(metrics) {
    const tbody = document.getElementById('verification-tbody');
    tbody.innerHTML = metrics.map(m => `
        <tr>
            <td>${m.id}</td>
            <td>${m.user_id}</td>
            <td><span class="badge badge-${getStatusColor(m.status)}">${m.status}</span></td>
            <td>${m.queue_time_ms || '-'}ms</td>
            <td>${m.processing_time_ms || '-'}ms</td>
            <td><code class="text-xs">${m.worker_id || '-'}</code></td>
            <td>${m.retry_count || 0}</td>
            <td><span class="badge badge-${m.redis_l1_status === 'active' ? 'success' : 'warning'}">${m.redis_l1_status || '-'}</span></td>
            <td>${m.server_load_avg || '-'}</td>
            <td>${formatDateTime(m.created_at)}</td>
        </tr>
    `).join('');
}

// Password Reset Metrics
function refreshPasswordResetMetrics(page = 1) {
    passwordResetPage = page;
    const limit = document.getElementById('password-reset-limit').value;

    document.getElementById('password-reset-loading').style.display = 'block';
    document.getElementById('password-reset-table-container').style.display = 'none';

    fetch(`api/email-metrics/password-reset?limit=${limit}&page=${page}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderPasswordResetTable(data.metrics);
                renderPagination('password-reset', data.page, data.total_pages);
                document.getElementById('password-reset-loading').style.display = 'none';
                document.getElementById('password-reset-table-container').style.display = 'block';
            }
        })
        .catch(err => console.error('Failed to load password reset metrics:', err));
}

function renderPasswordResetTable(metrics) {
    const tbody = document.getElementById('password-reset-tbody');
    tbody.innerHTML = metrics.map(m => `
        <tr>
            <td>${m.id}</td>
            <td>${m.email}</td>
            <td><span class="badge badge-${getStatusColor(m.action)}">${m.action}</span></td>
            <td>${m.ip_address || '-'}</td>
            <td>${m.queue_time_ms || '-'}ms</td>
            <td>${m.processing_time_ms || '-'}ms</td>
            <td><code class="text-xs">${m.worker_id || '-'}</code></td>
            <td>${m.retry_count || 0}</td>
            <td><span class="badge badge-${m.redis_l1_status === 'active' ? 'success' : 'warning'}">${m.redis_l1_status || '-'}</span></td>
            <td>${formatDateTime(m.created_at)}</td>
        </tr>
    `).join('');
}

// Hourly Metrics
function refreshHourlyMetrics() {
    const hours = document.getElementById('hourly-hours').value;

    document.getElementById('hourly-loading').style.display = 'block';
    document.getElementById('hourly-table-container').style.display = 'none';

    fetch(`api/email-metrics/hourly?hours=${hours}`)
        .then(res => res.json())
        .then(data => {
            console.debug('Hourly metrics response:', data);
            if (data.success) {
                renderHourlyTable(data.metrics);
                document.getElementById('hourly-loading').style.display = 'none';
                document.getElementById('hourly-table-container').style.display = 'block';
            } else {
                console.error('Hourly metrics error:', data);
                document.getElementById('hourly-loading').innerHTML = '<p class="text-danger">Error loading hourly metrics</p>';
            }
        })
        .catch(err => {
            console.error('Failed to load hourly metrics:', err);
            document.getElementById('hourly-loading').innerHTML = '<p class="text-danger">Network error loading hourly metrics</p>';
        });
}

function renderHourlyTable(metrics) {
    const tbody = document.getElementById('hourly-tbody');
    if (!metrics || metrics.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Nessuna metrica oraria trovata</td></tr>';
        return;
    }
    tbody.innerHTML = metrics.map(m => `
        <tr>
            <td>${formatDateTime(m.hour)}</td>
            <td><span class="badge badge-info">${m.email_type}</span></td>
            <td><span class="badge badge-${getActionColor(m.action)}">${m.action}</span></td>
            <td>${m.count}</td>
            <td>${(m.total_size / 1024).toFixed(2)}</td>
            <td>${parseFloat(m.avg_processing_time).toFixed(2)}</td>
        </tr>
    `).join('');
}

// Daily Metrics
function refreshDailyMetrics() {
    const days = document.getElementById('daily-days').value;

    document.getElementById('daily-loading').style.display = 'block';
    document.getElementById('daily-table-container').style.display = 'none';

    fetch(`api/email-metrics/daily?days=${days}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderDailyTable(data.metrics);
                document.getElementById('daily-loading').style.display = 'none';
                document.getElementById('daily-table-container').style.display = 'block';
            }
        })
        .catch(err => console.error('Failed to load daily metrics:', err));
}

function renderDailyTable(metrics) {
    const tbody = document.getElementById('daily-tbody');
    if (!metrics || metrics.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Nessuna metrica giornaliera trovata</td></tr>';
        return;
    }
    tbody.innerHTML = metrics.map(m => `
        <tr>
            <td>${m.day}</td>
            <td><span class="badge badge-info">${m.email_type}</span></td>
            <td><span class="badge badge-${getActionColor(m.action)}">${m.action}</span></td>
            <td>${m.count}</td>
            <td>${(m.total_size / 1024).toFixed(2)}</td>
            <td>${parseFloat(m.avg_processing_time).toFixed(2)}</td>
        </tr>
    `).join('');
}

// Idempotency Log
function refreshIdempotencyLog(page = 1) {
    idempotencyPage = page;
    const limit = document.getElementById('idempotency-limit').value;

    document.getElementById('idempotency-loading').style.display = 'block';
    document.getElementById('idempotency-table-container').style.display = 'none';

    fetch(`api/email-metrics/idempotency?limit=${limit}&page=${page}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderIdempotencyTable(data.logs);
                renderPagination('idempotency', data.page, data.total_pages);
                document.getElementById('idempotency-loading').style.display = 'none';
                document.getElementById('idempotency-table-container').style.display = 'block';
            }
        })
        .catch(err => console.error('Failed to load idempotency log:', err));
}

function renderIdempotencyTable(logs) {
    const tbody = document.getElementById('idempotency-tbody');
    tbody.innerHTML = logs.map(log => `
        <tr>
            <td>${log.id}</td>
            <td><code class="text-xs">${log.idempotency_key.substring(0, 16)}...</code></td>
            <td><code class="text-xs">${log.message_id}</code></td>
            <td><code class="text-xs">${log.user_uuid}</code></td>
            <td><code class="text-xs">${log.email_hash.substring(0, 16)}...</code></td>
            <td><span class="badge badge-info">${log.email_type}</span></td>
            <td><code class="text-xs">${log.worker_id || '-'}</code></td>
            <td>${formatDateTime(log.created_at)}</td>
        </tr>
    `).join('');
}

// Pagination renderer
function renderPagination(type, currentPage, totalPages) {
    const container = document.getElementById(`${type}-pagination`);
    const pages = [];

    // Previous button
    pages.push(`<button onclick="refresh${capitalize(type.replace('-', ''))}Metrics(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>← Precedente</button>`);

    // Page numbers
    for (let i = 1; i <= Math.min(totalPages, 10); i++) {
        pages.push(`<button onclick="refresh${capitalize(type.replace('-', ''))}Metrics(${i})" class="${i === currentPage ? 'active' : ''}">${i}</button>`);
    }

    if (totalPages > 10) {
        pages.push(`<span>... ${totalPages}</span>`);
    }

    // Next button
    pages.push(`<button onclick="refresh${capitalize(type.replace('-', ''))}Metrics(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Successiva →</button>`);

    container.innerHTML = pages.join('');
}

// Export metrics
function exportMetrics(type) {
    const days = type === 'hourly' ? document.getElementById('hourly-hours').value :
                 type === 'daily' ? document.getElementById('daily-days').value : 30;
    window.location.href = `api/email-metrics/export?type=${type}&days=${days}`;
}

// Helper functions
function getStatusColor(status) {
    if (status.includes('success') || status.includes('verified')) return 'success';
    if (status.includes('failed') || status.includes('error')) return 'danger';
    if (status.includes('queued')) return 'warning';
    return 'secondary';
}

function getActionColor(action) {
    if (action === 'sent') return 'success';
    if (action === 'failed') return 'danger';
    if (action === 'bounced') return 'warning';
    if (action === 'opened') return 'info';
    if (action === 'clicked') return 'primary';
    return 'secondary';
}

function formatDateTime(datetime) {
    return new Date(datetime).toLocaleString('it-IT', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function capitalize(str) {
    return str.split('-').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join('');
}

// ============================================================================
// WORKER CONTROL FUNCTIONS (Systemd)
// ============================================================================

function refreshWorkerStatus() {
    const loadingEl = document.getElementById('worker-status-loading');
    const dashboardEl = document.getElementById('worker-status-dashboard');

    if (loadingEl) loadingEl.style.display = 'block';
    if (dashboardEl) dashboardEl.style.display = 'none';

    // ENTERPRISE: Build admin-relative API URL
    const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
    fetch(`${adminBase}/api/email-workers/status`)
        .then(res => res.json())
        .then(data => {
            renderWorkerStatus(data);
            if (loadingEl) loadingEl.style.display = 'none';
            if (dashboardEl) dashboardEl.style.display = 'block';
        })
        .catch(err => {
            console.error('Failed to load worker status:', err);
            alert('❌ Impossibile caricare lo stato dei worker');
            if (loadingEl) loadingEl.style.display = 'none';
        });
}

function renderWorkerStatus(status) {
    // Status indicator
    const isRunning = status.active && status.status === 'running';
    const indicator = document.getElementById('worker-status-indicator');
    const statusText = document.getElementById('worker-status-text');

    if (indicator && statusText) {
        if (isRunning) {
            indicator.style.color = '#10b981';
            indicator.textContent = '●';
            statusText.textContent = 'Attivo';
        } else {
            indicator.style.color = '#ef4444';
            indicator.textContent = '●';
            statusText.textContent = 'Fermato';
        }
    }

    // Worker count
    const workerCount = document.getElementById('worker-count');
    if (workerCount) workerCount.textContent = status.workers || 0;

    // Uptime
    const workerUptime = document.getElementById('worker-uptime');
    if (workerUptime) workerUptime.textContent = status.uptime || '-';

    // Memory & CPU
    const workerMemory = document.getElementById('worker-memory');
    if (workerMemory) workerMemory.textContent = status.memory || '-';

    const workerCpu = document.getElementById('worker-cpu');
    if (workerCpu) workerCpu.textContent = status.cpu || '-';

    // Auto-start
    const autostartCard = document.getElementById('autostart-card');
    const autostartStatus = document.getElementById('autostart-status');
    if (autostartCard && autostartStatus) {
        if (status.enabled) {
            autostartCard.style.borderLeftColor = '#10b981';
            autostartStatus.style.color = '#10b981';
            autostartStatus.textContent = 'ON';
        } else {
            autostartCard.style.borderLeftColor = '#ef4444';
            autostartStatus.style.color = '#ef4444';
            autostartStatus.textContent = 'OFF';
        }
    }

    // Recent logs
    const logsDiv = document.getElementById('worker-logs');
    if (logsDiv) {
        if (status.recent_logs && status.recent_logs.length > 0) {
            logsDiv.innerHTML = status.recent_logs.map(log => `<div>${escapeHtml(log)}</div>`).join('');
        } else {
            logsDiv.innerHTML = '<div class="text-center text-gray-500">Nessun log disponibile</div>';
        }
    }

    // Update buttons state
    updateWorkerButtons(isRunning, status.enabled);
}

function updateWorkerButtons(isRunning, isEnabled) {
    // ENTERPRISE: Null checks - pulsanti potrebbero non esistere in questa vista
    const btnStart = document.getElementById('btn-start');
    const btnStop = document.getElementById('btn-stop');
    const btnRestart = document.getElementById('btn-restart');
    const btnEnable = document.getElementById('btn-enable');
    const btnDisable = document.getElementById('btn-disable');

    if (btnStart) btnStart.disabled = isRunning;
    if (btnStop) btnStop.disabled = !isRunning;
    if (btnRestart) btnRestart.disabled = !isRunning;
    if (btnEnable) btnEnable.disabled = isEnabled;
    if (btnDisable) btnDisable.disabled = !isEnabled;
}

function startWorkers() {
    if (!confirm('Avviare i worker email?')) return;

    const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
    fetch(`${adminBase}/api/email-workers/start`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('✅ Worker avviati con successo');
                refreshWorkerStatus();
            } else {
                alert('❌ Impossibile avviare i worker: ' + data.message);
            }
        })
        .catch(err => alert('❌ Errore: ' + err.message));
}

function stopWorkers() {
    if (!confirm('⚠️ Fermare i worker email? Questo metterà in pausa l\'elaborazione delle email!')) return;

    const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
    fetch(`${adminBase}/api/email-workers/stop`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('✅ Worker fermati con successo');
                refreshWorkerStatus();
            } else {
                alert('❌ Impossibile fermare i worker: ' + data.message);
            }
        })
        .catch(err => alert('❌ Errore: ' + err.message));
}

function restartWorkers() {
    if (!confirm('Riavviare i worker email?')) return;

    const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
    fetch(`${adminBase}/api/email-workers/restart`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('✅ Worker riavviati con successo');
                refreshWorkerStatus();
            } else {
                alert('❌ Impossibile riavviare i worker: ' + data.message);
            }
        })
        .catch(err => alert('❌ Errore: ' + err.message));
}

function enableAutoStart() {
    if (!confirm('Abilitare l\'avvio automatico all\'avvio del sistema?')) return;

    const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
    fetch(`${adminBase}/api/email-workers/enable`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('✅ Avvio automatico abilitato con successo');
                refreshWorkerStatus();
            } else {
                alert('❌ Impossibile abilitare l\'avvio automatico: ' + data.message);
            }
        })
        .catch(err => alert('❌ Errore: ' + err.message));
}

function disableAutoStart() {
    if (!confirm('⚠️ Disabilitare l\'avvio automatico? I worker non si avvieranno automaticamente dopo il riavvio del server!')) return;

    const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
    fetch(`${adminBase}/api/email-workers/disable`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('✅ Avvio automatico disabilitato con successo');
                refreshWorkerStatus();
            } else {
                alert('❌ Impossibile disabilitare l\'avvio automatico: ' + data.message);
            }
        })
        .catch(err => alert('❌ Errore: ' + err.message));
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================================================
// ADDITIONAL QUICK ACTIONS & LIVE MONITORING FUNCTIONS (Enterprise Galaxy)
// ============================================================================

function refreshStats() {
    location.reload();
}

function refreshConnectionPool() {
    showActionResult('refresh_connection_pool', 'Refresh DB Pool');
}

function stopWorkersClean() {
    showActionResult('stop_workers_clean', 'Stop Workers + Clean');
}

function monitorWorkers() {
    showMonitoringData('monitor_workers', 'Workers Status');
}

function runPerformanceTest() {
    const output = document.getElementById('monitoring-output');
    const timestamp = new Date().toLocaleTimeString();

    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'monitoring-section';
    loadingDiv.innerHTML = `<p class="text-info">[${timestamp}] 🔄 Running Performance Test (4 emails)...</p>`;
    output.appendChild(loadingDiv);
    output.scrollTop = output.scrollHeight;

    fetch('system-action', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=run_performance_test&duration=1&ops_per_second=4'
    })
    .then(response => response.json())
    .then(data => {
        output.removeChild(loadingDiv);

        const resultDiv = document.createElement('div');
        resultDiv.className = 'monitoring-section';

        if (data.success && data.test_results) {
            const results = data.test_results;
            resultDiv.innerHTML = `
                <h4>[${timestamp}] ⚡ Performance Test Results:</h4>
                <pre style="color: #10b981;">
═══════════════════════════════════════════════════════
📊 PERFORMANCE TEST COMPLETED
═══════════════════════════════════════════════════════

⏱️  Duration:              ${results.duration}s
✅  Operations Completed:  ${results.operations_completed}
❌  Operations Failed:     ${results.operations_failed}
📈  Operations/Second:     ${results.ops_per_second}
✔️  Success Rate:          ${results.success_rate}%
⚡  Execution Time:        ${results.execution_time_ms}ms

📝 Test Configuration:
   • Duration:             ${data.test_parameters.duration}s
   • Ops/Second:           ${data.test_parameters.ops_per_second}
   • Test Users:           4 dedicated (IDs 99999-100002)
   • Scenarios:            100% email_queue (verification emails)

═══════════════════════════════════════════════════════
✨ Test completed successfully using dedicated test users
   No random test emails created!
═══════════════════════════════════════════════════════
                </pre>
            `;
        } else {
            resultDiv.innerHTML = `
                <h4>[${timestamp}] ❌ Performance Test Failed:</h4>
                <pre style="color: #ef4444;">Error: ${data.error || 'Unknown error'}</pre>
            `;
        }

        output.appendChild(resultDiv);
        output.scrollTop = output.scrollHeight;
    })
    .catch(err => {
        if (output.contains(loadingDiv)) {
            output.removeChild(loadingDiv);
        }

        const errorDiv = document.createElement('div');
        errorDiv.className = 'monitoring-section';
        errorDiv.innerHTML = `
            <h4>[${timestamp}] ❌ Performance Test Error:</h4>
            <pre style="color: #ef4444;">Network error: ${err.message}</pre>
        `;

        output.appendChild(errorDiv);
        output.scrollTop = output.scrollHeight;
    });
}

function clearMonitoringOutput() {
    const output = document.getElementById('monitoring-output');
    output.innerHTML = '<p class="text-slate-400">Click a monitoring button to see live data...</p>';
}

function showActionResult(action, title) {
    const output = document.getElementById('monitoring-output');
    const timestamp = new Date().toLocaleTimeString();

    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'monitoring-section';
    loadingDiv.innerHTML = `<p class="text-info">[${timestamp}] 🔄 Executing ${title}...</p>`;
    output.appendChild(loadingDiv);
    output.scrollTop = output.scrollHeight;

    fetch('system-action', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=${action}`
    })
    .then(response => response.json())
    .then(data => {
        output.removeChild(loadingDiv);

        const resultDiv = document.createElement('div');
        resultDiv.className = 'monitoring-section';

        if (data.success) {
            const message = data.message || data.output || `${title} completed successfully`;
            resultDiv.innerHTML = `
                <h4>[${timestamp}] ✅ ${title}:</h4>
                <pre style="color: #10b981;">${message}</pre>
            `;
        } else {
            resultDiv.innerHTML = `
                <h4>[${timestamp}] ❌ ${title}:</h4>
                <pre style="color: #ef4444;">Error: ${data.error || 'Unknown error'}</pre>
            `;
        }

        output.appendChild(resultDiv);
        output.scrollTop = output.scrollHeight;
    })
    .catch(err => {
        if (output.contains(loadingDiv)) {
            output.removeChild(loadingDiv);
        }

        const errorDiv = document.createElement('div');
        errorDiv.className = 'monitoring-section';
        errorDiv.innerHTML = `
            <h4>[${timestamp}] ❌ ${title}:</h4>
            <pre style="color: #ef4444;">Network error: ${err.message}</pre>
        `;

        output.appendChild(errorDiv);
        output.scrollTop = output.scrollHeight;
    });
}

function showMonitoringData(action, title) {
    const output = document.getElementById('monitoring-output');
    const timestamp = new Date().toLocaleTimeString();

    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'monitoring-section';
    loadingDiv.innerHTML = `<p class="text-info">[${timestamp}] 🔄 Loading ${title}...</p>`;
    output.appendChild(loadingDiv);
    output.scrollTop = output.scrollHeight;

    fetchMonitoringData(action).then(data => {
        output.removeChild(loadingDiv);

        const resultDiv = document.createElement('div');
        resultDiv.className = 'monitoring-section';
        resultDiv.innerHTML = `
            <h4>[${timestamp}] 📊 ${title}:</h4>
            <pre>${data}</pre>
        `;

        output.appendChild(resultDiv);
        output.scrollTop = output.scrollHeight;
    }).catch(err => {
        if (output.contains(loadingDiv)) {
            output.removeChild(loadingDiv);
        }

        const errorDiv = document.createElement('div');
        errorDiv.className = 'monitoring-section';
        errorDiv.innerHTML = `<p class="text-danger">[${timestamp}] ❌ Error loading ${title}</p>`;

        output.appendChild(errorDiv);
        output.scrollTop = output.scrollHeight;
    });
}

function fetchMonitoringData(action) {
    return fetch('system-action', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=${action}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.output) {
            return data.output;
        } else {
            throw new Error(data.error || 'Unknown error');
        }
    });
}

// Auto-load first tab on page load
document.addEventListener('DOMContentLoaded', () => {
    refreshVerificationMetrics();
});
</script>
