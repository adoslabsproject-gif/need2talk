<?php
/**
 * NEED2TALK - USER BANNED EMAIL TEMPLATE
 *
 * Email inviata quando l'admin banna permanentemente un utente.
 * - Notifica all'utente che l'account è stato bannato
 * - Invito a contattare support@need2talk.it per informazioni
 * - Design enterprise coerente con brand need2talk
 * - Responsive per tutti i client email
 *
 * @package Need2Talk\Views\Emails
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Variabili disponibili:
// $nickname - Nome utente (opzionale, default: "Utente")
// $email - Email utente
// $reason - Motivo del ban (opzionale)

$nickname = $nickname ?? 'Utente';
$reason = $reason ?? null;
$supportEmail = 'support@need2talk.it';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Account Bannato - need2talk</title>
    <style>
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

        .danger-box {
            background: rgba(239, 68, 68, 0.15);
            border-left: 4px solid #ef4444;
            padding: 20px;
            margin: 30px 0;
            border-radius: 8px;
        }

        .danger-box h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #fca5a5;
            font-weight: 600;
        }

        .danger-box p {
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
            color: #ffffff !important;
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
                <span class="emoji">🚫</span>
                <h1>Account Bannato</h1>
            </div>

            <!-- Body -->
            <div class="email-body">
                <h2>Ciao <?= htmlspecialchars($nickname) ?>,</h2>

                <p>Ti informiamo che il tuo account <strong><?= htmlspecialchars($email) ?></strong> su need2talk è stato <strong>bannato permanentemente</strong>.</p>

                <div class="danger-box">
                    <h3>⛔ Accesso Permanentemente Bloccato</h3>
                    <p>Il tuo account è stato disabilitato in modo permanente. Non potrai più accedere alla piattaforma con questo account.</p>
                </div>

                <?php if ($reason && $reason !== 'Banned by administrator'): ?>
                <div class="danger-box">
                    <h3>📋 Motivo</h3>
                    <p><?= htmlspecialchars($reason) ?></p>
                </div>
                <?php endif; ?>

                <div class="info-box">
                    <h3>📧 Hai Bisogno di Assistenza?</h3>
                    <p>Se ritieni che questo ban sia un errore o desideri maggiori informazioni, puoi contattare il nostro team di supporto.</p>
                    <p style="margin-top: 15px;">Email: <strong><?= htmlspecialchars($supportEmail) ?></strong></p>
                </div>

                <p>Ti ricordiamo che le violazioni dei termini di servizio possono comportare il ban permanente dell'account.</p>

                <center>
                    <a href="mailto:<?= htmlspecialchars($supportEmail) ?>?subject=Richiesta%20Informazioni%20Ban%20Account%20-%20<?= urlencode($email) ?>" class="cta-button" style="color: #ffffff !important; text-decoration: none;">
                        📧 Contatta il Supporto
                    </a>
                </center>
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
