<?php

namespace Need2Talk\Services;

/**
 * NEED2TALK - WEBSOCKET LOGGING SERVICE
 *
 * LOGGING COMPLETO WEBSOCKET:
 * 1. Log connessioni WebSocket (connect, disconnect, errors)
 * 2. Performance monitoring WebSocket (latency, throughput, memory)
 * 3. Message logging con filtering per privacy
 * 4. Event tracking per analytics
 * 5. Error handling e debugging completo
 * 6. Scalabile per migliaia di connessioni contemporanee
 *
 * LOGS GENERATI:
 * - websocket.log: Eventi WebSocket generali
 * - websocket-performance.log: Performance e metriche WebSocket
 * - websocket-errors.log: Errori e debugging WebSocket
 */
class WebSocketLogger
{
    private static array $connectionStats = [];

    private static array $messageStats = [];

    private static int $totalConnections = 0;

    private static int $activeConnections = 0;

    /**
     * LOG CONNECTION evento (connect/disconnect)
     */
    public static function logConnection(string $event, string $connectionId, array $data = []): void
    {
        $connectionData = array_merge([
            'event' => $event,
            'connection_id' => $connectionId,
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'active_connections' => self::$activeConnections,
            'total_connections' => self::$totalConnections,
        ], $data);

        // AGGIORNA statistiche connessioni
        self::updateConnectionStats($event, $connectionId, $data);

        // LOG performance se necessario
        if ($event === 'connect') {
            self::logConnectionPerformance($connectionId, $data);
        }
    }

    /**
     * LOG MESSAGE WebSocket con privacy filtering
     */
    public static function logMessage(string $type, string $connectionId, array $message, string $direction = 'outgoing'): void
    {
        // PRIVACY: Filter sensitive data
        $filteredMessage = self::filterSensitiveData($message);

        $messageData = [
            'type' => $type,
            'connection_id' => $connectionId,
            'direction' => $direction, // incoming, outgoing
            'message_size_bytes' => strlen(json_encode($message)),
            'message_type' => $message['type'] ?? 'unknown',
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'user_id' => $message['user_id'] ?? null,
            'channel' => $message['channel'] ?? null,
            'event' => $message['event'] ?? null,
            'filtered_message' => $filteredMessage,
        ];

        // AGGIORNA statistiche messaggi
        self::updateMessageStats($type, $direction);
    }

    /**
     * LOG ERROR WebSocket con stack trace
     */
    public static function logError(string $error, string $connectionId = '', array $context = [], ?\Throwable $exception = null): void
    {
        $errorData = array_merge([
            'error' => $error,
            'connection_id' => $connectionId,
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'active_connections' => self::$activeConnections,
            'exception_class' => $exception ? get_class($exception) : null,
            'exception_message' => $exception?->getMessage(),
            'exception_file' => $exception?->getFile(),
            'exception_line' => $exception?->getLine(),
            'stack_trace' => $exception?->getTraceAsString(),
        ], $context);

        Logger::websocket('error', "WEBSOCKET: Error: {$error}", $errorData);
    }

    /**
     * LOG PERFORMANCE METRICS WebSocket dettagliate
     */
    public static function logPerformance(string $operation, float $executionTime, array $metrics = []): void
    {
        $performanceData = array_merge([
            'operation' => $operation,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'active_connections' => self::$activeConnections,
            'total_connections' => self::$totalConnections,
            'messages_per_second' => self::calculateMessagesPerSecond(),
            'connection_latency_ms' => $metrics['latency_ms'] ?? null,
            'throughput_kbps' => $metrics['throughput_kbps'] ?? null,
            'cpu_usage' => self::getCPUUsage(),
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'is_slow' => $executionTime > 0.1, // Slow operation >100ms
            'is_memory_intensive' => (memory_get_usage(true) / 1024 / 1024) > 100, // >100MB
        ], $metrics);

        Logger::performance('info', "PERFORMANCE: WebSocket {$operation}", $executionTime, $performanceData);

        // ALERT per performance critiche
        if ($executionTime > 1.0) {
            Logger::performance('warning', "PERFORMANCE: Slow WebSocket operation: {$operation} took {$executionTime}s", $executionTime, $performanceData);
        }

        if (self::$activeConnections > 1000) {
            Logger::performance('warning', 'PERFORMANCE: High connection count: ' . self::$activeConnections . ' active connections', 0, $performanceData);
        }
    }

    /**
     * LOG EVENT specifici WebSocket
     */
    public static function logEvent(string $event, array $data = []): void
    {
        $eventData = array_merge([
            'event' => $event,
            'timestamp' => microtime(true),
            'datetime' => date('Y-m-d H:i:s'),
            'active_connections' => self::$activeConnections,
            'total_connections' => self::$totalConnections,
        ], $data);
    }

    /**
     * LOG STATISTICS periodiche WebSocket
     */
    public static function logStatistics(): void
    {
        $stats = [
            'active_connections' => self::$activeConnections,
            'total_connections_lifetime' => self::$totalConnections,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'messages_per_second' => self::calculateMessagesPerSecond(),
            'connection_stats' => self::$connectionStats,
            'message_stats' => self::$messageStats,
            'uptime_seconds' => self::getUptimeSeconds(),
            'cpu_usage' => self::getCPUUsage(),
            'load_average' => self::getLoadAverage(),
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * RESET STATISTICS (per maintenance)
     */
    public static function resetStats(): void
    {
        self::$messageStats = [];
        // Mantieni connection stats attive ma resetta contatori
    }

    /**
     * GET CURRENT STATISTICS
     */
    public static function getCurrentStats(): array
    {
        return [
            'active_connections' => self::$activeConnections,
            'total_connections' => self::$totalConnections,
            'connection_stats' => self::$connectionStats,
            'message_stats' => self::$messageStats,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'uptime_seconds' => self::getUptimeSeconds(),
        ];
    }

    /**
     * AGGIORNA CONNECTION STATS
     */
    private static function updateConnectionStats(string $event, string $connectionId, array $data): void
    {
        switch ($event) {
            case 'connect':
                self::$activeConnections++;
                self::$totalConnections++;
                self::$connectionStats[$connectionId] = [
                    'connected_at' => microtime(true),
                    'user_id' => $data['user_id'] ?? null,
                    'ip' => $data['ip'] ?? null,
                    'user_agent' => $data['user_agent'] ?? null,
                    'messages_sent' => 0,
                    'messages_received' => 0,
                    'last_activity' => microtime(true),
                ];
                break;

            case 'disconnect':
                self::$activeConnections--;

                if (isset(self::$connectionStats[$connectionId])) {
                    unset(self::$connectionStats[$connectionId]);
                }
                break;
        }
    }

    /**
     * AGGIORNA MESSAGE STATS
     */
    private static function updateMessageStats(string $type, string $direction): void
    {
        if (!isset(self::$messageStats[$type])) {
            self::$messageStats[$type] = ['incoming' => 0, 'outgoing' => 0];
        }

        self::$messageStats[$type][$direction]++;

        // AGGIORNA anche stats per connessione specifica se necessario
        // (implementazione più complessa)
    }

    /**
     * LOG CONNECTION PERFORMANCE dettagliata
     */
    private static function logConnectionPerformance(string $connectionId, array $data): void
    {
        $performanceData = [
            'connection_id' => $connectionId,
            'handshake_time_ms' => $data['handshake_time_ms'] ?? null,
            'protocol_version' => $data['protocol_version'] ?? null,
            'extensions' => $data['extensions'] ?? null,
            'subprotocols' => $data['subprotocols'] ?? null,
            'memory_before_mb' => $data['memory_before_mb'] ?? null,
            'memory_after_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'active_connections' => self::$activeConnections,
            'timestamp' => time(),
        ];

        Logger::performance('info', 'PERFORMANCE: WebSocket Connection', $data['handshake_time_ms'] / 1000 ?? 0, $performanceData);
    }

    /**
     * FILTER SENSITIVE DATA per privacy
     */
    private static function filterSensitiveData(array $message): array
    {
        $sensitiveFields = [
            'password', 'token', 'secret', 'key', 'auth', 'session',
            'email', 'phone', 'address', 'credit_card', 'ssn',
        ];

        $filtered = $message;

        foreach ($sensitiveFields as $field) {
            if (isset($filtered[$field])) {
                $filtered[$field] = '***FILTERED***';
            }
        }

        // FILTER nested arrays
        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = self::filterSensitiveData($value);
            }
        }

        return $filtered;
    }

    /**
     * CALCOLA MESSAGES PER SECOND
     */
    private static function calculateMessagesPerSecond(): float
    {
        static $lastMessageCount = 0;
        static $lastTimestamp = 0;

        $currentMessageCount = array_sum(array_map(function ($stats) {
            return $stats['incoming'] + $stats['outgoing'];
        }, self::$messageStats));

        $currentTimestamp = time();

        if ($lastTimestamp > 0) {
            $timeDiff = $currentTimestamp - $lastTimestamp;
            $messageDiff = $currentMessageCount - $lastMessageCount;

            if ($timeDiff > 0) {
                $messagesPerSecond = $messageDiff / $timeDiff;
                $lastMessageCount = $currentMessageCount;
                $lastTimestamp = $currentTimestamp;

                return round($messagesPerSecond, 2);
            }
        }

        $lastMessageCount = $currentMessageCount;
        $lastTimestamp = $currentTimestamp;

        return 0.0;
    }

    /**
     * GET UPTIME in secondi
     */
    private static function getUptimeSeconds(): int
    {
        static $startTime = null;

        if ($startTime === null) {
            $startTime = time();
        }

        return time() - $startTime;
    }

    /**
     * GET CPU USAGE
     */
    private static function getCPUUsage(): ?float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();

            return $load[0] ?? null;
        }

        return null;
    }

    /**
     * GET LOAD AVERAGE
     */
    private static function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return null;
    }
}
