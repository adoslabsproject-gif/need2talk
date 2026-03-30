<!-- ENTERPRISE GALAXY LOGS VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-file-alt mr-3"></i>
    Gestione Log di Sistema
</h2>

<!-- ENTERPRISE GALAXY: Dynamic Logging Configuration -->
<div class="card mb-6" style="border: 2px solid rgba(139, 92, 246, 0.3);">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-sliders-h mr-3"></i>Configurazione Logging Dinamica
            <span class="badge badge-success ml-2" style="font-size: 0.7rem; vertical-align: middle;">ENTERPRISE GALAXY</span>
        </span>
        <span class="text-xs text-gray-400">
            <i class="fas fa-shield-alt mr-1"></i>ISO 27001 | GDPR | SOC 2 Compliant
        </span>
    </h3>

    <div class="alert alert-info mb-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-400 text-xl"></i>
            <div class="flex-1">
                <h4 class="text-white mb-2">🚀 Configurazione Zero-Downtime</h4>
                <p class="text-sm text-gray-300 mb-2">
                    Modifica i livelli di logging <strong>senza riavviare</strong> PHP-FPM o i worker.
                    Perfetto per il debugging in produzione con milioni di utenti.
                </p>
                <ul class="text-xs text-gray-400 space-y-1">
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Attivazione istantanea (cache Redis L1)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Meccanismo di rollback automatico</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Tracciamento completo per conformità</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Controllo granulare per canale</li>
                    <li><i class="fas fa-info-circle text-blue-400 mr-2"></i>Le modifiche si propagano immediatamente alle richieste web; i worker in background si sincronizzano entro 60 secondi</li>
                </ul>
            </div>
        </div>
    </div>

    <?php
    // Load current logging configuration
    $loggingConfig = [];

    // ENTERPRISE GALAXY: Channel-specific level restrictions
    // debug_general: debug, info, notice (for development/debugging)
    // default: warning, error, critical, alert, emergency (for production errors)
    // Other channels: all levels
    $debugLevels = ['debug', 'info', 'notice'];
    $errorLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
    $allLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

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
            // ENTERPRISE GALAXY: Admin panel always reads fresh data from DB (skipCache=true)
            $loggingConfig = $loggingService->getConfiguration(skipCache: true);
        }
    } catch (\Exception $e) {
        $loggingConfig = [];
    }
    ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php
        $channels = [
            'default' => ['icon' => 'fa-exclamation-triangle', 'description' => 'Errori e warning (warning+). Non può essere disattivato.'],
            'debug_general' => ['icon' => 'fa-bug', 'description' => 'Debug e sviluppo (debug/info/notice). Disattiva in produzione.'],
            'security' => ['icon' => 'fa-shield-alt', 'description' => 'Log di sicurezza e audit'],
            'performance' => ['icon' => 'fa-tachometer-alt', 'description' => 'Metriche di performance'],
            'database' => ['icon' => 'fa-database', 'description' => 'Log delle query database'],
            'email' => ['icon' => 'fa-envelope', 'description' => 'Log della coda email'],
            'api' => ['icon' => 'fa-exchange-alt', 'description' => 'Log richieste/risposte API'],
            'audio' => ['icon' => 'fa-microphone', 'description' => 'Log elaborazione audio (upload, workers, S3)'],
            'websocket' => ['icon' => 'fa-plug', 'description' => 'Log server WebSocket (connessioni, eventi, PubSub)'],
            'overlay' => ['icon' => 'fa-layer-group', 'description' => 'Log cache overlay (visualizzazioni, reazioni, amicizie, flush)'],
        ];

    foreach ($channels as $channel => $info) {
        $currentConfig = $loggingConfig[$channel] ?? null;
        $currentLevel = $currentConfig['level'] ?? 'warning';
        $autoRollbackAt = $currentConfig['auto_rollback_at'] ?? null;

        // ENTERPRISE GALAXY: Determine available levels for this channel
        if ($channel === 'default') {
            $channelLevels = $errorLevels; // warning, error, critical, alert, emergency
        } elseif ($channel === 'debug_general') {
            $channelLevels = $debugLevels; // debug, info, notice
            // Toggle logic: if level is warning (or higher), debug_general is OFF
            $isDebugEnabled = in_array($currentLevel, $debugLevels);
            // If disabled, show 'info' as default selection when re-enabled
            $displayLevel = $isDebugEnabled ? $currentLevel : 'info';
        } else {
            $channelLevels = $allLevels; // All levels for other channels
        }
        ?>
            <div class="p-4 rounded-lg" style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%); border: 1px solid rgba(139, 92, 246, 0.2);">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <i class="fas <?= $info['icon'] ?> text-purple-400"></i>
                        <span class="font-semibold text-white"><?= ucfirst(str_replace('_', ' ', $channel)) ?></span>
                    </div>
                    <?php if ($channel === 'debug_general') { ?>
                        <span class="text-xs px-2 py-1 rounded" style="background: <?= $isDebugEnabled ? $levelColors[$currentLevel] : '#374151' ?>; color: #fff;">
                            <?= $isDebugEnabled ? strtoupper($currentLevel) : 'OFF' ?>
                        </span>
                    <?php } else { ?>
                        <span class="text-xs px-2 py-1 rounded" style="background: <?= $levelColors[$currentLevel] ?>; color: #fff;">
                            <?= strtoupper($currentLevel) ?>
                        </span>
                    <?php } ?>
                </div>

                <p class="text-xs text-gray-400 mb-3"><?= $info['description'] ?></p>

                <?php if ($channel === 'debug_general') { ?>
                <!-- TOGGLE for debug_general -->
                <div class="mb-3 flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="toggle_<?= $channel ?>" class="sr-only peer" <?= $isDebugEnabled ? 'checked' : '' ?> onchange="toggleDebugGeneral(this.checked)">
                        <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                    </label>
                    <span class="text-sm text-gray-300" id="toggle_label_<?= $channel ?>"><?= $isDebugEnabled ? 'Attivo' : 'Disattivato' ?></span>
                </div>
                <?php } ?>

                <div class="mb-3" id="level_container_<?= $channel ?>" <?= ($channel === 'debug_general' && !$isDebugEnabled) ? 'style="opacity: 0.5; pointer-events: none;"' : '' ?>>
                    <label class="text-xs text-gray-300 mb-1 block">Livello Log</label>
                    <select id="level_<?= $channel ?>" class="form-control text-sm" style="padding: 0.5rem;">
                        <?php foreach ($channelLevels as $level) { ?>
                            <option value="<?= $level ?>" <?= ($channel === 'debug_general' ? $displayLevel : $currentLevel) === $level ? 'selected' : '' ?>>
                                <?= strtoupper($level) ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="text-xs text-gray-300 mb-1 block">
                        <i class="fas fa-clock mr-1"></i>Auto-Rollback (minuti)
                    </label>
                    <input type="number" id="rollback_<?= $channel ?>" class="form-control text-sm" style="padding: 0.5rem;" placeholder="Opzionale (5-1440)" min="5" max="1440">
                    <span class="text-xs text-gray-500">Lascia vuoto per modifica permanente</span>
                </div>

                <?php if ($autoRollbackAt) { ?>
                <div class="alert alert-warning p-2 mb-2 text-xs">
                    <i class="fas fa-undo-alt mr-1"></i>
                    Auto-rollback alle: <?= date('d/m/Y H:i', strtotime($autoRollbackAt)) ?>
                </div>
                <?php } ?>

                <button onclick="saveLoggingConfig('<?= $channel ?>')" class="btn btn-primary btn-sm w-full">
                    <i class="fas fa-save mr-1"></i>Salva
                </button>
            </div>
        <?php } ?>
    </div>

    <div class="mt-4 p-3 rounded" style="background: rgba(251, 191, 36, 0.1); border-left: 3px solid #f59e0b;">
        <div class="flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
            <div class="text-sm text-gray-300">
                <strong class="text-white">Avviso Impatto Performance:</strong>
                Abilitare il livello <code class="text-purple-400">DEBUG</code> può ridurre le performance del ~20-30%.
                Usa l'auto-rollback per debugging temporaneo in produzione.
            </div>
        </div>
    </div>
</div>

<!-- Summary Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $system_logs['total_files'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-file-alt mr-2"></i>File Log Totali
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $system_logs['total_size_formatted'] ?? '0 B' ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Dimensione Totale
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= count($system_logs['categories'] ?? []) ?></span>
        <div class="stat-label">
            <i class="fas fa-folder mr-2"></i>Categorie
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value">
            <button onclick="archiveLogsPrompt()" class="btn btn-warning btn-sm">
                <i class="fas fa-archive mr-2"></i>Archivia Log Vecchi
            </button>
        </span>
        <div class="stat-label">Azioni Rapide</div>
    </div>
</div>

<!-- Search & Filter Interface -->
<div class="card mb-6">
    <h3 class="flex items-center">
        <i class="fas fa-search mr-3"></i>Cerca nei Log
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="md:col-span-2">
            <label for="search_pattern" class="form-label">Pattern di Ricerca</label>
            <input type="text" id="search_pattern" class="form-control" placeholder="Inserisci termine di ricerca o pattern regex...">
        </div>
        <div>
            <label for="search_file" class="form-label">File Log</label>
            <select id="search_file" class="form-control">
                <option value="">-- Tutti i File --</option>
                <?php foreach ($system_logs['files'] ?? [] as $file) { ?>
                    <option value="<?= htmlspecialchars($file['filename']) ?>">
                        <?= htmlspecialchars($file['filename']) ?>
                    </option>
                <?php } ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button onclick="performSearch()" class="btn btn-primary">
                <i class="fas fa-search mr-2"></i>Cerca
            </button>
            <button onclick="clearSearchResults()" class="btn btn-secondary" style="background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);">
                <i class="fas fa-times mr-2"></i>Pulisci
            </button>
            <label class="flex items-center text-sm text-gray-300">
                <input type="checkbox" id="case_sensitive" class="mr-2">
                Case Sensitive
            </label>
        </div>
    </div>
    <div id="search_results" class="mt-4" style="display: none;"></div>
</div>

<!-- Log Categories -->
<?php if (!empty($system_logs['categories'])) { ?>
<div class="card mb-6">
    <h3 class="flex items-center">
        <i class="fas fa-folder-open mr-3"></i>Categorie Log
    </h3>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <?php foreach ($system_logs['categories'] as $category => $data) { ?>
            <div class="p-4 rounded-lg" style="background: linear-gradient(135deg, rgba(<?= $data['color'] === 'red' ? '239, 68, 68' : ($data['color'] === 'yellow' ? '251, 191, 36' : ($data['color'] === 'blue' ? '59, 130, 246' : ($data['color'] === 'green' ? '34, 197, 94' : '156, 163, 175'))) ?>, 0.1) 0%, rgba(<?= $data['color'] === 'red' ? '239, 68, 68' : ($data['color'] === 'yellow' ? '251, 191, 36' : ($data['color'] === 'blue' ? '59, 130, 246' : ($data['color'] === 'green' ? '34, 197, 94' : '156, 163, 175'))) ?>, 0.05) 100%); border: 1px solid rgba(<?= $data['color'] === 'red' ? '239, 68, 68' : ($data['color'] === 'yellow' ? '251, 191, 36' : ($data['color'] === 'blue' ? '59, 130, 246' : ($data['color'] === 'green' ? '34, 197, 94' : '156, 163, 175'))) ?>, 0.2);">
                <div class="text-2xl mb-2"><?= $data['icon'] ?></div>
                <div class="text-sm font-medium text-gray-300"><?= ucfirst($category) ?></div>
                <div class="text-xs text-gray-400 mt-1"><?= $data['count'] ?? 0 ?> file</div>
            </div>
        <?php } ?>
    </div>
</div>
<?php } ?>

<!-- Log Files Table -->
<?php if (!empty($system_logs['files'])) { ?>
<div class="card">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-list mr-3"></i>File Log
        </span>
        <span class="text-xs text-gray-400">
            <i class="fas fa-check-square mr-1"></i>Seleziona più file per azioni di massa
        </span>
    </h3>

    <!-- ENTERPRISE GALAXY: Bulk Actions Toolbar -->
    <div id="bulkActionsToolbar" class="mb-4 p-4 rounded-lg" style="display: none; background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%); border: 1px solid rgba(139, 92, 246, 0.4);">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <span class="text-white font-semibold">
                    <i class="fas fa-check-circle text-purple-400 mr-2"></i>
                    <span id="selectedCount">0</span> file selezionati
                </span>
                <button onclick="clearSelection()" class="btn btn-sm" style="background: rgba(107, 114, 128, 0.5);" title="Deseleziona tutto">
                    <i class="fas fa-times mr-1"></i>Deseleziona
                </button>
            </div>
            <div class="flex gap-2 flex-wrap">
                <button onclick="bulkDownload()" class="btn btn-secondary btn-sm" title="Scarica file selezionati come ZIP">
                    <i class="fas fa-download mr-1"></i>Scarica Selezionati
                </button>
                <button onclick="bulkClear()" class="btn btn-warning btn-sm" title="Pulisci file selezionati (con backup)">
                    <i class="fas fa-broom mr-1"></i>Pulisci Selezionati
                </button>
                <button onclick="bulkDelete()" class="btn btn-danger btn-sm" title="Elimina file selezionati">
                    <i class="fas fa-trash mr-1"></i>Elimina Selezionati
                </button>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;" class="text-center">
                        <label class="inline-flex items-center cursor-pointer" title="Seleziona/Deseleziona tutti">
                            <input type="checkbox" id="selectAllLogs" onchange="toggleSelectAll(this.checked)" class="form-checkbox h-5 w-5 text-purple-600 rounded border-gray-600 bg-gray-700 focus:ring-purple-500">
                        </label>
                    </th>
                    <th style="width: 5%;">Categoria</th>
                    <th style="width: 22%;">Nome File</th>
                    <th style="width: 10%;">Dimensione</th>
                    <th style="width: 10%;">Righe</th>
                    <th style="width: 15%;">Ultima Modifica</th>
                    <th style="width: 35%;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($system_logs['files'] as $file) {
                    $isProtected = in_array($file['filename'], ['php_errors.log', 'need2talk.log']);
                ?>
                    <tr id="row_<?= md5($file['filename']) ?>" class="log-row <?= $isProtected ? 'protected-log' : '' ?>">
                        <td class="text-center">
                            <?php if (!$isProtected) { ?>
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="checkbox"
                                       class="log-checkbox form-checkbox h-5 w-5 text-purple-600 rounded border-gray-600 bg-gray-700 focus:ring-purple-500"
                                       data-filename="<?= htmlspecialchars($file['filename'], ENT_QUOTES) ?>"
                                       onchange="updateBulkSelection()">
                            </label>
                            <?php } else { ?>
                            <span class="text-gray-500" title="File protetto">
                                <i class="fas fa-lock"></i>
                            </span>
                            <?php } ?>
                        </td>
                        <td class="text-center">
                            <span title="<?= ucfirst($file['category']) ?>" style="font-size: 1.5rem;">
                                <?= $file['category_icon'] ?>
                            </span>
                        </td>
                        <td class="font-mono text-sm">
                            <span style="color: <?= $file['category_color'] ?>;">
                                <?= htmlspecialchars($file['filename']) ?>
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
                                <?php if (!$isProtected) { ?>
                                <button onclick="deleteLog('<?= htmlspecialchars($file['filename'], ENT_QUOTES) ?>')"
                                        class="btn btn-danger btn-sm" title="Elimina Permanentemente">
                                    <i class="fas fa-trash mr-1"></i>Elimina
                                </button>
                                <?php } else { ?>
                                <button class="btn btn-secondary btn-sm" disabled title="File protetto - non può essere eliminato">
                                    <i class="fas fa-lock mr-1"></i>Protetto
                                </button>
                                <?php } ?>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } else { ?>
<div class="card">
    <div class="alert alert-info">
        <h4>Nessun File Log Trovato</h4>
        <p>Nessun file log è stato trovato nel sistema. I log appariranno qui non appena verranno generati.</p>
    </div>
</div>
<?php } ?>

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

<!-- Search Results Modal -->
<div id="searchResultsModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 90%; max-height: 90vh;">
        <div class="modal-header">
            <h3 id="searchResultsTitle" class="flex items-center">
                <i class="fas fa-search mr-3"></i>Risultati Ricerca
            </h3>
            <button class="modal-close" onclick="closeSearchResults()" aria-label="Chiudi finestra">&times;</button>
        </div>
        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
            <div id="searchResultsContent" class="font-mono text-sm"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeSearchResults()">Chiudi</button>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
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

/* Search result highlighting */
.search-match {
    background: rgba(251, 191, 36, 0.3);
    color: #fbbf24;
    font-weight: bold;
    padding: 2px 4px;
    border-radius: 3px;
}

.search-result-item {
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    background: rgba(139, 92, 246, 0.05);
    border-left: 3px solid #8b5cf6;
    border-radius: 4px;
}

.search-result-meta {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-bottom: 0.5rem;
}

/* ENTERPRISE GALAXY: Multi-select styling */
.form-checkbox {
    appearance: none;
    -webkit-appearance: none;
    width: 1.25rem;
    height: 1.25rem;
    border: 2px solid #4b5563;
    border-radius: 4px;
    background: #374151;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
}

.form-checkbox:checked {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    border-color: #8b5cf6;
}

.form-checkbox:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 0.875rem;
    font-weight: bold;
}

.form-checkbox:hover {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
}

.form-checkbox:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.3);
}

.log-row.selected {
    background: rgba(139, 92, 246, 0.15) !important;
}

.log-row:hover {
    background: rgba(139, 92, 246, 0.05);
}

.protected-log {
    opacity: 0.7;
}

#bulkActionsToolbar {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Bulk action progress modal */
.bulk-progress {
    padding: 1rem;
    background: rgba(30, 30, 50, 0.95);
    border-radius: 8px;
    margin-top: 1rem;
}

.bulk-progress-bar {
    height: 8px;
    background: #374151;
    border-radius: 4px;
    overflow: hidden;
    margin: 0.5rem 0;
}

.bulk-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #8b5cf6, #06b6d4);
    transition: width 0.3s ease;
}

.bulk-result-item {
    padding: 0.5rem;
    margin: 0.25rem 0;
    border-radius: 4px;
    font-size: 0.875rem;
}

.bulk-result-success {
    background: rgba(34, 197, 94, 0.1);
    color: #86efac;
}

.bulk-result-error {
    background: rgba(239, 68, 68, 0.1);
    color: #fca5a5;
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

    // ENTERPRISE: First page button
    if (currentPage > 1) {
        html += `<button class="btn btn-secondary btn-sm" onclick="viewLogFile('${currentLogFile}', 1)" title="Prima pagina">
            <i class="fas fa-angle-double-left"></i> Prima
        </button>`;
    }

    // Previous button
    if (currentPage > 1) {
        html += `<button class="btn btn-secondary btn-sm" onclick="viewLogFile('${currentLogFile}', ${currentPage - 1})" title="Pagina precedente">
            <i class="fas fa-chevron-left"></i> Precedente
        </button>`;
    }

    // Page info
    html += `<span class="text-gray-300 px-4 flex items-center">Pagina ${currentPage} di ${totalPages}</span>`;

    // ENTERPRISE: Page jump dropdown
    html += `<select
        id="pageJumpSelect"
        class="bg-gray-800 text-white border border-gray-600 rounded px-3 py-1 text-sm focus:outline-none focus:border-purple-500"
        onchange="if(this.value) viewLogFile('${currentLogFile}', parseInt(this.value))"
        title="Vai alla pagina">
        <option value="">Vai a...</option>`;

    for (let i = 1; i <= totalPages; i++) {
        const selected = i === currentPage ? 'selected' : '';
        html += `<option value="${i}" ${selected}>Pagina ${i}</option>`;
    }

    html += `</select>`;

    // Next button
    if (currentPage < totalPages) {
        html += `<button class="btn btn-secondary btn-sm" onclick="viewLogFile('${currentLogFile}', ${currentPage + 1})" title="Pagina successiva">
            Successiva <i class="fas fa-chevron-right"></i>
        </button>`;
    }

    // ENTERPRISE: Last page button
    if (currentPage < totalPages) {
        html += `<button class="btn btn-secondary btn-sm" onclick="viewLogFile('${currentLogFile}', ${totalPages})" title="Ultima pagina">
            Ultima <i class="fas fa-angle-double-right"></i>
        </button>`;
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
    if (!confirm(`⚠️ Sei sicuro di voler ELIMINARE PERMANENTEMENTE "${filename}"?\n\nQuesta azione non può essere annullata.`)) {
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
    if (!confirm(`🧹 Pulire "${filename}"?\n\n⚠️ Le ultime 100 righe saranno automaticamente salvate in backup in:\n/storage/logs/backups/${filename}_backup\n\nContinuare?`)) {
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

// Archive logs prompt
function archiveLogsPrompt() {
    const days = prompt('📦 Archiviare i log più vecchi di quanti giorni?\n\n(Inserisci il numero di giorni, default: 7)', '7');

    if (days === null) {
        return; // User cancelled
    }

    const daysNum = parseInt(days);
    if (isNaN(daysNum) || daysNum < 1) {
        alert('❌ Numero di giorni non valido. Inserisci un numero positivo.');
        return;
    }

    if (!confirm(`📦 Archiviare tutti i log più vecchi di ${daysNum} giorni?\n\n✅ I file saranno compressi in un archivio .tar.gz\n✅ I file originali saranno eliminati dopo l'archiviazione\n⚠️ I file protetti (php_errors.log, need2talk.log) saranno saltati\n\nContinuare?`)) {
        return;
    }

    archiveLogs(daysNum);
}

// Archive logs
function archiveLogs(days) {
    fetch(`system-action?action=archive_logs&older_than_days=${days}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ ${data.message || 'Log archiviati con successo'}\n\n📦 Archivio: ${data.archive_file || 'creato'}\n📊 File archiviati: ${data.archived_count || 0}`);
            location.reload();
        } else {
            alert(`❌ ${data.error || 'Impossibile archiviare i log'}`);
        }
    })
    .catch(error => {
        alert(`❌ Errore: ${error.message}`);
    });
}

// Perform search
function performSearch() {
    const pattern = document.getElementById('search_pattern').value.trim();
    const filename = document.getElementById('search_file').value;
    const caseSensitive = document.getElementById('case_sensitive').checked;

    if (!pattern) {
        alert('❌ Inserisci un pattern di ricerca');
        return;
    }

    if (!filename) {
        alert('❌ Seleziona un file log da cercare');
        return;
    }

    // Show loading
    const resultsDiv = document.getElementById('search_results');
    resultsDiv.style.display = 'block';
    resultsDiv.innerHTML = '<div class="text-center text-gray-400 p-4"><i class="fas fa-spinner fa-spin mr-2"></i>Ricerca in corso...</div>';

    // Perform search
    fetch(`system-action?action=search_log&filename=${encodeURIComponent(filename)}&pattern=${encodeURIComponent(pattern)}&case_sensitive=${caseSensitive ? 1 : 0}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySearchResults(data);
            } else {
                resultsDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>${data.error || 'Ricerca fallita'}</div>`;
            }
        })
        .catch(error => {
            resultsDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Errore: ${error.message}</div>`;
        });
}

// Display search results
function displaySearchResults(data) {
    const resultsDiv = document.getElementById('search_results');

    if (!data.results || data.results.length === 0) {
        resultsDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i>Nessun risultato trovato</div>';
        return;
    }

    let html = `<div class="alert alert-success mb-4">
        <i class="fas fa-check-circle mr-2"></i>
        Trovati ${data.results.length} risultato/i in "${data.filename}"
        ${data.truncated ? ' <span class="text-yellow-400">(Limitato ai primi 500 risultati)</span>' : ''}
    </div>`;

    html += '<div class="space-y-2">';
    data.results.forEach(result => {
        html += `<div class="search-result-item">
            <div class="search-result-meta">
                <i class="fas fa-hashtag mr-1"></i>Riga ${result.line_number}
            </div>
            <div class="font-mono text-sm">${result.content}</div>
        </div>`;
    });
    html += '</div>';

    resultsDiv.innerHTML = html;
}

// Clear search results
function clearSearchResults() {
    const resultsDiv = document.getElementById('search_results');
    resultsDiv.style.display = 'none';
    resultsDiv.innerHTML = '';

    // Clear search inputs
    document.getElementById('search_pattern').value = '';
    document.getElementById('search_file').value = '';
    document.getElementById('case_sensitive').checked = false;
}

// Close search results
function closeSearchResults() {
    document.getElementById('searchResultsModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const logModal = document.getElementById('logViewerModal');
    const searchModal = document.getElementById('searchResultsModal');

    if (event.target === logModal) {
        closeLogViewer();
    }
    if (event.target === searchModal) {
        closeSearchResults();
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // ESC to close modals
    if (event.key === 'Escape') {
        closeLogViewer();
        closeSearchResults();
    }
});

// ENTERPRISE GALAXY: Toggle debug_general ON/OFF
function toggleDebugGeneral(enabled) {
    const levelContainer = document.getElementById('level_container_debug_general');
    const toggleLabel = document.getElementById('toggle_label_debug_general');

    if (enabled) {
        levelContainer.style.opacity = '1';
        levelContainer.style.pointerEvents = 'auto';
        toggleLabel.textContent = 'Attivo';
    } else {
        levelContainer.style.opacity = '0.5';
        levelContainer.style.pointerEvents = 'none';
        toggleLabel.textContent = 'Disattivato';
    }
}

// ENTERPRISE GALAXY: Save logging configuration
function saveLoggingConfig(channel) {
    const levelSelect = document.getElementById(`level_${channel}`);
    const rollbackInput = document.getElementById(`rollback_${channel}`);

    if (!levelSelect) {
        alert('❌ Errore: Impossibile trovare il selettore livello');
        return;
    }

    // ENTERPRISE GALAXY: Handle debug_general toggle
    let level = levelSelect.value;
    if (channel === 'debug_general') {
        const toggle = document.getElementById('toggle_debug_general');
        if (toggle && !toggle.checked) {
            // Toggle OFF = save 'warning' to disable debug_general
            level = 'warning';
        }
    }
    const autoRollbackMinutes = rollbackInput.value ? parseInt(rollbackInput.value) : null;

    // Validate rollback minutes
    if (autoRollbackMinutes !== null && (autoRollbackMinutes < 5 || autoRollbackMinutes > 1440)) {
        alert('❌ L\'auto-rollback deve essere tra 5 e 1440 minuti (24 ore)');
        return;
    }

    // Confirm if enabling DEBUG level
    if (level === 'debug' && !confirm('⚠️ ATTENZIONE: Abilitare il livello DEBUG può ridurre le performance del 20-30%\n\n' +
        (autoRollbackMinutes ? `L\'auto-rollback è impostato a ${autoRollbackMinutes} minuti.\n\n` : 'Considera di impostare l\'auto-rollback per sicurezza in produzione.\n\n') +
        'Continuare?')) {
        return;
    }

    // Show loading state - find the button using the channel parameter
    const button = document.querySelector(`button[onclick*="saveLoggingConfig('${channel}')"]`);
    if (!button) {
        alert('❌ Errore: Impossibile trovare il pulsante salva');
        return;
    }
    const originalHTML = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Salvataggio...';

    // Prepare data
    const data = new FormData();
    data.append('channel', channel);
    data.append('level', level);
    if (autoRollbackMinutes) {
        data.append('auto_rollback_minutes', autoRollbackMinutes);
    }
    data.append('reason', `Aggiornamento configurazione manuale tramite pannello admin`);

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
            const message = `✅ Configurazione logging aggiornata con successo!\n\n` +
                `📊 Canale: ${data.channel}\n` +
                `📈 Livello: ${data.previous_level} → ${data.level}\n` +
                (data.auto_rollback_at ? `🕐 Auto-rollback alle: ${data.auto_rollback_at}\n` : '') +
                `\n🚀 Le modifiche sono attive immediatamente (nessun riavvio richiesto)\n` +
                `📝 Traccia di audit scritta (ISO 27001 / GDPR / SOC 2 compliant)`;

            alert(message);

            // ENTERPRISE GALAXY ULTIMATE: Redis-based cache invalidation
            // No need for cache busting - Redis timestamp forces immediate refresh
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

// ═══════════════════════════════════════════════════════════════════════════
// ENTERPRISE GALAXY: Multi-Select & Bulk Actions System
// ═══════════════════════════════════════════════════════════════════════════

// Get all selected filenames
function getSelectedFiles() {
    const checkboxes = document.querySelectorAll('.log-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.dataset.filename);
}

// Update bulk selection UI
function updateBulkSelection() {
    const selectedFiles = getSelectedFiles();
    const count = selectedFiles.length;
    const toolbar = document.getElementById('bulkActionsToolbar');
    const countSpan = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllLogs');

    // Update count
    countSpan.textContent = count;

    // Show/hide toolbar
    if (count > 0) {
        toolbar.style.display = 'block';
    } else {
        toolbar.style.display = 'none';
    }

    // Update "select all" checkbox state
    const allCheckboxes = document.querySelectorAll('.log-checkbox');
    const checkedCount = document.querySelectorAll('.log-checkbox:checked').length;

    if (checkedCount === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedCount === allCheckboxes.length) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }

    // Highlight selected rows
    document.querySelectorAll('.log-row').forEach(row => {
        const checkbox = row.querySelector('.log-checkbox');
        if (checkbox && checkbox.checked) {
            row.classList.add('selected');
        } else {
            row.classList.remove('selected');
        }
    });
}

// Toggle all checkboxes
function toggleSelectAll(checked) {
    document.querySelectorAll('.log-checkbox').forEach(cb => {
        cb.checked = checked;
    });
    updateBulkSelection();
}

// Clear all selections
function clearSelection() {
    document.querySelectorAll('.log-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('selectAllLogs').checked = false;
    updateBulkSelection();
}

// Bulk download selected files as ZIP
async function bulkDownload() {
    const files = getSelectedFiles();
    if (files.length === 0) {
        alert('❌ Nessun file selezionato');
        return;
    }

    if (!confirm(`📦 Scaricare ${files.length} file come archivio ZIP?\n\nFile selezionati:\n• ${files.join('\n• ')}`)) {
        return;
    }

    // Create form and submit to trigger download
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'system-action?action=bulk_download';
    form.style.display = 'none';

    files.forEach(filename => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'files[]';
        input.value = filename;
        form.appendChild(input);
    });

    // Add CSRF token
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_csrf_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);
    }

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    // Clear selection after download starts
    setTimeout(() => {
        clearSelection();
    }, 1000);
}

// Bulk clear selected files
async function bulkClear() {
    const files = getSelectedFiles();
    if (files.length === 0) {
        alert('❌ Nessun file selezionato');
        return;
    }

    if (!confirm(`🧹 Pulire ${files.length} file log?\n\n⚠️ Le ultime 100 righe di ogni file saranno salvate in backup.\n\nFile selezionati:\n• ${files.join('\n• ')}\n\nContinuare?`)) {
        return;
    }

    // Show progress
    const toolbar = document.getElementById('bulkActionsToolbar');
    const originalHTML = toolbar.innerHTML;
    toolbar.innerHTML = `
        <div class="bulk-progress">
            <div class="flex items-center justify-between mb-2">
                <span class="text-white"><i class="fas fa-spinner fa-spin mr-2"></i>Pulizia in corso...</span>
                <span id="bulkProgressText" class="text-gray-400">0/${files.length}</span>
            </div>
            <div class="bulk-progress-bar">
                <div id="bulkProgressFill" class="bulk-progress-fill" style="width: 0%"></div>
            </div>
            <div id="bulkResults" class="mt-3 max-h-40 overflow-y-auto"></div>
        </div>
    `;

    const results = [];
    let completed = 0;

    for (const filename of files) {
        try {
            const response = await fetch(`system-action?action=clear_log&filename=${encodeURIComponent(filename)}`, {
                method: 'POST'
            });
            const data = await response.json();

            results.push({
                filename,
                success: data.success,
                message: data.success ? 'Pulito con successo' : (data.error || 'Errore')
            });
        } catch (error) {
            results.push({
                filename,
                success: false,
                message: error.message
            });
        }

        completed++;
        document.getElementById('bulkProgressText').textContent = `${completed}/${files.length}`;
        document.getElementById('bulkProgressFill').style.width = `${(completed / files.length) * 100}%`;

        // Update results list
        const resultsDiv = document.getElementById('bulkResults');
        const lastResult = results[results.length - 1];
        resultsDiv.innerHTML += `
            <div class="bulk-result-item ${lastResult.success ? 'bulk-result-success' : 'bulk-result-error'}">
                <i class="fas ${lastResult.success ? 'fa-check' : 'fa-times'} mr-2"></i>
                ${lastResult.filename}: ${lastResult.message}
            </div>
        `;
    }

    // Show summary and reload
    const successCount = results.filter(r => r.success).length;
    setTimeout(() => {
        alert(`✅ Operazione completata!\n\n✓ Successo: ${successCount}\n✗ Errori: ${files.length - successCount}`);
        location.reload();
    }, 1500);
}

// Bulk delete selected files
async function bulkDelete() {
    const files = getSelectedFiles();
    if (files.length === 0) {
        alert('❌ Nessun file selezionato');
        return;
    }

    if (!confirm(`⚠️ ELIMINARE PERMANENTEMENTE ${files.length} file?\n\n🚨 ATTENZIONE: Questa azione NON può essere annullata!\n\nFile selezionati:\n• ${files.join('\n• ')}\n\nContinuare?`)) {
        return;
    }

    // Double confirmation for safety
    if (!confirm(`🔴 CONFERMA FINALE\n\nStai per eliminare DEFINITIVAMENTE ${files.length} file.\n\nSei ASSOLUTAMENTE sicuro?`)) {
        return;
    }

    // Show progress
    const toolbar = document.getElementById('bulkActionsToolbar');
    toolbar.innerHTML = `
        <div class="bulk-progress">
            <div class="flex items-center justify-between mb-2">
                <span class="text-white"><i class="fas fa-spinner fa-spin mr-2"></i>Eliminazione in corso...</span>
                <span id="bulkProgressText" class="text-gray-400">0/${files.length}</span>
            </div>
            <div class="bulk-progress-bar">
                <div id="bulkProgressFill" class="bulk-progress-fill" style="width: 0%"></div>
            </div>
            <div id="bulkResults" class="mt-3 max-h-40 overflow-y-auto"></div>
        </div>
    `;

    const results = [];
    let completed = 0;

    for (const filename of files) {
        try {
            const response = await fetch(`system-action?action=delete_log&filename=${encodeURIComponent(filename)}`, {
                method: 'POST'
            });
            const data = await response.json();

            results.push({
                filename,
                success: data.success,
                message: data.success ? 'Eliminato' : (data.error || 'Errore')
            });
        } catch (error) {
            results.push({
                filename,
                success: false,
                message: error.message
            });
        }

        completed++;
        document.getElementById('bulkProgressText').textContent = `${completed}/${files.length}`;
        document.getElementById('bulkProgressFill').style.width = `${(completed / files.length) * 100}%`;

        // Update results list
        const resultsDiv = document.getElementById('bulkResults');
        const lastResult = results[results.length - 1];
        resultsDiv.innerHTML += `
            <div class="bulk-result-item ${lastResult.success ? 'bulk-result-success' : 'bulk-result-error'}">
                <i class="fas ${lastResult.success ? 'fa-check' : 'fa-times'} mr-2"></i>
                ${lastResult.filename}: ${lastResult.message}
            </div>
        `;
    }

    // Show summary and reload
    const successCount = results.filter(r => r.success).length;
    setTimeout(() => {
        alert(`✅ Operazione completata!\n\n✓ Eliminati: ${successCount}\n✗ Errori: ${files.length - successCount}`);
        location.reload();
    }, 1500);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Ensure bulk toolbar is hidden initially
    const toolbar = document.getElementById('bulkActionsToolbar');
    if (toolbar) {
        toolbar.style.display = 'none';
    }
});
</script>
