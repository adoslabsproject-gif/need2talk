<?php
/**
 * NEED2TALK - ACCOUNT SUSPENSION EMAIL TEMPLATE (3RD DELETION)
 *
 * Email inviata quando l'utente richiede la 3a cancellazione in 30 giorni.
 * - Account SOSPESO (login bloccato)
 * - Richiesta contatto support@need2talk.it per riattivazione
 * - Hard delete dopo 7 giorni se non contatta supporto
 * - Design enterprise coerente con brand need2talk
 * - Responsive per tutti i client email
 *
 * GDPR COMPLIANCE:
 * - Article 17.3: Legitimate Interest (Prevenzione abusi)
 * - Article 12: Transparent communication
 * - 7-day grace period for manual review
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
// $hard_delete_deadline - Data cancellazione hard (Y-m-d H:i:s)
// $support_email - Email supporto (default: support@need2talk.it)
// $recent_deletions - Numero cancellazioni recenti (default: 3)
// $reason - Motivo cancellazione (opzionale)

$supportEmail = $supportEmail ?? 'support@need2talk.it';
$recentDeletions = $recentDeletions ?? 3;

// Format deadline date (ENTERPRISE: Italian locale)
$deadlineDate = date('d/m/Y', strtotime($hard_delete_deadline));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Account Sospeso - <?= htmlspecialchars('need2talk') ?></title>
    <style nonce="<?= csp_nonce() ?>">
        /* ENTERPRISE EMAIL STYLES - Responsive & Dark Theme */
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #0a0a0f;
            color: #e5e7eb;
            line-height: 1.6;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: linear-gradient(135deg, #1a1625 0%, #2a1b3d 100%);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(239, 68, 68, 0.15);
        }

        .email-header {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            padding: 40px 30px;
            text-align: center;
            border-bottom: 2px solid rgba(239, 68, 68, 0.3);
        }

        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .email-header .emoji {
            font-size: 48px;
            margin-bottom: 10px;
            display: block;
        }

        .email-body {
            padding: 40px 30px;
        }

        .email-body h2 {
            font-size: 22px;
            font-weight: 600;
            color: #fca5a5;
            margin: 0 0 20px 0;
        }

        .email-body p {
            margin: 0 0 16px 0;
            font-size: 16px;
            color: #d1d5db;
            line-height: 1.7;
        }

        .warning-box {
            background: rgba(239, 68, 68, 0.15);
            border-left: 4px solid #ef4444;
            padding: 20px;
            margin: 30px 0;
            border-radius: 8px;
        }

        .warning-box h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #fca5a5;
            font-weight: 600;
        }

        .warning-box p {
            margin: 0;
            color: #fecaca;
        }

        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid #3b82f6;
            padding: 20px;
            margin: 30px 0;
            border-radius: 8px;
        }

        .info-box h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #93c5fd;
            font-weight: 600;
        }

        .info-box p {
            margin: 0;
            color: #bfdbfe;
        }

        .info-box strong {
            color: #dbeafe;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            margin: 30px 0 20px 0;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
            transition: all 0.3s ease;
        }

        .cta-button:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 15px 35px rgba(59, 130, 246, 0.4);
        }

        .email-footer {
            background: rgba(31, 41, 55, 0.5);
            padding: 30px;
            text-align: center;
            border-top: 1px solid rgba(139, 92, 246, 0.2);
        }

        .email-footer p {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #9ca3af;
        }

        .email-footer a {
            color: #a78bfa;
            text-decoration: none;
            font-weight: 500;
        }

        .email-footer a:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }

        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-container {
                border-radius: 0;
            }

            .email-header, .email-body, .email-footer {
                padding: 30px 20px;
            }

            .email-header h1 {
                font-size: 24px;
            }

            .cta-button {
                display: block;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div style="padding: 40px 20px;">
        <div class="email-container">
            <!-- Header -->
            <div class="email-header">
                <span class="emoji">⚠️</span>
                <h1>Account Sospeso</h1>
            </div>

            <!-- Body -->
            <div class="email-body">
                <h2>Ciao <?= htmlspecialchars($nickname) ?>,</h2>

                <p>Il tuo account <strong><?= htmlspecialchars($email) ?></strong> su need2talk è stato <strong>sospeso temporaneamente</strong> a causa di <strong>ripetute cancellazioni</strong> (<?= (int)$recentDeletions ?> volte in 30 giorni).</p>

                <div class="warning-box">
                    <h3>🚫 Account Bloccato</h3>
                    <p><strong>Non puoi più accedere</strong> al tuo account. Il login è stato disabilitato per prevenire abusi del sistema.</p>
                </div>

                <div class="info-box">
                    <h3>📧 Vuoi Riattivare il Tuo Account?</h3>
                    <p><strong>Contatta il nostro supporto entro il <?= htmlspecialchars($deadlineDate) ?></strong> per richiedere la riattivazione del tuo account.</p>
                    <p style="margin-top: 15px;">Invia una email a: <strong><?= htmlspecialchars($supportEmail) ?></strong></p>
                    <p style="margin-top: 10px; font-size: 14px;">Includi nella email il tuo indirizzo email (<?= htmlspecialchars($email) ?>) e spiega il motivo per cui vuoi riattivare l'account.</p>
                </div>

                <h3 style="color: #fca5a5; font-size: 18px; margin: 30px 0 15px 0;">⏰ Cosa Succede Se Non Contatti il Supporto?</h3>
                <p>Se <strong>non contatterai il supporto entro il <?= htmlspecialchars($deadlineDate) ?></strong>, il tuo account e tutti i dati associati saranno <strong>cancellati definitivamente e irreversibilmente</strong>:</p>
                <ul style="color: #d1d5db; line-height: 1.8; margin: 0; padding-left: 25px;">
                    <li>Il tuo profilo utente</li>
                    <li>Tutti i tuoi post e audio</li>
                    <li>Le tue amicizie e connessioni</li>
                    <li>Le tue reazioni e commenti</li>
                    <li>Tutte le impostazioni e preferenze</li>
                </ul>

                <h3 style="color: #93c5fd; font-size: 18px; margin: 30px 0 15px 0;">❓ Perché è Successo?</h3>
                <p>Hai richiesto la cancellazione del tuo account <strong><?= (int)$recentDeletions ?> volte in 30 giorni</strong>. Per prevenire abusi del sistema e proteggere le risorse del servizio, dopo 3 cancellazioni è richiesta una <strong>verifica manuale</strong> da parte del team di supporto.</p>

                <p style="margin-top: 30px; font-size: 14px; color: #9ca3af;">Questa misura è conforme al <strong>GDPR Art. 17.3</strong> (Legitimate Interest - Prevenzione Abusi).</p>

                <center>
                    <a href="mailto:<?= htmlspecialchars($supportEmail) ?>?subject=Richiesta%20Riattivazione%20Account%20-%20<?= urlencode($email) ?>" class="cta-button">
                        📧 Contatta il Supporto
                    </a>
                </center>

                <p style="margin-top: 30px; font-size: 14px; color: #9ca3af; text-align: center;">
                    Deadline per contattare il supporto: <strong><?= htmlspecialchars($deadlineDate) ?></strong>
                </p>
            </div>

            <!-- Footer -->
            <div class="email-footer">
                <p><strong>need2talk</strong> - Il tuo spazio per esprimere emozioni</p>
                <p>Supporto: <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"><?= htmlspecialchars($supportEmail) ?></a></p>
                <p style="margin-top: 20px; font-size: 12px; color: #6b7280;">
                    Questa è un'email automatica di notifica. Per favore non rispondere a questo messaggio.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
