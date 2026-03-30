<?php
/**
 * NEED2TALK - MODERATOR WELCOME EMAIL
 *
 * Email inviata al moderatore alla creazione dell'account
 * Contiene credenziali di accesso (password in chiaro - da cambiare dopo primo login)
 *
 * ENTERPRISE: Dark theme con accenti fucsia (branding moderazione)
 */

if (!function_exists('buildEmailUrl')) {
    function buildEmailUrl($path = '')
    {
        $base = $_ENV['APP_URL'] ?? null;
        if (!$base) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || ($_SERVER['SERVER_PORT'] ?? 80) === 443 ? 'https' : 'http';
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
// $displayName - Nome visualizzato moderatore
// $username - Username moderatore
// $email - Email moderatore
// $password - Password in chiaro (da comunicare)
// $portalUrl - URL del portale moderazione
// $permissions - Array dei permessi assegnati

$companyName = 'need2talk';
$supportEmail = 'support@need2talk.it';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Benvenuto nel Team Moderazione - <?= htmlspecialchars($companyName) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 20px !important; }
            .content { padding: 30px 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">

    <div style="display: none; font-size: 1px; color: #fefefe; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
        Sei stato nominato Moderatore di <?= htmlspecialchars($companyName) ?>! Ecco le tue credenziali di accesso.
    </div>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
        <tr>
            <td style="padding: 20px 0;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="container" style="margin: 0 auto; background: linear-gradient(135deg, #1a0a1a 0%, #2d1b3d 50%, #0f0f0f 100%); border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);">

                    <!-- Header con Logo -->
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #d946ef 0%, #a21caf 100%); border-radius: 12px 12px 0 0;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                                <tr>
                                    <td align="center">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 16px;">
                                            <tr>
                                                <td align="center" valign="middle" style="padding: 10px;">
                                                    <img src="<?= buildEmailUrl('assets/img/logo.png') ?>" alt="<?= htmlspecialchars($companyName) ?> Logo" width="60" height="60" style="display: block; width: 60px; height: 60px; border-radius: 12px; object-fit: contain; margin: 0 auto;" />
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <h1 style="color: white; font-size: 28px; font-weight: bold; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                🛡️ Benvenuto nel Team Moderazione!
                            </h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 8px 0 0; font-size: 16px;">
                                Sei stato nominato Moderatore di <?= htmlspecialchars($companyName) ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Main Content -->
                    <tr>
                        <td class="content" style="padding: 40px; background: linear-gradient(135deg, #1a0a1a 0%, #2d1b3d 50%, #0f0f0f 100%); color: #ffffff;">

                            <h2 style="color: #ffffff; font-size: 24px; font-weight: bold; margin: 0 0 16px; text-align: center;">
                                Ciao <?= htmlspecialchars($displayName) ?>! 👋
                            </h2>

                            <p style="color: #e5e7eb; font-size: 16px; margin: 0 0 24px; text-align: center;">
                                Congratulazioni! Sei stato selezionato come <strong style="color: #d946ef;">Moderatore</strong> della community di <?= htmlspecialchars($companyName) ?>.
                            </p>

                            <!-- Credenziali Box -->
                            <div style="background-color: #1f1f1f; border: 2px solid #d946ef; padding: 24px; margin: 24px 0; border-radius: 12px;">
                                <h3 style="color: #d946ef; font-size: 18px; font-weight: bold; margin: 0 0 16px; text-align: center;">
                                    🔐 Le tue Credenziali di Accesso
                                </h3>

                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td style="padding: 8px 0; color: #9ca3af; font-size: 14px;">Email:</td>
                                        <td style="padding: 8px 0; color: #ffffff; font-size: 14px; font-family: monospace; font-weight: bold;"><?= htmlspecialchars($email) ?></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; color: #9ca3af; font-size: 14px;">Password:</td>
                                        <td style="padding: 8px 0; color: #10b981; font-size: 16px; font-family: monospace; font-weight: bold; letter-spacing: 1px;"><?= htmlspecialchars($password) ?></td>
                                    </tr>
                                </table>

                                <p style="color: #fcd34d; font-size: 12px; margin: 16px 0 0; text-align: center; padding: 10px; background: rgba(251, 191, 36, 0.1); border-radius: 6px;">
                                    ⚠️ <strong>IMPORTANTE:</strong> Salva questa password in un luogo sicuro e cancella questa email dopo averla annotata.
                                </p>
                            </div>

                            <!-- Portal URL -->
                            <div style="background-color: #1f1f1f; border-left: 4px solid #d946ef; padding: 20px; margin: 24px 0; border-radius: 6px;">
                                <h3 style="color: #d946ef; font-size: 16px; font-weight: bold; margin: 0 0 12px;">
                                    🌐 Accesso al Portale Moderazione
                                </h3>
                                <p style="color: #e5e7eb; font-size: 14px; margin: 0 0 12px;">
                                    Accedi al portale moderazione usando questo link:
                                </p>
                                <a href="<?= htmlspecialchars(buildEmailUrl(ltrim($portalUrl ?? '', '/'))) ?>/login"
                                   style="display: block; color: #d946ef; font-size: 14px; font-family: monospace; word-break: break-all; text-decoration: none; padding: 10px; background: rgba(217, 70, 239, 0.1); border-radius: 6px;">
                                    <?= htmlspecialchars(buildEmailUrl(ltrim($portalUrl ?? '', '/')) . '/login') ?>
                                </a>
                                <p style="color: #9ca3af; font-size: 12px; margin: 12px 0 0;">
                                    Nota: L'URL del portale cambia ogni ora per sicurezza. Richiedi sempre l'URL aggiornato se necessario.
                                </p>
                            </div>

                            <!-- Permessi -->
                            <?php if (!empty($permissions)): ?>
                            <div style="background-color: #1f1f1f; border-left: 4px solid #10b981; padding: 20px; margin: 24px 0; border-radius: 6px;">
                                <h3 style="color: #10b981; font-size: 16px; font-weight: bold; margin: 0 0 12px;">
                                    ✅ I tuoi Permessi
                                </h3>
                                <ul style="color: #ffffff; font-size: 14px; margin: 0; padding-left: 20px;">
                                    <?php foreach ($permissions as $perm): ?>
                                    <li style="margin-bottom: 6px;"><?= htmlspecialchars($perm) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <!-- CTA Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 32px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="<?= htmlspecialchars(buildEmailUrl(ltrim($portalUrl ?? '', '/'))) ?>/login"
                                           style="display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, #d946ef 0%, #a21caf 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 6px rgba(217, 70, 239, 0.3);">
                                            🚀 Accedi al Portale Moderazione
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Security Note -->
                            <div style="background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); padding: 16px; margin: 24px 0; border-radius: 6px;">
                                <p style="color: #fca5a5; font-size: 13px; margin: 0; text-align: center;">
                                    🔒 <strong>Sicurezza:</strong> Al primo accesso dovrai verificare la tua identità tramite codice 2FA inviato a questa email.
                                    Non condividere mai le tue credenziali con nessuno.
                                </p>
                            </div>

                            <p style="color: #9ca3af; font-size: 14px; margin: 32px 0 0; text-align: center;">
                                Grazie per far parte del team! 💜<br>
                                - Il Team di <?= htmlspecialchars($companyName) ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background: linear-gradient(135deg, #0f0f0f 0%, #1a0a1a 100%); border-radius: 0 0 12px 12px; border-top: 1px solid #333;">
                            <div style="text-align: center;">
                                <p style="color: #9ca3af; font-size: 12px; margin: 0;">
                                    🔒 Email riservata - Portale Moderazione <?= htmlspecialchars($companyName) ?>
                                </p>
                                <p style="color: #6b7280; font-size: 11px; margin: 10px 0 0;">
                                    © <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Tutti i diritti riservati.
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
