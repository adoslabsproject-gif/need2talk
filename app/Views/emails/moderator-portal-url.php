<?php
/**
 * NEED2TALK - MODERATOR PORTAL URL EMAIL
 *
 * Email inviata al moderatore con il link attuale del portale moderazione
 *
 * ENTERPRISE: Dark theme con accenti fucsia (branding moderazione)
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Variabili disponibili:
// $displayName - Nome visualizzato moderatore
// $portalUrl - URL completo del portale moderazione

$companyName = 'need2talk';
$supportEmail = 'support@need2talk.it';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Link Portale Moderazione - <?= htmlspecialchars($companyName) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 20px !important; }
            .content { padding: 20px !important; }
            .button { display: block !important; width: 100% !important; }
        }
    </style>
</head>
<body style="background-color: #0f0f0f; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #e0e0e0; margin: 0; padding: 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #0f0f0f;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 30px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background: linear-gradient(135deg, #d946ef, #8b5cf6); padding: 15px 30px; border-radius: 12px;">
                                        <span style="font-size: 28px; font-weight: 800; color: #ffffff; text-decoration: none; letter-spacing: -0.5px;">
                                            need2talk
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Main Content -->
                    <tr>
                        <td>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="content" style="background: linear-gradient(180deg, #1a1a2e 0%, #16162a 100%); border-radius: 16px; border: 1px solid rgba(217, 70, 239, 0.3); overflow: hidden;">
                                <!-- Banner -->
                                <tr>
                                    <td style="background: linear-gradient(135deg, rgba(217, 70, 239, 0.2), rgba(139, 92, 246, 0.2)); padding: 25px 30px; text-align: center;">
                                        <span style="font-size: 40px;">🔗</span>
                                        <h1 style="color: #f0abfc; font-size: 22px; font-weight: 700; margin: 10px 0 0 0;">
                                            Link Portale Moderazione
                                        </h1>
                                    </td>
                                </tr>

                                <!-- Body -->
                                <tr>
                                    <td style="padding: 30px;">
                                        <p style="color: #e0e0e0; font-size: 16px; margin-bottom: 20px;">
                                            Ciao <strong style="color: #f0abfc;"><?= htmlspecialchars($displayName) ?></strong>,
                                        </p>

                                        <p style="color: #b0b0b0; font-size: 15px; margin-bottom: 25px;">
                                            Ecco il link attuale del Portale Moderazione. L'URL cambia periodicamente per motivi di sicurezza, quindi conserva questo link per accedere.
                                        </p>

                                        <!-- URL Box -->
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                                            <tr>
                                                <td style="background: rgba(217, 70, 239, 0.1); border: 1px solid rgba(217, 70, 239, 0.3); border-radius: 12px; padding: 20px; text-align: center;">
                                                    <p style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">
                                                        URL Portale Moderazione
                                                    </p>
                                                    <a href="<?= htmlspecialchars($portalUrl) ?>" style="color: #d946ef; font-family: monospace; font-size: 14px; word-break: break-all; text-decoration: none;">
                                                        <?= htmlspecialchars($portalUrl) ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- CTA Button -->
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                                            <tr>
                                                <td align="center">
                                                    <a href="<?= htmlspecialchars($portalUrl) ?>" class="button" style="display: inline-block; background: linear-gradient(135deg, #d946ef 0%, #8b5cf6 100%); color: #ffffff; font-size: 16px; font-weight: 600; text-decoration: none; padding: 14px 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(217, 70, 239, 0.4);">
                                                        Accedi al Portale
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Security Notice -->
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6; border-radius: 0 8px 8px 0; padding: 15px;">
                                                    <p style="color: #93c5fd; font-size: 13px; margin: 0;">
                                                        <strong>Nota di sicurezza:</strong> Non condividere questo link con persone non autorizzate. L'URL scade periodicamente e ti verra' inviato un nuovo link quando necessario.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 20px; text-align: center;">
                            <p style="color: #666; font-size: 12px; margin-bottom: 10px;">
                                Questa email e' stata inviata da <?= htmlspecialchars($companyName) ?> Team Admin
                            </p>
                            <p style="color: #555; font-size: 11px;">
                                &copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Tutti i diritti riservati.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
