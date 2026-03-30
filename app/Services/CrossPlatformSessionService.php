<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * CrossPlatformSessionService - Enterprise Cross-Platform Session Isolation
 *
 * ENTERPRISE GALAXY: Prevents simultaneous login across different platforms.
 * A user/moderator with the same email cannot be logged in to both
 * the User Site and Moderation Portal at the same time.
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  Redis Key: need2talk:xplatform:{email_hash}                        │
 * │  Value: JSON { platform, session_id, login_at, ip, user_agent }     │
 * │  TTL: Platform-specific (moderator: 4h, user: 24h)                  │
 * │  Redis DB: Session DB (DB 1) via EnterpriseRedisManager             │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * SECURITY BENEFITS:
 * - Prevents session hijacking across platforms
 * - Ensures moderators can't simultaneously act as users
 * - Audit trail for cross-platform login attempts
 * - Privacy-safe: uses email hash, not plain email
 *
 * @package Need2Talk\Services
 */
class CrossPlatformSessionService
{
    // Platform identifiers
    public const PLATFORM_USER = 'user';
    public const PLATFORM_MODERATOR = 'moderator';

    // Redis key prefix
    private const REDIS_PREFIX = 'need2talk:xplatform:';

    // Session TTL (in seconds)
    private const TTL_USER = 86400;      // 24 hours (matches user session)
    private const TTL_MODERATOR = 14400; // 4 hours (matches moderator session)

    /**
     * Get Redis connection via EnterpriseRedisManager
     *
     * ENTERPRISE: Uses the session Redis connection (DB 1) for session-related data.
     * Falls back gracefully if Redis is unavailable.
     *
     * @return \Redis|null Redis instance or null on failure
     */
    private static function getRedis(): ?\Redis
    {
        try {
            return EnterpriseRedisManager::getInstance()->getConnection('sessions');
        } catch (\Exception $e) {
            Logger::error('XPLATFORM: Failed to get Redis connection', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a login is allowed on the specified platform
     *
     * @param string $email User/moderator email
     * @param string $platform Target platform (self::PLATFORM_USER or self::PLATFORM_MODERATOR)
     * @return array ['allowed' => bool, 'reason' => string, 'blocking_platform' => ?string]
     */
    public static function canLogin(string $email, string $platform): array
    {
        $emailHash = self::hashEmail($email);
        $key = self::REDIS_PREFIX . $emailHash;

        try {
            $redis = self::getRedis();
            if ($redis === null) {
                // Redis unavailable - fail open
                return [
                    'allowed' => true,
                    'reason' => 'Redis unavailable (fail-open policy)',
                    'blocking_platform' => null,
                    'redis_error' => true,
                ];
            }

            $existingSession = $redis->get($key);

            if (!$existingSession) {
                // No cross-platform session exists, login allowed
                return [
                    'allowed' => true,
                    'reason' => 'No active session on other platform',
                    'blocking_platform' => null,
                ];
            }

            $sessionData = json_decode($existingSession, true);
            if (!$sessionData || !isset($sessionData['platform'])) {
                // Corrupted data, allow login (will overwrite)
                Logger::warning('XPLATFORM: Corrupted session data found', [
                    'email_hash' => $emailHash,
                    'raw_data' => substr($existingSession, 0, 100),
                ]);
                return [
                    'allowed' => true,
                    'reason' => 'Corrupted session data (will be replaced)',
                    'blocking_platform' => null,
                ];
            }

            $existingPlatform = $sessionData['platform'];

            // Same platform login is allowed (session refresh)
            if ($existingPlatform === $platform) {
                return [
                    'allowed' => true,
                    'reason' => 'Same platform re-login (session refresh)',
                    'blocking_platform' => null,
                ];
            }

            // Different platform - BLOCKED
            Logger::security('warning', 'XPLATFORM: Cross-platform login blocked', [
                'email_hash' => $emailHash,
                'requested_platform' => $platform,
                'blocking_platform' => $existingPlatform,
                'blocking_session_age' => time() - ($sessionData['login_at'] ?? 0),
                'blocking_ip' => $sessionData['ip'] ?? 'unknown',
                'current_ip' => get_server('REMOTE_ADDR'),
            ]);

            return [
                'allowed' => false,
                'reason' => self::getBlockedMessage($platform, $existingPlatform),
                'blocking_platform' => $existingPlatform,
                'blocking_since' => $sessionData['login_at'] ?? null,
            ];

        } catch (\Exception $e) {
            // Redis error - fail open (allow login) but log
            Logger::error('XPLATFORM: Redis error during canLogin check', [
                'error' => $e->getMessage(),
                'email_hash' => $emailHash,
                'platform' => $platform,
            ]);

            // ENTERPRISE SECURITY: Fail open to not block legitimate users
            // but track this for investigation
            return [
                'allowed' => true,
                'reason' => 'Redis unavailable (fail-open policy)',
                'blocking_platform' => null,
                'redis_error' => true,
            ];
        }
    }

    /**
     * Register a successful login on a platform
     *
     * @param string $email User/moderator email
     * @param string $platform Platform identifier
     * @param string $sessionId Session token/ID for tracking
     * @param array $metadata Additional metadata (ip, user_agent, etc.)
     * @return bool Success status
     */
    public static function registerLogin(
        string $email,
        string $platform,
        string $sessionId,
        array $metadata = []
    ): bool {
        $emailHash = self::hashEmail($email);
        $key = self::REDIS_PREFIX . $emailHash;

        $sessionData = [
            'platform' => $platform,
            'session_id' => $sessionId,
            'login_at' => time(),
            'ip' => $metadata['ip'] ?? get_server('REMOTE_ADDR'),
            'user_agent' => substr($metadata['user_agent'] ?? get_server('HTTP_USER_AGENT'), 0, 200),
        ];

        $ttl = ($platform === self::PLATFORM_MODERATOR) ? self::TTL_MODERATOR : self::TTL_USER;

        try {
            $redis = self::getRedis();
            if ($redis === null) {
                Logger::error('XPLATFORM: Redis unavailable for registerLogin', [
                    'email_hash' => $emailHash,
                    'platform' => $platform,
                ]);
                return false;
            }

            $result = $redis->setex($key, $ttl, json_encode($sessionData));

            Logger::security('info', 'XPLATFORM: Session registered', [
                'email_hash' => $emailHash,
                'platform' => $platform,
                'ttl_seconds' => $ttl,
                'ip' => $sessionData['ip'],
            ]);

            return (bool) $result;

        } catch (\Exception $e) {
            Logger::error('XPLATFORM: Failed to register session', [
                'error' => $e->getMessage(),
                'email_hash' => $emailHash,
                'platform' => $platform,
            ]);
            return false;
        }
    }

    /**
     * Clear the cross-platform session on logout
     *
     * @param string $email User/moderator email
     * @param string $platform Platform logging out from
     * @return bool Success status
     */
    public static function clearSession(string $email, string $platform): bool
    {
        $emailHash = self::hashEmail($email);
        $key = self::REDIS_PREFIX . $emailHash;

        try {
            $redis = self::getRedis();
            if ($redis === null) {
                Logger::error('XPLATFORM: Redis unavailable for clearSession', [
                    'email_hash' => $emailHash,
                    'platform' => $platform,
                ]);
                // Return true to allow logout to proceed even if Redis fails
                return true;
            }

            // Verify we're clearing our own session (not another platform's)
            $existingSession = $redis->get($key);
            if ($existingSession) {
                $sessionData = json_decode($existingSession, true);
                if ($sessionData && isset($sessionData['platform']) && $sessionData['platform'] !== $platform) {
                    // Don't clear another platform's session
                    Logger::security('warning', 'XPLATFORM: Attempted to clear other platform session', [
                        'email_hash' => $emailHash,
                        'requesting_platform' => $platform,
                        'existing_platform' => $sessionData['platform'],
                    ]);
                    return false;
                }
            }

            $redis->del($key);

            Logger::security('info', 'XPLATFORM: Session cleared', [
                'email_hash' => $emailHash,
                'platform' => $platform,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('XPLATFORM: Failed to clear session', [
                'error' => $e->getMessage(),
                'email_hash' => $emailHash,
                'platform' => $platform,
            ]);
            // Return true to allow logout to proceed even if there's an error
            return true;
        }
    }

    /**
     * Validate that a session is still valid (optional periodic check)
     *
     * @param string $email User/moderator email
     * @param string $platform Expected platform
     * @param string $sessionId Expected session ID
     * @return bool True if session is valid
     */
    public static function validateSession(string $email, string $platform, string $sessionId): bool
    {
        $emailHash = self::hashEmail($email);
        $key = self::REDIS_PREFIX . $emailHash;

        try {
            $redis = self::getRedis();
            if ($redis === null) {
                // Fail open - if Redis is down, don't break the session
                return true;
            }

            $existingSession = $redis->get($key);

            if (!$existingSession) {
                return false;
            }

            $sessionData = json_decode($existingSession, true);
            if (!$sessionData) {
                return false;
            }

            return $sessionData['platform'] === $platform
                && $sessionData['session_id'] === $sessionId;

        } catch (\Exception $e) {
            Logger::error('XPLATFORM: Session validation error', [
                'error' => $e->getMessage(),
                'email_hash' => $emailHash,
            ]);
            // Fail open - if Redis is down, don't break the session
            return true;
        }
    }

    /**
     * Refresh the TTL of an existing session (called on activity)
     *
     * @param string $email User/moderator email
     * @param string $platform Current platform
     * @return bool Success status
     */
    public static function refreshSession(string $email, string $platform): bool
    {
        $emailHash = self::hashEmail($email);
        $key = self::REDIS_PREFIX . $emailHash;

        try {
            $redis = self::getRedis();
            if ($redis === null) {
                return false;
            }

            // Only refresh if session exists and matches platform
            $existingSession = $redis->get($key);
            if (!$existingSession) {
                return false;
            }

            $sessionData = json_decode($existingSession, true);
            if (!$sessionData || ($sessionData['platform'] ?? '') !== $platform) {
                return false;
            }

            // Refresh TTL
            $ttl = ($platform === self::PLATFORM_MODERATOR) ? self::TTL_MODERATOR : self::TTL_USER;
            $redis->expire($key, $ttl);

            return true;

        } catch (\Exception $e) {
            // Non-critical error
            return false;
        }
    }

    /**
     * Get human-readable blocked message
     */
    private static function getBlockedMessage(string $requestedPlatform, string $blockingPlatform): string
    {
        if ($requestedPlatform === self::PLATFORM_USER) {
            return 'Sei già autenticato nel Portale Moderazione. Effettua il logout dal portale moderazione prima di accedere come utente.';
        }

        return 'Sei già autenticato come utente sul sito. Effettua il logout dal sito utenti prima di accedere al Portale Moderazione.';
    }

    /**
     * Hash email for privacy-safe storage
     * Uses SHA256 with a salt for additional security
     */
    private static function hashEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));
        $salt = get_env('APP_KEY') ?? 'need2talk_xplatform_salt';
        return hash('sha256', $normalizedEmail . $salt);
    }

    /**
     * Get active session info (for debugging/admin purposes only)
     *
     * @param string $email User/moderator email
     * @return array|null Session data or null if not found
     */
    public static function getActiveSession(string $email): ?array
    {
        $emailHash = self::hashEmail($email);
        $key = self::REDIS_PREFIX . $emailHash;

        try {
            $redis = self::getRedis();
            if ($redis === null) {
                return null;
            }

            $existingSession = $redis->get($key);

            if (!$existingSession) {
                return null;
            }

            $sessionData = json_decode($existingSession, true);
            if (!$sessionData) {
                return null;
            }

            // Add TTL info
            $sessionData['remaining_ttl'] = $redis->ttl($key);

            return $sessionData;

        } catch (\Exception $e) {
            return null;
        }
    }
}
