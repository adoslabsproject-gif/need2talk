<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($subject ?? 'Messaggio dall\'Amministrazione') ?></title>
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
                                need2talk
                            </h1>
                            <p style="margin: 10px 0 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">
                                Messaggio dall'Amministrazione
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
                            <div style="margin: 0 0 30px 0; padding: 20px; background-color: #f5f5f7; border-radius: 8px; border-left: 4px solid #667eea;">
                                <?= nl2br(htmlspecialchars($message)) ?>
                            </div>

                            <!-- Additional Info (if provided) -->
                            <?php if (isset($additional_info) && !empty($additional_info)): ?>
                            <div style="margin: 0 0 20px 0; padding: 15px; background-color: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                                <p style="margin: 0; font-size: 14px; color: #856404;">
                                    <strong>ℹ️ Informazioni aggiuntive:</strong><br>
                                    <?= nl2br(htmlspecialchars($additional_info)) ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Call to Action (if provided) -->
                            <?php if (isset($cta_url) && isset($cta_text)): ?>
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="<?= htmlspecialchars($cta_url) ?>"
                                   style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                                    <?= htmlspecialchars($cta_text) ?>
                                </a>
                            </div>
                            <?php endif; ?>

                            <!-- Closing -->
                            <p style="margin: 20px 0 0 0; font-size: 14px; color: #6e6e73;">
                                Questo messaggio è stato inviato dal team di amministrazione di need2talk.
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f5f5f7; padding: 30px; text-align: center; border-top: 1px solid #e5e5e7;">

                            <!-- Admin Info -->
                            <?php if (isset($admin_name)): ?>
                            <p style="margin: 0 0 15px 0; font-size: 12px; color: #6e6e73;">
                                <strong>Inviato da:</strong> <?= htmlspecialchars($admin_name) ?><br>
                                <strong>Email:</strong> <?= htmlspecialchars($admin_email ?? '') ?>
                            </p>
                            <?php endif; ?>

                            <!-- Timestamp -->
                            <p style="margin: 0 0 15px 0; font-size: 11px; color: #86868b;">
                                Inviato il <?= date('d/m/Y H:i', time()) ?>
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

                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
