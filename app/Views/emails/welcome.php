<?php
/**
 * NEED2TALK - WELCOME EMAIL TEMPLATE (POST-VERIFICATION)
 *
 * Email di benvenuto inviata DOPO la verifica dell'account
 * - Design coerente con brand need2talk (deep purple gradient)
 * - Responsive per tutti i client email
 * - Enterprise-grade con dark theme
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
            $host = $_SERVER['HTTP_HOST'] ?? 'need2talk.it';
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
// $email - Email utente
// $companyName - Nome azienda (default: need2talk)
// $supportEmail - Email supporto

$companyName = $companyName ?? 'need2talk';
$supportEmail = $supportEmail ?? 'support@need2talk.it';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Benvenuto in <?= htmlspecialchars($companyName) ?>!</title>

    <style nonce="<?= csp_nonce() ?>">
        /* Reset CSS per email clients */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* Stili base responsive */
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 20px !important; }
            .content { padding: 30px 20px !important; }
            .button { width: 100% !important; font-size: 16px !important; }
            .logo { width: 60px !important; height: 60px !important; }
            h1 { font-size: 28px !important; }
        }

        /* ENTERPRISE: Dark Theme with Deep Purple Container */
        @media (prefers-color-scheme: dark) {
            .email-body { background-color: #000000 !important; }
            .container { background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; }
            .content-card { background-color: #1a1a1a !important; border-color: #333 !important; }
            .text-dark { color: #ffffff !important; }
            .text-muted { color: #e5e7eb !important; }
        }

        /* ENTERPRISE: Dark Theme - Main styles */
        .email-body { background-color: #000000 !important; }
        .container { background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; }
        .content-card { background-color: #1a1a1a !important; color: #ffffff !important; border: 1px solid #333 !important; }
        .text-dark { color: #eeececff !important; }
        .text-muted { color: #c3cbd6ff !important; }
        .card-text-white { color: #ffffff !important; }
        .card-text-green { color: #10b981 !important; }
        .card-text-purple { color: #9333ea !important; }
        .card-text-pink { color: #ec4899 !important; }
        .card-text-blue { color: #3b82f6 !important; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">

    <!-- Preheader (nascosto ma utile per preview) -->
    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
        Benvenuto in <?= htmlspecialchars($companyName) ?>! Il tuo account è stato verificato con successo. Inizia subito a condividere le tue emozioni.
    </div>

    <!-- Container principale -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="email-body">
        <tr>
            <td style="padding: 20px 0;">

                <!-- Content Container - ENTERPRISE: Deep purple gradient background -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="container" style="margin: 0 auto; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);">

                    <!-- Header con Logo -->
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #9333ea 0%, #ec4899 100%); border-radius: 12px 12px 0 0;">
                            <!-- Logo - ENTERPRISE: Brand logo integration - TABLE METHOD (email client compatible) -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                                <tr>
                                    <td align="center">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="logo" style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 16px;">
                                            <tr>
                                                <td align="center" valign="middle" style="padding: 10px;">
                                                    <img src="<?= buildEmailUrl('assets/img/logo.png') ?>" alt="<?= htmlspecialchars($companyName) ?> Logo" width="60" height="60" style="display: block; width: 60px; height: 60px; border-radius: 12px; object-fit: contain; margin: 0 auto;" />
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Brand Name -->
                            <h1 style="color: white; font-size: 32px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                🎉 Benvenuto in <?= htmlspecialchars($companyName) ?>!
                            </h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 16px;">
                                Il tuo account è stato verificato con successo
                            </p>
                        </td>
                    </tr>

                    <!-- Main Content - ENTERPRISE: Deep purple gradient background -->
                    <tr>
                        <td class="content" style="padding: 40px; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; color: #eeececff !important;">

                            <!-- Greeting -->
                            <h2 style="color: #eeececff; font-size: 24px; font-weight: bold; margin: 0 0 16px; text-align: center;" class="text-dark">
                                Ciao <?= htmlspecialchars($nickname) ?>! 👋
                            </h2>

                            <p style="color: #f6f6f6; font-size: 16px; margin: 0 0 24px; text-align: center;" class="text-muted">
                                Benvenuto nella community di <strong><?= htmlspecialchars($companyName) ?></strong>!<br>
                                La tua email è stata verificata e il tuo account è ora <strong style="color: #10b981;">attivo</strong>.
                            </p>

                            <p style="color: #f6f6f6; font-size: 16px; margin: 0 0 32px; text-align: center;" class="text-muted">
                                Sei pronto per iniziare a condividere le tue emozioni con chi ti capisce veramente.
                            </p>

                            <!-- Call to Action Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 32px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="<?= buildEmailUrl('profile') ?>"
                                           class="button"
                                           style="display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, #9333ea 0%, #ec4899 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 6px rgba(147, 51, 234, 0.3); transition: all 0.3s;">
                                            🚀 Vai al tuo Profilo
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- What You Can Do Now - ENTERPRISE: Dark theme with green accents -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #10b981; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #10b981; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-green">
                                    🎙️ Cosa puoi fare ora:
                                </h3>
                                <ul style="color: #ffffff; font-size: 14px; margin: 0; padding-left: 16px;" class="card-text-white">
                                    <li style="margin-bottom: 8px;">🎧 <strong>Ascoltare</strong> tutte le emozioni condivise dalla community</li>
                                    <li style="margin-bottom: 8px;">🎤 <strong>Registrare</strong> i tuoi messaggi audio anonimi (max 30 secondi)</li>
                                    <li style="margin-bottom: 8px;">💬 <strong>Connetterti</strong> con persone che capiscono davvero quello che provi</li>
                                    <li style="margin-bottom: 8px;">👥 <strong>Aggiungere amici</strong> e costruire la tua rete emotiva</li>
                                    <li style="margin-bottom: 8px;">🔒 <strong>Condividere</strong> in totale sicurezza e privacy (18+)</li>
                                </ul>
                            </div>

                            <!-- Quick Start Guide - ENTERPRISE: Dark theme with purple accents -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #9333ea; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #9333ea; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-purple">
                                    📖 Guida rapida per iniziare:
                                </h3>
                                <ol style="color: #ffffff; font-size: 14px; margin: 0; padding-left: 20px;" class="card-text-white">
                                    <li style="margin-bottom: 8px;"><strong>Completa il tuo profilo</strong> - Aggiungi una foto e personalizza le tue informazioni</li>
                                    <li style="margin-bottom: 8px;"><strong>Esplora la community</strong> - Scopri le emozioni condivise dagli altri</li>
                                    <li style="margin-bottom: 8px;"><strong>Registra il tuo primo messaggio</strong> - Condividi quello che senti</li>
                                    <li style="margin-bottom: 8px;"><strong>Connettiti</strong> - Inizia a costruire la tua rete emotiva</li>
                                </ol>
                            </div>

                            <!-- Useful Links - ENTERPRISE: Dark theme with blue accents -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #3b82f6; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #3b82f6; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-blue">
                                    🔗 Link utili:
                                </h3>
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td style="padding: 6px 0;">
                                            <a href="<?= buildEmailUrl('profile') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px;">
                                                👤 Il tuo Profilo
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0;">
                                            <a href="<?= buildEmailUrl('help/guide') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px;">
                                                📚 Guida completa
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0;">
                                            <a href="<?= buildEmailUrl('legal/privacy') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px;">
                                                🛡️ Privacy Policy
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0;">
                                            <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: #9333ea; text-decoration: none; font-size: 14px;">
                                                💬 Supporto Tecnico
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Final Message -->
                            <p style="color: #f6f6f6; font-size: 16px; margin: 32px 0 0; text-align: center;" class="text-muted">
                                Grazie per esserti unito alla nostra community! 💜<br>
                                Siamo felici di averti con noi.
                            </p>

                            <p style="color: #c3cbd6ff; font-size: 14px; margin: 16px 0 0; text-align: center;" class="text-muted">
                                - Il Team di <?= htmlspecialchars($companyName) ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer - ENTERPRISE: Deep purple gradient background -->
                    <tr>
                        <td style="padding: 30px 40px; background: linear-gradient(135deg, #0f0824 0%, #1a0f2e 50%, #2d1b4e 100%) !important; border-radius: 0 0 12px 12px; border-top: 1px solid #444; color: #000000 !important;">

                            <!-- Security Notice -->
                            <div style="text-align: center; margin-bottom: 20px;">
                                <p style="color: #f6f6f6; font-size: 12px; margin: 0;" class="text-muted">
                                    🔒 Questa email è stata inviata automaticamente dal sistema sicuro di <?= htmlspecialchars($companyName) ?>
                                </p>
                            </div>

                            <!-- Support Links -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: #9333ea; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            📧 Supporto
                                        </a>
                                        <span style="color: #d1d5db;">|</span>
                                        <a href="<?= buildEmailUrl('legal/privacy') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            🛡️ Privacy
                                        </a>
                                        <span style="color: #d1d5db;">|</span>
                                        <a href="<?= buildEmailUrl('legal/terms') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            📋 Termini
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Company Info -->
                            <div style="text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <p style="color: #f6f6f6; font-size: 12px; margin: 0;" class="text-muted">
                                    © <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Tutti i diritti riservati.<br>
                                    🇮🇹 Made in Italy con ❤️ per la community italiana
                                </p>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
