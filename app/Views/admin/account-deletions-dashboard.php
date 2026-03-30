<!-- 🚀 ENTERPRISE GALAXY: Account Deletions Dashboard (GDPR Article 17 Compliance) -->

<?php
// Define maskEmail helper BEFORE usage (ENTERPRISE: Function must be defined early)
if (!function_exists('maskEmail')) {
    function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];

        if (strlen($local) <= 2) {
            return $local[0] . '*@' . $domain;
        }

        return $local[0] . str_repeat('*', strlen($local) - 2) . $local[strlen($local) - 1] . '@' . $domain;
    }
}
?>

<!-- Chart.js CDN (inline for this view) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<h2 class="enterprise-title mb-8 flex items-center justify-between">
    <span class="flex items-center">
        🗑️ Dashboard Cancellazioni Account
    </span>
    <div class="flex items-center gap-3">
        <a href="?status=<?= htmlspecialchars($status_filter) ?>&period=<?= htmlspecialchars($timeline_period) ?>&export=csv" class="btn btn-success btn-sm">
            📥 Esporta CSV
        </a>
        <button onclick="refreshDashboard()" class="btn btn-purple btn-sm" id="refreshBtn">
            🔄 Aggiorna Dati
        </button>
        <button onclick="clearCache()" class="btn btn-secondary btn-sm opacity-50" title="Svuota TUTTA la cache (incluso timeline)">
            🗑️ Svuota Tutto
        </button>
    </div>
</h2>

<!-- GDPR Compliance Subtitle -->
<p class="text-muted mb-6">Monitoraggio e Analisi Conformità GDPR Articolo 17</p>

<!-- Rate Limiting Alert -->
<?php if (($stats['rate_limit_violations'] ?? 0) > 0): ?>
<div class="alert alert-warning mb-6">
    <strong>⚠️ Violazioni Limite di Recupero Rilevate</strong><br>
    <?= $stats['rate_limit_violations'] ?> utente/i ha superato il limite di recupero (3 recuperi in 30 giorni). Controlla la tabella Cancellazioni Recenti sotto.
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="stats-grid mb-8">
    <div class="stat-card">
        <span class="stat-value" style="color: #8b5cf6;"><?= number_format($stats['total_deletions'] ?? 0) ?></span>
        <span class="stat-label">Cancellazioni Totali</span>
        <small class="text-muted">Da sempre</small>
    </div>

    <div class="stat-card">
        <span class="stat-value" style="color: #f59e0b;"><?= number_format($stats['pending_deletions'] ?? 0) ?></span>
        <span class="stat-label">In Attesa (Periodo di Grazia)</span>
        <small class="text-muted">Entro 30 giorni</small>
    </div>

    <div class="stat-card">
        <span class="stat-value" style="color: #10b981;"><?= number_format($stats['cancelled_recoveries'] ?? 0) ?></span>
        <span class="stat-label">Account Recuperati</span>
        <small class="text-muted"><?= number_format($stats['recovery_rate_percent'] ?? 0, 1) ?>% tasso di recupero</small>
    </div>

    <div class="stat-card">
        <span class="stat-value" style="color: #ef4444;"><?= number_format($stats['completed_hard_deletes'] ?? 0) ?></span>
        <span class="stat-label">Cancellati Definitivamente</span>
        <small class="text-muted">Cancellazioni permanenti</small>
    </div>
</div>

<!-- Timeline Chart -->
<div class="card mb-8">
    <h3 class="flex items-center justify-between">
        <span>📊 Cronologia Richieste Cancellazione</span>
        <div class="flex items-center gap-2">
            <button onclick="changePeriod('daily')" class="btn btn-sm <?= $timeline_period === 'daily' ? 'btn-purple' : 'btn-secondary' ?>">Giornaliero</button>
            <button onclick="changePeriod('weekly')" class="btn btn-sm <?= $timeline_period === 'weekly' ? 'btn-purple' : 'btn-secondary' ?>">Settimanale</button>
            <button onclick="changePeriod('monthly')" class="btn btn-sm <?= $timeline_period === 'monthly' ? 'btn-purple' : 'btn-secondary' ?>">Mensile</button>
        </div>
    </h3>
    <div style="position: relative; height: 300px;">
        <canvas id="timelineChart"></canvas>
    </div>
</div>

<!-- Recent Deletions Table -->
<div class="card mb-8">
    <h3 class="flex items-center justify-between">
        <span>🔍 Cancellazioni Recenti (<?= number_format($deletions_total) ?> totali)</span>
        <div class="flex items-center gap-2">
            <a href="?status=all&period=<?= htmlspecialchars($timeline_period) ?>" class="btn btn-sm <?= $status_filter === 'all' ? 'btn-purple' : 'btn-secondary' ?>">Tutte</a>
            <a href="?status=pending&period=<?= htmlspecialchars($timeline_period) ?>" class="btn btn-sm <?= $status_filter === 'pending' ? 'btn-warning' : 'btn-secondary' ?>">In Attesa</a>
            <a href="?status=cancelled&period=<?= htmlspecialchars($timeline_period) ?>" class="btn btn-sm <?= $status_filter === 'cancelled' ? 'btn-success' : 'btn-secondary' ?>">Recuperati</a>
            <a href="?status=completed&period=<?= htmlspecialchars($timeline_period) ?>" class="btn btn-sm <?= $status_filter === 'completed' ? 'btn-danger' : 'btn-secondary' ?>">Completati</a>
        </div>
    </h3>

    <?php if (!empty($deletions)): ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Utente</th>
                    <th>Email</th>
                    <th>Richiesta</th>
                    <th>Cancellazione Prevista</th>
                    <th>Stato</th>
                    <th>Periodo di Grazia</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletions as $deletion): ?>
                <tr>
                    <td><?= htmlspecialchars($deletion['id']) ?></td>
                    <td><?= htmlspecialchars($deletion['nickname']) ?></td>
                    <td><?= htmlspecialchars(maskEmail($deletion['email'])) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($deletion['requested_at']))) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($deletion['scheduled_deletion_at']))) ?></td>
                    <td>
                        <?php
                        $statusColors = [
                            'pending' => 'warning',
                            'cancelled' => 'success',
                            'completed' => 'danger'
                        ];
                        $badgeColor = $statusColors[$deletion['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $badgeColor ?>"><?= htmlspecialchars(strtoupper($deletion['status'])) ?></span>
                    </td>
                    <td>
                        <?php
                        $now = time();
                        $scheduledTime = strtotime($deletion['scheduled_deletion_at']);
                        $daysLeft = ceil(($scheduledTime - $now) / 86400);

                        if ($deletion['status'] === 'pending') {
                            if ($daysLeft > 7) {
                                echo '<span class="badge badge-success">' . $daysLeft . ' giorni</span>';
                            } elseif ($daysLeft > 3) {
                                echo '<span class="badge badge-warning">' . $daysLeft . ' giorni</span>';
                            } else {
                                echo '<span class="badge badge-danger">' . $daysLeft . ' giorni</span>';
                            }
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <button onclick="viewDetails(<?= $deletion['id'] ?>)" class="btn btn-info btn-sm">Dettagli</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($deletions_pagination['total_pages'] > 1): ?>
    <div class="flex items-center justify-between mt-6">
        <div class="text-muted">
            Pagina <?= $deletions_pagination['page'] ?> di <?= $deletions_pagination['total_pages'] ?>
            (<?= number_format($deletions_total) ?> record totali)
        </div>
        <div class="flex items-center gap-2">
            <?php if ($deletions_pagination['page'] > 1): ?>
                <a href="?status=<?= htmlspecialchars($status_filter) ?>&period=<?= htmlspecialchars($timeline_period) ?>&page=<?= $deletions_pagination['page'] - 1 ?>" class="btn btn-secondary btn-sm">← Precedente</a>
            <?php endif; ?>

            <?php if ($deletions_pagination['has_more']): ?>
                <a href="?status=<?= htmlspecialchars($status_filter) ?>&period=<?= htmlspecialchars($timeline_period) ?>&page=<?= $deletions_pagination['page'] + 1 ?>" class="btn btn-secondary btn-sm">Successiva →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="alert alert-info">
        <strong>ℹ️ Nessuna Richiesta di Cancellazione Trovata</strong><br>
        Nessuna richiesta di cancellazione corrisponde al filtro corrente (<?= htmlspecialchars($status_filter) ?>).
    </div>
    <?php endif; ?>
</div>

<!-- Loading Spinner CSS -->
<style nonce="<?= csp_nonce() ?>">
.spinner {
    border: 4px solid rgba(139, 92, 246, 0.1);
    border-radius: 50%;
    border-top: 4px solid #8b5cf6;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<!-- 🚀 ENTERPRISE: Modal for Deletion Details -->
<div id="detailsModal" class="hidden fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center p-4">
    <div class="bg-gray-900 rounded-lg max-w-3xl w-full max-h-[90vh] overflow-y-auto shadow-2xl border border-purple-500/30">
        <!-- Modal Header -->
        <div class="sticky top-0 bg-gray-900 border-b border-gray-700 px-6 py-4 flex items-center justify-between z-10">
            <h3 class="text-xl font-semibold text-purple-300">🗑️ Dettagli Cancellazione Account</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-white transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Modal Body -->
        <div id="modalBody" class="p-6">
            <!-- Content will be injected here via JavaScript -->
        </div>

        <!-- Modal Footer -->
        <div class="sticky bottom-0 bg-gray-900 border-t border-gray-700 px-6 py-4 flex justify-end">
            <button onclick="closeModal()" class="btn btn-secondary">Chiudi</button>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script nonce="<?= csp_nonce() ?>">
// 🚀 ENTERPRISE: Extract current admin URL from browser location (dynamic admin URL support)
const currentPath = window.location.pathname;
const adminUrlMatch = currentPath.match(/^(\/admin_[a-f0-9]{16})/);
const ADMIN_BASE_URL = adminUrlMatch ? adminUrlMatch[1] : '/admin';

// Timeline Chart.js
const timelineCtx = document.getElementById('timelineChart').getContext('2d');
const timelineData = <?= json_encode($timeline) ?>;

// ENTERPRISE: Use Chart.js format from backend (labels + datasets)
new Chart(timelineCtx, {
    type: 'bar',
    data: timelineData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: {
                stacked: true,
                grid: { color: 'rgba(255, 255, 255, 0.1)' },
                ticks: { color: '#9ca3af' }
            },
            y: {
                stacked: true,
                beginAtZero: true,
                grid: { color: 'rgba(255, 255, 255, 0.1)' },
                ticks: { color: '#9ca3af' }
            }
        },
        plugins: {
            legend: {
                labels: { color: '#e5e7eb' }
            }
        }
    }
});

// Change period function
function changePeriod(period) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('period', period);
    window.location.href = currentUrl.toString();
}

// 🎯 ENTERPRISE: Refresh dashboard (granular cache invalidation)
function refreshDashboard() {
    const refreshBtn = document.getElementById('refreshBtn');
    const originalText = refreshBtn.innerHTML;

    // Show loading state
    refreshBtn.disabled = true;
    refreshBtn.innerHTML = '⏳ Aggiornamento...';

    fetch(`${ADMIN_BASE_URL}/api/account-deletions/refresh-dashboard`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Success - reload to show fresh data
            window.location.reload();
        } else {
            alert('❌ ' + (data.message || 'Errore aggiornamento'));
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        alert('❌ Errore di rete: ' + error.message);
        refreshBtn.disabled = false;
        refreshBtn.innerHTML = originalText;
    });
}

// Clear cache function (FULL cache clear - use refreshDashboard instead for granular)
function clearCache() {
    if (!confirm('Svuotare la cache delle analitiche? Questo rigenererà le statistiche dal database.')) {
        return;
    }

    fetch(`${ADMIN_BASE_URL}/api/account-deletions/clear-cache`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Cache svuotata con successo!');
            window.location.reload();
        } else {
            alert('❌ Errore nello svuotamento cache: ' + (data.error || 'Errore sconosciuto'));
        }
    })
    .catch(error => {
        alert('❌ Errore di rete: ' + error.message);
    });
}

// 🚀 ENTERPRISE: View details function with modal
function viewDetails(deletionId) {
    // Show loading state
    const modal = document.getElementById('detailsModal');
    const modalBody = document.getElementById('modalBody');

    if (!modal || !modalBody) {
        console.error('Modal elements not found');
        return;
    }

    modal.classList.remove('hidden');
    modalBody.innerHTML = '<div class="text-center py-8"><div class="spinner"></div><p class="mt-4 text-gray-400">Caricamento dettagli...</p></div>';

    // Fetch deletion details via AJAX
    fetch(`${ADMIN_BASE_URL}/api/account-deletions/details/${deletionId}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.deletion) {
            displayDeletionDetails(data.deletion);
        } else {
            modalBody.innerHTML = `<div class="alert alert-danger">❌ Errore: ${data.error || 'Impossibile caricare i dettagli'}</div>`;
        }
    })
    .catch(error => {
        modalBody.innerHTML = `<div class="alert alert-danger">❌ Errore di rete: ${error.message}</div>`;
    });
}

// Display deletion details in modal
function displayDeletionDetails(deletion) {
    const modalBody = document.getElementById('modalBody');

    // Format dates
    const requestedAt = new Date(deletion.requested_at).toLocaleString('it-IT');
    const scheduledAt = new Date(deletion.scheduled_deletion_at).toLocaleString('it-IT');
    const cancelledAt = deletion.cancelled_at ? new Date(deletion.cancelled_at).toLocaleString('it-IT') : '—';
    const deletedAt = deletion.deleted_at ? new Date(deletion.deleted_at).toLocaleString('it-IT') : '—';

    // Status badge color
    const statusColors = {
        'pending': 'warning',
        'cancelled': 'success',
        'completed': 'danger'
    };
    const badgeColor = statusColors[deletion.status] || 'secondary';

    modalBody.innerHTML = `
        <div class="space-y-6">
            <!-- User Info -->
            <div class="bg-gray-800 p-4 rounded-lg">
                <h4 class="text-lg font-semibold mb-3 text-purple-300">👤 Informazioni Utente</h4>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-400">ID Utente:</span>
                        <span class="text-white ml-2">${deletion.user_id}</span>
                    </div>
                    <div>
                        <span class="text-gray-400">Nickname:</span>
                        <span class="text-white ml-2">${deletion.nickname || 'N/A'}</span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-400">Email:</span>
                        <span class="text-white ml-2">${deletion.email}</span>
                    </div>
                </div>
            </div>

            <!-- Deletion Info -->
            <div class="bg-gray-800 p-4 rounded-lg">
                <h4 class="text-lg font-semibold mb-3 text-purple-300">🗑️ Dettagli Cancellazione</h4>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-400">Stato:</span>
                        <span class="badge badge-${badgeColor} ml-2">${deletion.status.toUpperCase()}</span>
                    </div>
                    <div>
                        <span class="text-gray-400">ID Richiesta:</span>
                        <span class="text-white ml-2">#${deletion.id}</span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-400">Motivo:</span>
                        <span class="text-white ml-2">${deletion.reason || 'Non specificato'}</span>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-gray-800 p-4 rounded-lg">
                <h4 class="text-lg font-semibold mb-3 text-purple-300">📅 Cronologia</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-400">Richiesta inviata:</span>
                        <span class="text-white">${requestedAt}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Cancellazione prevista:</span>
                        <span class="text-white">${scheduledAt}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Recupero (se cancellato):</span>
                        <span class="text-white">${cancelledAt}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Cancellazione definitiva:</span>
                        <span class="text-white">${deletedAt}</span>
                    </div>
                </div>
            </div>

            <!-- Recovery Stats -->
            <div class="bg-gray-800 p-4 rounded-lg">
                <h4 class="text-lg font-semibold mb-3 text-purple-300">🔄 Statistiche Recupero</h4>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-400">Recuperi totali:</span>
                        <span class="text-white ml-2">${deletion.recovery_count || 0}</span>
                    </div>
                    <div>
                        <span class="text-gray-400">Ultimo recupero:</span>
                        <span class="text-white ml-2">${deletion.last_recovery_at ? new Date(deletion.last_recovery_at).toLocaleString('it-IT') : 'Mai'}</span>
                    </div>
                </div>
            </div>

            <!-- Technical Info -->
            <div class="bg-gray-800 p-4 rounded-lg">
                <h4 class="text-lg font-semibold mb-3 text-purple-300">🔧 Informazioni Tecniche</h4>
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-gray-400">IP Address (mascherato):</span>
                        <span class="text-white ml-2">${deletion.ip_address ? maskIp(deletion.ip_address) : 'N/A'}</span>
                    </div>
                    <div>
                        <span class="text-gray-400">User Agent:</span>
                        <span class="text-white ml-2 text-xs break-all">${deletion.user_agent || 'N/A'}</span>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Mask IP address for privacy
function maskIp(ip) {
    const parts = ip.split('.');
    if (parts.length === 4) {
        return `${parts[0]}.${parts[1]}.XXX.XXX`;
    }
    return ip.substring(0, 8) + '...';
}

// Close modal
function closeModal() {
    const modal = document.getElementById('detailsModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

</script>
