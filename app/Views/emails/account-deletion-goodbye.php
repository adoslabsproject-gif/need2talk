<?php
/**
 * NEED2TALK - ACCOUNT DELETION GOODBYE EMAIL TEMPLATE
 *
 * Email di addio inviata IMMEDIATAMENTE dopo la richiesta di cancellazione account
 * - Conferma la richiesta di cancellazione
 * - Spiega il periodo di grazia di 30 giorni
 * - Fornisce link per cancellare la richiesta
 * - Design enterprise coerente con brand need2talk
 * - Responsive per tutti i client email
 * - Accessibile e user-friendly
 *
 * GDPR COMPLIANCE:
 * - Article 17: Right to be Forgotten
 * - Article 12: Transparent communication
 * - 30-day grace period for account recovery
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
// $scheduled_deletion_at - Data cancellazione programmata (Y-m-d H:i:s)
// $reason - Motivo cancellazione (opzionale)
// $companyName - Nome azienda (default: need2talk)
// $supportEmail - Email supporto

$companyName = $companyName ?? 'need2talk';
$supportEmail = $supportEmail ?? 'support@need2talk.it';

// Format deletion date (ENTERPRISE: Italian locale)
$deletionDate = date('d/m/Y', strtotime($scheduled_deletion_at));
$loginUrl = buildEmailUrl('login');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Richiesta di Cancellazione Account - <?= htmlspecialchars($companyName) ?></title>
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
            box-shadow: 0 20px 60px rgba(139, 92, 246, 0.15);
        }

        .email-header {
            background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
            padding: 40px 30px;
            text-align: center;
            border-bottom: 2px solid rgba(139, 92, 246, 0.3);
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
            color: #a78bfa;
            margin: 0 0 20px 0;
        }

        .email-body p {
            margin: 0 0 16px 0;
            font-size: 16px;
            color: #d1d5db;
            line-height: 1.7;
        }

        .warning-box {
            background: rgba(239, 68, 68, 0.1);
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
            background: rgba(139, 92, 246, 0.1);
            border-left: 4px solid #8b5cf6;
            padding: 20px;
            margin: 30px 0;
            border-radius: 8px;
        }

        .info-box h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #c4b5fd;
            font-weight: 600;
        }

        .info-box p {
            margin: 0;
            color: #d8b4fe;
        }

        .info-box strong {
            color: #e9d5ff;
        }

        .deletion-details {
            background: rgba(31, 41, 55, 0.5);
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
        }

        .deletion-details ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .deletion-details li {
            padding: 10px 0;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
            font-size: 15px;
        }

        .deletion-details li:last-child {
            border-bottom: none;
        }

        .deletion-details strong {
            color: #a78bfa;
            font-weight: 600;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #8b5cf6 0%, #a855f7 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            margin: 30px 0 20px 0;
            box-shadow: 0 10px 25px rgba(139, 92, 246, 0.3);
            transition: all 0.3s ease;
        }

        .cta-button:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%);
            box-shadow: 0 15px 35px rgba(139, 92, 246, 0.4);
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
                <span class="emoji">👋</span>
                <h1>Richiesta di Cancellazione Account</h1>
            </div>

            <!-- Body -->
            <div class="email-body">
                <h2>Ciao <?= htmlspecialchars($nickname) ?>,</h2>

                <p>Abbiamo ricevuto la tua richiesta di cancellazione dell'account <strong><?= htmlspecialchars($email) ?></strong> su <?= htmlspecialchars($companyName) ?>.</p>

                <div class="warning-box">
                    <h3>⚠️ Account Sospeso</h3>
                    <p>Il tuo account è stato <strong>sospeso immediatamente</strong> e non è più accessibile. Non potrai più accedere alla piattaforma.</p>
                </div>

                <div class="info-box">
                    <h3>🕐 Periodo di Grazia: 30 Giorni</h3>
                    <p>La cancellazione definitiva dei tuoi dati avverrà il <strong><?= htmlspecialchars($deletionDate) ?></strong>.</p>
                    <p style="margin-top: 10px;">Durante questo periodo puoi <strong>cancellare la richiesta</strong> e recuperare il tuo account.</p>
                </div>

                <div class="deletion-details">
                    <ul>
                        <li><strong>📧 Email:</strong> <?= htmlspecialchars($email) ?></li>
                        <li><strong>👤 Nickname:</strong> <?= htmlspecialchars($nickname) ?></li>
                        <li><strong>📅 Cancellazione Programmata:</strong> <?= htmlspecialchars($deletionDate) ?></li>
                        <?php if (!empty($reason)): ?>
                        <li><strong>📝 Motivo:</strong> <?= htmlspecialchars($reason) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <h3 style="color: #c4b5fd; font-size: 18px; margin: 30px 0 15px 0;">🗑️ Cosa verrà cancellato:</h3>
                <p>Dopo 30 giorni, cancelleremo <strong>definitivamente e irreversibilmente</strong>:</p>
                <ul style="color: #d1d5db; line-height: 1.8; margin: 0; padding-left: 25px;">
                    <li>Il tuo profilo utente</li>
                    <li>Tutti i tuoi post e audio</li>
                    <li>Le tue amicizie e connessioni</li>
                    <li>Le tue reazioni e commenti</li>
                    <li>Tutte le impostazioni e preferenze</li>
                </ul>

                <h3 style="color: #fca5a5; font-size: 18px; margin: 30px 0 15px 0;">💔 Hai Cambiato Idea?</h3>
                <p>Se hai cancellato il tuo account per errore o hai cambiato idea, puoi <strong>recuperarlo entro 30 giorni</strong>.</p>

                <center>
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="cta-button">
                        🔄 Recupera il Tuo Account
                    </a>
                </center>

                <p style="font-size: 14px; color: #9ca3af; text-align: center; margin-top: 10px;">
                    Fai login e cancella la richiesta di eliminazione dalla pagina impostazioni
                </p>

                <div style="margin-top: 40px; padding-top: 30px; border-top: 1px solid rgba(139, 92, 246, 0.2);">
                    <h3 style="color: #c4b5fd; font-size: 18px; margin: 0 0 15px 0;">📊 Esporta i Tuoi Dati</h3>
                    <p>Puoi <strong>scaricare una copia completa</strong> dei tuoi dati in qualsiasi momento prima della cancellazione definitiva.</p>
                    <p style="font-size: 14px; color: #9ca3af; margin: 0;">
                        (Login → Impostazioni → Esportazione Dati)
                    </p>
                </div>

                <p style="margin-top: 30px; font-size: 14px; color: #9ca3af;">
                    Grazie per aver fatto parte della community <?= htmlspecialchars($companyName) ?>. Ci mancherai! 💜
                </p>
            </div>

            <!-- Footer -->
            <div class="email-footer">
                <p><strong><?= htmlspecialchars($companyName) ?></strong> - Il tuo spazio per esprimere emozioni</p>
                <p>Hai bisogno di aiuto? <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"><?= htmlspecialchars($supportEmail) ?></a></p>
                <p style="margin-top: 20px; font-size: 12px; color: #6b7280;">
                    Questa è un'email automatica di conferma. Per favore non rispondere a questo messaggio.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
