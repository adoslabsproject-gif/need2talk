<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Newsletter Unsubscribe Controller
 *
 * ENTERPRISE GALAXY: GDPR-compliant one-click unsubscribe system
 * Handles newsletter opt-out via unique token
 *
 * SECURITY: Token-based authentication, no login required
 */
class NewsletterUnsubscribeController extends BaseController
{
    /**
     * Show unsubscribe confirmation page
     * GET /newsletter/unsubscribe/{token}
     */
    public function showUnsubscribe(string $token): void
    {
        try {
            // Validate token format
            if (strlen($token) !== 64 || !ctype_xdigit($token)) {
                $this->renderError('Invalid unsubscribe token');

                return;
            }

            // Get user by token
            $pdo = $this->getFreshPDO();
            $stmt = $pdo->prepare("
                SELECT id, email, nickname, newsletter_opt_in
                FROM users
                WHERE newsletter_unsubscribe_token = :token
                  AND deleted_at IS NULL
            ");
            $stmt->execute(['token' => $token]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                $this->renderError('Unsubscribe link expired or invalid');

                return;
            }

            // Check if already unsubscribed
            $alreadyUnsubscribed = ($user['newsletter_opt_in'] == 0);

            // Render confirmation page
            $this->renderUnsubscribePage($user, $token, $alreadyUnsubscribed);

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterUnsubscribe: Show page error', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 8) . '...',
            ]);

            $this->renderError('An error occurred. Please try again later.');
        }
    }

    /**
     * Process unsubscribe request
     * POST /newsletter/unsubscribe/{token}
     */
    public function processUnsubscribe(string $token): void
    {
        try {
            // Validate token
            if (strlen($token) !== 64 || !ctype_xdigit($token)) {
                $this->json(['success' => false, 'message' => 'Invalid token'], 400);

                return;
            }

            // Get optional reason
            $reason = trim($_POST['reason'] ?? '');

            // Get user
            $pdo = $this->getFreshPDO();
            $stmt = $pdo->prepare("
                SELECT id, email, nickname, newsletter_opt_in
                FROM users
                WHERE newsletter_unsubscribe_token = :token
                  AND deleted_at IS NULL
            ");
            $stmt->execute(['token' => $token]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                $this->json(['success' => false, 'message' => 'Invalid or expired token'], 404);

                return;
            }

            // Check if already unsubscribed
            if ($user['newsletter_opt_in'] == 0) {
                $this->json([
                    'success' => true,
                    'message' => 'You are already unsubscribed from our newsletters',
                    'already_unsubscribed' => true,
                ]);

                return;
            }

            // Update user - opt out from newsletter
            $stmt = $pdo->prepare("
                UPDATE users
                SET newsletter_opt_in = 0,
                    newsletter_opt_out_at = NOW(),
                    updated_at = NOW()
                WHERE id = :user_id
            ");
            $stmt->execute(['user_id' => $user['id']]);

            // Log unsubscribe (always, even without reason - for tracking)
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt = $pdo->prepare("
                INSERT INTO newsletter_unsubscribe_log (
                    user_id, email, reason, unsubscribed_at, ip_address, user_agent
                ) VALUES (?, ?, ?, NOW(), ?, ?)
            ");
            $stmt->execute([
                $user['id'],
                $user['email'],
                $reason ?: null,
                $ipAddress,
                substr($userAgent, 0, 500), // Limit to 500 chars
            ]);

            // ENTERPRISE: Update all newsletter_metrics for this user
            $stmt = $pdo->prepare("
                UPDATE newsletter_metrics
                SET unsubscribed_at = NOW(),
                    unsubscribe_reason = ?,
                    status = 'unsubscribed'
                WHERE user_id = ?
                  AND unsubscribed_at IS NULL
            ");
            $stmt->execute([$reason ?: null, $user['id']]);

            Logger::email('info', 'Newsletter: User unsubscribed', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'has_reason' => !empty($reason),
                'ip' => $ipAddress,
            ]);

            $this->json([
                'success' => true,
                'message' => 'You have been successfully unsubscribed from our newsletters',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterUnsubscribe: Process error', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 8) . '...',
            ]);

            $this->json(['success' => false, 'message' => 'An error occurred'], 500);
        }
    }

    /**
     * Re-subscribe (opt back in)
     * POST /newsletter/resubscribe/{token}
     */
    public function resubscribe(string $token): void
    {
        try {
            // Validate token
            if (strlen($token) !== 64 || !ctype_xdigit($token)) {
                $this->json(['success' => false, 'message' => 'Invalid token'], 400);

                return;
            }

            // Get user
            $pdo = $this->getFreshPDO();
            $stmt = $pdo->prepare("
                SELECT id, email, newsletter_opt_in
                FROM users
                WHERE newsletter_unsubscribe_token = :token
                  AND deleted_at IS NULL
            ");
            $stmt->execute(['token' => $token]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                $this->json(['success' => false, 'message' => 'Invalid token'], 404);

                return;
            }

            // Check if already subscribed
            if ($user['newsletter_opt_in'] == 1) {
                $this->json([
                    'success' => true,
                    'message' => 'You are already subscribed to our newsletters',
                    'already_subscribed' => true,
                ]);

                return;
            }

            // Re-subscribe
            $stmt = $pdo->prepare("
                UPDATE users
                SET newsletter_opt_in = TRUE,
                    newsletter_opt_in_at = NOW(),
                    updated_at = NOW()
                WHERE id = :user_id
            ");
            $stmt->execute(['user_id' => $user['id']]);

            $this->json([
                'success' => true,
                'message' => 'Welcome back! You have been re-subscribed to our newsletters',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterUnsubscribe: Resubscribe error', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'An error occurred'], 500);
        }
    }

    /**
     * Render unsubscribe confirmation page
     */
    private function renderUnsubscribePage(array $user, string $token, bool $alreadyUnsubscribed): void
    {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe from Newsletter - need2talk</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 16px;
        }

        .content {
            padding: 40px 30px;
        }

        .user-info {
            background: #f5f5f7;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
        }

        .user-info p {
            margin-bottom: 10px;
            color: #1d1d1f;
        }

        .user-info strong {
            color: #667eea;
        }

        .status-message {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .status-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }

        .status-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }

        .reason-box {
            margin-bottom: 30px;
        }

        .reason-box label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #1d1d1f;
        }

        .reason-box textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e5e7;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
        }

        .reason-box textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            min-width: 150px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .footer {
            background: #f5f5f7;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e5e5e7;
        }

        .footer p {
            color: #6e6e73;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .footer a {
            color: #667eea;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        #loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>need2talk</h1>
            <p>Newsletter Preferences</p>
        </div>

        <div class="content">
            <div class="user-info">
                <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
                <p><strong>Nickname:</strong> <?= htmlspecialchars($user['nickname'] ?? 'N/A') ?></p>
            </div>

            <?php if ($alreadyUnsubscribed): ?>
                <div class="status-message status-success">
                    <p><strong>✓ You are already unsubscribed</strong></p>
                    <p>You won't receive any more newsletters from us.</p>
                    <p>Changed your mind? You can re-subscribe anytime.</p>
                </div>

                <div class="buttons">
                    <button class="btn btn-primary" onclick="resubscribe()">
                        Re-Subscribe to Newsletter
                    </button>
                </div>
            <?php else: ?>
                <div class="status-message status-warning">
                    <p><strong>⚠ You're about to unsubscribe</strong></p>
                    <p>You will no longer receive our newsletters. We're sorry to see you go!</p>
                </div>

                <div class="reason-box">
                    <label for="reason">Why are you unsubscribing? (optional)</label>
                    <textarea id="reason" placeholder="Your feedback helps us improve..."></textarea>
                </div>

                <div class="buttons">
                    <button class="btn btn-danger" onclick="unsubscribe()">
                        Unsubscribe from Newsletter
                    </button>
                    <a href="<?= get_env('APP_URL') ?>" class="btn btn-primary" style="text-decoration: none; display: flex; align-items: center; justify-content: center;">
                        Keep Subscription
                    </a>
                </div>
            <?php endif; ?>

            <div id="loading">
                <div class="spinner"></div>
                <p>Processing...</p>
            </div>
        </div>

        <div class="footer">
            <p>
                <a href="<?= get_env('APP_URL') ?>">Go to Homepage</a> ·
                <a href="<?= get_env('APP_URL') ?>/legal/privacy">Privacy Policy</a> ·
                <a href="<?= get_env('APP_URL') ?>/legal/terms">Terms of Service</a>
            </p>
            <p>© <?= date('Y') ?> need2talk. All rights reserved.</p>
        </div>
    </div>

    <script>
        const token = <?= json_encode($token) ?>;

        function unsubscribe() {
            if (!confirm('Are you sure you want to unsubscribe?')) return;

            document.getElementById('loading').style.display = 'block';
            document.querySelector('.buttons').style.display = 'none';

            const formData = new FormData();
            formData.append('reason', document.getElementById('reason').value);

            fetch(`/newsletter/unsubscribe/${token}`, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';

                if (data.success) {
                    alert('✓ ' + data.message);
                    window.location.reload();
                } else {
                    alert('✗ ' + (data.message || 'An error occurred'));
                    document.querySelector('.buttons').style.display = 'flex';
                }
            })
            .catch(err => {
                console.error('Unsubscribe error:', err);
                document.getElementById('loading').style.display = 'none';
                document.querySelector('.buttons').style.display = 'flex';
                alert('Network error. Please try again.');
            });
        }

        function resubscribe() {
            if (!confirm('Re-subscribe to our newsletter?')) return;

            document.getElementById('loading').style.display = 'block';
            document.querySelector('.buttons').style.display = 'none';

            fetch(`/newsletter/resubscribe/${token}`, {
                method: 'POST'
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';

                if (data.success) {
                    alert('✓ ' + data.message);
                    window.location.reload();
                } else {
                    alert('✗ ' + (data.message || 'An error occurred'));
                    document.querySelector('.buttons').style.display = 'flex';
                }
            })
            .catch(err => {
                console.error('Resubscribe error:', err);
                document.getElementById('loading').style.display = 'none';
                document.querySelector('.buttons').style.display = 'flex';
                alert('Network error. Please try again.');
            });
        }
    </script>
</body>
</html>
        <?php
    }

    /**
     * Render error page
     */
    private function renderError(string $message): void
    {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(400);
        ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - need2talk</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            max-width: 500px;
        }
        h1 { color: #ef4444; margin-bottom: 20px; }
        p { color: #6e6e73; margin-bottom: 30px; }
        a { color: #667eea; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>⚠ Error</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="<?= get_env('APP_URL') ?>">← Back to Homepage</a>
    </div>
</body>
</html>
        <?php
    }

    /**
     * Get fresh PDO connection
     */
    private function getFreshPDO(): \PDO
    {
        $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') .
               ';dbname=' . env('DB_DATABASE', 'need2talk');

        return new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
