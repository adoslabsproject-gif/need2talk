<!-- ENTERPRISE GALAXY LEGITIMATE BOTS MONITORING VIEW -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-robot mr-3"></i>
    Dashboard Bot Legittimi
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; font-weight: 600;">
        <i class="fas fa-shield-check mr-1"></i>DNS VERIFICATO
    </span>
</h2>

<!-- ENTERPRISE GALAXY: Database Summary Statistics -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #4ade80;">
            <?= $database_stats['total_bots'] ?? 0 ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-robot mr-2"></i>Bot Unici (DB)
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #60a5fa;">
            <?= number_format($database_stats['visits_today'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-calendar-day mr-2"></i>Visite Oggi
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
        <span class="stat-value" style="color: #a78bfa;">
            <?= number_format($database_stats['visits_week'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-calendar-week mr-2"></i>Visite Questa Settimana
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #fbbf24;">
            <?= number_format($database_stats['visits_month'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-calendar-alt mr-2"></i>Visite Questo Mese
        </div>
    </div>

    <div class="stat-card" style="border-left: 3px solid #ef4444;">
        <span class="stat-value" style="color: #f87171; font-size: 1.5rem;">
            <?= number_format($database_stats['avg_response_time'] ?? 0, 2) ?>ms
        </span>
        <div class="stat-label">
            <i class="fas fa-tachometer-alt mr-2"></i>Tempo Risposta Medio
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-value">
            <button onclick="refreshBotStats()" class="btn btn-primary btn-sm" style="font-size: 12px; padding: 8px 16px;">
                <i class="fas fa-sync-alt mr-2"></i>Aggiorna
            </button>
        </span>
        <div class="stat-label">Azioni Rapide</div>
    </div>
</div>

<!-- Top Bot Crawlers -->
<div class="card" style="margin-top: 2rem;">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-star mr-3"></i>Top Bot Crawler
        </span>
        <span class="text-xs text-gray-400">
            <i class="fas fa-info-circle mr-1"></i>Bot Verificati Più Attivi
        </span>
    </h3>

    <?php if (empty($top_bots)) { ?>
        <div class="alert alert-info">
            <div class="flex items-center gap-3">
                <i class="fas fa-info-circle text-blue-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessuna Attività Bot</h4>
                    <p class="text-sm text-gray-300">
                        Nessun bot legittimo è stato ancora verificato. Una volta che i motori di ricerca e i crawler dei social media visiteranno il tuo sito, appariranno qui.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="top-bots-table">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">Posizione</th>
                        <th style="min-width: 200px;">Nome Bot</th>
                        <th style="width: 120px; text-align: center;">Richieste</th>
                        <th style="width: 100px; text-align: center;">IP Unici</th>
                        <th style="min-width: 180px;">Ultimo Accesso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_bots as $index => $bot) {
                        $rank = $index + 1;
                        $rankEmoji = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : ''));
                        $lastSeen = $bot['last_seen'] ? date('d/m/Y H:i:s', $bot['last_seen']) : 'Mai';
                        ?>
                    <tr>
                        <td style="text-align: center; font-weight: bold; color: #fbbf24; font-size: 1.2rem;">
                            <?= $rankEmoji ?> <?= $rank ?>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-robot" style="color: #4ade80;"></i>
                                <span class="font-semibold"><?= htmlspecialchars($bot['name']) ?></span>
                            </div>
                        </td>
                        <td style="text-align: center; color: #60a5fa; font-weight: 600; font-family: monospace;">
                            <?= number_format($bot['count']) ?>
                        </td>
                        <td style="text-align: center; color: #a78bfa; font-weight: 600;">
                            <?= count($bot['ips']) ?>
                        </td>
                        <td style="color: #d1d5db; font-family: monospace; font-size: 0.875rem;">
                            <?= $lastSeen ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>

<!-- Recent Verifications -->
<div class="card" style="margin-top: 2rem;">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-history mr-3"></i>Verifiche Recenti (Ultime 20)
        </span>
        <span class="text-xs text-gray-400">
            <i class="fas fa-database mr-1"></i>Redis Cache: <?= $redis_keys_count ?? 0 ?> chiavi
        </span>
    </h3>

    <?php if (empty($recent_verifications)) { ?>
        <div class="alert alert-info">
            <div class="flex items-center gap-3">
                <i class="fas fa-info-circle text-blue-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessuna Verifica Registrata</h4>
                    <p class="text-sm text-gray-300">
                        Nessun tentativo di verifica bot è stato registrato. Il sistema verificherà automaticamente i bot legittimi utilizzando il DNS reverse lookup.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="recent-verifications-table">
                <thead>
                    <tr>
                        <th style="min-width: 180px;">Indirizzo IP</th>
                        <th style="min-width: 200px;">Nome Bot</th>
                        <th style="width: 120px; text-align: center;">Stato</th>
                        <th style="min-width: 180px;">Data/Ora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_verifications as $verification) {
                        $verified = $verification['verified'] ?? false;
                        $timestamp = $verification['timestamp'] ? date('d/m/Y H:i:s', strtotime($verification['timestamp'])) : '-';
                        ?>
                    <tr>
                        <td style="color: #60a5fa; font-family: monospace; font-weight: 600;">
                            <?= htmlspecialchars($verification['ip']) ?>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-robot" style="color: <?= $verified ? '#4ade80' : '#ef4444' ?>;"></i>
                                <span><?= htmlspecialchars($verification['bot_name']) ?></span>
                            </div>
                        </td>
                        <td style="text-align: center;">
                            <?php if ($verified) { ?>
                                <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; display: inline-block;">
                                    <i class="fas fa-check-circle mr-1"></i>VERIFICATO
                                </span>
                            <?php } else { ?>
                                <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: rgba(239, 68, 68, 0.2); color: #f87171; display: inline-block;">
                                    <i class="fas fa-times-circle mr-1"></i>FALLITO
                                </span>
                            <?php } ?>
                        </td>
                        <td style="color: #d1d5db; font-family: monospace; font-size: 0.875rem;">
                            <?= $timestamp ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>

<!-- ENTERPRISE GALAXY: Complete Bot Visits Table (All Columns) -->
<div class="card" style="margin-top: 2rem;">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-table mr-3"></i>Log Completo Visite Bot (Database)
        </span>
        <span class="text-xs text-gray-400">
            <i class="fas fa-database mr-1"></i>Ultime 50 visite dalla tabella legitimate_bot_visits
        </span>
    </h3>

    <?php if (empty($all_bot_visits)) { ?>
        <div class="alert alert-info">
            <div class="flex items-center gap-3">
                <i class="fas fa-info-circle text-blue-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessuna Visita Bot Registrata</h4>
                    <p class="text-sm text-gray-300">
                        Nessuna visita di bot legittimi è stata ancora registrata nel database. Una volta che i bot visitano il tuo sito e vengono verificati tramite DNS, saranno tracciati qui.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div class="table-responsive" style="overflow-x: auto;">
            <table class="complete-bot-visits-table" style="min-width: 1200px; font-size: 0.875rem;">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Indirizzo IP</th>
                        <th style="min-width: 150px;">Nome Bot</th>
                        <th style="min-width: 250px;">Percorso Richiesta</th>
                        <th style="width: 100px; text-align: center;">Metodo</th>
                        <th style="width: 90px; text-align: center;">Stato</th>
                        <th style="width: 110px; text-align: right;">Risposta (ms)</th>
                        <th style="min-width: 95px; text-align: center;">Data Visita</th>
                        <th style="min-width: 160px;">Creato il</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_bot_visits as $visit) {
                        $statusColor = match(true) {
                            $visit['response_status'] >= 200 && $visit['response_status'] < 300 => '#4ade80',
                            $visit['response_status'] >= 300 && $visit['response_status'] < 400 => '#fbbf24',
                            $visit['response_status'] >= 400 && $visit['response_status'] < 500 => '#f87171',
                            $visit['response_status'] >= 500 => '#ef4444',
                            default => '#d1d5db'
                        };
                        $responseTime = $visit['response_time_ms'] ? number_format($visit['response_time_ms'], 2) : '-';
                        ?>
                    <tr>
                        <td style="color: #60a5fa; font-family: monospace; font-weight: 600; font-size: 0.8125rem;">
                            <?= htmlspecialchars($visit['ip_address']) ?>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-robot" style="color: #4ade80;"></i>
                                <span style="font-weight: 500;"><?= htmlspecialchars($visit['bot_name']) ?></span>
                            </div>
                        </td>
                        <td style="color: #d1d5db; font-family: monospace; font-size: 0.8125rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($visit['request_path']) ?>">
                            <?= htmlspecialchars($visit['request_path']) ?>
                        </td>
                        <td style="text-align: center; font-family: monospace; font-weight: 600; color: #a78bfa;">
                            <?= htmlspecialchars($visit['request_method'] ?? 'GET') ?>
                        </td>
                        <td style="text-align: center; font-weight: 700; font-family: monospace;" >
                            <span style="color: <?= $statusColor ?>;"><?= $visit['response_status'] ?></span>
                        </td>
                        <td style="text-align: right; font-family: monospace; color: #fbbf24; font-weight: 600;">
                            <?= $responseTime ?>
                        </td>
                        <td style="text-align: center; color: #d1d5db; font-family: monospace; font-size: 0.8125rem;">
                            <?= htmlspecialchars($visit['visit_date'] ?? '-') ?>
                        </td>
                        <td style="color: #d1d5db; font-family: monospace; font-size: 0.8125rem;">
                            <?= date('d/m/Y H:i:s', strtotime($visit['created_at'])) ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4" style="font-size: 0.8125rem; color: #9ca3af; text-align: center;">
            <i class="fas fa-info-circle mr-1"></i>
            Visualizzate le ultime 50 visite. Dati partizionati per mese per prestazioni ottimali.
        </div>
    <?php } ?>
</div>

<!-- ENTERPRISE GALAXY: IP Whitelist Management (CRUD Interface) -->
<div class="card" style="margin-top: 2rem;">
    <h3 class="flex items-center justify-between">
        <span>
            <i class="fas fa-shield-alt mr-3"></i>Gestione Whitelist IP
        </span>
        <button onclick="showAddIPModal()" class="btn btn-success btn-sm" style="font-size: 12px; padding: 8px 16px;">
            <i class="fas fa-plus mr-2"></i>Aggiungi Indirizzo IP
        </button>
    </h3>

    <p style="color: #9ca3af; font-size: 0.875rem; margin-bottom: 1.5rem;">
        <i class="fas fa-info-circle mr-1"></i>
        Gli IP in whitelist bypassano TUTTI i controlli di sicurezza (anti-scan, rate limits, rilevamento vulnerabilità). Usa con estrema cautela.
    </p>

    <?php if (empty($ip_whitelist)) { ?>
        <div class="alert alert-info">
            <div class="flex items-center gap-3">
                <i class="fas fa-info-circle text-blue-400 text-2xl"></i>
                <div>
                    <h4 class="text-white mb-1">Nessuna Voce in Whitelist</h4>
                    <p class="text-sm text-gray-300">
                        Nessun indirizzo IP è attualmente in whitelist. Aggiungi IP fidati per bypassare i controlli di sicurezza.
                    </p>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="ip-whitelist-table" style="font-size: 0.875rem;">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">ID</th>
                        <th style="min-width: 150px;">Indirizzo IP</th>
                        <th style="min-width: 180px;">Etichetta</th>
                        <th style="width: 120px; text-align: center;">Tipo</th>
                        <th style="width: 100px; text-align: center;">Stato</th>
                        <th style="min-width: 150px;">Scade il</th>
                        <th style="min-width: 200px;">Note</th>
                        <th style="width: 150px; text-align: center;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ip_whitelist as $entry) {
                        $typeColors = [
                            'owner' => '#fbbf24',
                            'staff' => '#60a5fa',
                            'bot' => '#4ade80',
                            'api_client' => '#a78bfa',
                            'other' => '#9ca3af',
                        ];
                        $typeColor = $typeColors[$entry['type']] ?? '#9ca3af';
                        $isActive = $entry['is_active'];
                        $isExpired = $entry['expires_at'] && strtotime($entry['expires_at']) < time();
                        ?>
                    <tr style="opacity: <?= $isActive && !$isExpired ? '1' : '0.6' ?>;">
                        <td style="text-align: center; color: #9ca3af; font-family: monospace;">
                            <?= $entry['id'] ?>
                        </td>
                        <td style="color: #60a5fa; font-family: monospace; font-weight: 600;">
                            <?= htmlspecialchars($entry['ip_address']) ?>
                        </td>
                        <td style="color: #e5e7eb; font-weight: 500;">
                            <?= htmlspecialchars($entry['label']) ?>
                        </td>
                        <td style="text-align: center;">
                            <span class="text-xs px-2 py-1 rounded font-semibold" style="background: rgba(<?= hexToRgb($typeColor) ?>, 0.2); color: <?= $typeColor ?>; display: inline-block;">
                                <?= strtoupper($entry['type']) ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <?php if ($isExpired) { ?>
                                <span class="text-xs px-2 py-1 rounded font-semibold" style="background: rgba(239, 68, 68, 0.2); color: #f87171; display: inline-block;">
                                    <i class="fas fa-clock mr-1"></i>SCADUTO
                                </span>
                            <?php } elseif ($isActive) { ?>
                                <span class="text-xs px-2 py-1 rounded font-semibold" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; display: inline-block;">
                                    <i class="fas fa-check-circle mr-1"></i>ATTIVO
                                </span>
                            <?php } else { ?>
                                <span class="text-xs px-2 py-1 rounded font-semibold" style="background: rgba(239, 68, 68, 0.2); color: #f87171; display: inline-block;">
                                    <i class="fas fa-ban mr-1"></i>INATTIVO
                                </span>
                            <?php } ?>
                        </td>
                        <td style="color: #d1d5db; font-family: monospace; font-size: 0.8125rem;">
                            <?= $entry['expires_at'] ? date('d/m/Y H:i', strtotime($entry['expires_at'])) : '<span style="color: #4ade80;">Mai</span>' ?>
                        </td>
                        <td style="color: #9ca3af; font-size: 0.8125rem; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($entry['notes'] ?? '') ?>">
                            <?= htmlspecialchars($entry['notes'] ?? '-') ?>
                        </td>
                        <td style="text-align: center;">
                            <button onclick="editIPEntry(<?= $entry['id'] ?>)" class="btn btn-primary btn-xs" style="font-size: 11px; padding: 4px 8px; margin-right: 4px;">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteIPEntry(<?= $entry['id'] ?>, '<?= htmlspecialchars($entry['ip_address']) ?>')" class="btn btn-danger btn-xs" style="font-size: 11px; padding: 4px 8px;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4" style="font-size: 0.8125rem; color: #9ca3af; text-align: center;">
            <i class="fas fa-database mr-1"></i>
            Totale: <?= count($ip_whitelist) ?> IP in whitelist | Cache in Redis per 5 minuti
        </div>
    <?php } ?>
</div>

<?php
// Helper function for converting hex to RGB
function hexToRgb($hex)
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }

    return hexdec(substr($hex, 0, 2)).','.hexdec(substr($hex, 2, 2)).','.hexdec(substr($hex, 4, 2));
}
            ?>

<!-- System Information -->
<div class="card" style="margin-top: 2rem; background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%); border: 1px solid rgba(34, 197, 94, 0.3);">
    <div class="flex items-start gap-4">
        <div class="text-4xl">
            <i class="fas fa-shield-check" style="color: #4ade80;"></i>
        </div>
        <div style="flex: 1;">
            <h3 class="mb-2" style="color: #4ade80; font-weight: 700;">
                Protezione Bot Enterprise Galaxy Attiva
            </h3>
            <p style="color: #e5e7eb; font-size: 0.875rem; line-height: 1.6; margin-bottom: 0.75rem;">
                Il sistema verifica automaticamente i bot legittimi utilizzando la verifica DNS inversa per prevenire lo spoofing del User-Agent e garantire che solo i crawler reali accedano al tuo sito.
            </p>

            <div style="color: #d1d5db; font-size: 0.8125rem; line-height: 1.8;">
                <strong style="color: #fbbf24;">Funzionalità di Protezione:</strong>
                <ul style="list-style: none; padding-left: 0; margin-top: 0.5rem;">
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Verifica DNS Inversa (Googlebot, Bingbot, ecc.)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Whitelist User-Agent (40+ bot legittimi)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Cache Redis (TTL 24h, 99% cache hit rate)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Protezione Anti-Spoofing (verifica in 3 fasi)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Zero Overhead (bot verificati saltano tutti i controlli anti-scan)</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>Performance: &lt;2ms con cache, ~100ms prima visita</li>
                </ul>
            </div>

            <div class="mt-3" style="font-size: 0.75rem; color: #d1d5db;">
                <i class="fas fa-info-circle mr-1"></i>
                Bot supportati: Google, Bing, Yahoo, Facebook, Twitter, LinkedIn, UptimeRobot, Ahrefs, SEMrush e altri 30+
            </div>

            <div class="mt-2" style="font-size: 0.75rem; color: #d1d5db;">
                <i class="fas fa-clock mr-1"></i>
                Ultimo aggiornamento: <?= date('d/m/Y H:i:s') ?>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE GALAXY: Refresh bot statistics (real-time update)
async function refreshBotStats() {
    console.debug('🔄 Refreshing bot statistics...');

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
        const protocol = window.location.protocol;
        const host = window.location.host;
        const url = `${protocol}//${host}/admin_${adminHash}/api/legitimate-bots/stats?_=${timestamp}`;

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

        if (data.success) {
            // Update statistics cards
            updateStatsCards(data.stats);

            // Update top bots table
            updateTopBotsTable(data.top_bots);

            // Update recent verifications table
            updateRecentVerificationsTable(data.recent_verifications);

            console.info('✅ Bot statistics refreshed successfully!');

            // Visual feedback
            if (button) {
                button.innerHTML = '<i class="fas fa-check mr-1"></i>Aggiornato!';
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                }, 1500);
            }
        } else {
            throw new Error(data.error || 'Impossibile caricare le statistiche bot');
        }

    } catch (error) {
        console.error('❌ Failed to refresh bot statistics:', error);
        alert('❌ Impossibile aggiornare le statistiche bot: ' + error.message);

        if (button) {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }
}

// Update statistics cards
function updateStatsCards(stats) {
    // Update verified bots count
    const verifiedCard = document.querySelector('.stat-card:nth-child(1) .stat-value');
    if (verifiedCard) {
        verifiedCard.textContent = stats.total_verified_bots || 0;
    }

    // Update failed verifications count
    const failedCard = document.querySelector('.stat-card:nth-child(2) .stat-value');
    if (failedCard) {
        failedCard.textContent = stats.total_failed_verifications || 0;
    }

    // Update cache hit rate
    const cacheCard = document.querySelector('.stat-card:nth-child(3) .stat-value');
    if (cacheCard) {
        cacheCard.textContent = (stats.cache_hit_rate || 0).toFixed(1) + '%';
    }

    // Update DNS time saved
    const dnsCard = document.querySelector('.stat-card:nth-child(4) .stat-value');
    if (dnsCard) {
        dnsCard.textContent = (stats.dns_verifications_saved || 0).toLocaleString() + 'ms';
    }

    console.debug('✅ Stats cards updated');
}

// Update top bots table
function updateTopBotsTable(topBots) {
    const tbody = document.querySelector('.top-bots-table tbody');
    if (!tbody) {
        console.warn('⚠️ Top bots table not found');
        return;
    }

    if (topBots.length === 0) {
        // Show "no bots" message
        const card = tbody.closest('.card');
        if (card) {
            const content = card.querySelector('h3').nextElementSibling;
            content.outerHTML = `
                <div class="alert alert-info">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-info-circle text-blue-400 text-2xl"></i>
                        <div>
                            <h4 class="text-white mb-1">Nessuna Attività Bot</h4>
                            <p class="text-sm text-gray-300">
                                Nessun bot legittimo è stato ancora verificato. Una volta che i motori di ricerca e i crawler dei social media visiteranno il tuo sito, appariranno qui.
                            </p>
                        </div>
                    </div>
                </div>
            `;
        }
        return;
    }

    const rows = topBots.map((bot, index) => {
        const rank = index + 1;
        const rankEmoji = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : ''));
        const lastSeen = bot.last_seen ? new Date(bot.last_seen * 1000).toLocaleString('it-IT') : 'Mai';

        return `
            <tr>
                <td style="text-align: center; font-weight: bold; color: #fbbf24; font-size: 1.2rem;">
                    ${rankEmoji} ${rank}
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-robot" style="color: #4ade80;"></i>
                        <span class="font-semibold">${escapeHtml(bot.name)}</span>
                    </div>
                </td>
                <td style="text-align: center; color: #60a5fa; font-weight: 600; font-family: monospace;">
                    ${bot.count.toLocaleString()}
                </td>
                <td style="text-align: center; color: #a78bfa; font-weight: 600;">
                    ${bot.ips.length}
                </td>
                <td style="color: #d1d5db; font-family: monospace; font-size: 0.875rem;">
                    ${lastSeen}
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
    console.debug('✅ Top bots table updated');
}

// Update recent verifications table
function updateRecentVerificationsTable(verifications) {
    const tbody = document.querySelector('.recent-verifications-table tbody');
    if (!tbody) {
        console.warn('⚠️ Recent verifications table not found');
        return;
    }

    if (verifications.length === 0) {
        // Show "no verifications" message
        const card = tbody.closest('.card');
        if (card) {
            const content = card.querySelector('h3').nextElementSibling;
            content.outerHTML = `
                <div class="alert alert-info">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-info-circle text-blue-400 text-2xl"></i>
                        <div>
                            <h4 class="text-white mb-1">Nessuna Verifica Registrata</h4>
                            <p class="text-sm text-gray-300">
                                Nessun tentativo di verifica bot è stato registrato. Il sistema verificherà automaticamente i bot legittimi utilizzando il DNS reverse lookup.
                            </p>
                        </div>
                    </div>
                </div>
            `;
        }
        return;
    }

    const rows = verifications.map(verification => {
        const verified = verification.verified || false;
        const timestamp = verification.timestamp ? new Date(verification.timestamp * 1000).toLocaleString('it-IT') : '-';

        return `
            <tr>
                <td style="color: #60a5fa; font-family: monospace; font-weight: 600;">
                    ${escapeHtml(verification.ip)}
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-robot" style="color: ${verified ? '#4ade80' : '#ef4444'};"></i>
                        <span>${escapeHtml(verification.bot_name)}</span>
                    </div>
                </td>
                <td style="text-align: center;">
                    ${verified ? `
                        <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: rgba(34, 197, 94, 0.2); color: #4ade80; display: inline-block;">
                            <i class="fas fa-check-circle mr-1"></i>VERIFICATO
                        </span>
                    ` : `
                        <span class="text-xs px-3 py-1.5 rounded font-semibold" style="background: rgba(239, 68, 68, 0.2); color: #f87171; display: inline-block;">
                            <i class="fas fa-times-circle mr-1"></i>FALLITO
                        </span>
                    `}
                </td>
                <td style="color: #d1d5db; font-family: monospace; font-size: 0.875rem;">
                    ${timestamp}
                </td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
    console.debug('✅ Recent verifications table updated');
}

// Helper: Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================================================
// ENTERPRISE GALAXY: IP WHITELIST CRUD FUNCTIONS
// =============================================================================

/**
 * Show modal to add new IP address to whitelist
 */
function showAddIPModal() {
    const ip = prompt('Inserisci indirizzo IP da aggiungere alla whitelist (es. 95.230.116.76 o 2001:db8::1):');
    if (!ip) return;

    const label = prompt('Inserisci un\'etichetta descrittiva (es. "IP Casa Proprietario", "Server API Ufficio"):');
    if (!label) return;

    const type = prompt('Inserisci tipo (owner/staff/bot/api_client/other):', 'other');
    if (!type) return;

    const expires = confirm('Questo IP deve avere una data di scadenza?');
    let expiresAt = null;
    if (expires) {
        const days = prompt('Scade tra quanti giorni?', '30');
        if (days) {
            const date = new Date();
            date.setDate(date.getDate() + parseInt(days));
            expiresAt = date.toISOString().split('T')[0];
        }
    }

    const notes = prompt('Note opzionali (motivo per la whitelist):') || '';

    // Send request to server
    addIPToWhitelist(ip, label, type, expiresAt, notes);
}

/**
 * Add IP to whitelist via AJAX
 */
async function addIPToWhitelist(ip, label, type, expiresAt, notes) {
    try {
        const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
        const adminHash = pathMatch ? pathMatch[1] : '';

        if (!adminHash) {
            throw new Error('Hash URL admin non trovato');
        }

        const response = await fetch(`/admin_${adminHash}/api/ip-whitelist/add`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ip_address: ip,
                label: label,
                type: type,
                expires_at: expiresAt,
                notes: notes
            })
        });

        const data = await response.json();

        if (data.success) {
            alert('✅ IP aggiunto alla whitelist con successo!');
            location.reload(); // Reload to show new entry
        } else {
            throw new Error(data.error || 'Impossibile aggiungere IP');
        }
    } catch (error) {
        console.error('❌ Failed to add IP:', error);
        alert('❌ Impossibile aggiungere IP: ' + error.message);
    }
}

/**
 * Edit existing IP whitelist entry
 */
async function editIPEntry(id) {
    const newLabel = prompt('Inserisci nuova etichetta (lascia vuoto per saltare):');
    const newNotes = prompt('Inserisci nuove note (lascia vuoto per saltare):');

    if (!newLabel && !newNotes) {
        alert('Nessuna modifica effettuata.');
        return;
    }

    try {
        const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
        const adminHash = pathMatch ? pathMatch[1] : '';

        if (!adminHash) {
            throw new Error('Hash URL admin non trovato');
        }

        const payload = { id: id };
        if (newLabel) payload.label = newLabel;
        if (newNotes) payload.notes = newNotes;

        const response = await fetch(`/admin_${adminHash}/api/ip-whitelist/edit`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (data.success) {
            alert('✅ Voce IP aggiornata con successo!');
            location.reload();
        } else {
            throw new Error(data.error || 'Impossibile aggiornare IP');
        }
    } catch (error) {
        console.error('❌ Failed to edit IP:', error);
        alert('❌ Impossibile modificare IP: ' + error.message);
    }
}

/**
 * Delete IP from whitelist
 */
async function deleteIPEntry(id, ip) {
    if (!confirm(`⚠️ Sei sicuro di voler rimuovere "${ip}" dalla whitelist?\n\nQuesto IP sarà immediatamente soggetto a tutti i controlli di sicurezza.`)) {
        return;
    }

    try {
        const pathMatch = window.location.pathname.match(/\/admin_([a-f0-9]{16})/);
        const adminHash = pathMatch ? pathMatch[1] : '';

        if (!adminHash) {
            throw new Error('Hash URL admin non trovato');
        }

        const response = await fetch(`/admin_${adminHash}/api/ip-whitelist/delete`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ id: id })
        });

        const data = await response.json();

        if (data.success) {
            alert('✅ IP rimosso dalla whitelist con successo!');
            location.reload();
        } else {
            throw new Error(data.error || 'Impossibile eliminare IP');
        }
    } catch (error) {
        console.error('❌ Failed to delete IP:', error);
        alert('❌ Impossibile eliminare IP: ' + error.message);
    }
}

console.info('✅ Enterprise Legitimate Bots System loaded');
console.info('✅ IP Whitelist CRUD functions ready');
</script>
