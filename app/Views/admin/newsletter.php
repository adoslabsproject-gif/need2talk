<!-- ENTERPRISE GALAXY: NEWSLETTER MANAGEMENT & WORKER CONTROL -->
<h2 class="enterprise-title mb-8 flex items-center">
    <i class="fas fa-newspaper mr-3"></i>
    Gestione Newsletter
    <span class="text-xs px-2 py-1 rounded ml-3" style="background: rgba(147, 51, 234, 0.2); color: #a855f7; font-weight: 600;">
        <i class="fas fa-rocket mr-1"></i>ENTERPRISE GALAXY
    </span>
</h2>

<?php
$dashboard = $dashboard ?? [];
$overall = $dashboard['overall'] ?? [];
$recentCampaigns = $dashboard['recent_campaigns'] ?? [];
$users = $dashboard['users'] ?? [];
$topCampaigns = $dashboard['top_campaigns'] ?? [];
$workerStatus = $worker_status ?? [];
?>

<!-- Summary Statistics -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <!-- Total Campaigns -->
    <div class="stat-card" style="border-left: 3px solid #a855f7;">
        <span class="stat-value" style="color: #a855f7;">
            <?= number_format($overall['total_campaigns'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-newspaper mr-2"></i>Totale Campagne
        </div>
    </div>

    <!-- Sent Campaigns -->
    <div class="stat-card" style="border-left: 3px solid #10b981;">
        <span class="stat-value" style="color: #10b981;">
            <?= number_format($overall['sent_campaigns'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-paper-plane mr-2"></i>Inviate
        </div>
    </div>

    <!-- Total Recipients -->
    <div class="stat-card" style="border-left: 3px solid #3b82f6;">
        <span class="stat-value" style="color: #3b82f6;">
            <?= number_format($overall['total_sent'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-users mr-2"></i>Totale Destinatari
        </div>
    </div>

    <!-- Open Rate -->
    <div class="stat-card" style="border-left: 3px solid #f59e0b;">
        <span class="stat-value" style="color: #f59e0b;">
            <?= number_format($overall['overall_open_rate'] ?? 0, 1) ?>%
        </span>
        <div class="stat-label">
            <i class="fas fa-envelope-open mr-2"></i>Tasso Apertura Medio
        </div>
    </div>

    <!-- Click Through Rate -->
    <div class="stat-card" style="border-left: 3px solid #ec4899;">
        <span class="stat-value" style="color: #ec4899;">
            <?= number_format($overall['overall_ctr'] ?? 0, 1) ?>%
        </span>
        <div class="stat-label">
            <i class="fas fa-mouse-pointer mr-2"></i>CTR Medio
        </div>
    </div>

    <!-- Opted In Users -->
    <div class="stat-card" style="border-left: 3px solid #06b6d4;">
        <span class="stat-value" style="color: #06b6d4;">
            <?= number_format($users['verified_opted_in'] ?? 0) ?>
        </span>
        <div class="stat-label">
            <i class="fas fa-user-check mr-2"></i>Iscritti Verificati
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tabs-container mt-8">
    <div class="tabs-header">
        <button class="tab-btn active" data-tab="campaigns">
            <i class="fas fa-list mr-2"></i>Campagne
        </button>
        <button class="tab-btn" data-tab="create">
            <i class="fas fa-plus-circle mr-2"></i>Crea Campagna
        </button>
        <button class="tab-btn" data-tab="worker-control">
            <i class="fas fa-cogs mr-2"></i>Controllo Worker & Monitor
        </button>
    </div>

    <!-- Tab: Campaigns List -->
    <div id="tab-campaigns" class="tab-content active">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-newspaper mr-2"></i>Campagne Newsletter
            </h3>
            <button onclick="refreshCampaigns()" class="btn btn-secondary btn-sm">
                <i class="fas fa-sync-alt mr-2"></i>Aggiorna
            </button>
        </div>

        <div id="campaigns-loading" class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-purple-500"></i>
            <p class="mt-2">Caricamento campagne...</p>
        </div>

        <div id="campaigns-container" style="display: none;">
            <div class="table-wrapper">
                <table class="enterprise-table sticky-header">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome Campagna</th>
                            <th>Oggetto</th>
                            <th>Stato</th>
                            <th>Destinatari</th>
                            <th>Inviate</th>
                            <th>Aperture</th>
                            <th>Click</th>
                            <th>Tasso Apertura</th>
                            <th>CTR</th>
                            <th>Creata il</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody id="campaigns-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab: Create Campaign -->
    <div id="tab-create" class="tab-content">
        <h3 class="text-xl font-bold mb-6">
            <i class="fas fa-plus-circle mr-2"></i>Crea Campagna Newsletter
        </h3>

        <form id="campaign-form" class="space-y-6">
            <!-- Campaign Details -->
            <div class="form-section">
                <h4 class="text-lg font-semibold mb-4">
                    <i class="fas fa-info-circle mr-2"></i>Dettagli Campagna
                </h4>

                <div class="form-group">
                    <label for="campaign-name" class="form-label">Nome Campagna *</label>
                    <input type="text" id="campaign-name" name="campaign_name" class="form-input" required
                           placeholder="es. Newsletter Mensile - Gennaio 2025">
                </div>

                <div class="form-group">
                    <label for="subject" class="form-label">Oggetto Email *</label>
                    <input type="text" id="subject" name="subject" class="form-input" required
                           placeholder="es. Novità di Gennaio 2025">
                </div>

                <div class="form-group">
                    <label for="preview-text" class="form-label">Testo Anteprima (Preheader)</label>
                    <input type="text" id="preview-text" name="preview_text" class="form-input"
                           placeholder="Testo visibile nell'anteprima email (max 100 caratteri)">
                    <small class="text-muted">Appare nell'anteprima della casella di posta, prima di aprire l'email</small>
                </div>

                <div class="form-group">
                    <label for="priority" class="form-label">Priorità</label>
                    <select id="priority" name="priority" class="form-select">
                        <option value="low">Bassa</option>
                        <option value="normal" selected>Normale</option>
                        <option value="high">Alta</option>
                        <option value="urgent">Urgente</option>
                    </select>
                </div>
            </div>

            <!-- Email Content (TinyMCE) -->
            <div class="form-section">
                <h4 class="text-lg font-semibold mb-4">
                    <i class="fas fa-edit mr-2"></i>Contenuto Email
                </h4>

                <div class="form-group">
                    <label for="html-body" class="form-label">Contenuto HTML Newsletter *</label>
                    <textarea id="html-body" name="html_body"></textarea>
                </div>
            </div>

            <!-- Targeting -->
            <div class="form-section">
                <h4 class="text-lg font-semibold mb-4">
                    <i class="fas fa-bullseye mr-2"></i>Targeting & Destinatari
                </h4>

                <div class="form-group">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="target-all" name="target_all_users" value="1" checked>
                        <span>Invia a tutti gli utenti verificati e iscritti</span>
                    </label>
                </div>

                <div id="filter-options" style="display: none;">
                    <div class="form-group">
                        <label for="filter-status" class="form-label">Stato Utente</label>
                        <select id="filter-status" name="filter_status" class="form-select">
                            <option value="">Tutti gli Stati</option>
                            <option value="active">Solo Attivi</option>
                            <option value="pending">Solo In Attesa</option>
                        </select>
                    </div>
                </div>

                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Destinatari Stimati:</strong> <span id="estimated-recipients"><?= number_format($users['verified_opted_in'] ?? 0) ?></span> utenti
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-4">
                <button type="button" onclick="saveDraft()" class="btn btn-secondary">
                    <i class="fas fa-save mr-2"></i>Salva come Bozza
                </button>
                <button type="button" onclick="sendNow()" class="btn btn-primary">
                    <i class="fas fa-paper-plane mr-2"></i>Invia Ora
                </button>
                <button type="button" onclick="scheduleFor()" class="btn btn-success">
                    <i class="fas fa-clock mr-2"></i>Programma per Dopo
                </button>
            </div>
        </form>
    </div>

    <!-- Tab: Worker Control & Monitoring (ENTERPRISE GALAXY - Docker Container) -->
    <div id="tab-worker-control" class="tab-content">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold">
                <i class="fas fa-server mr-2"></i>Controllo Worker Newsletter (Docker Container)
            </h3>
        </div>

        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle mr-2"></i>
            <strong>Container Dedicato Enterprise:</strong> I worker newsletter girano in un container Docker isolato (need2talk_newsletter_worker) con riavvio automatico in caso di errore.
            L'auto-recovery viene eseguito ogni 15 minuti via cron. Docker garantisce zero downtime con architettura self-healing.
        </div>

        <!-- Worker Status Stats -->
        <div class="stats-grid mb-6" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div class="stat-card" style="border-left: 3px solid #10b981;">
                <span class="stat-value" id="newsletter-worker-status-indicator" style="color: #10b981;">●</span>
                <div class="stat-label">
                    <i class="fas fa-heartbeat mr-2"></i><span id="newsletter-worker-status-text">Attivo</span>
                </div>
            </div>

            <div class="stat-card" style="border-left: 3px solid #3b82f6;">
                <span class="stat-value" id="newsletter-worker-count" style="color: #3b82f6;">0</span>
                <div class="stat-label">
                    <i class="fas fa-users mr-2"></i>Worker Attivi
                </div>
            </div>

            <div class="stat-card" style="border-left: 3px solid #8b5cf6;">
                <span class="stat-value" id="newsletter-worker-uptime" style="color: #8b5cf6;">-</span>
                <div class="stat-label">
                    <i class="fas fa-clock mr-2"></i>Uptime
                </div>
            </div>

            <div class="stat-card" style="border-left: 3px solid #f59e0b;">
                <span class="stat-value" id="newsletter-worker-memory" style="color: #f59e0b;">-</span>
                <div class="stat-label">
                    <i class="fas fa-memory mr-2"></i>Memoria
                </div>
            </div>

            <div class="stat-card" style="border-left: 3px solid #06b6d4;">
                <span class="stat-value" id="newsletter-worker-cpu" style="color: #06b6d4;">-</span>
                <div class="stat-label">
                    <i class="fas fa-microchip mr-2"></i>CPU
                </div>
            </div>

            <div class="stat-card" id="newsletter-autostart-card" style="border-left: 3px solid #10b981;">
                <span class="stat-value" id="newsletter-autostart-status" style="color: #10b981;">ON</span>
                <div class="stat-label">
                    <i class="fas fa-power-off mr-2"></i>Auto-Riavvio
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-6">
            <h4 class="text-lg font-bold mb-4">
                <i class="fas fa-bolt mr-2"></i>Azioni Rapide
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <button style="background: linear-gradient(135deg, #166534 0%, #14532d 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="startNewsletterWorkers()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Avvia Workers
                </button>
                <button style="background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="stopNewsletterWorkers()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                    Ferma Workers
                </button>
                <button style="background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="stopAndCleanNewsletterWorkers()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Ferma + Pulisci Log
                </button>
                <button style="background: linear-gradient(135deg, #7e22ce 0%, #6b21a8 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="restartNewsletterWorkers()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Riavvia Container
                </button>
                <button style="background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="getNewsletterWorkerHealth()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Controllo Salute
                </button>
                <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="enableNewsletterAutostart()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Abilita Auto-Riavvio
                </button>
                <button style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="disableNewsletterAutostart()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    Disabilita Auto-Riavvio
                </button>
                <button style="background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);" class="px-4 py-2.5 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md flex items-center justify-center gap-2" onclick="refreshNewsletterWorkerStatus()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Aggiorna Statistiche
                </button>
            </div>
        </div>

        <!-- Recent Logs -->
        <div class="card">
            <h4 class="text-lg font-bold mb-4">
                <i class="fas fa-file-alt mr-2"></i>Log Container Recenti (Ultime 50 Righe)
            </h4>
            <div id="newsletter-worker-logs" style="background: #000; padding: 1rem; border-radius: 6px; font-family: monospace; font-size: 12px; color: #0f0; max-height: 400px; overflow-y: auto;">
                <div class="text-center" style="color: #6b7280;">Nessun log disponibile</div>
            </div>
        </div>
    </div>
</div>

<!-- ENTERPRISE GALAXY: Campaign Details Modal -->
<div id="campaign-modal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); z-index: 9999; overflow-y: auto;">
    <div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 1rem; max-width: 900px; width: 100%; box-shadow: 0 25px 50px rgba(168, 85, 247, 0.3); border: 1px solid rgba(168, 85, 247, 0.2);">
            <!-- Modal Header -->
            <div style="background: linear-gradient(90deg, #a855f7 0%, #ec4899 100%); padding: 1.5rem; border-radius: 1rem 1rem 0 0; position: relative;">
                <button onclick="closeCampaignModal()" style="position: absolute; top: 1rem; right: 1rem; background: rgba(255, 255, 255, 0.2); border: none; color: white; width: 2.5rem; height: 2.5rem; border-radius: 50%; cursor: pointer; font-size: 1.25rem; transition: all 0.3s;">
                    ×
                </button>
                <h3 style="margin: 0; color: white; font-size: 1.5rem; font-weight: 700; display: flex; align-items: center;">
                    <i class="fas fa-newspaper" style="margin-right: 0.75rem;"></i>
                    <span id="modal-campaign-name">Dettagli Campagna</span>
                </h3>
                <p style="margin: 0.5rem 0 0 0; color: rgba(255, 255, 255, 0.9); font-size: 0.9rem;">
                    ID Campagna: <span id="modal-campaign-id" style="font-weight: 600;">-</span>
                </p>
            </div>

            <!-- Modal Body -->
            <div style="padding: 2rem;">
                <!-- Status & Progress Section -->
                <div style="background: rgba(168, 85, 247, 0.1); border-left: 4px solid #a855f7; padding: 1.25rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                    <h4 style="margin: 0 0 1rem 0; color: #a855f7; font-size: 1.1rem; font-weight: 600;">
                        <i class="fas fa-chart-line" style="margin-right: 0.5rem;"></i>Stato Campagna
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem;">
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Stato</div>
                            <div id="modal-status" style="color: white; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Totale Destinatari</div>
                            <div id="modal-total-recipients" style="color: white; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Inviate</div>
                            <div id="modal-sent-count" style="color: #10b981; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Fallite</div>
                            <div id="modal-failed-count" style="color: #ef4444; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                    </div>
                </div>

                <!-- Timing & Performance Section -->
                <div style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6; padding: 1.25rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                    <h4 style="margin: 0 0 1rem 0; color: #3b82f6; font-size: 1.1rem; font-weight: 600;">
                        <i class="fas fa-clock" style="margin-right: 0.5rem;"></i>Timing & Performance
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Inizio Invio</div>
                            <div id="modal-started-at" style="color: white; font-weight: 600;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Fine Invio</div>
                            <div id="modal-completed-at" style="color: white; font-weight: 600;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Tempo Elaborazione</div>
                            <div id="modal-processing-time" style="color: #10b981; font-weight: 600;">-</div>
                        </div>
                    </div>
                </div>

                <!-- Campaign Content Section -->
                <div style="background: rgba(236, 72, 153, 0.1); border-left: 4px solid #ec4899; padding: 1.25rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                    <h4 style="margin: 0 0 1rem 0; color: #ec4899; font-size: 1.1rem; font-weight: 600;">
                        <i class="fas fa-envelope-open-text" style="margin-right: 0.5rem;"></i>Contenuto
                    </h4>
                    <div style="margin-bottom: 1rem;">
                        <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.5rem;">Oggetto</div>
                        <div id="modal-subject" style="color: white; font-weight: 600; padding: 0.75rem; background: rgba(255, 255, 255, 0.05); border-radius: 0.375rem;">-</div>
                    </div>
                    <div>
                        <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.5rem;">Testo Semplice</div>
                        <div id="modal-plain-text" style="color: rgba(255, 255, 255, 0.9); max-height: 200px; overflow-y: auto; padding: 0.75rem; background: rgba(0, 0, 0, 0.3); border-radius: 0.375rem; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; line-height: 1.6;">Caricamento...</div>
                    </div>
                </div>

                <!-- Metrics Section -->
                <div style="background: rgba(16, 185, 129, 0.1); border-left: 4px solid #10b981; padding: 1.25rem; border-radius: 0.5rem;">
                    <h4 style="margin: 0 0 1rem 0; color: #10b981; font-size: 1.1rem; font-weight: 600;">
                        <i class="fas fa-chart-bar" style="margin-right: 0.5rem;"></i>Metriche Engagement
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem;">
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Aperture Uniche</div>
                            <div id="modal-unique-opens" style="color: white; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Click Unici</div>
                            <div id="modal-unique-clicks" style="color: white; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Tasso Apertura</div>
                            <div id="modal-open-rate" style="color: #10b981; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                        <div>
                            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.85rem; margin-bottom: 0.25rem;">Tasso Click</div>
                            <div id="modal-click-rate" style="color: #3b82f6; font-weight: 600; font-size: 1.1rem;">-</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div style="padding: 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.1); text-align: right;">
                <button onclick="closeCampaignModal()" style="background: linear-gradient(90deg, #6b7280 0%, #4b5563 100%); color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 0.5rem; cursor: pointer; font-weight: 600; transition: all 0.3s;">
                    <i class="fas fa-times" style="margin-right: 0.5rem;"></i>Chiudi
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- ENTERPRISE GALAXY: Live Monitor Modal -->
<!-- Professional real-time output display for worker operations -->
<!-- ====================================================================== -->
<div id="live-monitor-modal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.95); z-index: 99999; align-items: center; justify-content: center;">
    <div class="monitor-container" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 1rem; max-width: 900px; width: 90%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px rgba(59, 130, 246, 0.5); border: 2px solid rgba(59, 130, 246, 0.3);">

        <!-- Header -->
        <div class="monitor-header" style="padding: 1.5rem; border-bottom: 2px solid rgba(59, 130, 246, 0.3); display: flex; justify-content: space-between; align-items: center;">
            <h3 id="monitor-title" style="margin: 0; color: white; font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-terminal" style="color: #3b82f6;"></i>
                <span>Monitor Worker</span>
            </h3>
            <button onclick="closeLiveMonitor()" style="background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444; width: 40px; height: 40px; border-radius: 0.5rem; font-size: 1.25rem; font-weight: 700; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(239, 68, 68, 0.3)'" onmouseout="this.style.background='rgba(239, 68, 68, 0.2)'">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Output Area -->
        <div id="live-monitor-output" class="monitor-output" style="flex: 1; overflow-y: auto; padding: 1.5rem; font-family: 'Courier New', monospace; font-size: 0.875rem; line-height: 1.6; background: #0a0a0f; color: #e0e0e0;">
            <div style="color: rgba(255, 255, 255, 0.5); text-align: center; padding: 2rem;">
                <i class="fas fa-rocket" style="font-size: 3rem; margin-bottom: 1rem; display: block;"></i>
                Pronto per monitorare le operazioni dei worker...
            </div>
        </div>

        <!-- Footer -->
        <div class="monitor-footer" style="padding: 1rem 1.5rem; border-top: 2px solid rgba(59, 130, 246, 0.3); display: flex; justify-content: space-between; align-items: center; background: rgba(0, 0, 0, 0.3);">
            <div style="color: rgba(255, 255, 255, 0.6); font-size: 0.875rem;">
                <i class="fas fa-info-circle"></i>
                <span id="monitor-status">Inattivo</span>
            </div>
            <button onclick="closeLiveMonitor()" style="background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; padding: 0.5rem 1.5rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                Chiudi
            </button>
        </div>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
/* Inherit from email-metrics.php */
.tabs-container {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    padding: 1.5rem;
}

.tabs-header {
    display: flex;
    gap: 0.5rem;
    border-bottom: 2px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.6);
    cursor: pointer;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
    font-weight: 500;
}

.tab-btn:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.05);
}

.tab-btn.active {
    color: #a855f7;
    border-bottom-color: #a855f7;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Form Styles */
.form-section {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    padding: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 500;
}

.form-input, .form-select {
    width: 100%;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    color: white;
    font-size: 0.95rem;
}

.form-input:focus, .form-select:focus {
    outline: none;
    border-color: #a855f7;
    background: rgba(255, 255, 255, 0.08);
}

.text-muted {
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.85rem;
}

/* Worker Control */
.worker-status-card {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    padding: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.9rem;
}

.status-unknown {
    background: rgba(156, 163, 175, 0.2);
    color: #9ca3af;
}

.status-running {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.status-stopped {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.bg-dark-light {
    background: rgba(255, 255, 255, 0.05);
}

.worker-controls button {
    width: 100%;
}

.stat-card.mini {
    padding: 1rem;
}

.stat-card.mini .stat-value {
    font-size: 1.5rem;
}

.stat-card.mini .stat-label {
    font-size: 0.8rem;
}

/* Log Output */
.log-container {
    background: #1a1a1a;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    max-height: 500px;
    overflow-y: auto;
}

.log-output {
    color: #10b981;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    line-height: 1.6;
    padding: 1rem;
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid;
}

.alert-info {
    background: rgba(59, 130, 246, 0.1);
    border-color: #3b82f6;
    color: #93c5fd;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border-color: #10b981;
    color: #6ee7b7;
}

/* Table wrapper from email-metrics */
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
</style>

<!-- ENTERPRISE GALAXY: Global Admin Base URL (MUST be defined BEFORE all other scripts) -->
<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE: Extract admin base URL for dynamic admin URLs (e.g., /admin_17d71a0b9af1c67a)
// CRITICAL: GLOBAL scope so all fetch() calls can access it
const adminBase = window.location.pathname.match(/\/admin_[a-f0-9]{16}/)?.[0] || '';
</script>

<!-- ENTERPRISE GALAXY: Fix TinyMCE Non-Passive Touch Event Listeners -->
<!-- CRITICAL: Must execute BEFORE TinyMCE loads to intercept addEventListener calls -->
<script nonce="<?= csp_nonce() ?>">
(function() {
    'use strict';

    // ENTERPRISE PERFORMANCE FIX: Monkey-patch addEventListener to force passive: true on touch events
    // WHY: TinyMCE adds non-passive touchstart/touchmove listeners that block scrolling on mobile
    // HOW: Intercept addEventListener calls and automatically add passive: true for touch events
    // WHEN: Before TinyMCE loads (this script runs first)

    const originalAddEventListener = EventTarget.prototype.addEventListener;

    EventTarget.prototype.addEventListener = function(type, listener, options) {
        // Target touch events that block scroll performance
        const isTouchEvent = (type === 'touchstart' || type === 'touchmove');

        if (isTouchEvent) {
            // Convert options to object if it's a boolean (old syntax: capture flag)
            let optionsObj;

            if (typeof options === 'boolean') {
                // Old syntax: addEventListener(type, listener, useCapture)
                optionsObj = { capture: options, passive: true };
            } else if (typeof options === 'object' && options !== null) {
                // Modern syntax: addEventListener(type, listener, {options})
                // Only override if passive is not explicitly set (respect developer intent)
                optionsObj = options.passive === undefined
                    ? { ...options, passive: true }
                    : options;
            } else {
                // No options provided: default to passive
                optionsObj = { passive: true };
            }

            return originalAddEventListener.call(this, type, listener, optionsObj);
        }

        // Non-touch events: use original behavior (no modification)
        return originalAddEventListener.call(this, type, listener, options);
    };

    // ENTERPRISE TIPS: Log patch application in debug mode
    console.debug('[ENTERPRISE] addEventListener patched for passive touch events (TinyMCE performance fix)');
})();
</script>

<!-- Load TinyMCE (Local Installation - Full version for admin readability, modified for passive touch events) -->
<script src="/assets/js/tinymce/tinymce.js"></script>

<!-- ENTERPRISE GALAXY: Admin Session Guard loaded in layout.php (avoid duplicate) -->

<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE: Get CSRF token from meta tag
// IMPORTANT: Define this BEFORE TinyMCE init so it's available for image upload handler
function getCSRFToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    const token = meta ? meta.getAttribute('content') : '';

    // ENTERPRISE LOGGING: Errors sent to server via EnterpriseErrorMonitor
    if (!token) {
        console.error('[CSRF] Token is EMPTY! Meta tag not found or content is empty');
    }
    // Success case: silent (no logging spam in production)

    return token;
}

// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;

        // Update button states
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update content visibility
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(`tab-${tabId}`).classList.add('active');

        // Load tab-specific data
        if (tabId === 'worker-control') {
            refreshNewsletterWorkerStatus();
        } else if (tabId === 'campaigns') {
            refreshCampaigns();
        }
    });
});

// Initialize TinyMCE
tinymce.init({
    selector: '#html-body',
    license_key: 'gpl', // GPL/LGPL license for open source use
    height: 500,
    menubar: true,
    plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
        'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
        'insertdatetime', 'media', 'table', 'help', 'wordcount'
    ],
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
             'alignleft aligncenter alignright alignjustify | ' +
             'bullist numlist outdent indent | ' +
             'forecolor backcolor | link image | removeformat | code | help',
    content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px; }',
    skin: 'oxide-dark',
    content_css: 'dark',

    // ENTERPRISE GALAXY: Force absolute URLs for email compatibility
    // CRITICAL: Email clients require absolute URLs (https://domain.com/path)
    // TinyMCE by default converts to relative URLs - we MUST prevent this!
    relative_urls: false,              // Don't convert to relative paths (../assets/...)
    remove_script_host: false,         // Keep protocol and domain (https://need2talk.it)
    document_base_url: 'https://need2talk.it/',  // Base URL for conversions
    convert_urls: true,                // Still process URLs, but maintain absolute format

    // ENTERPRISE GALAXY: Image upload handler for newsletter images
    // CRITICAL: Must RETURN a Promise (not use callbacks) - TinyMCE expects Promise pattern
    images_upload_handler: function (blobInfo, progress) {
        // ENTERPRISE LOGGING: Trace upload flow (auto-sent to js_errors channel via EnterpriseErrorMonitor)
        console.debug('[TinyMCE Upload] Starting image upload', {
            filename: blobInfo.filename(),
            size: blobInfo.blob().size,
            type: blobInfo.blob().type
        });

        // RETURN Promise - TinyMCE will call .then() on this
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('image', blobInfo.blob(), blobInfo.filename());
            formData.append('_csrf_token', getCSRFToken());

            fetch(`${adminBase}/api/newsletter/upload-image`, {
                method: 'POST',
                body: formData
            })
            .then(res => {
                console.debug('[TinyMCE Upload] Response received', {
                    status: res.status,
                    statusText: res.statusText,
                    contentType: res.headers.get('content-type')
                });
                return res.json();
            })
            .then(data => {
                console.debug('[TinyMCE Upload] JSON parsed', {
                    success: data.success,
                    image_url: data.image_url,
                    message: data.message,
                    full_response: data
                });

                if (data.success) {
                    console.debug('[TinyMCE Upload] Resolving Promise with URL:', data.image_url);
                    resolve(data.image_url); // RESOLVE Promise with URL
                } else {
                    console.error('[TinyMCE Upload] Upload failed:', data.message);
                    reject(data.message || 'Upload failed'); // REJECT Promise with error
                }
            })
            .catch(err => {
                console.error('[TinyMCE Upload] Error during upload:', err);
                reject('Network error during upload'); // REJECT Promise with error
            });
        });
    },

    // Allow automatic uploads when pasting images
    automatic_uploads: true,

    // Allow drag-and-drop of images
    paste_data_images: true,
});

// Target all checkbox toggle
document.getElementById('target-all').addEventListener('change', (e) => {
    document.getElementById('filter-options').style.display = e.target.checked ? 'none' : 'block';
});

// Campaign Functions
function refreshCampaigns() {
    document.getElementById('campaigns-loading').style.display = 'block';
    document.getElementById('campaigns-container').style.display = 'none';

    fetch(`${adminBase}/api/newsletter/stats?campaign_id=all`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // ENTERPRISE TIPS: API returns {campaigns: {campaigns: [...], total: 10}}
                renderCampaignsTable(data.campaigns?.campaigns || []);
                document.getElementById('campaigns-loading').style.display = 'none';
                document.getElementById('campaigns-container').style.display = 'block';
            }
        })
        .catch(err => console.error('Failed to load campaigns:', err));
}

function renderCampaignsTable(campaigns) {
    const tbody = document.getElementById('campaigns-tbody');

    if (!campaigns || campaigns.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">Nessuna campagna trovata. Crea la tua prima campagna!</td></tr>';
        return;
    }

    tbody.innerHTML = campaigns.map(c => `
        <tr>
            <td>${c.id}</td>
            <td>${escapeHtml(c.campaign_name)}</td>
            <td>${escapeHtml(c.subject)}</td>
            <td><span class="badge badge-${getStatusColor(c.status)}">${c.status}</span></td>
            <td>${c.total_recipients || 0}</td>
            <td>${c.sent_count || 0}</td>
            <td>${c.unique_opens || 0}</td>
            <td>${c.unique_clicks || 0}</td>
            <td>${parseFloat(c.avg_open_rate || 0).toFixed(1)}%</td>
            <td>${parseFloat(c.avg_click_rate || 0).toFixed(1)}%</td>
            <td>${formatDateTime(c.created_at)}</td>
            <td>
                <button onclick="viewCampaign(${c.id})" class="btn btn-sm btn-info">
                    <i class="fas fa-eye"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

function saveDraft() {
    const formData = getFormData();
    formData.append('status', 'draft');

    createCampaign(formData, 'Bozza salvata con successo!');
}

function sendNow() {
    if (!confirm('⚠️ Inviare la newsletter immediatamente a tutti gli utenti selezionati?')) return;

    const formData = getFormData();

    // Create campaign first
    createCampaign(formData, null, (campaignId) => {
        // Then send it
        const sendData = new FormData();
        sendData.append('campaign_id', campaignId);
        sendData.append('_csrf_token', getCSRFToken());

        fetch(`${adminBase}/api/newsletter/send`, {
            method: 'POST',
            body: sendData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification(`Newsletter in coda! ${data.queued} email inviate alla coda.`, 'success');
                switchToTab('campaigns');
                refreshCampaigns();
            } else {
                showNotification(data.message || 'Invio newsletter fallito', 'error');
            }
        })
        .catch(err => {
            console.error('Send error:', err);
            showNotification('Errore di rete durante l\'invio della newsletter', 'error');
        });
    });
}

function scheduleFor() {
    const datetime = prompt('⏰ Programma newsletter per quando?\n\nFormato: YYYY-MM-DD HH:MM\nEsempio: 2025-01-20 09:00');
    if (!datetime) return;

    const formData = getFormData();

    createCampaign(formData, null, (campaignId) => {
        const sendData = new FormData();
        sendData.append('campaign_id', campaignId);
        sendData.append('scheduled_for', datetime);
        sendData.append('_csrf_token', getCSRFToken());

        fetch(`${adminBase}/api/newsletter/send`, {
            method: 'POST',
            body: sendData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification(`Newsletter programmata per ${data.scheduled_for}`, 'success');
                switchToTab('campaigns');
                refreshCampaigns();
            } else {
                showNotification(data.message || 'Programmazione newsletter fallita', 'error');
            }
        });
    });
}

function getFormData() {
    const formData = new FormData();
    formData.append('campaign_name', document.getElementById('campaign-name').value);
    formData.append('subject', document.getElementById('subject').value);
    formData.append('preview_text', document.getElementById('preview-text').value);
    formData.append('html_body', tinymce.get('html-body').getContent());
    formData.append('priority', document.getElementById('priority').value);
    formData.append('target_all_users', document.getElementById('target-all').checked ? 1 : 0);

    return formData;
}

function createCampaign(formData, successMessage = null, callback = null) {
    formData.append('_csrf_token', getCSRFToken());

    fetch(`${adminBase}/api/newsletter/create`, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (successMessage) {
                showNotification(successMessage, 'success');
            }
            if (callback) {
                callback(data.campaign_id);
            }
        } else {
            showNotification(data.message || 'Creazione campagna fallita', 'error');
        }
    })
    .catch(err => {
        console.error('Create campaign error:', err);
        showNotification('Errore di rete durante la creazione della campagna', 'error');
    });
}

// Worker Control Functions
function refreshWorkerStatus() {
    fetch(`${adminBase}/api/worker/status`)
        .then(res => {
            // ENTERPRISE TIPS: Check Content-Type before parsing JSON
            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.error('Worker status API returned non-JSON response:', contentType);
                throw new Error('Invalid response type: ' + (contentType || 'unknown'));
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                updateWorkerUI(data);
            }
            // Failure case: silent (no diagnostic logging spam)
        })
        .catch(err => console.error('Failed to get worker status:', err));
}

function updateWorkerUI(data) {
    // Note: This function is now deprecated - the old worker UI has been removed
    // Keeping function for backward compatibility but it does nothing
    console.log('updateWorkerUI called (deprecated - old UI removed)');
}

// ENTERPRISE GALAXY: Newsletter Worker Container Control Functions
function startNewsletterWorkers() {
    if (!confirm('Avviare il container worker newsletter?')) return;

    openLiveMonitor('Avvia Worker Newsletter');
    appendToMonitor('Preparazione avvio container worker newsletter...', 'info');

    const formData = new FormData();
    formData.append('_csrf_token', getCSRFToken());

    appendToMonitor('Invio comando avvio al container Docker...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/start`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                appendToMonitor('✓ Container newsletter avviato con successo', 'success');
                appendToMonitor('', 'info');
                appendToMonitor('Il container è ora in esecuzione. I worker inizieranno a processare la coda.', 'info');
                appendToMonitor('Aggiornamento stato tra 2 secondi...', 'info');
                // Delay refresh to allow container to write logs
                setTimeout(() => {
                    refreshNewsletterWorkerStatus();
                    appendToMonitor('✓ Stato aggiornato', 'success');
                }, 2000);
            } else {
                appendToMonitor(`✗ Avvio container fallito: ${data.message || 'Errore sconosciuto'}`, 'error');
            }
        })
        .catch(err => {
            console.error('Start container error:', err);
            appendToMonitor(`✗ Errore di rete: ${err.message}`, 'error');
        });
}

function stopNewsletterWorkers() {
    if (!confirm('⚠️ Fermare il container worker newsletter?')) return;

    openLiveMonitor('Ferma Worker Newsletter');
    appendToMonitor('Preparazione arresto container worker newsletter...', 'warning');

    const formData = new FormData();
    formData.append('_csrf_token', getCSRFToken());

    appendToMonitor('Sending stop command to Docker container...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/stop`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                appendToMonitor('✓ Newsletter container stopped successfully', 'success');
                appendToMonitor('', 'info');
                appendToMonitor('Container is now stopped. No workers are processing queue.', 'warning');
                appendToMonitor('Refreshing status in 2 seconds...', 'info');
                // Delay refresh to allow container to write logs
                setTimeout(() => {
                    refreshNewsletterWorkerStatus();
                    appendToMonitor('✓ Status refreshed', 'success');
                }, 2000);
            } else {
                appendToMonitor(`✗ Failed to stop container: ${data.message || 'Unknown error'}`, 'error');
            }
        })
        .catch(err => {
            console.error('Stop container error:', err);
            appendToMonitor(`✗ Network error: ${err.message}`, 'error');
        });
}

function restartNewsletterWorkers() {
    if (!confirm('Restart newsletter worker container?')) return;

    openLiveMonitor('Restart Newsletter Workers');
    appendToMonitor('Preparing to restart newsletter worker container...', 'info');

    const formData = new FormData();
    formData.append('_csrf_token', getCSRFToken());

    appendToMonitor('Sending restart command to Docker container...', 'info');
    appendToMonitor('This will stop and start the container in sequence...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/restart`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                appendToMonitor('✓ Newsletter container restarted successfully', 'success');
                appendToMonitor('', 'info');
                appendToMonitor('Container has been restarted. Workers are reinitializing...', 'info');
                appendToMonitor('Refreshing status in 2 seconds...', 'info');
                // Delay refresh to allow container to write logs
                setTimeout(() => {
                    refreshNewsletterWorkerStatus();
                    appendToMonitor('✓ Status refreshed', 'success');
                }, 2000);
            } else {
                appendToMonitor(`✗ Failed to restart container: ${data.message || 'Unknown error'}`, 'error');
            }
        })
        .catch(err => {
            console.error('Restart container error:', err);
            appendToMonitor(`✗ Network error: ${err.message}`, 'error');
        });
}

function stopAndCleanNewsletterWorkers() {
    if (!confirm('⚠️ Stop newsletter worker container AND clean all logs?\n\nThis will:\n- Stop the container\n- Delete all newsletter log files\n\nThis action cannot be undone!')) return;

    openLiveMonitor('Stop & Clean Newsletter Workers');
    appendToMonitor('⚠️ DESTRUCTIVE OPERATION - Stopping container and cleaning logs...', 'warning');

    const formData = new FormData();
    formData.append('_csrf_token', getCSRFToken());

    appendToMonitor('Step 1: Stopping Docker container...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/stop-clean`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                appendToMonitor('✓ Container stopped', 'success');
                appendToMonitor('Step 2: Deleting all newsletter log files...', 'info');
                appendToMonitor('✓ Logs cleaned successfully', 'success');
                appendToMonitor('', 'info');
                appendToMonitor('⚠️ All newsletter logs have been permanently deleted', 'warning');
                appendToMonitor('Container is now stopped with clean slate', 'info');
                appendToMonitor('Refreshing status in 2 seconds...', 'info');
                // Delay refresh to allow container to write logs
                setTimeout(() => {
                    refreshNewsletterWorkerStatus();
                    appendToMonitor('✓ Status refreshed', 'success');
                }, 2000);
            } else {
                appendToMonitor(`✗ Failed to stop and clean: ${data.message || 'Unknown error'}`, 'error');
            }
        })
        .catch(err => {
            console.error('Stop and clean error:', err);
            appendToMonitor(`✗ Network error: ${err.message}`, 'error');
        });
}

function getNewsletterWorkerHealth() {
    openLiveMonitor('Health Check');
    appendToMonitor('Checking newsletter worker health...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/health`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.health) {
                appendToMonitor('✓ Health check completed successfully', 'success');
                appendToMonitor('', 'info'); // Blank line

                // Display health data in formatted way
                const health = data.health;
                appendToMonitor('=== CONTAINER STATUS ===', 'info');
                appendToMonitor(`Container Status: ${health.container_status || 'unknown'}`, 'info');
                appendToMonitor(`Health Check: ${health.container_health || 'unknown'}`, 'info');
                appendToMonitor(`Uptime: ${health.uptime || '-'}`, 'info');

                appendToMonitor('', 'info'); // Blank line
                appendToMonitor('=== PERFORMANCE METRICS ===', 'info');
                appendToMonitor(`Memory Usage: ${health.memory_usage || '-'}`, 'info');
                appendToMonitor(`CPU Usage: ${health.cpu_usage || '-'}`, 'info');

                appendToMonitor('', 'info'); // Blank line
                appendToMonitor('=== WORKER STATUS ===', 'info');
                appendToMonitor(`Workers Running: ${health.workers_running || 0}`, 'info');
                appendToMonitor(`Worker Processes: ${health.worker_processes || 0}`, 'info');
                appendToMonitor(`Auto-restart: ${health.autostart_enabled ? 'ENABLED' : 'DISABLED'}`, health.autostart_enabled ? 'success' : 'warning');

                appendToMonitor('', 'info'); // Blank line
                appendToMonitor('=== QUEUE INFO ===', 'info');
                appendToMonitor(`Queue Size: ${health.queue_size || 0} emails`, 'info');
                appendToMonitor(`Queue Directory: ${health.queue_directory || 'unknown'}`, 'info');

                appendToMonitor('', 'info'); // Blank line
                appendToMonitor(`Timestamp: ${data.timestamp || '-'}`, 'info');
            } else {
                appendToMonitor(`✗ Health check failed: ${data.message || 'Unknown error'}`, 'error');
            }
        })
        .catch(error => {
            appendToMonitor(`✗ Health check failed: ${error.message}`, 'error');
        });
}

function enableNewsletterAutostart() {
    openLiveMonitor('Enable Auto-Restart');
    appendToMonitor('Enabling auto-restart for newsletter workers...', 'info');

    const formData = new FormData();
    formData.append('_csrf_token', getCSRFToken());

    appendToMonitor('Configuring Docker restart policy...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/enable-autostart`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                appendToMonitor('✓ Auto-restart enabled successfully', 'success');
                appendToMonitor('', 'info');
                appendToMonitor('Container will now automatically restart on failure', 'success');
                appendToMonitor('Self-healing architecture active', 'success');
                appendToMonitor('Refreshing status...', 'info');
                // Immediate refresh for autostart toggle (no delay needed)
                setTimeout(() => {
                    refreshNewsletterWorkerStatus();
                    appendToMonitor('✓ Status refreshed', 'success');
                }, 500);
            } else {
                appendToMonitor(`✗ Failed to enable auto-restart: ${data.message || 'Unknown error'}`, 'error');
            }
        })
        .catch(err => {
            console.error('Enable autostart error:', err);
            appendToMonitor(`✗ Network error: ${err.message}`, 'error');
        });
}

function disableNewsletterAutostart() {
    if (!confirm('⚠️ Disable auto-restart for newsletter workers? Container will NOT auto-recover if it fails.')) return;

    openLiveMonitor('Disable Auto-Restart');
    appendToMonitor('⚠️ Disabling auto-restart for newsletter workers...', 'warning');

    const formData = new FormData();
    formData.append('_csrf_token', getCSRFToken());

    appendToMonitor('Removing Docker restart policy...', 'info');

    fetch(`${adminBase}/api/newsletter-worker/disable-autostart`, {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                appendToMonitor('✓ Auto-restart disabled successfully', 'success');
                appendToMonitor('', 'info');
                appendToMonitor('⚠️ WARNING: Container will NOT auto-recover on failure', 'warning');
                appendToMonitor('Self-healing architecture is now DISABLED', 'warning');
                appendToMonitor('Manual intervention will be required if container fails', 'warning');
                appendToMonitor('Refreshing status...', 'info');
                // Immediate refresh for autostart toggle (no delay needed)
                setTimeout(() => {
                    refreshNewsletterWorkerStatus();
                    appendToMonitor('✓ Status refreshed', 'success');
                }, 500);
            } else {
                appendToMonitor(`✗ Failed to disable auto-restart: ${data.message || 'Unknown error'}`, 'error');
            }
        })
        .catch(err => {
            console.error('Disable autostart error:', err);
            appendToMonitor(`✗ Network error: ${err.message}`, 'error');
        });
}

function refreshNewsletterWorkerStatus() {
    fetch(`${adminBase}/api/newsletter-worker/status`)
        .then(res => res.json())
        .then(data => {
            // Update status indicator
            const statusIndicator = document.getElementById('newsletter-worker-status-indicator');
            const statusText = document.getElementById('newsletter-worker-status-text');

            if (data.active) {
                statusIndicator.style.color = '#10b981';
                statusIndicator.textContent = '●';
                statusText.textContent = 'Attivo';
                statusText.style.color = '#10b981';
            } else {
                statusIndicator.style.color = '#ef4444';
                statusIndicator.textContent = '●';
                statusText.textContent = 'Fermato';
                statusText.style.color = '#ef4444';
            }

            // Update stats
            document.getElementById('newsletter-worker-count').textContent = data.workers || 0;
            document.getElementById('newsletter-worker-uptime').textContent = data.uptime || '-';
            document.getElementById('newsletter-worker-memory').textContent = data.memory || '-';
            document.getElementById('newsletter-worker-cpu').textContent = data.cpu || '-';

            // Update auto-restart status (data.enabled or data.autostart_enabled)
            const autostartEnabled = data.enabled !== undefined ? data.enabled : data.autostart_enabled;
            const autostartStatus = document.getElementById('newsletter-autostart-status');
            const autostartCard = document.getElementById('newsletter-autostart-card');
            if (autostartEnabled) {
                autostartStatus.textContent = 'ON';
                autostartStatus.style.color = '#10b981';
                autostartCard.style.borderLeftColor = '#10b981';
            } else {
                autostartStatus.textContent = 'OFF';
                autostartStatus.style.color = '#ef4444';
                autostartCard.style.borderLeftColor = '#ef4444';
            }

            // Update logs
            const logsContainer = document.getElementById('newsletter-worker-logs');
            if (data.recent_logs && data.recent_logs.length > 0) {
                logsContainer.innerHTML = data.recent_logs.map(log => `<div>${escapeHtml(log)}</div>`).join('');
            } else {
                logsContainer.innerHTML = '<div class="text-center" style="color: #6b7280;">Nessun log disponibile</div>';
            }
        })
        .catch(err => {
            console.error('Failed to refresh newsletter worker status:', err);
        });
}

// Helper Functions
function getStatusColor(status) {
    const statusMap = {
        'draft': 'secondary',
        'scheduled': 'warning',
        'sending': 'info',
        'sent': 'success',
        'paused': 'warning',
        'cancelled': 'danger',
        'failed': 'danger',
        'queued': 'warning',
        'processing': 'info'
    };
    return statusMap[status] || 'secondary';
}

function formatDateTime(datetime) {
    return new Date(datetime).toLocaleString('it-IT', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function switchToTab(tabName) {
    document.querySelector(`.tab-btn[data-tab="${tabName}"]`)?.click();
}

function showNotification(message, type = 'info') {
    // Use existing notification system or alert
    alert(message);
}

// ENTERPRISE GALAXY: View Campaign Details Modal
function viewCampaign(id) {
    // Show modal with loading state
    const modal = document.getElementById('campaign-modal');
    modal.style.display = 'block';
    document.getElementById('modal-campaign-id').textContent = id;
    document.getElementById('modal-campaign-name').textContent = 'Loading...';

    // Fetch campaign details
    fetch(`api/newsletter/stats?campaign_id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.campaign) {
                const campaign = data.campaign;

                // Header
                document.getElementById('modal-campaign-name').textContent = escapeHtml(campaign.campaign_name);

                // Status & Progress
                const statusBadge = `<span class="badge badge-${getStatusColor(campaign.status)}">${campaign.status.toUpperCase()}</span>`;
                document.getElementById('modal-status').innerHTML = statusBadge;
                document.getElementById('modal-total-recipients').textContent = campaign.total_recipients || 0;
                document.getElementById('modal-sent-count').textContent = campaign.sent_count || 0;
                document.getElementById('modal-failed-count').textContent = campaign.failed_count || 0;

                // Timing & Performance
                document.getElementById('modal-started-at').textContent =
                    campaign.started_sending_at ? formatDateTime(campaign.started_sending_at) : 'Non iniziato';
                document.getElementById('modal-completed-at').textContent =
                    campaign.completed_sending_at ? formatDateTime(campaign.completed_sending_at) : 'In corso...';

                // Processing time formatting
                const procTime = campaign.processing_time_ms;
                if (procTime) {
                    const seconds = Math.round(procTime / 1000);
                    const minutes = Math.floor(seconds / 60);
                    const secs = seconds % 60;
                    document.getElementById('modal-processing-time').textContent =
                        minutes > 0 ? `${minutes}m ${secs}s` : `${secs}s`;
                } else {
                    document.getElementById('modal-processing-time').textContent = '-';
                }

                // Content
                document.getElementById('modal-subject').textContent = escapeHtml(campaign.subject);
                document.getElementById('modal-plain-text').textContent =
                    campaign.plain_text_body || 'Testo semplice non disponibile';

                // Metrics (from data.metrics for single campaign API)
                const metrics = data.metrics || {};
                document.getElementById('modal-unique-opens').textContent = metrics.opened_count || 0;
                document.getElementById('modal-unique-clicks').textContent = metrics.clicked_count || 0;

                // Calculate rates
                const totalRecipients = campaign.total_recipients || 1;
                const openRate = ((metrics.opened_count || 0) / totalRecipients * 100).toFixed(1);
                const clickRate = ((metrics.clicked_count || 0) / totalRecipients * 100).toFixed(1);

                document.getElementById('modal-open-rate').textContent = openRate + '%';
                document.getElementById('modal-click-rate').textContent = clickRate + '%';
            } else {
                showNotification('Caricamento dettagli campagna fallito', 'error');
                closeCampaignModal();
            }
        })
        .catch(err => {
            console.error('Failed to load campaign:', err);
            showNotification('Errore di rete durante il caricamento dei dettagli campagna', 'error');
            closeCampaignModal();
        });
}

function closeCampaignModal() {
    document.getElementById('campaign-modal').style.display = 'none';
}

/**
 * ====================================================================
 * ENTERPRISE GALAXY: Live Monitor Functions
 * Professional real-time output display system
 * ====================================================================
 */

/**
 * Opens the live monitor modal with a specific title
 * @param {string} title - The title to display in the monitor header
 */
function openLiveMonitor(title) {
    const modal = document.getElementById('live-monitor-modal');
    const output = document.getElementById('live-monitor-output');
    const titleEl = document.getElementById('monitor-title').querySelector('span');
    const statusEl = document.getElementById('monitor-status');

    // Clear previous output
    output.innerHTML = '';

    // Set title
    titleEl.textContent = title;
    statusEl.textContent = 'Avvio...';

    // Show modal
    modal.style.display = 'flex';
}

/**
 * Appends a message to the live monitor with color coding
 * @param {string} message - The message to display
 * @param {string} type - Message type: 'info', 'success', 'warning', 'error'
 */
function appendToMonitor(message, type = 'info') {
    const output = document.getElementById('live-monitor-output');
    const statusEl = document.getElementById('monitor-status');
    const timestamp = new Date().toLocaleTimeString('it-IT', { hour12: false });

    const line = document.createElement('div');
    line.style.marginBottom = '0.5rem';
    line.style.padding = '0.5rem';
    line.style.borderRadius = '0.25rem';
    line.style.borderLeft = '3px solid';

    // ENTERPRISE GALAXY: Color coding based on type
    const colors = {
        'info': { bg: 'rgba(59, 130, 246, 0.1)', border: '#3b82f6', text: '#93c5fd' },
        'success': { bg: 'rgba(16, 185, 129, 0.1)', border: '#10b981', text: '#6ee7b7' },
        'warning': { bg: 'rgba(245, 158, 11, 0.1)', border: '#f59e0b', text: '#fbbf24' },
        'error': { bg: 'rgba(239, 68, 68, 0.1)', border: '#ef4444', text: '#fca5a5' }
    };

    const color = colors[type] || colors.info;
    line.style.background = color.bg;
    line.style.borderColor = color.border;
    line.style.color = color.text;

    line.innerHTML = `<span style="color: rgba(255, 255, 255, 0.5);">[${timestamp}]</span> ${escapeHtml(message)}`;

    output.appendChild(line);
    output.scrollTop = output.scrollHeight; // Auto-scroll to bottom

    statusEl.textContent = 'Attivo';
}

/**
 * Closes the live monitor modal
 */
function closeLiveMonitor() {
    const modal = document.getElementById('live-monitor-modal');
    const statusEl = document.getElementById('monitor-status');
    modal.style.display = 'none';
    statusEl.textContent = 'Inattivo';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    refreshCampaigns();
    refreshWorkerStatus();

    // ENTERPRISE GALAXY: Always refresh newsletter worker status on page load
    refreshNewsletterWorkerStatus();
});
</script>
