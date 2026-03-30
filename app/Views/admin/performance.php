<!-- 🚀 ENTERPRISE GALAXY: PERFORMANCE METRICS & ANALYTICS VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-rocket mr-3"></i>
    Dashboard Metriche Prestazioni
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(147, 51, 234, 0.2); color: #9333ea; font-weight: 600;">
        <i class="fas fa-tachometer-alt mr-1"></i>PRESTAZIONI TEMPO REALE
    </span>
</h2>

<?php
$dashboard = $dashboard ?? [];
$kpis = $dashboard['kpis'] ?? [];
$performance_breakdown = $dashboard['performance_breakdown'] ?? [];
$slow_pages = $dashboard['slow_pages'] ?? [];
$most_fast_pages = $dashboard['most_fast_pages'] ?? [];
$recent_fast = $dashboard['recent_fast'] ?? [];
$hourly_trend = $dashboard['hourly_trend'] ?? [];
$all_pages = $dashboard['all_pages'] ?? [];
$recent_slow = $dashboard['recent_slow'] ?? [];
$db_stats = $dashboard['db_stats'] ?? [];
$distribution = $dashboard['distribution'] ?? [];
?>

<!-- 📊 KPI Cards (Ultime 24h) -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <!-- Total Requests -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;">
            <?= number_format($kpis['total_requests'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-chart-line mr-2"></i>Totale Richieste (24h)
        </div>
    </div>

    <!-- Avg Page Load Time -->
    <div class="stat-card" style="border-left: 3px solid <?= ($kpis['avg_page_load'] ?? 0) < 400 ? '#10b981' : ($kpis['avg_page_load'] < 800 ? '#f59e0b' : '#ef4444') ?>;">
        <span class="stat-value" style="color: <?= ($kpis['avg_page_load'] ?? 0) < 400 ? '#10b981' : ($kpis['avg_page_load'] < 800 ? '#f59e0b' : '#ef4444') ?>;">
            <?= number_format($kpis['avg_page_load'] ?? 0) ?>ms
        </span>
        <div class="stat-label">
            <i class="fas fa-clock mr-2"></i>Caricamento Pagina Medio
        </div>
    </div>

    <!-- Avg Server Response -->
    <div class="stat-card" style="border-left: 3px solid <?= ($kpis['avg_server_response'] ?? 0) < 150 ? '#10b981' : ($kpis['avg_server_response'] < 300 ? '#f59e0b' : '#ef4444') ?>;">
        <span class="stat-value" style="color: <?= ($kpis['avg_server_response'] ?? 0) < 150 ? '#10b981' : ($kpis['avg_server_response'] < 300 ? '#f59e0b' : '#ef4444') ?>;">
            <?= number_format($kpis['avg_server_response'] ?? 0) ?>ms
        </span>
        <div class="stat-label">
            <i class="fas fa-server mr-2"></i>Risposta Server Media
        </div>
    </div>

    <!-- Avg DOM Ready -->
    <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
        <span class="stat-value" style="color: #8b5cf6;">
            <?= number_format($kpis['avg_dom_ready'] ?? 0) ?>ms
        </span>
        <div class="stat-label">
            <i class="fas fa-code mr-2"></i>DOM Ready Medio
        </div>
    </div>

    <!-- Avg First Byte -->
    <div class="stat-card" style="border-left: 3px solid #06b6d4;">
        <span class="stat-value" style="color: #06b6d4;">
            <?= number_format($kpis['avg_first_byte'] ?? 0) ?>ms
        </span>
        <div class="stat-label">
            <i class="fas fa-bolt mr-2"></i>First Byte Medio (TTFB)
        </div>
    </div>

    <!-- Unique Pages -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;">
            <?= number_format($kpis['unique_pages'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-file-alt mr-2"></i>Pagine Uniche Tracciate
        </div>
    </div>
</div>

<!-- 🚀 ENTERPRISE GALAXY: PERFORMANCE BREAKDOWN (Server / Network / Client) -->
<?php if (!empty($performance_breakdown)): ?>
<div class="mt-8 p-6 rounded-lg" style="background: linear-gradient(135deg, rgba(147, 51, 234, 0.1) 0%, rgba(59, 130, 246, 0.1) 100%); border: 1px solid rgba(147, 51, 234, 0.3);">
    <h3 class="text-xl font-bold mb-2 flex items-center">
        <i class="fas fa-microscope mr-3"></i>Analisi Dettagliata Prestazioni
        <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(16, 185, 129, 0.2); color: #10b981; font-weight: 600;">
            <i class="fas fa-shield-check mr-1"></i>METRICHE ENTERPRISE
        </span>
    </h3>
    <p class="text-sm mb-6" style="color: rgba(255,255,255,0.6);">
        <i class="fas fa-info-circle mr-1"></i>
        Separa le <strong style="color: #10b981;">metriche controllabili</strong> (il tuo server) dai <strong style="color: #f59e0b;">fattori lato utente</strong> (connessione/dispositivo).
        Questa analisi previene percezioni fuorvianti di "sito lento" quando il problema è in realtà la connettività dell'utente.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- SERVER PERFORMANCE (Controllable) -->
        <?php
        $server = $performance_breakdown['server'] ?? [];
        $server_status = $server['status'] ?? [];
        ?>
        <div class="p-5 rounded-lg" style="background: rgba(16, 185, 129, 0.1); border: 2px solid <?= $server_status['color'] ?? '#10b981' ?>;">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center">
                    <span class="text-2xl mr-2"><?= $server['icon'] ?? '🚀' ?></span>
                    <h4 class="text-sm font-bold" style="color: rgba(255,255,255,0.9);"><?= $server['label'] ?? 'Server' ?></h4>
                </div>
                <span class="text-xs px-2 py-1 rounded font-bold" style="background: <?= $server_status['bg_color'] ?? '#d1fae5' ?>; color: <?= $server_status['color'] ?? '#10b981' ?>;">
                    <?= $server_status['badge'] ?? 'GOOD' ?>
                </span>
            </div>
            <div class="text-3xl font-bold mb-2" style="color: <?= $server_status['color'] ?? '#10b981' ?>;">
                <?= $server['value'] ?? 0 ?>ms
            </div>
            <p class="text-xs" style="color: rgba(255,255,255,0.5);">
                <?= $server['description'] ?? 'PHP execution time' ?>
            </p>
            <div class="mt-3 pt-3 border-t" style="border-color: rgba(255,255,255,0.1);">
                <span class="text-xs px-2 py-1 rounded" style="background: rgba(16, 185, 129, 0.2); color: #10b981;">
                    <i class="fas fa-user-check mr-1"></i>TUO CONTROLLO
                </span>
            </div>
        </div>

        <!-- NETWORK LATENCY (User Connection) -->
        <?php
        $network = $performance_breakdown['network'] ?? [];
        $network_status = $network['status'] ?? [];
        ?>
        <div class="p-5 rounded-lg" style="background: rgba(245, 158, 11, 0.05); border: 2px solid <?= $network_status['color'] ?? '#f59e0b' ?>;">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center">
                    <span class="text-2xl mr-2"><?= $network['icon'] ?? '🌐' ?></span>
                    <h4 class="text-sm font-bold" style="color: rgba(255,255,255,0.9);"><?= $network['label'] ?? 'Network' ?></h4>
                </div>
                <span class="text-xs px-2 py-1 rounded font-bold" style="background: <?= $network_status['bg_color'] ?? '#fef3c7' ?>; color: <?= $network_status['color'] ?? '#f59e0b' ?>;">
                    <?= $network_status['badge'] ?? 'ACCEPTABLE' ?>
                </span>
            </div>
            <div class="text-3xl font-bold mb-2" style="color: <?= $network_status['color'] ?? '#f59e0b' ?>;">
                <?= $network['value'] ?? 0 ?>ms
            </div>
            <p class="text-xs" style="color: rgba(255,255,255,0.5);">
                <?= $network['description'] ?? 'User connection speed' ?>
            </p>
            <div class="mt-3 pt-3 border-t" style="border-color: rgba(255,255,255,0.1);">
                <span class="text-xs px-2 py-1 rounded" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">
                    <i class="fas fa-wifi mr-1"></i>CONNESSIONE UTENTE
                </span>
            </div>
        </div>

        <!-- CLIENT RENDERING (User Device) -->
        <?php
        $client = $performance_breakdown['client'] ?? [];
        $client_status = $client['status'] ?? [];
        ?>
        <div class="p-5 rounded-lg" style="background: rgba(245, 158, 11, 0.05); border: 2px solid <?= $client_status['color'] ?? '#f59e0b' ?>;">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center">
                    <span class="text-2xl mr-2"><?= $client['icon'] ?? '💻' ?></span>
                    <h4 class="text-sm font-bold" style="color: rgba(255,255,255,0.9);"><?= $client['label'] ?? 'Client' ?></h4>
                </div>
                <span class="text-xs px-2 py-1 rounded font-bold" style="background: <?= $client_status['bg_color'] ?? '#fef3c7' ?>; color: <?= $client_status['color'] ?? '#f59e0b' ?>;">
                    <?= $client_status['badge'] ?? 'ACCEPTABLE' ?>
                </span>
            </div>
            <div class="text-3xl font-bold mb-2" style="color: <?= $client_status['color'] ?? '#f59e0b' ?>;">
                <?= $client['value'] ?? 0 ?>ms
            </div>
            <p class="text-xs" style="color: rgba(255,255,255,0.5);">
                <?= $client['description'] ?? 'Browser rendering speed' ?>
            </p>
            <div class="mt-3 pt-3 border-t" style="border-color: rgba(255,255,255,0.1);">
                <span class="text-xs px-2 py-1 rounded" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">
                    <i class="fas fa-mobile-screen mr-1"></i>DISPOSITIVO UTENTE
                </span>
            </div>
        </div>

        <!-- TOTAL PAGE LOAD -->
        <?php
        $total = $performance_breakdown['total'] ?? [];
        $total_status = $total['status'] ?? [];
        ?>
        <div class="p-5 rounded-lg" style="background: rgba(59, 130, 246, 0.05); border: 2px solid <?= $total_status['color'] ?? '#3b82f6' ?>;">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center">
                    <span class="text-2xl mr-2"><?= $total['icon'] ?? '⚡' ?></span>
                    <h4 class="text-sm font-bold" style="color: rgba(255,255,255,0.9);"><?= $total['label'] ?? 'Total' ?></h4>
                </div>
                <span class="text-xs px-2 py-1 rounded font-bold" style="background: <?= $total_status['bg_color'] ?? '#dbeafe' ?>; color: <?= $total_status['color'] ?? '#3b82f6' ?>;">
                    <?= $total_status['badge'] ?? 'GOOD' ?>
                </span>
            </div>
            <div class="text-3xl font-bold mb-2" style="color: <?= $total_status['color'] ?? '#3b82f6' ?>;">
                <?= $total['value'] ?? 0 ?>ms
            </div>
            <p class="text-xs" style="color: rgba(255,255,255,0.5);">
                <?= $total['description'] ?? 'Complete load time' ?>
            </p>
            <div class="mt-3 pt-3 border-t" style="border-color: rgba(255,255,255,0.1);">
                <span class="text-xs px-2 py-1 rounded" style="background: rgba(59, 130, 246, 0.2); color: #3b82f6;">
                    <i class="fas fa-clock mr-1"></i>TEMPO PERCEPITO UTENTE
                </span>
            </div>
        </div>
    </div>

    <!-- ENTERPRISE INSIGHTS: What This Means -->
    <div class="mt-6 p-4 rounded-lg" style="background: rgba(255, 255, 255, 0.03); border-left: 4px solid #9333ea;">
        <h4 class="text-sm font-bold mb-3 flex items-center" style="color: #9333ea;">
            <i class="fas fa-brain mr-2"></i>Cosa Significa
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs" style="color: rgba(255,255,255,0.7);">
            <div>
                <strong style="color: #10b981;">✅ Prestazioni Server:</strong>
                <?php if (($server['value'] ?? 0) < 150): ?>
                    Eccellente! Il tuo server risponde in <<?= $server['value'] ?? 0 ?>ms. Queste sono prestazioni enterprise-grade.
                <?php elseif (($server['value'] ?? 0) < 300): ?>
                    Buone prestazioni server. Il tempo di risposta è sotto i 300ms.
                <?php else: ?>
                    Ottimizzazione server consigliata. Target: <150ms per UX ottimale.
                <?php endif; ?>
            </div>
            <div>
                <strong style="color: #f59e0b;">⚠️ Network/Client:</strong>
                <?php if ((($network['value'] ?? 0) + ($client['value'] ?? 0)) > 1000): ?>
                    L'utente ha una connessione lenta (<?= $network['value'] ?? 0 ?>ms) o dispositivo lento (<?= $client['value'] ?? 0 ?>ms). Questo NON è colpa del tuo sito - è la loro connessione 3G/4G o velocità del dispositivo.
                <?php else: ?>
                    La connessione e il dispositivo dell'utente stanno performando in modo accettabile.
                <?php endif; ?>
            </div>
            <div>
                <strong style="color: #3b82f6;">💡 Conclusione:</strong>
                <?php if (($server['value'] ?? 0) < 150 && (($network['value'] ?? 0) + ($client['value'] ?? 0)) > 1000): ?>
                    Il tuo sito è VELOCE (<?= $server['value'] ?? 0 ?>ms server). La lentezza è lato utente (connessione/dispositivo). PageSpeed 100 lo conferma! 🎉
                <?php elseif (($server['value'] ?? 0) >= 300): ?>
                    Concentrati prima sull'ottimizzazione server - questo è sotto il tuo controllo.
                <?php else: ?>
                    Prestazioni bilanciate su tutte le metriche. Ottimo lavoro! 🚀
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 📊 Performance Distribution (Fast/Medium/Slow) -->
<div class="mt-8 p-6 rounded-lg" style="background: rgba(255, 255, 255, 0.05);">
    <h3 class="text-xl font-bold mb-4 flex items-center">
        <i class="fas fa-chart-pie mr-3"></i>Distribuzione Prestazioni (24h)
    </h3>
    <div class="grid grid-cols-3 gap-4">
        <div class="text-center p-4 rounded" style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981;">
            <div class="text-3xl font-bold mb-2" style="color: #10b981;"><?= $distribution['fast_pct'] ?? 0 ?>%</div>
            <div class="text-sm" style="color: rgba(255,255,255,0.7);">
                <i class="fas fa-rabbit-fast mr-2"></i>Veloce (&lt;150ms)
            </div>
            <div class="text-xs mt-1" style="color: rgba(255,255,255,0.5);">
                <?= number_format($distribution['fast_count'] ?? 0) ?> richieste
            </div>
        </div>
        <div class="text-center p-4 rounded" style="background: rgba(245, 158, 11, 0.1); border: 1px solid #f59e0b;">
            <div class="text-3xl font-bold mb-2" style="color: #f59e0b;"><?= $distribution['medium_pct'] ?? 0 ?>%</div>
            <div class="text-sm" style="color: rgba(255,255,255,0.7);">
                <i class="fas fa-gauge-high mr-2"></i>Medio (150-500ms)
            </div>
            <div class="text-xs mt-1" style="color: rgba(255,255,255,0.5);">
                <?= number_format($distribution['medium_count'] ?? 0) ?> richieste
            </div>
        </div>
        <div class="text-center p-4 rounded" style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444;">
            <div class="text-3xl font-bold mb-2" style="color: #ef4444;"><?= $distribution['slow_pct'] ?? 0 ?>%</div>
            <div class="text-sm" style="color: rgba(255,255,255,0.7);">
                <i class="fas fa-turtle mr-2"></i>Lento (&gt;500ms)
            </div>
            <div class="text-xs mt-1" style="color: rgba(255,255,255,0.5);">
                <?= number_format($distribution['slow_count'] ?? 0) ?> richieste
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tabs-container mt-8">
    <div class="tabs-header">
        <button class="tab-btn active" data-tab="overview">
            <i class="fas fa-chart-bar mr-2"></i>Panoramica
        </button>
        <button class="tab-btn" data-tab="slow-pages">
            <i class="fas fa-exclamation-triangle mr-2"></i>Pagine Lente
        </button>
        <button class="tab-btn" data-tab="most-fast-pages">
            <i class="fas fa-trophy mr-2"></i>Pagine Più Veloci
        </button>
        <button class="tab-btn" data-tab="all-pages">
            <i class="fas fa-list mr-2"></i>Tutte le Pagine
        </button>
        <button class="tab-btn" data-tab="hourly-trend">
            <i class="fas fa-chart-line mr-2"></i>Tendenza Oraria
        </button>
        <button class="tab-btn" data-tab="recent-fast">
            <i class="fas fa-bolt mr-2"></i>Recenti Veloci
        </button>
        <button class="tab-btn" data-tab="recent-slow">
            <i class="fas fa-history mr-2"></i>Recenti Lente
        </button>
    </div>

    <!-- Tab: Overview -->
    <div id="tab-overview" class="tab-content active">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-chart-bar mr-2"></i>Panoramica Prestazioni
            </h3>
            <div class="flex gap-2">
                <button onclick="exportPerformanceMetrics()" class="btn btn-primary btn-sm">
                    <i class="fas fa-download mr-2"></i>Esporta CSV
                </button>
                <button onclick="location.reload()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt mr-2"></i>Aggiorna
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Database Stats -->
            <div class="p-6 rounded-lg" style="background: rgba(255, 255, 255, 0.05);">
                <h4 class="text-lg font-bold mb-4" style="color: #3b82f6;">
                    <i class="fas fa-database mr-2"></i>Statistiche Database
                </h4>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span style="color: rgba(255,255,255,0.7);">Metriche Totali Memorizzate:</span>
                        <span class="font-bold"><?= number_format($db_stats['total_metrics'] ?? 0) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span style="color: rgba(255,255,255,0.7);">Pagine Uniche Tracciate:</span>
                        <span class="font-bold"><?= number_format($db_stats['unique_pages'] ?? 0) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span style="color: rgba(255,255,255,0.7);">Utenti Unici:</span>
                        <span class="font-bold"><?= number_format($db_stats['unique_users'] ?? 0) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span style="color: rgba(255,255,255,0.7);">Giorni Tracciati:</span>
                        <span class="font-bold"><?= number_format($db_stats['days_tracked'] ?? 0) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span style="color: rgba(255,255,255,0.7);">Metrica Più Vecchia:</span>
                        <span class="font-bold text-sm"><?= isset($db_stats['oldest_metric']) ? date('Y-m-d H:i', strtotime($db_stats['oldest_metric'])) : 'N/A' ?></span>
                    </div>
                </div>
            </div>

            <!-- Performance Insights -->
            <div class="p-6 rounded-lg" style="background: rgba(255, 255, 255, 0.05);">
                <h4 class="text-lg font-bold mb-4" style="color: #10b981;">
                    <i class="fas fa-lightbulb mr-2"></i>Insights Prestazioni
                </h4>
                <div class="space-y-3">
                    <?php
                    $avgLoad = $kpis['avg_page_load'] ?? 0;
$avgServer = $kpis['avg_server_response'] ?? 0;
$fastPct = $distribution['fast_pct'] ?? 0;
?>
                    <?php if ($avgLoad < 400): ?>
                        <div class="flex items-start gap-3">
                            <i class="fas fa-check-circle text-green-500 mt-1"></i>
                            <span style="color: rgba(255,255,255,0.9);">Eccellente! Caricamento medio pagina sotto i 400ms.</span>
                        </div>
                    <?php elseif ($avgLoad < 800): ?>
                        <div class="flex items-start gap-3">
                            <i class="fas fa-info-circle text-yellow-500 mt-1"></i>
                            <span style="color: rgba(255,255,255,0.9);">Buone prestazioni. Considera di ottimizzare le pagine >800ms.</span>
                        </div>
                    <?php else: ?>
                        <div class="flex items-start gap-3">
                            <i class="fas fa-exclamation-circle text-red-500 mt-1"></i>
                            <span style="color: rgba(255,255,255,0.9);">Le prestazioni necessitano di attenzione. Controlla la tab pagine lente.</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($avgServer < 150): ?>
                        <div class="flex items-start gap-3">
                            <i class="fas fa-check-circle text-green-500 mt-1"></i>
                            <span style="color: rgba(255,255,255,0.9);">Tempo di risposta server eccellente (&lt;150ms).</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($fastPct > 70): ?>
                        <div class="flex items-start gap-3">
                            <i class="fas fa-trophy text-yellow-400 mt-1"></i>
                            <span style="color: rgba(255,255,255,0.9);">Oltre il 70% delle pagine si carica in meno di 150ms! 🎉</span>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-start gap-3">
                        <i class="fas fa-chart-line text-blue-500 mt-1"></i>
                        <span style="color: rgba(255,255,255,0.9);">Tracciamento di <?= number_format($kpis['total_requests'] ?? 0) ?> richieste nelle ultime 24h.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Slow Pages (>300ms) -->
    <div id="tab-slow-pages" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Pagine Lente (&gt;300ms)
            </h3>
        </div>

        <?php if (empty($slow_pages)): ?>
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                <p class="text-xl" style="color: rgba(255,255,255,0.9);">Nessuna pagina lenta rilevata!</p>
                <p class="text-sm mt-2" style="color: rgba(255,255,255,0.5);">Tutte le pagine si caricano in meno di 300ms.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>URL Pagina</th>
                            <th>Richieste</th>
                            <th>🚀 Server</th>
                            <th>🌐 Network</th>
                            <th>💻 Client</th>
                            <th>⚡ Totale</th>
                            <th>Caric. Max</th>
                            <th>Caric. Min</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slow_pages as $page): ?>
                            <?php
                            // Calculate breakdown per row (same logic as controller)
                            $serverTime = (float) $page['avg_server'];
                            $ttfb = (float) $page['avg_ttfb'];
                            $totalLoad = (float) $page['avg_load'];
                            $networkTime = max(0, $ttfb - $serverTime);
                            $clientTime = max(0, $totalLoad - $ttfb);

                            // Color coding (ENTERPRISE V11.8: Realistic thresholds)
                            $serverColor = $serverTime < 150 ? '#10b981' : ($serverTime < 300 ? '#f59e0b' : '#ef4444');
                            $networkColor = $networkTime < 300 ? '#10b981' : ($networkTime < 600 ? '#f59e0b' : '#ef4444');
                            $clientColor = $clientTime < 500 ? '#10b981' : ($clientTime < 1500 ? '#f59e0b' : '#ef4444');
                            ?>
                            <tr>
                                <td><code class="text-xs"><?= htmlspecialchars($page['page_url']) ?></code></td>
                                <td><?= number_format($page['requests']) ?></td>
                                <td>
                                    <span class="badge" style="background: <?= $serverColor ?>20; color: <?= $serverColor ?>;">
                                        <?= round($serverTime) ?>ms
                                    </span>
                                    <div class="text-xs mt-1" style="color: rgba(255,255,255,0.4);">TUO CONTROLLO</div>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?= $networkColor ?>20; color: <?= $networkColor ?>;">
                                        <?= round($networkTime) ?>ms
                                    </span>
                                    <div class="text-xs mt-1" style="color: rgba(255,255,255,0.4);">CONNESSIONE UTENTE</div>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?= $clientColor ?>20; color: <?= $clientColor ?>;">
                                        <?= round($clientTime) ?>ms
                                    </span>
                                    <div class="text-xs mt-1" style="color: rgba(255,255,255,0.4);">DISPOSITIVO UTENTE</div>
                                </td>
                                <td><span class="badge badge-danger"><?= $page['avg_load'] ?>ms</span></td>
                                <td><?= $page['max_load'] ?>ms</td>
                                <td><?= $page['min_load'] ?>ms</td>
                                <td>
                                    <?php if ($page['avg_load'] > 500): ?>
                                        <span class="badge badge-danger"><i class="fas fa-fire"></i> Critico</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><i class="fas fa-exclamation"></i> Da Ottimizzare</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Most Fast Pages (Top 10 Best Times) -->
    <div id="tab-most-fast-pages" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-trophy mr-2 text-yellow-500"></i>🏆 Pagine Più Veloci (Top 10 Migliori Tempi)
            </h3>
        </div>

        <?php if (empty($most_fast_pages)): ?>
            <div class="text-center py-8">
                <i class="fas fa-info-circle text-6xl text-blue-500 mb-4"></i>
                <p class="text-xl" style="color: rgba(255,255,255,0.9);">Nessuna pagina veloce trovata</p>
                <p class="text-sm mt-2" style="color: rgba(255,255,255,0.5);">Prova ad ottimizzare le tue pagine per ottenere tempi di caricamento più veloci.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>URL Pagina</th>
                            <th>Caricamento</th>
                            <th>Risposta Server</th>
                            <th>DOM Ready</th>
                            <th>TTFB</th>
                            <th>DNS Lookup</th>
                            <th>Connect</th>
                            <th>ID Utente</th>
                            <th>Creato il</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1;
            foreach ($most_fast_pages as $page): ?>
                            <tr>
                                <td>
                                    <?php if ($rank === 1): ?>
                                        <span class="text-2xl">🥇</span>
                                    <?php elseif ($rank === 2): ?>
                                        <span class="text-2xl">🥈</span>
                                    <?php elseif ($rank === 3): ?>
                                        <span class="text-2xl">🥉</span>
                                    <?php else: ?>
                                        <span class="font-bold"><?= $rank ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code class="text-xs"><?= htmlspecialchars($page['page_url']) ?></code></td>
                                <td><span class="badge badge-success"><?= $page['page_load_time'] ?>ms</span></td>
                                <td><?= $page['server_response_time'] ?>ms</td>
                                <td><?= $page['dom_ready_time'] ?>ms</td>
                                <td><?= $page['first_byte_time'] ?>ms</td>
                                <td><?= $page['dns_lookup_time'] ?>ms</td>
                                <td><?= $page['connect_time'] ?>ms</td>
                                <td><?= $page['user_id'] ?? 'Guest' ?></td>
                                <td class="text-xs"><?= date('Y-m-d H:i:s', strtotime($page['created_at'])) ?></td>
                            </tr>
                        <?php $rank++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab: All Pages -->
    <div id="tab-all-pages" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-list mr-2"></i>Prestazioni Tutte le Pagine (24h)
            </h3>
        </div>

        <div class="table-wrapper">
            <table class="enterprise-table sticky-header">
                <thead>
                    <tr>
                        <th>URL Pagina</th>
                        <th>Richieste</th>
                        <th>🚀 Server</th>
                        <th>🌐 Network</th>
                        <th>💻 Client</th>
                        <th>⚡ Caric. Totale</th>
                        <th>Caric. Max</th>
                        <th>Caric. Min</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_pages as $page): ?>
                        <?php
                        // Calculate breakdown per row
                        $serverTime = (float) $page['avg_server'];
                        $ttfb = (float) $page['avg_first_byte'];
                        $totalLoad = (float) $page['avg_load'];
                        $networkTime = max(0, $ttfb - $serverTime);
                        $clientTime = max(0, $totalLoad - $ttfb);

                        // Color coding (ENTERPRISE V11.8: Realistic thresholds)
                        $serverColor = $serverTime < 150 ? '#10b981' : ($serverTime < 300 ? '#f59e0b' : '#ef4444');
                        $networkColor = $networkTime < 300 ? '#10b981' : ($networkTime < 600 ? '#f59e0b' : '#ef4444');
                        $clientColor = $clientTime < 500 ? '#10b981' : ($clientTime < 1500 ? '#f59e0b' : '#ef4444');
                        ?>
                        <tr>
                            <td><code class="text-xs"><?= htmlspecialchars($page['page_url']) ?></code></td>
                            <td><?= number_format($page['requests']) ?></td>
                            <td>
                                <span class="badge" style="background: <?= $serverColor ?>20; color: <?= $serverColor ?>;">
                                    <?= round($serverTime) ?>ms
                                </span>
                            </td>
                            <td>
                                <span class="badge" style="background: <?= $networkColor ?>20; color: <?= $networkColor ?>;">
                                    <?= round($networkTime) ?>ms
                                </span>
                            </td>
                            <td>
                                <span class="badge" style="background: <?= $clientColor ?>20; color: <?= $clientColor ?>;">
                                    <?= round($clientTime) ?>ms
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $page['avg_load'] < 150 ? 'success' : ($page['avg_load'] < 500 ? 'warning' : 'danger') ?>">
                                    <?= $page['avg_load'] ?>ms
                                </span>
                            </td>
                            <td><?= $page['max_load'] ?>ms</td>
                            <td><?= $page['min_load'] ?>ms</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab: Hourly Trend -->
    <div id="tab-hourly-trend" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-chart-line mr-2"></i>Tendenza Prestazioni (Ultime 24h)
            </h3>
        </div>

        <div class="table-wrapper">
            <table class="enterprise-table sticky-header">
                <thead>
                    <tr>
                        <th>Ora</th>
                        <th>Richieste</th>
                        <th>Caric. Medio</th>
                        <th>Server Medio</th>
                        <th>DOM Medio</th>
                        <th>Caric. Max</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hourly_trend as $hour): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($hour['hour'])) ?></td>
                            <td><?= number_format($hour['requests']) ?></td>
                            <td>
                                <span class="badge badge-<?= $hour['avg_load'] < 150 ? 'success' : ($hour['avg_load'] < 500 ? 'warning' : 'danger') ?>">
                                    <?= $hour['avg_load'] ?>ms
                                </span>
                            </td>
                            <td><?= $hour['avg_server'] ?>ms</td>
                            <td><?= $hour['avg_dom'] ?>ms</td>
                            <td><?= $hour['max_load'] ?>ms</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab: Recent Fast Requests (<50ms) -->
    <div id="tab-recent-fast" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-bolt mr-2 text-green-500"></i>🚀 Richieste Veloci Recenti (&lt;150ms)
            </h3>
        </div>

        <?php if (empty($recent_fast)): ?>
            <div class="text-center py-8">
                <i class="fas fa-info-circle text-6xl text-blue-500 mb-4"></i>
                <p class="text-xl" style="color: rgba(255,255,255,0.9);">Nessuna richiesta veloce recente!</p>
                <p class="text-sm mt-2" style="color: rgba(255,255,255,0.5);">Nessuna richiesta completata in meno di 150ms di recente.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>URL Pagina</th>
                            <th>Caricamento</th>
                            <th>Risposta Server</th>
                            <th>DOM Ready</th>
                            <th>TTFB</th>
                            <th>DNS Lookup</th>
                            <th>Connect</th>
                            <th>User Agent</th>
                            <th>ID Utente</th>
                            <th>Creato il</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_fast as $request): ?>
                            <tr>
                                <td><code class="text-xs"><?= htmlspecialchars($request['page_url']) ?></code></td>
                                <td><span class="badge badge-success"><?= $request['page_load_time'] ?>ms</span></td>
                                <td><?= $request['server_response_time'] ?>ms</td>
                                <td><?= $request['dom_ready_time'] ?>ms</td>
                                <td><?= $request['first_byte_time'] ?>ms</td>
                                <td><?= $request['dns_lookup_time'] ?>ms</td>
                                <td><?= $request['connect_time'] ?>ms</td>
                                <td><code class="text-xs"><?= htmlspecialchars(substr($request['user_agent'], 0, 50)) ?>...</code></td>
                                <td><?= $request['user_id'] ?? 'Guest' ?></td>
                                <td class="text-xs"><?= date('Y-m-d H:i:s', strtotime($request['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Recent Slow Requests -->
    <div id="tab-recent-slow" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-history mr-2 text-yellow-500"></i>Richieste Lente Recenti (&gt;500ms)
            </h3>
        </div>

        <?php if (empty($recent_slow)): ?>
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                <p class="text-xl" style="color: rgba(255,255,255,0.9);">Nessuna richiesta lenta recente!</p>
                <p class="text-sm mt-2" style="color: rgba(255,255,255,0.5);">Tutte le richieste recenti sono state completate in meno di 500ms.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>URL Pagina</th>
                            <th>Caricamento</th>
                            <th>Risposta Server</th>
                            <th>DOM Ready</th>
                            <th>TTFB</th>
                            <th>DNS Lookup</th>
                            <th>Connect</th>
                            <th>User Agent</th>
                            <th>ID Utente</th>
                            <th>Creato il</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_slow as $request): ?>
                            <tr>
                                <td><code class="text-xs"><?= htmlspecialchars($request['page_url']) ?></code></td>
                                <td><span class="badge badge-danger"><?= $request['page_load_time'] ?>ms</span></td>
                                <td><?= $request['server_response_time'] ?>ms</td>
                                <td><?= $request['dom_ready_time'] ?>ms</td>
                                <td><?= $request['first_byte_time'] ?>ms</td>
                                <td><?= $request['dns_lookup_time'] ?? 'N/A' ?>ms</td>
                                <td><?= $request['connect_time'] ?? 'N/A' ?>ms</td>
                                <td><code class="text-xs"><?= htmlspecialchars(substr($request['user_agent'], 0, 50)) ?>...</code></td>
                                <td><?= $request['user_id'] ?? 'Guest' ?></td>
                                <td class="text-xs"><?= date('Y-m-d H:i:s', strtotime($request['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
    color: #9333ea;
    border-bottom-color: #9333ea;
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

.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-success {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.badge-warning {
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
}

.badge-danger {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
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

/* Scrollbar styling */
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
    });
});

// Export performance metrics
function exportPerformanceMetrics() {
    const days = prompt('Quanti giorni di dati esportare? (1-365)', '30');
    if (days && days > 0 && days <= 365) {
        window.location.href = `api/performance/export?days=${days}`;
    }
}

// Auto-load first tab on page load
document.addEventListener('DOMContentLoaded', () => {
    console.info('📊 Dashboard prestazioni caricata con successo');
});
</script>
