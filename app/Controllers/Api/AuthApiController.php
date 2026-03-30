<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\JWTService;

/**
 *   Quando implementare AuthApiController?

   * Solo se in futuro vuoi:
   * 1. App mobile iOS/Android per need2talk
   * 2. Frontend separato (React/Vue app standalone)
   * 3. Permettere ad altri siti di usare l'autenticazione need2talk (OAuth-like)
 * Authentication API Controller
 *
 * Gestisce le operazioni API per autenticazione
 */
class AuthApiController extends BaseController
{
    /**
     * Login utente
     */
    public function login(): void
    {
        // TODO: Implementare login API
        $this->json([
            'success' => false,
            'error' => 'API login not yet implemented',
        ], 501);
    }

    /**
     * Registrazione utente
     */
    public function register(): void
    {
        // TODO: Implementare registrazione API
        $this->json([
            'success' => false,
            'error' => 'API registration not yet implemented',
        ], 501);
    }

    /**
     * Logout utente
     */
    public function logout(): void
    {
        // TODO: Implementare logout API
        $this->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Rinnova token di accesso
     */
    public function refresh(): void
    {
        // TODO: Implementare refresh token
        $this->json([
            'success' => false,
            'error' => 'Token refresh not yet implemented',
        ], 501);
    }

    /**
     * Verifica stato autenticazione
     *
     * ENTERPRISE SECURITY: Only expose safe public user fields
     * NEVER expose: password_hash, email, IPs, tokens, internal IDs
     */
    public function status(): void
    {
        $isAuthenticated = isset($_SESSION['user_id']);

        $safeUserData = null;
        if ($isAuthenticated) {
            $user = current_user();
            if ($user) {
                // ENTERPRISE SECURITY: Sanitize user data
                $safeUserData = [
                    'uuid' => $user['uuid'],
                    'nickname' => $user['nickname'],
                    'avatar_url' => get_avatar_url($user['avatar_url'] ?? null),
                    'status' => $user['status'] ?? null,
                ];
            }
        }

        $this->json([
            'success' => true,
            'authenticated' => $isAuthenticated,
            'user' => $safeUserData,
        ]);
    }

    /**
     * ENTERPRISE V11.9: Check authentication status for PWA back-button security
     *
     * This lightweight endpoint is called by pageshow event listener
     * when user navigates back to a cached page after logout.
     * Returns 200 if authenticated, 401 if not.
     *
     * SECURITY NOTE:
     * - Uses SecureSessionManager for proper Redis-based session check
     * - No heavy database queries - just session validation
     * - Prevents showing cached authenticated content after logout
     */
    public function checkAuth(): void
    {
        // Fast session check using SecureSessionManager
        $isAuthenticated = \Need2Talk\Services\SecureSessionManager::isAuthenticated();

        if ($isAuthenticated) {
            $this->json([
                'success' => true,
                'authenticated' => true,
            ]);
        } else {
            $this->json([
                'success' => false,
                'authenticated' => false,
                'error' => 'Not authenticated',
            ], 401);
        }
    }

    /**
     * Generate WebSocket JWT token for authenticated user (ENTERPRISE GALAXY)
     *
     * ARCHITECTURE:
     * - Requires user authentication (auth middleware)
     * - Generates JWT token with 24-hour lifetime
     * - Token used for WebSocket connection authentication
     * - Client stores token in localStorage/memory
     *
     * RESPONSE:
     * {
     *   "success": true,
     *   "token": "eyJhbGciOiJIUzI1NiIsInR5cCI...",
     *   "expires_at": 1234567890  // Unix timestamp
     * }
     *
     * USAGE (Frontend):
     * ```javascript
     * const response = await fetch('/api/auth/websocket-token');
     * const {token} = await response.json();
     * localStorage.setItem('ws_token', token);
     * ```
     *
     * @return void JSON response with JWT token
     */
    public function getWebSocketToken(): void
    {
        // ENTERPRISE: Require authentication (enforced by 'auth' middleware)
        $user = current_user();
        $userUuid = $user['uuid'] ?? null;

        if (!$userUuid) {
            $this->json([
                'success' => false,
                'error' => 'User not authenticated',
            ], 401);
            return;
        }

        try {
            // ENTERPRISE V9.4: Include nickname and avatar_url in JWT for WebSocket chat
            // This avoids database/Redis lookup on WebSocket connection
            // avatar_url is used by MessageList.js to render sender avatars in real-time
            // ENTERPRISE V9.5: Use get_avatar_url() to convert relative path to full URL
            $token = JWTService::generate($userUuid, [
                'nickname' => $user['nickname'] ?? 'Anonymous',
                'avatar_url' => get_avatar_url($user['avatar_url'] ?? null),
            ]);

            // Calculate expiration timestamp (24 hours from now)
            $expiresAt = time() + 86400; // 24 hours

            $this->json([
                'success' => true,
                'token' => $token,
                'expires_at' => $expiresAt,
                'expires_in' => 86400, // seconds
            ]);

        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::error('Failed to generate WebSocket token', [
                'error' => $e->getMessage(),
                'user_uuid' => $userUuid,
            ]);

            $this->json([
                'success' => false,
                'error' => 'Failed to generate WebSocket token',
            ], 500);
        }
    }
}
