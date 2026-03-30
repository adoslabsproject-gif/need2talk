<?php
/**
 * Admin Moderators Management View
 * ENTERPRISE GALAXY: Create and manage moderators for the Moderation Portal
 */

$moderators = $moderators ?? [];
$stats = $stats ?? [];
$portalUrl = $portalUrl ?? '';
?>

<div class="space-y-6">
    <!-- Header with Portal URL -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-2">
                <span class="text-3xl">🛡️</span> Gestione Moderatori
            </h2>
            <p class="text-gray-400 text-sm mt-1">Crea e gestisci i moderatori per il Portale di Moderazione</p>
        </div>

        <div class="flex items-center gap-3">
            <!-- Portal URL Display -->
            <div class="bg-gray-800/50 border border-purple-500/30 rounded-lg px-4 py-2">
                <div class="text-xs text-gray-400 mb-1">URL Portale Moderazione attuale:</div>
                <div class="flex items-center gap-2">
                    <code id="portalUrl" class="text-purple-300 text-sm font-mono"><?= htmlspecialchars($portalUrl) ?></code>
                    <button onclick="copyPortalUrl()" class="text-gray-400 hover:text-white transition" title="Copy URL">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                    <button onclick="refreshPortalUrl()" class="text-gray-400 hover:text-white transition" title="Refresh URL">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Send Portal URL Button -->
            <button onclick="sendPortalUrlToSelected()" id="sendUrlBtn" class="bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-700 hover:to-cyan-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2 opacity-50 cursor-not-allowed" disabled>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Invia URL Portale
            </button>

            <!-- Add Moderator Button -->
            <button onclick="openCreateModal()" class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Aggiungi Moderatore
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-3xl font-bold text-purple-400"><?= $stats['total'] ?? 0 ?></div>
            <div class="text-gray-400 text-sm">Moderatori Totali</div>
        </div>
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-3xl font-bold text-green-400"><?= $stats['active'] ?? 0 ?></div>
            <div class="text-gray-400 text-sm">Attivi</div>
        </div>
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-3xl font-bold text-blue-400"><?= $stats['online'] ?? 0 ?></div>
            <div class="text-gray-400 text-sm">Online Ora</div>
        </div>
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-3xl font-bold text-yellow-400"><?= $stats['actions_today'] ?? 0 ?></div>
            <div class="text-gray-400 text-sm">Azioni Oggi</div>
        </div>
    </div>

    <!-- Moderators Table -->
    <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-400 uppercase tracking-wider w-10">
                            <input type="checkbox" id="selectAllMods" onchange="toggleSelectAll()" class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Moderatore</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Stato</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Permessi</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Attività</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Ultimo Accesso</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wider">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700/50" id="moderatorsTableBody">
                    <?php if (empty($moderators)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                            Nessun moderatore. Clicca "Aggiungi Moderatore" per crearne uno.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($moderators as $mod): ?>
                    <tr class="hover:bg-gray-700/30 transition" data-moderator-id="<?= $mod['id'] ?>">
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="mod-checkbox rounded bg-gray-700 border-gray-600 text-purple-500"
                                   data-id="<?= $mod['id'] ?>"
                                   data-email="<?= htmlspecialchars($mod['email']) ?>"
                                   data-name="<?= htmlspecialchars($mod['display_name'] ?? $mod['username']) ?>"
                                   onchange="updateSendButton()">
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-white font-bold">
                                    <?= strtoupper(substr($mod['display_name'] ?? $mod['username'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="text-white font-medium"><?= htmlspecialchars($mod['display_name'] ?? $mod['username']) ?></div>
                                    <div class="text-gray-400 text-xs">@<?= htmlspecialchars($mod['username']) ?></div>
                                    <div class="text-gray-500 text-xs"><?= htmlspecialchars($mod['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($mod['is_active']): ?>
                                <?php if ($mod['locked_until'] && strtotime($mod['locked_until']) > time()): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-500/20 text-red-400 border border-red-500/30">
                                        🔒 Bloccato
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400 border border-green-500/30">
                                        ✓ Attivo
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-500/20 text-gray-400 border border-gray-500/30">
                                    ✗ Inattivo
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <?php if ($mod['can_view_rooms']): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-300" title="View Rooms">👁️</span>
                                <?php endif; ?>
                                <?php if ($mod['can_ban_users']): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-red-500/20 text-red-300" title="Ban Users">🚫</span>
                                <?php endif; ?>
                                <?php if ($mod['can_delete_messages']): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-orange-500/20 text-orange-300" title="Delete Messages">🗑️</span>
                                <?php endif; ?>
                                <?php if ($mod['can_manage_keywords']): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300" title="Manage Keywords">📝</span>
                                <?php endif; ?>
                                <?php if ($mod['can_resolve_reports']): ?>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-green-500/20 text-green-300" title="Resolve Reports">✅</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm">
                                <div class="text-white"><?= number_format($mod['total_actions'] ?? 0) ?> azioni</div>
                                <div class="text-gray-400 text-xs"><?= number_format($mod['total_bans_issued'] ?? 0) ?> ban emessi</div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($mod['last_login_at']): ?>
                                <div class="text-sm text-gray-300"><?= date('d M Y', strtotime($mod['last_login_at'])) ?></div>
                                <div class="text-xs text-gray-500"><?= date('H:i', strtotime($mod['last_login_at'])) ?></div>
                            <?php else: ?>
                                <span class="text-gray-500 text-sm">Mai</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <!-- Suspend/Unsuspend Toggle -->
                                <button onclick="toggleSuspension(<?= $mod['id'] ?>, '<?= htmlspecialchars($mod['display_name'] ?? $mod['username'], ENT_QUOTES) ?>', <?= $mod['is_active'] ? 'true' : 'false' ?>)"
                                        class="<?= $mod['is_active'] ? 'text-gray-400 hover:text-orange-400' : 'text-orange-400 hover:text-green-400' ?> transition p-1"
                                        title="<?= $mod['is_active'] ? 'Suspend Moderator' : 'Unsuspend Moderator' ?>">
                                    <?php if ($mod['is_active']): ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <?php else: ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <?php endif; ?>
                                </button>
                                <!-- Reset Password -->
                                <button onclick="resetPassword(<?= $mod['id'] ?>, '<?= htmlspecialchars($mod['display_name'] ?? $mod['username'], ENT_QUOTES) ?>')" class="text-gray-400 hover:text-yellow-400 transition p-1" title="Reset Password">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                                    </svg>
                                </button>
                                <!-- Delete (Soft Delete) -->
                                <button onclick="deleteModerator(<?= $mod['id'] ?>, '<?= htmlspecialchars($mod['display_name'] ?? $mod['username'], ENT_QUOTES) ?>')" class="text-gray-400 hover:text-red-400 transition p-1" title="Delete Moderator">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                                <!-- View Activity -->
                                <button onclick="viewActivity(<?= $mod['id'] ?>)" class="text-gray-400 hover:text-blue-400 transition p-1" title="View Activity">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4">
        <h4 class="text-blue-300 font-medium mb-2">ℹ️ Informazioni sul Portale Moderazione</h4>
        <ul class="text-gray-400 text-sm space-y-1">
            <li>• I moderatori accedono a un <strong>portale separato</strong> con un URL dinamico (mostrato sopra)</li>
            <li>• L'URL del portale cambia ogni ora per sicurezza - condividi l'URL corrente con i moderatori</li>
            <li>• I moderatori possono moderare solo le <strong>chat room pubbliche</strong> - i DM sono privati e non accessibili</li>
            <li>• Le email 2FA vengono inviate da <strong>moderation@need2talk.it</strong></li>
            <li>• Tutte le azioni dei moderatori sono registrate nell'audit trail</li>
        </ul>
    </div>
</div>

<!-- Create Moderator Modal (Edit is disabled - delete and recreate for changes) -->
<div id="moderatorModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-gray-800 border border-gray-700 rounded-xl w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-700">
            <h3 class="text-lg font-semibold text-white">Aggiungi Moderatore</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="moderatorForm" onsubmit="submitModeratorForm(event)" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Username *</label>
                    <input type="text" id="username" name="username" required pattern="[a-zA-Z0-9_]{3,50}"
                           class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                           placeholder="mod_username">
                    <p class="text-xs text-gray-500 mt-1">3-50 caratteri, alfanumerico + underscore</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Nome Visualizzato *</label>
                    <input type="text" id="displayName" name="display_name" required maxlength="100"
                           class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                           placeholder="Mario Rossi">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">Email *</label>
                <input type="email" id="email" name="email" required
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                       placeholder="moderatore@example.com">
                <p class="text-xs text-gray-500 mt-1">I codici 2FA verranno inviati qui</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1">Password *</label>
                <div class="flex gap-2">
                    <input type="text" id="password" name="password" required minlength="12"
                           class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm font-mono focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                           placeholder="Minimo 12 caratteri">
                    <button type="button" onclick="generatePassword()" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-2 rounded-lg text-sm transition">
                        Genera
                    </button>
                </div>
                <p class="text-xs text-yellow-500 mt-1">Un'email con le credenziali verrà inviata al moderatore</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Permessi</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" id="canViewRooms" name="can_view_rooms" checked class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        👁️ Visualizza Stanze
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" id="canBanUsers" name="can_ban_users" checked class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        🚫 Banna Utenti
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" id="canDeleteMessages" name="can_delete_messages" checked class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        🗑️ Elimina Messaggi
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" id="canManageKeywords" name="can_manage_keywords" class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        📝 Gestisci Parole Chiave
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" id="canViewReports" name="can_view_reports" checked class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        📋 Visualizza Segnalazioni
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" id="canResolveReports" name="can_resolve_reports" checked class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        ✅ Risolvi Segnalazioni
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer col-span-2">
                        <input type="checkbox" id="canEscalateReports" name="can_escalate_reports" checked class="rounded bg-gray-700 border-gray-600 text-purple-500">
                        ⬆️ Escalation Admin
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-700">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-gray-400 hover:text-white transition">
                    Annulla
                </button>
                <button type="submit" class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition">
                    Crea Moderatore
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Activity Modal -->
<div id="activityModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-gray-800 border border-gray-700 rounded-xl w-full max-w-2xl shadow-2xl max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-700">
            <h3 class="text-lg font-semibold text-white">Log Attività Moderatore</h3>
            <button onclick="closeActivityModal()" class="text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div id="activityContent" class="p-6 overflow-y-auto flex-1">
            <div class="text-gray-400 text-center py-8">Loading...</div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-gray-800 border border-gray-700 rounded-xl w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-700">
            <h3 class="text-lg font-semibold text-white">🔑 Reset Password</h3>
            <button onclick="closeResetPasswordModal()" class="text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="p-6">
            <input type="hidden" id="resetPasswordModeratorId" value="">

            <div class="mb-4">
                <p class="text-gray-400 text-sm mb-3">
                    Reset password per: <strong id="resetPasswordModeratorName" class="text-purple-300"></strong>
                </p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-1">Nuova Password *</label>
                <div class="flex gap-2">
                    <input type="text" id="resetPasswordInput" required minlength="12"
                           class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm font-mono focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                           placeholder="Minimo 12 caratteri">
                    <button type="button" onclick="generateResetPassword()" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-2 rounded-lg text-sm transition">
                        Genera
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Condividi questa password in modo sicuro con il moderatore</p>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-700">
                <button type="button" onclick="closeResetPasswordModal()" class="px-4 py-2 text-gray-400 hover:text-white transition">
                    Annulla
                </button>
                <button type="button" onclick="submitResetPassword()" id="resetPasswordBtn" class="bg-gradient-to-r from-yellow-600 to-orange-600 hover:from-yellow-700 hover:to-orange-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition">
                    Reset Password
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
const csrf_token = '<?= csrf_token() ?>';

// ============================================================
// ENTERPRISE GALAXY: Checkbox Selection for Send Portal URL
// ============================================================
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAllMods');
    const checkboxes = document.querySelectorAll('.mod-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSendButton();
}

function updateSendButton() {
    const selected = document.querySelectorAll('.mod-checkbox:checked');
    const btn = document.getElementById('sendUrlBtn');
    const count = selected.length;

    if (count > 0) {
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
            </svg>
            Send Portal URL (${count})
        `;
    } else {
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
        btn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
            </svg>
            Send Portal URL
        `;
    }

    // Update "Select All" checkbox state
    const allCheckboxes = document.querySelectorAll('.mod-checkbox');
    const selectAll = document.getElementById('selectAllMods');
    selectAll.checked = allCheckboxes.length > 0 && count === allCheckboxes.length;
    selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
}

async function sendPortalUrlToSelected() {
    const selected = document.querySelectorAll('.mod-checkbox:checked');
    if (selected.length === 0) {
        showNotification('Select at least one moderator', 'error');
        return;
    }

    const names = Array.from(selected).map(cb => cb.dataset.name).join(', ');
    if (!confirm(`Send current Portal URL to ${selected.length} moderator(s)?\n\n${names}`)) {
        return;
    }

    const ids = Array.from(selected).map(cb => cb.dataset.id);

    try {
        const response = await fetch('api/moderators/send-portal-url', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf_token,
            },
            body: JSON.stringify({ moderator_ids: ids }),
        });

        const result = await response.json();

        if (result.success) {
            showNotification(result.message || `Portal URL sent to ${selected.length} moderator(s)`, 'success');
            // Uncheck all after sending
            document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllMods').checked = false;
            updateSendButton();
        } else {
            showNotification(result.error || 'Failed to send emails', 'error');
        }
    } catch (e) {
        showNotification('Request failed: ' + e.message, 'error');
    }
}

function copyPortalUrl() {
    const url = document.getElementById('portalUrl').textContent;
    navigator.clipboard.writeText(window.location.origin + url).then(() => {
        showNotification('Portal URL copied to clipboard', 'success');
    });
}

async function refreshPortalUrl() {
    try {
        const response = await fetch('api/moderators/portal-url', {
            headers: { 'X-CSRF-Token': csrf_token }
        });
        const data = await response.json();
        if (data.success) {
            document.getElementById('portalUrl').textContent = data.url;
            showNotification('Portal URL refreshed', 'success');
        }
    } catch (e) {
        console.error('Failed to refresh URL:', e);
    }
}

function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < 16; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('password').value = password;
}

function openCreateModal() {
    document.getElementById('moderatorForm').reset();
    document.getElementById('moderatorModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('moderatorModal').classList.add('hidden');
}

async function submitModeratorForm(e) {
    e.preventDefault();

    const data = {
        username: document.getElementById('username').value,
        email: document.getElementById('email').value,
        display_name: document.getElementById('displayName').value,
        password: document.getElementById('password').value,
        can_view_rooms: document.getElementById('canViewRooms').checked,
        can_ban_users: document.getElementById('canBanUsers').checked,
        can_delete_messages: document.getElementById('canDeleteMessages').checked,
        can_manage_keywords: document.getElementById('canManageKeywords').checked,
        can_view_reports: document.getElementById('canViewReports').checked,
        can_resolve_reports: document.getElementById('canResolveReports').checked,
        can_escalate_reports: document.getElementById('canEscalateReports').checked,
    };

    try {
        const response = await fetch('api/moderators/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf_token,
            },
            body: JSON.stringify(data),
        });

        const result = await response.json();

        if (result.success) {
            showNotification(result.message || 'Moderator created successfully', 'success');
            closeModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(result.error || 'Operation failed', 'error');
        }
    } catch (e) {
        showNotification('Request failed: ' + e.message, 'error');
    }
}

// ENTERPRISE GALAXY: Toggle suspension (temporary - can be reversed)
async function toggleSuspension(id, displayName, isCurrentlyActive) {
    const action = isCurrentlyActive ? 'suspend' : 'unsuspend';
    if (!confirm(`${isCurrentlyActive ? 'Suspend' : 'Unsuspend'} moderator "${displayName}"?`)) return;

    try {
        const response = await fetch('api/moderators/toggle-status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf_token,
            },
            body: JSON.stringify({ id }),
        });

        const result = await response.json();

        if (result.success) {
            showNotification(result.message || `Moderator ${action}ed`, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(result.error || 'Action failed', 'error');
        }
    } catch (e) {
        showNotification('Request failed: ' + e.message, 'error');
    }
}

// ENTERPRISE GALAXY: Soft delete (permanent - moderator is deactivated but data preserved)
async function deleteModerator(id, displayName) {
    if (!confirm(`Delete moderator "${displayName}"?\n\nThis will permanently remove their access.`)) return;

    const reason = prompt('Optional: Enter a reason for deletion (or leave empty):', '');

    try {
        const response = await fetch('api/moderators/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf_token,
            },
            body: JSON.stringify({ id, reason: reason || 'Deleted by admin' }),
        });

        const result = await response.json();

        if (result.success) {
            showNotification('Moderator deleted successfully', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(result.error || 'Delete failed', 'error');
        }
    } catch (e) {
        showNotification('Request failed: ' + e.message, 'error');
    }
}

function resetPassword(id, displayName) {
    document.getElementById('resetPasswordModeratorId').value = id;
    document.getElementById('resetPasswordModeratorName').textContent = displayName || 'Moderator #' + id;
    document.getElementById('resetPasswordInput').value = '';
    document.getElementById('resetPasswordModal').classList.remove('hidden');
}

function generateResetPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < 16; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('resetPasswordInput').value = password;
}

function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.add('hidden');
}

async function submitResetPassword() {
    const id = document.getElementById('resetPasswordModeratorId').value;
    const newPassword = document.getElementById('resetPasswordInput').value;

    if (!newPassword) {
        showNotification('Please enter a password', 'error');
        return;
    }
    if (newPassword.length < 12) {
        showNotification('Password must be at least 12 characters', 'error');
        return;
    }

    try {
        const response = await fetch('api/moderators/reset-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf_token,
            },
            body: JSON.stringify({ id, new_password: newPassword }),
        });

        const result = await response.json();

        if (result.success) {
            showNotification('Password reset successfully. Share the new password securely.', 'success');
            closeResetPasswordModal();
        } else {
            showNotification(result.error || 'Password reset failed', 'error');
        }
    } catch (e) {
        showNotification('Request failed: ' + e.message, 'error');
    }
}

async function viewActivity(id) {
    document.getElementById('activityModal').classList.remove('hidden');
    document.getElementById('activityContent').innerHTML = '<div class="text-gray-400 text-center py-8">Loading...</div>';

    try {
        const response = await fetch(`api/moderators/${id}/activity?limit=50`, {
            headers: { 'X-CSRF-Token': csrf_token }
        });
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            let html = '<div class="space-y-3">';
            result.data.forEach(activity => {
                const date = new Date(activity.created_at).toLocaleString('it-IT');
                const details = activity.details ? JSON.parse(activity.details) : {};
                html += `
                    <div class="bg-gray-700/50 rounded-lg p-3">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-purple-300 font-medium">${activity.action_type.replace(/_/g, ' ')}</span>
                            <span class="text-gray-500 text-xs">${date}</span>
                        </div>
                        ${activity.target_user_id ? `<div class="text-gray-400 text-sm">Target User: #${activity.target_user_id}</div>` : ''}
                        ${activity.ip_address ? `<div class="text-gray-500 text-xs">IP: ${activity.ip_address}</div>` : ''}
                    </div>
                `;
            });
            html += '</div>';
            document.getElementById('activityContent').innerHTML = html;
        } else {
            document.getElementById('activityContent').innerHTML = '<div class="text-gray-500 text-center py-8">No activity recorded yet.</div>';
        }
    } catch (e) {
        document.getElementById('activityContent').innerHTML = '<div class="text-red-400 text-center py-8">Failed to load activity.</div>';
    }
}

function closeActivityModal() {
    document.getElementById('activityModal').classList.add('hidden');
}

// Close modals on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeModal();
        closeActivityModal();
        closeResetPasswordModal();
    }
});
</script>
