<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'need2talk - Verifica 2FA') ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico">

    <!-- Admin CSS with MIDNIGHT AURORA palette -->
    <link href="/assets/css/base.css" rel="stylesheet">

    <?php if (function_exists('debugbar_render_head')) {
        echo debugbar_render_head();
    } ?>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight flex items-center justify-center p-4 md:p-8">

    <!-- Animated background particles -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute w-2 h-2 bg-accent-purple/30 rounded-full animate-bounce" style="top: 20%; left: 10%; animation-delay: 0s; animation-duration: 3s;"></div>
        <div class="absolute w-1 h-1 bg-energy-pink/40 rounded-full animate-bounce" style="top: 40%; left: 80%; animation-delay: 1s; animation-duration: 4s;"></div>
        <div class="absolute w-3 h-3 bg-accent-violet/20 rounded-full animate-bounce" style="top: 60%; left: 20%; animation-delay: 2s; animation-duration: 5s;"></div>
        <div class="absolute w-1 h-1 bg-cool-cyan/30 rounded-full animate-bounce" style="top: 80%; left: 70%; animation-delay: 1.5s; animation-duration: 3.5s;"></div>
    </div>

    <!-- 2FA Verification Card -->
    <div class="relative w-full max-w-md">
        <div class="bg-brand-charcoal/80 backdrop-blur-xl rounded-3xl p-10 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">

            <!-- Logo and Header -->
            <div class="text-center mb-8">
                <img src="/assets/img/logo.png" alt="need2talk" class="w-16 h-16 mx-auto mb-4 animate-pulse" loading="lazy" decoding="async">
                <h2 class="text-3xl font-bold mb-2 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    Verifica 2FA
                </h2>
            </div>

            <!-- Instructions Card -->
            <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-2xl p-6 mb-6 backdrop-blur-sm">
                <h3 class="text-lg font-semibold text-cool-cyan mb-2">📧 Controlla la tua email!</h3>
                <p class="text-neutral-silver text-sm leading-relaxed">
                    Ti abbiamo inviato un codice di verifica a 6 cifre.<br>
                    Il codice scade in <strong class="text-neutral-white">5 minuti</strong>.
                </p>
            </div>

            <form id="verifyForm">
                <input type="hidden" name="temp_token" value="<?= htmlspecialchars($tempToken ?? '') ?>">

                <!-- 2FA Code Input -->
                <div class="mb-6">
                    <label for="code" class="block text-sm font-medium text-neutral-white mb-2">
                        🔐 Codice di Verifica
                    </label>
                    <input
                        type="text"
                        id="code"
                        name="code"
                        placeholder="123456"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                        autocomplete="off"
                        inputmode="numeric"
                        class="w-full px-4 py-4 bg-brand-midnight/50 border-2 border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-gray text-center text-2xl font-semibold tracking-widest focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-brand-midnight/70 focus:text-neutral-white transition-all duration-300 backdrop-blur-sm"
                    >
                </div>

                <!-- Verify Button -->
                <button
                    type="submit"
                    id="verifyButton"
                    class="w-full bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-neutral-white font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-green-600/25 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 focus:ring-offset-brand-charcoal disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none flex items-center justify-center gap-2 mb-4"
                >
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    <span id="verifyButtonText">Verifica e Accedi</span>
                </button>

                <!-- Loading Indicator -->
                <div id="loadingIndicator" class="hidden bg-cool-cyan/10 border border-cool-cyan/30 text-cool-cyan p-4 rounded-xl mb-4 text-center backdrop-blur-sm">
                    ⏳ Verifica in corso...
                </div>

                <!-- Error Message Container -->
                <div id="errorMessage" class="hidden"></div>
            </form>

            <!-- Back Link -->
            <a
                href="<?= htmlspecialchars($admin_url ?? '/') ?>"
                class="inline-flex items-center gap-2 text-neutral-gray hover:text-neutral-white text-sm transition-colors duration-200 px-4 py-3 rounded-xl border border-neutral-darkGray/50 bg-brand-midnight/30 hover:bg-brand-midnight/50 backdrop-blur-sm"
            >
                ← Torna al Login
            </a>

            <!-- Security Note -->
            <div class="text-center text-xs text-neutral-gray mt-6 p-4 bg-brand-midnight/30 rounded-xl border border-neutral-darkGray/30 space-y-1">
                <p>🔒 Connessione sicura • 🛡️ Protezione anti-bruteforce</p>
                <p>📊 Tentativo loggato nel sistema di audit</p>
            </div>
        </div>
    </div>

    <script nonce="<?= csp_nonce() ?>">
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('verifyForm');
        const button = document.getElementById('verifyButton');
        const buttonText = document.getElementById('verifyButtonText');
        const loading = document.getElementById('loadingIndicator');
        const codeInput = document.getElementById('code');

        // Auto-focus code input
        codeInput.focus();

        // Format input and auto-submit on 6 digits
        codeInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                // Auto-submit when 6 digits entered
                form.dispatchEvent(new Event('submit'));
            }
        });

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const code = codeInput.value.trim();

            if (!code || code.length !== 6) {
                showAlert('Inserisci un codice a 6 cifre', 'error');
                return;
            }

            // Disable button and show loading
            button.disabled = true;
            buttonText.textContent = 'Verificando...';
            loading.classList.remove('hidden');

            try {
                const formData = new FormData(this);
                const response = await fetch('<?= $admin_url ?? '' ?>/verify-2fa', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Accesso autorizzato! Reindirizzamento...', 'success');
                    buttonText.textContent = '✅ Accesso Autorizzato';

                    // ENTERPRISE: Use NEW admin URL from response (URLs rotate every half hour for security)
                    setTimeout(() => {
                        window.location.href = result.redirect || '<?= $admin_url ?? '' ?>/dashboard';
                    }, 1000);
                } else {
                    showAlert(result.error || 'Codice non valido', 'error');
                    button.disabled = false;
                    buttonText.textContent = 'Verifica e Accedi';
                    loading.classList.add('hidden');
                    codeInput.value = '';
                    codeInput.focus();
                }
            } catch (error) {
                showAlert('Errore di connessione. Riprova.', 'error');
                button.disabled = false;
                buttonText.textContent = 'Verifica e Accedi';
                loading.classList.add('hidden');
            }
        });

        function showAlert(message, type) {
            const errorDiv = document.getElementById('errorMessage');
            const alertClass = type === 'success'
                ? 'bg-green-500/20 border border-green-500/50 text-green-100 p-3 rounded-lg'
                : 'bg-red-500/20 border border-red-500/50 text-red-100 p-3 rounded-lg';

            errorDiv.innerHTML = `
                <div class="${alertClass}" style="margin-bottom: 1rem;">
                    <p class="text-sm">${message}</p>
                </div>
            `;
            errorDiv.classList.remove('hidden');

            // Auto-hide after 5 seconds
            setTimeout(() => {
                errorDiv.classList.add('hidden');
                errorDiv.innerHTML = '';
            }, 5000);
        }
    });
    </script>

    <?php if (function_exists('debugbar_render')) {
        echo debugbar_render();
    } ?>
</body>
</html>
