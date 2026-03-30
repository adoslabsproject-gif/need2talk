<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Core\EnterpriseSecurityFunctions;
use Need2Talk\Services\CookieConsentService;
use Need2Talk\Services\Logger;

/**
 * Cookie Consent API Controller
 *
 * Gestisce le richieste API per il sistema di consenso cookie
 * con focus su tracking emozioni e conformità GDPR
 */
class CookieConsentController
{
    /**
     * Ottieni configurazione categorie e servizi
     */
    public function getConfig()
    {
        header('Content-Type: application/json');

        try {
            // ENTERPRISE GALAXY: Session ALWAYS active (industry standard)
            // Bootstrap always starts session for ALL requests (no conditional logic)
            // GDPR: Session cookies are "strictly necessary" (no consent required)
            // No need to call ensureSessionStarted() - session already active!
            // Session ID is consistent across all pages (no race conditions)

            $userId = EnterpriseGlobalsManager::getSession('user_id');
            $sessionId = session_id();

            $config = CookieConsentService::generateJavaScriptConfig($userId, $sessionId);

            // Log richiesta configurazione per analytics
            CookieConsentService::logBannerDisplay(
                $userId,
                $sessionId,
                EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
                EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '')
            );

            // Enterprise: Set security headers
            EnterpriseSecurityFunctions::setSecurityHeaders();

            // Enterprise: Clean output and prevent any additional output - DISABLED FOR ZLIB FIX
            // EnterpriseSecurityFunctions::cleanOutputBuffer(); // Causes zlib compression errors
            echo EnterpriseSecurityFunctions::jsonEncode($config);

            // Enterprise: Ensure clean shutdown without additional output
            EnterpriseSecurityFunctions::finishRequest();
            exit();

        } catch (\Exception $e) {
            error_log('Cookie config API error: ' . $e->getMessage());
            http_response_code(500);

            // Enterprise: Clean output for error response too - DISABLED FOR ZLIB FIX
            // \Need2Talk\Core\EnterpriseSecurityFunctions::cleanOutputBuffer(); // Causes zlib compression errors
            echo json_encode([
                'success' => false,
                'error' => 'Errore nel caricamento configurazione cookie',
                'debug' => $e->getMessage(), // Debug info
            ]);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                ob_end_flush();
                flush();
            }
            exit();
        }
    }

    /**
     * Salva consenso utente
     */
    public function saveConsent()
    {
        header('Content-Type: application/json');

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);

            return;
        }

        // GDPR COMPLIANCE: CSRF exemption for cookie consent save
        // Cookie consent must work for first-time visitors (no session required)
        // Security: IP address + user agent tracking provides audit trail
        // See: CsrfMiddleware.php - '/api/cookie-consent/*' exempt routes

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                throw new \Exception('Dati non validi');
            }

            $consentType = $input['consent_type'] ?? '';
            $servicePreferences = $input['service_preferences'] ?? [];
            $bannerLogId = $input['banner_log_id'] ?? null;

            // Validazione input
            if (!in_array($consentType, ['accepted_all', 'declined_all', 'custom'], true)) {
                throw new \Exception('Tipo consenso non valido');
            }

            if (!is_array($servicePreferences)) {
                throw new \Exception('Preferenze servizi non valide');
            }

            // ENTERPRISE GALAXY: Session ALWAYS active (industry standard)
            // Bootstrap always starts session for ALL requests (no conditional logic)
            // GDPR: Session cookies are "strictly necessary" (no consent required)
            // No need to call ensureSessionStarted() - session already active!
            // Session ID is consistent across all pages (no race conditions)

            $userId = $_SESSION['user_id'] ?? null;
            $sessionId = session_id();
            $ipAddress = EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown');
            $userAgent = EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '');

            // Salva consenso
            // ENTERPRISE GALAXY (2025-11-11): saveUserConsent now returns array with consent_id and consent_uuid
            $consentResult = CookieConsentService::saveUserConsent(
                $userId,
                $sessionId,
                $consentType,
                $servicePreferences,
                $ipAddress,
                $userAgent
            );

            $consentId = $consentResult['consent_id'];
            $consentUuid = $consentResult['consent_uuid'];

            // ENTERPRISE GALAXY (2025-11-11): SET N2T_CONSENT cookie with UUID
            // GDPR-compliant "strictly necessary" cookie for consent persistence across session changes
            // Cookie duration: 365 days (same as consent validity)
            // ENTERPRISE SECURITY: __Host- prefix for consent cookie (RFC 6265bis)
            $cookieOptions = [
                'expires' => time() + (365 * 24 * 3600),  // 1 year
                'path' => '/',
                // NO 'domain' - required for __Host- prefix (host-only binding)
                'secure' => true,                           // __Host- requires HTTPS
                'httponly' => true,                         // No JavaScript access (XSS protection)
                'samesite' => 'Lax',                        // CSRF protection
            ];

            setcookie('__Host-N2T_CONSENT', $consentUuid, $cookieOptions);

            // Aggiorna log banner se fornito
            if ($bannerLogId) {
                CookieConsentService::updateBannerResponse($bannerLogId, $consentType);
            }

            // Genera response con configurazione aggiornata
            $updatedConfig = CookieConsentService::generateJavaScriptConfig($userId, $sessionId);

            // ENTERPRISE SECURITY LOG: GDPR consent saved
            Logger::security('info', 'GDPR: Cookie consent saved', [
                'consent_id' => $consentId,
                'consent_uuid' => $consentUuid,            // Log UUID for traceability
                'consent_type' => $consentType,
                'user_id' => $userId,
                'session_id' => substr($sessionId, 0, 8) . '***',
                'service_count' => count($servicePreferences),
                'ip' => $ipAddress,
                'n2t_consent_cookie_set' => true,          // Confirm cookie was set
            ]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'consent_id' => $consentId,
                    'consent_uuid' => $consentUuid,         // Return UUID to frontend
                    'config' => $updatedConfig,
                    'message' => $this->getConsentMessage($consentType),
                ],
            ]);

        } catch (\Exception $e) {
            error_log('Cookie consent save API error: ' . $e->getMessage());

            // ENTERPRISE SECURITY LOG: GDPR consent save failed
            Logger::security('warning', 'GDPR: Cookie consent save failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null,
                'session_id' => substr(session_id(), 0, 8) . '***',
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ritira consenso utente
     */
    public function withdrawConsent()
    {
        header('Content-Type: application/json');

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);

            return;
        }

        // GDPR COMPLIANCE: CSRF exemption for cookie consent withdrawal
        // Cookie consent withdrawal must be low-friction per GDPR requirements
        // Security: IP address + user agent tracking provides audit trail
        // See: CsrfMiddleware.php - '/api/cookie-consent/*' exempt routes

        try {
            $userId = $_SESSION['user_id'] ?? null;
            $sessionId = session_id();
            $ipAddress = EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown');
            $userAgent = EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '');

            $success = CookieConsentService::withdrawConsent(
                $userId,
                $sessionId,
                $ipAddress,
                $userAgent
            );

            if ($success) {
                // ENTERPRISE SECURITY LOG: GDPR consent withdrawn
                Logger::security('warning', 'GDPR: Cookie consent withdrawn', [
                    'user_id' => $userId,
                    'session_id' => substr($sessionId, 0, 8) . '***',
                    'ip' => $ipAddress,
                    'user_agent' => substr($userAgent, 0, 100),
                ]);

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'message' => 'Consenso ritirato con successo',
                    ],
                ]);
            } else {
                throw new \Exception('Errore nel ritiro del consenso');
            }

        } catch (\Exception $e) {
            error_log('Cookie consent withdrawal API error: ' . $e->getMessage());

            // ENTERPRISE SECURITY LOG: GDPR consent withdrawal failed
            Logger::security('critical', 'GDPR: Cookie consent withdrawal failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null,
                'session_id' => substr(session_id(), 0, 8) . '***',
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Errore nel ritiro del consenso',
            ]);
        }
    }

    /**
     * Log visualizzazione banner cookie
     */
    public function logBannerDisplay()
    {
        header('Content-Type: application/json');

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);

            return;
        }

        try {
            $userId = $_SESSION['user_id'] ?? null;
            $sessionId = session_id();
            $ipAddress = EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown');
            $userAgent = EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '');

            $logId = CookieConsentService::logBannerDisplay(
                $userId,
                $sessionId,
                $ipAddress,
                $userAgent
            );

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Banner display logged',
                    'display_log_id' => $logId,  // ENTERPRISE FIX: Return as display_log_id (frontend expects this key)
                    'timestamp' => date('c'),
                ],
            ]);

        } catch (\Exception $e) {
            error_log('Cookie banner display log API error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Errore nel logging banner display',
            ]);
        }
    }

    /**
     * Aggiorna risposta banner cookie
     *
     * ENTERPRISE GALAXY UX (2025-01-23): Fallback endpoint for banner response tracking
     * Handles race condition where displayLogId is NULL when updateBannerResponse() called
     * Finds latest display log by session_id and updates response
     */
    public function updateBannerResponse()
    {
        header('Content-Type: application/json');

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);

            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                throw new \Exception('Dati non validi');
            }

            $displayLogId = $input['display_log_id'] ?? null;
            $responseType = $input['response_type'] ?? '';

            // Validazione response_type
            if (!in_array($responseType, ['accepted_all', 'declined_all', 'custom_settings'], true)) {
                throw new \Exception('Tipo risposta non valido');
            }

            // ENTERPRISE GALAXY FIX (2025-01-23): If displayLogId is NULL, find by session_id
            if (!$displayLogId) {
                $sessionId = session_id();

                // Find latest display log for this session without a response
                $db = db_pdo();
                $stmt = $db->prepare('
                    SELECT id FROM cookie_banner_display_log
                    WHERE session_id = ? AND response_type IS NULL
                    ORDER BY display_timestamp DESC LIMIT 1
                ');
                $stmt->execute([$sessionId]);
                $result = $stmt->fetch();

                if ($result) {
                    $displayLogId = $result['id'];
                } else {
                    throw new \Exception('No banner display log found for session');
                }
            }

            // Update banner response
            CookieConsentService::updateBannerResponse($displayLogId, $responseType);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Banner response updated',
                    'display_log_id' => $displayLogId,
                    'response_type' => $responseType,
                ],
            ]);

        } catch (\Exception $e) {
            error_log('Cookie banner response update API error: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica consenso per servizio specifico
     */
    public function checkServiceConsent()
    {
        header('Content-Type: application/json');

        $serviceKey = $_GET['service'] ?? '';

        if (empty($serviceKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Service key richiesto']);

            return;
        }

        try {
            $userId = $_SESSION['user_id'] ?? null;
            $sessionId = session_id();

            $hasConsent = CookieConsentService::hasServiceConsent($serviceKey, $userId, $sessionId);
            $isEmotionalTracking = CookieConsentService::isEmotionalTrackingService($serviceKey);

            echo json_encode([
                'success' => true,
                'data' => [
                    'service_key' => $serviceKey,
                    'has_consent' => $hasConsent,
                    'is_emotional_tracking' => $isEmotionalTracking,
                    'timestamp' => time(),
                ],
            ]);

        } catch (\Exception $e) {
            error_log('Service consent check API error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Errore nella verifica consenso',
            ]);
        }
    }

    /**
     * Salva evento tracking emozioni
     */
    public function trackEmotionEvent()
    {
        header('Content-Type: application/json');

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);

            return;
        }

        // Verifica consenso per emotion tracking
        $userId = $_SESSION['user_id'] ?? null;
        $sessionId = session_id();

        if (!CookieConsentService::hasServiceConsent('emotion_registration', $userId, $sessionId)
            && !CookieConsentService::hasServiceConsent('emotion_listening', $userId, $sessionId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Consenso emotion tracking richiesto']);

            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                throw new \Exception('Dati non validi');
            }

            $eventType = $input['event_type'] ?? '';
            $emotionData = $input['emotion_data'] ?? [];

            // Validazione
            if (!in_array($eventType, ['registration', 'listening'], true)) {
                throw new \Exception('Tipo evento non valido');
            }

            // Salva evento emotion tracking (implementazione futura)
            // Per ora logghiamo per sviluppo
            error_log('Emotion tracking event: ' . json_encode([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'event_type' => $eventType,
                'emotion_data' => $emotionData,
                'timestamp' => date('Y-m-d H:i:s'),
            ]));

            // ENTERPRISE SECURITY LOG: Emotion tracking event (privacy-sensitive)
            Logger::security('info', 'PRIVACY: Emotion tracking event', [
                'event_type' => $eventType,
                'user_id' => $userId,
                'session_id' => substr($sessionId, 0, 8) . '***',
                'has_consent' => true,
                'emotion_keys' => array_keys($emotionData),
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'event_id' => uniqid('emotion_'),
                    'message' => 'Evento emotion tracking salvato',
                ],
            ]);

        } catch (\Exception $e) {
            error_log('Emotion tracking API error: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ottieni statistiche consensi (admin)
     */
    public function getStatistics()
    {
        header('Content-Type: application/json');

        // Solo per admin o utenti autorizzati
        if (!isset($_SESSION['user_id']) || !$this->isAuthorizedUser()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Non autorizzato']);

            return;
        }

        try {
            $stats = CookieConsentService::getConsentStatistics();

            // ENTERPRISE SECURITY LOG: GDPR statistics accessed
            Logger::security('info', 'GDPR: Consent statistics accessed', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'stats_count' => count($stats),
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            echo json_encode([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            error_log('Cookie statistics API error: ' . $e->getMessage());

            // ENTERPRISE SECURITY LOG: GDPR statistics access failed
            Logger::security('warning', 'GDPR: Consent statistics access failed', [
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user_id'] ?? null,
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Errore nel caricamento statistiche',
            ]);
        }
    }

    /**
     * Messaggio di consenso localizzato
     */
    private function getConsentMessage(string $consentType): string
    {
        $messages = [
            'accepted_all' => 'Hai accettato tutti i cookie. Grazie!',
            'declined_all' => 'Hai declinato i cookie non essenziali. Solo i cookie necessari sono attivi.',
            'custom' => 'Le tue preferenze personalizzate sono state salvate.',
        ];

        return $messages[$consentType] ?? 'Preferenze salvate';
    }

    /**
     * Verifica se utente è autorizzato per statistiche
     */
    private function isAuthorizedUser(): bool
    {
        // Implementazione futura: controllo ruoli utente
        return true; // Per ora tutti gli utenti autenticati
    }
}
