<?php

/**
 * ENTERPRISE GALAXY: Honeypot Routes
 *
 * Endpoint fasulli per catturare bot scanner
 * Qualsiasi accesso = BAN IMMEDIATO (7 giorni)
 *
 * STRATEGIA:
 * - Endpoint che sembrano vulnerabili
 * - Response realistiche per confondere bot
 * - Log centralizzato + alert CRITICAL
 * - Ban automatico IP + Redis tracking
 *
 * PERFORMANCE:
 * - Zero overhead per utenti legittimi
 * - Response immediate per bot (< 10ms)
 * - Async ban processing con Redis
 */

use Need2Talk\Controllers\HoneypotController;

// ============================================================================
// CRITICAL VULNERABILITY HONEYPOTS - Instant Ban
// ============================================================================

// Environment files (most scanned)
$router->get('/.env', [HoneypotController::class, 'trap']);
$router->get('/.env.local', [HoneypotController::class, 'trap']);
$router->get('/.env.production', [HoneypotController::class, 'trap']);
$router->get('/.env.development', [HoneypotController::class, 'trap']);
$router->get('/.env.backup', [HoneypotController::class, 'trap']);

// Git repository files
$router->get('/.git/config', [HoneypotController::class, 'trap']);
$router->get('/.git/HEAD', [HoneypotController::class, 'trap']);
$router->get('/.git/', [HoneypotController::class, 'trap']);
$router->get('/.svn/', [HoneypotController::class, 'trap']);

// PHP info/test files
$router->get('/phpinfo.php', [HoneypotController::class, 'trap']);
$router->get('/info.php', [HoneypotController::class, 'trap']);
$router->get('/test.php', [HoneypotController::class, 'trap']);
$router->get('/temp.php', [HoneypotController::class, 'trap']);

// Config files
$router->get('/config.php', [HoneypotController::class, 'trap']);
$router->get('/configuration.php', [HoneypotController::class, 'trap']);
$router->get('/settings.php', [HoneypotController::class, 'trap']);
$router->get('/config.json', [HoneypotController::class, 'trap']);
$router->get('/config.yml', [HoneypotController::class, 'trap']);

// Database files
$router->get('/database.php', [HoneypotController::class, 'trap']);
$router->get('/database.yml', [HoneypotController::class, 'trap']);
$router->get('/backup.sql', [HoneypotController::class, 'trap']);
$router->get('/dump.sql', [HoneypotController::class, 'trap']);
$router->get('/db.sql', [HoneypotController::class, 'trap']);

// WordPress honeypots
$router->get('/wp-admin/', [HoneypotController::class, 'trap']);
$router->get('/wp-login.php', [HoneypotController::class, 'trap']);
$router->get('/wp-config.php', [HoneypotController::class, 'trap']);
$router->get('/wp-content/', [HoneypotController::class, 'trap']);
$router->get('/wp-includes/', [HoneypotController::class, 'trap']);
$router->get('/xmlrpc.php', [HoneypotController::class, 'trap']);

// Other CMS honeypots
$router->get('/administrator/', [HoneypotController::class, 'trap']);
$router->get('/admin/', [HoneypotController::class, 'trap']);
$router->get('/phpmyadmin/', [HoneypotController::class, 'trap']);
$router->get('/pma/', [HoneypotController::class, 'trap']);
$router->get('/adminer.php', [HoneypotController::class, 'trap']);

// Shell/backdoor honeypots
$router->get('/shell.php', [HoneypotController::class, 'trap']);
$router->get('/c99.php', [HoneypotController::class, 'trap']);
$router->get('/r57.php', [HoneypotController::class, 'trap']);
$router->get('/backdoor.php', [HoneypotController::class, 'trap']);
$router->get('/webshell.php', [HoneypotController::class, 'trap']);

// AWS/Cloud credentials
$router->get('/.aws/', [HoneypotController::class, 'trap']);
$router->get('/.aws/credentials', [HoneypotController::class, 'trap']);
$router->get('/.ssh/', [HoneypotController::class, 'trap']);
$router->get('/id_rsa', [HoneypotController::class, 'trap']);
$router->get('/id_rsa.pub', [HoneypotController::class, 'trap']);

// Docker/Kubernetes
$router->get('/docker-compose.yml', [HoneypotController::class, 'trap']);
$router->get('/Dockerfile', [HoneypotController::class, 'trap']);
$router->get('/kubernetes.yml', [HoneypotController::class, 'trap']);
$router->get('/.dockerignore', [HoneypotController::class, 'trap']);

// Credentials/Secrets
$router->get('/secrets.json', [HoneypotController::class, 'trap']);
$router->get('/credentials.json', [HoneypotController::class, 'trap']);
$router->get('/.htpasswd', [HoneypotController::class, 'trap']);
$router->get('/.htaccess', [HoneypotController::class, 'trap']);

// Package managers
$router->get('/composer.json', [HoneypotController::class, 'trap']);
$router->get('/composer.lock', [HoneypotController::class, 'trap']);
$router->get('/package.json', [HoneypotController::class, 'trap']);
$router->get('/package-lock.json', [HoneypotController::class, 'trap']);
$router->get('/.npmrc', [HoneypotController::class, 'trap']);

// ============================================================================
// MODERATION HONEYPOTS - Trap attackers guessing moderation URLs
// ============================================================================
// Common moderation URL patterns that attackers might try
$router->get('/mod', [HoneypotController::class, 'trap']);
$router->get('/mod/', [HoneypotController::class, 'trap']);
$router->get('/mods', [HoneypotController::class, 'trap']);
$router->get('/mods/', [HoneypotController::class, 'trap']);
$router->get('/moderation', [HoneypotController::class, 'trap']);
$router->get('/moderation/', [HoneypotController::class, 'trap']);
$router->get('/moderator', [HoneypotController::class, 'trap']);
$router->get('/moderator/', [HoneypotController::class, 'trap']);
$router->get('/moderators', [HoneypotController::class, 'trap']);
$router->get('/moderators/', [HoneypotController::class, 'trap']);
$router->get('/mod-panel', [HoneypotController::class, 'trap']);
$router->get('/mod-panel/', [HoneypotController::class, 'trap']);
$router->get('/modpanel', [HoneypotController::class, 'trap']);
$router->get('/modpanel/', [HoneypotController::class, 'trap']);
$router->get('/mod_panel', [HoneypotController::class, 'trap']);
$router->get('/mod_panel/', [HoneypotController::class, 'trap']);
$router->get('/mod-admin', [HoneypotController::class, 'trap']);
$router->get('/mod-admin/', [HoneypotController::class, 'trap']);
$router->get('/modadmin', [HoneypotController::class, 'trap']);
$router->get('/modadmin/', [HoneypotController::class, 'trap']);
$router->get('/mod_admin', [HoneypotController::class, 'trap']);
$router->get('/mod_admin/', [HoneypotController::class, 'trap']);

// Fake moderation URLs with fake hashes (attackers trying to guess the pattern)
$router->get('/mod_0000000000000000', [HoneypotController::class, 'trap']);
$router->get('/mod_1111111111111111', [HoneypotController::class, 'trap']);
$router->get('/mod_aaaaaaaaaaaaaaaa', [HoneypotController::class, 'trap']);
$router->get('/mod_admin123456789', [HoneypotController::class, 'trap']);
$router->get('/mod_test', [HoneypotController::class, 'trap']);
$router->get('/mod_login', [HoneypotController::class, 'trap']);
$router->get('/mod_dashboard', [HoneypotController::class, 'trap']);

// Staff/Support honeypots
$router->get('/staff', [HoneypotController::class, 'trap']);
$router->get('/staff/', [HoneypotController::class, 'trap']);
$router->get('/support', [HoneypotController::class, 'trap']);
$router->get('/support/', [HoneypotController::class, 'trap']);
$router->get('/support-panel', [HoneypotController::class, 'trap']);
$router->get('/staff-panel', [HoneypotController::class, 'trap']);

// Content moderation specific
$router->get('/content-moderation', [HoneypotController::class, 'trap']);
$router->get('/review', [HoneypotController::class, 'trap']);
$router->get('/review/', [HoneypotController::class, 'trap']);
$router->get('/reports', [HoneypotController::class, 'trap']);
$router->get('/reports/', [HoneypotController::class, 'trap']);
$router->get('/flagged', [HoneypotController::class, 'trap']);
$router->get('/flagged/', [HoneypotController::class, 'trap']);
$router->get('/ban-panel', [HoneypotController::class, 'trap']);
$router->get('/bans', [HoneypotController::class, 'trap']);
$router->get('/bans/', [HoneypotController::class, 'trap']);

// ============================================================================
// 🚀 ENTERPRISE GALAXY v2.0: API HONEYPOTS - Fake API endpoints
// ============================================================================
// Fake REST API endpoints (competitor/scanner bait)
$router->get('/api/v1/users', [HoneypotController::class, 'trap']);
$router->get('/api/v1/users/', [HoneypotController::class, 'trap']);
$router->get('/api/v2/users', [HoneypotController::class, 'trap']);
$router->get('/api/v1/admin', [HoneypotController::class, 'trap']);
$router->get('/api/v1/admin/', [HoneypotController::class, 'trap']);
$router->get('/api/v1/config', [HoneypotController::class, 'trap']);
$router->get('/api/v1/debug', [HoneypotController::class, 'trap']);
$router->get('/api/v2/debug', [HoneypotController::class, 'trap']);
$router->get('/api/v1/internal', [HoneypotController::class, 'trap']);
$router->get('/api/v2/internal', [HoneypotController::class, 'trap']);
$router->get('/api/internal', [HoneypotController::class, 'trap']);
$router->get('/api/debug', [HoneypotController::class, 'trap']);
$router->get('/api/private', [HoneypotController::class, 'trap']);
$router->get('/api/secret', [HoneypotController::class, 'trap']);
$router->get('/api/test', [HoneypotController::class, 'trap']);
$router->get('/api/admin', [HoneypotController::class, 'trap']);
$router->post('/api/v1/users', [HoneypotController::class, 'trap']);
$router->post('/api/v1/debug', [HoneypotController::class, 'trap']);
$router->post('/api/debug', [HoneypotController::class, 'trap']);

// GraphQL honeypots (common scanner target)
$router->get('/graphql', [HoneypotController::class, 'trap']);
$router->post('/graphql', [HoneypotController::class, 'trap']);
$router->get('/graphql/', [HoneypotController::class, 'trap']);
$router->get('/api/graphql', [HoneypotController::class, 'trap']);
$router->post('/api/graphql', [HoneypotController::class, 'trap']);
$router->get('/graphiql', [HoneypotController::class, 'trap']);
$router->get('/graphql/console', [HoneypotController::class, 'trap']);
$router->get('/graphql-playground', [HoneypotController::class, 'trap']);

// API Documentation honeypots (scanners love these)
$router->get('/swagger.json', [HoneypotController::class, 'trap']);
$router->get('/swagger.yaml', [HoneypotController::class, 'trap']);
$router->get('/openapi.json', [HoneypotController::class, 'trap']);
$router->get('/openapi.yaml', [HoneypotController::class, 'trap']);
$router->get('/api-docs', [HoneypotController::class, 'trap']);
$router->get('/api-docs/', [HoneypotController::class, 'trap']);
$router->get('/api/docs', [HoneypotController::class, 'trap']);
$router->get('/api/swagger', [HoneypotController::class, 'trap']);
$router->get('/swagger-ui/', [HoneypotController::class, 'trap']);
$router->get('/swagger-ui.html', [HoneypotController::class, 'trap']);
$router->get('/api/v1/docs', [HoneypotController::class, 'trap']);
$router->get('/api/v2/docs', [HoneypotController::class, 'trap']);
$router->get('/docs/api', [HoneypotController::class, 'trap']);
$router->get('/redoc', [HoneypotController::class, 'trap']);

// ============================================================================
// 🚀 ENTERPRISE GALAXY v2.0: STORAGE/BACKUP HONEYPOTS
// ============================================================================
// Fake storage/upload paths (competitor trying to access user data)
$router->get('/storage/users', [HoneypotController::class, 'trap']);
$router->get('/storage/users/', [HoneypotController::class, 'trap']);
$router->get('/storage/private', [HoneypotController::class, 'trap']);
$router->get('/storage/audio', [HoneypotController::class, 'trap']);
$router->get('/storage/uploads', [HoneypotController::class, 'trap']);
$router->get('/uploads/private', [HoneypotController::class, 'trap']);
$router->get('/uploads/audio', [HoneypotController::class, 'trap']);
$router->get('/private/audio', [HoneypotController::class, 'trap']);
$router->get('/private/users', [HoneypotController::class, 'trap']);
$router->get('/data/users', [HoneypotController::class, 'trap']);
$router->get('/data/audio', [HoneypotController::class, 'trap']);

// Backup file honeypots
$router->get('/backup.zip', [HoneypotController::class, 'trap']);
$router->get('/backup.tar.gz', [HoneypotController::class, 'trap']);
$router->get('/backup.tar', [HoneypotController::class, 'trap']);
$router->get('/site-backup.zip', [HoneypotController::class, 'trap']);
$router->get('/db-backup.sql', [HoneypotController::class, 'trap']);
$router->get('/database-backup.sql', [HoneypotController::class, 'trap']);
$router->get('/full-backup.zip', [HoneypotController::class, 'trap']);
$router->get('/www.zip', [HoneypotController::class, 'trap']);
$router->get('/html.zip', [HoneypotController::class, 'trap']);
$router->get('/public.zip', [HoneypotController::class, 'trap']);
$router->get('/users.sql', [HoneypotController::class, 'trap']);
$router->get('/users.csv', [HoneypotController::class, 'trap']);
$router->get('/users.json', [HoneypotController::class, 'trap']);
$router->get('/export.sql', [HoneypotController::class, 'trap']);
$router->get('/export.json', [HoneypotController::class, 'trap']);

// ============================================================================
// 🚀 ENTERPRISE GALAXY v2.0: DEBUG/LOG HONEYPOTS
// ============================================================================
// Debug endpoints (common on dev servers left in production)
$router->get('/debug', [HoneypotController::class, 'trap']);
$router->get('/debug/', [HoneypotController::class, 'trap']);
$router->get('/debug.php', [HoneypotController::class, 'trap']);
$router->get('/debug-sig.php', [HoneypotController::class, 'trap']);
$router->get('/debug-info', [HoneypotController::class, 'trap']);
$router->get('/_debug', [HoneypotController::class, 'trap']);
$router->get('/_profiler', [HoneypotController::class, 'trap']);
$router->get('/_profiler/', [HoneypotController::class, 'trap']);
$router->get('/telescope', [HoneypotController::class, 'trap']);
$router->get('/horizon', [HoneypotController::class, 'trap']);
$router->get('/clockwork', [HoneypotController::class, 'trap']);
$router->get('/debugbar', [HoneypotController::class, 'trap']);
$router->get('/ray', [HoneypotController::class, 'trap']);

// Log file honeypots
$router->get('/logs', [HoneypotController::class, 'trap']);
$router->get('/logs/', [HoneypotController::class, 'trap']);
$router->get('/log', [HoneypotController::class, 'trap']);
$router->get('/log/', [HoneypotController::class, 'trap']);
$router->get('/error.log', [HoneypotController::class, 'trap']);
$router->get('/debug.log', [HoneypotController::class, 'trap']);
$router->get('/access.log', [HoneypotController::class, 'trap']);
$router->get('/app.log', [HoneypotController::class, 'trap']);
$router->get('/laravel.log', [HoneypotController::class, 'trap']);
$router->get('/storage/logs', [HoneypotController::class, 'trap']);
$router->get('/storage/logs/', [HoneypotController::class, 'trap']);
$router->get('/var/log', [HoneypotController::class, 'trap']);

// ============================================================================
// 🚀 ENTERPRISE GALAXY v2.0: ACTUATOR/HEALTH HONEYPOTS (Spring Boot style)
// ============================================================================
$router->get('/actuator', [HoneypotController::class, 'trap']);
$router->get('/actuator/', [HoneypotController::class, 'trap']);
$router->get('/actuator/env', [HoneypotController::class, 'trap']);
$router->get('/actuator/health', [HoneypotController::class, 'trap']);
$router->get('/actuator/info', [HoneypotController::class, 'trap']);
$router->get('/actuator/configprops', [HoneypotController::class, 'trap']);
$router->get('/actuator/mappings', [HoneypotController::class, 'trap']);
$router->get('/actuator/beans', [HoneypotController::class, 'trap']);
$router->get('/actuator/heapdump', [HoneypotController::class, 'trap']);
$router->get('/actuator/threaddump', [HoneypotController::class, 'trap']);
$router->get('/health', [HoneypotController::class, 'trap']);
$router->get('/health/', [HoneypotController::class, 'trap']);
$router->get('/status', [HoneypotController::class, 'trap']);
$router->get('/status/', [HoneypotController::class, 'trap']);
$router->get('/metrics', [HoneypotController::class, 'trap']);
$router->get('/metrics/', [HoneypotController::class, 'trap']);

// ENTERPRISE GALAXY: Persistent flag pattern (standard Unix/Linux like systemd/nginx/mysql)
// Log SUCCESS only ONCE per container lifecycle - persists until container restart
static $logged = false;
$honeypotFlagFile = '/tmp/.need2talk_honeypot_init';

if (!$logged && !file_exists($honeypotFlagFile)) {
    \Need2Talk\Services\Logger::security('warning', 'ANTI-SCAN: Honeypot routes v2.0 initialized successfully', [
        'total_honeypots' => 180,  // Updated count with new API/GraphQL/Debug honeypots
        'ban_duration' => 604800, // 7 days
        'instant_ban_score' => 100,
        'new_features' => ['API honeypots', 'GraphQL traps', 'Swagger bait', 'Storage honeypots', 'Debug traps', 'Intelligence gathering'],
    ]);
    @touch($honeypotFlagFile); // Persists until container reboot (standard /tmp/ pattern)
    $logged = true; // Static cache prevents repeated file checks in same process
}
