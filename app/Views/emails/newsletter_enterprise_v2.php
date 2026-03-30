<?php
/**
 * NEED2TALK - ENTERPRISE NEWSLETTER TEMPLATE V2
 *
 * Template newsletter enterprise-grade con deep purple gradient
 * - Stile identico a verification.php e welcome.php
 * - Deep purple gradient background (#1a0f2e → #2d1b4e → #0f0824)
 * - Dark theme completo con sfondo nero
 * - Header con logo e sfumatura viola-rosa
 * - Responsive per tutti i client email
 * - Tracking pixel integrato
 * - Unsubscribe link GDPR-compliant
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
// $campaign_name - Nome della campagna newsletter
// $subject - Subject della newsletter (opzionale)
// $html_content - Contenuto HTML dalla campagna (da TinyMCE)
// $user_nickname - Nome utente
// $tracking_pixel_url - URL per tracking apertura (opzionale)
// $unsubscribe_token - Token per disiscrizione (opzionale)
// $companyName - Nome azienda (default: need2talk)
// $supportEmail - Email supporto

$companyName = $companyName ?? 'need2talk';
$supportEmail = $supportEmail ?? 'support@need2talk.it';
$campaign_name = $campaign_name ?? 'Newsletter';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($subject ?? $campaign_name) ?></title>

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

        /* TinyMCE Content Styling */
        .newsletter-content { color: #ffffff !important; line-height: 1.7; }
        .newsletter-content p { color: #f6f6f6 !important; margin-bottom: 16px; }
        .newsletter-content h1 { color: #eeececff !important; font-size: 28px; font-weight: bold; margin: 24px 0 16px; }
        .newsletter-content h2 { color: #eeececff !important; font-size: 24px; font-weight: bold; margin: 20px 0 12px; }
        .newsletter-content h3 { color: #eeececff !important; font-size: 20px; font-weight: bold; margin: 16px 0 10px; }
        .newsletter-content ul, .newsletter-content ol { color: #f6f6f6 !important; margin: 12px 0; padding-left: 24px; }
        .newsletter-content li { color: #f6f6f6 !important; margin-bottom: 8px; }
        .newsletter-content a { color: #9333ea !important; text-decoration: underline; }
        .newsletter-content a:hover { color: #ec4899 !important; }
        .newsletter-content strong { color: #ffffff !important; font-weight: bold; }
        .newsletter-content em { color: #c3cbd6ff !important; font-style: italic; }
        .newsletter-content blockquote {
            border-left: 4px solid #9333ea;
            padding: 12px 20px;
            margin: 16px 0;
            background-color: #1a1a1a;
            color: #c3cbd6ff !important;
            border-radius: 4px;
        }
        .newsletter-content code {
            background-color: #1a1a1a;
            color: #10b981 !important;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .newsletter-content pre {
            background-color: #1a1a1a;
            color: #10b981 !important;
            padding: 16px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 16px 0;
            border: 1px solid #333;
        }
        .newsletter-content img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 16px 0;
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">

    <!-- Preheader (nascosto ma utile per preview) -->
    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
        <?= htmlspecialchars($campaign_name) ?> - La tua newsletter da need2talk
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
                            <!-- Logo - ENTERPRISE: Brand logo integration (table-based for email client compatibility) -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                                <tr>
                                    <td align="center">
                                        <div class="logo" style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 16px; display: inline-block; text-align: center; vertical-align: middle; line-height: 80px;">
                                            <img src="<?= buildEmailUrl('assets/img/logo.png') ?>" alt="<?= htmlspecialchars($companyName) ?> Logo" style="width: 60px; height: 60px; border-radius: 12px; vertical-align: middle;" />
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Brand Name -->
                            <h1 style="color: white; font-size: 32px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                <?= htmlspecialchars($companyName) ?>
                            </h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 16px;">
                                <?= htmlspecialchars($campaign_name) ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Main Content - ENTERPRISE: Deep purple gradient background -->
                    <tr>
                        <td class="content" style="padding: 40px; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; color: #eeececff !important;">

                            <!-- Greeting (opzionale se presente user_nickname) -->
                            <?php if (!empty($user_nickname)): ?>
                            <p style="color: #f6f6f6; font-size: 16px; margin: 0 0 24px;" class="text-muted">
                                Ciao <strong><?= htmlspecialchars($user_nickname) ?></strong>,
                            </p>
                            <?php endif; ?>

                            <!-- Subject Line (opzionale se diverso da campaign_name) -->
                            <?php if (!empty($subject) && $subject !== $campaign_name): ?>
                            <h2 style="color: #eeececff; font-size: 24px; font-weight: bold; margin: 0 0 24px;" class="text-dark">
                                <?= htmlspecialchars($subject) ?>
                            </h2>
                            <?php endif; ?>

                            <!-- Newsletter Content (HTML from TinyMCE) -->
                            <div class="newsletter-content" style="color: #ffffff; font-size: 16px; line-height: 1.7; margin: 0;">
                                <?= $html_content /* HTML from TinyMCE - already sanitized */ ?>
                            </div>

                            <!-- Tracking Pixel (invisible 1x1) -->
                            <?php if (isset($tracking_pixel_url) && !empty($tracking_pixel_url)): ?>
                            <img src="<?= htmlspecialchars($tracking_pixel_url) ?>" width="1" height="1" alt="" style="display:block;border:0;outline:none;opacity:0;margin:0;padding:0;">
                            <?php endif; ?>

                            <!-- Closing Message -->
                            <p style="color: #f6f6f6; font-size: 14px; margin: 32px 0 0; padding-top: 20px; border-top: 1px solid #444;" class="text-muted">
                                Grazie per far parte della community <?= htmlspecialchars($companyName) ?>! 💜
                            </p>

                        </td>
                    </tr>

                    <!-- Footer - ENTERPRISE: Deep purple gradient background -->
                    <tr>
                        <td style="padding: 30px 40px; background: linear-gradient(135deg, #0f0824 0%, #1a0f2e 50%, #2d1b4e 100%) !important; border-radius: 0 0 12px 12px; border-top: 1px solid #444; color: #000000 !important;">

                            <!-- Security Notice -->
                            <div style="text-align: center; margin-bottom: 20px;">
                                <p style="color: #f6f6f6; font-size: 12px; margin: 0;" class="text-muted">
                                    🔒 Questa newsletter è stata inviata dal sistema sicuro di <?= htmlspecialchars($companyName) ?>
                                </p>
                            </div>

                            <!-- Timestamp -->
                            <p style="margin: 0 0 15px 0; font-size: 11px; color: #c3cbd6ff; text-align: center;">
                                Inviato il <?= date('d/m/Y H:i', time()) ?>
                            </p>

                            <!-- Support Links -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="<?= buildEmailUrl('') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            🌐 Vai al Sito
                                        </a>
                                        <span style="color: #d1d5db;">|</span>
                                        <a href="<?= buildEmailUrl('login') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            🔐 Accedi
                                        </a>
                                        <span style="color: #d1d5db;">|</span>
                                        <a href="<?= buildEmailUrl('legal/privacy') ?>" style="color: #9333ea; text-decoration: none; font-size: 14px; margin: 0 10px;">
                                            🛡️ Privacy
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Unsubscribe Link (GDPR Required) -->
                            <?php if (isset($unsubscribe_token) && !empty($unsubscribe_token)): ?>
                            <p style="margin: 20px 0 0 0; font-size: 12px; color: #c3cbd6ff; text-align: center;">
                                Non vuoi più ricevere le nostre newsletter?<br>
                                <a href="https://need2talk.it/newsletter/unsubscribe/<?= htmlspecialchars($unsubscribe_token, ENT_QUOTES, 'UTF-8') ?>"
                                   style="color: #9333ea; text-decoration: underline;">
                                    Clicca qui per disiscriverti
                                </a>
                            </p>
                            <?php endif; ?>

                            <!-- Company Info -->
                            <div style="text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #444;">
                                <p style="color: #f6f6f6; font-size: 12px; margin: 0 0 8px 0;" class="text-muted">
                                    © <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Tutti i diritti riservati.
                                </p>
                                <p style="color: #c3cbd6ff; font-size: 11px; margin: 0;" class="text-muted">
                                    <?= htmlspecialchars($companyName) ?> di Angelo Zeli<br>
                                    Via Canaletto Nord, 117 - 41122 Modena (MO), Italia<br>
                                    P.IVA: 04131040368
                                </p>
                                <p style="color: #f6f6f6; font-size: 11px; margin: 12px 0 0 0;" class="text-muted">
                                    🇮🇹 Made in Italy con ❤️ per la community italiana
                                </p>
                            </div>

                        </td>
                    </tr>

                </table>

                <!-- Privacy Notice (Outside Container) -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width: 600px; margin-top: 24px;">
                    <tr>
                        <td align="center" style="padding: 0 20px;">
                            <p style="margin: 0; color: #c3cbd6ff; font-size: 11px; line-height: 1.5; text-align: center;">
                                Questa email è stata inviata in conformità con il GDPR (Regolamento UE 2016/679).<br>
                                I tuoi dati sono trattati in modo sicuro e non verranno mai condivisi con terze parti.
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>
</html>
