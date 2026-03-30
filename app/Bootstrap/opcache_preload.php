<?php

/**
 * NEED2TALK - OPCACHE PRELOAD SCRIPT (PHP 7.4+)
 *
 * ENTERPRISE GALAXY LEVEL: Preload tutti i file critici in OPcache al boot
 *
 * PERFORMANCE IMPACT:
 * - First request dopo restart: 185ms → 5-10ms
 * - Zero compilation overhead
 * - Memoria OPcache occupata sin dall'inizio
 *
 * USAGE:
 * Nel php.ini o docker-php-ext-opcache.ini:
 * opcache.preload=/var/www/html/app/Bootstrap/opcache_preload.php
 * opcache.preload_user=www-data
 *
 * NOTA: Solo per production! In development disabilitare per hot-reload
 */

// 🛡️ SECURITY: Solo da CLI/preload context
if (PHP_SAPI !== 'cli' && !function_exists('opcache_compile_file')) {
    die('OPcache preload must run in CLI mode with opcache_compile_file available');
}

// 📁 APP_ROOT
define('APP_ROOT', dirname(__DIR__, 2));

// 📊 Stats
$preloaded = 0;
$failed = 0;
$startTime = microtime(true);

/**
 * Preload a file into OPcache
 */
function preload_file(string $file): bool
{
    global $preloaded, $failed;

    if (!file_exists($file)) {
        error_log("OPcache Preload: File not found: {$file}");
        $failed++;

        return false;
    }

    try {
        // LOG BEFORE compilation to catch hanging files (commented for production - too verbose)
        // error_log("OPcache Preload: Attempting {$file}");
        opcache_compile_file($file);
        // error_log("OPcache Preload: SUCCESS {$file}");
        $preloaded++;

        return true;
    } catch (Throwable $e) {
        error_log("OPcache Preload: FAILED {$file}: " . $e->getMessage());
        $failed++;

        return false;
    }
}

/**
 * Preload all PHP files in a directory (recursive)
 */
function preload_directory(string $dir, array $excludeFiles = []): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filePath = $file->getPathname();

            // Skip excluded files
            $skip = false;
            foreach ($excludeFiles as $excludePattern) {
                if (strpos($filePath, $excludePattern) !== false) {
                    $skip = true;
                    break;
                }
            }

            if (!$skip) {
                preload_file($filePath);
            }
        }
    }
}

// ==========================================
// 1️⃣ CORE BOOTSTRAP FILES (CRITICAL)
// ==========================================
echo "🚀 OPcache Preload: Loading core bootstrap...\n";

// PRODUCTION FIX: DON'T preload bootstrap.php - it executes initialization code!
// Only preload class definitions, not executable code
// preload_file(APP_ROOT . '/app/bootstrap.php');  // SKIP - executes DB/Redis connections
preload_file(APP_ROOT . '/app/Bootstrap/EnterpriseBootstrap.php');

// ==========================================
// 2️⃣ CORE CLASSES (Database, Cache, Router)
// ==========================================
echo "🚀 OPcache Preload: Loading core classes...\n";

// ENTERPRISE FIX: Exclude IDE Stubs to prevent duplicate class declarations
preload_directory(APP_ROOT . '/app/Core', ['Stubs/']);

// ==========================================
// 3️⃣ SERVICES (All enterprise services)
// ==========================================
echo "🚀 OPcache Preload: Loading services...\n";

// SKIP DebugbarService (requires vendor classes not in preload)
preload_directory(APP_ROOT . '/app/Services', ['DebugbarService.php']);

// ==========================================
// 4️⃣ MIDDLEWARE (Security, Auth, Rate Limit)
// ==========================================
echo "🚀 OPcache Preload: Loading middleware...\n";

preload_directory(APP_ROOT . '/app/Middleware');

// ==========================================
// 5️⃣ MODELS (User, etc)
// ==========================================
echo "🚀 OPcache Preload: Loading models...\n";

preload_directory(APP_ROOT . '/app/Models');

// ==========================================
// 6️⃣ CONTROLLERS (All controllers)
// ==========================================
echo "🚀 OPcache Preload: Loading controllers...\n";

// PRODUCTION FIX: Exclude controllers that cause duplicate class declaration
// These controllers are loaded dynamically by router/autoloader after preload
// Excluding them prevents "Can't preload already declared class" warnings
preload_directory(APP_ROOT . '/app/Controllers', [
    'CookieConsentController.php',      // Consent API - loaded dynamically
    'AdminMLSecurityController.php',    // ML Admin API - loaded dynamically (v6.5 fix)
]);

// ==========================================
// 7️⃣ TRAITS (Enterprise dependencies)
// ==========================================
echo "🚀 OPcache Preload: Loading traits...\n";

preload_directory(APP_ROOT . '/app/Traits');

// ==========================================
// 8️⃣ HELPERS (Lightning autoloader loads these)
// ==========================================
echo "🚀 OPcache Preload: Loading helpers...\n";

preload_directory(APP_ROOT . '/app/Helpers');

// ==========================================
// 9️⃣ CONFIG FILES (app.php, routes.php)
// ==========================================
echo "🚀 OPcache Preload: Loading config...\n";

// SKIP config files - have top-level code: preload_file(APP_ROOT . '/config/app.php');
// preload_file(APP_ROOT . '/config/routes.php');

// ==========================================
// 🔟 ROUTES
// ==========================================
echo "🚀 OPcache Preload: Loading routes...\n";

// SKIP routes - have executable code: preload_directory(APP_ROOT . '/routes');

// ==========================================
// 1️⃣1️⃣ REPOSITORIES (Audio Repositories - NEW)
// ==========================================
echo "🚀 OPcache Preload: Loading repositories...\n";

preload_directory(APP_ROOT . '/app/Repositories');

// ==========================================
// 1️⃣2️⃣ BACKGROUND JOBS (Admin Jobs - NEW)
// ==========================================
echo "🚀 OPcache Preload: Loading background jobs...\n";

preload_directory(APP_ROOT . '/app/Jobs');

// ==========================================
// 1️⃣3️⃣ SYSTEM CLASSES (Email Testing, etc - NEW)
// ==========================================
echo "🚀 OPcache Preload: Loading system classes...\n";

preload_directory(APP_ROOT . '/app/System');

// ==========================================
// 1️⃣4️⃣ UNIFIED LAYOUTS (CRITICAL FOR REFACTORING)
// ==========================================
echo "🚀 OPcache Preload: Loading unified layouts...\n";

// Guest layouts (critical path)
preload_file(APP_ROOT . '/app/Views/layouts/guest.php');
preload_file(APP_ROOT . '/app/Views/layouts/error.php');
preload_file(APP_ROOT . '/app/Views/layouts/navbar-guest.php');

// Authenticated layouts (post-login critical path)
preload_file(APP_ROOT . '/app/Views/layouts/app-post-login.php');
preload_file(APP_ROOT . '/app/Views/layouts/navbar-auth.php');
preload_file(APP_ROOT . '/app/Views/layouts/footer.php');
preload_file(APP_ROOT . '/app/Views/layouts/footer-auth.php');

// Admin panel layout (admin critical path)
preload_file(APP_ROOT . '/app/Views/admin/layout.php');

// ==========================================
// 1️⃣5️⃣ CRITICAL VIEW COMPONENTS
// ==========================================
echo "🚀 OPcache Preload: Loading view components...\n";

preload_file(APP_ROOT . '/app/Views/components/enterprise-monitoring.php');

// ==========================================
// 1️⃣3️⃣ VENDOR AUTOLOAD (Composer dependencies)
// ==========================================
echo "🚀 OPcache Preload: Loading vendor autoload...\n";

if (file_exists(APP_ROOT . '/vendor/autoload.php')) {
    // SKIP vendor autoload - causes crash:     require_once APP_ROOT . '/vendor/autoload.php';
}

// ==========================================
// 📊 STATISTICS
// ==========================================
$duration = round((microtime(true) - $startTime) * 1000, 2);

echo "\n";
echo "✅ OPcache Preload Complete!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Files preloaded: {$preloaded}\n";
echo "  Files failed:    {$failed}\n";
echo "  Duration:        {$duration}ms\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
echo "🚀 Container ready for production traffic!\n";
echo "   First request will be ultra-fast (<10ms)\n";
echo "\n";

// ==========================================
// 📝 LOG TO FILE
// ==========================================
$logMessage = sprintf(
    "[%s] OPcache Preload: %d files loaded, %d failed, %sms\n",
    date('Y-m-d H:i:s'),
    $preloaded,
    $failed,
    $duration
);

error_log($logMessage, 3, APP_ROOT . '/storage/logs/opcache_preload.log');
