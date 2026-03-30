<?php
// ENTERPRISE SECURITY: Admin-only page - MUST be authenticated
// This page generates test errors and MUST NOT be accessible to non-admin users

use Need2Talk\Services\AdminSecurityService;

// Check if admin is logged in
$sessionToken = $_COOKIE['__Host-admin_session'] ?? null;
if (!$sessionToken) {
    http_response_code(404);
    exit('Not Found');
}

$security = new AdminSecurityService();
$adminSession = $security->validateAdminSession($sessionToken);

if (!$adminSession) {
    // Session invalid - return 404 to avoid revealing admin URLs
    http_response_code(404);
    exit('Not Found');
}

// ENTERPRISE: Admin is authenticated - allow access to test page
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo bin2hex(random_bytes(32)); ?>">
    <!-- ENTERPRISE: Force no-cache for real-time testing -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>🧪 Enterprise JS Error Testing - Need2Talk [v<?php echo time(); ?>]</title>
<?php
// ENTERPRISE GALAXY: Get current js_errors channel logging configuration
$jsErrorsConfig = null;
$currentLevel = 'info'; // default
$levelDescription = '';

try {
    if (class_exists('Need2Talk\\Services\\LoggingConfigService')) {
        $loggingService = \Need2Talk\Services\LoggingConfigService::getInstance();
        $config = $loggingService->getConfiguration(skipCache: true);
        $jsErrorsConfig = $config['js_errors'] ?? null;
        $currentLevel = $jsErrorsConfig['level'] ?? 'info';

        // Level descriptions
        $levelDescriptions = [
            'debug' => 'ALL errors will be stored (debug, info, warning, error, critical)',
            'info' => 'INFO+ errors will be stored (info, warning, error, critical)',
            'notice' => 'NOTICE+ errors will be stored (notice, warning, error, critical)',
            'warning' => 'WARNING+ errors will be stored (warning, error, critical)',
            'error' => 'ERROR+ errors will be stored (error, critical)',
            'critical' => 'CRITICAL errors ONLY will be stored',
            'alert' => 'ALERT+ errors will be stored (very high priority)',
            'emergency' => 'EMERGENCY errors ONLY will be stored (system unusable)',
        ];

        $levelDescription = $levelDescriptions[$currentLevel] ?? 'Standard logging';
    }
} catch (\Exception $e) {
    // Fail silently
}
?>
<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE GALAXY: Inject current logging configuration for JavaScript
window.CURRENT_JS_ERRORS_LEVEL = <?php echo json_encode($currentLevel); ?>;
window.CURRENT_JS_ERRORS_CONFIG = <?php echo json_encode($jsErrorsConfig); ?>;
</script>
    <style nonce="<?= csp_nonce() ?>">
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #000;
            min-height: 100vh;
            padding: 20px;
            color: #fff;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: rgb(31 41 55 / 0.8);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(168,85,247,0.3);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgb(168 85 247 / 0.3);
        }
        .header h1 {
            color: #fff;
            font-size: 32px;
            margin-bottom: 10px;
        }
        .header .badge {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin: 5px;
        }
        .card {
            background: rgb(31 41 55 / 0.5);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(168,85,247,0.2);
            margin-bottom: 20px;
            border: 1px solid rgb(75 85 99 / 0.5);
        }
        .card h2 {
            color: rgb(147 197 253);
            font-size: 20px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .button-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .btn-danger { background: #e74c3c; }
        .btn-warning { background: #f39c12; }
        .btn-primary { background: #3498db; }
        .btn-success { background: #2ecc71; }
        .btn-info { background: #1abc9c; }
        .btn-dark { background: #34495e; }
        .error-list {
            list-style: none;
            padding: 0;
        }
        .error-item {
            background: rgb(55 65 81 / 0.5);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #e74c3c;
        }
        .error-item .id {
            font-weight: bold;
            color: rgb(147 197 253);
            margin-right: 10px;
        }
        .error-item .type {
            display: inline-block;
            background: #e74c3c;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-right: 10px;
        }
        .error-item .severity {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: white;
            margin-right: 10px;
        }
        .severity-critical { background: #c0392b; }
        .severity-high { background: #e74c3c; }
        .severity-medium { background: #f39c12; }
        .severity-low { background: #95a5a6; }
        .error-item .message {
            color: #fff;
            margin-top: 5px;
            font-size: 14px;
        }
        .error-item .file {
            color: rgb(156 163 175);
            font-size: 12px;
            font-family: monospace;
            margin-top: 5px;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .alert-warning {
            background: rgb(120 53 15 / 0.3);
            border: 1px solid rgb(251 191 36 / 0.5);
            color: rgb(253 224 71);
        }
        .alert-info {
            background: rgb(12 74 110 / 0.3);
            border: 1px solid rgb(6 182 212 / 0.5);
            color: rgb(103 232 249);
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: rgb(55 65 81 / 0.5);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid rgb(75 85 99 / 0.5);
        }
        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-box .label {
            color: rgb(156 163 175);
            font-size: 14px;
            margin-top: 5px;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: rgb(156 163 175);
        }
        code {
            background: rgb(55 65 81 / 0.5);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 12px;
            color: rgb(147 197 253);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🧪 Enterprise JS Error Testing Dashboard</h1>
            <div>
                <span class="badge">ENTERPRISE GALAXY</span>
                <span class="badge">ERROR MONITORING</span>
                <span class="badge">DOCKER/ORBSTACK</span>
            </div>
        </div>

        <!-- ENTERPRISE GALAXY ULTIMATE: Dual Logging Configuration -->
        <div class="card" style="background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%); border: 2px solid rgba(139, 92, 246, 0.4);">
            <h2>🎛️ Current Logging Configuration (Dual System)</h2>

            <?php
        // Get Database Filter Config
        // ENTERPRISE GALAXY ULTIMATE: Bypass query cache for real-time display (same as EnterpriseLoggingController)
        $dbFilterConfig = null;
try {
    $cacheBypass = '/* NOCACHE-' . microtime(true) . ' */';
    $setting = db()->findOne(
        "SELECT {$cacheBypass} setting_value FROM admin_settings WHERE setting_key = 'js_errors_db_filter_config'",
        [],
        ['cache' => false] // Force no cache
    );
    if ($setting) {
        $dbFilterConfig = json_decode($setting['setting_value'], true);
    }
} catch (\Exception $e) {
    // Fail silently
}
$dbFilterEnabled = $dbFilterConfig['enabled'] ?? true;
$dbFilterMinLevel = $dbFilterConfig['min_level'] ?? 'error';
?>

            <div style="display: grid; grid-template-columns: auto 1fr; gap: 15px; align-items: center; margin-bottom: 20px;">
                <div style="text-align: right; font-weight: bold; color: #667eea;">
                    📄 File Logging Level:
                </div>
                <div>
                    <span style="display: inline-block; background: <?php
                $levelColors = [
                    'debug' => '#8b5cf6',
                    'info' => '#3b82f6',
                    'notice' => '#06b6d4',
                    'warning' => '#f59e0b',
                    'error' => '#ef4444',
                    'critical' => '#dc2626',
                    'alert' => '#991b1b',
                    'emergency' => '#7f1d1d',
                ];
echo $levelColors[$currentLevel] ?? '#3b82f6';
?>; color: white; padding: 8px 20px; border-radius: 8px; font-size: 18px; font-weight: bold;">
                        <?= strtoupper($currentLevel) ?>
                    </span>
                    <small style="display: block; color: rgb(156 163 175); margin-top: 5px;">Configured in JS Errors tab</small>
                </div>

                <div style="text-align: right; font-weight: bold; color: #22c55e;">
                    💾 Database Filter:
                </div>
                <div>
                    <span style="display: inline-block; background: <?= $dbFilterEnabled ? '#22c55e' : '#6b7280' ?>; color: white; padding: 8px 20px; border-radius: 8px; font-size: 18px; font-weight: bold;">
                        <?= $dbFilterEnabled ? strtoupper($dbFilterMinLevel) . '+' : 'ALL LEVELS' ?>
                    </span>
                    <small style="display: block; color: rgb(156 163 175); margin-top: 5px;">Configured in Settings tab</small>
                </div>
            </div>

            <div class="alert alert-warning" style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.4);">
                <strong>⚡ ENTERPRISE GALAXY DUAL SYSTEM:</strong><br>
                <strong>📄 File logs:</strong> Respect js_errors channel level (<code><?= strtoupper($currentLevel) ?></code>)<br>
                <strong>💾 Database:</strong> <?= $dbFilterEnabled ? "Only stores <code>" . strtoupper($dbFilterMinLevel) . "</code> and higher" : "Stores <strong>ALL</strong> levels" ?> (independent filter)<br>
                <strong>🎯 Result:</strong> An error is processed if it passes EITHER filter (file OR database)
            </div>

            <div class="alert alert-info" style="margin-top: 15px;">
                <strong>🎯 Testing Instructions:</strong><br>
                1. File level: <code><?= strtoupper($currentLevel) ?></code> → Logs this level+ to <code>js_errors-{date}.log</code><br>
                2. Database filter: <code><?= $dbFilterEnabled ? strtoupper($dbFilterMinLevel) . '+' : 'ALL' ?></code> → Stores this level+ to <code>enterprise_js_errors</code> table<br>
                3. Trigger test errors below and verify both file and database respect their settings<br>
                4. Change levels in admin panel → Refresh this page → Test again
            </div>
        </div>

        <!-- Important Note -->
        <div class="card">
            <div class="alert alert-warning">
                <strong>⚠️ IMPORTANTE:</strong> Questa pagina testa il sistema <code>enterprise_js_errors</code>.<br>
                <strong>console.log/warn/error</strong> NON vanno in questa tabella (vanno a <code>/api/logs/client</code>).<br>
                Solo <strong>errori JavaScript VERI</strong> vengono salvati in <code>enterprise_js_errors</code>.
            </div>
        </div>

        <!-- Trigger Errors -->
        <div class="card">
            <h2>⚡ Trigger Real JavaScript Errors</h2>
            <p style="color: rgb(156 163 175); margin-bottom: 15px;">Clicca i bottoni per generare errori. La severità determina se l'errore verrà salvato in base al livello configurato:</p>

            <div class="alert alert-info" style="margin-bottom: 15px; font-size: 13px;">
                <strong>📋 Severity Mapping (automatic categorization):</strong><br>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                    <div>
                        • <span style="background: #c0392b; color: white; padding: 2px 6px; border-radius: 3px; font-weight: bold;">CRITICAL</span> → 'critical' log level<br>
                        • <span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 3px; font-weight: bold;">HIGH</span> → 'error' log level
                    </div>
                    <div>
                        • <span style="background: #f39c12; color: white; padding: 2px 6px; border-radius: 3px; font-weight: bold;">MEDIUM</span> → 'warning' log level<br>
                        • <span style="background: #95a5a6; color: white; padding: 2px 6px; border-radius: 3px; font-weight: bold;">LOW</span> → 'info' log level
                    </div>
                </div>
            </div>

            <div class="button-grid">
                <button class="btn btn-danger" onclick="testSyntaxError()" title="Triggers CRITICAL severity error">
                    🔥 Syntax Error<br><small style="font-size: 11px; opacity: 0.8;">(CRITICAL severity)</small>
                </button>
                <button class="btn btn-danger" onclick="testReferenceError()" title="Triggers CRITICAL severity error">
                    🔥 Reference Error<br><small style="font-size: 11px; opacity: 0.8;">(CRITICAL severity)</small>
                </button>
                <button class="btn btn-danger" onclick="testTypeError()" title="Triggers CRITICAL severity error">
                    🔥 Type Error<br><small style="font-size: 11px; opacity: 0.8;">(CRITICAL severity)</small>
                </button>
                <button class="btn btn-warning" onclick="testPromiseRejection()" title="Triggers HIGH severity error">
                    ⚠️ Promise Rejection<br><small style="font-size: 11px; opacity: 0.8;">(HIGH severity)</small>
                </button>
                <button class="btn btn-warning" onclick="testResourceError()" title="Triggers HIGH severity error">
                    ⚠️ Resource Error<br><small style="font-size: 11px; opacity: 0.8;">(HIGH severity)</small>
                </button>
                <button class="btn btn-danger" onclick="testCustomError()" title="Triggers CRITICAL severity error">
                    🔥 Custom Error<br><small style="font-size: 11px; opacity: 0.8;">(CRITICAL severity)</small>
                </button>
            </div>

            <div class="alert alert-warning" style="margin-top: 15px; font-size: 13px;">
                <strong>💡 Expected Behavior with Current Level (<?= strtoupper($currentLevel) ?>):</strong><br>
                <?php
            switch ($currentLevel) {
                case 'emergency':
                    echo '✅ EMERGENCY errors ONLY will be stored<br>';
                    echo '❌ All other levels will be IGNORED';
                    break;
                case 'alert':
                    echo '✅ ALERT and EMERGENCY errors will be stored<br>';
                    echo '❌ CRITICAL, ERROR, WARNING, NOTICE, INFO, DEBUG will be IGNORED';
                    break;
                case 'critical':
                    echo '✅ CRITICAL, ALERT, EMERGENCY errors will be stored<br>';
                    echo '❌ ERROR, WARNING, NOTICE, INFO, DEBUG will be IGNORED';
                    break;
                case 'error':
                    echo '✅ ERROR+ errors will be stored (ERROR, CRITICAL, ALERT, EMERGENCY)<br>';
                    echo '❌ WARNING, NOTICE, INFO, DEBUG will be IGNORED';
                    break;
                case 'warning':
                    echo '✅ WARNING+ errors will be stored (WARNING, ERROR, CRITICAL, ALERT, EMERGENCY)<br>';
                    echo '❌ NOTICE, INFO, DEBUG will be IGNORED';
                    break;
                case 'notice':
                    echo '✅ NOTICE+ errors will be stored (NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY)<br>';
                    echo '❌ INFO, DEBUG will be IGNORED';
                    break;
                case 'info':
                    echo '✅ INFO+ errors will be stored (INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY)<br>';
                    echo '❌ DEBUG will be IGNORED';
                    break;
                case 'debug':
                    echo '✅ ALL errors will be stored (DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY)';
                    break;
                default:
                    echo '✅ Errors at or above this level will be stored';
            }
?>
            </div>
        </div>

        <!-- ENTERPRISE GALAXY: Direct PSR-3 Level Tests -->
        <div class="card">
            <h2>🎯 Direct PSR-3 Level Tests (All 8 Levels)</h2>
            <p style="color: rgb(156 163 175); margin-bottom: 15px;">Test diretto di ogni livello PSR-3. Questi test inviano log client che rispettano i livelli configurati:</p>

            <div class="alert alert-info" style="margin-bottom: 15px; font-size: 13px;">
                <strong>📊 PSR-3 Level Hierarchy (low to high):</strong><br>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; margin-top: 10px; font-size: 12px;">
                    <div style="background: #8b5cf6; color: white; padding: 5px; border-radius: 4px; text-align: center;">DEBUG</div>
                    <div style="background: #3b82f6; color: white; padding: 5px; border-radius: 4px; text-align: center;">INFO</div>
                    <div style="background: #06b6d4; color: white; padding: 5px; border-radius: 4px; text-align: center;">NOTICE</div>
                    <div style="background: #f59e0b; color: white; padding: 5px; border-radius: 4px; text-align: center;">WARNING</div>
                    <div style="background: #ef4444; color: white; padding: 5px; border-radius: 4px; text-align: center;">ERROR</div>
                    <div style="background: #dc2626; color: white; padding: 5px; border-radius: 4px; text-align: center;">CRITICAL</div>
                    <div style="background: #991b1b; color: white; padding: 5px; border-radius: 4px; text-align: center;">ALERT</div>
                    <div style="background: #7f1d1d; color: white; padding: 5px; border-radius: 4px; text-align: center;">EMERGENCY</div>
                </div>
            </div>

            <div class="button-grid">
                <button class="btn" style="background: #8b5cf6;" onclick="testPsr3Level('debug', event)" title="DEBUG level - lowest priority">
                    🐛 DEBUG Level<br><small style="font-size: 11px; opacity: 0.8;">(Debug information)</small>
                </button>
                <button class="btn" style="background: #3b82f6;" onclick="testPsr3Level('info', event)" title="INFO level - informational">
                    ℹ️ INFO Level<br><small style="font-size: 11px; opacity: 0.8;">(Informational messages)</small>
                </button>
                <button class="btn" style="background: #06b6d4;" onclick="testPsr3Level('notice', event)" title="NOTICE level - normal but significant">
                    📢 NOTICE Level<br><small style="font-size: 11px; opacity: 0.8;">(Normal but significant)</small>
                </button>
                <button class="btn" style="background: #f59e0b;" onclick="testPsr3Level('warning', event)" title="WARNING level - warning conditions">
                    ⚠️ WARNING Level<br><small style="font-size: 11px; opacity: 0.8;">(Warning conditions)</small>
                </button>
                <button class="btn" style="background: #ef4444;" onclick="testPsr3Level('error', event)" title="ERROR level - error conditions">
                    ❌ ERROR Level<br><small style="font-size: 11px; opacity: 0.8;">(Error conditions)</small>
                </button>
                <button class="btn" style="background: #dc2626;" onclick="testPsr3Level('critical', event)" title="CRITICAL level - critical conditions">
                    🔥 CRITICAL Level<br><small style="font-size: 11px; opacity: 0.8;">(Critical conditions)</small>
                </button>
                <button class="btn" style="background: #991b1b;" onclick="testPsr3Level('alert', event)" title="ALERT level - immediate action required">
                    🚨 ALERT Level<br><small style="font-size: 11px; opacity: 0.8;">(Action must be taken)</small>
                </button>
                <button class="btn" style="background: #7f1d1d;" onclick="testPsr3Level('emergency', event)" title="EMERGENCY level - system is unusable">
                    🆘 EMERGENCY Level<br><small style="font-size: 11px; opacity: 0.8;">(System is unusable)</small>
                </button>
            </div>

            <div class="alert alert-warning" style="margin-top: 15px; font-size: 13px;">
                <strong>💡 How These Tests Work:</strong><br>
                • Click a level button → Sends error to <code>/api/enterprise-logging</code> with explicit PSR-3 level<br>
                • <code>EnterpriseLoggingController::handleErrorReport()</code> processes the request<br>
                • <code>should_log('js_errors', level)</code> checks if it should be stored<br>
                • If level passes → writes to BOTH log file (<code>js_errors-{date}.log</code>) AND database (<code>enterprise_js_errors</code>)<br>
                • Only logs at or above configured level (<?= strtoupper($currentLevel) ?>) will be stored<br>
                • Check database + log files to verify which levels were stored
            </div>
        </div>

        <!-- Database Stats -->
        <div class="card">
            <h2>📊 Database Statistics</h2>
            <div class="stats" id="stats">
                <div class="stat-box">
                    <div class="number" id="total-errors">-</div>
                    <div class="label">Total Errors</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="critical-errors">-</div>
                    <div class="label">Critical</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="high-errors">-</div>
                    <div class="label">High</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="medium-errors">-</div>
                    <div class="label">Medium</div>
                </div>
            </div>
            <button class="btn btn-primary" onclick="refreshDatabase()">🔄 Refresh Database</button>
        </div>

        <!-- Recent Errors -->
        <div class="card">
            <h2>🗄️ Recent Errors from Database</h2>
            <div class="alert alert-info">
                Gli ultimi 10 errori salvati in <code>enterprise_js_errors</code>.
                Se la lista è vuota, prova a triggerare degli errori sopra.
            </div>
            <div id="error-list">
                <div class="loading">Loading...</div>
            </div>
        </div>

        <!-- Instructions -->
        <div class="card">
            <h2>📖 How It Works</h2>
            <ol style="line-height: 2;">
                <li>Click an error button → Browser generates real JS error</li>
                <li><code>window.addEventListener('error')</code> catches it (enterprise-error-monitor.js:178)</li>
                <li><code>reportError()</code> called (line 192)</li>
                <li><code>sendToEnterpriseLogging()</code> sends to <code>/api/enterprise-logging</code> (line 474)</li>
                <li><code>EnterpriseLoggingController::handleErrorReport()</code> processes (line 150)</li>
                <li><code>storeErrorInDatabase()</code> saves to DB (line 296)</li>
                <li>✅ Record appears in table!</li>
            </ol>
            <div class="alert alert-info" style="margin-top: 15px;">
                <strong>💡 Pro Tip:</strong> Apri DevTools → Network tab per vedere le richieste POST a <code>/api/enterprise-logging</code>
            </div>
        </div>
    </div>

    <!-- Load Enterprise Error Monitor -->
    <script src="/assets/js/core/enterprise-error-monitor.js?v=<?php echo time(); ?>"></script>

    <!-- Test Functions - ENTERPRISE REFRESH FIX v2 -->
    <script nonce="<?= csp_nonce() ?>" data-version="<?php echo time(); ?>">
        // IMPORTANT: Set verbose mode to see what's happening
        if (window.Need2Talk && window.Need2Talk.EnterpriseErrorMonitor) {
            Need2Talk.EnterpriseErrorMonitor.verbose = true;
        }

        // ================================================================
        // TEST FUNCTIONS - These generate REAL errors that go to DB
        // ================================================================

        function testSyntaxError() {
            console.debug('🧪 Testing Syntax Error...');
            try {
                eval('var x = {');  // Incomplete object literal
            } catch (e) {
                // Even in try/catch, error event fires before catch
                console.info('✅ SyntaxError triggered!');
            }
        }

        function testReferenceError() {
            console.debug('🧪 Testing Reference Error...');
            nonExistentFunction();  // ReferenceError: not defined
        }

        function testTypeError() {
            console.debug('🧪 Testing Type Error...');
            null.someMethod();  // TypeError: Cannot read property 'someMethod' of null
        }

        function testPromiseRejection() {
            console.debug('🧪 Testing Promise Rejection...');
            Promise.reject(new Error('Test unhandled promise rejection from test page'));
        }

        function testResourceError() {
            console.debug('🧪 Testing Resource Error...');
            const img = document.createElement('img');
            img.src = '/nonexistent-test-image-' + Date.now() + '.jpg';
            img.onerror = function() {
                console.info('✅ Resource error triggered!');
            };
            document.body.appendChild(img);
            setTimeout(() => img.remove(), 2000);
        }

        function testCustomError() {
            console.debug('🧪 Testing Custom Error...');
            throw new Error('Custom test error from test page with full stack trace');
        }

        // ================================================================
        // PSR-3 LEVEL TEST FUNCTIONS
        // ================================================================

        /**
         * Test specific PSR-3 logging level
         * ENTERPRISE GALAXY: Sends to /api/enterprise-logging which writes to BOTH logs AND database
         */
        async function testPsr3Level(level, evt) {
            console.debug(`🧪 Testing PSR-3 Level: ${level.toUpperCase()}`);

            const levelDescriptions = {
                'debug': 'Debug information for developers',
                'info': 'Informational message',
                'notice': 'Normal but significant condition',
                'warning': 'Warning condition',
                'error': 'Error condition',
                'critical': 'Critical condition',
                'alert': 'Action must be taken immediately',
                'emergency': 'System is unusable'
            };

            // ENTERPRISE GALAXY: Payload for /api/enterprise-logging
            const payload = {
                type: 'error_report',
                psr3_level: level,  // Explicit PSR-3 level for testing
                data: {
                    type: 'psr3_test',
                    message: `Test ${level.toUpperCase()} level log from PSR-3 test suite: ${levelDescriptions[level]}`,
                    filename: 'js-error-test.php',
                    lineno: 0,
                    colno: 0,
                    stack: `PSR-3 Test Stack:\n  at testPsr3Level (js-error-test.php:${level}_test)\n  at Test Suite (admin panel)`,
                    timestamp: new Date().toISOString()
                },
                context: {
                    url: window.location.href,
                    userAgent: navigator.userAgent,
                    viewport: {
                        width: window.innerWidth,
                        height: window.innerHeight
                    },
                    userId: window.Need2Talk?._uid || null,
                    test_mode: true,
                    test_level: level,
                    description: levelDescriptions[level]
                }
            };

            try {
                const response = await fetch('/api/enterprise-logging', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Enterprise-Monitor': 'true'
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();

                if (data.status === 'success' || data.logged) {
                    console.info(`✅ ${level.toUpperCase()} level test sent successfully`);
                    console.debug(`📊 Current config level: ${window.CURRENT_JS_ERRORS_LEVEL}`);
                    console.debug(`💾 Logged to: js_errors-${new Date().toISOString().split('T')[0]}.log + database`);

                    // Show visual feedback
                    if (evt) {
                        const button = evt.target.closest('button');
                        if (button) {
                            const originalHTML = button.innerHTML;
                            button.innerHTML = '✅ Sent!';
                            button.disabled = true;
                            setTimeout(() => {
                                button.innerHTML = originalHTML;
                                button.disabled = false;
                            }, 1500);
                        }
                    }

                    // Auto-refresh database after 1 second
                    setTimeout(refreshDatabase, 1000);
                } else {
                    console.error(`❌ Failed to send ${level} test:`, data);
                    alert(`Failed to send ${level} test: ${data.error || 'Unknown error'}`);
                }
            } catch (error) {
                console.error(`❌ Network error testing ${level}:`, error);
                alert(`Network error: ${error.message}`);
            }
        }

        // ================================================================
        // DATABASE QUERY FUNCTIONS
        // ================================================================

        async function refreshDatabase() {
            console.debug('🔄 Refreshing database...');
            document.getElementById('error-list').innerHTML = '<div class="loading">Loading...</div>';

            try {
                // ENTERPRISE TIPS: Use absolute URL with current protocol to avoid redirects
                const timestamp = Date.now();
                const protocol = window.location.protocol; // http: or https:
                const host = window.location.host;
                const url = `${protocol}//${host}/api/enterprise-logging/recent?_=${timestamp}`;
                console.debug('📡 Fetching:', url);

                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache',
                        'Expires': '0'
                    }
                });

                console.debug('📥 Response status:', response.status);
                const data = await response.json();
                console.debug('📦 Data received:', data);
                console.debug('✅ Errors count:', data.errors ? data.errors.length : 0);

                if (data.success) {
                    displayErrors(data.errors);
                    updateStats(data);
                    console.info('✅ Display completed!');
                } else {
                    console.error('❌ API returned error:', data);
                    document.getElementById('error-list').innerHTML =
                        '<div class="alert alert-warning">❌ Failed to load errors: ' + (data.message || 'Unknown error') + '</div>';
                }
            } catch (error) {
                console.error('❌ Failed to fetch errors:', error);
                document.getElementById('error-list').innerHTML =
                    '<div class="alert alert-warning">❌ Network error: ' + error.message + '</div>';
            }
        }

        function displayErrors(errors) {
            const container = document.getElementById('error-list');

            if (!errors || errors.length === 0) {
                container.innerHTML = '<div class="alert alert-info">📭 No errors in database yet. Try triggering some errors above!</div>';
                return;
            }

            let html = '<ul class="error-list">';
            errors.forEach(error => {
                const severityClass = 'severity-' + error.severity;
                html += `
                    <li class="error-item">
                        <div>
                            <span class="id">#${error.id}</span>
                            <span class="type">${error.error_type}</span>
                            <span class="severity ${severityClass}">${error.severity.toUpperCase()}</span>
                            <span style="color: #999; font-size: 12px;">${error.created_at}</span>
                        </div>
                        <div class="message">${escapeHtml(error.message)}</div>
                        ${error.filename ? `<div class="file">📄 ${error.filename}:${error.line_number || '?'}</div>` : ''}
                    </li>
                `;
            });
            html += '</ul>';

            container.innerHTML = html;
        }

        function updateStats(data) {
            document.getElementById('total-errors').textContent = data.total || 0;

            // Count by severity
            const counts = { critical: 0, high: 0, medium: 0, low: 0 };
            if (data.errors) {
                data.errors.forEach(err => {
                    if (counts.hasOwnProperty(err.severity)) {
                        counts[err.severity]++;
                    }
                });
            }

            document.getElementById('critical-errors').textContent = counts.critical;
            document.getElementById('high-errors').textContent = counts.high;
            document.getElementById('medium-errors').textContent = counts.medium;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ================================================================
        // AUTO-REFRESH
        // ================================================================

        // Initial load
        refreshDatabase();

        // Auto-refresh every 5 seconds
        setInterval(refreshDatabase, 5000);

        console.info('✅ Enterprise JS Error Testing Page loaded successfully!');
        console.debug('💡 Verbose mode enabled - check console for detailed logs');
    </script>
</body>
</html>
