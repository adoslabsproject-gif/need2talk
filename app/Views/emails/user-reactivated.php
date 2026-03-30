<?php
/**
 * NEED2TALK - USER REACTIVATED EMAIL TEMPLATE
 *
 * Email inviata quando l'admin riattiva un account sospeso/bannato.
 * - Notifica all'utente che l'account è stato riattivato
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

$nickname = $nickname ?? 'Utente';
$siteUrl = rtrim($_ENV['APP_URL'] ?? 'https://need2talk.it', '/');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Account Riattivato - need2talk</title>
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
            box-shadow: 0 20px 60px rgba(34, 197, 94, 0.15);
        }

        .email-header {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            padding: 40px 30px;
            text-align: center;
            border-bottom: 2px solid rgba(34, 197, 94, 0.3);
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
            color: #86efac;
            margin: 0 0 20px 0;
        }

        .email-body p {
            margin: 0 0 16px 0;
            font-size: 16px;
            color: #d1d5db;
            line-height: 1.7;
        }

        .success-box {
            background: rgba(34, 197, 94, 0.15);
            border-left: 4px solid #22c55e;
            padding: 20px;
            margin: 30px 0;
            border-radius: 8px;
        }

        .success-box h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #86efac;
            font-weight: 600;
        }

        .success-box p {
            margin: 0;
            color: #bbf7d0;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: #ffffff !important;
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
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
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
                <span class="emoji">✅</span>
                <h1>Account Riattivato!</h1>
            </div>

            <!-- Body -->
            <div class="email-body">
                <h2>Bentornato <?= htmlspecialchars($nickname) ?>!</h2>

                <p>Siamo lieti di informarti che il tuo account <strong><?= htmlspecialchars($email) ?></strong> su need2talk è stato <strong>riattivato</strong>.</p>

                <div class="success-box">
                    <h3>🎉 Accesso Ripristinato</h3>
                    <p>Puoi accedere nuovamente alla piattaforma e utilizzare tutte le funzionalità. Ti aspettiamo!</p>
                </div>

                <p>Siamo felici di riaverti con noi. Se hai domande o hai bisogno di assistenza, non esitare a contattarci.</p>

                <center>
                    <a href="<?= htmlspecialchars($siteUrl) ?>/auth/login" class="cta-button" style="color: #ffffff !important; text-decoration: none;">
                        🚀 Accedi Ora
                    </a>
                </center>
            </div>

            <!-- Footer -->
            <div class="email-footer">
                <p><strong>need2talk</strong> - Il tuo spazio per esprimere emozioni</p>
                <p>Supporto: <a href="mailto:support@need2talk.it">support@need2talk.it</a></p>
                <p style="margin-top: 20px; font-size: 12px; color: #6b7280;">
                    Questa è un'email automatica di notifica. Per favore non rispondere a questo messaggio.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
