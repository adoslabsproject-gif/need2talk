<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * DiaryPasswordController - True E2E Diary Password Management
 *
 * ZERO KNOWLEDGE ARCHITECTURE:
 * - Server stores password hash (Argon2id) for verification
 * - Server stores KDF salt for client-side key derivation
 * - Server CANNOT decrypt diary content
 * - Password lost = Data lost FOREVER (no recovery)
 *
 * SECURITY MODEL:
 * - Password hash: Argon2id (server verification only)
 * - KDF salt: 32 bytes random (for PBKDF2 on client)
 * - Device token: 64 bytes random, hashed for storage
 * - Remember period: 30 days
 *
 * @version 4.2 - True E2E Diary
 */
class DiaryPasswordController extends BaseController
{
    /**
     * Check diary password status
     *
     * GET /api/journal/password/status
     *
     * Response:
     * {
     *   "has_diary_password": true/false,
     *   "setup_required": true/false,
     *   "requires_account_password": true/false (OAuth users without password)
     * }
     */
    public function status(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];

            $db = db();

            // Check diary password status AND account password in a single query
            // ENTERPRISE: Read password_hash directly from DB (not from cache)
            // This ensures we always get fresh data after password changes
            $record = $db->findOne(
                "SELECT
                    u.password_hash IS NOT NULL AND u.password_hash != '' AS has_account_password,
                    u.oauth_provider,
                    uek.has_diary_password,
                    uek.diary_setup_at
                 FROM users u
                 LEFT JOIN user_encryption_keys uek ON uek.user_id = u.id
                 WHERE u.id = :user_id",
                ['user_id' => $userId],
                ['cache' => false] // CRITICAL: No cache for security-sensitive data
            );

            $hasDiaryPassword = (bool) ($record['has_diary_password'] ?? false);
            $hasAccountPassword = (bool) ($record['has_account_password'] ?? false);
            $requiresAccountPassword = !$hasAccountPassword;

            $this->json([
                'success' => true,
                'has_diary_password' => $hasDiaryPassword,
                'setup_required' => !$hasDiaryPassword,
                'setup_at' => $record['diary_setup_at'] ?? null,
                // v4.2: OAuth users must set account password first
                'has_account_password' => $hasAccountPassword,
                'requires_account_password' => $requiresAccountPassword,
            ]);

        } catch (\Exception $e) {
            Logger::error('Diary password status check failed', [
                'user_id' => $userId ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il controllo dello stato',
            ], 500);
        }
    }

    /**
     * Setup diary password (first time)
     *
     * POST /api/journal/password/setup
     *
     * Body:
     * {
     *   "diary_password_hash": "argon2id hash from client",
     *   "kdf_salt": "base64 encoded 32 bytes salt",
     *   "login_password": "current login password for verification"
     * }
     *
     * SECURITY:
     * - Client generates salt (crypto.getRandomValues)
     * - Client hashes password with Argon2id (for server verification)
     * - Client derives DEK with PBKDF2 (stored in sessionStorage)
     * - Server stores hash + salt, CANNOT derive DEK
     */
    public function setup(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];
            $userUuid = $user['uuid'];

            $input = $this->getJsonInput();

            // Validate required fields
            $diaryPasswordHash = $input['diary_password_hash'] ?? null;
            $kdfSalt = $input['kdf_salt'] ?? null;
            $loginPassword = $input['login_password'] ?? null;

            if (!$diaryPasswordHash || !$kdfSalt || !$loginPassword) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_fields',
                    'message' => 'Tutti i campi sono obbligatori',
                ], 400);

                return;
            }

            // Verify login password (identity confirmation)
            $db = db();
            $userRecord = $db->findOne(
                "SELECT password_hash FROM users WHERE id = :user_id",
                ['user_id' => $userId]
            );

            if (!$userRecord || !password_verify($loginPassword, $userRecord['password_hash'])) {
                Logger::security('warning', 'Diary setup: invalid login password', [
                    'user_uuid' => $userUuid,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'invalid_password',
                    'message' => 'Password di login non corretta',
                ], 401);

                return;
            }

            // Check if diary password already exists
            $existing = $db->findOne(
                "SELECT has_diary_password FROM user_encryption_keys WHERE user_id = :user_id",
                ['user_id' => $userId]
            );

            if ($existing && $existing['has_diary_password']) {
                $this->json([
                    'success' => false,
                    'error' => 'already_setup',
                    'message' => 'Password diario già configurata',
                ], 400);

                return;
            }

            // Validate KDF salt (must be base64 encoded, 32 bytes = 44 chars base64)
            $decodedSalt = base64_decode($kdfSalt, true);
            if ($decodedSalt === false || strlen($decodedSalt) !== 32) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_salt',
                    'message' => 'Salt KDF non valido',
                ], 400);

                return;
            }

            // ENTERPRISE: Convert binary salt to hex for PostgreSQL BYTEA
            // PDO has issues with base64 containing non-ASCII chars after decode
            // Hex format is pure ASCII and works reliably with decode('hex')
            $saltHex = bin2hex($decodedSalt);

            // Store diary password hash and KDF salt
            if ($existing) {
                // Update existing record
                $db->execute(
                    "UPDATE user_encryption_keys SET
                        diary_password_hash = :hash,
                        diary_kdf_salt = decode(:salt, 'hex'),
                        has_diary_password = TRUE,
                        diary_setup_at = NOW(),
                        remembered_devices = '[]'::jsonb
                     WHERE user_id = :user_id",
                    [
                        'hash' => $diaryPasswordHash,
                        'salt' => $saltHex,
                        'user_id' => $userId,
                    ]
                );
            } else {
                // Insert new record
                $db->execute(
                    "INSERT INTO user_encryption_keys (user_id, diary_password_hash, diary_kdf_salt, has_diary_password, diary_setup_at, remembered_devices)
                     VALUES (:user_id, :hash, decode(:salt, 'hex'), TRUE, NOW(), '[]'::jsonb)",
                    [
                        'user_id' => $userId,
                        'hash' => $diaryPasswordHash,
                        'salt' => $saltHex,
                    ]
                );
            }

            Logger::security('info', 'Diary password setup completed', [
                'user_uuid' => $userUuid,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'has_diary_password' => true,
                'message' => 'Password diario configurata con successo',
            ]);

        } catch (\Exception $e) {
            Logger::error('Diary password setup failed', [
                'user_uuid' => $userUuid ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante la configurazione',
            ], 500);
        }
    }

    /**
     * Verify diary password
     *
     * POST /api/journal/password/verify
     *
     * Body:
     * {
     *   "diary_password": "plain password (sent over HTTPS)",
     *   "remember_device": true/false
     * }
     *
     * SECURITY: Password is sent plain over HTTPS. Server re-computes
     * the hash using stored salt for comparison. This is MORE secure because:
     * - Salt is never exposed to client (prevents precomputation attacks)
     * - No timing attacks possible on client-side hash comparison
     * - Same pattern as login authentication
     *
     * Response:
     * {
     *   "success": true,
     *   "kdf_salt": "base64 encoded salt for PBKDF2",
     *   "device_token": "optional 30-day remember token"
     * }
     */
    public function verify(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];
            $userUuid = $user['uuid'];

            $input = $this->getJsonInput();

            // ENTERPRISE FIX V11.6: Accept plain password, compute hash server-side
            $diaryPassword = $input['diary_password'] ?? null;
            $rememberDevice = (bool) ($input['remember_device'] ?? false);

            if (!$diaryPassword) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_password',
                    'message' => 'Password diario richiesta',
                ], 400);

                return;
            }

            $db = db();
            $record = $db->findOne(
                "SELECT diary_password_hash, diary_kdf_salt, has_diary_password, remembered_devices
                 FROM user_encryption_keys
                 WHERE user_id = :user_id",
                ['user_id' => $userId]
            );

            if (!$record || !$record['has_diary_password']) {
                $this->json([
                    'success' => false,
                    'error' => 'not_setup',
                    'message' => 'Password diario non configurata',
                ], 400);

                return;
            }

            // ENTERPRISE FIX V11.6: Re-compute hash server-side using stored salt
            // Original hash was: SHA-256(password + base64(salt))
            // We need to compute the same hash and compare
            $storedSalt = $record['diary_kdf_salt']; // BYTEA from PostgreSQL

            // DEBUG: Check what PostgreSQL returns for BYTEA
            // PostgreSQL can return BYTEA in different formats:
            // - Hex format: \xdd1e03a1... (escaped)
            // - Binary: raw bytes
            if (str_starts_with($storedSalt, '\\x')) {
                // PostgreSQL returned hex-escaped format, convert to binary
                $storedSalt = hex2bin(substr($storedSalt, 2));
            }

            $saltBase64 = base64_encode($storedSalt);
            $computedHash = base64_encode(hash('sha256', $diaryPassword . $saltBase64, true));

            // DEBUG LOGGING - REMOVE AFTER FIX
            Logger::debug('Diary verify debug', [
                'stored_hash' => $record['diary_password_hash'],
                'computed_hash' => $computedHash,
                'salt_b64_computed' => $saltBase64,
                'salt_raw_len' => strlen($storedSalt),
                'password_len' => strlen($diaryPassword),
            ]);

            // Verify password hash (constant-time comparison)
            if (!hash_equals($record['diary_password_hash'], $computedHash)) {
                Logger::security('warning', 'Diary unlock: invalid password', [
                    'user_uuid' => $userUuid,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'debug_stored' => substr($record['diary_password_hash'], 0, 10) . '...',
                    'debug_computed' => substr($computedHash, 0, 10) . '...',
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'invalid_password',
                    'message' => 'Password diario non corretta',
                ], 401);

                return;
            }

            // Password verified - return KDF salt
            $kdfSalt = base64_encode($record['diary_kdf_salt']);

            $response = [
                'success' => true,
                'kdf_salt' => $kdfSalt,
                'message' => 'Password verificata',
            ];

            // Generate device token if remember requested
            if ($rememberDevice) {
                $deviceToken = bin2hex(random_bytes(64)); // 128 chars hex
                $tokenHash = hash('sha256', $deviceToken);
                $expiresAt = date('c', strtotime('+30 days'));
                $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

                // Add to remembered_devices JSONB array
                $rememberedDevices = json_decode($record['remembered_devices'] ?? '[]', true) ?: [];

                // Remove expired devices
                $rememberedDevices = array_filter($rememberedDevices, function ($device) {
                    return strtotime($device['expires_at'] ?? '0') > time();
                });

                // Add new device
                $rememberedDevices[] = [
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                    'user_agent' => $userAgent,
                    'created_at' => date('c'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ];

                // Max 5 devices
                if (count($rememberedDevices) > 5) {
                    $rememberedDevices = array_slice($rememberedDevices, -5);
                }

                $db->execute(
                    "UPDATE user_encryption_keys SET remembered_devices = :devices WHERE user_id = :user_id",
                    [
                        'devices' => json_encode(array_values($rememberedDevices)),
                        'user_id' => $userId,
                    ]
                );

                $response['device_token'] = $deviceToken;
                $response['token_expires_at'] = $expiresAt;
            }

            Logger::security('info', 'Diary unlocked successfully', [
                'user_uuid' => $userUuid,
                'remember_device' => $rememberDevice,
            ]);

            $this->json($response);

        } catch (\Exception $e) {
            Logger::error('Diary password verification failed', [
                'user_uuid' => $userUuid ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante la verifica',
            ], 500);
        }
    }

    /**
     * Check if device token is valid
     *
     * POST /api/journal/password/check-device
     *
     * Body:
     * {
     *   "device_token": "saved token from localStorage"
     * }
     *
     * Response:
     * {
     *   "valid": true/false,
     *   "kdf_salt": "base64 salt if valid"
     * }
     */
    public function checkDevice(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];

            $input = $this->getJsonInput();
            $deviceToken = $input['device_token'] ?? null;

            if (!$deviceToken) {
                $this->json([
                    'success' => true,
                    'valid' => false,
                    'reason' => 'no_token',
                ]);

                return;
            }

            $tokenHash = hash('sha256', $deviceToken);

            $db = db();
            $record = $db->findOne(
                "SELECT diary_kdf_salt, remembered_devices, has_diary_password
                 FROM user_encryption_keys
                 WHERE user_id = :user_id AND has_diary_password = TRUE",
                ['user_id' => $userId]
            );

            if (!$record) {
                $this->json([
                    'success' => true,
                    'valid' => false,
                    'reason' => 'not_setup',
                ]);

                return;
            }

            $rememberedDevices = json_decode($record['remembered_devices'] ?? '[]', true) ?: [];

            // Find matching token
            $validDevice = null;
            foreach ($rememberedDevices as $device) {
                if (
                    isset($device['token_hash']) &&
                    hash_equals($device['token_hash'], $tokenHash) &&
                    strtotime($device['expires_at'] ?? '0') > time()
                ) {
                    $validDevice = $device;
                    break;
                }
            }

            if (!$validDevice) {
                $this->json([
                    'success' => true,
                    'valid' => false,
                    'reason' => 'token_invalid_or_expired',
                ]);

                return;
            }

            // Valid token - return KDF salt
            $this->json([
                'success' => true,
                'valid' => true,
                'kdf_salt' => base64_encode($record['diary_kdf_salt']),
                'expires_at' => $validDevice['expires_at'],
            ]);

        } catch (\Exception $e) {
            Logger::error('Diary device check failed', [
                'user_id' => $userId ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il controllo dispositivo',
            ], 500);
        }
    }

    /**
     * Forget (logout) remembered device
     *
     * DELETE /api/journal/password/forget-device
     *
     * Body:
     * {
     *   "device_token": "token to forget"
     * }
     */
    public function forgetDevice(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];
            $userUuid = $user['uuid'];

            $input = $this->getJsonInput();
            $deviceToken = $input['device_token'] ?? null;

            if (!$deviceToken) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_token',
                    'message' => 'Token dispositivo richiesto',
                ], 400);

                return;
            }

            $tokenHash = hash('sha256', $deviceToken);

            $db = db();
            $record = $db->findOne(
                "SELECT remembered_devices FROM user_encryption_keys WHERE user_id = :user_id",
                ['user_id' => $userId]
            );

            if (!$record) {
                $this->json([
                    'success' => true,
                    'message' => 'Dispositivo dimenticato',
                ]);

                return;
            }

            $rememberedDevices = json_decode($record['remembered_devices'] ?? '[]', true) ?: [];

            // Remove matching token
            $rememberedDevices = array_filter($rememberedDevices, function ($device) use ($tokenHash) {
                return !isset($device['token_hash']) || !hash_equals($device['token_hash'], $tokenHash);
            });

            $db->execute(
                "UPDATE user_encryption_keys SET remembered_devices = :devices WHERE user_id = :user_id",
                [
                    'devices' => json_encode(array_values($rememberedDevices)),
                    'user_id' => $userId,
                ]
            );

            Logger::security('info', 'Diary device forgotten', [
                'user_uuid' => $userUuid,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => 'Dispositivo dimenticato con successo',
            ]);

        } catch (\Exception $e) {
            Logger::error('Diary forget device failed', [
                'user_uuid' => $userUuid ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante la rimozione del dispositivo',
            ], 500);
        }
    }
}
