<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - need2talk</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f7; color: #1d1d1f;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f7; padding: 40px 20px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">

                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">
                                🔐 need2talk
                            </h1>
                            <p style="margin: 10px 0 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">
                                Reset Password Richiesto
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">

                            <!-- Greeting -->
                            <p style="margin: 0 0 20px 0; font-size: 16px; color: #1d1d1f;">
                                Ciao <strong><?= htmlspecialchars($nickname ?? $recipient_email) ?></strong>,
                            </p>

                            <!-- Main Message -->
                            <p style="margin: 0 0 20px 0; font-size: 15px; color: #1d1d1f; line-height: 1.6;">
                                L'amministrazione di need2talk ha generato un link per il reset della tua password.
                            </p>

                            <!-- Security Alert -->
                            <div style="margin: 0 0 30px 0; padding: 20px; background-color: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                                <p style="margin: 0; font-size: 14px; color: #856404; line-height: 1.6;">
                                    <strong>⚠️ Importante:</strong><br>
                                    Questo link è stato generato da un amministratore per motivi di sicurezza o su tua richiesta.
                                    Se non hai richiesto questo reset, ti consigliamo di contattare immediatamente il supporto.
                                </p>
                            </div>

                            <!-- Reset Token Box -->
                            <div style="margin: 0 0 30px 0; padding: 25px; background-color: #f5f5f7; border-radius: 8px; border: 2px dashed #667eea; text-align: center;">
                                <p style="margin: 0 0 15px 0; font-size: 12px; color: #6e6e73; text-transform: uppercase; letter-spacing: 1px;">
                                    Il Tuo Token di Reset
                                </p>
                                <p style="margin: 0 0 20px 0; font-family: 'Courier New', monospace; font-size: 24px; font-weight: 700; color: #667eea; letter-spacing: 2px; word-break: break-all;">
                                    <?= htmlspecialchars($reset_token) ?>
                                </p>
                                <p style="margin: 0; font-size: 11px; color: #86868b;">
                                    Valido per <?= $token_expiry_hours ?? 24 ?> ore
                                </p>
                            </div>

                            <!-- Call to Action -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="<?= htmlspecialchars($reset_url) ?>"
                                   style="display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);">
                                    🔐 Reset Password
                                </a>
                            </div>

                            <!-- Alternative Link -->
                            <div style="margin: 20px 0 30px 0; padding: 15px; background-color: #f5f5f7; border-radius: 8px;">
                                <p style="margin: 0 0 10px 0; font-size: 12px; color: #6e6e73;">
                                    <strong>Se il pulsante non funziona, copia e incolla questo link nel browser:</strong>
                                </p>
                                <p style="margin: 0; font-size: 11px; font-family: 'Courier New', monospace; color: #667eea; word-break: break-all;">
                                    <?= htmlspecialchars($reset_url) ?>
                                </p>
                            </div>

                            <!-- Instructions -->
                            <div style="margin: 0 0 20px 0; padding: 20px; background-color: #e3f2fd; border-radius: 8px; border-left: 4px solid #2196f3;">
                                <p style="margin: 0 0 10px 0; font-size: 14px; color: #0d47a1; font-weight: 600;">
                                    📋 Istruzioni:
                                </p>
                                <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: #1565c0; line-height: 1.8;">
                                    <li>Clicca sul pulsante "Reset Password" oppure copia il link</li>
                                    <li>Inserisci il token se richiesto: <code style="background: #fff; padding: 2px 6px; border-radius: 4px; font-family: monospace;"><?= htmlspecialchars($reset_token) ?></code></li>
                                    <li>Scegli una nuova password sicura (min. 8 caratteri)</li>
                                    <li>Completa il reset e accedi con la nuova password</li>
                                </ol>
                            </div>

                            <!-- Security Tips -->
                            <div style="margin: 0 0 20px 0; padding: 15px; background-color: #f5f5f7; border-radius: 8px;">
                                <p style="margin: 0 0 10px 0; font-size: 12px; color: #6e6e73; font-weight: 600;">
                                    🛡️ Consigli per la sicurezza:
                                </p>
                                <ul style="margin: 0; padding-left: 20px; font-size: 12px; color: #6e6e73; line-height: 1.6;">
                                    <li>Usa una password unica che non utilizzi altrove</li>
                                    <li>Combina lettere maiuscole, minuscole, numeri e simboli</li>
                                    <li>Evita informazioni personali facilmente indovinabili</li>
                                    <li>Considera l'uso di un password manager</li>
                                </ul>
                            </div>

                            <!-- Warning -->
                            <div style="margin: 20px 0 0 0; padding: 15px; background-color: #ffebee; border-radius: 8px; border-left: 4px solid #f44336;">
                                <p style="margin: 0; font-size: 12px; color: #c62828; line-height: 1.6;">
                                    <strong>⚠️ Non hai richiesto questo reset?</strong><br>
                                    Se non hai richiesto il reset della password, ignora questa email e contatta immediatamente il supporto.
                                    La tua password attuale rimarrà invariata se non completi la procedura.
                                </p>
                            </div>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f5f5f7; padding: 30px; text-align: center; border-top: 1px solid #e5e5e7;">

                            <!-- Admin Info -->
                            <?php if (isset($admin_name)): ?>
                            <p style="margin: 0 0 15px 0; font-size: 12px; color: #6e6e73;">
                                <strong>Reset generato da:</strong> <?= htmlspecialchars($admin_name) ?><br>
                                <strong>Email amministratore:</strong> <?= htmlspecialchars($admin_email ?? '') ?>
                            </p>
                            <?php endif; ?>

                            <!-- Expiry Info -->
                            <p style="margin: 0 0 15px 0; font-size: 12px; color: #6e6e73; background-color: #ffffff; padding: 10px; border-radius: 6px; display: inline-block;">
                                ⏰ Questo link scadrà il <strong><?= date('d/m/Y H:i', strtotime("+{$token_expiry_hours} hours")) ?></strong>
                            </p>

                            <!-- Support -->
                            <p style="margin: 0 0 15px 0; font-size: 12px; color: #6e6e73;">
                                Hai bisogno di aiuto? <a href="<?= get_env('APP_URL') ?>/legal/contacts" style="color: #667eea; text-decoration: none; font-weight: 600;">Contatta il supporto</a>
                            </p>

                            <!-- Links -->
                            <p style="margin: 0; font-size: 12px; color: #6e6e73;">
                                <a href="<?= get_env('APP_URL') ?>" style="color: #667eea; text-decoration: none;">Vai al sito</a> ·
                                <a href="<?= get_env('APP_URL') ?>/legal/privacy" style="color: #667eea; text-decoration: none;">Privacy Policy</a> ·
                                <a href="<?= get_env('APP_URL') ?>/legal/terms" style="color: #667eea; text-decoration: none;">Termini di Servizio</a>
                            </p>

                            <!-- Copyright -->
                            <p style="margin: 15px 0 0 0; font-size: 11px; color: #86868b;">
                                © <?= date('Y') ?> need2talk. Tutti i diritti riservati.
                            </p>

                            <!-- Job ID (for debugging) -->
                            <?php if (isset($job_id)): ?>
                            <p style="margin: 10px 0 0 0; font-size: 9px; color: #d1d1d6; font-family: monospace;">
                                Job ID: <?= htmlspecialchars($job_id) ?>
                            </p>
                            <?php endif; ?>

                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
