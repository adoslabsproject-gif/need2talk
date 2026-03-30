<?php
/**
 * NEED2TALK - PASSWORD RESET EMAIL TEMPLATE
 *
 * Template email responsive e professionale per reset password
 * - Design coerente con brand need2talk
 * - Responsive per tutti i client email
 * - Ottimizzato per anti-spam
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
// $reset_url - URL completo per reset password (contiene token in chiaro)
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
    <title>Reimposta la tua password - <?= htmlspecialchars($companyName) ?></title>

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
        /* Black background with deep purple container */
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
        .card-text-red { color: #ef4444 !important; }
        .card-text-yellow { color: #fbbf24 !important; }
        .card-text-orange { color: #f97316 !important; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">

    <!-- Preheader (nascosto ma utile per preview) -->
    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
        Reimposta la tua password su <?= htmlspecialchars($companyName) ?> - Link sicuro valido per 1 ora
    </div>

    <!-- Container principale -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="email-body">
        <tr>
            <td style="padding: 20px 0;">

                <!-- Content Container - ENTERPRISE: Deep purple gradient background -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="container" style="margin: 0 auto; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);">

                    <!-- Header con Logo -->
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #f97316 0%, #dc2626 100%); border-radius: 12px 12px 0 0;">
                            <!-- Logo - ENTERPRISE: Brand logo integration -->
                            <div style="margin-bottom: 20px;">
                                <div class="logo" style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin: 0 auto;">
                                    <span style="font-size: 48px;">🔐</span>
                                </div>
                            </div>

                            <!-- Brand Name -->
                            <h1 style="color: white; font-size: 32px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                <?= htmlspecialchars($companyName) ?>
                            </h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 16px;">
                                Reset Password Richiesto
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
                                Hai richiesto di <strong>reimpostare la tua password</strong> su <?= htmlspecialchars($companyName) ?>.
                                Clicca sul pulsante qui sotto per creare una nuova password sicura.
                            </p>

                            <!-- Call to Action Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 32px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="<?= htmlspecialchars($reset_url) ?>"
                                           class="button"
                                           style="display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, #f97316 0%, #dc2626 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 6px rgba(249, 115, 22, 0.3); transition: all 0.3s;">
                                            🔑 Reimposta Password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Important Information - ENTERPRISE: Dark theme with colored text -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #fbbf24; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #fbbf24; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-yellow">
                                    ⏱️ Informazioni Importanti
                                </h3>
                                <ul style="color: #ffffff; font-size: 14px; margin: 0; padding-left: 16px;" class="card-text-white">
                                    <li style="margin-bottom: 6px;">Questo link <strong style="color: #fbbf24;">scade tra 1 ora</strong> per sicurezza</li>
                                    <li style="margin-bottom: 6px;">Utilizzo <strong>singolo</strong> - dopo il reset non sarà più valido</li>
                                    <li style="margin-bottom: 6px;">Il link contiene un token crittografato SHA-256</li>
                                </ul>
                            </div>

                            <!-- Alternative Link - ENTERPRISE: Dark theme -->
                            <div style="background-color: #1a1a1a; padding: 20px; border-radius: 6px; margin: 24px 0; border: 1px solid #333;" class="content-card">
                                <p style="color: #ffffff; font-size: 14px; margin: 0 0 12px;" class="card-text-white">
                                    Se il pulsante non funziona, copia questo link nel tuo browser:
                                </p>
                                <div style="background-color: #2d2d2d; padding: 12px; border-radius: 4px; word-break: break-all; font-family: 'Courier New', monospace; font-size: 12px; color: #f97316; border: 1px solid #444;">
                                    <?= htmlspecialchars($reset_url) ?>
                                </div>
                            </div>

                            <!-- Security Warning - ENTERPRISE: Dark theme with red accents -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #ef4444; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #ef4444; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-red">
                                    ⚠️ Non hai richiesto questo reset?
                                </h3>
                                <p style="color: #ffffff; font-size: 14px; margin: 0;" class="card-text-white">
                                    Se <strong>NON hai richiesto</strong> il reset della password:
                                </p>
                                <ul style="color: #ffffff; font-size: 14px; margin: 8px 0 0; padding-left: 16px;" class="card-text-white">
                                    <li style="margin-bottom: 6px;"><strong>Ignora questa email</strong> - la tua password è ancora sicura</li>
                                    <li style="margin-bottom: 6px;">Il tuo account NON è stato compromesso</li>
                                    <li style="margin-bottom: 6px;">Il link scadrà automaticamente tra 1 ora</li>
                                    <li style="margin-bottom: 6px;">Considera di cambiare password per precauzione</li>
                                </ul>
                            </div>

                            <!-- After Reset - ENTERPRISE: Dark theme with green accents -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #10b981; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #10b981; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-green">
                                    ✅ Dopo il reset:
                                </h3>
                                <ul style="color: #ffffff; font-size: 14px; margin: 0; padding-left: 16px;" class="card-text-white">
                                    <li style="margin-bottom: 6px;">📧 Riceverai un'email di conferma</li>
                                    <li style="margin-bottom: 6px;">🔒 Usa la nuova password per accedere</li>
                                    <li style="margin-bottom: 6px;">📱 Tutti i dispositivi verranno disconnessi per sicurezza</li>
                                </ul>
                            </div>
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
                                        <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: #f97316; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            📧 Supporto
                                        </a>
                                        <span style="color: #d1d5db;">|</span>
                                        <a href="<?= buildEmailUrl('legal/privacy') ?>" style="color: #f97316; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            🛡️ Privacy
                                        </a>
                                        <span style="color: #d1d5db;">|</span>
                                        <a href="<?= buildEmailUrl('legal/terms') ?>" style="color: #f97316; text-decoration: none; font-size: 14px; margin: 0 10px;">
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
