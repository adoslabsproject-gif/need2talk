<!-- ENTERPRISE COOKIES MANAGEMENT -->
<div class="mb-8">
    <h2 class="text-3xl font-bold bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent mb-2">
        🍪 Gestione Cookie
    </h2>
    <p class="text-gray-400">Gestione consensi cookie e conformità GDPR di livello enterprise</p>
</div>

<!-- Statistics Overview -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10 text-center group hover:border-purple-400/40 transition-all duration-300">
        <span class="block text-4xl font-bold bg-gradient-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent group-hover:scale-110 transition-transform duration-300">
            <?= $total_stats['total_consents'] ?? 0 ?>
        </span>
        <div class="text-gray-400 text-sm mt-2">Consensi Totali</div>
    </div>
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10 text-center group hover:border-purple-400/40 transition-all duration-300">
        <span class="block text-4xl font-bold bg-gradient-to-r from-green-400 to-emerald-400 bg-clip-text text-transparent group-hover:scale-110 transition-transform duration-300">
            <?= $total_stats['unique_users'] ?? 0 ?>
        </span>
        <div class="text-gray-400 text-sm mt-2">Utenti Unici</div>
    </div>
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10 text-center group hover:border-purple-400/40 transition-all duration-300">
        <span class="block text-4xl font-bold bg-gradient-to-r from-orange-400 to-pink-400 bg-clip-text text-transparent group-hover:scale-110 transition-transform duration-300">
            <?= $total_stats['unique_ips'] ?? 0 ?>
        </span>
        <div class="text-gray-400 text-sm mt-2">IP Unici</div>
    </div>
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10 text-center group hover:border-purple-400/40 transition-all duration-300">
        <span class="block text-4xl font-bold bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent group-hover:scale-110 transition-transform duration-300">
            <?= count($categories ?? []) ?>
        </span>
        <div class="text-gray-400 text-sm mt-2">Categorie Cookie</div>
    </div>
</div>

<!-- Consent Statistics -->
<div class="mb-8">
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10">
        <h3 class="text-xl font-semibold text-blue-400 mb-6 flex items-center">
            <i class="fas fa-chart-bar mr-3"></i>
            Statistiche Consensi
        </h3>

        <?php if (!empty($consent_stats)) { ?>
            <div class="space-y-4">
                <?php foreach ($consent_stats as $stat) { ?>
                    <div class="flex items-center space-x-4">
                        <span class="min-w-[120px] font-medium text-gray-300">
                            <?= ucfirst(str_replace('_', ' ', $stat['consent_type'])) ?>:
                        </span>
                        <div class="flex-1">
                            <div class="flex items-center space-x-3">
                                <div class="bg-gray-700 rounded-full h-5 flex-1 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r <?= $stat['consent_type'] === 'accepted_all' ? 'from-green-500 to-emerald-400' : ($stat['consent_type'] === 'declined_all' ? 'from-red-500 to-pink-400' : 'from-yellow-500 to-orange-400') ?> transition-all duration-1000 ease-out"
                                         style="width: <?= max(5, $stat['percentage']) ?>%"></div>
                                </div>
                                <span class="text-sm font-medium text-white min-w-[80px]">
                                    <?= $stat['count'] ?> (<?= $stat['percentage'] ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } else { ?>
            <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
                <p class="text-blue-400">Nessun dato sui consensi disponibile.</p>
            </div>
        <?php } ?>
    </div>
</div>

<!-- Cookie Categories Management -->
<div class="mb-8">
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-semibold text-blue-400 flex items-center">
                <i class="fas fa-tags mr-3"></i>
                Categorie Cookie
            </h3>
            <button onclick="showAddCategoryModal()"
                    class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all duration-300 transform hover:scale-105 shadow-lg shadow-purple-500/30">
                <i class="fas fa-plus mr-2"></i>Aggiungi Categoria
            </button>
        </div>

        <?php if (!empty($categories)) { ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-700">
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">ID</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Key</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Nome</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Descrizione</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Richiesti</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Attivi</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Ordine</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category) { ?>
                        <tr class="border-b border-gray-700/50 hover:bg-gray-700/30 transition-all duration-200">
                            <td class="py-4 px-4 text-gray-100"><?= htmlspecialchars($category['id']) ?></td>
                            <td class="py-4 px-4">
                                <code class="bg-gray-700/50 text-purple-300 px-2 py-1 rounded text-sm">
                                    <?= htmlspecialchars($category['category_key']) ?>
                                </code>
                            </td>
                            <td class="py-4 px-4 font-medium text-white"><?= htmlspecialchars($category['category_name']) ?></td>
                            <td class="py-4 px-4 text-gray-300 max-w-xs">
                                <div title="<?= htmlspecialchars($category['description']) ?>" class="truncate">
                                    <?= htmlspecialchars(strlen($category['description']) > 60 ? substr($category['description'], 0, 57) . '...' : $category['description']) ?>
                                </div>
                            </td>
                            <td class="py-4 px-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $category['is_required'] ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-gray-600/50 text-gray-300 border border-gray-500/30' ?>">
                                    <?= $category['is_required'] ? 'Richiesto' : 'Opzionale' ?>
                                </span>
                            </td>
                            <td class="py-4 px-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $category['is_active'] ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30' ?>">
                                    <?= $category['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                </span>
                            </td>
                            <td class="py-4 px-4 text-center">
                                <span class="font-bold text-purple-300"><?= $category['sort_order'] ?></span>
                            </td>
                            <td class="py-4 px-4">
                                <div class="flex space-x-2">
                                    <button onclick="editCategory(<?= $category['id'] ?>)"
                                            class="bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 p-2 rounded-lg transition-all duration-200 border border-blue-500/30 hover:border-blue-400/50">
                                        <i class="fas fa-edit text-sm"></i>
                                    </button>
                                    <button onclick="deleteCategory(<?= $category['id'] ?>)"
                                            class="bg-red-500/20 hover:bg-red-500/30 text-red-300 p-2 rounded-lg transition-all duration-200 border border-red-500/30 hover:border-red-400/50">
                                        <i class="fas fa-trash text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
                <p class="text-blue-400">Nessuna categoria cookie configurata.</p>
            </div>
        <?php } ?>
    </div>
</div>

<!-- Recent Consents -->
<div class="mb-8">
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10">
        <h3 class="text-xl font-semibold text-blue-400 mb-6 flex items-center">
            <i class="fas fa-clock mr-3"></i>
            Consensi Recenti (Ultimi 50)
        </h3>

        <?php if (!empty($recent_consents)) { ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-700">
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">ID</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Utente</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Tipo Consenso</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Indirizzo IP</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Versione</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Data/Ora</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Scade</th>
                            <th class="text-left py-4 px-4 text-gray-300 font-medium">Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_consents as $consent) { ?>
                            <?php
                            $consentFormatted = date('d/m/Y H:i', strtotime($consent['consent_timestamp']));
                            $expiresFormatted = $consent['expires_at'] ? date('d/m/Y H:i', strtotime($consent['expires_at'])) : 'Mai';
                            $isExpired = $consent['expires_at'] && strtotime($consent['expires_at']) < time();
                            ?>
                        <tr class="border-b border-gray-700/50 hover:bg-gray-700/30 transition-all duration-200">
                            <td class="py-4 px-4 text-gray-100"><?= htmlspecialchars($consent['id']) ?></td>
                            <td class="py-4 px-4">
                                <?php if ($consent['nickname']) { ?>
                                    <div>
                                        <div class="font-medium text-white"><?= htmlspecialchars($consent['nickname']) ?></div>
                                        <div class="text-xs text-gray-400 truncate max-w-[150px]">
                                            <?= htmlspecialchars(substr($consent['email'], 0, 20)) ?>...
                                        </div>
                                    </div>
                                <?php } else { ?>
                                    <div>
                                        <div class="italic text-gray-400">Anonimo</div>
                                        <div class="text-xs text-gray-500">
                                            Sessione: <?= htmlspecialchars(substr($consent['session_id'], 0, 8)) ?>...
                                        </div>
                                    </div>
                                <?php } ?>
                            </td>
                            <td class="py-4 px-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $consent['consent_type'] === 'accepted_all' ? 'bg-green-500/20 text-green-300 border border-green-500/30' : ($consent['consent_type'] === 'declined_all' ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30') ?>">
                                    <?= ucfirst(str_replace('_', ' ', $consent['consent_type'])) ?>
                                </span>
                            </td>
                            <td class="py-4 px-4 text-gray-300 font-mono text-sm"><?= htmlspecialchars($consent['ip_address']) ?></td>
                            <td class="py-4 px-4 text-gray-300"><?= htmlspecialchars($consent['consent_version']) ?></td>
                            <td class="py-4 px-4 text-gray-300 text-sm"><?= $consentFormatted ?></td>
                            <td class="py-4 px-4 text-gray-300 text-sm"><?= $expiresFormatted ?></td>
                            <td class="py-4 px-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $isExpired ? 'bg-red-500/20 text-red-300 border border-red-500/30' : ($consent['is_active'] ? 'bg-green-500/20 text-green-300 border border-green-500/30' : 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30') ?>">
                                    <?= $isExpired ? 'Scaduto' : ($consent['is_active'] ? 'Attivo' : 'Inattivo') ?>
                                </span>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
                <p class="text-blue-400">Nessun consenso recente disponibile.</p>
            </div>
        <?php } ?>
    </div>
</div>

<!-- Consent Trends -->
<?php if (!empty($consent_trends)) { ?>
<div class="mb-8">
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10">
        <h3 class="text-xl font-semibold text-blue-400 mb-6 flex items-center">
            <i class="fas fa-chart-line mr-3"></i>
            Tendenze Consensi (Ultimi 7 Giorni)
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-700">
                        <th class="text-left py-4 px-4 text-gray-300 font-medium">Data</th>
                        <th class="text-left py-4 px-4 text-gray-300 font-medium">Tipo Consenso</th>
                        <th class="text-left py-4 px-4 text-gray-300 font-medium">Conteggio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($consent_trends as $trend) { ?>
                    <tr class="border-b border-gray-700/50 hover:bg-gray-700/30 transition-all duration-200">
                        <td class="py-4 px-4 text-gray-300"><?= date('d/m/Y', strtotime($trend['date'])) ?></td>
                        <td class="py-4 px-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $trend['consent_type'] === 'accepted_all' ? 'bg-green-500/20 text-green-300 border border-green-500/30' : ($trend['consent_type'] === 'declined_all' ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30') ?>">
                                <?= ucfirst(str_replace('_', ' ', $trend['consent_type'])) ?>
                            </span>
                        </td>
                        <td class="py-4 px-4 text-center">
                            <span class="font-bold text-purple-300"><?= $trend['count'] ?></span>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php } ?>

<!-- Enterprise Features -->
<div class="mb-8">
    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl p-6 border border-purple-500/20 shadow-2xl shadow-purple-500/10">
        <h3 class="text-xl font-semibold text-blue-400 mb-6 flex items-center">
            <i class="fas fa-building mr-3"></i>
            Strumenti Enterprise
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Export Data -->
            <div class="bg-gray-700/50 backdrop-blur rounded-xl p-6 border border-gray-600/30 hover:border-blue-500/40 transition-all duration-300">
                <h4 class="text-blue-400 font-semibold mb-3 flex items-center">
                    <i class="fas fa-download mr-2"></i>
                    Export & Report
                </h4>
                <p class="text-gray-400 text-sm mb-4">Esporta dati consensi per audit di conformità</p>
                <div class="space-y-2">
                    <button onclick="exportData('csv')"
                            class="w-full bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-blue-500/30 hover:border-blue-400/50">
                        Esporta CSV
                    </button>
                    <button onclick="exportData('json')"
                            class="w-full bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-blue-500/30 hover:border-blue-400/50">
                        Esporta JSON
                    </button>
                    <button onclick="generateReport()"
                            class="w-full bg-green-500/20 hover:bg-green-500/30 text-green-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-green-500/30 hover:border-green-400/50">
                        📊 Genera Report
                    </button>
                </div>
            </div>

            <!-- Compliance Monitor -->
            <div class="bg-gray-700/50 backdrop-blur rounded-xl p-6 border border-gray-600/30 hover:border-green-500/40 transition-all duration-300">
                <h4 class="text-green-400 font-semibold mb-3 flex items-center">
                    <i class="fas fa-shield-alt mr-2"></i>
                    Conformità
                </h4>
                <p class="text-gray-400 text-sm mb-4">Monitoraggio conformità GDPR</p>
                <div class="space-y-3 mb-4">
                    <div class="flex items-center">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-300 border border-green-500/30">
                            ✅ Conservazione Dati
                        </span>
                    </div>
                    <div class="flex items-center">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-300 border border-green-500/30">
                            ✅ Registri Consensi
                        </span>
                    </div>
                </div>
                <button onclick="runComplianceCheck()"
                        class="w-full bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-yellow-500/30 hover:border-yellow-400/50">
                    🔍 Audit Completo
                </button>
            </div>

            <!-- Data Management -->
            <div class="bg-gray-700/50 backdrop-blur rounded-xl p-6 border border-gray-600/30 hover:border-orange-500/40 transition-all duration-300">
                <h4 class="text-orange-400 font-semibold mb-3 flex items-center">
                    <i class="fas fa-database mr-2"></i>
                    Gestione Dati
                </h4>
                <p class="text-gray-400 text-sm mb-4">Gestisci il ciclo di vita dei dati cookie</p>
                <div class="space-y-2">
                    <button onclick="cleanupExpired()"
                            class="w-full bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-yellow-500/30 hover:border-yellow-400/50">
                        🧹 Pulizia
                    </button>
                    <button onclick="massDelete()"
                            class="w-full bg-red-500/20 hover:bg-red-500/30 text-red-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-red-500/30 hover:border-red-400/50">
                        🗑️ Eliminazione Massiva
                    </button>
                    <button onclick="showRetentionSettings()"
                            class="w-full bg-gray-500/20 hover:bg-gray-500/30 text-gray-300 py-2 px-4 rounded-lg text-sm transition-all duration-200 border border-gray-500/30 hover:border-gray-400/50">
                        ⚙️ Impostazioni
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Category Management Modal -->
<div id="categoryModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-gray-800/90 backdrop-blur-lg rounded-2xl p-8 max-w-md w-full border border-purple-500/20 shadow-2xl shadow-purple-500/10">
        <h3 id="modalTitle" class="text-2xl font-bold text-blue-400 mb-6">Aggiungi Categoria Cookie</h3>

        <form id="categoryForm" class="space-y-6">
            <div>
                <label class="block text-gray-300 font-medium mb-2">Chiave Categoria:</label>
                <input type="text" id="categoryKey"
                       class="w-full bg-gray-700/50 border border-gray-600/50 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:border-purple-500/50 focus:ring-1 focus:ring-purple-500/50 transition-all duration-200"
                       placeholder="es., analytics">
            </div>

            <div>
                <label class="block text-gray-300 font-medium mb-2">Nome Categoria:</label>
                <input type="text" id="categoryName"
                       class="w-full bg-gray-700/50 border border-gray-600/50 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:border-purple-500/50 focus:ring-1 focus:ring-purple-500/50 transition-all duration-200"
                       placeholder="es., Cookie Analytics">
            </div>

            <div>
                <label class="block text-gray-300 font-medium mb-2">Descrizione:</label>
                <textarea id="categoryDescription"
                          class="w-full bg-gray-700/50 border border-gray-600/50 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:border-purple-500/50 focus:ring-1 focus:ring-purple-500/50 transition-all duration-200 h-24 resize-none"
                          placeholder="Descrizione di questa categoria cookie..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <label class="flex items-center space-x-3 cursor-pointer">
                    <input type="checkbox" id="isRequired"
                           class="w-4 h-4 text-purple-500 bg-gray-700 border-gray-600 rounded focus:ring-purple-500 focus:ring-2">
                    <span class="text-gray-300">Categoria Richiesta</span>
                </label>
                <label class="flex items-center space-x-3 cursor-pointer">
                    <input type="checkbox" id="isActive" checked
                           class="w-4 h-4 text-purple-500 bg-gray-700 border-gray-600 rounded focus:ring-purple-500 focus:ring-2">
                    <span class="text-gray-300">Attivo</span>
                </label>
            </div>

            <div>
                <label class="block text-gray-300 font-medium mb-2">Ordine:</label>
                <input type="number" id="sortOrder"
                       class="w-full bg-gray-700/50 border border-gray-600/50 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:border-purple-500/50 focus:ring-1 focus:ring-purple-500/50 transition-all duration-200"
                       value="1" min="1">
            </div>

            <div class="flex space-x-4 pt-4">
                <button type="button" onclick="closeModal()"
                        class="flex-1 bg-gray-600/50 hover:bg-gray-600/70 text-gray-300 py-3 px-4 rounded-lg transition-all duration-200 border border-gray-500/30">
                    Annulla
                </button>
                <button type="submit"
                        class="flex-1 bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white py-3 px-4 rounded-lg transition-all duration-200 shadow-lg shadow-purple-500/30">
                    Salva Categoria
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
// Category Management
function showAddCategoryModal() {
    document.getElementById('modalTitle').textContent = 'Aggiungi Categoria Cookie';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryModal').classList.remove('hidden');
}

function editCategory(categoryId) {
    alert('La funzionalità di modifica verrà implementata. ID Categoria: ' + categoryId);
}

function deleteCategory(categoryId) {
    if (confirm('Sei sicuro di voler eliminare questa categoria cookie?')) {
        alert('La funzionalità di eliminazione verrà implementata. ID Categoria: ' + categoryId);
    }
}

function closeModal() {
    document.getElementById('categoryModal').classList.add('hidden');
}

// Enterprise Features
function exportData(format) {
    alert('L\'esportazione in ' + format.toUpperCase() + ' verrà implementata per i report di conformità.');
}

function generateReport() {
    alert('La generazione del Report di Conformità GDPR verrà implementata.');
}

function runComplianceCheck() {
    if (confirm('Eseguire audit completo di conformità GDPR? Questo verificherà tutti i dati cookie rispetto alle normative vigenti.')) {
        alert('L\'audit completo di conformità verrà implementato per verificare:\n• Politiche di conservazione dati\n• Validità dei consensi\n• Requisiti GDPR\n• Allineamento privacy policy');
    }
}

function cleanupExpired() {
    if (confirm('Questo rimuoverà tutti i consensi cookie scaduti. Continuare?')) {
        alert('La pulizia dei dati scaduti verrà implementata.');
    }
}

function massDelete() {
    if (confirm('ATTENZIONE: Questo eliminerà i dati cookie in massa. Questa azione non può essere annullata. Continuare?')) {
        alert('La funzionalità di eliminazione massiva verrà implementata con controlli di sicurezza.');
    }
}

function showRetentionSettings() {
    alert('Il pannello delle impostazioni di conservazione dati verrà implementato per configurare:\n• Periodi di conservazione dati cookie\n• Pianificazioni di pulizia automatica\n• Soglie di conformità');
}

// Form submission
document.getElementById('categoryForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = {
        category_key: document.getElementById('categoryKey').value,
        category_name: document.getElementById('categoryName').value,
        description: document.getElementById('categoryDescription').value,
        is_required: document.getElementById('isRequired').checked,
        is_active: document.getElementById('isActive').checked,
        sort_order: document.getElementById('sortOrder').value
    };

    console.debug('Dati categoria:', formData);
    alert('La funzionalità di salvataggio categoria verrà implementata.');
    closeModal();
});

// Close modal when clicking outside
document.getElementById('categoryModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>