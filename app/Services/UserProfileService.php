<?php

namespace Need2Talk\Services;

/**
 * User Profile Service - need2talk
 *
 * Gestione sicura dei profili utente con UUID criptati
 * Per prevenire enumeration attacks e garantire privacy
 */
class UserProfileService
{
    private const ENCRYPTION_KEY = 'n2t_profile_encryption_key_2024'; // Cambiare in produzione
    private const CIPHER_METHOD = 'AES-256-CBC';

    /**
     * Genera un hash criptato dell'UUID per URL pubblici
     * Es: /profile/abc123def456 invece di /profile/550e8400-e29b-41d4-a716-446655440000
     */
    public static function encryptUuidForUrl(string $uuid): string
    {
        // Rimuove i trattini dall'UUID per rendering più pulito
        $cleanUuid = str_replace('-', '', $uuid);

        // Genera IV random per ogni criptazione
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER_METHOD));

        // Cripta l'UUID
        $encrypted = openssl_encrypt($cleanUuid, self::CIPHER_METHOD, self::ENCRYPTION_KEY, 0, $iv);

        // Combina IV + encrypted data e codifica in base64 URL-safe
        $combined = base64_encode($iv . $encrypted);
        $urlSafe = strtr($combined, '+/', '-_');

        // Rimuove padding per URL più puliti
        return rtrim($urlSafe, '=');
    }

    /**
     * Decripta un hash URL per ottenere l'UUID originale
     */
    public static function decryptUuidFromUrl(string $encryptedHash): ?string
    {
        try {
            // Ripristina base64 standard e padding
            $base64 = strtr($encryptedHash, '-_', '+/');
            $padLength = 4 - (strlen($base64) % 4);

            if ($padLength < 4) {
                $base64 .= str_repeat('=', $padLength);
            }

            $combined = base64_decode($base64, true);

            if ($combined === false) {
                return null;
            }

            // Estrae IV e dati criptati
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = substr($combined, 0, $ivLength);
            $encrypted = substr($combined, $ivLength);

            // Decripta
            $decrypted = openssl_decrypt($encrypted, self::CIPHER_METHOD, self::ENCRYPTION_KEY, 0, $iv);

            if ($decrypted === false) {
                return null;
            }

            // Ripristina formato UUID standard
            $uuid = substr($decrypted, 0, 8) . '-' .
                    substr($decrypted, 8, 4) . '-' .
                    substr($decrypted, 12, 4) . '-' .
                    substr($decrypted, 16, 4) . '-' .
                    substr($decrypted, 20, 12);

            // Valida formato UUID
            if (!self::isValidUuid($uuid)) {
                return null;
            }

            return $uuid;
        } catch (\Exception $e) {
            error_log('UUID decryption error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Genera URL profilo sicuro per un utente
     */
    public static function generateProfileUrl(string $uuid): string
    {
        $encryptedHash = self::encryptUuidForUrl($uuid);

        return url("profile/{$encryptedHash}");
    }

    /**
     * Ottiene dati utente da hash URL criptato
     */
    public static function getUserByUrlHash(string $hash): ?array
    {
        $uuid = self::decryptUuidFromUrl($hash);

        if (!$uuid) {
            return null;
        }

        try {
            $stmt = db_pdo()->prepare(
                "SELECT id, uuid, nickname, avatar, bio, created_at,
                        is_anonymous, show_age, show_location, show_gender,
                        birth_year, birth_month, location_country, location_city,
                        total_uploads, total_listens, total_likes_received
                 FROM users
                 WHERE uuid = ? AND deleted_at IS NULL AND status = 'active'"
            );
            $stmt->execute([$uuid]);

            return $stmt->fetch() ?: null;
        } catch (\Exception $e) {
            error_log('Profile lookup error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Verifica se un hash URL è valido senza rivelare l'UUID
     * Utile per rate limiting e security checks
     */
    public static function isValidProfileHash(string $hash): bool
    {
        return self::decryptUuidFromUrl($hash) !== null;
    }

    /**
     * Genera hash alternativo per avatar/media files
     * Diverso da quello per URL profili per maggiore security
     */
    public static function generateMediaHash(string $uuid): string
    {
        $salt = 'n2t_media_salt_2024';

        return hash('sha256', $uuid . $salt);
    }

    /**
     * Valida formato UUID standard
     */
    private static function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
