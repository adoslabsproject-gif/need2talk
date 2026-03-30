<!-- ENTERPRISE GALAXY SECURITY EVENT MONITORING VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-shield-alt mr-3"></i>
    Monitoraggio Eventi di Sicurezza
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; font-weight: 600;">REAL-TIME DUAL-WRITE</span>
</h2>

<!-- Summary Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $security_log_files['total_files'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-file-alt mr-2"></i>File di Log Totali
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $security_log_files['total_size_formatted'] ?? '0 B' ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Dimensione Totale
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-value">
            <a href="security-test" target="_blank" class="btn btn-warning btn-sm">
                <i class="fas fa-vial mr-2"></i>Pagina Test
            </a>
        </span>
        <div class="stat-label">Strumenti di Test</div>
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
<?php if (!empty($security_log_files['files'])) { ?>
<div class="card mb-6">
    <h3 class="flex items-center">
        <i class="fas fa-list mr-3"></i>File di Log Sicurezza
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
                <?php foreach ($security_log_files['files'] as $file) { ?>
                    <tr>
                        <td class="font-mono text-sm">
                            <span style="color: #f59e0b;">
                                <i class="fas fa-shield-alt mr-2"></i><?= htmlspecialchars($file['filename']) ?>
                            </span>
                        </td>
                        <td class="text-gray-300">
                            <?= $file['size_formatted'] ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-info">
                                <?= number_format($file['lines']) ?> lines
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
                <h4 class="text-white mb-1">Nessun File di Log di Sicurezza Trovato</h4>
                <p class="text-sm text-gray-300">
                    Nessun log di eventi di sicurezza è stato ancora generato. I log appariranno qui quando verranno rilevati eventi di sicurezza.
                </p>
                <p class="text-xs text-gray-400 mt-2">
                    <i class="fas fa-lightbulb mr-1"></i>
                    Gli eventi di sicurezza vengono registrati automaticamente durante autenticazione, autorizzazione e operazioni critiche di sicurezza.
                </p>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<!-- Database Events Statistics (by PSR-3 Level) -->
<div id="db-events-stats" class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
    <div class="stat-card" data-stat="total">
        <span class="stat-value"><?= $total_events ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Eventi DB Totali
        </div>
    </div>

    <!-- EMERGENCY + ALERT + CRITICAL (Red) -->
    <div class="stat-card" style="border-left: 3px solid #dc2626;" data-stat="critical">
        <span class="stat-value" style="color: #ef4444;">
            <?= ($level_counts['emergency'] ?? 0) + ($level_counts['alert'] ?? 0) + ($level_counts['critical'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-skull-crossbones mr-2"></i>🔴 EMG/ALRT/CRIT
        </div>
    </div>

    <!-- ERROR (Orange) -->
    <div class="stat-card" style="border-left: 3px solid #ea580c;" data-stat="error">
        <span class="stat-value" style="color: #f97316;"><?= $level_counts['error'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-times-circle mr-2"></i>🟠 Error
        </div>
    </div>

    <!-- WARNING (Yellow) -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;" data-stat="warning">
        <span class="stat-value" style="color: #f59e0b;"><?= $level_counts['warning'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>🟡 Warning
        </div>
    </div>

    <!-- NOTICE + INFO + DEBUG (Green) -->
    <div class="stat-card" style="border-left: 3px solid #10b981;" data-stat="info">
        <span class="stat-value" style="color: #10b981;">
            <?= ($level_counts['notice'] ?? 0) + ($level_counts['info'] ?? 0) + ($level_counts['debug'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-info-circle mr-2"></i>🟢 NTC/INFO/DBG
        </div>
    </div>
</div>

<!-- Database Events Table -->
<div class="card">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-database mr-3"></i>Eventi Database (Totale: <?= $total_events ?? 0 ?>)
        </span>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-300">Mostra:</label>
                <select id="eventsPerPage" class="form-control" style="width: auto; padding: 0.5rem;" onchange="changeEventsPerPage()">
                    <option value="25" <?= ($limit ?? 50) == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= ($limit ?? 50) == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= ($limit ?? 50) == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-xs text-gray-400">per pagina</span>
            </div>
            <button onclick="refreshDatabaseSecurityEvents()" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 8px 16px;">
                <i class="fas fa-sync-alt mr-1"></i>Aggiorna Database
            </button>
        </div>
    </h3>

    <?php if (empty($events)) { ?>
        <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3);">
            <div class="flex items-center gap-3">
                <i class="fas fa-check-circle text-green-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessun Evento nel Database</h4>
                    <p class="text-sm text-gray-300">Il sistema è sano! Nessun evento di sicurezza è stato memorizzato nel database. 🎉</p>
                    <p class="text-xs text-gray-400 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Il database memorizza dati strutturati degli eventi per il monitoraggio in tempo reale. Controlla i file di log sopra per lo storico audit.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <!-- ENTERPRISE GALAXY: Full table with ALL columns, smooth horizontal scroll -->
        <div class="security-events-table-container" style="overflow-x: auto; overflow-y: visible; max-width: 100%; border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 8px; background: rgba(26, 26, 46, 0.3);">
            <table class="security-events-table" style="font-size: 11px; width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr>
                        <th class="sticky-col" style="position: sticky; left: 0; z-index: 3; background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%); padding: 1rem 0.75rem; text-align: center; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #8b5cf6; white-space: nowrap;">ID</th>
                        <th class="sticky-col-2" style="position: sticky; left: 45px; z-index: 3; background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%); padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; white-space: nowrap; min-width: 110px;">Livello</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 120px;">Canale</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 400px; max-width: 500px;">Messaggio</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 300px;">Contesto</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 150px;">Indirizzo IP</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 250px;">User Agent</th>
                        <th style="padding: 1rem; text-align: center; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap;">User ID</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 200px;">Session ID</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(139, 92, 246, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(139, 92, 246, 0.1); white-space: nowrap; min-width: 150px;">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event) {
                        $level = $event['level'] ? strtolower($event['level']) : 'info';
                        // ENTERPRISE GALAXY: PSR-3 color hierarchy
                        $levelColors = [
                            'emergency' => '#7f1d1d',
                            'alert' => '#991b1b',
                            'critical' => '#dc2626',
                            'error' => '#ea580c',
                            'warning' => '#f59e0b',
                            'notice' => '#10b981',
                            'info' => '#3b82f6',
                            'debug' => '#8b5cf6',
                        ];
                        $levelColor = $levelColors[$level] ?? $levelColors['info'];
                        $created = $event['created_at'] ? date('d/m/Y H:i:s', strtotime($event['created_at'])) : '-';
                        $context = $event['context'] ? json_decode($event['context'], true) : [];
                        $contextFormatted = $context ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '-';
                        ?>
                    <tr class="event-row" style="border-bottom: 1px solid rgba(55, 65, 81, 0.2); background: rgba(26, 26, 46, 0.4); transition: all 0.2s ease;">
                        <!-- Sticky ID Column -->
                        <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem 0.75rem; text-align: center; font-weight: bold; color: #8b5cf6; border-right: 1px solid rgba(139, 92, 246, 0.2); font-size: 13px;">
                            #<?= htmlspecialchars($event['id'] ?? '-') ?>
                        </td>
                        <!-- Sticky Level Column -->
                        <td class="sticky-col-2" style="position: sticky; left: 45px; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem; text-align: center; border-right: 1px solid rgba(139, 92, 246, 0.2);">
                            <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: <?= $levelColor ?>; color: #fff; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.5px;">
                                <?= strtoupper($level) ?>
                            </span>
                        </td>
                        <!-- Channel -->
                        <td style="padding: 1rem; vertical-align: top;">
                            <span class="text-xs px-2.5 py-1 rounded font-mono" style="background: rgba(139,92,246,0.15); color: #c4b5fd; display: inline-block; border: 1px solid rgba(139,92,246,0.3);">
                                <?= htmlspecialchars($event['channel'] ?? 'security') ?>
                            </span>
                        </td>
                        <!-- Message -->
                        <td style="padding: 1rem; color: #e5e5e5; line-height: 1.6; vertical-align: top; min-width: 400px; max-width: 500px;">
                            <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                                <?= nl2br(htmlspecialchars($event['message'] ?: '-')) ?>
                            </div>
                        </td>
                        <!-- Context (JSON) -->
                        <td style="padding: 1rem; color: #d0d0d0; font-family: 'Courier New', monospace; font-size: 10px; line-height: 1.5; vertical-align: top; background: rgba(0,0,0,0.25); min-width: 300px;">
                            <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: pre-wrap;">
                                <?= htmlspecialchars($contextFormatted) ?>
                            </div>
                        </td>
                        <!-- IP Address -->
                        <td style="padding: 1rem; color: #60a5fa; font-family: monospace; font-size: 11px; font-weight: 600; vertical-align: top; white-space: nowrap; min-width: 150px;">
                            <?= htmlspecialchars($event['ip_address'] ?: '-') ?>
                        </td>
                        <!-- User Agent -->
                        <td style="padding: 1rem; color: #a0a0a0; font-size: 10px; line-height: 1.4; vertical-align: top; word-break: break-word; min-width: 250px;">
                            <?= htmlspecialchars($event['user_agent'] ?: '-') ?>
                        </td>
                        <!-- User ID -->
                        <td style="padding: 1rem; text-align: center; color: #fbbf24; font-family: monospace; font-weight: 600; vertical-align: top; white-space: nowrap;">
                            <?= htmlspecialchars($event['user_id'] ?? '-') ?>
                        </td>
                        <!-- Session ID -->
                        <td style="padding: 1rem; color: #a8a8a8; font-family: 'Courier New', monospace; font-size: 10px; vertical-align: top; word-break: break-all; min-width: 200px;">
                            <?= htmlspecialchars($event['session_id'] ?: '-') ?>
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
                Pagina <?= $current_page ?? 1 ?> di <?= $total_pages ?? 1 ?>
                (<?= count($events) ?> eventi in questa pagina)
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
                    <strong class="text-white">Architettura Dual-Write:</strong>
                    Gli eventi di sicurezza sono memorizzati nella tabella <code class="text-purple-400">security_events</code> (monitoraggio real-time)
                    e nei file <code class="text-purple-400">security-*.log</code> (storico audit).
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

<!-- ENTERPRISE GALAXY: Security Overview Dashboard -->
<h2 class="enterprise-title mb-8 flex items-center" style="color: #ffffff; font-weight: 700;">
    <i class="fas fa-chart-line mr-3" style="color: #60a5fa;"></i>
    Panoramica Eventi di Sicurezza (Ultime 24h)
    <?php
    $statusColor = match ($security_status['security_level_color'] ?? 'gray') {
        'red' => 'background: rgba(220, 38, 38, 0.2); color: #ef4444;',
        'orange' => 'background: rgba(234, 88, 12, 0.2); color: #f97316;',
        'yellow' => 'background: rgba(245, 158, 11, 0.2); color: #fbbf24;',
        'green' => 'background: rgba(34, 197, 94, 0.2); color: #4ade80;',
        default => 'background: rgba(107, 114, 128, 0.2); color: #9ca3af;',
    };
        ?>
    <span class="text-xs px-2 py-1 rounded ml-3" style="<?= $statusColor ?> font-weight: 600;">
        <i class="fas fa-<?= $security_status['security_level_icon'] ?? 'shield' ?> mr-1"></i>
        <?= strtoupper($security_status['security_level'] ?? 'UNKNOWN') ?>
    </span>
</h2>

<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 2rem;">
    <!-- Failed Logins -->
    <div class="stat-card" style="border-left: 3px solid #dc2626;">
        <span class="stat-value" style="color: #ef4444;">
            <?= $security_status['failed_logins_24h'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-user-lock mr-2"></i>Login Falliti (24h)
        </div>
    </div>

    <!-- Blocked IPs -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #fbbf24;">
            <?= $security_status['blocked_ips_24h'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-ban mr-2"></i>IP Sospetti (24h)
        </div>
    </div>

    <!-- Critical Events -->
    <div class="stat-card" style="border-left: 3px solid #991b1b;">
        <span class="stat-value" style="color: #dc2626;">
            <?= $security_status['critical_events_24h'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>Eventi Critici (24h)
        </div>
    </div>

    <!-- Total Events -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #60a5fa;">
            <?= $security_status['total_events_24h'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Eventi Totali (24h)
        </div>
    </div>

    <!-- Active Sessions -->
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #34d399;">
            <?= $security_status['active_admin_sessions'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-user-shield mr-2"></i>Sessioni Admin Attive
        </div>
    </div>
</div>

<!-- Security Status Description -->
<div class="card mb-6" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.15) 100%); border: 1px solid rgba(73, 73, 74, 0.3);">
    <div class="flex items-start gap-4">
        <div class="text-4xl">
            <i class="fas fa-<?= $security_status['security_level_icon'] ?? 'shield' ?>" style="color: <?= match ($security_status['security_level_color'] ?? 'gray') {
                'red' => '#ef4444',
                'orange' => '#f97316',
                'yellow' => '#fbbf24',
                'green' => '#4ade80',
                default => '#9ca3af',
            }; ?>;"></i>
        </div>
        <div style="flex: 1;">
            <h3 class="mb-2" style="color: <?= match ($security_status['security_level_color'] ?? 'gray') {
                'red' => '#fca5a5',
                'orange' => '#fdba74',
                'yellow' => '#fcd34d',
                'green' => '#86efac',
                default => '#d1d5db',
            }; ?>; font-weight: 700;">
                Livello di Sicurezza: <?= $security_status['security_level'] ?? 'Sconosciuto' ?>
            </h3>
            <p style="color: #e5e7eb; font-size: 0.875rem; line-height: 1.6;">
                <?= $security_status['security_level_description'] ?? 'Stato di sicurezza non disponibile' ?>
            </p>
            <div class="mt-3" style="font-size: 0.75rem; color: #d1d5db;">
                <i class="fas fa-clock mr-1"></i>
                Ultimo aggiornamento: <?= date('d/m/Y H:i:s') ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Critical Events (if any) -->
<?php if (!empty($security_status['recent_critical_events'])) { ?>
<div class="card mb-6" style="border-left: 3px solid #dc2626;">
    <h3 class="flex items-center mb-4">
        <i class="fas fa-skull-crossbones mr-3" style="color: #ef4444;"></i>
        <span style="color: #ef4444;">Eventi Critici Recenti</span>
        <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(220, 38, 38, 0.2); color: #ef4444;">
            <?= count($security_status['recent_critical_events']) ?> eventi
        </span>
    </h3>
    <div class="space-y-2">
        <?php foreach ($security_status['recent_critical_events'] as $criticalEvent) { ?>
        <div class="p-3 rounded" style="background: rgba(220, 38, 38, 0.1); border-left: 2px solid #dc2626;">
            <div class="flex justify-between items-start gap-3">
                <div style="flex: 1;">
                    <div class="text-sm font-semibold text-white mb-1">
                        <?= htmlspecialchars($criticalEvent['message'] ?? 'No message') ?>
                    </div>
                    <div class="text-xs text-gray-400 flex gap-4 flex-wrap">
                        <span><i class="fas fa-network-wired mr-1"></i><?= htmlspecialchars($criticalEvent['channel'] ?? 'security') ?></span>
                        <span><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($criticalEvent['ip_address'] ?? 'N/A') ?></span>
                        <span><i class="fas fa-clock mr-1"></i><?= date('d/m/Y H:i:s', strtotime($criticalEvent['created_at'])) ?></span>
                    </div>
                </div>
                <span class="text-xs px-2 py-1 rounded font-semibold" style="background: <?= match (strtolower($criticalEvent['level'] ?? 'critical')) {
                    'emergency' => '#7f1d1d',
                    'alert' => '#991b1b',
                    default => '#dc2626',
                }; ?>; color: #fff; white-space: nowrap;">
                    <?= strtoupper($criticalEvent['level'] ?? 'CRITICAL') ?>
                </span>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php } ?>

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

/* Table row hover effect */
.event-row:hover {
    background: rgba(139, 92, 246, 0.1) !important;
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
                document.getElementById('logViewerContent').innerHTML = data.content || '<div class="text-gray-400">File di log vuoto</div>';

                // Generate pagination
                generateLogPagination(data.current_page, data.total_pages);
            } else {
                document.getElementById('logViewerContent').innerHTML = `<div class="text-red-400"><i class="fas fa-exclamation-triangle mr-2"></i>${data.error || 'Impossibile caricare il file di log'}</div>`;
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
    if (!confirm(`⚠️ Sei sicuro di voler ELIMINARE PERMANENTEMENTE "${filename}"?\n\nQuesta azione non può essere annullata.`)) {
        return;
    }

    fetch(`system-action?action=delete_log&filename=${encodeURIComponent(filename)}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ ${data.message || 'File di log eliminato con successo'}`);
            location.reload();
        } else {
            alert(`❌ ${data.error || 'Impossibile eliminare il file di log'}`);
        }
    })
    .catch(error => {
        alert(`❌ Errore: ${error.message}`);
    });
}

// Clear log
function clearLog(filename) {
    if (!confirm(`🧹 Pulire "${filename}"?\n\n⚠️ Le ultime 100 righe verranno automaticamente salvate in:\n/storage/logs/backups/${filename}_backup\n\nContinuare?`)) {
        return;
    }

    fetch(`system-action?action=clear_log&filename=${encodeURIComponent(filename)}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ ${data.message || 'File di log pulito con successo'}\n\n💾 Backup salvato in: ${data.backup_file || 'directory backups'}`);
            location.reload();
        } else {
            alert(`❌ ${data.error || 'Impossibile pulire il file di log'}`);
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

// ENTERPRISE GALAXY: Refresh database security events table (real-time update)
async function refreshDatabaseSecurityEvents() {
    console.debug('🔄 Refreshing database security events...');

    // Get current pagination settings
    const eventsPerPageSelect = document.getElementById('eventsPerPage');
    const limit = eventsPerPageSelect ? parseInt(eventsPerPageSelect.value) : 50;

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
            throw new Error('Hash URL admin non trovato nel percorso corrente');
        }

        // ENTERPRISE GALAXY: Use admin API endpoint with cache-busting
        const timestamp = Date.now();
        const protocol = window.location.protocol; // http: or https:
        const host = window.location.host;
        const url = `${protocol}//${host}/admin_${adminHash}/api/security-events/database?limit=${limit}&_=${timestamp}`;

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
        console.debug('✅ Events count:', data.events ? data.events.length : 0, '/', data.total);

        if (data.success) {
            // Update total count in header
            const headerTitle = document.querySelector('.card h3 span');
            if (headerTitle && headerTitle.textContent.includes('Total:')) {
                headerTitle.innerHTML = `<i class="fas fa-database mr-3"></i>Database Events (Total: ${data.total})`;
            }

            // Update level counts
            updateLevelCounts(data.level_counts);

            // Update table
            updateEventsTable(data.events);

            // Update pagination
            updatePagination(data.page, data.total_pages);

            // Show success message
            console.info(`✅ Database refreshed successfully! Total: ${data.total}, Shown: ${data.events.length}`);

            // Visual feedback
            if (button) {
                button.innerHTML = '<i class="fas fa-check mr-1"></i>Aggiornato!';
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                }, 1500);
            }
        } else {
            throw new Error(data.message || 'Impossibile caricare gli eventi di sicurezza');
        }

    } catch (error) {
        console.error('❌ Failed to refresh database security events:', error);
        alert('❌ Impossibile aggiornare gli eventi di sicurezza del database: ' + error.message);

        if (button) {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }
}

// Update level count cards
function updateLevelCounts(counts) {
    // ENTERPRISE TIPS: Use specific selectors for database events stats only (not all stat-cards!)
    const dbStatsGrid = document.getElementById('db-events-stats');
    if (!dbStatsGrid) {
        console.warn('⚠️ Database stats grid not found');
        return;
    }

    // Total DB Events
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

    console.debug('✅ Level counts updated:', counts);
}

// Update events table with fresh data
function updateEventsTable(events) {
    const tbody = document.querySelector('.security-events-table tbody');
    if (!tbody) {
        console.warn('⚠️ Table tbody not found');
        return;
    }

    if (events.length === 0) {
        // Show "no events" message
        const card = tbody.closest('.card');
        if (card) {
            card.innerHTML = `
                <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3);">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-green-400 text-2xl"></i>
                        <div>
                            <h4 class="text-white mb-1">Nessun Evento nel Database</h4>
                            <p class="text-sm text-gray-300">Il sistema è sano! Nessun evento di sicurezza è stato memorizzato nel database. 🎉</p>
                        </div>
                    </div>
                </div>
            `;
        }
        return;
    }

    // Generate table rows
    const levelColors = {
        'emergency': '#7f1d1d',
        'alert': '#991b1b',
        'critical': '#dc2626',
        'error': '#ea580c',
        'warning': '#f59e0b',
        'notice': '#10b981',
        'info': '#3b82f6',
        'debug': '#8b5cf6'
    };

    const rows = events.map(event => {
        const level = (event.level || 'info').toLowerCase();
        const levelColor = levelColors[level] || levelColors['info'];
        const created = event.created_at ? new Date(event.created_at).toLocaleString('it-IT') : '-';

        let contextFormatted = '-';
        try {
            const context = event.context ? JSON.parse(event.context) : {};
            contextFormatted = Object.keys(context).length > 0 ? JSON.stringify(context, null, 2) : '-';
        } catch (e) {
            contextFormatted = event.context || '-';
        }

        return `
            <tr class="event-row" style="border-bottom: 1px solid rgba(55, 65, 81, 0.2); background: rgba(26, 26, 46, 0.4); transition: all 0.2s ease;">
                <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem 0.75rem; text-align: center; font-weight: bold; color: #8b5cf6; border-right: 1px solid rgba(139, 92, 246, 0.2); font-size: 13px;">
                    #${event.id || '-'}
                </td>
                <td class="sticky-col-2" style="position: sticky; left: 45px; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem; text-align: center; border-right: 1px solid rgba(139, 92, 246, 0.2);">
                    <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: ${levelColor}; color: #fff; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.5px;">
                        ${level.toUpperCase()}
                    </span>
                </td>
                <td style="padding: 1rem; vertical-align: top;">
                    <span class="text-xs px-2.5 py-1 rounded font-mono" style="background: rgba(139,92,246,0.15); color: #c4b5fd; display: inline-block; border: 1px solid rgba(139,92,246,0.3);">
                        ${escapeHtml(event.channel || 'security')}
                    </span>
                </td>
                <td style="padding: 1rem; color: #e5e5e5; line-height: 1.6; vertical-align: top; min-width: 400px; max-width: 500px;">
                    <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                        ${escapeHtml(event.message || '-').replace(/\n/g, '<br>')}
                    </div>
                </td>
                <td style="padding: 1rem; color: #d0d0d0; font-family: 'Courier New', monospace; font-size: 10px; line-height: 1.5; vertical-align: top; background: rgba(0,0,0,0.25); min-width: 300px;">
                    <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: pre-wrap;">
                        ${escapeHtml(contextFormatted)}
                    </div>
                </td>
                <td style="padding: 1rem; color: #60a5fa; font-family: monospace; font-size: 11px; font-weight: 600; vertical-align: top; white-space: nowrap; min-width: 150px;">
                    ${escapeHtml(event.ip_address || '-')}
                </td>
                <td style="padding: 1rem; color: #a0a0a0; font-size: 10px; line-height: 1.4; vertical-align: top; word-break: break-word; min-width: 250px;">
                    ${escapeHtml(event.user_agent || '-')}
                </td>
                <td style="padding: 1rem; text-align: center; color: #fbbf24; font-family: monospace; font-weight: 600; vertical-align: top; white-space: nowrap;">
                    ${escapeHtml(event.user_id || '-')}
                </td>
                <td style="padding: 1rem; color: #a8a8a8; font-family: 'Courier New', monospace; font-size: 10px; vertical-align: top; word-break: break-all; min-width: 200px;">
                    ${escapeHtml(event.session_id || '-')}
                </td>
                <td style="padding: 1rem; color: #b0b0b0; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                    <div>${created}</div>
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
    console.debug(`✅ Table updated with ${events.length} rows`);
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
        pageInfo.textContent = `Pagina ${currentPage} di ${totalPages}`;
    }
}

// Helper: Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Change events per page and reload
function changeEventsPerPage() {
    const limit = document.getElementById('eventsPerPage').value;
    window.location.href = `?limit=${limit}`;
}

// Navigate to specific page
function navigateToPage(page) {
    const limit = document.getElementById('eventsPerPage')?.value || 50;
    window.location.href = `?limit=${limit}&page=${page}`;
}

console.info('✅ Monitoraggio Eventi di Sicurezza Enterprise caricato');
</script>
>
