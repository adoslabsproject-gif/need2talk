<!-- ENTERPRISE: Admin Audit Log View -->
<div class="mb-8">
    <h2 class="text-3xl font-bold bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent mb-2">
        🔍 Log di Audit Admin
    </h2>
    <p class="text-gray-400 text-sm">Traccia tutte le azioni amministrative con audit trail dettagliato</p>
</div>

<!-- ENTERPRISE: Statistics Cards -->
<div class="stats-grid mb-6">
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_actions']) ?></div>
        <div class="stat-label">Azioni Totali</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['actions_today']) ?></div>
        <div class="stat-label">Azioni Oggi</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['actions_24h']) ?></div>
        <div class="stat-label">Ultime 24 Ore</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['unique_admins']) ?></div>
        <div class="stat-label">Admin Attivi</div>
    </div>
</div>

<!-- ENTERPRISE: Top Actions & Admins -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Top Actions (Last 7 Days) -->
    <div class="card">
        <h3><i class="fas fa-chart-bar mr-2"></i>Azioni Principali (Ultimi 7 Giorni)</h3>
        <div class="space-y-2">
            <?php if (!empty($stats['top_actions'])): ?>
                <?php foreach ($stats['top_actions'] as $actionStat): ?>
                <div class="flex justify-between items-center p-3 rounded-lg" style="background: rgba(255,255,255,0.03);">
                    <span class="text-sm font-mono text-purple-300"><?= htmlspecialchars($actionStat['action']) ?></span>
                    <span class="text-sm font-bold text-gray-300"><?= number_format($actionStat['count']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-gray-400 text-sm">Nessun dato disponibile</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Most Active Admins (Last 7 Days) -->
    <div class="card">
        <h3><i class="fas fa-users-cog mr-2"></i>Admin Più Attivi (Ultimi 7 Giorni)</h3>
        <div class="space-y-2">
            <?php if (!empty($stats['top_admins'])): ?>
                <?php foreach ($stats['top_admins'] as $adminStat): ?>
                <div class="flex justify-between items-center p-3 rounded-lg" style="background: rgba(255,255,255,0.03);">
                    <div class="flex flex-col">
                        <span class="text-sm font-medium text-gray-200"><?= htmlspecialchars($adminStat['email'] ?? 'Sconosciuto') ?></span>
                        <?php if (!empty($adminStat['name'])): ?>
                        <span class="text-xs text-gray-400"><?= htmlspecialchars($adminStat['name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-sm font-bold text-purple-300"><?= number_format($adminStat['action_count']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-gray-400 text-sm">Nessun dato disponibile</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ENTERPRISE: Advanced Filters -->
<div class="card">
    <h3><i class="fas fa-filter mr-2"></i>Filtri Avanzati</h3>
    <form method="GET" action="audit" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Admin Filter -->
        <div>
            <label class="block text-sm text-gray-400 mb-2">Utente Admin</label>
            <select name="admin_id" class="form-control">
                <option value="">Tutti gli Admin</option>
                <?php foreach ($admin_users as $admin): ?>
                <option value="<?= htmlspecialchars($admin['id']) ?>" <?= $filters['admin_id'] == $admin['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($admin['email']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Action Type Filter -->
        <div>
            <label class="block text-sm text-gray-400 mb-2">Tipo di Azione</label>
            <select name="action" class="form-control">
                <option value="">Tutte le Azioni</option>
                <?php foreach ($action_types as $actionType): ?>
                <option value="<?= htmlspecialchars($actionType['action']) ?>" <?= $filters['action'] === $actionType['action'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($actionType['action']) ?> (<?= $actionType['count'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Date From -->
        <div>
            <label class="block text-sm text-gray-400 mb-2">Data Da</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
        </div>

        <!-- Date To -->
        <div>
            <label class="block text-sm text-gray-400 mb-2">Data A</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
        </div>

        <!-- Limit -->
        <div>
            <label class="block text-sm text-gray-400 mb-2">Elementi per Pagina</label>
            <select name="limit" class="form-control">
                <option value="25" <?= $filters['limit'] == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $filters['limit'] == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $filters['limit'] == 100 ? 'selected' : '' ?>>100</option>
                <option value="250" <?= $filters['limit'] == 250 ? 'selected' : '' ?>>250</option>
                <option value="500" <?= $filters['limit'] == 500 ? 'selected' : '' ?>>500</option>
            </select>
        </div>

        <!-- Action Buttons -->
        <div class="col-span-full flex gap-2">
            <button type="submit" class="btn btn-info">
                <i class="fas fa-search mr-2"></i>Applica Filtri
            </button>
            <a href="audit" class="btn">
                <i class="fas fa-times mr-2"></i>Pulisci Filtri
            </a>
            <button type="button" onclick="exportAuditLog('csv')" class="btn btn-success">
                <i class="fas fa-file-csv mr-2"></i>Esporta CSV
            </button>
            <button type="button" onclick="exportAuditLog('json')" class="btn btn-warning">
                <i class="fas fa-file-code mr-2"></i>Esporta JSON
            </button>
        </div>
    </form>
</div>

<!-- ENTERPRISE: Audit Log Table -->
<div class="card">
    <h3>
        <i class="fas fa-history mr-2"></i>Traccia di Audit
        <span class="text-sm font-normal text-gray-400 ml-2">
            (Visualizzazione di <?= count($audit_logs) ?> su <?= number_format($total_count) ?> totali)
        </span>
    </h3>

    <?php if (empty($audit_logs)): ?>
        <div class="text-center py-12">
            <i class="fas fa-inbox text-6xl text-gray-600 mb-4"></i>
            <p class="text-gray-400">Nessun log di audit trovato con i criteri selezionati</p>
        </div>
    <?php else: ?>
        <!-- ENTERPRISE: Table wrapper with sticky headers for better UX during scroll -->
        <div class="table-wrapper">
            <table class="sticky-header">
                <thead>
                    <tr>
                        <th class="text-left">ID</th>
                        <th class="text-left">Admin</th>
                        <th class="text-left">Azione</th>
                        <th class="text-left">Dettagli</th>
                        <th class="text-left">Indirizzo IP</th>
                        <th class="text-left">Data/Ora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_logs as $log): ?>
                    <tr>
                        <td class="font-mono text-sm text-gray-400">#<?= $log['id'] ?></td>
                        <td>
                            <div class="flex flex-col">
                                <span class="text-sm font-medium"><?= htmlspecialchars($log['admin_email'] ?? 'Sconosciuto') ?></span>
                                <?php if (!empty($log['admin_name'])): ?>
                                <span class="text-xs text-gray-400"><?= htmlspecialchars($log['admin_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="px-3 py-1 rounded-full text-xs font-mono" style="background: rgba(147,51,234,0.2); color: #c084fc;">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td class="max-w-md">
                            <?php if (!empty($log['details_parsed'])): ?>
                                <button
                                    onclick="showDetailsModal(<?= htmlspecialchars(json_encode($log['details_parsed'])) ?>, '<?= htmlspecialchars($log['action']) ?>')"
                                    class="text-xs px-3 py-1 rounded bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 transition-colors"
                                >
                                    <i class="fas fa-eye mr-1"></i>Visualizza Dettagli
                                </button>
                            <?php else: ?>
                                <span class="text-gray-500 text-xs">Nessun dettaglio</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono text-sm text-gray-400"><?= htmlspecialchars($log['ip_address'] ?? 'N/D') ?></td>
                        <td class="text-sm text-gray-400">
                            <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ENTERPRISE: Pagination -->
        <?php
        $currentOffset = $filters['offset'];
        $limit = $filters['limit'];
        $totalPages = ceil($total_count / $limit);
        $currentPage = floor($currentOffset / $limit) + 1;
        ?>

        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center mt-6 pt-4 border-t border-gray-700">
            <div class="text-sm text-gray-400">
                Pagina <?= $currentPage ?> di <?= $totalPages ?>
            </div>
            <div class="flex gap-2">
                <!-- Previous Page -->
                <?php if ($currentPage > 1): ?>
                <a href="?<?= http_build_query(array_merge($filters, ['offset' => ($currentPage - 2) * $limit])) ?>" class="btn btn-info">
                    <i class="fas fa-chevron-left mr-1"></i>Precedente
                </a>
                <?php endif; ?>

                <!-- Next Page -->
                <?php if ($currentPage < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($filters, ['offset' => $currentPage * $limit])) ?>" class="btn btn-info">
                    Successiva<i class="fas fa-chevron-right ml-1"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ENTERPRISE: Details Modal -->
<div id="detailsModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden z-50 flex items-center justify-center" onclick="closeDetailsModal(event)">
    <div class="bg-gray-900 border border-purple-500/30 rounded-xl p-6 max-w-4xl max-h-[80vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-xl font-bold text-purple-300" id="modalTitle">Dettagli Azione</h3>
            <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-white text-2xl" aria-label="Chiudi finestra">&times;</button>
        </div>
        <div class="bg-black rounded-lg p-4 overflow-x-auto">
            <pre id="modalContent" class="text-sm text-green-400 font-mono whitespace-pre-wrap"></pre>
        </div>
        <div class="mt-4 flex justify-end">
            <button onclick="closeDetailsModal()" class="btn">Chiudi</button>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
/* ENTERPRISE: Sticky Table Headers - Same as Email Metrics */
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
</style>

<script nonce="<?= csp_nonce() ?>">
function showDetailsModal(details, action) {
    const modal = document.getElementById('detailsModal');
    const title = document.getElementById('modalTitle');
    const content = document.getElementById('modalContent');

    title.textContent = `Azione: ${action}`;
    content.textContent = JSON.stringify(details, null, 2);

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeDetailsModal(event) {
    if (event && event.target.id !== 'detailsModal') return;

    const modal = document.getElementById('detailsModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function exportAuditLog(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('format', format);

    window.location.href = '/api/audit/export?' + params.toString();
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDetailsModal();
    }
});
</script>
