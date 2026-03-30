<?php
/**
 * Chat Moderation Dashboard - Admin View
 * Enterprise Galaxy Chat System
 *
 * Features:
 * - Pending reports queue
 * - Keyword blacklist management
 * - Report statistics
 * - Escrow key release
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Stats from controller
$stats = $stats ?? [
    'pending_reports' => 0,
    'resolved_today' => 0,
    'escalated' => 0,
    'total_keywords' => 0,
];

$pendingReports = $pendingReports ?? [];
$recentActions = $recentActions ?? [];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Moderation - Admin | need2talk.it</title>
    <link href="/assets/css/app.min.css" rel="stylesheet">
    <style>
        .report-card { transition: all 0.2s ease; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .status-badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <!-- Admin Header -->
    <header class="bg-gray-800 border-b border-gray-700 px-6 py-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center">
                <a href="/admin" class="text-gray-400 hover:text-white mr-4">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <h1 class="text-xl font-bold flex items-center">
                    <svg class="w-6 h-6 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    Chat Moderation
                </h1>
            </div>
            <nav class="flex items-center space-x-4">
                <a href="#reports" class="text-gray-300 hover:text-white">Segnalazioni</a>
                <a href="#keywords" class="text-gray-300 hover:text-white">Blacklist</a>
                <a href="#stats" class="text-gray-300 hover:text-white">Statistiche</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-400 text-sm">Segnalazioni Pending</span>
                    <span class="w-8 h-8 bg-yellow-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </span>
                </div>
                <div class="text-3xl font-bold text-yellow-400"><?= $stats['pending_reports'] ?></div>
            </div>

            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-400 text-sm">Risolte Oggi</span>
                    <span class="w-8 h-8 bg-green-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </span>
                </div>
                <div class="text-3xl font-bold text-green-400"><?= $stats['resolved_today'] ?></div>
            </div>

            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-400 text-sm">Escalate</span>
                    <span class="w-8 h-8 bg-red-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </span>
                </div>
                <div class="text-3xl font-bold text-red-400"><?= $stats['escalated'] ?></div>
            </div>

            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-gray-400 text-sm">Keywords in Blacklist</span>
                    <span class="w-8 h-8 bg-purple-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </span>
                </div>
                <div class="text-3xl font-bold text-purple-400"><?= $stats['total_keywords'] ?></div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Pending Reports -->
            <div id="reports" class="lg:col-span-2">
                <div class="bg-gray-800 rounded-xl border border-gray-700">
                    <div class="p-4 border-b border-gray-700 flex items-center justify-between">
                        <h2 class="font-semibold text-white">Segnalazioni in Attesa</h2>
                        <select id="reportFilter" class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-1 text-sm">
                            <option value="all">Tutte</option>
                            <option value="harassment">Molestie</option>
                            <option value="spam">Spam</option>
                            <option value="inappropriate">Inappropriato</option>
                            <option value="hate_speech">Odio</option>
                        </select>
                    </div>

                    <div class="p-4 space-y-4 max-h-[600px] overflow-y-auto">
                        <?php if (empty($pendingReports)): ?>
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-gray-400">Nessuna segnalazione in attesa</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($pendingReports as $report): ?>
                        <div class="report-card bg-gray-700/50 rounded-lg p-4 border border-gray-600" data-report-id="<?= $report['id'] ?>">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center">
                                    <img src="<?= htmlspecialchars($report['reporter_avatar'] ?? '/assets/img/default-avatar.png') ?>"
                                         class="w-8 h-8 rounded-full mr-3" alt="">
                                    <div>
                                        <span class="text-sm font-medium text-white"><?= htmlspecialchars($report['reporter_name'] ?? 'Utente') ?></span>
                                        <span class="text-xs text-gray-400 ml-2"><?= date('d/m/Y H:i', strtotime($report['created_at'])) ?></span>
                                    </div>
                                </div>
                                <span class="status-badge bg-<?= $report['report_type'] === 'hate_speech' ? 'red' : ($report['report_type'] === 'harassment' ? 'orange' : 'yellow') ?>-500/20 text-<?= $report['report_type'] === 'hate_speech' ? 'red' : ($report['report_type'] === 'harassment' ? 'orange' : 'yellow') ?>-400">
                                    <?= ucfirst(str_replace('_', ' ', $report['report_type'])) ?>
                                </span>
                            </div>

                            <div class="bg-gray-800 rounded-lg p-3 mb-3">
                                <p class="text-sm text-gray-300 break-words">
                                    <?= htmlspecialchars($report['message_content'] ?? '[Messaggio crittografato]') ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-2">
                                    Da: <?= htmlspecialchars($report['sender_name'] ?? 'Utente') ?>
                                </p>
                            </div>

                            <?php if (!empty($report['report_reason'])): ?>
                            <p class="text-xs text-gray-400 mb-3">
                                <strong>Motivo:</strong> <?= htmlspecialchars($report['report_reason']) ?>
                            </p>
                            <?php endif; ?>

                            <div class="flex items-center space-x-2">
                                <button class="action-resolve flex-1 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg transition-colors">
                                    Risolvi
                                </button>
                                <button class="action-dismiss flex-1 py-2 bg-gray-600 hover:bg-gray-500 text-white text-sm rounded-lg transition-colors">
                                    Ignora
                                </button>
                                <button class="action-escalate py-2 px-4 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg transition-colors">
                                    Escalate
                                </button>
                                <?php if ($report['is_e2e_encrypted'] ?? false): ?>
                                <button class="action-escrow py-2 px-4 bg-purple-600 hover:bg-purple-700 text-white text-sm rounded-lg transition-colors" title="Rilascia chiave escrow">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                                    </svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar: Quick Actions & Recent Activity -->
            <div class="space-y-6">

                <!-- Add Keyword -->
                <div id="keywords" class="bg-gray-800 rounded-xl border border-gray-700 p-4">
                    <h3 class="font-semibold text-white mb-4">Aggiungi Keyword</h3>
                    <form id="addKeywordForm" class="space-y-3">
                        <input type="text" name="keyword" placeholder="Parola o frase..."
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-sm"
                               required maxlength="100">
                        <div class="grid grid-cols-2 gap-2">
                            <select name="severity" class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-sm">
                                <option value="1">Bassa</option>
                                <option value="2" selected>Media</option>
                                <option value="3">Alta</option>
                                <option value="4">Critica</option>
                                <option value="5">Blocco</option>
                            </select>
                            <select name="action_type" class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-sm">
                                <option value="flag">Flag</option>
                                <option value="block" selected>Blocca</option>
                                <option value="shadow_hide">Nascondi</option>
                                <option value="warn">Avvisa</option>
                            </select>
                        </div>
                        <button type="submit" class="w-full py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm transition-colors">
                            Aggiungi
                        </button>
                    </form>
                </div>

                <!-- Recent Moderation Activity -->
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-4">
                    <h3 class="font-semibold text-white mb-4">Attività Recente</h3>
                    <div class="space-y-3 max-h-64 overflow-y-auto">
                        <?php if (empty($recentActions)): ?>
                        <p class="text-sm text-gray-500">Nessuna attività recente</p>
                        <?php else: ?>
                        <?php foreach ($recentActions as $action): ?>
                        <div class="flex items-start text-sm">
                            <span class="w-2 h-2 mt-1.5 rounded-full mr-2 <?= $action['type'] === 'resolved' ? 'bg-green-500' : ($action['type'] === 'dismissed' ? 'bg-gray-500' : 'bg-red-500') ?>"></span>
                            <div>
                                <p class="text-gray-300"><?= htmlspecialchars($action['description']) ?></p>
                                <p class="text-xs text-gray-500"><?= date('H:i', strtotime($action['created_at'])) ?> - <?= htmlspecialchars($action['admin_name']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div id="stats" class="bg-gray-800 rounded-xl border border-gray-700 p-4">
                    <h3 class="font-semibold text-white mb-4">Statistiche 7 Giorni</h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-400">Messaggi totali</span>
                            <span class="font-medium text-white"><?= number_format($stats['messages_week'] ?? 0) ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-400">Messaggi filtrati</span>
                            <span class="font-medium text-yellow-400"><?= number_format($stats['filtered_week'] ?? 0) ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-400">Utenti bannati</span>
                            <span class="font-medium text-red-400"><?= $stats['banned_week'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </main>

    <!-- Escrow Release Modal -->
    <div id="escrowModal" class="fixed inset-0 bg-black/70 z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-md w-full p-6">
                <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                    Rilascio Chiave Escrow
                </h2>
                <p class="text-sm text-gray-400 mb-4">
                    Questo messaggio è crittografato E2E. Rilasciando la chiave escrow potrai leggere il contenuto per la moderazione.
                </p>
                <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 mb-4">
                    <p class="text-xs text-yellow-400">
                        <strong>Attenzione:</strong> Il rilascio della chiave escrow viene loggato e gli utenti potrebbero essere notificati.
                    </p>
                </div>
                <div class="flex space-x-3">
                    <button id="cancelEscrow" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg">Annulla</button>
                    <button id="confirmEscrow" class="flex-1 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">Rilascia</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Moderation actions
    document.querySelectorAll('.report-card').forEach(card => {
        const reportId = card.dataset.reportId;

        card.querySelector('.action-resolve')?.addEventListener('click', () => {
            handleAction(reportId, 'resolve');
        });

        card.querySelector('.action-dismiss')?.addEventListener('click', () => {
            handleAction(reportId, 'dismiss');
        });

        card.querySelector('.action-escalate')?.addEventListener('click', () => {
            handleAction(reportId, 'escalate');
        });

        card.querySelector('.action-escrow')?.addEventListener('click', () => {
            openEscrowModal(reportId);
        });
    });

    async function handleAction(reportId, action) {
        try {
            const response = await fetch(`/api/admin/chat/reports/${reportId}/${action}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= csrf_token() ?>',
                },
            });

            if (response.ok) {
                document.querySelector(`[data-report-id="${reportId}"]`)?.remove();
            }
        } catch (e) {
            console.error('Action failed:', e);
        }
    }

    // Escrow modal
    let currentEscrowReport = null;
    const escrowModal = document.getElementById('escrowModal');

    function openEscrowModal(reportId) {
        currentEscrowReport = reportId;
        escrowModal.classList.remove('hidden');
    }

    document.getElementById('cancelEscrow')?.addEventListener('click', () => {
        escrowModal.classList.add('hidden');
        currentEscrowReport = null;
    });

    document.getElementById('confirmEscrow')?.addEventListener('click', async () => {
        if (!currentEscrowReport) return;

        try {
            const response = await fetch(`/api/admin/chat/reports/${currentEscrowReport}/escrow`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= csrf_token() ?>',
                },
            });

            if (response.ok) {
                location.reload();
            }
        } catch (e) {
            console.error('Escrow release failed:', e);
        }

        escrowModal.classList.add('hidden');
    });

    // Add keyword form
    document.getElementById('addKeywordForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        try {
            const response = await fetch('/api/admin/chat/keywords', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= csrf_token() ?>',
                },
                body: JSON.stringify(Object.fromEntries(formData)),
            });

            if (response.ok) {
                form.reset();
                alert('Keyword aggiunta con successo');
            }
        } catch (e) {
            console.error('Add keyword failed:', e);
        }
    });

    // Report filter
    document.getElementById('reportFilter')?.addEventListener('change', (e) => {
        const filter = e.target.value;
        document.querySelectorAll('.report-card').forEach(card => {
            if (filter === 'all') {
                card.style.display = '';
            } else {
                const badge = card.querySelector('.status-badge');
                const type = badge?.textContent.toLowerCase().replace(' ', '_');
                card.style.display = type === filter ? '' : 'none';
            }
        });
    });
    </script>

</body>
</html>
