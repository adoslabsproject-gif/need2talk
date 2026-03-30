<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova Segnalazione - need2talk</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #0f172a; color: #e2e8f0;">

    <!-- Main Container -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0f172a; padding: 40px 20px;">
        <tr>
            <td align="center">
                <!-- Email Content -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #1e293b; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);">

                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold;">
                                🚨 Nuova Segnalazione
                            </h1>
                            <p style="margin: 10px 0 0 0; color: #f3e8ff; font-size: 14px;">
                                Report ID: #<?= htmlspecialchars($report_id) ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">

                            <!-- Alert Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #991b1b; border-left: 4px solid #dc2626; border-radius: 8px; margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0; color: #fca5a5; font-size: 14px; line-height: 1.6;">
                                            <strong style="color: #fef2f2;">⚠️ Azione Richiesta</strong><br>
                                            Una nuova segnalazione richiede la tua attenzione. Esamina i dettagli e prendi le misure appropriate.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Report Details -->
                            <h2 style="margin: 0 0 20px 0; color: #f1f5f9; font-size: 20px;">Dettagli Segnalazione</h2>

                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 12px; background-color: #334155; border-bottom: 1px solid #475569;">
                                        <strong style="color: #cbd5e1;">Tipo:</strong>
                                    </td>
                                    <td style="padding: 12px; background-color: #334155; border-bottom: 1px solid #475569;">
                                        <span style="display: inline-block; padding: 4px 12px; background-color: #8b5cf6; color: #ffffff; border-radius: 6px; font-size: 12px; font-weight: bold;">
                                            <?= strtoupper(htmlspecialchars($report_type)) ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; background-color: #2d3748; border-bottom: 1px solid #475569;">
                                        <strong style="color: #cbd5e1;">Email Reporter:</strong>
                                    </td>
                                    <td style="padding: 12px; background-color: #2d3748; border-bottom: 1px solid #475569; color: #e2e8f0;">
                                        <?= htmlspecialchars($reporter_email) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; background-color: #334155; border-bottom: 1px solid #475569;">
                                        <strong style="color: #cbd5e1;">Data/Ora:</strong>
                                    </td>
                                    <td style="padding: 12px; background-color: #334155; border-bottom: 1px solid #475569; color: #e2e8f0;">
                                        <?= htmlspecialchars($submitted_at) ?>
                                    </td>
                                </tr>
                                <?php if (!empty($content_url)): ?>
                                <tr>
                                    <td style="padding: 12px; background-color: #2d3748; border-bottom: 1px solid #475569;">
                                        <strong style="color: #cbd5e1;">URL Contenuto:</strong>
                                    </td>
                                    <td style="padding: 12px; background-color: #2d3748; border-bottom: 1px solid #475569;">
                                        <a href="<?= htmlspecialchars($content_url) ?>" style="color: #60a5fa; text-decoration: none;">
                                            <?= htmlspecialchars($content_url) ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>

                            <!-- Description -->
                            <h3 style="margin: 30px 0 15px 0; color: #f1f5f9; font-size: 18px;">Descrizione</h3>
                            <div style="background-color: #334155; border-radius: 8px; padding: 20px; border-left: 4px solid #8b5cf6;">
                                <p style="margin: 0; color: #e2e8f0; font-size: 14px; line-height: 1.8; white-space: pre-wrap;">
<?= htmlspecialchars($description) ?>
                                </p>
                            </div>

                            <!-- Evidence (if provided) -->
                            <?php if (!empty($evidence)): ?>
                            <h3 style="margin: 30px 0 15px 0; color: #f1f5f9; font-size: 18px;">Prove Aggiuntive</h3>
                            <div style="background-color: #1e3a5f; border-radius: 8px; padding: 20px; border-left: 4px solid #3b82f6;">
                                <p style="margin: 0; color: #bfdbfe; font-size: 14px; line-height: 1.8; white-space: pre-wrap;">
<?= htmlspecialchars($evidence) ?>
                                </p>
                            </div>
                            <?php endif; ?>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-top: 1px solid #475569;">
                            <p style="margin: 0 0 10px 0; color: #94a3b8; font-size: 12px;">
                                Questa email è stata generata automaticamente dal sistema need2talk
                            </p>
                            <p style="margin: 0; color: #64748b; font-size: 12px;">
                                © <?= date('Y') ?> need2talk - Tutti i diritti riservati
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
