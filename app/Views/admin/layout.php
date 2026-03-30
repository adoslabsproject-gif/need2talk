<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>need2talk - <?= htmlspecialchars($title) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/img/favicon.ico">

    <!-- CSRF Token for AJAX requests -->
    <?= \Need2Talk\Middleware\CsrfMiddleware::tokenMeta() ?>

    <!-- Admin CSS with MIDNIGHT AURORA palette + Custom Admin Components -->
    <link rel="stylesheet" href="<?= asset('base.css') ?>">

    <!-- FontAwesome REMOVED - Using Heroicons SVG system (zero CSS footprint) -->

    <?php
    /**
     * ENTERPRISE GALAXY: Auto-Sync VERBOSE_LOGGING with js_errors channel level
     *
     * ZERO-DOWNTIME: Reads real-time config from LoggingConfigService (Redis L1 cache)
     * SCALABILITY: Single query, cached result, millions of concurrent users ready
     * ARCHITECTURE: Ensures client-side verbose flag matches server-side logging level
     *
     * Flow:
     * 1. LoggingConfigService reads js_errors level from Redis L1 (5ms) or app_settings table
     * 2. If level = 'debug' → window.VERBOSE_LOGGING = true (console.debug sent to server)
     * 3. Otherwise → window.VERBOSE_LOGGING = false (console.debug filtered client-side)
     * 4. EnterpriseErrorMonitor reads this flag on init (line 60 of enterprise-error-monitor.js)
     */
    $jsErrorsVerbose = false; // Default: filter debug logs

    try {
        if (class_exists('Need2Talk\\Services\\LoggingConfigService')) {
            $loggingService = \Need2Talk\Services\LoggingConfigService::getInstance();
            $config = $loggingService->getConfiguration(skipCache: false); // Use cache for performance
            $jsErrorsLevel = $config['js_errors']['level'] ?? 'info';

            // Enable verbose mode ONLY if js_errors channel is set to 'debug'
            $jsErrorsVerbose = ($jsErrorsLevel === 'debug');
        }
    } catch (\Exception $e) {
        // Fallback: If service fails, default to false (safe mode)
        $jsErrorsVerbose = false;
    }
    ?>

    <!-- ENTERPRISE GALAXY: Set VERBOSE_LOGGING before loading error monitor -->
    <script nonce="<?= csp_nonce() ?>">
        /**
         * ENTERPRISE GALAXY: Synchronized verbose logging
         *
         * This flag is automatically synchronized with the js_errors logging channel level.
         * When js_errors channel is set to 'debug' in admin panel, this is true.
         * Otherwise, it's false to prevent console.debug from flooding the server.
         *
         * Current state: <?= $jsErrorsVerbose ? 'VERBOSE (debug enabled)' : 'NORMAL (debug filtered)' ?>
         */
        window.VERBOSE_LOGGING = <?= $jsErrorsVerbose ? 'true' : 'false' ?>;
    </script>

    <!-- ENTERPRISE GALAXY: JavaScript Error Monitor - Centralized Logging [MINIFIED] -->
    <script src="/assets/js/core/enterprise-error-monitor.min.js"></script>

    <?php
    // ENTERPRISE: Inject debugbar HEAD assets
    if (function_exists('debugbar_render_head')) {
        echo debugbar_render_head();
    }
    ?>
</head>
<body class="min-h-screen pt-20" style="background: #0f0f0f; color: #ffffff;">
    <header class="backdrop-blur-lg border-b px-6 lg:px-8 py-4 fixed top-0 left-0 right-0 z-50" style="background: rgba(15, 15, 15, 0.9); border-color: rgba(147, 51, 234, 0.2);">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <h1 class="text-2xl font-bold bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent">
                🚀 need2talk Enterprise Admin
            </h1>
            <div class="bg-green-500/20 border border-green-500/50 rounded-lg px-4 py-2 text-sm text-green-300">
                ⚡ Lightning Framework v1.2.0
            </div>
        </div>
    </header>

    <!-- ENTERPRISE GALAXY: 2-COLUMN LAYOUT (Vertical Sidebar + Content) -->
    <div class="flex">
        <!-- VERTICAL SIDEBAR NAVIGATION (Alphabetically Ordered) -->
        <!-- ENTERPRISE FIX: scroll-y-auto + pb-16 for scrollable sidebar with room for logout button -->
        <aside class="w-64 backdrop-blur-sm border-r px-4 py-6 pb-16 fixed left-0 top-20 z-40 overflow-hidden" style="background: rgba(15, 15, 15, 0.95); border-color: rgba(147, 51, 234, 0.3); height: calc(100vh - 5rem);">
            <div class="h-full overflow-y-auto pr-2" style="scrollbar-width: thin; scrollbar-color: rgba(147, 51, 234, 0.5) transparent;">
            <?php
                // Check if current view is a worker page (for auto-expanding dropdown)
                // ENTERPRISE GALAXY V11.6: Added notification-workers to Workers menu
                $workerViews = ['audio-workers', 'dm-audio-workers', 'email-metrics', 'newsletter', 'notification-workers', 'overlay-workers', 'cron'];
                $isWorkerView = in_array($view, $workerViews);
                ?>
                <nav>
                <ul class="space-y-2">
                    <li><a href="account-deletions" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'account-deletions-dashboard' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🗑️ Cancellazioni Account</a></li>
                    <li><a href="anti-scan" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'anti-scan' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🛡️ Anti-Scan</a></li>
                    <li><a href="audio" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'audio' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🎵 Audio</a></li>
                    <li><a href="audit" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'audit' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🔍 Audit</a></li>
                    <li><a href="cookies" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'cookies' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🍪 Cookies</a></li>
                    <li><a href="dashboard" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'dashboard' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">📊 Dashboard</a></li>
                    <li><a href="emotional-analytics" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'emotional-analytics' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🧠 Emotional Analytics</a></li>
                    <li><a href="enterprise" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'enterprise' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">⚡ Enterprise V8.0</a></li>
                    <li><a href="js-errors" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'js-errors' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🐛 JS Errors</a></li>
                    <li><a href="legitimate-bots" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'legitimate-bots' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🤖 Bot Legittimi</a></li>
                    <li><a href="logs" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'logs' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">📋 Logs</a></li>
                    <li><a href="moderators" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'moderators' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🛡️ Moderatori</a></li>
                    <li><a href="performance" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'performance' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🚀 Performance</a></li>
                    <li><a href="users" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'users' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">👥 Utenti e Rate Limit</a></li>
                    <li><a href="ml-security" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'ml-security' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🧠 ML Security & DDoS</a></li>
                    <li><a href="security" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'security' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">🔒 Security</a></li>
                    <li><a href="settings" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'settings' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">⚙️ Settings</a></li>
                    <li><a href="stats" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'stats' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>">📈 Stats</a></li>

                    <!-- WORKERS DROPDOWN MENU -->
                    <li class="pt-2 mt-2 border-t border-gray-700/30">
                        <button id="workers-dropdown-btn"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $isWorkerView ? 'bg-gradient-to-r from-purple-500/20 to-cyan-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?>"
                                onclick="toggleWorkersDropdown()">
                            <span class="flex items-center">
                                <span class="mr-2">🛠️</span>
                                <span>Workers</span>
                                <?php if ($isWorkerView): ?>
                                <span class="ml-2 px-1.5 py-0.5 text-xs rounded bg-green-500/30 text-green-300">ATTIVO</span>
                                <?php endif; ?>
                            </span>
                            <svg id="workers-dropdown-arrow" class="w-4 h-4 transition-transform duration-200 <?= $isWorkerView ? 'rotate-180' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <!-- Workers Sub-Menu -->
                        <ul id="workers-dropdown-menu" class="mt-1 ml-4 space-y-1 overflow-hidden transition-all duration-300 <?= $isWorkerView ? 'max-h-96 opacity-100' : 'max-h-0 opacity-0' ?>">
                            <li>
                                <a href="audio-workers" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'audio-workers' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">🎵</span> Audio Post Workers
                                </a>
                            </li>
                            <li>
                                <a href="dm-audio-workers" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'dm-audio-workers' ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">💬</span> DM Audio Workers
                                </a>
                            </li>
                            <li>
                                <a href="email-metrics" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'email-metrics' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">📧</span> Email Workers
                                </a>
                            </li>
                            <li>
                                <a href="newsletter" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'newsletter' ? 'bg-pink-500/20 text-pink-300 border border-pink-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">📬</span> Newsletter Workers
                                </a>
                            </li>
                            <li>
                                <a href="notification-workers" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'notification-workers' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">🔔</span> Notification Workers
                                </a>
                            </li>
                            <li>
                                <a href="overlay-workers" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'overlay-workers' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">🔄</span> Overlay Workers
                                </a>
                            </li>
                            <li>
                                <a href="cron" class="block px-3 py-2 rounded-lg text-sm transition-all duration-300 <?= $view === 'cron' ? 'bg-orange-500/20 text-orange-300 border border-orange-500/30' : 'text-gray-400 hover:bg-gray-700/50 hover:text-white' ?>">
                                    <span class="inline-block w-5 text-center mr-1">⏰</span> Cron Jobs
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- Logout button -->
                    <li class="pt-4 border-t border-gray-700/50 mt-4">
                        <form action="logout" method="POST">
                            <button type="submit" class="w-full px-3 py-2 rounded-lg text-sm transition-all duration-300 bg-red-500/20 text-red-300 border border-red-500/30 hover:bg-red-500/30 hover:text-red-200" onclick="return confirm('Sei sicuro di voler uscire?')">
                                🚪 Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </nav>

            <!-- Workers Dropdown Toggle Script -->
            <script nonce="<?= csp_nonce() ?>">
            function toggleWorkersDropdown() {
                const menu = document.getElementById('workers-dropdown-menu');
                const arrow = document.getElementById('workers-dropdown-arrow');
                const isOpen = menu.classList.contains('max-h-96');

                if (isOpen) {
                    menu.classList.remove('max-h-96', 'opacity-100');
                    menu.classList.add('max-h-0', 'opacity-0');
                    arrow.classList.remove('rotate-180');
                } else {
                    menu.classList.remove('max-h-0', 'opacity-0');
                    menu.classList.add('max-h-96', 'opacity-100');
                    arrow.classList.add('rotate-180');
                }
            }
            </script>
            </div><!-- End scrollable container -->
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="ml-64 flex-1 px-6 lg:px-8 py-8 min-h-screen max-w-full" style="background: #0f0f0f;">
            <div class="max-w-7xl mx-auto">
        <?php
        // Track layout view for debugbar
        if (function_exists('debugbar_add_view')) {
            debugbar_add_view('admin/layout', ['view' => $view, 'title' => $title ?? 'Admin']);
            debugbar_add_view('admin/' . $view, get_defined_vars());
        }

    include __DIR__ . '/' . $view . '.php';
    ?>
            </div><!-- End max-w-7xl container -->
        </main>
    </div><!-- End 2-column flex layout -->

    <script nonce="<?= csp_nonce() ?>">
        // Enterprise admin functionality
        function confirmAction(message) {
            return confirm(message);
        }

        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm alert alert-${type}`;
            notification.textContent = message;

            document.body.appendChild(notification);

            // Remove after 3 seconds
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Auto-refresh for real-time data
        function setupAutoRefresh(interval = 30000) {
            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    // Refresh data for current page
                    const currentView = document.body.dataset.view;
                    if (currentView === 'dashboard' || currentView === 'stats') {
                        location.reload();
                    }
                }
            }, interval);
        }

        function refreshStats() {
            location.reload();
        }

        function clearOpCache() {
            showActionResult('clear_opcache', 'Clear OpCache');
        }

        function startWorkers() {
            showActionResult('start_workers', 'Start Workers');
        }

        function recoverRedis() {
            showActionResult('recover_redis', 'Recover Redis');
        }

        function refreshConnectionPool() {
            showActionResult('refresh_connection_pool', 'Refresh DB Pool');
        }

        function stopWorkers() {
            showActionResult('stop_workers', 'Stop Workers');
        }

        function stopWorkersClean() {
            showActionResult('stop_workers_clean', 'Stop Workers + Clean');
        }

        function showActionResult(action, title) {
            const output = document.getElementById('monitoring-output');
            const timestamp = new Date().toLocaleTimeString();

            // Add loading message
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'monitoring-section';
            loadingDiv.innerHTML = `<p class="text-info">[${timestamp}] 🔄 Executing ${title}...</p>`;
            output.appendChild(loadingDiv);
            output.scrollTop = output.scrollHeight;

            fetch('system-action', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=${action}`
            })
            .then(response => response.json())
            .then(data => {
                // Remove loading message
                output.removeChild(loadingDiv);

                // Add result
                const resultDiv = document.createElement('div');
                resultDiv.className = 'monitoring-section';

                if (data.success) {
                    const message = data.message || data.output || `${title} completed successfully`;
                    resultDiv.innerHTML = `
                        <h4>[${timestamp}] ✅ ${title}:</h4>
                        <pre style="color: #10b981;">${message}</pre>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <h4>[${timestamp}] ❌ ${title}:</h4>
                        <pre style="color: #ef4444;">Error: ${data.error || 'Unknown error'}</pre>
                    `;
                }

                output.appendChild(resultDiv);
                output.scrollTop = output.scrollHeight;
            })
            .catch(err => {
                // Remove loading message
                if (output.contains(loadingDiv)) {
                    output.removeChild(loadingDiv);
                }

                // Add error
                const errorDiv = document.createElement('div');
                errorDiv.className = 'monitoring-section';
                errorDiv.innerHTML = `
                    <h4>[${timestamp}] ❌ ${title}:</h4>
                    <pre style="color: #ef4444;">Network error: ${err.message}</pre>
                `;

                output.appendChild(errorDiv);
                output.scrollTop = output.scrollHeight;
            });
        }

        function monitorWorkers() {
            showMonitoringData('monitor_workers', 'Workers Status');
        }

        function monitorPerformance() {
            showMonitoringData('monitor_performance', 'Performance Monitor');
        }

        /**
         * ENTERPRISE: Run manual performance test
         * Uses 4 dedicated test users (IDs 99999-100002) - no random test emails
         */
        function runPerformanceTest() {
            const output = document.getElementById('monitoring-output');
            const timestamp = new Date().toLocaleTimeString();

            // Add loading message
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'monitoring-section';
            loadingDiv.innerHTML = `<p class="text-info">[${timestamp}] 🔄 Running Performance Test (4 emails)...</p>`;
            output.appendChild(loadingDiv);
            output.scrollTop = output.scrollHeight;

            // ENTERPRISE TIPS: Run test with 4 operations (1 per test user) - NO random emails
            fetch('system-action', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=run_performance_test&duration=1&ops_per_second=4'
            })
            .then(response => response.json())
            .then(data => {
                // Remove loading message
                output.removeChild(loadingDiv);

                // Add result
                const resultDiv = document.createElement('div');
                resultDiv.className = 'monitoring-section';

                if (data.success && data.test_results) {
                    const results = data.test_results;
                    resultDiv.innerHTML = `
                        <h4>[${timestamp}] ⚡ Performance Test Results:</h4>
                        <pre style="color: #10b981;">
═══════════════════════════════════════════════════════
📊 PERFORMANCE TEST COMPLETED
═══════════════════════════════════════════════════════

⏱️  Duration:              ${results.duration}s
✅  Operations Completed:  ${results.operations_completed}
❌  Operations Failed:     ${results.operations_failed}
📈  Operations/Second:     ${results.ops_per_second}
✔️  Success Rate:          ${results.success_rate}%
⚡  Execution Time:        ${results.execution_time_ms}ms

📝 Test Configuration:
   • Duration:             ${data.test_parameters.duration}s
   • Ops/Second:           ${data.test_parameters.ops_per_second}
   • Test Users:           4 dedicated (IDs 99999-100002)
   • Scenarios:            100% email_queue (verification emails)

═══════════════════════════════════════════════════════
✨ Test completed successfully using dedicated test users
   No random test emails created!
═══════════════════════════════════════════════════════
                        </pre>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <h4>[${timestamp}] ❌ Performance Test Failed:</h4>
                        <pre style="color: #ef4444;">Error: ${data.error || 'Unknown error'}</pre>
                    `;
                }

                output.appendChild(resultDiv);
                output.scrollTop = output.scrollHeight;
            })
            .catch(err => {
                // Remove loading message
                if (output.contains(loadingDiv)) {
                    output.removeChild(loadingDiv);
                }

                // Add error
                const errorDiv = document.createElement('div');
                errorDiv.className = 'monitoring-section';
                errorDiv.innerHTML = `
                    <h4>[${timestamp}] ❌ Performance Test Error:</h4>
                    <pre style="color: #ef4444;">Network error: ${err.message}</pre>
                `;

                output.appendChild(errorDiv);
                output.scrollTop = output.scrollHeight;
            });
        }

        function refreshMonitoring() {
            const output = document.getElementById('monitoring-output');
            output.innerHTML = '<p class="text-info">🔄 Refreshing all monitoring data...</p>';

            Promise.all([
                fetchMonitoringData('monitor_workers'),
                fetchMonitoringData('monitor_performance')
            ]).then(results => {
                output.innerHTML = `
                    <div class="monitoring-section">
                        <h4>👷 Workers Status:</h4>
                        <pre>${results[0]}</pre>
                    </div>
                    <div class="monitoring-section">
                        <h4>⚡ Performance:</h4>
                        <pre>${results[1]}</pre>
                    </div>
                `;
            }).catch(err => {
                output.innerHTML = '<p class="text-danger">Error refreshing monitoring data</p>';
            });
        }

        function showMonitoringData(action, title) {
            const output = document.getElementById('monitoring-output');
            const timestamp = new Date().toLocaleTimeString();

            // Add loading message
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'monitoring-section';
            loadingDiv.innerHTML = `<p class="text-info">[${timestamp}] 🔄 Loading ${title}...</p>`;
            output.appendChild(loadingDiv);
            output.scrollTop = output.scrollHeight;

            fetchMonitoringData(action).then(data => {
                // Remove loading message
                output.removeChild(loadingDiv);

                // Add result
                const resultDiv = document.createElement('div');
                resultDiv.className = 'monitoring-section';
                resultDiv.innerHTML = `
                    <h4>[${timestamp}] 📊 ${title}:</h4>
                    <pre>${data}</pre>
                `;

                output.appendChild(resultDiv);
                output.scrollTop = output.scrollHeight;
            }).catch(err => {
                // Remove loading message
                if (output.contains(loadingDiv)) {
                    output.removeChild(loadingDiv);
                }

                // Add error
                const errorDiv = document.createElement('div');
                errorDiv.className = 'monitoring-section';
                errorDiv.innerHTML = `<p class="text-danger">[${timestamp}] ❌ Error loading ${title}</p>`;

                output.appendChild(errorDiv);
                output.scrollTop = output.scrollHeight;
            });
        }

        function fetchMonitoringData(action) {
            return fetch('system-action', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=${action}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.output) {
                    return data.output;
                } else {
                    throw new Error(data.error || 'Unknown error');
                }
            });
        }

        function clearMonitoringOutput() {
            const output = document.getElementById('monitoring-output');
            output.innerHTML = '<p class="text-muted">Click a monitoring button to see live data...</p>';
        }

        // Initialize enterprise features
        document.addEventListener('DOMContentLoaded', function() {
            setupAutoRefresh();
        });
    </script>

    <!-- ENTERPRISE GALAXY: Admin Session Guard with Auto-Heartbeat [MINIFIED] -->
    <script src="/assets/js/admin-session-guard.min.js"></script>

    <?php
    // ENTERPRISE: Inject debugbar BODY assets
    if (function_exists('debugbar_render')) {
        echo debugbar_render();
    }
    ?>
</body>
</html>