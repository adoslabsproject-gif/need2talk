<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Admin Settings Controller
 *
 * ENTERPRISE: Gestisce tutte le impostazioni configurabili dalla tab Settings dell'admin panel
 *
 * Responsabilità:
 * - Debugbar settings (enable/disable, theme, options)
 * - JS Errors Database Filter (PSR-3 level filtering per enterprise_js_errors table)
 * - Future: Altri settings amministrativi
 *
 * NOTE: Tutte le impostazioni sono salvate in admin_settings table per tracciabilità admin_id
 */
class AdminSettingsController extends BaseController
{
    /**
     * Update Debugbar settings
     * Called from admin Settings tab via same-page POST
     *
     * @return void (outputs JSON response)
     */
    public function updateDebugbarSettings(): void
    {
        try {
            // ENTERPRISE MASTER SWITCH: If ENV disables debugbar, block ALL changes
            // Debugbar cannot be enabled from admin panel when .env says ENABLE_DEBUGBAR=false
            // This prevents wasted DB writes and ensures .env is the authoritative source
            if (!env('ENABLE_DEBUGBAR', false)) {
                // Log security attempt
                Logger::security('warning', 'ADMIN: Attempted to modify debugbar settings while ENV disabled', [
                    'env_debugbar' => false,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Redirect with error message
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=env_disabled');
                exit;
            }

            // ENTERPRISE TIPS: Use hidden field for debugbar_enabled (checkbox doesn't submit when unchecked)
            // For main toggle, use hidden field; for sub-options, use checkbox presence (they have hidden fields with value=0)
            $debugbarSettings = [
                'debugbar_enabled' => ($_POST['debugbar_enabled_hidden'] ?? '0') === '1' ? 1 : 0,
                'debugbar_admin_only' => ($_POST['debugbar_admin_only'] ?? '0') === '1' ? 1 : 0,
                'debugbar_show_queries' => ($_POST['debugbar_show_queries'] ?? '0') === '1' ? 1 : 0,
                'debugbar_show_performance' => ($_POST['debugbar_show_performance'] ?? '0') === '1' ? 1 : 0,
                'debugbar_collect_views' => ($_POST['debugbar_collect_views'] ?? '0') === '1' ? 1 : 0,
                'debugbar_theme' => $_POST['debugbar_theme'] ?? 'crt-amber',
            ];

            // Get current admin ID from session
            // ENTERPRISE FIX: Admin session uses 'admin_user_id', not 'user.id'
            $adminId = $_SESSION['admin_user_id'] ?? null;

            if (!$adminId) {
                Logger::error('AdminSettingsController: No admin ID in session', [
                    'session_keys' => array_keys($_SESSION ?? []),
                ]);
                throw new \Exception('Admin authentication required');
            }

            foreach ($debugbarSettings as $key => $value) {
                // UPDATE with cache invalidation using global db() helper
                // ENTERPRISE FIX: Include admin_id to track who made the change
                db()->execute(
                    'INSERT INTO admin_settings (setting_key, setting_value, admin_id, updated_at)
                     VALUES (?, ?, ?, NOW())
                     ON CONFLICT (setting_key) DO UPDATE SET
                         setting_value = EXCLUDED.setting_value,
                         admin_id = EXCLUDED.admin_id,
                         updated_at = NOW()',
                    [$key, $value, $adminId],
                    ['invalidate_cache' => ['table:admin_settings']]
                );
            }

            // Log admin action using security channel (configuration change)
            Logger::security('info', 'ADMIN: Debugbar settings updated', [
                'enabled' => $debugbarSettings['debugbar_enabled'],
                'theme' => $debugbarSettings['debugbar_theme'],
                'admin_only' => $debugbarSettings['debugbar_admin_only'],
                'show_queries' => $debugbarSettings['debugbar_show_queries'],
                'show_performance' => $debugbarSettings['debugbar_show_performance'],
                'collect_views' => $debugbarSettings['debugbar_collect_views'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // ENTERPRISE: Clear DebugbarService settings cache so next request loads fresh values
            if (class_exists('\Need2Talk\Services\DebugbarService')) {
                \Need2Talk\Services\DebugbarService::clearSettingsCache();
            }

            // ENTERPRISE GALAXY ULTIMATE: Populate Redis cache IMMEDIATELY with new settings
            // This prevents race condition where homepage reads empty cache before DB repopulates it
            try {
                // ENTERPRISE POOL: Use connection pool instead of direct connection
                $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L3_logging');

                if ($redis) {
                    // Build complete settings array with new values
                    $freshSettings = [
                        'debugbar_enabled' => $debugbarSettings['debugbar_enabled'] === 1,
                        'debugbar_admin_only' => $debugbarSettings['debugbar_admin_only'] === 1,
                        'debugbar_show_queries' => $debugbarSettings['debugbar_show_queries'] === 1,
                        'debugbar_show_performance' => $debugbarSettings['debugbar_show_performance'] === 1,
                        'debugbar_collect_views' => $debugbarSettings['debugbar_collect_views'] === 1,
                        'debugbar_theme' => $debugbarSettings['debugbar_theme'],
                    ];

                    // Cache for 10 seconds (same as DebugbarService)
                    $redis->setex('debugbar:settings', 10, json_encode($freshSettings));

                    // Log to default channel - debug level (internal operation)
                    if (function_exists('should_log') && should_log('default', 'debug')) {
                        Logger::debug('[ADMIN SETTINGS] Redis cache repopulated with fresh settings immediately', []);
                    }
                }
            } catch (\Exception $e) {
                // Log to default channel - warning level (cache repopulation failed)
                if (function_exists('should_log') && should_log('default', 'warning')) {
                    Logger::warning('[ADMIN SETTINGS] Failed to repopulate Redis cache', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ENTERPRISE TIPS: Clear OPcache for settings view to force fresh render
            if (function_exists('opcache_invalidate')) {
                $settingsViewPath = dirname(__DIR__) . '/Views/admin/settings.php';
                if (file_exists($settingsViewPath)) {
                    opcache_invalidate($settingsViewPath, true);
                }
            }

            // ENTERPRISE GALAXY ULTIMATE: Invalidate page cache for public pages
            // The homepage.html cache contains debugbar pre-rendered in HTML
            // When admin_only changes, we must invalidate ALL page caches
            if (class_exists('EarlyPageCache')) {
                \EarlyPageCache::invalidate(); // Invalidate all cached pages

                // Log to default channel - debug level (internal cache operation)
                if (function_exists('should_log') && should_log('default', 'debug')) {
                    Logger::debug('[ADMIN SETTINGS] EarlyPageCache invalidated (all pages)', []);
                }
            }

            // ENTERPRISE GALAXY ULTIMATE: Use HTTP 303 redirect instead of JSON response
            // This forces Chrome/Safari to reload the page from server (bypasses ALL caches)

            // CHROME-SPECIFIC FIX: Clear-Site-Data header forces Chrome to purge ALL caches
            // This is the ONLY way to bypass Chrome's aggressive disk cache and bfcache
            header('Clear-Site-Data: "cache", "storage"');

            // Standard cache bypass headers
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Vary header tells Chrome that response depends on cookies
            header('Vary: Cookie');

            // HTTP 303 redirect with aggressive cache-busting timestamp
            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?updated=' . time() . '&_=' . mt_rand());
            exit;

        } catch (\Exception $e) {
            // Log to security channel - critical level (admin settings update failed)
            Logger::security('critical', 'ADMIN: Debugbar settings update exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // Track exception in debugbar
            if (class_exists('\Need2Talk\Services\DebugbarService')) {
                \Need2Talk\Services\DebugbarService::addException($e);
            }

            // On error, redirect with error parameter
            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=1');
            exit;
        }
    }

    /**
     * Update JS Errors Database Filter settings
     * Called from admin Settings tab via same-page POST
     *
     * Controlla quali errori vengono salvati nella tabella enterprise_js_errors
     * in base al livello PSR-3 configurato (debug, info, notice, warning, error, critical, alert, emergency)
     *
     * NOTE: Questo filtro è INDIPENDENTE dal file logging - il file logging rispetta
     * sempre il livello configurato nel canale js_errors.
     *
     * @return void (outputs JSON response)
     */
    public function updateJsErrorsDbFilter(): void
    {
        try {
            // Validate admin session and get admin_id
            $sessionToken = $_COOKIE['__Host-admin_session'] ?? null;
            if (!$sessionToken) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=auth');
                exit;
            }

            $security = new \Need2Talk\Services\AdminSecurityService();
            $adminSession = $security->validateAdminSession($sessionToken);

            if (!$adminSession) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=session');
                exit;
            }

            $adminId = $adminSession['admin_id'];

            // ENTERPRISE TIPS: Use hidden field for enabled toggle (checkbox doesn't submit when unchecked)
            $enabled = ($_POST['enabled_hidden'] ?? '1') === '1';
            $minLevel = $_POST['min_level'] ?? 'error';

            // Validate PSR-3 level
            $validLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
            if (!in_array($minLevel, $validLevels, true)) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=invalid_level');
                exit;
            }

            // Get current configuration for comparison (for audit log)
            $currentSetting = db()->findOne(
                "SELECT setting_value FROM admin_settings WHERE setting_key = 'js_errors_db_filter_config'",
                [],
                ['cache' => false] // Don't use cache for current value
            );

            $previousConfig = null;
            if ($currentSetting) {
                $previousConfig = json_decode($currentSetting['setting_value'], true);
            }

            // Build new configuration
            $newConfig = [
                'enabled' => $enabled,
                'min_level' => $minLevel,
                'description' => 'Database filter for JavaScript errors - independent from file logging',
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $adminId,
            ];

            // Update admin_settings table
            db()->execute(
                "INSERT INTO admin_settings (setting_key, setting_value, admin_id, updated_at)
                 VALUES ('js_errors_db_filter_config', :value, :admin_id, NOW())
                 ON CONFLICT (setting_key) DO UPDATE SET
                 setting_value = EXCLUDED.setting_value,
                 admin_id = EXCLUDED.admin_id,
                 updated_at = NOW()",
                [
                    'value' => json_encode($newConfig),
                    'admin_id' => $adminId,
                ],
                ['invalidate_cache' => ['table:admin_settings']]
            );

            // ENTERPRISE GALAXY ULTIMATE: Invalidate static cache in EnterpriseLoggingController
            // The shouldFilterFromDatabase() method uses static caching
            // Clear it by setting a Redis invalidation timestamp
            try {
                $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
                $redis = $redisManager->getConnection('L1_cache');
                if ($redis) {
                    $redis->set('js_errors:db_filter:invalidation_timestamp', microtime(true));
                }
            } catch (\Throwable $e) {
                // Redis unavailable - cache will refresh naturally after static cache timeout
            }

            // Log the change for audit trail - already logged via Logger::security() below
            // No duplicate logging needed here

            // ENTERPRISE SECURITY LOG: Configuration change
            Logger::security('warning', 'ADMIN: JS Errors database filter updated', [
                'enabled' => $enabled,
                'min_level' => $minLevel,
                'previous_enabled' => $previousConfig['enabled'] ?? null,
                'previous_min_level' => $previousConfig['min_level'] ?? null,
                'admin_user_id' => $adminId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // ENTERPRISE GALAXY ULTIMATE: Use HTTP 303 redirect instead of JSON response
            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?updated=' . time());
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            exit;

        } catch (\Exception $e) {
            // Log to security channel - critical level (admin settings update failed)
            Logger::security('critical', 'ADMIN: JS Errors DB Filter update exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // On error, redirect with error parameter
            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=1');
            exit;
        }
    }

    /**
     * Get Debugbar settings from database
     *
     * @return array Debugbar settings with defaults
     */
    public function getDebugbarSettings(): array
    {
        try {
            // ENTERPRISE 2025: Bypass all caching with random comment to force fresh query
            $randComment = '/* nocache_' . microtime(true) . ' */';

            $rows = db()->query("
                SELECT {$randComment} setting_key, setting_value
                FROM admin_settings
                WHERE setting_key LIKE 'debugbar_%'
            ", [], ['cache' => false]);

            $settings = [];

            foreach ($rows as $row) {
                $key = $row['setting_key'];
                $value = $row['setting_value'];

                // ENTERPRISE: Handle different setting types
                if ($key === 'debugbar_theme') {
                    // String value for theme selection
                    $settings[$key] = $value;
                } else {
                    // Boolean conversion for other settings
                    // ENTERPRISE FIX: Database stores as integer (0 or 1), convert to int for template
                    // Template expects integer for consistency (not boolean)
                    $settings[$key] = (int) $value;
                }
            }

            // Default values if not set (integers for consistency)
            // ENTERPRISE MASTER SWITCH: Include ENV state for frontend UI
            return array_merge([
                'debugbar_enabled' => 0,
                'debugbar_admin_only' => 1,
                'debugbar_show_queries' => 1,
                'debugbar_show_performance' => 1,
                'debugbar_collect_views' => 1,
                'debugbar_theme' => 'crt-amber',
                'env_debugbar_enabled' => env('ENABLE_DEBUGBAR', false), // Master switch
            ], $settings);

        } catch (\Exception $e) {
            // Log to default channel - error level (settings load failed, using defaults)
            // HARDCODED should_log check because this may run during bootstrap
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[ADMIN SETTINGS] Error loading debugbar settings', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            return [
                'debugbar_enabled' => 0,
                'debugbar_admin_only' => 1,
                'debugbar_show_queries' => 1,
                'debugbar_show_performance' => 1,
                'debugbar_collect_views' => 1,
                'debugbar_theme' => 'crt-amber',
                'env_debugbar_enabled' => env('ENABLE_DEBUGBAR', false), // Master switch
            ];
        }
    }

    /**
     * Get JS Errors Database Filter settings from database
     *
     * @return array Filter settings with defaults
     */
    public function getJsErrorsDbFilterSettings(): array
    {
        try {
            // ENTERPRISE 2025: Bypass all caching with random comment to force fresh query
            $randComment = '/* nocache_' . microtime(true) . ' */';

            $row = db()->findOne("
                SELECT {$randComment} setting_value
                FROM admin_settings
                WHERE setting_key = 'js_errors_db_filter_config'
            ", [], ['cache' => false]);

            if ($row && $row['setting_value']) {
                $config = json_decode($row['setting_value'], true);
                if (is_array($config)) {
                    return $config;
                }
            }

            // Default values if not set
            return [
                'enabled' => true,
                'min_level' => 'error',
                'description' => 'Database filter for JavaScript errors - independent from file logging',
            ];

        } catch (\Exception $e) {
            // Log to default channel - error level (settings load failed, using defaults)
            // HARDCODED should_log check because this may run during bootstrap
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[ADMIN SETTINGS] Error loading JS errors DB filter settings', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            return [
                'enabled' => true,
                'min_level' => 'error',
                'description' => 'Database filter for JavaScript errors - independent from file logging',
            ];
        }
    }

    /**
     * Update Browser Console Logging setting
     * Called from admin Settings tab via same-page POST
     *
     * ENTERPRISE PSR-3: Controls whether console logs appear in browser or only go to server
     * - true: Logs shown in browser console AND sent to server (.log files)
     * - false: Logs ONLY sent to server, NOT shown in browser console
     *
     * Use case: Production debugging without exposing logs to end users
     *
     * @return void (redirects with 303 See Other)
     */
    public function updateBrowserConsoleSettings(): void
    {
        // Log to default channel - debug level (method entry)
        // HARDCODED should_log check because this may run during bootstrap
        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[ADMIN SETTINGS] updateBrowserConsoleSettings() started', [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'post_data' => $_POST,
            ]);
        }

        try {
            // Validate admin session
            $sessionToken = $_COOKIE['__Host-admin_session'] ?? null;
            if (!$sessionToken) {
                // Log to security channel - warning level (unauthorized attempt)
                Logger::security('warning', 'ADMIN: Browser console settings update - no session token', [
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=auth');
                exit;
            }

            $security = new \Need2Talk\Services\AdminSecurityService();
            $adminSession = $security->validateAdminSession($sessionToken);

            if (!$adminSession) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=session');
                exit;
            }

            $adminId = $adminSession['admin_id'];

            // Read setting from POST (hidden field)
            $enabled = ($_POST['js_console_browser_enabled_hidden'] ?? 'true') === 'true';

            // Update admin_settings table
            db()->execute(
                "INSERT INTO admin_settings (setting_key, setting_value, admin_id, updated_at)
                 VALUES ('js_console_browser_enabled', :value, :admin_id, NOW())
                 ON CONFLICT (setting_key) DO UPDATE SET
                 setting_value = EXCLUDED.setting_value,
                 admin_id = EXCLUDED.admin_id,
                 updated_at = NOW()",
                [
                    'value' => $enabled ? 'true' : 'false',
                    'admin_id' => $adminId,
                ],
                ['invalidate_cache' => ['table:admin_settings']]
            );

            // ENTERPRISE 2025: No Redis cache repopulation needed!
            // base.php now reads directly from DB (faster than Redis lookup)
            // PostgreSQL connection pool + simple query < Redis network I/O

            // ENTERPRISE TIPS: Clear OPcache for base.php to force fresh read of new setting
            if (function_exists('opcache_invalidate')) {
                $basePhpPath = dirname(__DIR__) . '/Views/layouts/base.php';
                if (file_exists($basePhpPath)) {
                    opcache_invalidate($basePhpPath, true);

                    // Log to default channel - debug level (internal cache operation)
                    if (function_exists('should_log') && should_log('default', 'debug')) {
                        Logger::debug('[ADMIN SETTINGS] OPcache invalidated for base.php', []);
                    }
                }
            }

            // ENTERPRISE GALAXY ULTIMATE: Invalidate static cache via Redis timestamp
            // The base.php uses this setting during page render
            try {
                $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
                $redis = $redisManager->getConnection('L1_cache');
                if ($redis) {
                    $redis->set('browser_console:invalidation_timestamp', microtime(true));
                }
            } catch (\Throwable $e) {
                // Redis unavailable - cache will refresh naturally
            }

            // Log the change - already logged via Logger::security() below
            // No duplicate logging needed here

            // ENTERPRISE SECURITY LOG: Configuration change
            Logger::security('warning', 'ADMIN: Browser console logging updated', [
                'enabled' => $enabled,
                'admin_user_id' => $adminId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // ENTERPRISE 2025: Simple redirect - no cache busting needed!
            // base.php reads directly from DB (no Redis cache), so changes are instant
            $redirectUrl = $_SERVER['REQUEST_URI'] . '?updated=' . time();

            // HTTP 303 redirect (forces GET request)
            header('HTTP/1.1 303 See Other');
            header('Location: ' . $redirectUrl);
            header('Cache-Control: no-store, no-cache, must-revalidate');

            exit;

        } catch (\Exception $e) {
            // Log to security channel - critical level (admin settings update failed)
            Logger::security('critical', 'ADMIN: Browser console settings update exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=1');
            exit;
        }
    }

    /**
     * Get Browser Console Logging setting from database
     *
     * ENTERPRISE 2025: Bypasses all caching to ensure fresh value after admin changes
     *
     * @return bool Browser console enabled status
     */
    public function getBrowserConsoleEnabled(): bool
    {
        try {
            // ENTERPRISE 2025: Bypass all caching with random comment to force fresh query
            $randComment = '/* nocache_' . microtime(true) . ' */';

            $row = db()->findOne("
                SELECT {$randComment} setting_value
                FROM admin_settings
                WHERE setting_key = 'js_console_browser_enabled'
            ", [], ['cache' => false]);

            return ($row['setting_value'] ?? 'true') === 'true';

        } catch (\Exception $e) {
            // Log to default channel - error level (settings load failed, using defaults)
            // HARDCODED should_log check because this may run during bootstrap
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[ADMIN SETTINGS] Error loading browser console setting', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            return true; // Default: enabled
        }
    }

    /**
     * Update Telegram Log Alerts settings
     * Called from admin Settings tab via same-page POST
     *
     * ENTERPRISE GALAXY: Real-time Telegram notifications for critical log events
     * - Toggle enabled/disabled
     * - Select minimum level (warning, error, critical, emergency)
     * - Rate limit configuration
     *
     * @return void (redirects with 303 See Other)
     */
    public function updateTelegramLogAlertsSettings(): void
    {
        try {
            // Validate admin session
            $sessionToken = $_COOKIE['__Host-admin_session'] ?? null;
            if (!$sessionToken) {
                Logger::security('warning', 'ADMIN: Telegram alerts settings update - no session token', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=auth');
                exit;
            }

            $security = new \Need2Talk\Services\AdminSecurityService();
            $adminSession = $security->validateAdminSession($sessionToken);

            if (!$adminSession) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=session');
                exit;
            }

            $adminId = $adminSession['admin_id'];

            // Read settings from POST
            $enabled = ($_POST['telegram_alerts_enabled_hidden'] ?? 'true') === 'true';
            $minLevel = $_POST['telegram_alerts_min_level'] ?? 'error';
            $rateLimitSeconds = (int) ($_POST['telegram_alerts_rate_limit'] ?? 300);

            // Validate min_level
            $validLevels = ['warning', 'error', 'critical', 'emergency'];
            if (!in_array($minLevel, $validLevels, true)) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=invalid_level');
                exit;
            }

            // Validate rate limit (min 60s, max 3600s)
            $rateLimitSeconds = max(60, min(3600, $rateLimitSeconds));

            // Update via TelegramLogAlertService (handles DB + cache invalidation)
            $success = \Need2Talk\Services\TelegramLogAlertService::updateConfig(
                $enabled,
                $minLevel,
                $rateLimitSeconds
            );

            if (!$success) {
                header('HTTP/1.1 303 See Other');
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=update_failed');
                exit;
            }

            // ENTERPRISE SECURITY LOG: Configuration change
            Logger::security('warning', 'ADMIN: Telegram Log Alerts settings updated', [
                'enabled' => $enabled,
                'min_level' => $minLevel,
                'rate_limit_seconds' => $rateLimitSeconds,
                'admin_user_id' => $adminId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // HTTP 303 redirect
            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?updated=' . time());
            header('Cache-Control: no-store, no-cache, must-revalidate');
            exit;

        } catch (\Exception $e) {
            Logger::security('critical', 'ADMIN: Telegram alerts settings update exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            header('HTTP/1.1 303 See Other');
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?error=1');
            exit;
        }
    }

    /**
     * Get Telegram Log Alerts settings
     *
     * @return array Current settings
     */
    public function getTelegramLogAlertsSettings(): array
    {
        return \Need2Talk\Services\TelegramLogAlertService::getCurrentConfig();
    }

    /**
     * Send test Telegram alert
     * Called via AJAX from admin panel
     *
     * @return void (outputs JSON response)
     */
    public function sendTestTelegramAlert(): void
    {
        try {
            // Validate admin session
            $sessionToken = $_COOKIE['__Host-admin_session'] ?? null;
            if (!$sessionToken) {
                $this->json(['success' => false, 'error' => 'Not authenticated'], 401);
                return;
            }

            $security = new \Need2Talk\Services\AdminSecurityService();
            $adminSession = $security->validateAdminSession($sessionToken);

            if (!$adminSession) {
                $this->json(['success' => false, 'error' => 'Invalid session'], 401);
                return;
            }

            // Send test alert
            $success = \Need2Talk\Services\TelegramLogAlertService::sendTestAlert();

            if ($success) {
                Logger::security('info', 'ADMIN: Telegram test alert sent', [
                    'admin_user_id' => $adminSession['admin_id'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            }

            $this->json([
                'success' => $success,
                'message' => $success ? 'Test alert sent successfully' : 'Failed to send test alert',
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Telegram test alert exception', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'error' => 'Internal error'], 500);
        }
    }
}
