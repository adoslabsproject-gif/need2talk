<?php

namespace Need2Talk\Jobs\Admin;

use Need2Talk\Services\Logger;

/**
 * Enterprise 2FA Email Job - Uses existing queue system
 * Integrates with Redis queue for high-performance email delivery
 */
class SendTwoFactorEmailJob
{
    public $user;

    public $code;

    public $ipAddress;

    public $userAgent;

    public function __construct($user, string $code, string $ipAddress, string $userAgent)
    {
        $this->user = $user;
        $this->code = $code;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        // Send 2FA email directly (this IS the queued job)
        $this->send2FAEmail();
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Logger::error('SECURITY: Admin 2FA email job failed', [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Optionally: Send admin notification about failed 2FA email
        // This could alert super admins about critical security email failures
    }

    /**
     * Send the 2FA email directly
     */
    private function send2FAEmail(): void
    {
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : 'need2talk.test';
        $environment = $_ENV['APP_ENV'] ?? 'development';
        $envLabel = $environment === 'production' ? '' : ' [' . strtoupper($environment) . ']';
        $currentTimestamp = date('d/m/Y H:i:s');

        $subject = '🔐 need2talk Admin' . $envLabel . ' - Codice 2FA';

        $message = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #8B5CF6, #3B82F6); padding: 30px; text-align: center; color: white; border-radius: 12px 12px 0 0;'>
                    <h1 style='margin: 0; font-size: 28px;'>🔐 need2talk Admin</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Autenticazione a Due Fattori</p>
                </div>

                <div style='background: #ffffff; padding: 40px; border-radius: 0 0 12px 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #374151; margin-bottom: 20px;'>
                        Ciao <strong>{$this->user->name}</strong>,
                    </p>

                    <p style='color: #6B7280; margin-bottom: 25px;'>
                        È stato richiesto l'accesso al pannello admin. Inserisci il codice seguente per completare l'autenticazione.
                    </p>

                    <div style='background: #F8FAFC; border: 2px solid #E5E7EB; border-radius: 8px; padding: 30px; margin: 25px 0; text-align: center;'>
                        <h3 style='color: #374151; margin: 0 0 20px 0; font-size: 18px;'>🔑 Il tuo codice 2FA:</h3>
                        <div style='background: linear-gradient(135deg, #8B5CF6, #3B82F6); color: white; padding: 20px; border-radius: 8px; font-family: monospace; font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center; margin: 0 auto; max-width: 300px;'>
                            {$this->code}
                        </div>
                    </div>

                    <div style='background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 25px 0; border-radius: 0 6px 6px 0;'>
                        <h4 style='color: #92400E; margin: 0 0 10px 0;'>⚠️ Importante:</h4>
                        <ul style='color: #92400E; margin: 0; padding-left: 20px;'>
                            <li>Questo codice scade in <strong>5 minuti</strong></li>
                            <li>Utilizzabile una sola volta</li>
                            <li>Se non hai richiesto l'accesso, ignora questa email</li>
                        </ul>
                    </div>

                    <div style='background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h4 style='color: #166534; margin: 0 0 15px 0;'>🛡️ Dettagli Accesso:</h4>
                        <table style='width: 100%; font-family: monospace; font-size: 14px;'>
                            <tr>
                                <td style='padding: 4px 0; color: #6B7280; width: 30%;'><strong>Timestamp:</strong></td>
                                <td style='padding: 4px 0; color: #1F2937;'>{$currentTimestamp}</td>
                            </tr>
                            <tr>
                                <td style='padding: 4px 0; color: #6B7280;'><strong>Indirizzo IP:</strong></td>
                                <td style='padding: 4px 0; color: #1F2937; word-break: break-all;'>{$this->ipAddress}</td>
                            </tr>
                            <tr>
                                <td style='padding: 4px 0; color: #6B7280;'><strong>Sistema:</strong></td>
                                <td style='padding: 4px 0; color: #1F2937;'>{$domain}</td>
                            </tr>
                        </table>
                    </div>

                    <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB;'>
                        <p style='color: #9CA3AF; font-size: 12px; margin: 0;'>
                            Generato automaticamente il {$currentTimestamp}<br>
                            need2talk Enterprise Security System
                        </p>
                    </div>
                </div>
            </div>
        ";

        $emailService = new \Need2Talk\Services\EmailService();
        $emailService->send($this->user->email, $subject, $message);

        // Log successful email to SECURITY channel (admin access tracking)
        Logger::security('info', '2FA email sent successfully', [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'ip' => $this->ipAddress,
        ]);
    }
}
