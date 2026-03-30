<?php
/**
 * Enterprise Monitoring Logs Cleanup Script
 * 
 * ENTERPRISE 2025: Auto-cleanup per performance 100k+ users
 * 
 * Mantiene:
 * - Ultimi 30 giorni di errori JS
 * - Ultimi 7 giorni di performance metrics
 * - Errori critici: 90 giorni (retention estesa)
 * 
 * Esecuzione: php scripts/cleanup-enterprise-logs.php
 * Cron: 0 2 * * * /usr/bin/php /path/to/scripts/cleanup-enterprise-logs.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "🧹 Enterprise Monitoring Logs Cleanup Started\n";
echo "==========================================\n\n";

try {
    $db = db_pdo();
    
    // 1. CLEANUP JS ERRORS (30 giorni, tranne critical)
    echo "📊 Cleaning JS errors older than 30 days...\n";
    $stmt = $db->prepare("
        DELETE FROM enterprise_js_errors 
        WHERE created_at < NOW() - INTERVAL '30 days'
        AND severity != 'critical'
    ");
    $stmt->execute();
    $deletedErrors = $stmt->rowCount();
    echo "   ✓ Deleted {$deletedErrors} old JS errors\n\n";
    
    // 2. CLEANUP CRITICAL ERRORS (90 giorni)
    echo "🚨 Cleaning critical errors older than 90 days...\n";
    $stmt = $db->prepare("
        DELETE FROM enterprise_js_errors 
        WHERE created_at < NOW() - INTERVAL '90 days'
        AND severity = 'critical'
    ");
    $stmt->execute();
    $deletedCritical = $stmt->rowCount();
    echo "   ✓ Deleted {$deletedCritical} old critical errors\n\n";
    
    // 3. CLEANUP PERFORMANCE METRICS (7 giorni)
    echo "⚡ Cleaning performance metrics older than 7 days...\n";
    $stmt = $db->prepare("
        DELETE FROM enterprise_performance_metrics 
        WHERE created_at < NOW() - INTERVAL '7 days'
    ");
    $stmt->execute();
    $deletedMetrics = $stmt->rowCount();
    echo "   ✓ Deleted {$deletedMetrics} old performance metrics\n\n";
    
    // 4. OPTIMIZE TABLES
    echo "🔧 Optimizing tables...\n";
    $db->exec("OPTIMIZE TABLE enterprise_js_errors");
    $db->exec("OPTIMIZE TABLE enterprise_performance_metrics");
    echo "   ✓ Tables optimized\n\n";
    
    // 5. STATISTICS
    $stmt = $db->query("SELECT COUNT(*) as count FROM enterprise_js_errors");
    $errorsCount = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM enterprise_performance_metrics");
    $metricsCount = $stmt->fetchColumn();
    
    echo "📈 Current Statistics:\n";
    echo "   - JS Errors: {$errorsCount}\n";
    echo "   - Performance Metrics: {$metricsCount}\n\n";
    
    echo "==========================================\n";
    echo "✅ Cleanup completed successfully!\n";

    // Log success
        Logger::info('DEFAULT: Enterprise logs cleanup completed', [
            'deleted_errors' => $deletedErrors,
            'deleted_critical' => $deletedCritical,
            'deleted_metrics' => $deletedMetrics,
            'remaining_errors' => $errorsCount,
            'remaining_metrics' => $metricsCount
        ]);
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
        Logger::error('DEFAULT: Enterprise logs cleanup failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    exit(1);
}
