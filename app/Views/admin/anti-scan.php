<!-- ENTERPRISE GALAXY ANTI-SCAN SYSTEM MONITORING VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-shield-virus mr-3"></i>
    Monitoraggio Sistema Anti-Scan
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(220, 38, 38, 0.2); color: #ef4444; font-weight: 600;">
        <i class="fas fa-ban mr-1"></i>PROTEZIONE IN TEMPO REALE
    </span>
</h2>

<!-- Summary Statistics -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="stat-card" style="border-left: 3px solid #dc2626;">
        <span class="stat-value" style="color: #ef4444;">
            <?= $active_bans ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-ban mr-2"></i>Ban Attivi
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #991b1b;">
        <span class="stat-value" style="color: #dc2626;">
            <?= $honeypot_catches_24h ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-spider mr-2"></i>Catture Honeypot (24h)
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #ea580c;">
        <span class="stat-value" style="color: #f97316;">
            <?= $critical_bans_24h ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-skull-crossbones mr-2"></i>Ban Critici (24h)
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-value">
            <button onclick="refreshDatabaseBans()" class="btn btn-primary btn-sm">
                <i class="fas fa-sync-alt mr-2"></i>Aggiorna Database
            </button>
        </span>
        <div class="stat-label">Azioni Rapide</div>
    </div>
</div>

<!-- Database Ban Statistics (by Severity) -->
<div id="db-bans-stats" class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-top: 2rem;">
    <div class="stat-card" data-stat="total">
        <span class="stat-value"><?= $total_bans ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-database mr-2"></i>Totale Ban DB
        </div>
    </div>

    <!-- Critical (Red) -->
    <div class="stat-card" style="border-left: 3px solid #7f1d1d;" data-stat="critical">
        <span class="stat-value" style="color: #dc2626;">
            <?= $severity_counts['critical'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-exclamation-circle mr-2"></i>🔴 Critici
        </div>
    </div>

    <!-- High (Orange) -->
    <div class="stat-card" style="border-left: 3px solid #ea580c;" data-stat="high">
        <span class="stat-value" style="color: #f97316;"><?= $severity_counts['high'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-exclamation-triangle mr-2"></i>🟠 Alti
        </div>
    </div>

    <!-- Medium (Yellow) -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;" data-stat="medium">
        <span class="stat-value" style="color: #fbbf24;"><?= $severity_counts['medium'] ?? 0 ?></span>
        <div class="stat-label">
            <i class="fas fa-info-circle mr-2"></i>🟡 Medi
        </div>
    </div>

    <!-- Low (Green) -->
    <div class="stat-card" style="border-left: 3px solid #10b981;" data-stat="low">
        <span class="stat-value" style="color: #10b981;">
            <?= $severity_counts['low'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-check-circle mr-2"></i>🟢 Bassi
        </div>
    </div>

    <!-- Honeypot Triggered -->
    <div class="stat-card" style="border-left: 3px solid #991b1b;" data-stat="honeypot">
        <span class="stat-value" style="color: #ef4444;">
            <?= $honeypot_count ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-spider mr-2"></i>Trigger Honeypot
        </div>
    </div>
</div>

<!-- Database Bans Table -->
<div class="card" style="margin-top: 2rem;">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-database mr-3"></i>Ban per Scansioni Vulnerabilità (Totale: <?= $total_bans ?? 0 ?>)
        </span>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-300">Mostra:</label>
                <select id="bansPerPage" class="form-control" style="width: auto; padding: 0.5rem;" onchange="changeBansPerPage()">
                    <option value="25" <?= ($limit ?? 50) == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= ($limit ?? 50) == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= ($limit ?? 50) == 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-xs text-gray-400">per pagina</span>
            </div>
            <button onclick="refreshDatabaseBans()" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 8px 16px;">
                <i class="fas fa-sync-alt mr-1"></i>Aggiorna Database
            </button>
        </div>
    </h3>

    <?php if (empty($bans)) { ?>
        <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3);">
            <div class="flex items-center gap-3">
                <i class="fas fa-shield-check text-green-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessuna Scansione Vulnerabilità Rilevata</h4>
                    <p class="text-sm text-gray-300">
                        Eccellente! Non sono stati rilevati tentativi di scansione. Il tuo sistema anti-scan è attivo e pronto. 🎉
                    </p>
                    <p class="text-xs text-gray-400 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Il sistema rileva automaticamente e banna gli IP che tentano di scansionare vulnerabilità, accedere agli endpoint honeypot o generare 404 eccessivi.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <!-- ENTERPRISE GALAXY: Full table with ALL columns, smooth horizontal scroll -->
        <div class="bans-table-container" style="overflow-x: auto; overflow-y: visible; max-width: 100%; border: 1px solid rgba(220, 38, 38, 0.2); border-radius: 8px; background: rgba(26, 26, 46, 0.3);">
            <table class="bans-table" style="font-size: 11px; width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr>
                        <th class="sticky-col" style="position: sticky; left: 0; z-index: 3; background: linear-gradient(135deg, rgba(220, 38, 38, 0.2) 0%, rgba(234, 88, 12, 0.1) 100%); padding: 1rem 0.75rem; text-align: center; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #ef4444; white-space: nowrap;">ID</th>
                        <th class="sticky-col-2" style="position: sticky; left: 45px; z-index: 3; background: linear-gradient(135deg, rgba(220, 38, 38, 0.2) 0%, rgba(234, 88, 12, 0.1) 100%); padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; white-space: nowrap; min-width: 150px;">Indirizzo IP</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 100px;">Gravità</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 100px;">Tipo Ban</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; text-align: center; min-width: 80px;">Punteggio</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 200px;">Pattern Scansione</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 200px;">Percorsi Accessi</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; text-align: center; min-width: 120px;">Honeypot</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 250px;">User Agent</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; text-align: center; min-width: 100px;">Tipo UA</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 150px;">Bannato il</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; min-width: 150px;">Scade il</th>
                        <th style="padding: 1rem; border-bottom: 2px solid rgba(220, 38, 38, 0.4); font-weight: 600; color: #e0e0e0; background: rgba(220, 38, 38, 0.1); white-space: nowrap; text-align: center; min-width: 80px;">Violazioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bans as $ban) {
                        $severity = $ban['severity'] ? strtolower($ban['severity']) : 'medium';
                        // ENTERPRISE GALAXY: Severity color hierarchy
                        $severityColors = [
                            'critical' => '#7f1d1d',
                            'high' => '#ea580c',
                            'medium' => '#f59e0b',
                            'low' => '#10b981',
                        ];
                        $severityColor = $severityColors[$severity] ?? $severityColors['medium'];

                        $bannedAt = $ban['banned_at'] ? date('d/m/Y H:i:s', strtotime($ban['banned_at'])) : '-';
                        $expiresAt = $ban['expires_at'] ? date('d/m/Y H:i:s', strtotime($ban['expires_at'])) : '-';

                        $scanPatterns = $ban['scan_patterns'] ? json_decode($ban['scan_patterns'], true) : [];
                        $scanPatternsFormatted = !empty($scanPatterns) ? implode(', ', $scanPatterns) : '-';

                        $pathsAccessed = $ban['paths_accessed'] ? json_decode($ban['paths_accessed'], true) : [];
                        $pathsFormatted = !empty($pathsAccessed) ? implode(', ', array_slice($pathsAccessed, 0, 5)) : '-';
                        if (count($pathsAccessed) > 5) {
                            $pathsFormatted .= ' (+' . (count($pathsAccessed) - 5) . ' more)';
                        }

                        $honeypotTriggered = $ban['honeypot_triggered'] ? true : false;
                        $honeypotPath = $ban['honeypot_path'] ?? '-';
                        ?>
                    <tr class="ban-row" style="border-bottom: 1px solid rgba(55, 65, 81, 0.2); background: rgba(26, 26, 46, 0.4); transition: all 0.2s ease;">
                        <!-- Sticky ID Column -->
                        <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem 0.75rem; text-align: center; font-weight: bold; color: #ef4444; border-right: 1px solid rgba(220, 38, 38, 0.2); font-size: 13px;">
                            #<?= htmlspecialchars($ban['id'] ?? '-') ?>
                        </td>
                        <!-- Sticky IP Address Column -->
                        <td class="sticky-col-2" style="position: sticky; left: 45px; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem; text-align: center; border-right: 1px solid rgba(220, 38, 38, 0.2); color: #60a5fa; font-family: monospace; font-size: 11px; font-weight: 600;">
                            <?= htmlspecialchars($ban['ip_address'] ?? '-') ?>
                        </td>
                        <!-- Severity -->
                        <td style="padding: 1rem; text-align: center; vertical-align: top;">
                            <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: <?= $severityColor ?>; color: #fff; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.5px;">
                                <?= strtoupper($severity) ?>
                            </span>
                        </td>
                        <!-- Ban Type -->
                        <td style="padding: 1rem; vertical-align: top;">
                            <span class="text-xs px-2.5 py-1 rounded font-mono" style="background: rgba(220,38,38,0.15); color: #fca5a5; display: inline-block; border: 1px solid rgba(220,38,38,0.3);">
                                <?= htmlspecialchars($ban['ban_type'] ?? 'automatic') ?>
                            </span>
                        </td>
                        <!-- Score -->
                        <td style="padding: 1rem; text-align: center; color: #fbbf24; font-family: monospace; font-weight: 700; font-size: 14px; vertical-align: top;">
                            <?= htmlspecialchars($ban['score'] ?? '0') ?>
                        </td>
                        <!-- Scan Patterns -->
                        <td style="padding: 1rem; color: #e5e5e5; line-height: 1.6; vertical-align: top; min-width: 200px; font-size: 10px;">
                            <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                                <?= htmlspecialchars($scanPatternsFormatted) ?>
                            </div>
                        </td>
                        <!-- Paths Accessed -->
                        <td style="padding: 1rem; color: #d0d0d0; font-family: 'Courier New', monospace; font-size: 10px; line-height: 1.5; vertical-align: top; min-width: 200px;">
                            <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                                <?= htmlspecialchars($pathsFormatted) ?>
                            </div>
                        </td>
                        <!-- Honeypot -->
                        <td style="padding: 1rem; text-align: center; vertical-align: top;">
                            <?php if ($honeypotTriggered) { ?>
                                <div class="flex flex-col items-center gap-1">
                                    <span class="text-xs px-2 py-1 rounded" style="background: rgba(153, 27, 27, 0.3); color: #ef4444; font-weight: 600;">
                                        <i class="fas fa-spider mr-1"></i>YES
                                    </span>
                                    <?php if ($honeypotPath !== '-') { ?>
                                        <span class="text-xs text-gray-400 font-mono">
                                            <?= htmlspecialchars($honeypotPath) ?>
                                        </span>
                                    <?php } ?>
                                </div>
                            <?php } else { ?>
                                <span class="text-xs text-gray-500">-</span>
                            <?php } ?>
                        </td>
                        <!-- User Agent -->
                        <td style="padding: 1rem; color: #a0a0a0; font-size: 10px; line-height: 1.4; vertical-align: top; word-break: break-word; min-width: 250px;">
                            <?= htmlspecialchars($ban['user_agent'] ? (strlen($ban['user_agent']) > 100 ? substr($ban['user_agent'], 0, 100) . '...' : $ban['user_agent']) : '-') ?>
                        </td>
                        <!-- User Agent Type -->
                        <td style="padding: 1rem; text-align: center; vertical-align: top;">
                            <span class="text-xs px-2 py-1 rounded" style="background: rgba(139,92,246,0.15); color: #c4b5fd; border: 1px solid rgba(139,92,246,0.3);">
                                <?= htmlspecialchars($ban['user_agent_type'] ?? 'unknown') ?>
                            </span>
                        </td>
                        <!-- Banned At -->
                        <td style="padding: 1rem; color: #f97316; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                            <div><?= $bannedAt ?></div>
                        </td>
                        <!-- Expires At -->
                        <td style="padding: 1rem; color: #b0b0b0; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                            <div><?= $expiresAt ?></div>
                        </td>
                        <!-- Violation Count -->
                        <td style="padding: 1rem; text-align: center; color: #ef4444; font-family: monospace; font-weight: 600; vertical-align: top; white-space: nowrap;">
                            <?= htmlspecialchars($ban['violation_count'] ?? '1') ?>
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
                (<?= count($bans) ?> ban in questa pagina)
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

        <div class="mt-4 p-3 rounded" style="background: rgba(220, 38, 38, 0.1); border-left: 3px solid #dc2626;">
            <div class="flex items-start gap-2">
                <i class="fas fa-shield-virus text-red-400"></i>
                <div class="text-sm text-gray-300">
                    <strong class="text-white">Sistema Anti-Scan Enterprise:</strong>
                    I ban sono memorizzati nella tabella <code class="text-red-400">vulnerability_scan_bans</code> con architettura dual-write (Redis + Database).
                    Il rilevamento automatico include: percorsi critici (/.env, /.git), scansione CMS (/wp-admin), file di configurazione, user-agent scanner, accesso honeypot e 404 eccessivi.
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- System Information -->
<div class="card" style="margin-top: 2rem; background: linear-gradient(135deg, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.15) 100%); border: 1px solid rgba(73, 73, 74, 0.3);">
    <div class="flex items-start gap-4">
        <div class="text-4xl">
            <i class="fas fa-shield-virus" style="color: #ef4444;"></i>
        </div>
        <div style="flex: 1;">
            <h3 class="mb-2" style="color: #fca5a5; font-weight: 700;">
                Protezione Anti-Scan Enterprise Galaxy Attiva
            </h3>
            <p style="color: #e5e7eb; font-size: 0.875rem; line-height: 1.6; margin-bottom: 0.75rem;">
                Il sistema monitora continuamente i tentativi di scansione vulnerabilità con rilevamento in tempo reale e ban automatico degli IP.
            </p>

            <div style="color: #d1d5db; font-size: 0.8125rem; line-height: 1.8;">
                <strong style="color: #fbbf24;">Funzionalità di Rilevamento:</strong>
                <ul style="list-style: none; padding-left: 0; margin-top: 0.5rem;">
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Oltre 50 endpoint Honeypot (/.env, /phpinfo.php, /wp-admin, ecc.)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Sistema di punteggio progressivo (soglia: 50 punti = ban)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Rilevamento user-agent scanner (sqlmap, nikto, nmap, ecc.)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Tracciamento 404 eccessivi (5+/10+/20+ in 5 minuti)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Architettura dual-write (Redis per velocità, Database per persistenza)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Durata ban automatica: 24h (standard), 7 giorni (honeypot)</li>
                </ul>
            </div>

            <div class="mt-3" style="font-size: 0.75rem; color: #d1d5db;">
                <i class="fas fa-clock mr-1"></i>
                Ultimo aggiornamento: <?= date('d/m/Y H:i:s') ?>
            </div>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
/* Table row hover effect */
.ban-row:hover {
    background: rgba(220, 38, 38, 0.1) !important;
}

/* Smooth scrolling */
.bans-table-container {
    scroll-behavior: smooth;
}

/* Sticky column shadows */
.sticky-col::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 1px;
    box-shadow: 2px 0 4px rgba(0, 0, 0, 0.3);
}

.sticky-col-2::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 1px;
    box-shadow: 2px 0 4px rgba(0, 0, 0, 0.3);
}
</style>

<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE GALAXY: Refresh database bans table (real-time update)
async function refreshDatabaseBans() {
    console.debug('🔄 Refreshing database bans...');

    // Get current pagination settings
    const bansPerPageSelect = document.getElementById('bansPerPage');
    const limit = bansPerPageSelect ? parseInt(bansPerPageSelect.value) : 50;

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

        // ENTERPRISE GALAXY: Use admin API endpoint with cache-busting
        const timestamp = Date.now();
        const protocol = window.location.protocol;
        const host = window.location.host;
        const url = `${protocol}//${host}/admin_${adminHash}/api/anti-scan/database?limit=${limit}&_=${timestamp}`;

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
        console.debug('✅ Bans count:', data.bans ? data.bans.length : 0, '/', data.total);

        if (data.success) {
            // Update total count in header
            const headerTitle = document.querySelector('.card h3 span');
            if (headerTitle && headerTitle.textContent.includes('Totale:')) {
                headerTitle.innerHTML = `<i class="fas fa-database mr-3"></i>Ban per Scansioni Vulnerabilità (Totale: ${data.total})`;
            }

            // Update severity counts
            updateSeverityCounts(data.severity_counts, data.stats);

            // Update table
            updateBansTable(data.bans);

            // Show success message
            console.info(`✅ Database refreshed successfully! Total: ${data.total}, Shown: ${data.bans.length}`);

            // Visual feedback
            if (button) {
                button.innerHTML = '<i class="fas fa-check mr-1"></i>Aggiornato!';
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                }, 1500);
            }
        } else {
            throw new Error(data.message || 'Impossibile caricare i ban');
        }

    } catch (error) {
        console.error('❌ Failed to refresh database bans:', error);
        alert('❌ Impossibile aggiornare i ban dal database: ' + error.message);

        if (button) {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }
}

// Update severity count cards
function updateSeverityCounts(counts, stats) {
    const dbStatsGrid = document.getElementById('db-bans-stats');
    if (!dbStatsGrid) {
        console.warn('⚠️ Database stats grid not found');
        return;
    }

    // Total DB Bans
    const totalCard = dbStatsGrid.querySelector('[data-stat="total"] .stat-value');
    if (totalCard && stats) {
        totalCard.textContent = stats.total_bans || 0;
    }

    // Critical
    const criticalCard = dbStatsGrid.querySelector('[data-stat="critical"] .stat-value');
    if (criticalCard) {
        criticalCard.textContent = counts['critical'] || 0;
    }

    // High
    const highCard = dbStatsGrid.querySelector('[data-stat="high"] .stat-value');
    if (highCard) {
        highCard.textContent = counts['high'] || 0;
    }

    // Medium
    const mediumCard = dbStatsGrid.querySelector('[data-stat="medium"] .stat-value');
    if (mediumCard) {
        mediumCard.textContent = counts['medium'] || 0;
    }

    // Low
    const lowCard = dbStatsGrid.querySelector('[data-stat="low"] .stat-value');
    if (lowCard) {
        lowCard.textContent = counts['low'] || 0;
    }

    // Honeypot
    const honeypotCard = dbStatsGrid.querySelector('[data-stat="honeypot"] .stat-value');
    if (honeypotCard && stats) {
        honeypotCard.textContent = stats.honeypot_count || 0;
    }

    console.debug('✅ Severity counts updated:', counts);
}

// Update bans table with fresh data
function updateBansTable(bans) {
    // ENTERPRISE TIPS: Check if bans is empty FIRST, before looking for DOM elements
    if (bans.length === 0) {
        console.info('📊 No bans in database - showing success message');

        // Find the table card container (might exist or might not)
        let card = document.querySelector('.bans-table-container')?.closest('.card');
        if (!card) {
            // Try to find card by h3 containing "Ban per Scansioni Vulnerabilità"
            const allH3 = document.querySelectorAll('.card h3');
            for (const h3 of allH3) {
                if (h3.textContent.includes('Ban per Scansioni Vulnerabilità')) {
                    card = h3.closest('.card');
                    break;
                }
            }
        }

        if (card) {
            // Replace entire card with "no bans" message
            card.innerHTML = `
                <h3 class="flex items-center justify-between">
                    <span>
                        <i class="fas fa-database mr-3"></i>Ban per Scansioni Vulnerabilità (Totale: 0)
                    </span>
                    <button onclick="refreshDatabaseBans()" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 8px 16px;">
                        <i class="fas fa-sync-alt mr-1"></i>Aggiorna Database
                    </button>
                </h3>
                <div class="alert alert-success" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3); margin-top: 1rem;">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-shield-check text-green-400 text-2xl"></i>
                        <div>
                            <h4 class="text-white mb-1">Nessuna Scansione Vulnerabilità Rilevata</h4>
                            <p class="text-sm text-gray-300">
                                Eccellente! Non sono stati rilevati tentativi di scansione. Il tuo sistema anti-scan è attivo e pronto. 🎉
                            </p>
                            <p class="text-xs text-gray-400 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Il sistema rileva automaticamente e banna gli IP che tentano di scansionare vulnerabilità, accedere agli endpoint honeypot o generare 404 eccessivi.
                            </p>
                        </div>
                    </div>
                </div>
            `;
        }
        return;
    }

    // Table should exist if we have bans, find it
    const tbody = document.querySelector('.bans-table tbody');
    if (!tbody) {
        console.warn('⚠️ Table tbody not found - page may need full reload');
        // Reload the page to get proper table structure
        window.location.reload();
        return;
    }

    // Generate table rows
    const severityColors = {
        'critical': '#7f1d1d',
        'high': '#ea580c',
        'medium': '#f59e0b',
        'low': '#10b981'
    };

    const rows = bans.map(ban => {
        const severity = (ban.severity || 'medium').toLowerCase();
        const severityColor = severityColors[severity] || severityColors['medium'];
        const bannedAt = ban.banned_at ? new Date(ban.banned_at).toLocaleString('it-IT') : '-';
        const expiresAt = ban.expires_at ? new Date(ban.expires_at).toLocaleString('it-IT') : '-';

        let scanPatterns = [];
        let pathsAccessed = [];
        try {
            scanPatterns = ban.scan_patterns ? JSON.parse(ban.scan_patterns) : [];
            pathsAccessed = ban.paths_accessed ? JSON.parse(ban.paths_accessed) : [];
        } catch (e) {
            scanPatterns = [];
            pathsAccessed = [];
        }

        const scanPatternsFormatted = scanPatterns.length > 0 ? scanPatterns.join(', ') : '-';
        let pathsFormatted = pathsAccessed.length > 0 ? pathsAccessed.slice(0, 5).join(', ') : '-';
        if (pathsAccessed.length > 5) {
            pathsFormatted += ` (+${pathsAccessed.length - 5} more)`;
        }

        const honeypotTriggered = ban.honeypot_triggered ? true : false;
        const honeypotPath = ban.honeypot_path || '-';

        return `
            <tr class="ban-row" style="border-bottom: 1px solid rgba(55, 65, 81, 0.2); background: rgba(26, 26, 46, 0.4); transition: all 0.2s ease;">
                <td class="sticky-col" style="position: sticky; left: 0; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem 0.75rem; text-align: center; font-weight: bold; color: #ef4444; border-right: 1px solid rgba(220, 38, 38, 0.2); font-size: 13px;">
                    #${ban.id || '-'}
                </td>
                <td class="sticky-col-2" style="position: sticky; left: 45px; z-index: 2; background: rgba(26, 26, 46, 0.95); padding: 1rem; text-align: center; border-right: 1px solid rgba(220, 38, 38, 0.2); color: #60a5fa; font-family: monospace; font-size: 11px; font-weight: 600;">
                    ${escapeHtml(ban.ip_address || '-')}
                </td>
                <td style="padding: 1rem; text-align: center; vertical-align: top;">
                    <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: ${severityColor}; color: #fff; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.5px;">
                        ${severity.toUpperCase()}
                    </span>
                </td>
                <td style="padding: 1rem; vertical-align: top;">
                    <span class="text-xs px-2.5 py-1 rounded font-mono" style="background: rgba(220,38,38,0.15); color: #fca5a5; display: inline-block; border: 1px solid rgba(220,38,38,0.3);">
                        ${escapeHtml(ban.ban_type || 'automatic')}
                    </span>
                </td>
                <td style="padding: 1rem; text-align: center; color: #fbbf24; font-family: monospace; font-weight: 700; font-size: 14px; vertical-align: top;">
                    ${ban.score || '0'}
                </td>
                <td style="padding: 1rem; color: #e5e5e5; line-height: 1.6; vertical-align: top; min-width: 200px; font-size: 10px;">
                    <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                        ${escapeHtml(scanPatternsFormatted)}
                    </div>
                </td>
                <td style="padding: 1rem; color: #d0d0d0; font-family: 'Courier New', monospace; font-size: 10px; line-height: 1.5; vertical-align: top; min-width: 200px;">
                    <div style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                        ${escapeHtml(pathsFormatted)}
                    </div>
                </td>
                <td style="padding: 1rem; text-align: center; vertical-align: top;">
                    ${honeypotTriggered ? `
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                            <span class="text-xs px-2 py-1 rounded" style="background: rgba(153, 27, 27, 0.3); color: #ef4444; font-weight: 600;">
                                <i class="fas fa-spider mr-1"></i>YES
                            </span>
                            ${honeypotPath !== '-' ? `<span class="text-xs text-gray-400 font-mono">${escapeHtml(honeypotPath)}</span>` : ''}
                        </div>
                    ` : '<span class="text-xs text-gray-500">-</span>'}
                </td>
                <td style="padding: 1rem; color: #a0a0a0; font-size: 10px; line-height: 1.4; vertical-align: top; word-break: break-word; min-width: 250px;">
                    ${escapeHtml((ban.user_agent && ban.user_agent.length > 100) ? ban.user_agent.substring(0, 100) + '...' : (ban.user_agent || '-'))}
                </td>
                <td style="padding: 1rem; text-align: center; vertical-align: top;">
                    <span class="text-xs px-2 py-1 rounded" style="background: rgba(139,92,246,0.15); color: #c4b5fd; border: 1px solid rgba(139,92,246,0.3);">
                        ${escapeHtml(ban.user_agent_type || 'unknown')}
                    </span>
                </td>
                <td style="padding: 1rem; color: #f97316; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                    <div>${bannedAt}</div>
                </td>
                <td style="padding: 1rem; color: #b0b0b0; font-size: 10px; font-family: monospace; vertical-align: top; white-space: nowrap; min-width: 150px;">
                    <div>${expiresAt}</div>
                </td>
                <td style="padding: 1rem; text-align: center; color: #ef4444; font-family: monospace; font-weight: 600; vertical-align: top; white-space: nowrap;">
                    ${ban.violation_count || '1'}
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
    console.debug(`✅ Table updated with ${bans.length} rows`);
}

// Helper: Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Change bans per page and reload
function changeBansPerPage() {
    const limit = document.getElementById('bansPerPage').value;
    window.location.href = `?limit=${limit}`;
}

// Navigate to specific page
function navigateToPage(page) {
    const limit = document.getElementById('bansPerPage')?.value || 50;
    window.location.href = `?limit=${limit}&page=${page}`;
}

console.info('✅ Enterprise Anti-Scan System loaded');
</script>
