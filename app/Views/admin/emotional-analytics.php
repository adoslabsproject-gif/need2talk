<?php
/**
 * Dashboard Analisi Emozionale - Admin
 * ENTERPRISE: Insights interni per miglioramento prodotto (GDPR-compliant)
 */

// Extract data
$insights = $data['insights'] ?? [];
$consentStats = $data['consent_stats'] ?? [];
$periodDays = $data['period_days'] ?? 30;
$error = $data['error'] ?? null;
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="p-8">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-white mb-2">
            🧠 Dashboard Analisi Emozionale
        </h1>
        <p class="text-gray-400">
            Insights interni per miglioramento prodotto • Conforme GDPR • <?= $insights['total_users_with_consent'] ?? 0 ?> utenti con consenso
        </p>
    </div>

    <?php if ($error): ?>
        <!-- Stato Errore -->
        <div class="bg-red-900/30 border border-red-600 rounded-lg p-6">
            <p class="text-red-200"><?= htmlspecialchars($error) ?></p>
        </div>

    <?php elseif (isset($insights['message'])): ?>
        <!-- Stato Nessun Dato -->
        <div class="bg-yellow-900/30 border border-yellow-600 rounded-lg p-6">
            <p class="text-yellow-200"><?= htmlspecialchars($insights['message']) ?></p>
        </div>

    <?php else: ?>
        <!-- Statistiche Consenso -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-white mb-4">📊 Statistiche Consenso (GDPR)</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php foreach ($consentStats as $stat): ?>
                    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                        <h3 class="text-sm font-medium text-gray-400 mb-2">
                            <?= htmlspecialchars($stat['service_name']) ?>
                        </h3>
                        <p class="text-3xl font-bold text-green-400 mb-1">
                            <?= number_format($stat['consents_active']) ?>
                        </p>
                        <p class="text-xs text-gray-500">
                            <?= htmlspecialchars($stat['description']) ?>
                        </p>
                        <div class="mt-3 text-xs text-gray-400">
                            <span class="text-red-400"><?= $stat['consents_declined'] ?> rifiutati</span> •
                            <span class="text-gray-500"><?= $stat['total_users_decided'] ?> totale</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Metriche Chiave -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-sm font-medium text-gray-400 mb-2">Totale Post Audio</h3>
                <p class="text-3xl font-bold text-white">
                    <?= number_format($insights['total_audio_posts'] ?? 0) ?>
                </p>
            </div>
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-sm font-medium text-gray-400 mb-2">Totale Reazioni</h3>
                <p class="text-3xl font-bold text-purple-400">
                    <?= number_format($insights['engagement_metrics']['total_reactions'] ?? 0) ?>
                </p>
            </div>
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-sm font-medium text-gray-400 mb-2">Reazioni/Post</h3>
                <p class="text-3xl font-bold text-blue-400">
                    <?= number_format($insights['engagement_metrics']['reactions_per_post'] ?? 0, 2) ?>
                </p>
            </div>
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-sm font-medium text-gray-400 mb-2">Utenti Unici Reattivi</h3>
                <p class="text-3xl font-bold text-green-400">
                    <?= number_format($insights['engagement_metrics']['unique_reactors'] ?? 0) ?>
                </p>
            </div>
        </div>

        <!-- Analisi Gap Sentimentale (INSIGHT CHIAVE!) -->
        <?php if (isset($insights['sentiment_gap'])): ?>
            <div class="bg-gradient-to-r from-purple-900/30 to-blue-900/30 border border-purple-600 rounded-lg p-6 mb-8">
                <h2 class="text-xl font-bold text-white mb-4">🎯 Analisi Gap Sentimentale (INSIGHT CHIAVE!)</h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Positivo Espresso</p>
                        <p class="text-2xl font-bold text-blue-400">
                            <?= $insights['sentiment_gap']['expressed_positive_percent'] ?>%
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Positivo Evocato</p>
                        <p class="text-2xl font-bold text-green-400">
                            <?= $insights['sentiment_gap']['evoked_positive_percent'] ?>%
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-400 mb-1">Gap</p>
                        <p class="text-2xl font-bold <?= $insights['sentiment_gap']['gap_percent'] > 0 ? 'text-green-400' : 'text-red-400' ?>">
                            <?= $insights['sentiment_gap']['gap_percent'] > 0 ? '+' : '' ?><?= $insights['sentiment_gap']['gap_percent'] ?>%
                        </p>
                    </div>
                </div>

                <div class="bg-gray-800/50 rounded-lg p-4">
                    <p class="text-sm text-gray-300">
                        <strong class="text-white">Interpretazione:</strong>
                        <?= htmlspecialchars($insights['sentiment_gap']['interpretation']) ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Grafici: Espresse vs Evocate -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Grafico Emozioni Espresse -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-lg font-bold text-white mb-4">
                    😊 Emozioni ESPRESSE (Cosa registrano gli utenti)
                </h3>
                <canvas id="expressedEmotionsChart" style="max-height: 300px;"></canvas>
            </div>

            <!-- Grafico Emozioni Evocate -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-lg font-bold text-white mb-4">
                    💬 Emozioni EVOCATE (Reazioni degli altri)
                </h3>
                <canvas id="evokedEmotionsChart" style="max-height: 300px;"></canvas>
            </div>
        </div>

        <!-- Tabelle Dati -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Tabella Emozioni Espresse -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-lg font-bold text-white mb-4">Dettagli Emozioni Espresse</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-400 uppercase border-b border-gray-700">
                            <tr>
                                <th class="py-3 px-4">Emozione</th>
                                <th class="py-3 px-4 text-right">Conteggio</th>
                                <th class="py-3 px-4 text-right">%</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <?php foreach (($insights['expressed_emotions']['distribution'] ?? []) as $emotion): ?>
                                <tr class="border-b border-gray-700/50">
                                    <td class="py-3 px-4">
                                        <?= $emotion['icon_emoji'] ?? '' ?> <?= htmlspecialchars($emotion['name_it']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right font-medium"><?= number_format($emotion['count']) ?></td>
                                    <td class="py-3 px-4 text-right"><?= $emotion['percentage'] ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tabella Emozioni Evocate -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-lg font-bold text-white mb-4">Dettagli Emozioni Evocate</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-400 uppercase border-b border-gray-700">
                            <tr>
                                <th class="py-3 px-4">Emozione</th>
                                <th class="py-3 px-4 text-right">Conteggio</th>
                                <th class="py-3 px-4 text-right">%</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <?php foreach (($insights['evoked_emotions']['distribution'] ?? []) as $emotion): ?>
                                <tr class="border-b border-gray-700/50">
                                    <td class="py-3 px-4">
                                        <?= $emotion['icon_emoji'] ?? '' ?> <?= htmlspecialchars($emotion['name_it']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right font-medium"><?= number_format($emotion['count']) ?></td>
                                    <td class="py-3 px-4 text-right"><?= $emotion['percentage'] ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pulsante Esporta -->
        <div class="mt-8 text-center">
            <a href="api/emotional-analytics/export?days=<?= $periodDays ?>"
               class="inline-flex items-center px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
                </svg>
                Esporta Dati (CSV)
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Inizializzazione Chart.js -->
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    const insights = <?= json_encode($insights) ?>;

    // Chart.js defaults
    Chart.defaults.color = '#9CA3AF';
    Chart.defaults.borderColor = '#374151';

    // Grafico Emozioni Espresse
    if (insights.expressed_emotions?.distribution) {
        const expressedData = insights.expressed_emotions.distribution;
        new Chart(document.getElementById('expressedEmotionsChart'), {
            type: 'doughnut',
            data: {
                labels: expressedData.map(e => e.icon_emoji + ' ' + e.name_it),
                datasets: [{
                    data: expressedData.map(e => e.count),
                    backgroundColor: [
                        '#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EF4444',
                        '#06B6D4', '#EC4899', '#6366F1', '#14B8A6', '#F97316'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: '#D1D5DB', font: { size: 11 } }
                    }
                }
            }
        });
    }

    // Grafico Emozioni Evocate
    if (insights.evoked_emotions?.distribution) {
        const evokedData = insights.evoked_emotions.distribution;
        new Chart(document.getElementById('evokedEmotionsChart'), {
            type: 'doughnut',
            data: {
                labels: evokedData.map(e => e.icon_emoji + ' ' + e.name_it),
                datasets: [{
                    data: evokedData.map(e => e.count),
                    backgroundColor: [
                        '#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EF4444',
                        '#06B6D4', '#EC4899', '#6366F1', '#14B8A6', '#F97316'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: '#D1D5DB', font: { size: 11 } }
                    }
                }
            }
        });
    }
});
</script>
