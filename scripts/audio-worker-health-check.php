<?php
/**
 * NEED2TALK - AUDIO WORKER HEALTH CHECK
 *
 * Verifica che il worker audio sia attivo e funzionante
 * Usato da Docker healthcheck
 *
 * EXIT CODES:
 * - 0: Healthy (worker attivo)
 * - 1: Unhealthy (worker morto o non risponde)
 */

require_once __DIR__ . '/../app/bootstrap.php';

try {
    // REDIS CONNECTION
    $redis = new Redis();
    $redis->connect(env('REDIS_HOST', 'redis'), (int) env('REDIS_PORT', 6379));

    $redisPassword = env('REDIS_PASSWORD');
    if ($redisPassword) {
        $redis->auth($redisPassword);
    }

    $redis->select((int) env('REDIS_DB_QUEUE', 2));

    // CHECK HEARTBEAT
    $workerId = gethostname() . '_' . getmypid();
    $heartbeatKey = "worker:audio:{$workerId}:heartbeat";

    $heartbeat = $redis->get($heartbeatKey);

    if (!$heartbeat) {
        // FALLBACK: Check se esiste almeno un worker attivo
        $allKeys = $redis->keys("worker:audio:*:heartbeat");

        if (empty($allKeys)) {
            error_log("Audio worker health check failed: No active workers found");
            exit(1);
        }

        // Almeno un worker è attivo
        exit(0);
    }

    // Worker specifico è attivo
    exit(0);

} catch (\Exception $e) {
    error_log("Audio worker health check exception: " . $e->getMessage());
    exit(1);
}
