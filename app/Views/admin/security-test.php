<?php
// ENTERPRISE SECURITY: Admin-only page - MUST be authenticated
// This page generates test security events and MUST NOT be accessible to non-admin users

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
    <title>🧪 Enterprise Security Events Testing - Need2Talk [v<?php echo time(); ?>]</title>
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
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            background: rgb(31 41 55 / 0.8);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(220,38,38,0.3);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgb(220 38 38 / 0.3);
        }
        .header h1 {
            color: #fff;
            font-size: 32px;
            margin-bottom: 10px;
        }
        .header .badge {
            display: inline-block;
            background: #dc2626;
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
            box-shadow: 0 10px 40px rgba(220,38,38,0.2);
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            text-align: left;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-emergency { background: #7f1d1d; }
        .btn-alert { background: #991b1b; }
        .btn-critical { background: #dc2626; }
        .btn-error { background: #ef4444; }
        .btn-warning { background: #f59e0b; }
        .btn-notice { background: #10b981; }
        .btn-info { background: #3b82f6; }
        .btn-debug { background: #8b5cf6; }
        .event-list {
            list-style: none;
            padding: 0;
            max-height: 600px;
            overflow-y: auto;
        }
        .event-item {
            background: rgb(55 65 81 / 0.5);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #f59e0b;
        }
        .event-item .id {
            font-weight: bold;
            color: rgb(147 197 253);
            margin-right: 10px;
        }
        .event-item .level {
            display: inline-block;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin-right: 10px;
            font-weight: bold;
        }
        .level-emergency { background: #7f1d1d; }
        .level-alert { background: #991b1b; }
        .level-critical { background: #dc2626; }
        .level-error { background: #ef4444; }
        .level-warning { background: #f59e0b; }
        .level-notice { background: #10b981; }
        .level-info { background: #3b82f6; }
        .level-debug { background: #8b5cf6; }
        .event-item .message {
            color: #fff;
            margin-top: 5px;
            font-size: 13px;
        }
        .event-item .context {
            color: rgb(203 213 225);
            font-size: 11px;
            font-family: monospace;
            margin-top: 5px;
            background: rgb(17 24 39);
            padding: 5px;
            border-radius: 4px;
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
        .alert-success {
            background: rgb(20 83 45 / 0.3);
            border: 1px solid rgb(34 197 94 / 0.5);
            color: rgb(134 239 172);
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
            font-size: 28px;
            font-weight: bold;
            color: #dc2626;
        }
        .stat-box .label {
            color: rgb(156 163 175);
            font-size: 12px;
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
        .scenario-desc {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🧪 Enterprise Security Events Testing Dashboard</h1>
            <div>
                <span class="badge">ENTERPRISE GALAXY</span>
                <span class="badge">DUAL-WRITE SYSTEM</span>
                <span class="badge">DB + FILE LOGS</span>
            </div>
        </div>

        <!-- Important Note -->
        <div class="card">
            <div class="alert alert-info">
                <strong>🎯 Sistema Dual-Write</strong><br>
                Ogni evento di sicurezza viene scritto CONTEMPORANEAMENTE in:
                <ul style="margin-top: 10px; margin-left: 20px;">
                    <li><strong>Database</strong>: tabella <code>security_events</code> (monitoraggio real-time)</li>
                    <li><strong>File logs</strong>: <code>storage/logs/security-{date}.log</code> (audit trail permanente)</li>
                </ul>
            </div>
        </div>

        <!-- PSR-3 Level Tests -->
        <div class="card">
            <h2>🎯 Test PSR-3 Levels (All 8 Levels)</h2>
            <p style="color: rgb(156 163 175); margin-bottom: 15px;">Test dei livelli PSR-3 con scenari di sicurezza reali:</p>

            <div class="alert alert-warning" style="margin-bottom: 15px; font-size: 13px;">
                <strong>📊 PSR-3 Hierarchy (dalla meno critica alla più critica):</strong><br>
                DEBUG → INFO → NOTICE → WARNING → ERROR → CRITICAL → ALERT → EMERGENCY
            </div>

            <div class="button-grid">
                <button class="btn btn-debug" onclick="testLevel('debug', 'generic_test')" title="DEBUG - Lowest priority">
                    🐛 DEBUG Level
                    <div class="scenario-desc">Generic test event</div>
                </button>
                <button class="btn btn-info" onclick="testLevel('info', 'data_access')" title="INFO - Informational">
                    ℹ️ INFO Level
                    <div class="scenario-desc">Sensitive data access</div>
                </button>
                <button class="btn btn-notice" onclick="testLevel('notice', 'session_management')" title="NOTICE - Normal but significant">
                    📢 NOTICE Level
                    <div class="scenario-desc">Session management event</div>
                </button>
                <button class="btn btn-warning" onclick="testLevel('warning', 'login_attempt')" title="WARNING - Warning conditions">
                    ⚠️ WARNING Level
                    <div class="scenario-desc">Failed login attempt</div>
                </button>
                <button class="btn btn-error" onclick="testLevel('error', 'authorization_failure')" title="ERROR - Error conditions">
                    ❌ ERROR Level
                    <div class="scenario-desc">Authorization check failed</div>
                </button>
                <button class="btn btn-critical" onclick="testLevel('critical', 'suspicious_activity')" title="CRITICAL - Critical conditions">
                    🔥 CRITICAL Level
                    <div class="scenario-desc">Suspicious activity detected</div>
                </button>
                <button class="btn btn-alert" onclick="testLevel('alert', 'rate_limit')" title="ALERT - Immediate action required">
                    🚨 ALERT Level
                    <div class="scenario-desc">Rate limit exceeded</div>
                </button>
                <button class="btn btn-emergency" onclick="testLevel('emergency', 'csrf_attack')" title="EMERGENCY - System is unusable">
                    🆘 EMERGENCY Level
                    <div class="scenario-desc">CSRF attack detected</div>
                </button>
            </div>

            <div class="alert alert-info" style="font-size: 13px;">
                <strong>💡 Come funziona:</strong><br>
                • Click su un bottone → Invia richiesta a <code>/api/security-test/generate</code><br>
                • <code>SecurityTestController::generateTestEvent()</code> processa la richiesta<br>
                • <code>Logger::security()</code> scrive CONTEMPORANEAMENTE su DB e file log<br>
                • Verifica entrambe le destinazioni per confermare il dual-write
            </div>
        </div>

        <!-- Scenario Tests -->
        <div class="card">
            <h2>🎬 Security Scenario Tests</h2>
            <p style="color: rgb(156 163 175); margin-bottom: 15px;">Scenari di sicurezza comuni con livello WARNING:</p>

            <div class="button-grid">
                <button class="btn btn-warning" onclick="testScenario('login_attempt')" title="Test failed login">
                    🔐 Login Attempt
                    <div class="scenario-desc">Failed login with invalid credentials</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('authorization_failure')" title="Test authorization">
                    🚫 Authorization Failure
                    <div class="scenario-desc">Access to protected resource denied</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('suspicious_activity')" title="Test suspicious behavior">
                    👁️ Suspicious Activity
                    <div class="scenario-desc">Multiple failed attempts detected</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('data_access')" title="Test data access">
                    📊 Data Access
                    <div class="scenario-desc">Sensitive data read operation</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('configuration_change')" title="Test config change">
                    ⚙️ Configuration Change
                    <div class="scenario-desc">System settings modified</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('rate_limit')" title="Test rate limiting">
                    🚦 Rate Limit
                    <div class="scenario-desc">API request limit exceeded</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('session_management')" title="Test session ops">
                    🔄 Session Management
                    <div class="scenario-desc">Force logout operation</div>
                </button>
                <button class="btn btn-warning" onclick="testScenario('csrf_attack')" title="Test CSRF detection">
                    🛡️ CSRF Attack
                    <div class="scenario-desc">Invalid CSRF token detected</div>
                </button>
            </div>
        </div>

        <!-- Database Stats -->
        <div class="card">
            <h2>📊 Database Statistics</h2>
            <div class="stats" id="stats">
                <div class="stat-box">
                    <div class="number" id="total-events">-</div>
                    <div class="label">Total Events</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="emergency-count">-</div>
                    <div class="label">Emergency</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="alert-count">-</div>
                    <div class="label">Alert</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="critical-count">-</div>
                    <div class="label">Critical</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="error-count">-</div>
                    <div class="label">Error</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="warning-count">-</div>
                    <div class="label">Warning</div>
                </div>
            </div>
            <button class="btn" style="background: #3b82f6; width: 100%;" onclick="refreshDatabase()">🔄 Refresh Database</button>
        </div>

        <!-- Recent Events -->
        <div class="card">
            <h2>🗄️ Recent Events from Database (Last 20)</h2>
            <div class="alert alert-success">
                Eventi recenti dalla tabella <code>security_events</code>.
                Usa il pulsante Refresh per aggiornare la lista.
            </div>
            <div id="event-list">
                <div class="loading">Loading...</div>
            </div>
        </div>

        <!-- Instructions -->
        <div class="card">
            <h2>📖 Verification Checklist</h2>
            <ol style="line-height: 2; margin-left: 20px;">
                <li>✅ Click su un bottone per generare un evento di test</li>
                <li>✅ Verifica che l'evento appaia nella lista qui sotto (Database)</li>
                <li>✅ Apri la pagina <strong>Security Events</strong> nel menu e verifica che l'evento sia presente</li>
                <li>✅ Controlla i file log in <code>storage/logs/security-{date}.log</code></li>
                <li>✅ Conferma che l'evento è stato scritto sia in DB che nei file (dual-write)</li>
            </ol>
            <div class="alert alert-info" style="margin-top: 15px;">
                <strong>💡 Pro Tip:</strong> Apri DevTools → Network tab per vedere le richieste POST a <code>/api/security-test/generate</code>
            </div>
        </div>
    </div>

    <!-- Test Functions -->
    <script nonce="<?= csp_nonce() ?>">
        // Test PSR-3 level with specific scenario
        async function testLevel(level, scenario) {
            console.debug(`🧪 Testing ${level.toUpperCase()} level with scenario: ${scenario}`);

            const button = event.target.closest('button');
            const originalHTML = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '⏳ Sending...';

            try {
                const response = await fetch('api/security-test/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        level: level,
                        scenario: scenario
                    })
                });

                const data = await response.json();

                if (data.success) {
                    console.info(`✅ ${level.toUpperCase()} event generated:`, data);
                    button.innerHTML = '✅ Sent!';
                    setTimeout(() => {
                        button.innerHTML = originalHTML;
                        button.disabled = false;
                    }, 1500);

                    // Auto-refresh after 1 second
                    setTimeout(refreshDatabase, 1000);
                } else {
                    console.error('❌ Failed:', data);
                    alert('Failed: ' + (data.error || 'Unknown error'));
                    button.innerHTML = originalHTML;
                    button.disabled = false;
                }
            } catch (error) {
                console.error('❌ Network error:', error);
                alert('Network error: ' + error.message);
                button.innerHTML = originalHTML;
                button.disabled = false;
            }
        }

        // Test specific scenario with WARNING level
        async function testScenario(scenario) {
            await testLevel('warning', scenario);
        }

        // Refresh database events
        async function refreshDatabase() {
            console.debug('🔄 Refreshing database...');
            document.getElementById('event-list').innerHTML = '<div class="loading">Loading...</div>';

            try {
                const timestamp = Date.now();
                const response = await fetch(`api/security-test/recent?_=${timestamp}`, {
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache',
                        'Expires': '0'
                    }
                });

                const data = await response.json();
                console.debug('📦 Data received:', data);

                if (data.success) {
                    displayEvents(data.events);
                    updateStats(data);
                    console.info('✅ Display completed!');
                } else {
                    console.error('❌ API error:', data);
                    document.getElementById('event-list').innerHTML =
                        '<div class="alert alert-warning">❌ Failed to load events: ' + (data.error || 'Unknown error') + '</div>';
                }
            } catch (error) {
                console.error('❌ Failed to fetch:', error);
                document.getElementById('event-list').innerHTML =
                    '<div class="alert alert-warning">❌ Network error: ' + error.message + '</div>';
            }
        }

        function displayEvents(events) {
            const container = document.getElementById('event-list');

            if (!events || events.length === 0) {
                container.innerHTML = '<div class="alert alert-info">📭 No events yet. Try generating some test events above!</div>';
                return;
            }

            let html = '<ul class="event-list">';
            events.forEach(event => {
                const levelClass = 'level-' + event.level;
                const created = new Date(event.created_at).toLocaleString('it-IT');
                const context = event.context ? JSON.parse(event.context) : {};
                const contextStr = JSON.stringify(context, null, 2);

                html += `
                    <li class="event-item">
                        <div>
                            <span class="id">#${event.id}</span>
                            <span class="level ${levelClass}">${event.level.toUpperCase()}</span>
                            <span style="color: rgb(156 163 175); font-size: 11px;">${created}</span>
                        </div>
                        <div class="message">${escapeHtml(event.message)}</div>
                        ${event.ip_address ? `<div style="color: rgb(156 163 175); font-size: 11px; margin-top: 3px;">📍 IP: ${event.ip_address}</div>` : ''}
                        ${contextStr !== '{}' ? `<div class="context">${escapeHtml(contextStr)}</div>` : ''}
                    </li>
                `;
            });
            html += '</ul>';

            container.innerHTML = html;
        }

        function updateStats(data) {
            document.getElementById('total-events').textContent = data.total || 0;

            const counts = data.level_counts || {};
            document.getElementById('emergency-count').textContent = counts.emergency || 0;
            document.getElementById('alert-count').textContent = counts.alert || 0;
            document.getElementById('critical-count').textContent = counts.critical || 0;
            document.getElementById('error-count').textContent = counts.error || 0;
            document.getElementById('warning-count').textContent = counts.warning || 0;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initial load
        refreshDatabase();

        // Auto-refresh every 5 seconds
        setInterval(refreshDatabase, 5000);

        console.info('✅ Enterprise Security Events Testing Page loaded!');
    </script>
</body>
</html>
