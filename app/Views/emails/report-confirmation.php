<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conferma Segnalazione - need2talk</title>
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
                        <td style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold;">
                                ✅ Segnalazione Ricevuta
                            </h1>
                            <p style="margin: 10px 0 0 0; color: #d1fae5; font-size: 14px;">
                                Report ID: #<?= $reportId ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">

                            <!-- Success Message -->
                            <div style="background-color: #065f46; border-left: 4px solid: #10b981; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
                                <h2 style="margin: 0 0 10px 0; color: #d1fae5; font-size: 20px;">
                                    🎉 Grazie per la tua segnalazione!
                                </h2>
                                <p style="margin: 0; color: #a7f3d0; font-size: 14px; line-height: 1.6;">
                                    Abbiamo ricevuto correttamente la tua segnalazione e il nostro team la esaminerà il prima possibile.
                                </p>
                            </div>

                            <!-- What Happens Next -->
                            <h3 style="margin: 0 0 20px 0; color: #f1f5f9; font-size: 18px;">Cosa Succede Adesso?</h3>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 15px; background-color: #334155; border-radius: 8px; margin-bottom: 10px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="40" valign="top">
                                                    <!-- ENTERPRISE: Table-based centering for email clients -->
                                                    <table cellpadding="0" cellspacing="0" border="0" style="width: 32px; height: 32px; background-color: #8b5cf6; border-radius: 50%;">
                                                        <tr>
                                                            <td align="center" valign="middle" style="color: #ffffff; font-weight: bold; font-size: 16px; line-height: 32px;">
                                                                1
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td style="padding-left: 15px;">
                                                    <h4 style="margin: 0 0 5px 0; color: #f1f5f9; font-size: 16px;">Ricezione e Registrazione</h4>
                                                    <p style="margin: 0; color: #cbd5e1; font-size: 14px; line-height: 1.6;">
                                                        La tua segnalazione è stata registrata nel nostro sistema con ID <strong>#<?= $reportId ?></strong>
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 15px; background-color: #2d3748; border-radius: 8px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="40" valign="top">
                                                    <!-- ENTERPRISE: Table-based centering for email clients -->
                                                    <table cellpadding="0" cellspacing="0" border="0" style="width: 32px; height: 32px; background-color: #8b5cf6; border-radius: 50%;">
                                                        <tr>
                                                            <td align="center" valign="middle" style="color: #ffffff; font-weight: bold; font-size: 16px; line-height: 32px;">
                                                                2
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td style="padding-left: 15px;">
                                                    <h4 style="margin: 0 0 5px 0; color: #f1f5f9; font-size: 16px;">Esame da Parte del Team</h4>
                                                    <p style="margin: 0; color: #cbd5e1; font-size: 14px; line-height: 1.6;">
                                                        Un membro del nostro team esaminerà la segnalazione entro <strong>24-48 ore</strong>. I casi urgenti hanno priorità assoluta.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 15px; background-color: #334155; border-radius: 8px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="40" valign="top">
                                                    <!-- ENTERPRISE: Table-based centering for email clients -->
                                                    <table cellpadding="0" cellspacing="0" border="0" style="width: 32px; height: 32px; background-color: #8b5cf6; border-radius: 50%;">
                                                        <tr>
                                                            <td align="center" valign="middle" style="color: #ffffff; font-weight: bold; font-size: 16px; line-height: 32px;">
                                                                3
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td style="padding-left: 15px;">
                                                    <h4 style="margin: 0 0 5px 0; color: #f1f5f9; font-size: 16px;">Risposta e Risoluzione</h4>
                                                    <p style="margin: 0; color: #cbd5e1; font-size: 14px; line-height: 1.6;">
                                                        Ti aggiorneremo sull'esito della segnalazione via email. Se necessario, potrebbero esserti richieste informazioni aggiuntive.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Important Notes -->
                            <div style="background-color: #1e3a5f; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 20px; margin-top: 30px;">
                                <h4 style="margin: 0 0 10px 0; color: #bfdbfe; font-size: 16px;">
                                    📌 Note Importanti
                                </h4>
                                <ul style="margin: 0; padding-left: 20px; color: #93c5fd; font-size: 14px; line-height: 1.8;">
                                    <li>Conserva questo ID per future comunicazioni: <strong>#<?= $reportId ?></strong></li>
                                    <li>Riceverai aggiornamenti via email a questo indirizzo</li>
                                    <li>Puoi inviare 1 segnalazione ogni 24 ore</li>
                                    <li>Le segnalazioni sono trattate con la massima riservatezza</li>
                                </ul>
                            </div>

                            <!-- Support Link -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 40px;">
                                <tr>
                                    <td align="center">
                                        <p style="margin: 0 0 20px 0; color: #94a3b8; font-size: 14px;">
                                            Hai domande sulla tua segnalazione?
                                        </p>
                                        <a href="<?= env('APP_URL', 'https://need2talk.it') ?>/legal/contacts"
                                           style="display: inline-block; padding: 12px 30px; background-color: #8b5cf6; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">
                                            💬 Contatta il Supporto
                                        </a>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 30px; text-align: center; border-top: 1px solid #475569;">
                            <p style="margin: 0 0 10px 0; color: #94a3b8; font-size: 12px;">
                                Grazie per aiutarci a rendere need2talk un posto migliore per tutti! 💜
                            </p>
                            <p style="margin: 0 0 10px 0; color: #64748b; font-size: 12px;">
                                Questa email è stata inviata automaticamente. Per favore non rispondere direttamente.
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
