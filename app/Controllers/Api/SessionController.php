<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Logger;

/**
 * Session API Controller
 *
 * Gestisce le operazioni sulle sessioni utente
 * PERFORMANCE OPTIMIZED: Uses connection pool instead of fresh connections
 */
class SessionController extends BaseController
{
    /**
     * Ottieni informazioni sessione corrente
     * ENTERPRISE SECURITY: Never expose numeric user_id - use UUID only
     */
    public function current(): void
    {
        // Get user UUID if authenticated
        $userUuid = null;
        if (isset($_SESSION['user_id'])) {
            $userUuid = $_SESSION['user_uuid'] ?? null;
        }

        $this->json([
            'success' => true,
            'data' => [
                'session_id' => session_id(),
                'authenticated' => isset($_SESSION['user_id']),
                // ENTERPRISE SECURITY: Expose UUID only, never numeric ID
                'user_uuid' => $userUuid,
                'csrf_token' => $_SESSION['csrf_token'] ?? null,
                'session_start' => $_SESSION['session_start'] ?? null,
            ],
        ]);
    }

    /**
     * Estendi durata sessione
     */
    public function extend(): void
    {
        if (!isset($_SESSION['user_id'])) {
            // ENTERPRISE SECURITY LOG: Unauthorized session extend attempt
            Logger::security('warning', 'SESSION: Extend attempt without active session', [
                'session_id' => substr(session_id(), 0, 8),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
            ]);

            $this->json([
                'success' => false,
                'error' => 'No active session to extend',
            ], 401);

            return;
        }

        // Estendi la sessione
        $_SESSION['last_activity'] = time();

        $this->json([
            'success' => true,
            'message' => 'Session extended successfully',
        ]);
    }

    /**
     * Lista sessioni attive utente
     * PERFORMANCE: Uses connection pool for optimal performance
     */
    public function list(): void
    {
        $user = $this->requireAuth();

        try {
            // PERFORMANCE: Use connection pool (auto-release wrapper)
            $pdo = db_pdo();

            // ENTERPRISE GALAXY: Optimized query with index hints for millions of users
            $stmt = $pdo->prepare('
                SELECT
                    id,
                    ip_address,
                    user_agent,
                    last_activity,
                    is_active,
                    created_at,
                    expires_at,
                    device_info,
                    location_info
                FROM user_sessions
                WHERE user_id = ?
                  AND is_active = TRUE
                  AND expires_at > NOW()
                ORDER BY last_activity DESC
                LIMIT 50
            ');
            $stmt->execute([$user['id']]);
            $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Mark current session
            $currentSessionId = session_id();
            $sessionsList = [];

            foreach ($sessions as $session) {
                $sessionData = [
                    'id' => $session['id'],
                    'current' => ($session['id'] === $currentSessionId),
                    'ip_address' => $session['ip_address'],
                    'user_agent' => $session['user_agent'],
                    'last_activity' => strtotime($session['last_activity']),
                    'created_at' => strtotime($session['created_at']),
                    'expires_at' => strtotime($session['expires_at']),
                    'device_info' => json_decode($session['device_info'] ?? '{}', true),
                    'location_info' => json_decode($session['location_info'] ?? '{}', true),
                ];

                // Calculate relative time for last activity
                $diff = time() - $sessionData['last_activity'];
                if ($diff < 60) {
                    $sessionData['relative_time'] = 'Just now';
                } elseif ($diff < 3600) {
                    $sessionData['relative_time'] = floor($diff / 60) . ' minutes ago';
                } elseif ($diff < 86400) {
                    $sessionData['relative_time'] = floor($diff / 3600) . ' hours ago';
                } else {
                    $sessionData['relative_time'] = floor($diff / 86400) . ' days ago';
                }

                $sessionsList[] = $sessionData;
            }

            // Connection auto-released when $pdo goes out of scope

            $this->json([
                'success' => true,
                'data' => [
                    'sessions' => $sessionsList,
                    'total' => count($sessionsList),
                    'current_session_id' => $currentSessionId,
                ],
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Failed to retrieve user sessions', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Failed to retrieve sessions',
            ], 500);
        }
    }

    /**
     * Revoca sessione specifica
     * PERFORMANCE: Multi-backend cleanup with pooled connection
     */
    public function revoke(string $sessionId): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE GALAXY: Security validation - prevent revoking current session
        $currentSessionId = session_id();
        if ($sessionId === $currentSessionId) {
            $this->json([
                'success' => false,
                'error' => 'Cannot revoke current session. Use logout instead.',
            ], 400);

            return;
        }

        try {
            // PERFORMANCE: Use connection pool for atomic operations
            $pdo = db_pdo();

            // ENTERPRISE GALAXY: Verify session ownership
            $stmt = $pdo->prepare('
                SELECT id, ip_address, user_agent
                FROM user_sessions
                WHERE id = ? AND user_id = ?
                LIMIT 1
            ');
            $stmt->execute([$sessionId, $user['id']]);
            $targetSession = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$targetSession) {
                $this->json([
                    'success' => false,
                    'error' => 'Session not found or access denied',
                ], 404);

                return;
            }

            // ENTERPRISE GALAXY: Transaction for atomic revocation
            $pdo->beginTransaction();

            try {
                // 1. Mark session as inactive in user_sessions
                $stmt = $pdo->prepare('
                    UPDATE user_sessions
                    SET is_active = FALSE
                    WHERE id = ? AND user_id = ?
                ');
                $stmt->execute([$sessionId, $user['id']]);

                // 2. Delete from sessions table (active sessions)
                $stmt = $pdo->prepare('
                    DELETE FROM sessions
                    WHERE id = ?
                ');
                $stmt->execute([$sessionId]);

                // 3. Log activity to session_activity
                $stmt = $pdo->prepare('
                    INSERT INTO session_activity
                    (session_id, user_id, activity_type, ip_address, user_agent, metadata)
                    VALUES (?, ?, \'force_logout\', ?, ?, ?)
                ');
                $metadata = json_encode([
                    'revoked_by_user_id' => $user['id'],
                    'revoked_from_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'reason' => 'user_initiated_revocation',
                ]);
                $stmt->execute([
                    $sessionId,
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    $metadata,
                ]);

                $pdo->commit();

            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            // ENTERPRISE GALAXY: Multi-backend cleanup (Redis)
            try {
                // ENTERPRISE POOL: Use connection pool for session cleanup
                $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_cache');

                if ($redis) {
                    // Delete from Redis session storage (L1 cache handles DB 1 automatically)
                    $redis->del('need2talk:sess:' . $sessionId);
                    $redis->del('PHPREDIS_SESSION:' . $sessionId);
                }
            } catch (\Exception $e) {
                // Redis cleanup failed - not critical since DB is updated
                Logger::database('warning', 'SESSION: Redis cleanup failed during revocation', [
                    'session_id' => substr($sessionId, 0, 8),
                    'error' => $e->getMessage(),
                ]);
            }

            // Connection auto-released when $pdo goes out of scope

            // ENTERPRISE SECURITY LOG: Session revoke success
            Logger::security('warning', 'SESSION: User revoked specific session', [
                'user_id' => $user['id'],
                'revoked_session_id' => substr($sessionId, 0, 8),
                'current_session_id' => substr($currentSessionId, 0, 8),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'revoked_session_ip' => $targetSession['ip_address'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => 'Session revoked successfully',
                'revoked_session' => [
                    'id' => substr($sessionId, 0, 8) . '...',
                    'ip_address' => $targetSession['ip_address'],
                ],
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Failed to revoke session', [
                'user_id' => $user['id'],
                'session_id' => substr($sessionId, 0, 8),
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Failed to revoke session',
            ], 500);
        }
    }

    /**
     * Revoca tutte le altre sessioni
     * PERFORMANCE: Batch operations with pooled connection
     */
    public function revokeOthers(): void
    {
        $user = $this->requireAuth();
        $currentSessionId = session_id();

        try {
            // PERFORMANCE: Use connection pool for batch operations
            $pdo = db_pdo();

            // ENTERPRISE GALAXY: Get all other active sessions for this user
            $stmt = $pdo->prepare('
                SELECT id
                FROM user_sessions
                WHERE user_id = ?
                  AND id != ?
                  AND is_active = TRUE
                  AND expires_at > NOW()
            ');
            $stmt->execute([$user['id'], $currentSessionId]);
            $otherSessions = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($otherSessions)) {
                $this->json([
                    'success' => true,
                    'message' => 'No other sessions to revoke',
                    'revoked_count' => 0,
                ]);

                return;
            }

            // ENTERPRISE GALAXY: Transaction for atomic batch revocation
            $pdo->beginTransaction();

            try {
                // 1. Mark all other sessions as inactive (batch update)
                $placeholders = implode(',', array_fill(0, count($otherSessions), '?'));
                $stmt = $pdo->prepare("
                    UPDATE user_sessions
                    SET is_active = FALSE
                    WHERE user_id = ?
                      AND id != ?
                      AND id IN ($placeholders)
                ");
                $params = array_merge([$user['id'], $currentSessionId], $otherSessions);
                $stmt->execute($params);
                $updatedCount = $stmt->rowCount();

                // 2. Delete from sessions table (batch delete)
                $stmt = $pdo->prepare("
                    DELETE FROM sessions
                    WHERE id IN ($placeholders)
                ");
                $stmt->execute($otherSessions);

                // 3. Log batch activity to session_activity
                $stmt = $pdo->prepare('
                    INSERT INTO session_activity
                    (session_id, user_id, activity_type, ip_address, user_agent, metadata)
                    VALUES (?, ?, \'force_logout\', ?, ?, ?)
                ');
                $metadata = json_encode([
                    'revoked_by_user_id' => $user['id'],
                    'revoked_from_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'reason' => 'user_revoked_all_other_sessions',
                    'batch_revocation' => true,
                    'total_sessions_revoked' => count($otherSessions),
                ]);
                // Log once for the batch operation using current session
                $stmt->execute([
                    $currentSessionId,
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    $metadata,
                ]);

                $pdo->commit();

            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            // ENTERPRISE GALAXY: Multi-backend cleanup (Redis batch delete)
            try {
                // ENTERPRISE POOL: Use connection pool for batch session cleanup
                $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_cache');

                if ($redis) {
                    // Batch delete Redis keys for all revoked sessions (L1 cache handles DB 1 automatically)
                    $keysToDelete = [];
                    foreach ($otherSessions as $sessionId) {
                        $keysToDelete[] = 'need2talk:sess:' . $sessionId;
                        $keysToDelete[] = 'PHPREDIS_SESSION:' . $sessionId;
                    }

                    if (!empty($keysToDelete)) {
                        $redis->del($keysToDelete);
                    }
                }
            } catch (\Exception $e) {
                // Redis cleanup failed - not critical since DB is updated
                Logger::database('warning', 'SESSION: Redis batch cleanup failed during mass revocation', [
                    'user_id' => $user['id'],
                    'sessions_count' => count($otherSessions),
                    'error' => $e->getMessage(),
                ]);
            }

            // Connection auto-released when $pdo goes out of scope

            // ENTERPRISE SECURITY LOG: Mass revoke success
            Logger::security('warning', 'SESSION: User revoked all other sessions', [
                'user_id' => $user['id'],
                'current_session_id' => substr($currentSessionId, 0, 8),
                'revoked_count' => count($otherSessions),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
            ]);

            $this->json([
                'success' => true,
                'message' => 'Other sessions revoked successfully',
                'revoked_count' => count($otherSessions),
                'current_session_preserved' => true,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Failed to revoke other sessions', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Failed to revoke other sessions',
            ], 500);
        }
    }

    /**
     * Refresh CSRF Token - ENTERPRISE SECURITY
     */
    public function refreshCsrfToken(): void
    {
        try {
            // Genera nuovo token CSRF sicuro
            $newToken = bin2hex(random_bytes(32));

            // Salva in sessione
            $_SESSION['csrf_token'] = $newToken;

            // Risposta JSON
            $this->json([
                'success' => true,
                'csrf_token' => $newToken,
                'message' => 'CSRF token refreshed successfully',
            ]);

        } catch (\Exception $e) {
            Logger::security('error', 'CSRF token refresh failed', [
                'error_message' => $e->getMessage(),
                'session_id' => substr(session_id(), 0, 8),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);

            $this->json([
                'success' => false,
                'error' => 'Failed to refresh CSRF token',
            ], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY: Check if admin session is still valid with smart extension support
     *
     * Used by AdminSessionGuard.js for heartbeat checks
     * Returns 200 if valid, 401 if expired
     * Includes time_remaining and URL validity information
     * Does NOT log as security warning (prevents log spam)
     *
     * @return void (outputs JSON)
     */
    public function check(): void
    {
        // 🚀 ENTERPRISE GALAXY FIX: Use COOKIE-BASED admin authentication (not $_SESSION)
        // The admin system uses cookie 'admin_session' + PostgreSQL 'admin_sessions' table
        // $_SESSION['admin_authenticated'] is NEVER set, so we can't rely on it!

        $adminSessionToken = $_COOKIE['__Host-admin_session'] ?? null;
        $isUserAuth = isset($_SESSION['user_id']);

        // Validate admin session via cookie (enterprise method)
        $adminData = null;
        $isAdminAuth = false;
        if ($adminSessionToken) {
            try {
                $adminService = new \Need2Talk\Services\AdminSecurityService();
                $adminData = $adminService->validateAdminSession($adminSessionToken);
                $isAdminAuth = ($adminData !== null);
            } catch (\Exception $e) {
                Logger::error('SessionController: Failed to validate admin session', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isAdminAuth || $isUserAuth) {
            // Session is valid
            $adminId = $isAdminAuth ? ($adminData['admin_id'] ?? null) : ($_SESSION['admin_id'] ?? null);
            $userId = $_SESSION['user_id'] ?? null;

            // Update last activity time in session (PHP session)
            $_SESSION['last_activity'] = time();
            if ($isAdminAuth) {
                $_SESSION['admin_last_activity'] = time();
            }

            // ENTERPRISE GALAXY: Calculate time remaining from DATABASE (not PHP session!)
            // PHP session['admin_session_expiry'] can be stale after extensions
            // ENTERPRISE GALAXY V6.6: Use ADMIN session lifetime from env
            $timeRemaining = EnterpriseGlobalsManager::getAdminSessionLifetimeSeconds(); // Default fallback
            $inExtensionWindow = false;
            $sessionExtended = false;

            if ($isAdminAuth && $adminSessionToken) {
                try {
                    $pdo = db_pdo();
                    $hashedToken = hash('sha256', $adminSessionToken);

                    $stmt = $pdo->prepare("
                        SELECT EXTRACT(EPOCH FROM (expires_at - NOW())) as remaining_seconds
                        FROM admin_sessions
                        WHERE session_token = ? AND expires_at > NOW()
                        LIMIT 1
                    ");
                    $stmt->execute([$hashedToken]);
                    $result = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if ($result && $result['remaining_seconds'] > 0) {
                        $timeRemaining = (int) $result['remaining_seconds'];
                        $inExtensionWindow = $timeRemaining <= 300; // Last 5 minutes (for warning only)

                        // ENTERPRISE GALAXY FIX: SEMPRE auto-extend quando c'è heartbeat!
                        // Se il JS sta chiamando /api/session/check, significa che l'utente è ATTIVO
                        // Quindi estendiamo SEMPRE, non solo negli ultimi 5 minuti
                        // Extension window serve solo per mostrare warning JS, non per decidere se estendere

                        // ENTERPRISE GALAXY V6.6: Use ADMIN session lifetime from env (not hardcoded)
                        $adminLifetimeMinutes = EnterpriseGlobalsManager::getAdminSessionLifetimeMinutes();
                        $extendStmt = $pdo->prepare("
                            UPDATE admin_sessions
                            SET expires_at = NOW() + INTERVAL '{$adminLifetimeMinutes} minutes',
                                last_activity = NOW()
                            WHERE session_token = ?
                        ");
                        $extendStmt->execute([$hashedToken]);

                        // ENTERPRISE GALAXY CRITICAL FIX: Update PHP session to reset Redis TTL!
                        // Without this, Redis session expires even if admin_sessions is extended

                        // STEP 1: Touch session data (mark as modified)
                        $_SESSION['last_heartbeat'] = time(); // Force session write to reset Redis TTL

                        // STEP 2: FORCE immediate write to Redis + restart session
                        // session_write_close() writes NOW to Redis (resets TTL to ADMIN_SESSION_LIFETIME)
                        // session_start() resumes session for continued request processing
                        session_write_close();
                        session_start();

                        // STEP 3: CRITICAL! Touch again AFTER restart to ensure PHP detects modification!
                        // Without this, PHP might NOT write to Redis at shutdown (thinks nothing changed)
                        $_SESSION['last_heartbeat'] = time();

                        $sessionExtended = true;
                        $timeRemaining = EnterpriseGlobalsManager::getAdminSessionLifetimeSeconds();
                        $inExtensionWindow = false; // Never in extension window after auto-extend
                    }
                } catch (\Exception $e) {
                    // Database error - use fallback
                    Logger::error('SessionController: Failed to get session expiry', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ENTERPRISE: Check if current admin URL is still valid
            $currentAdminUrl = null;
            $adminUrlValid = false;
            if ($isAdminAuth) {
                try {
                    $currentPath = $_SERVER['REQUEST_URI'] ?? '';
                    if (preg_match('/\/admin_([a-f0-9]{16})/', $currentPath, $matches)) {
                        $currentAdminUrl = '/admin_' . $matches[1];
                        $adminUrlValid = \Need2Talk\Services\AdminSecurityService::validateAdminUrl($currentAdminUrl);
                    }
                } catch (\Exception $e) {
                    // URL validation failed - not critical
                }
            }

            // ENTERPRISE SECURITY: Get user UUID for response (never expose numeric IDs)
            $userUuid = $_SESSION['user_uuid'] ?? null;

            $this->json([
                'success' => true,
                'authenticated' => true,
                'admin' => $isAdminAuth,
                'user' => $isUserAuth,
                // ENTERPRISE SECURITY: Never expose numeric IDs to frontend - use UUID only
                'user_uuid' => $userUuid,
                'timestamp' => time(),
                // ENTERPRISE GALAXY: Smart session management data for frontend
                'time_remaining' => $timeRemaining,
                'in_extension_window' => $inExtensionWindow,
                'admin_url_valid' => $adminUrlValid,
                'current_admin_url' => $currentAdminUrl,
            ]);

            // ENTERPRISE GALAXY DEBUG: Log DETAILED session check (helps diagnose premature timeout)
            // CRITICAL: Use security channel with info level (visible in production logs)
            Logger::security('info', 'SESSION CHECK: Heartbeat successful ✅', [
                'admin' => $isAdminAuth,
                'admin_id' => $adminId,
                'user_id' => $userId,
                'time_remaining_seconds' => $timeRemaining,
                'time_remaining_minutes' => round($timeRemaining / 60, 1),
                'session_extended' => $sessionExtended ?? false,
                'in_extension_window' => $inExtensionWindow,
                'admin_url_valid' => $adminUrlValid,
                'current_admin_url' => $currentAdminUrl,
                'session_id' => substr(session_id(), 0, 16) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } else {
            // Session expired or not authenticated
            http_response_code(401);
            $this->json([
                'success' => false,
                'authenticated' => false,
                'reason' => 'session_expired',
            ]);

            // ENTERPRISE: Log at INFO level (not WARNING) to prevent security log spam
            // This is NORMAL behavior when users leave pages open
            Logger::info('Session check: expired (normal timeout)', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'session_id' => substr(session_id(), 0, 8),
            ]);
        }

        exit;
    }
}
