<!-- ENTERPRISE GALAXY V4.7: UTENTI E RATE LIMIT - ITALIANO -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-users-cog mr-3"></i>
    Utenti e Rate Limit
</h2>

<!-- STATISTICHE -->
<div class="stats-grid mb-8">
    <div class="stat-card">
        <span class="stat-value"><?= $total_users ?? 0 ?></span>
        <div class="stat-label">👥 Utenti Totali</div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $rate_limit_stats['active_bans'] ?? 0 ?></span>
        <div class="stat-label">🚫 Ban Attivi</div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $rate_limit_stats['violations_24h'] ?? 0 ?></span>
        <div class="stat-label">⚠️ Violazioni (24h)</div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $rate_limit_stats['requests_1h'] ?? 0 ?></span>
        <div class="stat-label">⚡ Richieste (1h)</div>
    </div>
</div>

<!-- TABELLA UTENTI -->
<div class="card">
    <div class="flex items-center justify-between mb-4">
        <h3 class="mb-0">
            <i class="fas fa-users mr-2"></i>
            Utenti Registrati
        </h3>
        <div class="flex items-center gap-3 flex-wrap">
            <select id="per_page" class="form-control" style="width: auto;" onchange="changePerPage(this.value)">
                <?php foreach ($per_page_options as $option) { ?>
                    <option value="<?= $option ?>" <?= ($option === $current_per_page) ? 'selected' : '' ?>>
                        <?= $option ?> per pagina
                    </option>
                <?php } ?>
            </select>

            <!-- ENTERPRISE V4.7: Dropdown Azioni Bulk Contestuale -->
            <div class="relative" id="bulk-ops-dropdown">
                <button onclick="toggleBulkOpsDropdown()" class="btn bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700">
                    <i class="fas fa-tasks"></i> Azioni Bulk <span id="selected-count" class="ml-1 bg-white/20 px-2 py-0.5 rounded text-xs">0/20</span> <i class="fas fa-chevron-down ml-1 text-xs"></i>
                </button>
                <div id="bulk-ops-dropdown-menu" class="absolute left-0 mt-2 w-64 rounded-lg shadow-lg bg-slate-800 border border-slate-700 z-10 hidden">
                    <!-- Le opzioni vengono generate dinamicamente da JavaScript in base agli utenti selezionati -->
                    <div id="bulk-ops-buttons"></div>
                    <div id="bulk-ops-empty" class="px-4 py-3 text-sm text-gray-400 text-center">
                        Seleziona almeno un utente
                    </div>
                </div>
            </div>

            <button onclick="refreshUsers()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
            <button onclick="exportSelectedUsers()" class="btn btn-success">
                <i class="fas fa-download"></i> Esporta Selezionati
            </button>
            <button onclick="exportAllUsers()" class="btn btn-warning">
                <i class="fas fa-file-csv"></i> Esporta Tutti
            </button>
        </div>
    </div>

    <!-- ENTERPRISE: Sticky headers wrapper -->
    <div class="table-wrapper">
        <table id="users-table" class="sticky-header" style="font-size: 13px; table-layout: auto; min-width: 100%;">
            <thead>
                <tr>
                    <th style="width: 50px;"><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th>
                    <th style="min-width: 60px;">ID</th>
                    <th style="min-width: 180px;">UUID</th>
                    <th style="min-width: 120px;">Nickname</th>
                    <th style="min-width: 120px;">Nome</th>
                    <th style="min-width: 120px;">Cognome</th>
                    <th style="min-width: 200px;">Email</th>
                    <th style="min-width: 100px;">Anno Nascita</th>
                    <th style="min-width: 100px;">Mese Nascita</th>
                    <th style="min-width: 100px;">Genere</th>
                    <th style="min-width: 120px;">Email Verificata</th>
                    <th style="min-width: 150px;">Verificata Il</th>
                    <th style="min-width: 80px;">Avatar</th>
                    <th style="min-width: 150px;">Ultimo Login</th>
                    <th style="min-width: 100px;">N° Login</th>
                    <th style="min-width: 150px;">Ultimo IP</th>
                    <th style="min-width: 120px;">Login Falliti</th>
                    <th style="min-width: 150px;">Bloccato Fino</th>
                    <th style="min-width: 160px;">Password Cambiata</th>
                    <th style="min-width: 150px;">Creato Il</th>
                    <th style="min-width: 150px;">Aggiornato Il</th>
                    <th style="min-width: 100px;">Stato</th>
                    <th style="min-width: 120px;">Newsletter</th>
                    <th style="min-width: 150px;">Iscrizione NL</th>
                    <th style="min-width: 150px;">Disiscrizione NL</th>
                    <th style="min-width: 180px;">Token Disiscrizione</th>
                    <th style="min-width: 150px;">Consenso GDPR</th>
                    <th style="min-width: 150px;">IP Registrazione</th>
                    <th style="min-width: 250px;">User Agent</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($users)) {
                    foreach ($users as $user) { ?>
                    <?php
                        $email_verified_at_formatted = $user['email_verified_at'] ? date('d/m/Y H:i', strtotime($user['email_verified_at'])) : '-';
                        $locked_until_formatted = $user['locked_until'] ? date('d/m/Y H:i', strtotime($user['locked_until'])) : '-';
                        $password_changed_formatted = $user['password_changed_at'] ? date('d/m/Y H:i', strtotime($user['password_changed_at'])) : '-';
                        $gdpr_consent_formatted = $user['gdpr_consent_at'] ? date('d/m/Y H:i', strtotime($user['gdpr_consent_at'])) : '-';
                        // ENTERPRISE V4.7: Style per utenti cancellati
                        $isDeleted = !empty($user['is_deleted']);
                        $rowClass = $isDeleted ? 'opacity-50 bg-slate-800/30' : '';
                        // Traduzione stato
                        $statusLabels = [
                            'active' => 'Attivo',
                            'suspended' => 'Sospeso',
                            'banned' => 'Bannato',
                            'deleted' => 'Eliminato'
                        ];
                        $statusLabel = $statusLabels[$user['status']] ?? ucfirst($user['status']);
                        ?>
                <tr class="<?= $rowClass ?>">
                    <!-- ENTERPRISE V4.7: data-status per dropdown contestuale -->
                    <td class="text-center">
                        <input type="checkbox" class="user-checkbox" value="<?= $user['id'] ?>"
                               data-status="<?= htmlspecialchars($user['status']) ?>"
                               data-email-verified="<?= $user['email_verified'] ? '1' : '0' ?>">
                    </td>
                    <td class="text-center"><?= htmlspecialchars($user['id']) ?></td>
                    <td class="font-mono text-xs"><?= htmlspecialchars($user['uuid']) ?></td>
                    <td class="font-medium <?= $isDeleted ? 'text-gray-500 line-through' : 'text-blue-400' ?>">
                        <?php if ($isDeleted) { ?><i class="fas fa-trash-alt text-red-400 mr-1" title="Eliminato - Ripristinabile"></i><?php } ?>
                        <?= htmlspecialchars($user['nickname']) ?>
                    </td>
                    <td class="text-gray-300"><?= htmlspecialchars($user['name'] ?: '-') ?></td>
                    <td class="text-gray-300"><?= htmlspecialchars($user['surname'] ?: '-') ?></td>
                    <td class="text-gray-300"><?= htmlspecialchars($user['email']) ?></td>
                    <td class="text-center text-gray-300"><?= $user['birth_year'] ?: '-' ?></td>
                    <td class="text-center text-gray-300"><?= $user['birth_month'] ?: '-' ?></td>
                    <td class="text-gray-300"><?= htmlspecialchars(ucfirst($user['gender'])) ?></td>
                    <td class="text-center">
                        <span class="badge badge-<?= $user['email_verified_badge'] ?>">
                            <?= $user['email_verified'] ? 'Sì' : 'No' ?>
                        </span>
                    </td>
                    <td class="text-gray-400 text-xs"><?= $email_verified_at_formatted ?></td>
                    <td class="text-center">
                        <?php if (!empty($user['avatar_url'])) { ?>
                            <a href="<?= htmlspecialchars(admin_avatar_url($user['avatar_url'])) ?>" target="_blank" title="Clicca per ingrandire">
                                <img src="<?= htmlspecialchars(admin_avatar_url($user['avatar_url'])) ?>"
                                     alt="Avatar di <?= htmlspecialchars($user['nickname']) ?>"
                                     class="w-10 h-10 rounded-full object-cover border-2 border-purple-500/50 hover:border-purple-400 hover:scale-110 transition-all duration-200 shadow-lg"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="w-10 h-10 rounded-full bg-slate-700 items-center justify-center text-slate-400 text-xs hidden">
                                    <i class="fas fa-user"></i>
                                </div>
                            </a>
                        <?php } else { ?>
                            <div class="w-10 h-10 rounded-full bg-slate-700/50 flex items-center justify-center text-slate-500 mx-auto">
                                <i class="fas fa-user-circle text-lg"></i>
                            </div>
                        <?php } ?>
                    </td>
                    <td class="text-gray-400 text-xs"><?= $user['last_login_formatted'] ?></td>
                    <td class="text-center"><span class="font-bold text-green-400"><?= $user['login_count'] ?? 0 ?></span></td>
                    <td class="text-gray-400 font-mono text-xs"><?= htmlspecialchars($user['last_ip'] ?: '-') ?></td>
                    <td class="text-center">
                        <?php if ($user['failed_login_attempts'] > 0) { ?>
                            <span class="font-bold text-red-400"><?= $user['failed_login_attempts'] ?></span>
                        <?php } else { ?>
                            <span class="text-gray-600">0</span>
                        <?php } ?>
                    </td>
                    <td class="text-center">
                        <?php if ($user['locked_until']) { ?>
                            <span class="badge badge-danger" title="<?= $locked_until_formatted ?>">
                                <i class="fas fa-lock"></i> Bloccato
                            </span>
                        <?php } else { ?>
                            <span class="text-gray-600">-</span>
                        <?php } ?>
                    </td>
                    <td class="text-gray-400 text-xs"><?= $password_changed_formatted ?></td>
                    <td class="text-gray-400 text-xs"><?= $user['created_at_formatted'] ?></td>
                    <td class="text-gray-400 text-xs"><?= date('d/m/Y H:i', strtotime($user['updated_at'])) ?></td>
                    <td class="text-center">
                        <span class="badge badge-<?= $user['status_badge'] ?>">
                            <?= $statusLabel ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?php if ($user['newsletter_opt_in']) { ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check-circle mr-1"></i>Iscritto
                            </span>
                        <?php } else { ?>
                            <span class="badge badge-secondary">
                                <i class="fas fa-times-circle mr-1"></i>Non Iscritto
                            </span>
                        <?php } ?>
                    </td>
                    <td class="text-gray-400 text-xs">
                        <?= $user['newsletter_opt_in_at'] ? date('d/m/Y H:i', strtotime($user['newsletter_opt_in_at'])) : '-' ?>
                    </td>
                    <td class="text-gray-400 text-xs">
                        <?= $user['newsletter_opt_out_at'] ? date('d/m/Y H:i', strtotime($user['newsletter_opt_out_at'])) : '-' ?>
                    </td>
                    <td class="font-mono text-xs text-purple-400" title="<?= htmlspecialchars($user['newsletter_unsubscribe_token'] ?: 'Nessun token') ?>">
                        <?php if ($user['newsletter_unsubscribe_token']) { ?>
                            <?= htmlspecialchars(substr($user['newsletter_unsubscribe_token'], 0, 16)) ?>...
                        <?php } else { ?>
                            <span class="text-gray-600">-</span>
                        <?php } ?>
                    </td>
                    <td class="text-gray-400 text-xs"><?= $gdpr_consent_formatted ?></td>
                    <td class="text-gray-400 font-mono text-xs"><?= htmlspecialchars($user['registration_ip'] ?: '-') ?></td>
                    <td class="text-gray-400 text-xs"><?= htmlspecialchars($user['user_agent'] ?: '-') ?></td>
                </tr>
                <?php }
                    } else { ?>
                <tr>
                    <td colspan="29" class="text-center text-gray-400">Nessun utente trovato</td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Paginazione -->
    <?php if ($total_pages > 1) { ?>
    <div class="mt-4">
        <div class="pagination-info mb-2 text-gray-400">
            Pagina <?= $current_page ?> di <?= $total_pages ?> (<?= $total_users ?> utenti totali)
        </div>
        <div class="flex items-center gap-2">
            <?php if ($current_page > 1) { ?>
                <a href="?page=<?= $current_page - 1 ?>&per_page=<?= $current_per_page ?>" class="btn btn-secondary">← Precedente</a>
            <?php } ?>

            <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) { ?>
                <a href="?page=<?= $i ?>&per_page=<?= $current_per_page ?>"
                   class="btn <?= ($i === $current_page) ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= $i ?>
                </a>
            <?php } ?>

            <?php if ($current_page < $total_pages) { ?>
                <a href="?page=<?= $current_page + 1 ?>&per_page=<?= $current_per_page ?>" class="btn btn-secondary">Successiva →</a>
            <?php } ?>
        </div>
    </div>
    <?php } ?>
</div>

<!-- SEZIONE RATE LIMITING -->
<div class="mt-8 space-y-6">
    <h3 class="enterprise-title flex items-center">
        <i class="fas fa-shield-alt mr-3"></i>
        Gestione Rate Limiting
    </h3>

    <!-- 1. BAN RATE LIMIT -->
    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="mb-0">
                <i class="fas fa-ban mr-2"></i>
                Ban Rate Limit
            </h3>
            <button onclick="refreshRateLimitBans()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
        </div>
        <div id="rate-limit-bans-container" class="table-wrapper-dynamic">
            <p class="text-gray-400">Caricamento...</p>
        </div>
    </div>

    <!-- 2. LOG RATE LIMIT -->
    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="mb-0">
                <i class="fas fa-list mr-2"></i>
                Log Rate Limit (Ultimi 200)
            </h3>
            <button onclick="refreshRateLimitLog()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
        </div>
        <div id="rate-limit-log-container" class="table-wrapper-dynamic">
            <p class="text-gray-400">Caricamento...</p>
        </div>
    </div>

    <!-- 3. VIOLAZIONI RATE LIMIT -->
    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="mb-0">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Violazioni Rate Limit (Ultime 100)
            </h3>
            <button onclick="refreshRateLimitViolations()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
        </div>
        <div id="rate-limit-violations-container" class="table-wrapper-dynamic">
            <p class="text-gray-400">Caricamento...</p>
        </div>
    </div>

    <!-- 4. MONITOR RATE LIMIT -->
    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="mb-0">
                <i class="fas fa-chart-line mr-2"></i>
                Monitor Rate Limit (Ultimi 100)
            </h3>
            <button onclick="refreshRateLimitMonitor()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
        </div>
        <div id="rate-limit-monitor-container" class="table-wrapper-dynamic">
            <p class="text-gray-400">Caricamento...</p>
        </div>
    </div>

    <!-- 5. ALERT RATE LIMIT -->
    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="mb-0">
                <i class="fas fa-bell mr-2"></i>
                Alert Rate Limit (Ultimi 100)
            </h3>
            <button onclick="refreshRateLimitAlerts()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
        </div>
        <div id="rate-limit-alerts-container" class="table-wrapper-dynamic">
            <p class="text-gray-400">Caricamento...</p>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
/* ENTERPRISE: Sticky Table Headers */
.table-wrapper, .table-wrapper-dynamic {
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
.table-wrapper::-webkit-scrollbar,
.table-wrapper-dynamic::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

.table-wrapper::-webkit-scrollbar-track,
.table-wrapper-dynamic::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 5px;
}

.table-wrapper::-webkit-scrollbar-thumb,
.table-wrapper-dynamic::-webkit-scrollbar-thumb {
    background: rgba(147, 51, 234, 0.5);
    border-radius: 5px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover,
.table-wrapper-dynamic::-webkit-scrollbar-thumb:hover {
    background: rgba(147, 51, 234, 0.7);
}

/* Firefox scrollbar */
.table-wrapper,
.table-wrapper-dynamic {
    scrollbar-width: thin;
    scrollbar-color: rgba(147, 51, 234, 0.5) rgba(0, 0, 0, 0.2);
}

/* ENTERPRISE: Bulk Operations Dropdown */
#bulk-ops-dropdown-menu {
    background: rgba(30, 30, 40, 0.98) !important;
    backdrop-filter: blur(20px);
    border: 1px solid rgba(147, 51, 234, 0.4) !important;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    z-index: 50 !important;
}

#bulk-ops-dropdown-menu button {
    color: rgba(255, 255, 255, 0.9) !important;
    transition: all 0.2s ease;
}

#bulk-ops-dropdown-menu button:hover {
    background: rgba(147, 51, 234, 0.2) !important;
    color: white !important;
}

#bulk-ops-dropdown-menu button i {
    opacity: 1;
}

.badge {
    padding: 0.25rem 0.5rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}
.badge-success { background: rgba(34, 197, 94, 0.2); color: rgb(74, 222, 128); border: 1px solid rgba(34, 197, 94, 0.3); }
.badge-warning { background: rgba(251, 191, 36, 0.2); color: rgb(250, 204, 21); border: 1px solid rgba(251, 191, 36, 0.3); }
.badge-danger { background: rgba(239, 68, 68, 0.2); color: rgb(248, 113, 113); border: 1px solid rgba(239, 68, 68, 0.3); }
.badge-secondary { background: rgba(156, 163, 175, 0.2); color: rgb(156, 163, 175); border: 1px solid rgba(156, 163, 175, 0.3); }
</style>

<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE GALAXY V4.7: Gestione Utenti e Rate Limiting - ITALIANO

function changePerPage(perPage) {
    const url = new URL(window.location);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// ENTERPRISE: Max 20 utenti per operazione bulk
const MAX_BULK_SELECTION = 20;

function toggleAllCheckboxes(checkbox) {
    const checkboxes = document.querySelectorAll('.user-checkbox');

    if (checkbox.checked) {
        let count = 0;
        checkboxes.forEach(cb => {
            if (count < MAX_BULK_SELECTION) {
                cb.checked = true;
                count++;
            } else {
                cb.checked = false;
            }
        });

        if (checkboxes.length > MAX_BULK_SELECTION) {
            alert(`⚠️ Limite: max ${MAX_BULK_SELECTION} utenti per operazione.\nSelezionati i primi ${MAX_BULK_SELECTION}.`);
        }
    } else {
        checkboxes.forEach(cb => cb.checked = false);
    }

    updateSelectedCount();
    updateBulkOpsDropdown();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.user-checkbox:checked').length;
    const counter = document.getElementById('selected-count');
    if (counter) {
        counter.textContent = `${count}/${MAX_BULK_SELECTION}`;
        counter.className = count >= MAX_BULK_SELECTION
            ? 'ml-1 bg-red-500/50 px-2 py-0.5 rounded text-xs'
            : 'ml-1 bg-white/20 px-2 py-0.5 rounded text-xs';
    }
}

function handleCheckboxChange(checkbox) {
    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;

    if (checkbox.checked && checkedCount > MAX_BULK_SELECTION) {
        checkbox.checked = false;
        alert(`⚠️ Limite raggiunto: max ${MAX_BULK_SELECTION} utenti per operazione.`);
        return;
    }

    updateSelectedCount();
    updateBulkOpsDropdown();
}

// ENTERPRISE V4.7: Dropdown contestuale basato sullo stato degli utenti selezionati
function updateBulkOpsDropdown() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    const buttonsContainer = document.getElementById('bulk-ops-buttons');
    const emptyMessage = document.getElementById('bulk-ops-empty');

    if (checkboxes.length === 0) {
        buttonsContainer.innerHTML = '';
        emptyMessage.classList.remove('hidden');
        return;
    }

    emptyMessage.classList.add('hidden');

    // Analizza gli stati degli utenti selezionati
    const statuses = new Set();
    const emailVerifiedStatuses = new Set();

    checkboxes.forEach(cb => {
        statuses.add(cb.dataset.status);
        emailVerifiedStatuses.add(cb.dataset.emailVerified);
    });

    // Determina quali azioni mostrare
    const hasActive = statuses.has('active');
    const hasSuspended = statuses.has('suspended');
    const hasBanned = statuses.has('banned');
    const hasDeleted = statuses.has('deleted');
    const hasUnverified = emailVerifiedStatuses.has('0');

    // Solo stati non-deleted (per azioni che non si applicano ai deleted)
    const hasNonDeleted = hasActive || hasSuspended || hasBanned;

    let buttons = '';

    // ATTIVA: mostra se ci sono utenti sospesi, bannati o eliminati
    if (hasSuspended || hasBanned || hasDeleted) {
        buttons += `<button onclick="bulkActivate()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-check-circle text-green-400"></i>
            Attiva Selezionati
        </button>`;
    }

    // SOSPENDI: mostra se ci sono utenti attivi (non già sospesi)
    if (hasActive) {
        buttons += `<button onclick="bulkSuspend()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-pause-circle text-yellow-400"></i>
            Sospendi Selezionati
        </button>`;
    }

    // BANNA: mostra se ci sono utenti attivi o sospesi (non già bannati)
    if (hasActive || hasSuspended) {
        buttons += `<button onclick="bulkBan()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-gavel text-red-500"></i>
            Banna Selezionati
        </button>`;
    }

    // ELIMINA: mostra se ci sono utenti non eliminati
    if (hasNonDeleted) {
        buttons += `<button onclick="bulkDelete()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-trash text-red-400"></i>
            Elimina Selezionati
        </button>`;
    }

    // RIPRISTINA: mostra solo se ci sono utenti eliminati
    if (hasDeleted) {
        buttons += `<button onclick="bulkRestore()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-undo text-emerald-400"></i>
            Ripristina Eliminati
        </button>`;
    }

    // Separatore
    if (buttons) {
        buttons += '<div class="border-t border-slate-700 my-1"></div>';
    }

    // VERIFICA EMAIL: mostra se ci sono utenti con email non verificata (e non deleted)
    if (hasUnverified && hasNonDeleted) {
        buttons += `<button onclick="bulkForceVerify()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-shield-check text-cyan-400"></i>
            Forza Verifica Email
        </button>`;
    }

    // RESET PASSWORD: mostra se ci sono utenti non eliminati
    if (hasNonDeleted) {
        buttons += `<button onclick="bulkPasswordReset()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
            <i class="fas fa-key text-orange-400"></i>
            Invia Reset Password
        </button>`;
    }

    // EMAIL: mostra sempre se ci sono utenti selezionati
    buttons += `<button onclick="bulkEmail()" class="w-full text-left px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 flex items-center gap-2">
        <i class="fas fa-envelope text-blue-400"></i>
        Invia Email
    </button>`;

    buttonsContainer.innerHTML = buttons;
}

// Inizializza listener checkbox
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.user-checkbox').forEach(cb => {
        cb.addEventListener('change', function() { handleCheckboxChange(this); });
    });
    updateSelectedCount();
    updateBulkOpsDropdown();
});

function refreshUsers() {
    location.reload();
}

function exportSelectedUsers() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Seleziona almeno un utente da esportare');
        return;
    }

    const selectedIds = Array.from(checkboxes).map(cb => cb.value).join(',');

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/users/export-csv';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'selected_ids';
    input.value = selectedIds;

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function exportAllUsers() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/users/export-csv';
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// ENTERPRISE GALAXY: Funzioni Rate Limiting

function refreshRateLimitBans() {
    const container = document.getElementById('rate-limit-bans-container');
    container.innerHTML = '<p class="text-gray-400">Caricamento...</p>';

    fetch('api/users/rate-limit-bans')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.bans) {
                let html = '<table class="sticky-header"><thead><tr><th>ID</th><th>ID Utente</th><th>Indirizzo IP</th><th>Tipo Azione</th><th>Scade Il</th><th>Creato Il</th><th>Azioni</th></tr></thead><tbody>';

                if (data.bans.length === 0) {
                    html += '<tr><td colspan="7" class="text-center text-gray-400">Nessun ban trovato</td></tr>';
                } else {
                    data.bans.forEach(ban => {
                        html += `<tr>
                            <td>${escapeHtml(ban.id)}</td>
                            <td>${escapeHtml(ban.user_id || '-')}</td>
                            <td class="font-mono text-xs">${escapeHtml(ban.ip_address || '-')}</td>
                            <td>${escapeHtml(ban.action_type || '-')}</td>
                            <td class="text-xs">${escapeHtml(ban.expires_at || '-')}</td>
                            <td class="text-xs">${escapeHtml(ban.created_at)}</td>
                            <td><button onclick="removeBan(${ban.id})" class="btn btn-danger btn-sm">Rimuovi</button></td>
                        </tr>`;
                    });
                }

                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-danger">Errore nel caricamento dei ban</p>';
            }
        })
        .catch(err => {
            container.innerHTML = '<p class="text-danger">Errore di rete</p>';
        });
}

function refreshRateLimitLog() {
    const container = document.getElementById('rate-limit-log-container');
    container.innerHTML = '<p class="text-gray-400">Caricamento...</p>';

    fetch('api/users/rate-limit-log')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.logs) {
                let html = '<table class="sticky-header"><thead><tr><th>ID</th><th>ID Utente</th><th>IP</th><th>Azione</th><th>Stato</th><th>Creato Il</th></tr></thead><tbody>';

                if (data.logs.length === 0) {
                    html += '<tr><td colspan="6" class="text-center text-gray-400">Nessun log trovato</td></tr>';
                } else {
                    data.logs.forEach(log => {
                        html += `<tr>
                            <td>${escapeHtml(log.id)}</td>
                            <td>${escapeHtml(log.user_id || '-')}</td>
                            <td class="font-mono text-xs">${escapeHtml(log.ip_address || '-')}</td>
                            <td>${escapeHtml(log.action_type || '-')}</td>
                            <td>${escapeHtml(log.status || '-')}</td>
                            <td class="text-xs">${escapeHtml(log.created_at)}</td>
                        </tr>`;
                    });
                }

                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-danger">Errore nel caricamento dei log</p>';
            }
        })
        .catch(err => {
            container.innerHTML = '<p class="text-danger">Errore di rete</p>';
        });
}

function refreshRateLimitViolations() {
    const container = document.getElementById('rate-limit-violations-container');
    container.innerHTML = '<p class="text-gray-400">Caricamento...</p>';

    fetch('api/users/rate-limit-violations')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.violations) {
                let html = '<table class="sticky-header"><thead><tr><th>ID</th><th>ID Utente</th><th>IP</th><th>Azione</th><th>Tipo Violazione</th><th>Creato Il</th></tr></thead><tbody>';

                if (data.violations.length === 0) {
                    html += '<tr><td colspan="6" class="text-center text-gray-400">Nessuna violazione trovata</td></tr>';
                } else {
                    data.violations.forEach(v => {
                        html += `<tr>
                            <td>${escapeHtml(v.id)}</td>
                            <td>${escapeHtml(v.user_id || '-')}</td>
                            <td class="font-mono text-xs">${escapeHtml(v.ip_address || '-')}</td>
                            <td>${escapeHtml(v.action_type || '-')}</td>
                            <td>${escapeHtml(v.violation_type || '-')}</td>
                            <td class="text-xs">${escapeHtml(v.created_at)}</td>
                        </tr>`;
                    });
                }

                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-danger">Errore nel caricamento delle violazioni</p>';
            }
        })
        .catch(err => {
            container.innerHTML = '<p class="text-danger">Errore di rete</p>';
        });
}

function refreshRateLimitMonitor() {
    const container = document.getElementById('rate-limit-monitor-container');
    container.innerHTML = '<p class="text-gray-400">Caricamento...</p>';

    fetch('api/users/rate-limit-monitor')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.monitor) {
                let html = '<table class="sticky-header"><thead><tr><th>ID</th><th>ID Utente</th><th>Azione</th><th>N° Richieste</th><th>Inizio Finestra</th><th>Creato Il</th></tr></thead><tbody>';

                if (data.monitor.length === 0) {
                    html += '<tr><td colspan="6" class="text-center text-gray-400">Nessun dato di monitoraggio trovato</td></tr>';
                } else {
                    data.monitor.forEach(m => {
                        html += `<tr>
                            <td>${escapeHtml(m.id)}</td>
                            <td>${escapeHtml(m.user_id || '-')}</td>
                            <td>${escapeHtml(m.action_type || '-')}</td>
                            <td class="font-bold text-green-400">${escapeHtml(m.request_count || '0')}</td>
                            <td class="text-xs">${escapeHtml(m.window_start || '-')}</td>
                            <td class="text-xs">${escapeHtml(m.created_at)}</td>
                        </tr>`;
                    });
                }

                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-danger">Errore nel caricamento dei dati di monitoraggio</p>';
            }
        })
        .catch(err => {
            container.innerHTML = '<p class="text-danger">Errore di rete</p>';
        });
}

function refreshRateLimitAlerts() {
    const container = document.getElementById('rate-limit-alerts-container');
    container.innerHTML = '<p class="text-gray-400">Caricamento...</p>';

    fetch('api/users/rate-limit-alerts')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.alerts) {
                let html = '<table class="sticky-header"><thead><tr><th>ID</th><th>ID Utente</th><th>Tipo Alert</th><th>Gravità</th><th>Messaggio</th><th>Creato Il</th></tr></thead><tbody>';

                if (data.alerts.length === 0) {
                    html += '<tr><td colspan="6" class="text-center text-gray-400">Nessun alert trovato</td></tr>';
                } else {
                    data.alerts.forEach(alert => {
                        const severityClass = alert.severity === 'critical' ? 'text-red-400' : (alert.severity === 'warning' ? 'text-yellow-400' : 'text-blue-400');
                        html += `<tr>
                            <td>${escapeHtml(alert.id)}</td>
                            <td>${escapeHtml(alert.user_id || '-')}</td>
                            <td>${escapeHtml(alert.alert_type || '-')}</td>
                            <td class="${severityClass} font-bold">${escapeHtml(alert.severity || '-')}</td>
                            <td>${escapeHtml(alert.message || '-')}</td>
                            <td class="text-xs">${escapeHtml(alert.created_at)}</td>
                        </tr>`;
                    });
                }

                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-danger">Errore nel caricamento degli alert</p>';
            }
        })
        .catch(err => {
            container.innerHTML = '<p class="text-danger">Errore di rete</p>';
        });
}

function removeBan(banId) {
    if (!confirm('Sei sicuro di voler rimuovere questo ban?')) {
        return;
    }

    const formData = new FormData();
    formData.append('ban_id', banId);

    fetch('api/users/remove-ban', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Ban rimosso con successo', 'success');
            refreshRateLimitBans();
        } else {
            showNotification('Impossibile rimuovere il ban: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// ENTERPRISE: Funzioni Operazioni Bulk

function toggleBulkOpsDropdown() {
    const menu = document.getElementById('bulk-ops-dropdown-menu');
    menu.classList.toggle('hidden');
    // Aggiorna le opzioni ogni volta che si apre
    updateBulkOpsDropdown();
}

// Chiudi dropdown quando si clicca fuori
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('bulk-ops-dropdown');
    const menu = document.getElementById('bulk-ops-dropdown-menu');

    if (dropdown && !dropdown.contains(event.target)) {
        menu.classList.add('hidden');
    }
});

function getSelectedUserIds() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function bulkActivate() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    if (!confirm(`Sei sicuro di voler ATTIVARE ${selectedIds.length} utente/i?`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));

    fetch('api/users/bulk-activate', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function bulkSuspend() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    if (!confirm(`Sei sicuro di voler SOSPENDERE ${selectedIds.length} utente/i?`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));

    fetch('api/users/bulk-suspend', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function bulkDelete() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    if (!confirm(`⚠️ ATTENZIONE: Sei sicuro di voler ELIMINARE ${selectedIds.length} utente/i?\n\nNota: Questa è un'ELIMINAZIONE SOFT - gli utenti possono essere ripristinati successivamente.`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));

    fetch('api/users/bulk-delete', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

// ENTERPRISE V4.7: Ripristina utenti eliminati
function bulkRestore() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    if (!confirm(`Sei sicuro di voler RIPRISTINARE ${selectedIds.length} utente/i?\n\nQuesto riattiverà i loro account e renderà i loro post nuovamente visibili.`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));

    fetch('api/users/bulk-restore', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function bulkForceVerify() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    if (!confirm(`Sei sicuro di voler FORZARE LA VERIFICA EMAIL per ${selectedIds.length} utente/i?\n\nQuesto segnerà le loro email come verificate senza richiedere il click sul link di verifica.`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));

    fetch('api/users/bulk-force-verify', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function bulkBan() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    const reason = prompt(`⚠️ BANNARE ${selectedIds.length} utente/i\n\nInserisci il motivo del ban:`, 'Bannato dall\'amministratore per violazione delle policy');
    if (!reason) return;

    const blockIp = confirm('Vuoi anche BLOCCARE gli indirizzi IP di questi utenti?\n\nQuesto impedirà loro di creare nuovi account dallo stesso IP.');

    if (!confirm(`⚠️ CONFERMA FINALE:\n\nBannare ${selectedIds.length} utente/i\nMotivo: ${reason}\nBlocca IP: ${blockIp ? 'SÌ' : 'NO'}\n\nSei assolutamente sicuro?`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));
    formData.append('reason', reason);
    formData.append('block_ip', blockIp ? 'true' : 'false');

    fetch('api/users/bulk-ban', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let message = data.message;
            if (data.blocked_ips > 0) {
                message += `\n\nBloccati ${data.blocked_ips} indirizzo/i IP`;
            }
            showNotification(message, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function bulkPasswordReset() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    const expiryHours = prompt(`🔐 Invia Reset Password a ${selectedIds.length} utente/i\n\nScadenza token (ore, 1-72):`, '24');
    if (!expiryHours) return;

    const hours = parseInt(expiryHours);
    if (isNaN(hours) || hours < 1 || hours > 72) {
        alert('Ore di scadenza non valide. Deve essere tra 1 e 72.');
        return;
    }

    if (!confirm(`⚠️ CONFERMA:\n\nInviare token di reset password a ${selectedIds.length} utente/i?\nScadenza token: ${hours} ore\n\nQuesto permetterà loro di reimpostare la password.`)) {
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));
    formData.append('token_expiry_hours', hours);

    fetch('api/users/bulk-password-reset', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let message = data.message;
            if (data.errors && data.errors.length > 0) {
                message += '\n\nErrori:\n' + data.errors.slice(0, 3).join('\n');
            }
            showNotification(message, 'success');
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

function bulkEmail() {
    const selectedIds = getSelectedUserIds();

    if (selectedIds.length === 0) {
        alert('Seleziona almeno un utente');
        return;
    }

    document.getElementById('bulk-ops-dropdown-menu').classList.add('hidden');

    const subject = prompt(`Invia email a ${selectedIds.length} utente/i\n\nInserisci l'oggetto:`);
    if (!subject) return;

    const message = prompt(`Inserisci il messaggio:\n\nPuoi usare:\n{{nickname}} - Nickname utente\n{{email}} - Email utente`);
    if (!message) return;

    const formData = new FormData();
    formData.append('user_ids', selectedIds.join(','));
    formData.append('subject', subject);
    formData.append('message', message);

    fetch('api/users/bulk-email', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
        } else {
            showNotification('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(err => {
        showNotification('Errore di rete', 'danger');
    });
}

// Carica dati rate limiting al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    refreshRateLimitBans();
    refreshRateLimitLog();
    refreshRateLimitViolations();
    refreshRateLimitMonitor();
    refreshRateLimitAlerts();
});
</script>
