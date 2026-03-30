<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\ContentValidator;

/**
 * ValidationController - API per validazione contenuti
 *
 * Endpoint per validazione in tempo reale via JavaScript
 */
class ValidationController extends BaseController
{
    /**
     * Validazione generica contenuti
     * POST /api/validate
     */
    public function validate(): void
    {
        // Permetti anche richieste non autenticate per validazione base
        $content = $this->getInput('content', '');
        $type = $this->getInput('type', '');

        if (empty($content) || empty($type)) {
            $this->json([
                'valid' => false,
                'error' => 'Content e type sono obbligatori',
            ], 400);
        }

        $result = ContentValidator::validateForApi($content, $type);

        // Non esporre la lista delle parole trovate per sicurezza
        if (isset($result['found_words'])) {
            unset($result['found_words']);
        }

        $this->json($result);
    }

    /**
     * Validazione nickname in tempo reale
     * POST /api/validate/nickname
     */
    public function validateNickname(): void
    {
        $nickname = $this->getInput('nickname', '');

        if (empty($nickname)) {
            $this->json([
                'valid' => false,
                'available' => false,
                'errors' => ['Nickname obbligatorio'],
            ]);
        }

        // ENTERPRISE: Redis cache per performance
        $redis = EnterpriseRedisManager::getInstance();
        $cacheKey = 'nickname_validation:' . sha1(strtolower($nickname));

        // Controlla cache prima del database
        if ($redis->isAvailable()) {
            $cached = $redis->get($cacheKey);

            if ($cached !== false) {
                $result = json_decode($cached, true);
                $result['cached'] = true;
                $this->json($result);

                return;
            }
        }

        $validator = new ContentValidator();
        $result = $validator->validateNickname($nickname);

        // Controlla disponibilità nel database se la validazione passa
        if ($result['valid']) {
            $stmt = db_pdo()->prepare('SELECT id FROM users WHERE nickname = ? AND deleted_at IS NULL');
            $stmt->execute([$nickname]);
            $exists = $stmt->fetch();

            $result['available'] = !$exists;

            if ($exists) {
                $result['errors'][] = 'Questo nickname è già in uso';
                $result['valid'] = false;
            }
        } else {
            $result['available'] = false;
        }

        $result['cached'] = false;

        // ENTERPRISE: Cache risultato per 5 minuti (più lungo per nickname)
        if ($redis->isAvailable()) {
            $redis->setex($cacheKey, 300, json_encode($result));
        }

        $this->json($result);
    }

    /**
     * Validazione email in tempo reale
     * POST /api/validate/email
     */
    public function validateEmail(): void
    {
        $email = $this->getInput('email', '');

        if (empty($email)) {
            $this->json([
                'valid' => false,
                'available' => false,
                'errors' => ['Email obbligatoria'],
            ]);
        }

        // Validazione formato email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json([
                'valid' => false,
                'available' => false,
                'errors' => ['Formato email non valido'],
            ]);
        }

        // ENTERPRISE: Redis cache per performance
        $redis = EnterpriseRedisManager::getInstance();
        $cacheKey = 'email_validation:' . sha1(strtolower($email));

        // Controlla cache prima del database
        if ($redis->isAvailable()) {
            $cached = $redis->get($cacheKey);

            if ($cached !== false) {
                $result = json_decode($cached, true);
                $result['cached'] = true;
                $this->json($result);

                return;
            }
        }

        // Controlla disponibilità nel database
        $stmt = db_pdo()->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL');
        $stmt->execute([$email]);
        $exists = $stmt->fetch();

        $available = !$exists;
        $valid = $available;
        $errors = [];

        if ($exists) {
            $errors[] = 'Questa email è già registrata';
        }

        $result = [
            'valid' => $valid,
            'available' => $available,
            'errors' => $errors,
            'cached' => false,
        ];

        // ENTERPRISE: Cache risultato per 5 minuti
        if ($redis->isAvailable()) {
            $redis->setex($cacheKey, 300, json_encode($result));
        }

        $this->json($result);
    }

    /**
     * Validazione descrizione audio
     * POST /api/validate/description
     */
    public function validateDescription(): void
    {
        // Richiede autenticazione per le descrizioni audio
        $this->requireAuth();

        $description = $this->getInput('description', '');

        $validator = new ContentValidator();
        $result = $validator->validateAudioDescription($description);

        // Aggiungi statistiche utili
        $result['stats'] = [
            'length' => strlen($result['cleaned']),
            'words' => str_word_count($result['cleaned']),
            'max_length' => 1000,
        ];

        $this->json($result);
    }

    /**
     * Validazione commento
     * POST /api/validate/comment
     */
    public function validateComment(): void
    {
        // Richiede autenticazione per i commenti
        $this->requireAuth();

        $comment = $this->getInput('comment', '');

        $validator = new ContentValidator();
        $result = $validator->validateComment($comment);

        // Aggiungi statistiche
        $result['stats'] = [
            'length' => strlen($result['cleaned']),
            'words' => str_word_count($result['cleaned']),
            'max_length' => 500,
        ];

        $this->json($result);
    }

    /**
     * Endpoint per controllo blacklist parole
     * POST /api/validate/check-words
     */
    public function checkWords(): void
    {
        // Solo per utenti autenticati
        $this->requireAuth();

        $text = $this->getInput('text', '');

        if (empty($text)) {
            $this->json([
                'clean' => true,
                'has_profanity' => false,
                'has_spam' => false,
            ]);
        }

        $validator = new ContentValidator();

        // Usa reflection per accedere ai metodi privati per questo endpoint specifico
        $reflection = new \ReflectionClass($validator);
        $profanityMethod = $reflection->getMethod('checkProfanity');
        $profanityMethod->setAccessible(true);
        $spamMethod = $reflection->getMethod('checkSpam');
        $spamMethod->setAccessible(true);

        $profanityResult = $profanityMethod->invoke($validator, $text);
        $spamResult = $spamMethod->invoke($validator, $text);

        $this->json([
            'clean' => $profanityResult['clean'] && $spamResult['clean'],
            'has_profanity' => !$profanityResult['clean'],
            'has_spam' => !$spamResult['clean'],
            'filtered_text' => $profanityResult['filtered'],
            'spam_reason' => $spamResult['reason'],
        ]);
    }
}
