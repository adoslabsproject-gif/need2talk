<!-- ENTERPRISE GALAXY: GESTIONE CONTENUTI AUDIO -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-music mr-3"></i>
    Gestione Contenuti Audio
</h2>

<!-- STATISTICHE -->
<div class="stats-grid mb-8">
    <div class="stat-card">
        <span class="stat-value"><?= $stats['total_posts'] ?? 0 ?></span>
        <div class="stat-label">🎵 Audio Post Totali</div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $stats['posts_last_24h'] ?? 0 ?></span>
        <div class="stat-label">📅 Ultime 24 Ore</div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $stats['total_reactions'] ?? 0 ?></span>
        <div class="stat-label">❤️ Reazioni Totali</div>
    </div>
    <div class="stat-card">
        <span class="stat-value">
            <?php if (!empty($stats['top_emotion'])): ?>
                <?= htmlspecialchars($stats['top_emotion']['icon_emoji'] ?? '') ?>
                <?= htmlspecialchars($stats['top_emotion']['name_it'] ?? 'N/A') ?>
            <?php else: ?>
                N/A
            <?php endif; ?>
        </span>
        <div class="stat-label">🔥 Emozione Top</div>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $stats['total_storage_formatted'] ?? '0 B' ?></span>
        <div class="stat-label">💾 Spazio Utilizzato</div>
    </div>
</div>

<!-- TABELLA AUDIO POST -->
<div class="card">
    <div class="flex items-center justify-between mb-4">
        <h3 class="mb-0">
            <i class="fas fa-headphones mr-2"></i>
            Audio Post (<?= $total_posts ?? 0 ?> totali)
        </h3>
        <div class="flex items-center gap-3 flex-wrap">
            <select id="per_page" class="form-control" style="width: auto;" onchange="changePerPage(this.value)">
                <?php foreach ($per_page_options as $option) { ?>
                    <option value="<?= $option ?>" <?= ($option === $current_per_page) ? 'selected' : '' ?>>
                        <?= $option ?> per pagina
                    </option>
                <?php } ?>
            </select>

            <button onclick="refreshAudioPosts()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Aggiorna
            </button>
            <button onclick="exportAudioPosts()" class="btn btn-success">
                <i class="fas fa-download"></i> Esporta
            </button>
        </div>
    </div>

    <!-- SEZIONE FILTRI -->
    <div class="mb-4 p-4 bg-slate-800 rounded-lg border border-slate-700">
        <h4 class="text-sm font-medium mb-3 text-slate-300">
            <i class="fas fa-filter mr-2"></i>Filtri
        </h4>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Cerca -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">Cerca</label>
                <input
                    type="text"
                    id="filter_search"
                    class="form-control w-full"
                    placeholder="Contenuto, utente, email..."
                    value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
                    onchange="applyFilters()"
                >
            </div>

            <!-- Visibilità -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">Visibilità</label>
                <select id="filter_visibility" class="form-control w-full" onchange="applyFilters()">
                    <option value="all" <?= ($filters['visibility'] ?? 'all') === 'all' ? 'selected' : '' ?>>Tutti</option>
                    <option value="public" <?= ($filters['visibility'] ?? '') === 'public' ? 'selected' : '' ?>>Pubblico</option>
                    <option value="friends" <?= ($filters['visibility'] ?? '') === 'friends' ? 'selected' : '' ?>>Solo Amici</option>
                    <option value="private" <?= ($filters['visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Privato</option>
                </select>
            </div>

            <!-- Stato Moderazione -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">Moderazione</label>
                <select id="filter_moderation" class="form-control w-full" onchange="applyFilters()">
                    <option value="all" <?= ($filters['moderation_status'] ?? 'all') === 'all' ? 'selected' : '' ?>>Tutti</option>
                    <option value="pending" <?= ($filters['moderation_status'] ?? '') === 'pending' ? 'selected' : '' ?>>In Attesa</option>
                    <option value="approved" <?= ($filters['moderation_status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approvato</option>
                    <option value="rejected" <?= ($filters['moderation_status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rifiutato</option>
                </select>
            </div>

            <!-- ID Utente -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">ID Utente</label>
                <input
                    type="number"
                    id="filter_user_id"
                    class="form-control w-full"
                    placeholder="Filtra per utente..."
                    value="<?= htmlspecialchars($filters['user_id'] ?? '') ?>"
                    onchange="applyFilters()"
                >
            </div>

            <!-- Data Da -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">Data Da</label>
                <input
                    type="date"
                    id="filter_date_from"
                    class="form-control w-full"
                    value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
                    onchange="applyFilters()"
                >
            </div>

            <!-- Data A -->
            <div>
                <label class="block text-xs text-slate-400 mb-1">Data A</label>
                <input
                    type="date"
                    id="filter_date_to"
                    class="form-control w-full"
                    value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
                    onchange="applyFilters()"
                >
            </div>

            <!-- Pulisci Filtri -->
            <div class="flex items-end">
                <button onclick="clearFilters()" class="btn btn-secondary w-full">
                    <i class="fas fa-times"></i> Pulisci Filtri
                </button>
            </div>
        </div>
    </div>

    <!-- ENTERPRISE: Wrapper scrollabile per overflow orizzontale -->
    <div class="table-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <table id="audio-posts-table" class="sticky-header" style="font-size: 13px; table-layout: auto; min-width: 1400px; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 50px;"><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th>
                    <th style="min-width: 60px;">ID</th>
                    <th style="min-width: 180px;">UUID</th>
                    <th style="min-width: 150px;">Utente</th>
                    <th style="min-width: 200px;">Titolo</th>
                    <th style="min-width: 250px;">Descrizione</th>
                    <th style="min-width: 100px;">Visibilità</th>
                    <th style="min-width: 120px;">Moderazione</th>
                    <th style="min-width: 80px;">Commenti</th>
                    <th style="min-width: 80px;">Durata</th>
                    <th style="min-width: 100px;">Dimensione</th>
                    <th style="min-width: 150px;">Data Creazione</th>
                    <th style="min-width: 150px;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($posts)) {
                    foreach ($posts as $post) { ?>
                <tr>
                    <td class="text-center"><input type="checkbox" class="audio-checkbox" value="<?= $post['id'] ?>"></td>
                    <td class="text-center"><?= htmlspecialchars($post['id']) ?></td>
                    <td class="font-mono text-xs"><?= htmlspecialchars($post['uuid']) ?></td>
                    <td>
                        <div class="flex items-center gap-2">
                            <?php if (!empty($post['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars(admin_avatar_url($post['avatar_url'])) ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs">
                                    <?= strtoupper(substr($post['nickname'] ?? 'U', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex flex-col">
                                <span class="font-medium text-blue-400"><?= htmlspecialchars($post['nickname'] ?? 'Sconosciuto') ?></span>
                                <span class="text-xs text-gray-500">ID: <?= $post['user_id'] ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="text-white font-medium">
                        <?php
                            $title = $post['audio_title'] ?? '';
                            $titleTruncated = mb_strlen($title) > 50 ? mb_substr($title, 0, 50) . '...' : $title;
                            echo $titleTruncated ? htmlspecialchars($titleTruncated) : '<span class="text-gray-500 italic">Senza titolo</span>';
                        ?>
                    </td>
                    <td class="text-gray-300">
                        <?php
                            $content = $post['content'] ?? '';
                            $truncated = mb_strlen($content) > 80 ? mb_substr($content, 0, 80) . '...' : $content;
                            echo $truncated ? htmlspecialchars($truncated) : '<span class="text-gray-500 italic">-</span>';
                        ?>
                    </td>
                    <td>
                        <?php
                            $visibilityLabels = [
                                'public' => 'Pubblico',
                                'friends' => 'Amici',
                                'private' => 'Privato'
                            ];
                            $visibilityLabel = $visibilityLabels[$post['visibility'] ?? ''] ?? ucfirst($post['visibility'] ?? 'sconosciuto');
                        ?>
                        <span class="badge badge-<?= $post['visibility'] === 'public' ? 'success' : ($post['visibility'] === 'friends' ? 'warning' : 'secondary') ?>">
                            <?= htmlspecialchars($visibilityLabel) ?>
                        </span>
                    </td>
                    <td>
                        <?php
                            $moderationLabels = [
                                'approved' => 'Approvato',
                                'pending' => 'In Attesa',
                                'rejected' => 'Rifiutato'
                            ];
                            $moderationLabel = $moderationLabels[$post['moderation_status'] ?? ''] ?? ucfirst($post['moderation_status'] ?? 'in attesa');
                        ?>
                        <span class="badge badge-<?= $post['moderation_status'] === 'approved' ? 'success' : ($post['moderation_status'] === 'pending' ? 'warning' : 'danger') ?>">
                            <?= htmlspecialchars($moderationLabel) ?>
                        </span>
                    </td>
                    <td class="text-center"><?= $post['comment_count'] ?? 0 ?></td>
                    <td class="text-center"><?= $post['audio_duration_formatted'] ?? 'N/A' ?></td>
                    <td class="text-center"><?= $post['audio_file_size_formatted'] ?? 'N/A' ?></td>
                    <td class="text-gray-400 text-xs"><?= date('d/m/Y H:i', strtotime($post['created_at'])) ?></td>
                    <td>
                        <div class="flex gap-1">
                            <button onclick="viewAudioPost(<?= $post['id'] ?>)" class="btn-xs btn-info" title="Visualizza">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="editAudioPost(<?= $post['id'] ?>)" class="btn-xs btn-warning" title="Modifica">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteAudioPost(<?= $post['id'] ?>)" class="btn-xs btn-danger" title="Elimina">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php if ($post['moderation_status'] === 'pending'): ?>
                                <button onclick="approveAudioPost(<?= $post['id'] ?>)" class="btn-xs btn-success" title="Approva">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php }
                    } else { ?>
                <tr>
                    <td colspan="13" class="text-center text-gray-400 py-8">
                        <i class="fas fa-inbox text-4xl mb-3"></i>
                        <p>Nessun audio post trovato. Prova a modificare i filtri.</p>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINAZIONE -->
    <?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-700">
        <div class="text-sm text-slate-400">
            Pagina <?= $current_page ?> di <?= $total_pages ?>
            (<?= $total_posts ?> post totali)
        </div>
        <div class="flex gap-2">
            <?php if ($pagination['has_prev']): ?>
                <button onclick="goToPage(<?= $pagination['prev'] ?>)" class="btn btn-secondary">
                    <i class="fas fa-chevron-left"></i> Precedente
                </button>
            <?php endif; ?>

            <?php
            // Mostra numeri pagina (max 5 intorno alla pagina corrente)
            $start = max(1, $current_page - 2);
            $end = min($total_pages, $current_page + 2);

            if ($start > 1): ?>
                <button onclick="goToPage(1)" class="btn btn-secondary">1</button>
                <?php if ($start > 2): ?>
                    <span class="px-2 text-slate-400">...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <button
                    onclick="goToPage(<?= $i ?>)"
                    class="btn <?= $i === $current_page ? 'btn-primary' : 'btn-secondary' ?>"
                >
                    <?= $i ?>
                </button>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?>
                    <span class="px-2 text-slate-400">...</span>
                <?php endif; ?>
                <button onclick="goToPage(<?= $total_pages ?>)" class="btn btn-secondary"><?= $total_pages ?></button>
            <?php endif; ?>

            <?php if ($pagination['has_next']): ?>
                <button onclick="goToPage(<?= $pagination['next'] ?>)" class="btn btn-secondary">
                    Successiva <i class="fas fa-chevron-right"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- JAVASCRIPT PER INTERAZIONI -->
<script nonce="<?= csp_nonce() ?>">
// Cambia per pagina
function changePerPage(perPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page', '1'); // Reset a pagina 1
    window.location.href = url.toString();
}

// Vai a pagina
function goToPage(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
}

// Applica filtri
function applyFilters() {
    const url = new URL(window.location.href);

    // Ottieni valori filtri
    const search = document.getElementById('filter_search').value;
    const visibility = document.getElementById('filter_visibility').value;
    const moderation = document.getElementById('filter_moderation').value;
    const userId = document.getElementById('filter_user_id').value;
    const dateFrom = document.getElementById('filter_date_from').value;
    const dateTo = document.getElementById('filter_date_to').value;

    // Applica filtri a URL
    if (search) url.searchParams.set('search', search);
    else url.searchParams.delete('search');

    if (visibility !== 'all') url.searchParams.set('visibility', visibility);
    else url.searchParams.delete('visibility');

    if (moderation !== 'all') url.searchParams.set('moderation_status', moderation);
    else url.searchParams.delete('moderation_status');

    if (userId) url.searchParams.set('user_id', userId);
    else url.searchParams.delete('user_id');

    if (dateFrom) url.searchParams.set('date_from', dateFrom);
    else url.searchParams.delete('date_from');

    if (dateTo) url.searchParams.set('date_to', dateTo);
    else url.searchParams.delete('date_to');

    // Reset a pagina 1 quando si filtra
    url.searchParams.set('page', '1');

    window.location.href = url.toString();
}

// Pulisci filtri
function clearFilters() {
    const url = new URL(window.location.href);
    url.searchParams.delete('search');
    url.searchParams.delete('visibility');
    url.searchParams.delete('moderation_status');
    url.searchParams.delete('user_id');
    url.searchParams.delete('date_from');
    url.searchParams.delete('date_to');
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Aggiorna
function refreshAudioPosts() {
    window.location.reload();
}

// Esporta
function exportAudioPosts() {
    alert('Funzionalità di esportazione in arrivo!');
}

// Toggle tutte le checkbox
function toggleAllCheckboxes(checkbox) {
    const checkboxes = document.querySelectorAll('.audio-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

// Visualizza audio post
function viewAudioPost(id) {
    window.open('/audio/' + id, '_blank');
}

// Modifica audio post
function editAudioPost(id) {
    alert('Funzionalità di modifica per post #' + id + ' in arrivo!');
}

// Elimina audio post
function deleteAudioPost(id) {
    if (!confirm('Sei sicuro di voler eliminare questo audio post?')) return;

    fetch('/admin/audio/' + id, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Audio post eliminato con successo!');
            window.location.reload();
        } else {
            alert('Errore durante l\'eliminazione: ' + (data.message || 'Errore sconosciuto'));
        }
    })
    .catch(error => {
        alert('Errore: ' + error.message);
    });
}

// Approva audio post
function approveAudioPost(id) {
    if (!confirm('Approvare questo audio post?')) return;

    fetch('/admin/audio/' + id + '/approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Audio post approvato!');
            window.location.reload();
        } else {
            alert('Errore durante l\'approvazione: ' + (data.message || 'Errore sconosciuto'));
        }
    })
    .catch(error => {
        alert('Errore: ' + error.message);
    });
}
</script>
