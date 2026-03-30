<!-- MODULAR SETTINGS VIEW -->
<div class="max-w-4xl">
    <h2 class="enterprise-title mb-8 flex items-center">
        <i class="fas fa-cog mr-3"></i>
        Configurazione Sistema
    </h2>

    <?php if (isset($_GET['updated'])) { ?>
        <div class="alert alert-success">
            ✅ Impostazioni aggiornate con successo!
        </div>
    <?php } ?>

    <?php if (isset($_GET['error'])) { ?>
        <?php if ($_GET['error'] === 'env_disabled') { ?>
            <div class="alert alert-danger">
                ⛔ <strong>Impossibile modificare le impostazioni Debugbar:</strong> ENABLE_DEBUGBAR è disabilitato nel file .env.<br>
                <span class="text-sm">Per abilitare Debugbar, prima imposta <code class="bg-gray-800 px-2 py-1 rounded">ENABLE_DEBUGBAR=true</code> in <code>/var/www/need2talk/.env</code> via SSH.</span>
            </div>
        <?php } else { ?>
            <div class="alert alert-danger">
                ❌ Errore nell'aggiornamento delle impostazioni. Riprova.
            </div>
        <?php } ?>
    <?php } ?>

    <!-- Debugbar Settings -->
    <form method="POST" action="<?= $_SERVER['REQUEST_URI'] ?>" class="debugbar-form">
        <div class="card">
            <!-- ENTERPRISE MASTER SWITCH WARNING -->
            <?php if (!($debugbar_settings['env_debugbar_enabled'] ?? true)) { ?>
                <div class="bg-gradient-to-r from-red-900/40 to-orange-900/40 border-2 border-red-500/50 rounded-lg p-5 mb-6 shadow-lg">
                    <div class="flex items-start gap-4">
                        <div class="text-4xl">⛔</div>
                        <div class="flex-1">
                            <h5 class="text-xl font-bold text-red-400 mb-2 flex items-center">
                                <i class="fas fa-lock mr-2"></i>
                                Debugbar Disabilitato via ENV (Interruttore Principale)
                            </h5>
                            <p class="text-gray-300 mb-3 text-sm leading-relaxed">
                                Il debugbar è <strong class="text-white">disabilitato a livello ambiente</strong> tramite <code class="bg-gray-800 px-2 py-1 rounded text-xs">ENABLE_DEBUGBAR=false</code> nel file <code class="bg-gray-800 px-2 py-1 rounded text-xs">.env</code>.
                            </p>
                            <div class="bg-gray-900/60 rounded-lg p-4 border border-gray-700/50">
                                <p class="text-yellow-300 font-semibold mb-2 text-sm">
                                    <i class="fas fa-terminal mr-2"></i>Per abilitare i controlli Debugbar:
                                </p>
                                <ol class="text-gray-300 text-sm space-y-1 ml-4 list-decimal">
                                    <li>Collegati via SSH al server di produzione</li>
                                    <li>Modifica il file .env nella directory principale del progetto</li>
                                    <li>Imposta <code class="bg-gray-800 px-2 py-1 rounded text-xs">ENABLE_DEBUGBAR=true</code></li>
                                    <li>Cancella la cache env: <code class="bg-gray-800 px-2 py-1 rounded text-xs">rm storage/cache/env.php</code> (dalla root del progetto)</li>
                                    <li>Riavvia il container PHP-FPM: <code class="bg-gray-800 px-2 py-1 rounded text-xs">docker compose restart php</code></li>
                                </ol>
                            </div>
                            <p class="text-gray-400 text-xs mt-3 italic">
                                <i class="fas fa-info-circle mr-1"></i>
                                Questo design garantisce la sicurezza in produzione: il file ENV è la fonte autorevole, il pannello admin non può sovrascriverlo.
                            </p>
                        </div>
                    </div>
                </div>
            <?php } ?>

            <div class="flex justify-between items-center mb-4">
                <h4 class="text-xl font-semibold text-blue-400 flex items-center">
                    <i class="fas fa-bug mr-2"></i>
                    Laravel Debugbar
                </h4>
                <div class="toggle-container">
                    <div class="toggle <?= ($debugbar_settings['debugbar_enabled'] ?? 0) == 1 ? 'active' : '' ?> <?= !($debugbar_settings['env_debugbar_enabled'] ?? true) ? 'opacity-50 pointer-events-none' : '' ?>" onclick="toggleDebugbar(this)">
                        <div class="toggle-slider"></div>
                        <input type="checkbox" id="debugbar_enabled" name="debugbar_enabled" value="1" <?= ($debugbar_settings['debugbar_enabled'] ?? 0) == 1 ? 'checked' : '' ?> <?= !($debugbar_settings['env_debugbar_enabled'] ?? true) ? 'disabled' : '' ?> style="display: none;">
                        <input type="hidden" id="debugbar_enabled_value" name="debugbar_enabled_hidden" value="<?= ($debugbar_settings['debugbar_enabled'] ?? 0) == 1 ? '1' : '0' ?>">
                    </div>
                </div>
            </div>
            <p class="text-gray-400 mb-5 text-sm">Abilita Laravel Debugbar per il debug in sviluppo</p>

            <div class="bg-gray-800/30 rounded-lg p-4 transition-opacity duration-300 <?= ($debugbar_settings['debugbar_enabled'] ?? 0) != 1 || !($debugbar_settings['env_debugbar_enabled'] ?? true) ? 'opacity-40 pointer-events-none' : '' ?>">
                <div class="mb-3 flex items-center">
                    <label class="flex items-center text-gray-300 cursor-pointer text-sm">
                        <input type="hidden" name="debugbar_admin_only" value="0">
                        <input type="checkbox" name="debugbar_admin_only" value="1" <?= ($debugbar_settings['debugbar_admin_only'] ?? 0) == 1 ? 'checked' : '' ?> class="mr-3 scale-110">
                        Solo accesso admin
                    </label>
                </div>
                <div class="mb-3 flex items-center">
                    <label class="flex items-center text-gray-300 cursor-pointer text-sm">
                        <input type="hidden" name="debugbar_show_queries" value="0">
                        <input type="checkbox" name="debugbar_show_queries" value="1" <?= ($debugbar_settings['debugbar_show_queries'] ?? 0) == 1 ? 'checked' : '' ?> class="mr-3 scale-110">
                        Mostra query database
                    </label>
                </div>
                <div class="mb-3 flex items-center">
                    <label class="flex items-center text-gray-300 cursor-pointer text-sm">
                        <input type="hidden" name="debugbar_show_performance" value="0">
                        <input type="checkbox" name="debugbar_show_performance" value="1" <?= ($debugbar_settings['debugbar_show_performance'] ?? 0) == 1 ? 'checked' : '' ?> class="mr-3 scale-110">
                        Mostra metriche prestazioni
                    </label>
                </div>
                <div class="mb-3 flex items-center">
                    <label class="flex items-center text-gray-300 cursor-pointer text-sm">
                        <input type="hidden" name="debugbar_collect_views" value="0">
                        <input type="checkbox" name="debugbar_collect_views" value="1" <?= ($debugbar_settings['debugbar_collect_views'] ?? 0) == 1 ? 'checked' : '' ?> class="mr-3 scale-110">
                        Raccogli dati viste
                    </label>
                </div>

                <!-- Theme Selection -->
                <div class="mt-5 pt-4 border-t border-gray-700/50">
                    <label class="block text-gray-300 mb-2 text-sm font-medium">
                        <i class="fas fa-palette mr-2 text-purple-400"></i>
                        Tema Debugbar
                    </label>
                    <select name="debugbar_theme" class="form-control bg-gray-700/50 text-white border-gray-600 rounded-lg px-4 py-2 w-full">
                        <option value="minimal" <?= ($debugbar_settings['debugbar_theme'] ?? 'crt-amber') === 'minimal' ? 'selected' : '' ?>>
                            ⚪ Minimal (B/N - Ultra veloce)
                        </option>
                        <option value="crt-amber" <?= ($debugbar_settings['debugbar_theme'] ?? 'crt-amber') === 'crt-amber' ? 'selected' : '' ?>>
                            📺 CRT Amber (Retro anni '80)
                        </option>
                    </select>
                    <p class="text-gray-500 text-xs mt-2">
                        <strong>Minimal:</strong> Bianco/nero, impatto zero, velocissimo<br>
                        <strong>CRT Amber:</strong> Giallo retro, scanlines, glow effects
                    </p>
                </div>
            </div>

            <button type="submit" class="btn btn-success mt-4" <?= !($debugbar_settings['env_debugbar_enabled'] ?? true) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                <i class="fas fa-save mr-2"></i>
                Salva Impostazioni Debugbar
            </button>
        </div>
    </form>

    <!-- Browser Console Logging Settings -->
    <form method="POST" action="<?= $_SERVER['REQUEST_URI'] ?>" class="browser-console-form mt-8">
        <div class="card">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-xl font-semibold text-green-400 flex items-center">
                    <i class="fas fa-terminal mr-2"></i>
                    Log Console Browser
                </h4>
                <div class="toggle-container">
                    <div class="toggle <?= ($browser_console_enabled ?? true) ? 'active' : '' ?>" onclick="toggleBrowserConsole(this)">
                        <div class="toggle-slider"></div>
                        <input type="checkbox" id="js_console_browser_enabled" name="js_console_browser_enabled" value="true" <?= ($browser_console_enabled ?? true) ? 'checked' : '' ?> style="display: none;">
                        <input type="hidden" id="js_console_browser_enabled_value" name="js_console_browser_enabled_hidden" value="<?= ($browser_console_enabled ?? true) ? 'true' : 'false' ?>">
                    </div>
                </div>
            </div>
            <p class="text-gray-400 mb-5 text-sm">
                Controlla se i log JavaScript appaiono nella console del browser o vanno solo nei file di log del server
            </p>

            <div class="bg-gradient-to-r from-green-900/20 to-blue-900/20 rounded-lg p-4 border border-green-700/30">
                <div class="flex items-start gap-3 mb-4">
                    <i class="fas fa-info-circle text-green-400 text-xl mt-1"></i>
                    <div class="flex-1">
                        <h5 class="text-white font-semibold mb-2">Come Funziona</h5>
                        <ul class="text-sm text-gray-300 space-y-2">
                            <li><strong class="text-green-400">Abilitato (ON):</strong> I log console appaiono nel browser E vengono inviati ai file .log del server</li>
                            <li><strong class="text-yellow-400">Disabilitato (OFF):</strong> I log console vengono SOLO inviati al server, NON mostrati nel browser</li>
                        </ul>
                    </div>
                </div>

                <div class="mt-3 p-3 rounded bg-blue-900/20 border border-blue-700/30">
                    <div class="flex items-start gap-2">
                        <i class="fas fa-lightbulb text-yellow-400"></i>
                        <div class="text-xs text-gray-300">
                            <strong class="text-white">Caso d'Uso:</strong> Disabilita la console del browser in produzione per debuggare senza esporre i log agli utenti finali.
                            I file di log (.log) continuano a registrare tutto in base al livello del canale js_errors configurato nella tab Errori JS.
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-success mt-4">
                <i class="fas fa-save mr-2"></i>
                Salva Impostazioni Console Browser
            </button>
        </div>
    </form>

    <!-- JS Errors Database Filter Settings -->
    <form method="POST" action="<?= $_SERVER['REQUEST_URI'] ?>" class="js-errors-db-filter-form mt-8">
        <div class="card">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-xl font-semibold text-blue-400 flex items-center">
                    <i class="fas fa-database mr-2"></i>
                    Filtro Database Errori JavaScript
                </h4>
                <div class="toggle-container">
                    <div class="toggle <?= ($js_errors_db_filter_settings['enabled'] ?? true) ? 'active' : '' ?>" onclick="toggleJsErrorsDbFilter(this)">
                        <div class="toggle-slider"></div>
                        <input type="checkbox" id="js_errors_db_filter_enabled" name="enabled" value="1" <?= ($js_errors_db_filter_settings['enabled'] ?? true) ? 'checked' : '' ?> style="display: none;">
                        <input type="hidden" id="js_errors_db_filter_enabled_value" name="enabled_hidden" value="<?= ($js_errors_db_filter_settings['enabled'] ?? true) ? '1' : '0' ?>">
                    </div>
                </div>
            </div>
            <p class="text-gray-400 mb-5 text-sm">
                Configura quali errori JavaScript vengono memorizzati nel database (indipendente dalle impostazioni del canale di log su file)
            </p>

            <div class="bg-gray-800/30 rounded-lg p-4 transition-opacity duration-300 <?= !($js_errors_db_filter_settings['enabled'] ?? true) ? 'opacity-40 pointer-events-none' : '' ?>">
                <div class="mb-4">
                    <label class="block text-gray-300 mb-2 text-sm font-medium">
                        <i class="fas fa-filter mr-2 text-purple-400"></i>
                        Livello Minimo per Salvare nel Database
                    </label>
                    <select name="min_level" class="form-control bg-gray-700/50 text-white border-gray-600 rounded-lg px-4 py-2 w-full">
                        <option value="debug" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'debug' ? 'selected' : '' ?>>
                            🐛 Debug (Tutti gli errori - modalità verbosa)
                        </option>
                        <option value="info" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'info' ? 'selected' : '' ?>>
                            ℹ️ Info (Informazioni generali)
                        </option>
                        <option value="notice" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'notice' ? 'selected' : '' ?>>
                            📋 Notice (Normale ma significativo)
                        </option>
                        <option value="warning" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'warning' ? 'selected' : '' ?>>
                            ⚠️ Warning (Messaggi di avviso)
                        </option>
                        <option value="error" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'error' ? 'selected' : '' ?>>
                            ❌ Error (Default - errori runtime)
                        </option>
                        <option value="critical" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'critical' ? 'selected' : '' ?>>
                            🔥 Critical (Condizioni critiche)
                        </option>
                        <option value="alert" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'alert' ? 'selected' : '' ?>>
                            🚨 Alert (Azione immediata richiesta)
                        </option>
                        <option value="emergency" <?= ($js_errors_db_filter_settings['min_level'] ?? 'error') === 'emergency' ? 'selected' : '' ?>>
                            💥 Emergency (Sistema inutilizzabile)
                        </option>
                    </select>
                    <p class="text-gray-500 text-xs mt-2">
                        <strong>Nota:</strong> Questo filtro si applica solo allo storage nel database. Il logging su file rispetta la configurazione del canale js_errors.
                    </p>
                </div>
            </div>

            <button type="submit" class="btn btn-success mt-4">
                <i class="fas fa-save mr-2"></i>
                Salva Impostazioni Filtro Database
            </button>
        </div>
    </form>

    <!-- Telegram Log Alerts Settings -->
    <?php
    $telegramEnabled = $telegram_alerts_settings['enabled'] ?? true;
    $telegramMinLevel = $telegram_alerts_settings['min_level'] ?? 'error';
    $telegramRateLimit = $telegram_alerts_settings['rate_limit_seconds'] ?? 300;
    ?>
    <form method="POST" action="<?= $_SERVER['REQUEST_URI'] ?>" class="telegram-alerts-form">
        <div class="card">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-xl font-semibold text-blue-400 flex items-center">
                    <i class="fab fa-telegram mr-2"></i>
                    Avvisi Log Telegram
                    <span class="ml-2 px-2 py-1 bg-blue-900/30 text-blue-300 text-xs rounded-full">Tempo Reale</span>
                </h4>
                <div class="toggle-container">
                    <div class="toggle <?= $telegramEnabled ? 'active' : '' ?>" onclick="toggleTelegramAlerts(this)">
                        <div class="toggle-slider"></div>
                        <input type="checkbox" id="telegram_alerts_enabled" name="telegram_alerts_enabled" value="1" <?= $telegramEnabled ? 'checked' : '' ?> style="display: none;">
                        <input type="hidden" id="telegram_alerts_enabled_value" name="telegram_alerts_enabled_hidden" value="<?= $telegramEnabled ? 'true' : 'false' ?>">
                    </div>
                </div>
            </div>
            <p class="text-gray-400 mb-5 text-sm">
                Ricevi notifiche Telegram in tempo reale quando si verificano eventi di log critici.
                <span class="text-yellow-400">Async + rate-limited</span> per prevenire spam.
            </p>

            <div class="bg-gray-800/30 rounded-lg p-4 transition-opacity duration-300 <?= !$telegramEnabled ? 'opacity-40 pointer-events-none' : '' ?>" id="telegram-alerts-options">
                <div class="mb-4">
                    <label class="block text-gray-300 mb-2 text-sm font-medium">
                        <i class="fas fa-filter mr-2 text-blue-400"></i>
                        Livello Minimo Avviso
                    </label>
                    <select name="telegram_alerts_min_level" class="form-control bg-gray-700/50 text-white border-gray-600 rounded-lg px-4 py-2 w-full">
                        <option value="warning" <?= $telegramMinLevel === 'warning' ? 'selected' : '' ?>>
                            ⚠️ Warning (warning + error + critical + emergency)
                        </option>
                        <option value="error" <?= $telegramMinLevel === 'error' ? 'selected' : '' ?>>
                            ❌ Error (error + critical + emergency) - Consigliato
                        </option>
                        <option value="critical" <?= $telegramMinLevel === 'critical' ? 'selected' : '' ?>>
                            🚨 Critical (critical + emergency)
                        </option>
                        <option value="emergency" <?= $telegramMinLevel === 'emergency' ? 'selected' : '' ?>>
                            🆘 Solo Emergency (sistema fuori uso)
                        </option>
                    </select>
                    <p class="text-gray-500 text-xs mt-2">
                        Tutti gli eventi di log a questo livello o superiore attiveranno una notifica Telegram.
                    </p>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-300 mb-2 text-sm font-medium">
                        <i class="fas fa-clock mr-2 text-yellow-400"></i>
                        Limite di Frequenza (secondi)
                    </label>
                    <select name="telegram_alerts_rate_limit" class="form-control bg-gray-700/50 text-white border-gray-600 rounded-lg px-4 py-2 w-full">
                        <option value="60" <?= $telegramRateLimit == 60 ? 'selected' : '' ?>>60s (1 minuto)</option>
                        <option value="180" <?= $telegramRateLimit == 180 ? 'selected' : '' ?>>180s (3 minuti)</option>
                        <option value="300" <?= $telegramRateLimit == 300 ? 'selected' : '' ?>>300s (5 minuti) - Consigliato</option>
                        <option value="600" <?= $telegramRateLimit == 600 ? 'selected' : '' ?>>600s (10 minuti)</option>
                        <option value="1800" <?= $telegramRateLimit == 1800 ? 'selected' : '' ?>>1800s (30 minuti)</option>
                        <option value="3600" <?= $telegramRateLimit == 3600 ? 'selected' : '' ?>>3600s (1 ora)</option>
                    </select>
                    <p class="text-gray-500 text-xs mt-2">
                        Lo stesso tipo di errore attiverà solo un avviso per questo periodo.
                    </p>
                </div>

                <div class="bg-green-900/20 border border-green-700/30 rounded-lg p-3 mb-4">
                    <div class="flex items-center gap-2 text-green-300 text-sm">
                        <i class="fas fa-check-circle"></i>
                        <span><strong>Telegram connesso:</strong> Gli avvisi saranno inviati alla tua chat admin</span>
                    </div>
                </div>

                <button type="button" onclick="sendTestTelegramAlert()" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Invia Avviso di Test
                </button>
            </div>

            <button type="submit" class="btn btn-success mt-4">
                <i class="fas fa-save mr-2"></i>
                Salva Impostazioni Avvisi Telegram
            </button>
        </div>
    </form>

    <script>
    function toggleTelegramAlerts(element) {
        const isActive = element.classList.toggle('active');
        document.getElementById('telegram_alerts_enabled').checked = isActive;
        document.getElementById('telegram_alerts_enabled_value').value = isActive ? 'true' : 'false';
        document.getElementById('telegram-alerts-options').classList.toggle('opacity-40', !isActive);
        document.getElementById('telegram-alerts-options').classList.toggle('pointer-events-none', !isActive);
    }

    async function sendTestTelegramAlert() {
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Invio in corso...';
        btn.disabled = true;

        try {
            const response = await fetch('<?= $_SERVER['REQUEST_URI'] ?>?action=test_telegram_alert', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await response.json();

            if (data.success) {
                btn.innerHTML = '<i class="fas fa-check mr-2"></i>Inviato!';
                btn.classList.remove('btn-outline-info');
                btn.classList.add('btn-success');
            } else {
                btn.innerHTML = '<i class="fas fa-times mr-2"></i>Fallito';
                btn.classList.remove('btn-outline-info');
                btn.classList.add('btn-danger');
            }
        } catch (e) {
            btn.innerHTML = '<i class="fas fa-times mr-2"></i>Errore';
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-danger');
        }

        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            btn.classList.remove('btn-success', 'btn-danger');
            btn.classList.add('btn-outline-info');
        }, 3000);
    }
    </script>

    <!-- System Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value status-healthy">ATTIVO</div>
            <div class="stat-label">🏢 Modalità Enterprise</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">12x</div>
            <div class="stat-label">🚀 Più Veloce di Laravel</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">100K+</div>
            <div class="stat-label">👥 Utenti Max</div>
        </div>
        <div class="stat-card">
            <div class="stat-value <?= ($debugbar_settings['debugbar_enabled'] ?? 0) == 1 ? 'status-healthy">ABILITATO' : 'status-error">DISABILITATO' ?>"></div>
            <div class="stat-label">🐛 Stato Debugbar</div>
        </div>
    </div>

    <!-- System Configuration (Database Settings - FRESH DATA) -->
    <?php if (isset($system_config) && !empty($system_config)) { ?>
    <div class="card mt-8">
        <div class="flex justify-between items-center mb-6">
            <h4 class="text-xl font-semibold text-purple-400 flex items-center">
                <i class="fas fa-database mr-2"></i>
                Configurazione Sistema (Database)
            </h4>
            <span class="text-xs text-gray-400">
                <i class="fas fa-sync-alt mr-1"></i>
                Ultimo aggiornamento: <?= $system_config['timestamp'] ?? date('Y-m-d H:i:s') ?>
            </span>
        </div>

        <p class="text-gray-400 mb-5 text-sm">
            Impostazioni di configurazione in tempo reale caricate FRESCO dalle tabelle database <code class="text-blue-400">admin_settings</code> e <code class="text-blue-400">app_settings</code>.
            Impostazioni totali: <strong class="text-white"><?= $system_config['total_settings'] ?? 0 ?></strong>
        </p>

        <?php if (isset($system_config['error'])) { ?>
            <div class="bg-red-900/20 border border-red-700/30 rounded-lg p-4 mb-4">
                <i class="fas fa-exclamation-triangle text-red-400 mr-2"></i>
                <span class="text-red-300"><?= htmlspecialchars($system_config['error']) ?></span>
            </div>
        <?php } ?>

        <!-- ADMIN SETTINGS (debugbar, browser console, etc.) -->
        <?php if (!empty($system_config['admin_settings'])) { ?>
        <div class="mb-6">
            <h5 class="text-lg font-semibold text-green-400 mb-3 flex items-center">
                <i class="fas fa-shield-alt mr-2"></i>
                Impostazioni Admin (tabella admin_settings)
                <span class="ml-2 text-xs text-gray-400">(<?= count($system_config['admin_settings']) ?> impostazioni)</span>
            </h5>

            <div class="bg-gray-800/30 rounded-lg p-4 divide-y divide-gray-700/50">
                <?php foreach ($system_config['admin_settings'] as $setting) { ?>
                    <div class="py-3 first:pt-0 last:pb-0">
                        <div class="flex justify-between items-start mb-1">
                            <span class="text-gray-300 font-medium text-sm">
                                <?= htmlspecialchars($setting['setting_key']) ?>
                            </span>
                            <span class="text-xs text-gray-500">
                                <?= date('d/m/Y H:i', strtotime($setting['updated_at'])) ?>
                            </span>
                        </div>

                        <div class="mt-2">
                            <?php
                            $value = $setting['setting_value'];

                    // Try to decode JSON
                    $decoded = json_decode($value, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        // It's JSON - pretty print it
                        echo '<pre class="bg-gray-900/50 text-xs p-3 rounded overflow-auto max-h-40 border border-gray-700/50">';
                        echo htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        echo '</pre>';
                    } elseif ($value === '0' || $value === '1') {
                        // Boolean-like value
                        $isEnabled = $value === '1';
                        echo '<span class="px-2 py-1 rounded text-xs font-semibold ' . ($isEnabled ? 'bg-green-900/30 text-green-400' : 'bg-red-900/30 text-red-400') . '">';
                        echo $isEnabled ? '✓ Abilitato' : '✗ Disabilitato';
                        echo '</span>';
                    } else {
                        // Plain text
                        echo '<code class="text-blue-300 text-sm">' . htmlspecialchars($value) . '</code>';
                    }
                    ?>
                        </div>

                        <?php if (!empty($setting['description'])) { ?>
                            <p class="text-xs text-gray-500 mt-2 italic">
                                <?= htmlspecialchars($setting['description']) ?>
                            </p>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <!-- APP SETTINGS (logging config, system settings) -->
        <?php if (!empty($system_config['app_settings'])) { ?>
        <div class="mb-4">
            <h5 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center">
                <i class="fas fa-cogs mr-2"></i>
                Impostazioni Applicazione (tabella app_settings)
                <span class="ml-2 text-xs text-gray-400">(<?= count($system_config['app_settings']) ?> impostazioni)</span>
            </h5>

            <div class="bg-gray-800/30 rounded-lg p-4 divide-y divide-gray-700/50">
                <?php foreach ($system_config['app_settings'] as $setting) { ?>
                    <div class="py-3 first:pt-0 last:pb-0">
                        <div class="flex justify-between items-start mb-1">
                            <span class="text-gray-300 font-medium text-sm">
                                <?= htmlspecialchars($setting['setting_key']) ?>
                            </span>
                            <span class="text-xs text-gray-500">
                                <?= date('d/m/Y H:i', strtotime($setting['updated_at'])) ?>
                            </span>
                        </div>

                        <div class="mt-2">
                            <?php
                    $value = $setting['setting_value'];

                    // Try to decode JSON
                    $decoded = json_decode($value, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        // It's JSON - pretty print it
                        echo '<pre class="bg-gray-900/50 text-xs p-3 rounded overflow-auto max-h-40 border border-gray-700/50">';
                        echo htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        echo '</pre>';
                    } else {
                        // Plain text
                        echo '<code class="text-blue-300 text-sm">' . htmlspecialchars($value) . '</code>';
                    }
                    ?>
                        </div>

                        <?php if (!empty($setting['description'])) { ?>
                            <p class="text-xs text-gray-500 mt-2 italic">
                                <?= htmlspecialchars($setting['description']) ?>
                            </p>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <!-- INFO BOX -->
        <div class="mt-4 p-3 rounded bg-blue-900/20 border border-blue-700/30">
            <div class="flex items-start gap-2">
                <i class="fas fa-info-circle text-blue-400"></i>
                <div class="text-xs text-gray-300">
                    <strong class="text-white">Note:</strong> These values are loaded FRESH from the database (no cache).
                    They represent the current state of system configuration and can be modified from the admin panel or database directly.
                </div>
            </div>
        </div>
    </div>
    <?php } ?>

</div>


<script nonce="<?= csp_nonce() ?>">
    // SUCCESS MESSAGE: Keep the ?updated param visible for 3 seconds
    // Then remove it from URL without reloading (preserves message visibility)
    (function() {
        const url = new URL(window.location.href);
        const updated = url.searchParams.get('updated');

        if (updated) {
            // Wait 3 seconds for user to see the success message
            setTimeout(function() {
                // Clean URL without reloading page
                url.searchParams.delete('updated');
                url.searchParams.delete('_');
                window.history.replaceState({}, '', url.toString());
            }, 3000);
        }
    })();

    function toggleDebugbar(toggleElement) {
        const checkbox = toggleElement.querySelector('input[type="checkbox"]');
        const hiddenField = toggleElement.querySelector('input[type="hidden"]');
        const isActive = toggleElement.classList.contains('active');

        if (isActive) {
            toggleElement.classList.remove('active');
            checkbox.checked = false;
            if (hiddenField) hiddenField.value = '0';
        } else {
            toggleElement.classList.add('active');
            checkbox.checked = true;
            if (hiddenField) hiddenField.value = '1';
        }

        // Update options visibility
        const options = document.querySelector('.bg-gray-800\\/30');
        if (checkbox.checked) {
            options.classList.remove('opacity-40', 'pointer-events-none');
        } else {
            options.classList.add('opacity-40', 'pointer-events-none');
        }
    }

    // ENTERPRISE GALAXY ULTIMATE: No AJAX - Use standard form submit with HTTP 303 redirect
    // This forces Chrome/Safari to reload the page from server (bypasses ALL caches)
    const debugbarForm = document.querySelector('.debugbar-form');

    if (debugbarForm) {
        // Remove async/await AJAX - let form submit naturally
        // The server will handle save and redirect with Cache-Control headers

        debugbarForm.addEventListener('submit', function(e) {
            const saveBtn = this.querySelector('.btn-success');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '💾 Saving...';

            // Let the form submit normally - no preventDefault()
            // Server will redirect with 303 See Other after save
        });
    }

    // Toggle function for JS Errors DB Filter
    function toggleJsErrorsDbFilter(toggleElement) {
        const checkbox = toggleElement.querySelector('input[type="checkbox"]');
        const hiddenField = toggleElement.querySelector('input[type="hidden"]');
        const isActive = toggleElement.classList.contains('active');

        if (isActive) {
            toggleElement.classList.remove('active');
            checkbox.checked = false;
            if (hiddenField) hiddenField.value = '0';
        } else {
            toggleElement.classList.add('active');
            checkbox.checked = true;
            if (hiddenField) hiddenField.value = '1';
        }

        // Update options visibility for JS errors DB filter
        const jsErrorsForm = document.querySelector('.js-errors-db-filter-form');
        const options = jsErrorsForm.querySelector('.bg-gray-800\\/30');
        if (checkbox.checked) {
            options.classList.remove('opacity-40', 'pointer-events-none');
        } else {
            options.classList.add('opacity-40', 'pointer-events-none');
        }
    }

    // ENTERPRISE GALAXY ULTIMATE: No AJAX - Use standard form submit with HTTP 303 redirect
    const jsErrorsDbFilterForm = document.querySelector('.js-errors-db-filter-form');

    if (jsErrorsDbFilterForm) {
        jsErrorsDbFilterForm.addEventListener('submit', function(e) {
            const saveBtn = this.querySelector('.btn-success');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '💾 Saving...';

            // Let the form submit normally - no preventDefault()
            // Server will redirect with 303 See Other after save
        });
    }

    // Toggle function for Browser Console
    function toggleBrowserConsole(toggleElement) {
        const checkbox = toggleElement.querySelector('input[type="checkbox"]');
        const hiddenField = toggleElement.querySelector('input[type="hidden"]');
        const isActive = toggleElement.classList.contains('active');

        if (isActive) {
            toggleElement.classList.remove('active');
            checkbox.checked = false;
            if (hiddenField) hiddenField.value = 'false';
        } else {
            toggleElement.classList.add('active');
            checkbox.checked = true;
            if (hiddenField) hiddenField.value = 'true';
        }
    }

    // ENTERPRISE GALAXY ULTIMATE: No AJAX - Use standard form submit with HTTP 303 redirect
    const browserConsoleForm = document.querySelector('.browser-console-form');

    if (browserConsoleForm) {
        browserConsoleForm.addEventListener('submit', function(e) {
            const saveBtn = this.querySelector('.btn-success');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '💾 Saving...';

            // Let the form submit normally - no preventDefault()
            // Server will redirect with 303 See Other after save
        });
    }
</script>