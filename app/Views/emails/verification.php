<?php
/**
 * NEED2TALK - EMAIL VERIFICATION TEMPLATE
 *
 * Template email responsive e professionale per verifica account
 * - Design coerente con brand need2talk
 * - ENTERPRISE: 100% inline styles (no CSS classes - GMX/Outlook compatibility)
 * - Colori solidi con fallback (no gradients)
 * - Accessibile e user-friendly
 */

// ENTERPRISE TIPS: Direct URL building to avoid Laravel UrlGenerator dependency in workers
if (!function_exists('buildEmailUrl')) {
    function buildEmailUrl($path = '')
    {
        $base = $_ENV['APP_URL'] ?? null;

        if (!$base) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || $_SERVER['SERVER_PORT'] === 443 ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'need2talk.test';
            $base = $protocol . '://' . $host;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Variabili disponibili:
// $nickname - Nome utente
// $verification_url - URL completo per verifica
// $companyName - Nome azienda (default: need2talk)
// $supportEmail - Email supporto

$companyName = $companyName ?? 'need2talk';
$supportEmail = $supportEmail ?? 'support@need2talk.it';

// ENTERPRISE: Colori definiti come variabili per consistenza
$colorPurple = '#7c3aed';
$colorPink = '#ec4899';
$colorDarkBg = '#1e1e2e';
$colorCardBg = '#2d2d3d';
$colorTextLight = '#f5f5f5';
$colorTextMuted = '#d1d5db';
$colorGreen = '#10b981';
$colorYellow = '#fbbf24';
$colorRed = '#ef4444';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Verifica il tuo account <?= htmlspecialchars($companyName) ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: <?= $colorDarkBg ?>; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">

    <!-- Preheader (nascosto ma utile per preview) -->
    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
        Conferma la tua email per iniziare a usare <?= htmlspecialchars($companyName) ?> - Clicca per verificare il tuo account
    </div>

    <!-- Container principale -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: <?= $colorDarkBg ?>;">
        <tr>
            <td style="padding: 20px 0;">

                <!-- Content Container -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" align="center" style="margin: 0 auto; background-color: <?= $colorDarkBg ?>; border-radius: 12px; max-width: 600px;">

                    <!-- Header con Logo -->
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background-color: <?= $colorPurple ?>; border-radius: 12px 12px 0 0;">
                            <!-- Logo -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                                <tr>
                                    <td align="center">
                                        <div style="width: 80px; height: 80px; background-color: rgba(255,255,255,0.2); border-radius: 16px; display: inline-block; text-align: center; line-height: 80px;">
                                            <img src="<?= buildEmailUrl('assets/img/logo-120.png') ?>" alt="<?= htmlspecialchars($companyName) ?> Logo" width="60" height="60" style="width: 60px; height: 60px; border-radius: 12px; vertical-align: middle;" />
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Brand Name -->
                            <h1 style="color: #ffffff; font-size: 32px; font-weight: bold; margin: 0;">
                                <?= htmlspecialchars($companyName) ?>
                            </h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 16px;">
                                Fai parlare la tua anima
                            </p>
                        </td>
                    </tr>

                    <!-- Main Content -->
                    <tr>
                        <td style="padding: 40px; background-color: <?= $colorDarkBg ?>;">

                            <!-- Greeting -->
                            <h2 style="color: <?= $colorTextLight ?>; font-size: 24px; font-weight: bold; margin: 0 0 16px; text-align: center;">
                                Ciao <?= htmlspecialchars($nickname) ?>! 👋
                            </h2>

                            <p style="color: <?= $colorTextMuted ?>; font-size: 16px; margin: 0 0 24px; text-align: center;">
                                Benvenuto in <strong style="color: <?= $colorTextLight ?>;"><?= htmlspecialchars($companyName) ?></strong>!
                                Per completare la registrazione e iniziare a condividere le tue emozioni,
                                devi verificare il tuo indirizzo email.
                            </p>

                            <!-- Call to Action Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 32px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="<?= htmlspecialchars($verification_url) ?>"
                                           style="display: inline-block; padding: 16px 32px; background-color: <?= $colorPurple ?>; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 18px;">
                                            ✅ Verifica la mia Email
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Important Information -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 24px 0;">
                                <tr>
                                    <td style="background-color: <?= $colorCardBg ?>; border-left: 4px solid <?= $colorYellow ?>; padding: 20px; border-radius: 6px;">
                                        <h3 style="color: <?= $colorYellow ?>; font-size: 16px; font-weight: bold; margin: 0 0 12px;">
                                            📋 Informazioni Importanti
                                        </h3>
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">• Questo link scade tra <strong style="color: <?= $colorYellow ?>;">1 ora</strong></td></tr>
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">• Clicca una sola volta per verificare</td></tr>
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">• Se non hai creato un account, ignora questa email</td></tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Alternative Link -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 24px 0;">
                                <tr>
                                    <td style="background-color: <?= $colorCardBg ?>; padding: 20px; border-radius: 6px;">
                                        <p style="color: <?= $colorTextLight ?>; font-size: 14px; margin: 0 0 12px;">
                                            Se il pulsante non funziona, copia questo link nel tuo browser:
                                        </p>
                                        <div style="background-color: #1a1a2e; padding: 12px; border-radius: 4px; word-break: break-all; font-family: 'Courier New', monospace; font-size: 12px; color: #ffffff;">
                                            <?= htmlspecialchars($verification_url) ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- What You Can Do -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 24px 0;">
                                <tr>
                                    <td style="background-color: <?= $colorCardBg ?>; border-left: 4px solid <?= $colorGreen ?>; padding: 20px; border-radius: 6px;">
                                        <h3 style="color: <?= $colorGreen ?>; font-size: 16px; font-weight: bold; margin: 0 0 12px;">
                                            🎙️ Cosa potrai fare una volta verificato:
                                        </h3>
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">🎧 Ascoltare tutte le emozioni della community</td></tr>
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">🎤 Registrare i tuoi messaggi audio anonimi</td></tr>
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">💬 Connetterti con chi ti capisce veramente</td></tr>
                                            <tr><td style="color: <?= $colorTextLight ?>; font-size: 14px; padding: 3px 0;">🔒 Condividere in totale sicurezza e privacy</td></tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Age Restriction Notice -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 24px 0;">
                                <tr>
                                    <td style="background-color: <?= $colorCardBg ?>; border-left: 4px solid <?= $colorRed ?>; padding: 20px; border-radius: 6px;">
                                        <p style="color: <?= $colorTextLight ?>; font-size: 14px; margin: 0;">
                                            ⚠️ <strong style="color: <?= $colorRed ?>;">Ricorda:</strong> <?= htmlspecialchars($companyName) ?> è riservato esclusivamente ai maggiorenni <strong style="color: <?= $colorRed ?>;">(18+)</strong>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #1a1a2e; border-radius: 0 0 12px 12px; border-top: 1px solid #444;">

                            <!-- Security Notice -->
                            <p style="color: <?= $colorTextMuted ?>; font-size: 12px; margin: 0 0 20px; text-align: center;">
                                🔒 Questa email è stata inviata automaticamente dal sistema sicuro di <?= htmlspecialchars($companyName) ?>
                            </p>

                            <!-- Support Links -->
                            <p style="text-align: center; margin: 0 0 20px;">
                                <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: <?= $colorPurple ?>; text-decoration: none; font-size: 14px;">📧 Supporto</a>
                                <span style="color: <?= $colorTextMuted ?>;"> | </span>
                                <a href="<?= buildEmailUrl('legal/privacy') ?>" style="color: <?= $colorPurple ?>; text-decoration: none; font-size: 14px;">🛡️ Privacy</a>
                                <span style="color: <?= $colorTextMuted ?>;"> | </span>
                                <a href="<?= buildEmailUrl('legal/terms') ?>" style="color: <?= $colorPurple ?>; text-decoration: none; font-size: 14px;">📋 Termini</a>
                            </p>

                            <!-- Company Info -->
                            <p style="color: <?= $colorTextMuted ?>; font-size: 12px; margin: 0; text-align: center; padding-top: 20px; border-top: 1px solid #333;">
                                © <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Tutti i diritti riservati.<br>
                                🇮🇹 Made in Italy con ❤️ per la community italiana
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
