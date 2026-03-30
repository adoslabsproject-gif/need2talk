<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico">

    <!-- Admin CSS with MIDNIGHT AURORA palette -->
    <link href="/assets/css/base.css" rel="stylesheet">

    <?php
    // ENTERPRISE: Inject debugbar HEAD assets
    if (function_exists('debugbar_render_head')) {
        echo debugbar_render_head();
    }
    ?>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight flex items-center justify-center p-4 md:p-8">

    <!-- Animated background particles -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute w-2 h-2 bg-accent-purple/30 rounded-full animate-bounce" style="top: 20%; left: 10%; animation-delay: 0s; animation-duration: 3s;"></div>
        <div class="absolute w-1 h-1 bg-energy-pink/40 rounded-full animate-bounce" style="top: 40%; left: 80%; animation-delay: 1s; animation-duration: 4s;"></div>
        <div class="absolute w-3 h-3 bg-accent-violet/20 rounded-full animate-bounce" style="top: 60%; left: 20%; animation-delay: 2s; animation-duration: 5s;"></div>
        <div class="absolute w-1 h-1 bg-cool-cyan/30 rounded-full animate-bounce" style="top: 80%; left: 70%; animation-delay: 1.5s; animation-duration: 3.5s;"></div>
    </div>

    <!-- Admin Login Card -->
    <div class="relative w-full max-w-md">
        <div class="bg-brand-charcoal/80 backdrop-blur-xl rounded-3xl p-10 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">

            <!-- Logo and Header -->
            <div class="text-center mb-8">
                <img src="/assets/img/logo.png" alt="need2talk" class="w-16 h-16 mx-auto mb-4 animate-pulse" loading="lazy" decoding="async">
                <h2 class="text-3xl font-bold mb-2 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    need2talk Admin
                </h2>
                <p class="text-neutral-silver text-sm">🔒 Accesso Sicuro con 2FA</p>
            </div>

            <form id="adminLoginForm" class="space-y-6">
                <div id="alertContainer"></div>

                <!-- Email Input -->
                <div>
                    <label for="email" class="block text-sm font-medium text-neutral-white mb-2">
                        📧 Email Amministratore
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="admin@need2talk.com"
                        class="w-full px-4 py-3 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-gray focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-brand-midnight/70 focus:text-neutral-white transition-all duration-300 backdrop-blur-sm"
                    >
                </div>

                <!-- Password Input -->
                <div>
                    <label for="password" class="block text-sm font-medium text-neutral-white mb-2">
                        🔐 Password
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="Password sicura"
                        class="w-full px-4 py-3 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-gray focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-brand-midnight/70 focus:text-neutral-white transition-all duration-300 backdrop-blur-sm"
                    >
                </div>

                <!-- Login Button -->
                <div>
                    <button
                        type="submit"
                        id="loginButton"
                        class="w-full bg-gradient-to-r from-energy-pink to-energy-magenta hover:from-energy-magenta hover:to-energy-pink text-neutral-white font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-energy-pink/25 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:ring-offset-2 focus:ring-offset-brand-charcoal disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none flex items-center justify-center gap-2"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" stroke="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z"/>
                        </svg>
                        <span id="loginButtonText">Accedi - Step 1/2</span>
                    </button>
                </div>

                <!-- Security Info -->
                <div class="text-center text-xs text-neutral-gray space-y-1 mt-6">
                    <p>🛡️ Protezione 2FA attiva</p>
                    <p>🔒 Sessione automaticamente scaduta dopo 8 ore di inattività</p>
                    <p>📧 Codice di verifica inviato via email</p>
                </div>

                <!-- Emergency Access -->
                <div class="text-center mt-4">
                    <button
                        type="button"
                        onclick="showEmergencyAccess()"
                        class="text-xs text-neutral-gray hover:text-energy-pink transition-colors duration-200 border-b border-dotted border-transparent hover:border-energy-pink pb-1"
                    >
                        🆘 Emergency Access
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Emergency Access Modal -->
    <div id="emergencyModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-brand-charcoal/95 backdrop-blur-xl border border-red-500/30 rounded-2xl p-6 max-w-md w-full shadow-2xl shadow-red-500/20">

            <!-- Emergency Header -->
            <div class="text-center mb-6">
                <div class="text-4xl mb-2 animate-pulse">🆘</div>
                <h3 class="text-lg font-medium text-neutral-white mb-1">Accesso di Emergenza</h3>
                <p class="text-sm text-neutral-silver">Inserisci il codice di emergenza per bypassare il 2FA</p>
            </div>

            <form id="emergencyForm" class="space-y-4">
                <!-- Emergency Code Input -->
                <div>
                    <label for="emergencyCode" class="block text-sm font-medium text-neutral-silver mb-2">
                        Codice di Emergenza
                    </label>
                    <input
                        type="text"
                        id="emergencyCode"
                        name="emergency_code"
                        placeholder="ABC12345"
                        maxlength="8"
                        autocomplete="off"
                        required
                        class="w-full px-4 py-3 bg-brand-midnight/50 border border-red-500/50 rounded-xl text-neutral-white placeholder-neutral-gray text-center text-lg tracking-widest uppercase focus:border-red-500 focus:ring-2 focus:ring-red-500/30 transition-all duration-300 backdrop-blur-sm"
                    >
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-3">
                    <button
                        type="submit"
                        id="emergencyButton"
                        class="flex-1 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white font-semibold py-3 px-4 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-red-600/30 flex items-center justify-center gap-2"
                    >
                        <span id="emergencyButtonText">🔓 Accedi</span>
                    </button>
                    <button
                        type="button"
                        onclick="hideEmergencyAccess()"
                        class="flex-1 bg-brand-midnight/50 hover:bg-brand-midnight/70 text-neutral-white border border-neutral-darkGray font-semibold py-3 px-4 rounded-xl transition-all duration-300 backdrop-blur-sm"
                    >
                        Annulla
                    </button>
                </div>
            </form>

            <!-- Emergency Notes -->
            <div class="text-center text-xs text-neutral-gray mt-4 space-y-1">
                <p>⚠️ I codici di emergenza sono monouso</p>
                <p>🕐 Validi per 24 ore dalla generazione</p>
                <p>📞 Contatta l'amministratore di sistema se necessario</p>
            </div>
        </div>
    </div>

    <script nonce="<?= csp_nonce() ?>">
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('adminLoginForm');
        const button = document.getElementById('loginButton');
        const buttonText = document.getElementById('loginButtonText');
        const alertContainer = document.getElementById('alertContainer');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            if (!email || !password) {
                showAlert('Email e password sono richiesti', 'error');
                return;
            }

            // Disable button and show loading
            button.disabled = true;
            buttonText.textContent = '🔄 Verificando credenziali...';

            try {
                const response = await fetch('<?= $admin_url ?>/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Codice 2FA inviato! Controlla la tua email.', 'success');
                    buttonText.textContent = '📧 Codice inviato via email';

                    // Redirect to 2FA page after 2 seconds
                    setTimeout(() => {
                        window.location.href = `<?= $admin_url ?>/2fa?token=${result.temp_token}`;
                    }, 2000);
                } else {
                    showAlert(result.error || 'Errore durante il login', 'error');
                    button.disabled = false;
                    buttonText.textContent = 'Accedi - Step 1/2';
                }
            } catch (error) {
                showAlert('Errore di connessione. Riprova.', 'error');
                button.disabled = false;
                buttonText.textContent = 'Accedi - Step 1/2';
            }
        });

        function showAlert(message, type) {
            const alertClass = type === 'success'
                ? 'bg-green-500/20 border border-green-500/50 text-green-100 p-3 rounded-lg'
                : 'bg-red-500/20 border border-red-500/50 text-red-100 p-3 rounded-lg';

            alertContainer.innerHTML = `
                <div class="${alertClass}">
                    <p class="text-sm">${message}</p>
                </div>
            `;

            // Auto-hide after 5 seconds
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }

        // Emergency Access Functions
        const emergencyForm = document.getElementById('emergencyForm');
        const emergencyButton = document.getElementById('emergencyButton');
        const emergencyButtonText = document.getElementById('emergencyButtonText');

        if (emergencyForm) {
            emergencyForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const emergencyCode = document.getElementById('emergencyCode').value.trim().toUpperCase();

                if (!emergencyCode) {
                    showAlert('Il codice di emergenza è obbligatorio', 'error');
                    return;
                }

                // Disable button and show loading
                emergencyButton.disabled = true;
                emergencyButtonText.textContent = '🔄 Verifica in corso...';

                try {
                    const response = await fetch('<?= $admin_url ?>/emergency-login', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `emergency_code=${encodeURIComponent(emergencyCode)}`
                    });

                    const result = await response.json();

                    if (result.success) {
                        showAlert('🆘 Accesso di emergenza concesso! Reindirizzamento...', 'success');
                        emergencyButtonText.textContent = '✅ Accesso Concesso';

                        // Redirect to dashboard after 1 second
                        setTimeout(() => {
                            window.location.href = `<?= $admin_url ?>/dashboard`;
                        }, 1000);
                    } else {
                        showAlert(result.error || 'Codice di emergenza non valido', 'error');
                        emergencyButton.disabled = false;
                        emergencyButtonText.textContent = '🔓 Accedi';
                    }
                } catch (error) {
                    showAlert('Errore di connessione. Riprova.', 'error');
                    emergencyButton.disabled = false;
                    emergencyButtonText.textContent = '🔓 Accedi';
                }
            });
        }
    });

    // Global functions for modal control
    function showEmergencyAccess() {
        document.getElementById('emergencyModal').classList.remove('hidden');
        document.getElementById('emergencyCode').focus();
    }

    function hideEmergencyAccess() {
        document.getElementById('emergencyModal').classList.add('hidden');
        document.getElementById('emergencyCode').value = '';
        document.getElementById('emergencyButton').disabled = false;
        document.getElementById('emergencyButtonText').textContent = '🔓 Accedi';
    }
    </script>

    <?php
    // ENTERPRISE: Inject debugbar BODY assets
    if (function_exists('debugbar_render')) {
        echo debugbar_render();
    }
    ?>
</body>
</html>
