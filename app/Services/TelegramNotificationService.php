<?php

declare(strict_types=1);

namespace Need2Talk\Services;

/**
 * Telegram Notification Service - Enterprise Galaxy Edition
 *
 * Sends notifications to Telegram with FULL TRACKING:
 * - Admin alerts (URL changes, security events)
 * - Error notifications (critical errors, exceptions)
 * - System monitoring (high load, disk space, etc.)
 * - Daily log file delivery (with deduplication)
 *
 * TRACKING TABLES:
 * - telegram_messages: Audit log of ALL messages sent
 * - telegram_log_deliveries: Tracks daily log files (prevents duplicates)
 *
 * CONFIGURATION (.env):
 * - TELEGRAM_BOT_TOKEN: Bot token from @BotFather
 * - TELEGRAM_ADMIN_CHAT_ID: Your personal chat ID for admin notifications
 *
 * @package Need2Talk\Services
 * @author Claude Code (Enterprise Galaxy Initiative)
 * @version 2.0.0 - With Enterprise Tracking
 */
class TelegramNotificationService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    private static ?string $botToken = null;
    private static ?string $adminChatId = null;

    /**
     * Initialize service (lazy loading)
     */
    private static function init(): bool
    {
        if (self::$botToken === null) {
            self::$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: null;
            self::$adminChatId = $_ENV['TELEGRAM_ADMIN_CHAT_ID'] ?? getenv('TELEGRAM_ADMIN_CHAT_ID') ?: null;
        }

        return self::$botToken !== null;
    }

    /**
     * Check if Telegram is configured
     */
    public static function isConfigured(): bool
    {
        self::init();
        return self::$botToken !== null && self::$adminChatId !== null;
    }

    // =========================================================================
    // MESSAGE SENDING METHODS
    // =========================================================================

    /**
     * Send notification to admin (uses TELEGRAM_ADMIN_CHAT_ID)
     *
     * @param string $message Message text (supports Markdown)
     * @param array $context Optional context data to append
     * @param bool $silent Send without notification sound
     * @param string $messageType Message type for tracking
     * @return bool Success
     */
    public static function sendAdmin(
        string $message,
        array $context = [],
        bool $silent = false,
        string $messageType = 'custom'
    ): bool {
        if (!self::init() || !self::$adminChatId) {
            return false;
        }

        // Append context if provided
        if (!empty($context)) {
            $message .= "\n\n📋 *Context:*\n```\n" . self::formatContext($context) . "\n```";
        }

        // Add timestamp and server info
        $message .= "\n\n⏰ " . date('d/m/Y H:i:s') . " (Europe/Rome)";

        return self::send(self::$adminChatId, $message, $silent, $messageType, $context);
    }

    /**
     * Send notification to specific chat
     *
     * @param string $chatId Telegram chat ID
     * @param string $message Message text (supports Markdown)
     * @param bool $silent Send without notification sound
     * @param string $messageType Message type for tracking
     * @param array $trackingContext Additional context for tracking
     * @return bool Success
     */
    public static function send(
        string $chatId,
        string $message,
        bool $silent = false,
        string $messageType = 'custom',
        array $trackingContext = []
    ): bool {
        if (!self::init()) {
            return false;
        }

        $url = self::API_BASE . self::$botToken . '/sendMessage';

        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_notification' => $silent,
            'disable_web_page_preview' => true,
        ];

        $success = false;
        $telegramMessageId = null;
        $errorMessage = null;

        try {
            // Use cURL for better reliability in Docker containers
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError) {
                $errorMessage = "cURL error: {$curlError}";
                Logger::error('Telegram notification cURL error', [
                    'error' => $curlError,
                    'chat_id' => $chatId,
                ]);
            } else {
                $result = json_decode($response, true);

                if ($result['ok'] ?? false) {
                    $success = true;
                    $telegramMessageId = $result['result']['message_id'] ?? null;
                } else {
                    $errorMessage = $result['description'] ?? 'unknown';
                    Logger::error('Telegram API error', [
                        'error' => $errorMessage,
                        'http_code' => $httpCode,
                        'chat_id' => $chatId,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Logger::error('Telegram notification exception', [
                'error' => $errorMessage,
                'chat_id' => $chatId,
            ]);
        }

        // Log to telegram_messages table
        self::logMessage(
            messageType: $messageType,
            chatId: $chatId,
            message: $message,
            success: $success,
            telegramMessageId: $telegramMessageId,
            errorMessage: $errorMessage,
            silent: $silent,
            context: $trackingContext
        );

        return $success;
    }

    /**
     * Send admin URL notification
     */
    public static function sendAdminUrlNotification(string $newUrl, ?array $executorInfo = null): bool
    {
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : 'need2talk.it';
        $environment = $_ENV['APP_ENV'] ?? 'production';

        $executorIp = $executorInfo['client_ip'] ?? 'N/A';
        $executorUser = $executorInfo['effective_user'] ?? 'N/A';
        $executorServer = $executorInfo['server_hostname'] ?? 'N/A';
        $executorHostname = $executorInfo['client_hostname'] ?? null;

        $ipDisplay = $executorIp;
        if ($executorHostname) {
            $ipDisplay .= "\n   ({$executorHostname})";
        }

        $message = "🔐 *need2talk Admin [{$environment}]*\n\n" .
                  "🆕 *Nuovo URL sicuro generato:*\n" .
                  "`https://{$domain}{$newUrl}`\n\n" .
                  "👤 *Richiesto da:*\n" .
                  "├ IP: `{$ipDisplay}`\n" .
                  "├ User: `{$executorUser}`\n" .
                  "└ Server: `{$executorServer}`\n\n" .
                  "⚠️ *Salva in luogo sicuro*\n" .
                  "🔒 Usa sempre 2FA per accedere\n" .
                  "⏰ URL valido per 8 ore";

        return self::sendAdmin($message, [], false, 'admin_url');
    }

    /**
     * Send security alert
     */
    public static function sendSecurityAlert(string $type, string $description, array $details = []): bool
    {
        $emoji = match ($type) {
            'critical' => '🚨',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            'success' => '✅',
            default => '🔔',
        };

        $message = "{$emoji} *SECURITY ALERT*\n\n" .
                  "*Type:* {$type}\n" .
                  "*Description:* {$description}";

        return self::sendAdmin($message, $details, false, 'security_alert');
    }

    /**
     * Send error notification
     */
    public static function sendError(string $error, array $context = []): bool
    {
        $message = "❌ *ERROR ALERT*\n\n" .
                  "*Error:* {$error}";

        return self::sendAdmin($message, $context, false, 'error');
    }

    /**
     * Send system alert (disk, memory, CPU)
     */
    public static function sendSystemAlert(string $metric, string $value, string $threshold): bool
    {
        $message = "🖥️ *SYSTEM ALERT*\n\n" .
                  "*Metric:* {$metric}\n" .
                  "*Current:* {$value}\n" .
                  "*Threshold:* {$threshold}";

        return self::sendAdmin($message, [], false, 'system_alert');
    }

    // =========================================================================
    // DOCUMENT SENDING METHODS
    // =========================================================================

    /**
     * Send document (file) to admin
     *
     * @param string $filePath Path to file
     * @param string|null $caption Optional caption
     * @param bool $silent Send without notification sound
     * @param string $messageType Message type for tracking
     * @return array{success: bool, telegram_message_id: ?int}
     */
    public static function sendDocumentToAdmin(
        string $filePath,
        ?string $caption = null,
        bool $silent = false,
        string $messageType = 'document'
    ): array {
        if (!self::init() || !self::$adminChatId) {
            return ['success' => false, 'telegram_message_id' => null];
        }

        return self::sendDocument(self::$adminChatId, $filePath, $caption, $silent, $messageType);
    }

    /**
     * Send document (file) to specific chat
     *
     * Supports files up to 50MB.
     *
     * @param string $chatId Telegram chat ID
     * @param string $filePath Path to file
     * @param string|null $caption Optional caption (max 1024 chars)
     * @param bool $silent Send without notification sound
     * @param string $messageType Message type for tracking
     * @return array{success: bool, telegram_message_id: ?int}
     */
    public static function sendDocument(
        string $chatId,
        string $filePath,
        ?string $caption = null,
        bool $silent = false,
        string $messageType = 'document'
    ): array {
        if (!self::init()) {
            return ['success' => false, 'telegram_message_id' => null];
        }

        if (!file_exists($filePath)) {
            Logger::error('Telegram sendDocument - file not found', [
                'file' => $filePath,
            ]);
            return ['success' => false, 'telegram_message_id' => null];
        }

        // Check file size (max 50MB)
        $fileSize = filesize($filePath);
        if ($fileSize > 50 * 1024 * 1024) {
            Logger::error('Telegram sendDocument - file too large', [
                'file' => $filePath,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
            ]);
            return ['success' => false, 'telegram_message_id' => null];
        }

        $url = self::API_BASE . self::$botToken . '/sendDocument';

        $success = false;
        $telegramMessageId = null;
        $errorMessage = null;

        try {
            $postFields = [
                'chat_id' => $chatId,
                'document' => new \CURLFile($filePath),
                'disable_notification' => $silent ? 'true' : 'false',
            ];

            if ($caption) {
                $postFields['caption'] = substr($caption, 0, 1024);
                $postFields['parse_mode'] = 'Markdown';
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120, // 2 min for large files
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError) {
                $errorMessage = "cURL error: {$curlError}";
                Logger::error('Telegram sendDocument cURL error', [
                    'error' => $curlError,
                    'file' => $filePath,
                ]);
            } else {
                $result = json_decode($response, true);

                if ($result['ok'] ?? false) {
                    $success = true;
                    $telegramMessageId = $result['result']['message_id'] ?? null;
                } else {
                    $errorMessage = $result['description'] ?? 'unknown';
                    Logger::error('Telegram sendDocument API error', [
                        'error' => $errorMessage,
                        'http_code' => $httpCode,
                        'file' => $filePath,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Logger::error('Telegram sendDocument exception', [
                'error' => $errorMessage,
                'file' => $filePath,
            ]);
        }

        // Log to telegram_messages table
        $isCompressed = str_ends_with($filePath, '.gz');
        self::logMessage(
            messageType: $messageType,
            chatId: $chatId,
            message: $caption ?? '',
            success: $success,
            telegramMessageId: $telegramMessageId,
            errorMessage: $errorMessage,
            silent: $silent,
            fileName: basename($filePath),
            filePath: $filePath,
            fileSize: $fileSize,
            fileType: 'document',
            isCompressed: $isCompressed
        );

        return [
            'success' => $success,
            'telegram_message_id' => $telegramMessageId,
        ];
    }

    // =========================================================================
    // DAILY LOGS DELIVERY (WITH DEDUPLICATION)
    // =========================================================================

    /**
     * Send daily log digest
     *
     * ENTERPRISE FEATURE: Only sends files that haven't been sent before!
     * Checks telegram_log_deliveries table before sending.
     *
     * Log types:
     * - Dated logs (YYYY-MM-DD suffix): errors, security, database, email, audio, js_errors, websocket, overlay
     * - Non-dated logs: php_errors.log, swoole_websocket.log (sent as-is, content from last 24h)
     *
     * Excluded:
     * - worker_*.log (always empty)
     * - admin-email-worker-*.log (worker internal)
     * - newsletter-worker-*.log (worker internal)
     * - cron.log (too large, separate handling)
     *
     * @return array Results per log type
     */
    public static function sendDailyLogs(): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $logsDir = defined('APP_ROOT') ? APP_ROOT . '/storage/logs' : '/var/www/html/storage/logs';
        $tempDir = sys_get_temp_dir();

        $results = [];

        // Dated log files (with YYYY-MM-DD suffix)
        $logTypes = [
            'errors' => "errors-{$yesterday}.log",
            'security' => "security-{$yesterday}.log",
            'database' => "database-{$yesterday}.log",
            'email' => "email-{$yesterday}.log",
            'audio' => "audio-{$yesterday}.log",
            'js_errors' => "js_errors-{$yesterday}.log",
            'websocket' => "websocket-{$yesterday}.log",
            'overlay' => "overlay-{$yesterday}.log",
        ];

        // Non-dated log files (send entire file, mark with yesterday's date for dedup)
        $nonDatedLogs = [
            'php_errors' => 'php_errors.log',
            'swoole' => 'swoole_websocket.log',
        ];

        $filesToSend = [];
        $alreadySentCount = 0;

        // Process dated log files
        foreach ($logTypes as $type => $filename) {
            // ENTERPRISE: Check if already sent!
            if (self::wasLogAlreadySent($yesterday, $type)) {
                $results[$type] = 'already_sent';
                $alreadySentCount++;
                continue;
            }

            $logFile = "{$logsDir}/{$filename}";

            if (!file_exists($logFile)) {
                $results[$type] = 'not_found';
                continue;
            }

            $fileSize = filesize($logFile);
            if ($fileSize === 0) {
                $results[$type] = 'empty';
                continue;
            }

            // Compress if larger than 1MB
            if ($fileSize > 1024 * 1024) {
                $gzFile = "{$tempDir}/{$filename}.gz";
                $gzHandle = gzopen($gzFile, 'wb9');
                $logHandle = fopen($logFile, 'rb');

                while (!feof($logHandle)) {
                    gzwrite($gzHandle, fread($logHandle, 8192));
                }

                fclose($logHandle);
                gzclose($gzHandle);

                $filesToSend[$type] = [
                    'path' => $gzFile,
                    'original' => $logFile,
                    'originalSize' => $fileSize,
                    'compressed' => true,
                    'filename' => $filename,
                ];
            } else {
                $filesToSend[$type] = [
                    'path' => $logFile,
                    'original' => $logFile,
                    'originalSize' => $fileSize,
                    'compressed' => false,
                    'filename' => $filename,
                ];
            }
        }

        // Process non-dated log files (php_errors, swoole)
        // These are sent with yesterday's date for deduplication tracking
        foreach ($nonDatedLogs as $type => $filename) {
            // Check if already sent for yesterday
            if (self::wasLogAlreadySent($yesterday, $type)) {
                $results[$type] = 'already_sent';
                $alreadySentCount++;
                continue;
            }

            $logFile = "{$logsDir}/{$filename}";

            if (!file_exists($logFile)) {
                $results[$type] = 'not_found';
                continue;
            }

            $fileSize = filesize($logFile);
            if ($fileSize === 0) {
                $results[$type] = 'empty';
                continue;
            }

            // For non-dated files, always compress (they accumulate)
            $renamedFilename = str_replace('.log', "-{$yesterday}.log", $filename);
            $gzFile = "{$tempDir}/{$renamedFilename}.gz";
            $gzHandle = gzopen($gzFile, 'wb9');
            $logHandle = fopen($logFile, 'rb');

            while (!feof($logHandle)) {
                gzwrite($gzHandle, fread($logHandle, 8192));
            }

            fclose($logHandle);
            gzclose($gzHandle);

            $filesToSend[$type] = [
                'path' => $gzFile,
                'original' => $logFile,
                'originalSize' => $fileSize,
                'compressed' => true,
                'filename' => $renamedFilename,
                'nonDated' => true,  // Flag to identify non-dated logs
            ];
        }

        // If all logs were already sent, send summary
        $totalLogTypes = count($logTypes) + count($nonDatedLogs);
        if (empty($filesToSend)) {
            if ($alreadySentCount === $totalLogTypes) {
                // All logs already sent - skip silently (don't spam)
                Logger::info('Telegram daily logs - all already sent', [
                    'date' => $yesterday,
                ]);
            } else {
                self::sendAdmin(
                    "📋 *Daily Log Report*\n\n🗓 {$yesterday}\n\nNo new log files to send.",
                    [],
                    true,
                    'daily_logs'
                );
            }
            return $results;
        }

        // Send intro message
        $newFilesCount = count($filesToSend);
        $intro = "📋 *Daily Log Report*\n\n" .
                 "🗓 *Date:* {$yesterday}\n" .
                 "📁 *New files:* {$newFilesCount}" .
                 ($alreadySentCount > 0 ? "\n✅ *Already sent:* {$alreadySentCount}" : "");
        self::sendAdmin($intro, [], true, 'daily_logs');

        // Send each file
        foreach ($filesToSend as $type => $fileInfo) {
            $filePath = $fileInfo['path'];
            $originalSize = $fileInfo['originalSize'];
            $compressedSize = filesize($filePath);
            $isCompressed = $fileInfo['compressed'];

            $caption = "📄 *{$type}* log\n" .
                      "Size: " . self::formatBytes($originalSize) .
                      ($isCompressed ? " → " . self::formatBytes($compressedSize) . " (gzip)" : "");

            $sendResult = self::sendDocumentToAdmin($filePath, $caption, true, 'daily_logs');

            if ($sendResult['success']) {
                $results[$type] = 'sent';

                // ENTERPRISE: Record delivery in telegram_log_deliveries
                self::logDelivery(
                    logDate: $yesterday,
                    logType: $type,
                    filePath: $fileInfo['original'],
                    fileName: $isCompressed ? $fileInfo['filename'] . '.gz' : $fileInfo['filename'],
                    originalSize: $originalSize,
                    compressedSize: $isCompressed ? $compressedSize : null,
                    isCompressed: $isCompressed,
                    telegramMessageId: $sendResult['telegram_message_id'],
                    success: true
                );
            } else {
                $results[$type] = 'failed';

                // Log failed delivery attempt
                self::logDelivery(
                    logDate: $yesterday,
                    logType: $type,
                    filePath: $fileInfo['original'],
                    fileName: $isCompressed ? $fileInfo['filename'] . '.gz' : $fileInfo['filename'],
                    originalSize: $originalSize,
                    compressedSize: $isCompressed ? $compressedSize : null,
                    isCompressed: $isCompressed,
                    telegramMessageId: null,
                    success: false,
                    errorMessage: 'Send failed'
                );
            }

            // Cleanup temp gz files (only delete compressed copies in /tmp)
            if ($isCompressed && str_starts_with($filePath, $tempDir) && file_exists($filePath)) {
                unlink($filePath);
            }
        }

        return $results;
    }

    // =========================================================================
    // TRACKING & AUDIT LOG METHODS
    // =========================================================================

    /**
     * Check if a log file was already sent for a specific date
     *
     * @param string $logDate Date in Y-m-d format
     * @param string $logType Log type (errors, security, etc.)
     * @return bool True if already sent
     */
    public static function wasLogAlreadySent(string $logDate, string $logType): bool
    {
        try {
            $db = db();
            $result = $db->findOne(
                "SELECT id FROM telegram_log_deliveries
                 WHERE log_date = :log_date AND log_type = :log_type AND success = TRUE",
                ['log_date' => $logDate, 'log_type' => $logType],
                ['cache' => false] // Don't cache this check
            );

            return $result !== null;
        } catch (\Exception $e) {
            Logger::error('Telegram: Failed to check log delivery status', [
                'error' => $e->getMessage(),
                'log_date' => $logDate,
                'log_type' => $logType,
            ]);
            return false; // On error, try to send anyway
        }
    }

    /**
     * Log a delivery to telegram_log_deliveries table
     */
    private static function logDelivery(
        string $logDate,
        string $logType,
        string $filePath,
        string $fileName,
        int $originalSize,
        ?int $compressedSize,
        bool $isCompressed,
        ?int $telegramMessageId,
        bool $success,
        ?string $errorMessage = null
    ): void {
        try {
            $db = db();

            // Use INSERT ... ON CONFLICT for idempotency
            $db->execute(
                "INSERT INTO telegram_log_deliveries
                 (log_date, log_type, file_path, file_name, original_size, compressed_size,
                  is_compressed, telegram_message_id, success, error_message, sent_at)
                 VALUES (:log_date, :log_type, :file_path, :file_name, :original_size,
                         :compressed_size, :is_compressed, :telegram_message_id, :success,
                         :error_message, NOW())
                 ON CONFLICT (log_date, log_type)
                 DO UPDATE SET
                     file_path = EXCLUDED.file_path,
                     file_name = EXCLUDED.file_name,
                     original_size = EXCLUDED.original_size,
                     compressed_size = EXCLUDED.compressed_size,
                     is_compressed = EXCLUDED.is_compressed,
                     telegram_message_id = EXCLUDED.telegram_message_id,
                     success = EXCLUDED.success,
                     error_message = EXCLUDED.error_message,
                     sent_at = NOW()",
                [
                    'log_date' => $logDate,
                    'log_type' => $logType,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'is_compressed' => $isCompressed ? 'true' : 'false',
                    'telegram_message_id' => $telegramMessageId,
                    'success' => $success ? 'true' : 'false',
                    'error_message' => $errorMessage,
                ],
                ['invalidate_cache' => ['table:telegram_log_deliveries']]
            );
        } catch (\Exception $e) {
            Logger::error('Telegram: Failed to log delivery', [
                'error' => $e->getMessage(),
                'log_date' => $logDate,
                'log_type' => $logType,
            ]);
        }
    }

    /**
     * Log a message to telegram_messages audit table
     */
    private static function logMessage(
        string $messageType,
        string $chatId,
        string $message,
        bool $success,
        ?int $telegramMessageId = null,
        ?string $errorMessage = null,
        bool $silent = false,
        ?string $fileName = null,
        ?string $filePath = null,
        ?int $fileSize = null,
        ?string $fileType = null,
        bool $isCompressed = false,
        array $context = []
    ): void {
        try {
            $db = db();

            // Generate content hash for deduplication
            $contentHash = hash('sha256', $message);

            // Preview (first 500 chars)
            $preview = mb_substr(strip_tags($message), 0, 500);

            $db->execute(
                "INSERT INTO telegram_messages
                 (message_type, chat_id, telegram_message_id, content_hash, message_preview,
                  file_name, file_path, file_size, file_type, is_compressed, success,
                  error_message, silent, parse_mode, context, sent_at)
                 VALUES (:message_type, :chat_id, :telegram_message_id, :content_hash,
                         :message_preview, :file_name, :file_path, :file_size, :file_type,
                         :is_compressed, :success, :error_message, :silent, :parse_mode,
                         :context, NOW())",
                [
                    'message_type' => $messageType,
                    'chat_id' => $chatId,
                    'telegram_message_id' => $telegramMessageId,
                    'content_hash' => $contentHash,
                    'message_preview' => $preview,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'file_type' => $fileType,
                    'is_compressed' => $isCompressed ? 'true' : 'false',
                    'success' => $success ? 'true' : 'false',
                    'error_message' => $errorMessage,
                    'silent' => $silent ? 'true' : 'false',
                    'parse_mode' => 'Markdown',
                    'context' => !empty($context) ? json_encode($context) : null,
                ],
                ['invalidate_cache' => ['table:telegram_messages']]
            );
        } catch (\Exception $e) {
            // Don't fail silently but don't block notifications either
            Logger::error('Telegram: Failed to log message to audit table', [
                'error' => $e->getMessage(),
                'message_type' => $messageType,
            ]);
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Format context array for display
     */
    private static function formatContext(array $context): string
    {
        $lines = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $lines[] = "{$key}: {$value}";
        }
        return implode("\n", $lines);
    }

    /**
     * Format bytes to human readable
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Test connection (sends a test message)
     */
    public static function test(): bool
    {
        return self::sendAdmin(
            "✅ *Telegram Test*\n\nConnection successful!\nBot is working correctly.\n\n📊 *Tracking:* Enabled\n🗄 *Tables:* telegram\\_messages, telegram\\_log\\_deliveries",
            [],
            false,
            'test'
        );
    }

    // =========================================================================
    // STATISTICS & ADMIN METHODS
    // =========================================================================

    /**
     * Get message statistics
     *
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public static function getStats(int $days = 30): array
    {
        try {
            $db = db();

            // Total messages
            $total = $db->findOne(
                "SELECT COUNT(*) as count FROM telegram_messages
                 WHERE sent_at > NOW() - INTERVAL '{$days} days'",
                [],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            // By type
            $byType = $db->findMany(
                "SELECT message_type, COUNT(*) as count, SUM(CASE WHEN success THEN 1 ELSE 0 END) as successful
                 FROM telegram_messages
                 WHERE sent_at > NOW() - INTERVAL '{$days} days'
                 GROUP BY message_type
                 ORDER BY count DESC",
                [],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            // Log deliveries
            $deliveries = $db->findOne(
                "SELECT COUNT(*) as count, COUNT(DISTINCT log_date) as unique_days
                 FROM telegram_log_deliveries
                 WHERE sent_at > NOW() - INTERVAL '{$days} days' AND success = TRUE",
                [],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            return [
                'total_messages' => (int) ($total['count'] ?? 0),
                'by_type' => $byType,
                'log_deliveries' => (int) ($deliveries['count'] ?? 0),
                'log_unique_days' => (int) ($deliveries['unique_days'] ?? 0),
                'period_days' => $days,
            ];
        } catch (\Exception $e) {
            Logger::error('Telegram: Failed to get stats', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get recent messages (for admin panel)
     *
     * @param int $limit Number of messages to return
     * @return array Recent messages
     */
    public static function getRecentMessages(int $limit = 50): array
    {
        try {
            $db = db();

            return $db->findMany(
                "SELECT id, message_type, chat_id, message_preview, file_name,
                        success, error_message, sent_at
                 FROM telegram_messages
                 ORDER BY sent_at DESC
                 LIMIT :limit",
                ['limit' => $limit],
                ['cache' => false]
            );
        } catch (\Exception $e) {
            Logger::error('Telegram: Failed to get recent messages', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
