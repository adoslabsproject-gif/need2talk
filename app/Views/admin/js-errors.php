<!-- ENTERPRISE GALAXY JS ERROR MONITORING VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-bug mr-3"></i>
    Monitoraggio Errori JavaScript
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; font-weight: 600;">REAL-TIME LOG FILES</span>
</h2>

<!-- ENTERPRISE GALAXY: Dynamic Logging Configuration for JS Errors Channel -->
<div class="card mb-6" style="border: 2px solid rgba(139, 92, 246, 0.3);">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-sliders-h mr-3"></i>Configurazione Logging Errori JS
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle;">ENTERPRISE GALAXY</span>
        </span>
    </h3>

    <div class="alert alert-info mb-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-400 text-xl"></i>
            <div class="flex-1">
                <h4 class="text-white mb-2">🚀 Configurazione Zero-Downtime</h4>
                <p class="text-sm text-gray-300 mb-2">
                    Modifica i livelli di logging per gli errori JavaScript <strong>senza riavviare</strong> PHP-FPM o workers.
                </p>
                <ul class="text-xs text-gray-400 space-y-1">
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Canale dedicato per tutti gli errori JS</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Attivazione istantanea via Redis L1 cache</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Meccanismo di auto-rollback</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Retention di 30 giorni per l'analisi</li>
                </ul>
            </div>
        </div>
    </div>

    <?php
    // Load current js_errors channel configuration
    $jsErrorsConfig = null;
    $availableLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    $levelColors = [
        'debug' => '#8b5cf6',
        'info' => '#3b82f6',
        'notice' => '#06b6d4',
        'warning' => '#f59e0b',
        'error' => '#ef4444',
        'critical' => '#dc2626',
        'alert' => '#991b1b',
        'emergency' => '#7f1d1d',
    ];

    try {
        if (class_exists('Need2Talk\\Services\\LoggingConfigService')) {
            $loggingService = \Need2Talk\Services\LoggingConfigService::getInstance();
            $config = $loggingService->getConfiguration(skipCache: true);
            $jsErrorsConfig = $config['js_errors'] ?? null;
        }
    } catch (\Exception $e) {
        $jsErrorsConfig = null;
    }

    $currentLevel = $jsErrorsConfig['level'] ?? 'info';
    $autoRollbackAt = $jsErrorsConfig['auto_rollback_at'] ?? null;
    ?>

    <div class="p-4 rounded-lg" style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%); border: 1px solid rgba(139, 92, 246, 0.2);">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <i class="fas fa-bug text-purple-400"></i>
                <span class="font-semibold text-white">JS Errors Channel</span>
            </div>
            <span class="text-xs px-2 py-1 rounded" style="background: <?= $levelColors[$currentLevel] ?>; color: #fff;">
                <?= strtoupper($currentLevel) ?>
            </span>
        </div>

        <p class="text-xs text-gray-400 mb-3">Canale dedicato per tutti gli errori JavaScript dal frontend</p>

        <div class="mb-3">
            <label class="text-xs text-gray-300 mb-1 block">Livello Log</label>
            <select id="level_js_errors" class="form-control text-sm" style="padding: 0.5rem;">
                <?php foreach ($availableLevels as $level) { ?>
                    <option value="<?= $level ?>" <?= $level === $currentLevel ? 'selected' : '' ?>>
                        <?= strtoupper($level) ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="text-xs text-gray-300 mb-1 block">
                <i class="fas fa-clock mr-1"></i>Auto-Rollback (minuti)
            </label>
            <input type="number" id="rollback_js_errors" class="form-control text-sm" style="padding: 0.5rem;" placeholder="Opzionale (5-1440)" min="5" max="1440">
            <span class="text-xs text-gray-500">Lascia vuoto per modifica permanente</span>
        </div>

        <?php if ($autoRollbackAt) { ?>
        <div class="alert alert-warning p-2 mb-2 text-xs">
            <i class="fas fa-undo-alt mr-1"></i>
            Auto-rollback alle: <?= date('d/m/Y H:i', strtotime($autoRollbackAt)) ?>
        </div>
        <?php } ?>

        <button onclick="saveJsErrorsLoggingConfig()" class="btn btn-primary btn-sm">
            <i class="fas fa-save mr-1"></i>Salva Configurazione
        </button>
    </div>

    <div class="mt-4 p-3 rounded" style="background: rgba(251, 191, 36, 0.1); border-left: 3px solid #f59e0b;">
        <div class="flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
            <div class="text-sm text-gray-300">
                <strong class="text-white">Impatto Performance:</strong>
                Abilitare il livello <code class="text-purple-400">DEBUG</code> per errori JS può aumentare il volume dei log.
            </div>
        </div>
    </div>
</div>

<!-- ENTERPRISE GALAXY ULTIMATE: Database Filter Info (Read-Only - Configure in Settings Tab) -->
<div class="card mb-6" style="border: 2px solid rgba(34, 197, 94, 0.3);">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-database mr-3"></i>Filtro Archiviazione Database
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle;">PSR-3 ENTERPRISE</span>
        </span>
    </h3>

    <div class="alert alert-info mb-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-400 text-xl"></i>
            <div class="flex-1">
                <h4 class="text-white mb-2">📊 Logging File vs Database Indipendente</h4>
                <p class="text-sm text-gray-300 mb-2">
                    L'archiviazione nel database è filtrata indipendentemente dal logging su file.
                </p>
                <ul class="text-xs text-gray-400 space-y-1">
                    <li><i class="fas fa-check text-green-400 mr-2"></i><strong>Log su file</strong>: Rispettano il livello del canale js_errors (configurato sopra)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i><strong>Archiviazione database</strong>: Filtrata dalle impostazioni admin (configurabile nel tab Impostazioni)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Il database archivia dati strutturati per l'analisi, i file archiviano log grezzi</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Livelli PSR-3 puri: debug, info, notice, warning, error, critical, alert, emergency</li>
                </ul>
            </div>
        </div>
    </div>

    <?php
    // Load current database filter configuration (read-only display)
    $dbFilterConfig = null;

    try {
        $setting = db()->findOne(
            "SELECT setting_value FROM admin_settings WHERE setting_key = 'js_errors_db_filter_config'",
            [],
            ['cache_ttl' => 'medium']
        );

        if ($setting) {
            $dbFilterConfig = json_decode($setting['setting_value'], true);
        }
    } catch (\Exception $e) {
        $dbFilterConfig = null;
    }

    $filterEnabled = $dbFilterConfig['enabled'] ?? true;
    $filterMinLevel = $dbFilterConfig['min_level'] ?? 'error';
    ?>

    <div class="p-4 rounded-lg" style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%); border: 1px solid rgba(34, 197, 94, 0.2);">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <i class="fas fa-database text-green-400"></i>
                <span class="font-semibold text-white">Current Database Filter Status</span>
            </div>
            <span class="text-xs px-2 py-1 rounded font-semibold" style="background: <?= $filterEnabled ? '#22c55e' : '#6b7280' ?>; color: #fff;">
                <?= $filterEnabled ? 'ENABLED' : 'DISABLED' ?>
            </span>
        </div>

        <p class="text-sm text-gray-300 mb-3">
            <?php if ($filterEnabled) { ?>
                📊 Archiviazione solo errori di severità <strong class="text-green-400"><?= strtoupper($filterMinLevel) ?></strong> e superiore nel database
            <?php } else { ?>
                📊 Filtro disabilitato - archiviazione di <strong>TUTTI</strong> i livelli di errore nel database
            <?php } ?>
        </p>

        <div class="mt-3 p-3 rounded" style="background: rgba(139, 92, 246, 0.1); border-left: 3px solid #8b5cf6;">
            <div class="flex items-start gap-2">
                <i class="fas fa-cog text-purple-400"></i>
                <div class="text-sm text-gray-300">
                    <strong class="text-white">Configurazione:</strong>
                    Per modificare le impostazioni del filtro database, vai al <a href="settings" class="text-purple-400 underline">tab Impostazioni</a>.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $js_error_logs['total_files'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-file-alt mr-2"></i>File Log Totali
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $js_error_logs['total_size_formatted'] ?? '0 B' ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Dimensione Totale
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value">
            <a href="js-error-test" target="_blank" class="btn btn-warning btn-sm">
                <i class="fas fa-vial mr-2"></i>Pagina Test
            </a>
        </span>
        <div class="stat-label">Strumenti Test</div>
    </div>
    <div class="stat-card">
        <span class="stat-value">
            <button onclick="refreshPage()" class="btn btn-primary btn-sm">
                <i class="fas fa-sync-alt mr-2"></i>Aggiorna Tutto
            </button>
        </span>
        <div class="stat-label">Azioni Rapide</div>
    </div>
</div>

<!-- Log Files Table -->
<?php if (!empty($js_error_logs['files'])) { ?>
<div class="card mb-6">
    <h3 class="flex items-center">
        <i class="fas fa-list mr-3"></i>File Log Errori JavaScript
    </h3>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 30%;">Nome File</th>
                    <th style="width: 10%;">Dimensione</th>
                    <th style="width: 10%;">Righe</th>
                    <th style="width: 15%;">Ultima Modifica</th>
                    <th style="width: 35%;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($js_error_logs['files'] as $file) { ?>
                    <tr>
                        <td class="font-mono text-sm">
                            <span style="color: #ef4444;">
                                <i class="fas fa-bug mr-2"></i><?= htmlspecialchars($file['filename']) ?>
                            </span>
                        </td>
                        <td class="text-gray-300">
                            <?= $file['size_formatted'] ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-info">
                                <?= number_format($file['lines']) ?> righe
                            </span>
                        </td>
                        <td class="text-gray-400 text-sm">
                            <div><?= date('d/m/Y H:i', $file['modified']) ?></div>
                            <div class="text-xs text-gray-500"><?= $file['relative_time'] ?></div>
                        </td>
                        <td>
                            <div class="flex gap-2 flex-wrap">
                                <button onclick="viewLogFile('<?= htmlspecialchars($file['filename'], ENT_QUOTES) ?>')"
                                        class="btn btn-primary btn-sm" title="Visualizza Log">
                                    <i class="fas fa-eye mr-1"></i>Visualizza
                                </button>
                                <button onclick="downloadLog('<?= htmlspecialchars($file['filename'], ENT_QUOTES) ?>')"
                                        class="btn btn-secondary btn-sm" title="Scarica">
                                    <i class="fas fa-download mr-1"></i>Scarica
                                </button>
                                <button onclick="clearLog('<?= htmlspecialchars($file['filename'], ENT_QUOTES) ?>')"
                                        class="btn btn-warning btn-sm" title="Pulisci (backup ultime 100 righe)">
                                    <i class="fas fa-broom mr-1"></i>Pulisci
                                </button>
                                <button onclick="deleteLog('<?= htmlspecialchars($file['filename'], ENT_QUOTES) ?>')"
                                        class="btn btn-danger btn-sm" title="Elimina Permanentemente">
                                    <i class="fas fa-trash mr-1"></i>Elimina
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } else { ?>
<div class="card mb-6">
    <div class="alert alert-info">
        <div class="flex items-center gap-3">
            <i class="fas fa-info-circle text-blue-400 text-xl"></i>
            <div>
                <h4 class="text-white mb-1">Nessun File Log Errori JS Trovato</h4>
                <p class="text-sm text-gray-300">
                    Nessun log di errori JavaScript è stato ancora generato. I log appariranno qui quando verranno rilevati errori JS.
                </p>
                <p class="text-xs text-gray-400 mt-2">
                    <i class="fas fa-lightbulb mr-1"></i>
                    Per testare il sistema, clicca il pulsante "Pagina Test" sopra per generare errori di esempio.
                </p>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<!-- Database Errors Statistics (by PSR-3 Severity) -->
<div id="db-errors-stats" class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
    <div class="stat-card" data-stat="total">
        <span class="stat-value"><?= $total_errors ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Errori DB Totali
        </div>
    </div>

    <!-- EMERGENCY + ALERT + CRITICAL (Red) -->
    <div class="stat-card" style="border-left: 3px solid #dc2626;" data-stat="critical">
        <span class="stat-value" style="color: #ef4444;">
            <?= ($severity_counts['emergency'] ?? 0) + ($severity_counts['alert'] ?? 0) + ($severity_counts['critical'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-skull-crossbones mr-2"></i>🔴 EMG/ALRT/CRIT
        </div>
    </div>

    <!-- ERROR (Orange) -->
    <div class="stat-card" style="border-left: 3px solid #ea580c;" data-stat="error">
        <span class="stat-value" style="color: #f97316;"><?= $severity_counts['error'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-times-circle mr-2"></i>🟠 Error
        </div>
    </div>

    <!-- WARNING (Yellow) -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;" data-stat="warning">
        <span class="stat-value" style="color: #f59e0b;"><?= $severity_counts['warning'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>🟡 Warning
        </div>
    </div>

    <!-- NOTICE + INFO + DEBUG (Green) -->
    <div class="stat-card" style="border-left: 3px solid #10b981;" data-stat="info">
        <span class="stat-value" style="color: #10b981;">
            <?= ($severity_counts['notice'] ?? 0) + ($severity_counts['info'] ?? 0) + ($severity_counts['debug'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-info-circle mr-2"></i>🟢 NTC/INFO/DBG
        </div>
    </div>
</div>

<!-- JavaScript Errors Database Table -->
<div class="card">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-database mr-3"></i>Database Errori JavaScript (Totale: <?= $total_errors ?? 0 ?>)
        </span>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-300">Mostra:</label>
                <select id="errorsPerPage" class="form-control" style="width: auto; padding: 0.5rem;" onchange="changeErrorsPerPage()">
                    <option value="25" <?= ($limit ?? 50) == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= ($limit ?? 50) == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= ($limit ?? 50) == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-xs text-gray-400">per pagina</span>
            </div>
            <button onclick="refreshDatabaseErrors()" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 8px 16px;">
                <i class="fas fa-sync-alt mr-1"></i>Aggiorna Database
            </button>
        </div>
    </h3>

    <?php if (empty($errors)) { ?>
        <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3);">
            <div class="flex items-center gap-3">
                <i class="fas fa-check-circle text-green-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessun Errore nel Database</h4>
                    <p class="text-sm text-gray-300">Sistema in salute! Nessun errore JavaScript è stato archiviato nel database. 🎉</p>
                    <p class="text-xs text-gray-400 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Il database archivia dati di errore strutturati per l'analisi. Controlla i file log sopra per i log grezzi.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <!-- ENTERPRISE GALAXY: Sticky headers wrapper for better UX during scroll -->
        <div class="table-wrapper js-errors-table-container" style="border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 8px; background: rgba(26, 26, 46, 0.3);">
            <table class="js-errors-table sticky-header" style="font-size: 11px; width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr>
                        <th class="sticky-col" style="position: sticky; left: 0; z-index: 3; background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%); padding: 1rem 0.75rem; text-align: center; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #8b5cf6; white-space: nowrap;">ID</th>
                        <th class="sticky-col-2" style="position: sticky; left: 45px; z-index: 3; background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%); padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; white-space: nowrap; min-width: 110px;">Severità</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 120px;">Tipo</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 400px; max-width: 500px;">Messaggio</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 250px;">Nome File</th>
                        <th style="padding: 1rem; text-align: center; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap;">Riga:Col</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 350px; max-width: 450px;">Stack Trace</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 300px;">URL Pagina</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 250px;">User Agent</th>
                        <th style="padding: 1rem; text-align: center; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap;">ID Utente</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 150px;">Data/Ora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $err) {
                        $severity = $err['severity'] ? strtolower($err['severity']) : 'info';
                        // ENTERPRISE GALAXY: PSR-3 color hierarchy
                        $severityColors = [
                            'emergency' => '#7f1d1d',
                            'alert' => '#991b1b',
                            'critical' => '#dc2626',
                            'error' => '#ea580c',
                            'warning' => '#f59e0b',
                            'notice' => '#10b981',
                            'info' => '#3b82f6',
                            'debug' => '#8b5cf6',
                        ];
                        $severityColor = $severityColors[$severity] ?? $severityColors['info'];
                        $created = $err['created_at'] ? date('d/m/Y H:i:s', strtotime($err['created_at'])) : '-';
                        ?>
                    <tr class="error-row" style="border-bottom: 1px solid rgba(55, 65, 81, 0.2); background: rgba(26, 26, 46, 0.4); transition: all 0.2s ease;">
                        <!-- Sticky ID Column -->
                        <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem 0.75rem; text-align: center; font-weight: bold; color: #8b5cf6; border-right: 1px solid rgba(139, 92, 246, 0.2); font-size: 13px;">
                            #<?= htmlspecialchars($err['id'] ?? '-') ?>
                        </td>
                        <!-- Sticky Severity Column -->
                        <td class="sticky-col-2" style="position: sticky; left: 45px; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem; text-align: center; border-right: 1px solid rgba(139, 92, 246, 0.2);">
                            <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: <?= $severityColor ?>; color: #fff; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.5px;">
                                <?= strtoupper($severity) ?>
                            </span>
                        </td>
                        <!-- Type -->
                        <td style="padding: 1rem; vertical-align: top;">
                            <span class="text-xs px-2.5 py-1 rounded font-mono" style="background: rgba(239,68,68,0.15); color: #fca5a5; display: inline-block; border: 1px solid rgba(239,68,68,0.3);">
                                <?= htmlspecialchars($err['error_type'] ?? 'unknown') ?>
                            </span>
                        </td>
                        <!-- Message -->
                        <td style="padding: 1rem; color: #e5e5e5; line-height: 1.6; vertical-align: top; min-width: 400px; max-width: 500px;">
                            <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                                <?= nl2br(htmlspecialchars($err['message'] ?: '-')) ?>
                            </div>
                        </td>
                        <!-- Filename -->
                        <td style="padding: 1rem; color: #a8a8a8; font-family: 'Courier New', monospace; font-size: 10px; vertical-align: top; word-break: break-all; min-width: 250px;">
                            <?= htmlspecialchars($err['filename'] ?: '-') ?>
                        </td>
                        <!-- Line:Col -->
                        <td style="padding: 1rem; text-align: center; color: #c0c0c0; font-family: monospace; font-size: 11px; font-weight: 600; vertical-align: top; white-space: nowrap;">
                            <span style="background: rgba(139, 92, 246, 0.1); padding: 4px 8px; border-radius: 4px;">
                                <?= htmlspecialchars($err['line_number'] ?? '0') ?>:<?= htmlspecialchars($err['column_number'] ?? '0') ?>
                            </span>
                        </td>
                        <!-- Stack Trace -->
                        <td style="padding: 1rem; color: #d0d0d0; font-family: 'Courier New', monospace; font-size: 10px; line-height: 1.5; vertical-align: top; background: rgba(0,0,0,0.25); min-width: 350px; max-width: 450px;">
                            <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: pre-wrap;">
                                <?= htmlspecialchars($err['stack_trace'] ?: '-') ?>
                            </div>
                        </td>
                        <!-- Page URL -->
                        <td style="padding: 1rem; color: #60a5fa; font-family: monospace; font-size: 10px; vertical-align: top; word-break: break-all; min-width: 300px;">
                            <?php if ($err['page_url']) { ?>
                                <a href="<?= htmlspecialchars($err['page_url']) ?>" target="_blank" style="color: #60a5fa; text-decoration: underline; hover: color: #93c5fd;">
                                    <?= htmlspecialchars($err['page_url']) ?>
                                </a>
                            <?php } else { ?>
                                <span style="color: #666;">-</span>
                            <?php } ?>
                        </td>
                        <!-- User Agent -->
                        <td style="padding: 1rem; color: #a0a0a0; font-size: 10px; line-height: 1.4; vertical-align: top; word-break: break-word; min-width: 250px;">
                            <?= htmlspecialchars($err['user_agent'] ?: '-') ?>
                        </td>
                        <!-- User ID -->
                        <td style="padding: 1rem; text-align: center; color: #fbbf24; font-family: monospace; font-weight: 600; vertical-align: top; white-space: nowrap;">
                            <?= htmlspecialchars($err['user_id'] ?? '-') ?>
                        </td>
                        <!-- Timestamp -->
                        <td style="padding: 1rem; color: #b0b0b0; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                            <div><?= $created ?></div>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if (($total_pages ?? 1) > 1) { ?>
        <div class="flex justify-between items-center mt-4 px-4">
            <div class="text-sm text-gray-300">
                Visualizzazione pagina <?= $current_page ?? 1 ?> di <?= $total_pages ?? 1 ?>
                (<?= count($errors) ?> errori in questa pagina)
            </div>
            <div class="flex gap-2">
                <?php if (($current_page ?? 1) > 1) { ?>
                <button onclick="navigateToPage(<?= ($current_page ?? 1) - 1 ?>)" class="btn btn-secondary btn-sm">
                    <i class="fas fa-chevron-left mr-1"></i>Precedente
                </button>
                <?php } ?>

                <?php if (($current_page ?? 1) < ($total_pages ?? 1)) { ?>
                <button onclick="navigateToPage(<?= ($current_page ?? 1) + 1 ?>)" class="btn btn-secondary btn-sm">
                    Successiva<i class="fas fa-chevron-right ml-1"></i>
                </button>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <div class="mt-4 p-3 rounded" style="background: rgba(59, 130, 246, 0.1); border-left: 3px solid #3b82f6;">
            <div class="flex items-start gap-2">
                <i class="fas fa-info-circle text-blue-400"></i>
                <div class="text-sm text-gray-300">
                    <strong class="text-white">Archiviazione Database:</strong>
                    Gli errori sono archiviati nella tabella <code class="text-purple-400">enterprise_js_errors</code>.
                    Solo gli errori che corrispondono al livello di logging configurato vengono archiviati (vedi configurazione sopra).
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Log Viewer Modal -->
<div id="logViewerModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 90%; max-height: 90vh;">
        <div class="modal-header">
            <h3 id="logViewerTitle" class="flex items-center">
                <i class="fas fa-file-alt mr-3"></i>Visualizzatore Log
            </h3>
            <button class="modal-close" onclick="closeLogViewer()" aria-label="Chiudi finestra">&times;</button>
        </div>
        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
            <div class="flex justify-between items-center mb-3">
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-300">Righe per pagina:</label>
                    <select id="linesPerPage" class="form-control" style="width: auto; padding: 0.5rem;" onchange="changeLinesPerPage()">
                        <option value="50">50</option>
                        <option value="100" selected>100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                    </select>
                </div>
            </div>
            <div id="logViewerContent" class="font-mono text-sm" style="white-space: pre-wrap; background: #1a1a2e; padding: 1rem; border-radius: 8px;">
                <div class="text-center text-gray-400">
                    <i class="fas fa-spinner fa-spin mr-2"></i>Caricamento contenuto log...
                </div>
            </div>
        </div>
        <div class="modal-footer flex justify-between items-center">
            <div id="logPagination" class="flex gap-2"></div>
            <button class="btn btn-secondary" onclick="closeLogViewer()">Chiudi</button>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
/* ENTERPRISE: Sticky Table Headers - Same as Email Metrics & Audit Log */
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

/* ENTERPRISE: Custom Scrollbar - Purple Theme */
.table-wrapper::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

.table-wrapper::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 5px;
}

.table-wrapper::-webkit-scrollbar-thumb {
    background: rgba(147, 51, 234, 0.5);
    border-radius: 5px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
    background: rgba(147, 51, 234, 0.7);
}

/* Firefox scrollbar */
.table-wrapper {
    scrollbar-width: thin;
    scrollbar-color: rgba(147, 51, 234, 0.5) rgba(0, 0, 0, 0.2);
}

/* Enterprise Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(10px);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}

.modal-content {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border-radius: 16px;
    border: 1px solid rgba(139, 92, 246, 0.3);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    animation: slideUp 0.3s ease;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid rgba(139, 92, 246, 0.2);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    color: #ffffff;
    margin: 0;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid rgba(139, 92, 246, 0.2);
}

.modal-close {
    background: none;
    border: none;
    color: #e0e0e0;
    font-size: 2rem;
    cursor: pointer;
    line-height: 1;
    transition: color 0.3s ease;
}

.modal-close:hover {
    color: #8b5cf6;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Log line highlighting */
.log-line {
    padding: 0.25rem 0;
    line-height: 1.6;
}

.log-line-error {
    background: rgba(239, 68, 68, 0.1);
    border-left: 3px solid #ef4444;
    padding-left: 0.5rem;
    color: #fca5a5;
}

.log-line-warning {
    background: rgba(251, 191, 36, 0.1);
    border-left: 3px solid #fbbf24;
    padding-left: 0.5rem;
    color: #fde68a;
}

.log-line-success {
    background: rgba(34, 197, 94, 0.1);
    border-left: 3px solid #22c55e;
    padding-left: 0.5rem;
    color: #86efac;
}
</style>

<script nonce="<?= csp_nonce() ?>">
// Global variables
let currentLogFile = '';
let currentLogPage = 1;
let currentLinesPerPage = 100;

// View log file
function viewLogFile(filename, page = 1) {
    currentLogFile = filename;
    currentLogPage = page;

    // Get lines per page from selector
    const linesPerPageSelect = document.getElementById('linesPerPage');
    if (linesPerPageSelect) {
        currentLinesPerPage = parseInt(linesPerPageSelect.value) || 100;
    }

    // Show modal
    document.getElementById('logViewerModal').style.display = 'flex';
    document.getElementById('logViewerTitle').innerHTML = `<i class="fas fa-file-alt mr-3"></i>${filename}`;
    document.getElementById('logViewerContent').innerHTML = '<div class="text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Caricamento contenuto log...</div>';

    // Fetch log content
    fetch(`system-action?action=view_log&filename=${encodeURIComponent(filename)}&page=${page}&lines_per_page=${currentLinesPerPage}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Display log content
                document.getElementById('logViewerContent').innerHTML = data.content || '<div class="text-gray-400">File log vuoto</div>';

                // Generate pagination
                generateLogPagination(data.current_page, data.total_pages);
            } else {
                document.getElementById('logViewerContent').innerHTML = `<div class="text-red-400"><i class="fas fa-exclamation-triangle mr-2"></i>${data.error || 'Impossibile caricare il file log'}</div>`;
            }
        })
        .catch(error => {
            document.getElementById('logViewerContent').innerHTML = `<div class="text-red-400"><i class="fas fa-exclamation-triangle mr-2"></i>Errore: ${error.message}</div>`;
        });
}

// Change lines per page and reload current log
function changeLinesPerPage() {
    if (currentLogFile) {
        viewLogFile(currentLogFile, 1); // Reset to page 1 when changing lines per page
    }
}

// Generate pagination for log viewer
function generateLogPagination(currentPage, totalPages) {
    const container = document.getElementById('logPagination');
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';

    // Previous button
    if (currentPage > 1) {
        html += `<button class="btn btn-secondary btn-sm" onclick="viewLogFile('${currentLogFile}', ${currentPage - 1})"><i class="fas fa-chevron-left"></i> Precedente</button>`;
    }

    // Page info
    html += `<span class="text-gray-300 px-4 flex items-center">Pagina ${currentPage} di ${totalPages}</span>`;

    // Next button
    if (currentPage < totalPages) {
        html += `<button class="btn btn-secondary btn-sm" onclick="viewLogFile('${currentLogFile}', ${currentPage + 1})">Successiva <i class="fas fa-chevron-right"></i></button>`;
    }

    container.innerHTML = html;
}

// Close log viewer
function closeLogViewer() {
    document.getElementById('logViewerModal').style.display = 'none';
}

// Download log
function downloadLog(filename) {
    window.location.href = `system-action?action=download_log&filename=${encodeURIComponent(filename)}`;
}

// Delete log
function deleteLog(filename) {
    if (!confirm(`⚠️ Sei sicuro di voler eliminare PERMANENTEMENTE "${filename}"?\n\nQuesta azione non può essere annullata.`)) {
        return;
    }

    fetch(`system-action?action=delete_log&filename=${encodeURIComponent(filename)}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ ${data.message || 'File log eliminato con successo'}`);
            location.reload();
        } else {
            alert(`❌ ${data.error || 'Impossibile eliminare il file log'}`);
        }
    })
    .catch(error => {
        alert(`❌ Errore: ${error.message}`);
    });
}

// Clear log
function clearLog(filename) {
    if (!confirm(`🧹 Pulire "${filename}"?\n\n⚠️ Le ultime 100 righe verranno automaticamente salvate in backup in:\n/storage/logs/backups/${filename}_backup\n\nContinuare?`)) {
        return;
    }

    fetch(`system-action?action=clear_log&filename=${encodeURIComponent(filename)}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ ${data.message || 'File log pulito con successo'}\n\n💾 Backup salvato in: ${data.backup_file || 'directory backups'}`);
            location.reload();
        } else {
            alert(`❌ ${data.error || 'Impossibile pulire il file log'}`);
        }
    })
    .catch(error => {
        alert(`❌ Errore: ${error.message}`);
    });
}

// Close modals when clicking outside
window.onclick = function(event) {
    const logModal = document.getElementById('logViewerModal');

    if (event.target === logModal) {
        closeLogViewer();
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // ESC to close modals
    if (event.key === 'Escape') {
        closeLogViewer();
    }
});

// Refresh entire page (reload all data: log files + database)
function refreshPage() {
    // Show loading state
    const button = event.target.closest('button');
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Aggiornamento...';
    }

    location.reload();
}

// ENTERPRISE GALAXY: Refresh database errors table (real-time update)
async function refreshDatabaseErrors() {
    console.debug('🔄 Refreshing database errors...');

    // Get current pagination settings
    const errorsPerPageSelect = document.getElementById('errorsPerPage');
    const limit = errorsPerPageSelect ? parseInt(errorsPerPageSelect.value) : 50;

    // Show loading state
    const button = event.target.closest('button');
    const originalHTML = button ? button.innerHTML : '';
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Aggiornamento...';
    }

    try {
        // ENTERPRISE GALAXY: Extract admin URL hash from current path
        const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
        const adminHash = pathMatch ? pathMatch[1] : '';

        if (!adminHash) {
            throw new Error('Admin URL hash not found in current path');
        }

        // ENTERPRISE TIPS: Use admin API endpoint with cache-busting
        const timestamp = Date.now();
        const protocol = window.location.protocol; // http: or https:
        const host = window.location.host;
        const url = `${protocol}//${host}/admin_${adminHash}/api/js-errors/database?limit=${limit}&_=${timestamp}`;

        console.debug('📡 Fetching:', url);

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            credentials: 'same-origin'
        });

        console.debug('📥 Response status:', response.status);

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();
        console.debug('📦 Data received:', data);
        console.debug('✅ Errors count:', data.errors ? data.errors.length : 0, '/', data.total);

        if (data.success) {
            // Update total count in header
            const headerTitle = document.querySelector('.card h3 span');
            if (headerTitle && headerTitle.textContent.includes('Total:')) {
                headerTitle.innerHTML = `<i class="fas fa-database mr-3"></i>JavaScript Errors Database (Total: ${data.total})`;
            }

            // Update severity counts
            updateSeverityCounts(data.severity_counts);

            // Update table
            updateErrorsTable(data.errors);

            // Update pagination
            updatePagination(data.page, data.total_pages);

            // Show success message
            console.info(`✅ Database refreshed successfully! Total: ${data.total}, Shown: ${data.errors.length}`);

            // Visual feedback
            if (button) {
                button.innerHTML = '<i class="fas fa-check mr-1"></i>Aggiornato!';
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                }, 1500);
            }
        } else {
            throw new Error(data.message || 'Impossibile caricare gli errori');
        }

    } catch (error) {
        console.error('❌ Failed to refresh database errors:', error);
        alert('❌ Impossibile aggiornare gli errori del database: ' + error.message);

        if (button) {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }
}

// Update severity count cards
function updateSeverityCounts(counts) {
    // ENTERPRISE TIPS: Use specific selectors for database errors stats only (not all stat-cards!)
    const dbStatsGrid = document.getElementById('db-errors-stats');
    if (!dbStatsGrid) {
        console.warn('⚠️ Database stats grid not found');
        return;
    }

    // Total DB Errors
    const totalCard = dbStatsGrid.querySelector('[data-stat="total"] .stat-value');
    if (totalCard) {
        const total = Object.values(counts).reduce((sum, val) => sum + val, 0);
        totalCard.textContent = total;
    }

    // Emergency + Alert + Critical (Red)
    const criticalCard = dbStatsGrid.querySelector('[data-stat="critical"] .stat-value');
    if (criticalCard) {
        const critical = (counts['emergency'] || 0) + (counts['alert'] || 0) + (counts['critical'] || 0);
        criticalCard.textContent = critical;
    }

    // Error (Orange)
    const errorCard = dbStatsGrid.querySelector('[data-stat="error"] .stat-value');
    if (errorCard) {
        errorCard.textContent = counts['error'] || 0;
    }

    // Warning (Yellow)
    const warningCard = dbStatsGrid.querySelector('[data-stat="warning"] .stat-value');
    if (warningCard) {
        warningCard.textContent = counts['warning'] || 0;
    }

    // Notice + Info + Debug (Green)
    const infoCard = dbStatsGrid.querySelector('[data-stat="info"] .stat-value');
    if (infoCard) {
        const info = (counts['notice'] || 0) + (counts['info'] || 0) + (counts['debug'] || 0);
        infoCard.textContent = info;
    }

    console.debug('✅ Severity counts updated:', counts);
}

// Update errors table with fresh data
function updateErrorsTable(errors) {
    const tbody = document.querySelector('.js-errors-table tbody');
    if (!tbody) {
        console.warn('⚠️ Table tbody not found');
        return;
    }

    if (errors.length === 0) {
        // Show "no errors" message
        const card = tbody.closest('.card');
        if (card) {
            card.innerHTML = `
                <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3);">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-green-400 text-2xl"></i>
                        <div>
                            <h4 class="text-white mb-1">Nessun Errore nel Database</h4>
                            <p class="text-sm text-gray-300">Sistema in salute! Nessun errore JavaScript è stato archiviato nel database. 🎉</p>
                        </div>
                    </div>
                </div>
            `;
        }
        return;
    }

    // Generate table rows
    const severityColors = {
        'emergency': '#7f1d1d',
        'alert': '#991b1b',
        'critical': '#dc2626',
        'error': '#ea580c',
        'warning': '#f59e0b',
        'notice': '#10b981',
        'info': '#3b82f6',
        'debug': '#8b5cf6'
    };

    const rows = errors.map(err => {
        const severity = (err.severity || 'info').toLowerCase();
        const severityColor = severityColors[severity] || severityColors['info'];
        const created = err.created_at ? new Date(err.created_at).toLocaleString('it-IT') : '-';

        return `
            <tr class="error-row" style="border-bottom: 1px solid rgba(55, 65, 81, 0.2); background: rgba(26, 26, 46, 0.4); transition: all 0.2s ease;">
                <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem 0.75rem; text-align: center; font-weight: bold; color: #8b5cf6; border-right: 1px solid rgba(139, 92, 246, 0.2); font-size: 13px;">
                    #${err.id || '-'}
                </td>
                <td class="sticky-col-2" style="position: sticky; left: 45px; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem; text-align: center; border-right: 1px solid rgba(139, 92, 246, 0.2);">
                    <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: ${severityColor}; color: #fff; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.5px;">
                        ${severity.toUpperCase()}
                    </span>
                </td>
                <td style="padding: 1rem; vertical-align: top;">
                    <span class="text-xs px-2.5 py-1 rounded font-mono" style="background: rgba(239,68,68,0.15); color: #fca5a5; display: inline-block; border: 1px solid rgba(239,68,68,0.3);">
                        ${escapeHtml(err.error_type || 'unknown')}
                    </span>
                </td>
                <td style="padding: 1rem; color: #e5e5e5; line-height: 1.6; vertical-align: top; min-width: 400px; max-width: 500px;">
                    <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                        ${escapeHtml(err.message || '-').replace(/\n/g, '<br>')}
                    </div>
                </td>
                <td style="padding: 1rem; color: #a8a8a8; font-family: 'Courier New', monospace; font-size: 10px; vertical-align: top; word-break: break-all; min-width: 250px;">
                    ${escapeHtml(err.filename || '-')}
                </td>
                <td style="padding: 1rem; text-align: center; color: #c0c0c0; font-family: monospace; font-size: 11px; font-weight: 600; vertical-align: top; white-space: nowrap;">
                    <span style="background: rgba(139, 92, 246, 0.1); padding: 4px 8px; border-radius: 4px;">
                        ${err.line_number || 0}:${err.column_number || 0}
                    </span>
                </td>
                <td style="padding: 1rem; color: #d0d0d0; font-family: 'Courier New', monospace; font-size: 10px; line-height: 1.5; vertical-align: top; background: rgba(0,0,0,0.25); min-width: 350px; max-width: 450px;">
                    <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: pre-wrap;">
                        ${escapeHtml(err.stack_trace || '-')}
                    </div>
                </td>
                <td style="padding: 1rem; color: #60a5fa; font-family: monospace; font-size: 10px; vertical-align: top; word-break: break-all; min-width: 300px;">
                    ${err.page_url ? `<a href="${escapeHtml(err.page_url)}" target="_blank" style="color: #60a5fa; text-decoration: underline;">${escapeHtml(err.page_url)}</a>` : '<span style="color: #666;">-</span>'}
                </td>
                <td style="padding: 1rem; color: #a0a0a0; font-size: 10px; line-height: 1.4; vertical-align: top; word-break: break-word; min-width: 250px;">
                    ${escapeHtml(err.user_agent || '-')}
                </td>
                <td style="padding: 1rem; text-align: center; color: #fbbf24; font-family: monospace; font-weight: 600; vertical-align: top; white-space: nowrap;">
                    ${escapeHtml(err.user_id || '-')}
                </td>
                <td style="padding: 1rem; color: #b0b0b0; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                    <div>${created}</div>
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
    console.debug(`✅ Table updated with ${errors.length} rows`);
}

// Update pagination controls
function updatePagination(currentPage, totalPages) {
    const paginationDiv = document.querySelector('.flex.justify-between.items-center.mt-4');
    if (!paginationDiv) return;

    if (totalPages <= 1) {
        paginationDiv.style.display = 'none';
        return;
    }

    paginationDiv.style.display = 'flex';

    // Update page info
    const pageInfo = paginationDiv.querySelector('.text-sm.text-gray-300');
    if (pageInfo) {
        pageInfo.textContent = `Visualizzazione pagina ${currentPage} di ${totalPages}`;
    }
}

// Helper: Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Change errors per page and reload
function changeErrorsPerPage() {
    const limit = document.getElementById('errorsPerPage').value;
    window.location.href = `?limit=${limit}`;
}

// Navigate to specific page
function navigateToPage(page) {
    const limit = document.getElementById('errorsPerPage')?.value || 50;
    window.location.href = `?limit=${limit}&page=${page}`;
}

// ENTERPRISE GALAXY: Save JS Errors logging configuration
function saveJsErrorsLoggingConfig() {
    const levelSelect = document.getElementById('level_js_errors');
    const rollbackInput = document.getElementById('rollback_js_errors');

    if (!levelSelect) {
        alert('❌ Errore: Impossibile trovare il selettore di livello');
        return;
    }

    const level = levelSelect.value;
    const autoRollbackMinutes = rollbackInput.value ? parseInt(rollbackInput.value) : null;

    // Validate rollback minutes
    if (autoRollbackMinutes !== null && (autoRollbackMinutes < 5 || autoRollbackMinutes > 1440)) {
        alert('❌ L\'auto-rollback deve essere tra 5 e 1440 minuti (24 ore)');
        return;
    }

    // Confirm if enabling DEBUG level
    if (level === 'debug' && !confirm('⚠️ ATTENZIONE: Abilitare il livello DEBUG per errori JS può aumentare il volume dei log\n\n' +
        (autoRollbackMinutes ? `L'auto-rollback è impostato a ${autoRollbackMinutes} minuti.\n\n` : 'Considera di impostare l\'auto-rollback per sicurezza in produzione.\n\n') +
        'Continuare?')) {
        return;
    }

    // Show loading state
    const button = document.querySelector('button[onclick*="saveJsErrorsLoggingConfig"]');
    if (!button) {
        alert('❌ Errore: Impossibile trovare il pulsante salva');
        return;
    }
    const originalHTML = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Salvataggio...';

    // Prepare data
    const data = new FormData();
    data.append('channel', 'js_errors');
    data.append('level', level);
    if (autoRollbackMinutes) {
        data.append('auto_rollback_minutes', autoRollbackMinutes);
    }
    data.append('reason', 'Manual configuration update via JS Errors admin panel');

    // SECURITY: Add CSRF token for POST protection
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        data.append('_csrf_token', csrfToken);
    }

    // Send request
    fetch('system-action?action=update_logging_config', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        button.disabled = false;
        button.innerHTML = originalHTML;

        if (data.success) {
            const message = `✅ Configurazione logging Errori JS aggiornata con successo!\n\n` +
                `📊 Canale: js_errors\n` +
                `📈 Livello: ${data.previous_level} → ${data.level}\n` +
                (data.auto_rollback_at ? `🕐 Auto-rollback alle: ${data.auto_rollback_at}\n` : '') +
                `\n🚀 Le modifiche sono attive immediatamente (nessun riavvio richiesto)`;

            alert(message);
            location.reload();
        } else {
            alert(`❌ Impossibile aggiornare la configurazione logging\n\nErrore: ${data.error || 'Errore sconosciuto'}`);
        }
    })
    .catch(error => {
        button.disabled = false;
        button.innerHTML = originalHTML;
        alert(`❌ Errore di rete: ${error.message}`);
    });
}

// ENTERPRISE GALAXY: Database filter is now configured in Settings tab only (removed from here)

console.info('✅ Enterprise JS Error Monitoring loaded');
</script>
