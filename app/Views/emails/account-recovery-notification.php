<?php
/**
 * 🚀 NEED2TALK ENTERPRISE GALAXY - ACCOUNT RECOVERY NOTIFICATION
 *
 * Template email responsive per notifica ripristino account
 * - Design coerente con brand need2talk (dark theme + deep purple gradient)
 * - Responsive per tutti i client email (Gmail, Outlook, Apple Mail, etc.)
 * - Ottimizzato per anti-spam e deliverability
 * - GDPR Article 17 compliance messaging
 * - Security-focused: IP address, recovery timestamp, 30-day policy
 *
 * @package need2talk/Lightning
 * @version 1.0.0
 * @author Claude Code (AI-Orchestrated Development)
 * @gdpr_compliant true
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
// $email - Email utente
// $recovery_method - Metodo recupero ('google_oauth', 'manual', etc.)
// $recovery_ip - IP address recupero (per security audit)
// $recovery_date - Data/ora recupero
// $deletion_requested_at - Data richiesta cancellazione originale
// $days_remaining - Giorni rimanenti prima hard delete (se non ripristinato)
// $companyName - Nome azienda (default: need2talk)
// $supportEmail - Email supporto

$companyName = $companyName ?? 'need2talk';
$supportEmail = $supportEmail ?? 'support@need2talk.it';
$recovery_method_label = match($recovery_method ?? 'google_oauth') {
    'google_oauth' => '🔐 Login con Google',
    'manual' => '🔗 Link di ripristino',
    'admin' => '👨‍💼 Intervento amministratore',
    default => '✅ Ripristino automatico',
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>🎉 Il tuo account è stato ripristinato! - <?= htmlspecialchars($companyName) ?></title>

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
        .card-text-red { color: #ef4444 !important; }
        .card-text-yellow { color: #fbbf24 !important; }
        .card-text-blue { color: #3b82f6 !important; }
        .card-text-purple { color: #a855f7 !important; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">

    <!-- Preheader (nascosto ma utile per preview) -->
    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
        🎉 Il tuo account <?= htmlspecialchars($companyName) ?> è stato ripristinato con successo! Bentornato nella community.
    </div>

    <!-- Container principale -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="email-body">
        <tr>
            <td style="padding: 20px 0;">

                <!-- Content Container - ENTERPRISE: Deep purple gradient background -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="container" style="margin: 0 auto; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);">

                    <!-- Header con Logo - SUCCESS THEME -->
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px 12px 0 0;">
                            <!-- Logo - ENTERPRISE: Brand logo integration -->
                            <div style="margin-bottom: 20px;">
                                <div class="logo" style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin: 0 auto;">
                                    <img src="<?= buildEmailUrl('assets/img/logo.png') ?>" alt="<?= htmlspecialchars($companyName) ?> Logo" style="width: 60px; height: 60px; border-radius: 12px; object-fit: contain;" />
                                </div>
                            </div>

                            <!-- Brand Name -->
                            <h1 style="color: white; font-size: 32px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                <?= htmlspecialchars($companyName) ?>
                            </h1>
                            <p style="color: rgba(255,255,255,0.95); margin: 8px 0 0; font-size: 18px; font-weight: 600;">
                                🎉 Account Ripristinato!
                            </p>
                        </td>
                    </tr>

                    <!-- Main Content - ENTERPRISE: Deep purple gradient background -->
                    <tr>
                        <td class="content" style="padding: 40px; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%) !important; color: #eeececff !important;">

                            <!-- Greeting -->
                            <h2 style="color: #eeececff; font-size: 26px; font-weight: bold; margin: 0 0 16px; text-align: center;" class="text-dark">
                                Bentornato, <?= htmlspecialchars($nickname) ?>! 👋
                            </h2>

                            <p style="color: #f6f6f6; font-size: 16px; margin: 0 0 24px; text-align: center;" class="text-muted">
                                Il tuo account <strong><?= htmlspecialchars($companyName) ?></strong> è stato <strong style="color: #10b981;">ripristinato con successo</strong>. La richiesta di cancellazione è stata annullata.
                            </p>

                            <!-- Recovery Success Card - ENTERPRISE: Green theme -->
                            <div style="background-color: rgba(16, 185, 129, 0.1); border-left: 4px solid #10b981; padding: 24px; margin: 24px 0; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.3);" class="content-card">
                                <h3 style="color: #10b981; font-size: 18px; font-weight: bold; margin: 0 0 16px; text-align: center;" class="card-text-green">
                                    ✅ Ripristino Completato
                                </h3>
                                <p style="color: #f6f6f6; font-size: 15px; margin: 0; text-align: center; line-height: 1.8;" class="text-muted">
                                    Il tuo account è di nuovo <strong>completamente attivo</strong>.<br>
                                    Tutti i tuoi dati, audio posts, amicizie e impostazioni sono stati preservati.
                                </p>
                            </div>

                            <!-- Recovery Details - ENTERPRISE: Dark theme with blue accents -->
                            <div style="background-color: #1a1a1a; border-left: 4px solid #3b82f6; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid #333;" class="content-card">
                                <h3 style="color: #3b82f6; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-blue">
                                    📋 Dettagli Ripristino
                                </h3>
                                <table style="width: 100%; border-collapse: collapse;">
                                    <tr>
                                        <td style="color: #c3cbd6ff; font-size: 14px; padding: 8px 0;" class="text-muted">
                                            <strong>Metodo:</strong>
                                        </td>
                                        <td style="color: #ffffff; font-size: 14px; padding: 8px 0; text-align: right;" class="card-text-white">
                                            <?= htmlspecialchars($recovery_method_label) ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color: #c3cbd6ff; font-size: 14px; padding: 8px 0; border-top: 1px solid #2a2a2a;" class="text-muted">
                                            <strong>Data ripristino:</strong>
                                        </td>
                                        <td style="color: #ffffff; font-size: 14px; padding: 8px 0; text-align: right; border-top: 1px solid #2a2a2a;" class="card-text-white">
                                            <?= htmlspecialchars($recovery_date ?? date('d/m/Y H:i')) ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color: #c3cbd6ff; font-size: 14px; padding: 8px 0; border-top: 1px solid #2a2a2a;" class="text-muted">
                                            <strong>Indirizzo IP:</strong>
                                        </td>
                                        <td style="color: #ffffff; font-size: 14px; padding: 8px 0; text-align: right; border-top: 1px solid #2a2a2a;" class="card-text-white">
                                            <?= htmlspecialchars($recovery_ip ?? 'Non disponibile') ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color: #c3cbd6ff; font-size: 14px; padding: 8px 0; border-top: 1px solid #2a2a2a;" class="text-muted">
                                            <strong>Cancellazione richiesta:</strong>
                                        </td>
                                        <td style="color: #fbbf24; font-size: 14px; padding: 8px 0; text-align: right; border-top: 1px solid #2a2a2a;" class="card-text-yellow">
                                            <?= htmlspecialchars($deletion_requested_at ?? 'N/A') ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- GDPR Policy Notice - ENTERPRISE: Purple theme -->
                            <div style="background-color: rgba(168, 85, 247, 0.1); border-left: 4px solid #a855f7; padding: 20px; margin: 24px 0; border-radius: 6px; border: 1px solid rgba(168, 85, 247, 0.3);" class="content-card">
                                <h3 style="color: #a855f7; font-size: 16px; font-weight: bold; margin: 0 0 12px;" class="card-text-purple">
                                    🔒 GDPR Article 17 - Policy Ripristino
                                </h3>
                                <p style="color: #c3cbd6ff; font-size: 14px; margin: 0 0 12px; line-height: 1.7;" class="text-muted">
                                    In conformità al <strong>GDPR Article 17</strong> ("diritto all'oblio"), quando un utente richiede la cancellazione dell'account, i suoi dati vengono <strong>soft-deleted</strong> con un <strong>grace period di 30 giorni</strong>.
                                </p>
                                <p style="color: #c3cbd6ff; font-size: 14px; margin: 0; line-height: 1.7;" class="text-muted">
                                    Hai utilizzato questo grace period per <strong>ripristinare il tuo account</strong>. Puoi ora richiedere nuovamente la cancellazione in qualsiasi momento, ma dovrai aspettare un altro periodo di 30 giorni prima dell'eliminazione definitiva.
                                </p>
                            </div>

                            <!-- CTA Button - Access your account -->
                            <div style="text-align: center; margin: 32px 0;">
                                <a href="<?= buildEmailUrl('') ?>" class="button" style="display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3); transition: transform 0.2s;">
                                    🚀 Accedi al tuo Account
                                </a>
                            </div>

                            <!-- Security Notice - ENTERPRISE: Yellow warning theme -->
                            <div style="background-color: rgba(251, 191, 36, 0.1); border-left: 4px solid #fbbf24; padding: 16px; margin: 24px 0; border-radius: 6px; border: 1px solid rgba(251, 191, 36, 0.3);">
                                <p style="color: #fbbf24; font-size: 14px; margin: 0 0 8px; font-weight: 600;">
                                    ⚠️ Non sei stato tu?
                                </p>
                                <p style="color: #c3cbd6ff; font-size: 13px; margin: 0; line-height: 1.6;" class="text-muted">
                                    Se non hai richiesto il ripristino dell'account, contattaci immediatamente a <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: #fbbf24; text-decoration: underline;"><?= htmlspecialchars($supportEmail) ?></a>. Il tuo account potrebbe essere compromesso.
                                </p>
                            </div>

                            <!-- Divider -->
                            <div style="border-top: 1px solid #333; margin: 32px 0;"></div>

                            <!-- Footer Message -->
                            <p style="color: #c3cbd6ff; font-size: 14px; text-align: center; margin: 0;" class="text-muted">
                                Grazie per essere parte della community <strong><?= htmlspecialchars($companyName) ?></strong>! 💜
                            </p>

                        </td>
                    </tr>

                    <!-- Footer - ENTERPRISE: Dark theme -->
                    <tr>
                        <td style="padding: 32px 40px; background-color: #0a0a0a; border-radius: 0 0 12px 12px; text-align: center;">
                            <p style="color: #9ca3af; font-size: 12px; margin: 0 0 8px;">
                                <strong><?= htmlspecialchars($companyName) ?></strong> - La tua voce conta
                            </p>
                            <p style="color: #6b7280; font-size: 11px; margin: 0 0 12px;">
                                Questa è un'email di notifica automatica inviata dal sistema <strong><?= htmlspecialchars($companyName) ?></strong>.<br>
                                Non rispondere a questa email. Per supporto, scrivi a <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color: #8b5cf6; text-decoration: none;"><?= htmlspecialchars($supportEmail) ?></a>.
                            </p>
                            <p style="color: #6b7280; font-size: 10px; margin: 0;">
                                © <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Tutti i diritti riservati.<br>
                                <a href="<?= buildEmailUrl('privacy') ?>" style="color: #8b5cf6; text-decoration: none;">Privacy Policy</a> |
                                <a href="<?= buildEmailUrl('terms') ?>" style="color: #8b5cf6; text-decoration: none;">Terms of Service</a>
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
