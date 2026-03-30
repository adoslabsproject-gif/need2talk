<?php
/**
 * JWT Service - Enterprise Galaxy
 *
 * JSON Web Token (JWT) generation and verification for WebSocket authentication
 * Uses firebase/php-jwt library (industry standard)
 *
 * ARCHITECTURE:
 * - HS256 algorithm (HMAC-SHA256 symmetric signing)
 * - 24-hour token lifetime (re-login daily)
 * - Minimal payload (uuid + timestamps only)
 * - Exception-based error handling (no graceful fallback)
 * - PSR-3 logging integration
 *
 * SECURITY:
 * - Secret key from environment (JWT_SECRET or APP_KEY fallback)
 * - Token expiration enforced (exp claim)
 * - Issued-at timestamp (iat claim) for audit trail
 * - No sensitive data in payload (stateless authentication)
 *
 * USAGE EXAMPLES:
 *
 * // Generate token for user (at login)
 * $token = JWTService::generate($user['uuid']);
 * // Store in localStorage/cookie for WebSocket auth
 *
 * // Verify token (WebSocket connection)
 * try {
 *     $payload = JWTService::verify($token);
 *     $userUuid = $payload['uuid'];
 * } catch (\Exception $e) {
 *     // Token invalid/expired - close connection
 * }
 *
 * PERFORMANCE:
 * - HS256 signing: ~0.1ms (very fast)
 * - Verification: ~0.1ms
 * - Stateless (no database query needed)
 *
 * REFERENCES:
 * - JWT RFC: https://tools.ietf.org/html/rfc7519
 * - firebase/php-jwt: https://github.com/firebase/php-jwt
 * - HS256 spec: https://tools.ietf.org/html/rfc7518#section-3.2
 *
 * @package Need2Talk\Services
 * @author  need2talk Enterprise Team
 * @version 1.0.0
 */

namespace Need2Talk\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class JWTService
{
    /**
     * JWT algorithm (HS256 = HMAC-SHA256 symmetric)
     *
     * RATIONALE:
     * - HS256: Symmetric (fast, simple, sufficient for server-to-server)
     * - RS256: Asymmetric (slower, complex, overkill for WebSocket auth)
     *
     * SECURITY: HS256 is secure IF secret is strong (256+ bits entropy)
     *
     * @var string
     */
    private const ALGORITHM = 'HS256';

    /**
     * Token lifetime (seconds)
     *
     * RATIONALE:
     * - 24 hours = 86400 seconds
     * - Balance: Long enough to avoid frequent re-auth, short enough for security
     * - User re-login daily is acceptable UX
     *
     * ALTERNATIVE: 7 days for "remember me" functionality (future enhancement)
     *
     * @var int
     */
    private const TOKEN_LIFETIME = 86400; // 24 hours

    /**
     * Generate JWT token for user
     *
     * TOKEN PAYLOAD:
     * {
     *   "uuid": "user-uuid-here",
     *   "iat": 1234567890,  // Issued at (Unix timestamp)
     *   "exp": 1234654290   // Expiration (iat + 24 hours)
     * }
     *
     * PERFORMANCE: ~0.1ms (HS256 is very fast)
     * STATELESS: No database query needed
     *
     * @param string $userUuid User UUID (36 characters)
     * @param array $extraClaims Optional additional claims (use sparingly!)
     * @return string JWT token (base64 encoded, ~150-200 chars)
     * @throws \RuntimeException If secret key not configured
     */
    public static function generate(string $userUuid, array $extraClaims = []): string
    {
        // ENTERPRISE: Validate UUID format (basic sanity check)
        if (strlen($userUuid) !== 36) {
            Logger::websocket('warning', 'Invalid UUID format for token generation', [
                'uuid' => $userUuid,
                'length' => strlen($userUuid)
            ]);
            throw new \InvalidArgumentException('Invalid UUID format');
        }

        // ENTERPRISE: Build payload (minimal claims for performance)
        $issuedAt = time();
        $expiresAt = $issuedAt + self::TOKEN_LIFETIME;

        $payload = array_merge([
            'uuid' => $userUuid,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], $extraClaims);

        // ENTERPRISE: Get secret key (with fallback)
        $secret = self::getSecret();

        // ENTERPRISE: Encode JWT token
        try {
            $token = JWT::encode($payload, $secret, self::ALGORITHM);

            Logger::websocket('debug', 'Token generated', [
                'uuid' => $userUuid,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
                'lifetime_hours' => self::TOKEN_LIFETIME / 3600,
                'token_length' => strlen($token)
            ]);

            return $token;

        } catch (\Exception $e) {
            // CRITICAL: JWT encoding failed (should never happen with HS256)
            Logger::websocket('error', 'Token generation failed', [
                'uuid' => $userUuid,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to generate JWT token');
        }
    }

    /**
     * Verify JWT token and extract payload
     *
     * VALIDATION:
     * - Signature verification (HMAC-SHA256)
     * - Expiration check (exp claim)
     * - Issued-at check (iat claim)
     *
     * ERRORS:
     * - ExpiredException: Token expired
     * - SignatureInvalidException: Token tampered/invalid secret
     * - BeforeValidException: Token used before iat time (clock skew)
     * - UnexpectedValueException: Malformed token
     *
     * PERFORMANCE: ~0.1ms (HS256 verification is fast)
     *
     * @param string $token JWT token to verify
     * @return array Decoded payload (associative array)
     * @throws \Exception If token is invalid/expired
     */
    public static function verify(string $token): array
    {
        try {
            // ENTERPRISE: Get secret key
            $secret = self::getSecret();

            // ENTERPRISE: Decode and verify token
            // Firebase\JWT\JWT::decode() automatically validates:
            // - Signature (HMAC-SHA256)
            // - Expiration (exp claim)
            // - Not-before (nbf claim, if present)
            $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));

            // ENTERPRISE: Convert stdClass to array
            $payload = (array) $decoded;

            // ENTERPRISE: Validate required claims
            if (!isset($payload['uuid'])) {
                throw new \UnexpectedValueException('Token missing required claim: uuid');
            }

            Logger::websocket('debug', 'Token verified', [
                'uuid' => $payload['uuid'],
                'iat' => $payload['iat'] ?? null,
                'exp' => $payload['exp'] ?? null,
                'remaining_seconds' => isset($payload['exp']) ? ($payload['exp'] - time()) : null
            ]);

            return $payload;

        } catch (ExpiredException $e) {
            // WARNING: Token expired (expected after 24 hours)
            Logger::websocket('warning', 'Token expired', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 50) . '...'
            ]);
            throw new \Exception('Token expired');

        } catch (SignatureInvalidException $e) {
            // WARNING: Invalid signature (token tampered or wrong secret)
            Logger::websocket('warning', 'Invalid token signature', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 50) . '...'
            ]);
            throw new \Exception('Invalid token signature');

        } catch (BeforeValidException $e) {
            // WARNING: Token used before iat time (clock skew issue)
            Logger::websocket('warning', 'Token not yet valid', [
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Token not yet valid');

        } catch (\UnexpectedValueException $e) {
            // WARNING: Malformed token
            Logger::websocket('warning', 'Malformed token', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 50) . '...'
            ]);
            throw new \Exception('Malformed token');

        } catch (\Exception $e) {
            // ERROR: Unexpected error
            Logger::websocket('error', 'Token verification failed', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 50) . '...'
            ]);
            throw new \Exception('Token verification failed');
        }
    }

    /**
     * Get JWT secret key from environment
     *
     * PRIORITY:
     * 1. JWT_SECRET (dedicated env var)
     * 2. APP_KEY (fallback to application key)
     *
     * SECURITY:
     * - Secret MUST be strong (256+ bits entropy)
     * - Secret MUST be kept confidential (never commit to git)
     * - Rotation: Change secret = invalidate ALL tokens (use with caution)
     *
     * @return string Secret key
     * @throws \RuntimeException If no secret configured
     */
    protected static function getSecret(): string
    {
        // ENTERPRISE: Try dedicated JWT secret first (getenv + $_ENV fallback)
        $secret = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? null);

        // ENTERPRISE: Fallback to APP_KEY (getenv + $_ENV fallback)
        if (!$secret || empty($secret)) {
            $secret = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? null);
        }

        // CRITICAL: No secret configured (cannot proceed)
        if (!$secret || empty($secret)) {
            Logger::websocket('error', 'JWT secret not configured', [
                'env_JWT_SECRET' => (getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? null)) ? 'set but empty' : 'not set',
                'env_APP_KEY' => (getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? null)) ? 'set but empty' : 'not set'
            ]);
            throw new \RuntimeException('JWT_SECRET or APP_KEY must be configured in environment');
        }

        // ENTERPRISE: Validate secret strength (minimum 32 characters = 256 bits)
        if (strlen($secret) < 32) {
            Logger::websocket('warning', 'Weak JWT secret detected', [
                'length' => strlen($secret),
                'recommended' => 32
            ]);
        }

        return $secret;
    }

    /**
     * Generate token with custom lifetime
     *
     * USE CASE: "Remember me" functionality (7-30 days)
     *
     * SECURITY: Longer lifetime = higher security risk
     * RECOMMENDATION: Use sparingly, only for "remember me" feature
     *
     * @param string $userUuid User UUID
     * @param int $lifetimeSeconds Token lifetime in seconds
     * @param array $extraClaims Optional additional claims
     * @return string JWT token
     */
    public static function generateWithLifetime(string $userUuid, int $lifetimeSeconds, array $extraClaims = []): string
    {
        // ENTERPRISE: Validate lifetime (reasonable bounds)
        if ($lifetimeSeconds < 60) {
            throw new \InvalidArgumentException('Token lifetime too short (min 60 seconds)');
        }
        if ($lifetimeSeconds > 2592000) { // 30 days
            Logger::websocket('warning', 'Very long token lifetime requested', [
                'lifetime_days' => $lifetimeSeconds / 86400
            ]);
        }

        // ENTERPRISE: Build payload with custom lifetime
        $issuedAt = time();
        $expiresAt = $issuedAt + $lifetimeSeconds;

        $payload = array_merge([
            'uuid' => $userUuid,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], $extraClaims);

        $secret = self::getSecret();
        $token = JWT::encode($payload, $secret, self::ALGORITHM);

        Logger::websocket('info', 'Custom lifetime token generated', [
            'uuid' => $userUuid,
            'lifetime_seconds' => $lifetimeSeconds,
            'lifetime_hours' => $lifetimeSeconds / 3600,
            'exp' => $expiresAt
        ]);

        return $token;
    }

    /**
     * Decode token WITHOUT verification (for debugging only!)
     *
     * WARNING: Does NOT verify signature or expiration!
     * Use ONLY for debugging/logging, never for authentication!
     *
     * @param string $token JWT token
     * @return array|null Decoded payload or null if malformed
     */
    public static function decodeWithoutVerification(string $token): ?array
    {
        try {
            // Split token into parts (header.payload.signature)
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            // Decode payload (base64url decode)
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            return $payload;

        } catch (\Exception $e) {
            Logger::websocket('warning', 'Failed to decode token without verification', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if token is expired (without full verification)
     *
     * USAGE: Quick expiration check before expensive operations
     *
     * @param string $token JWT token
     * @return bool True if expired, false otherwise
     */
    public static function isExpired(string $token): bool
    {
        $payload = self::decodeWithoutVerification($token);

        if (!$payload || !isset($payload['exp'])) {
            return true; // Invalid token = treat as expired
        }

        return time() >= $payload['exp'];
    }
}
