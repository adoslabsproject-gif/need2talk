<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Models\Friendship;
use Need2Talk\Models\User;
use Need2Talk\Services\EmoFriendlyService;
use Need2Talk\Services\Logger;

/**
 * EmoFriendlyApiController - Enterprise Galaxy
 *
 * API endpoints per il sistema EmoFriendly (Anime Affini).
 *
 * ENDPOINTS:
 * - GET /api/emofriendly/suggestions - Ottiene suggerimenti affini/complementari
 * - POST /api/emofriendly/dismiss - Rimuove/blocca un suggerimento
 * - POST /api/emofriendly/friend-request - Invia richiesta amicizia
 *
 * @package Need2Talk\Controllers\Api
 */
class EmoFriendlyApiController extends BaseController
{
    private EmoFriendlyService $service;
    private Friendship $friendshipModel;
    private User $userModel;

    public function __construct()
    {
        parent::__construct();
        $this->service = new EmoFriendlyService();
        $this->friendshipModel = new Friendship();
        $this->userModel = new User();
    }

    /**
     * Ottiene i suggerimenti per l'utente corrente
     *
     * GET /api/emofriendly/suggestions
     *
     * @return void
     */
    public function getSuggestions(): void
    {
        $user = $this->requireAuth();

        try {
            $data = $this->service->getSuggestionsForUser((int)$user['id']);

            $this->jsonResponse([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            Logger::error('[EmoFriendly API] Failed to get suggestions', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => ['Errore nel caricamento dei suggerimenti'],
            ], 500);
        }
    }

    /**
     * Rimuove o blocca un suggerimento
     *
     * POST /api/emofriendly/dismiss
     * Body: { "user_uuid": "...", "type": "remove|block" }
     *
     * @return void
     */
    public function dismiss(): void
    {
        $user = $this->requireAuth();
        $input = $this->getJsonInput();

        $userUuid = $input['user_uuid'] ?? null;
        $type = $input['type'] ?? 'remove';

        if (!$userUuid) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['UUID utente richiesto'],
            ], 400);
            return;
        }

        // Trova l'utente da rimuovere
        $dismissedUser = $this->userModel->findByUuid($userUuid);

        if (!$dismissedUser) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Utente non trovato'],
            ], 404);
            return;
        }

        try {
            // Se è un block, blocca anche l'utente nel sistema principale
            if ($type === 'block') {
                $this->friendshipModel->blockUser((int)$user['id'], (int)$dismissedUser['id']);
            }

            // Registra il dismiss nel sistema EmoFriendly
            $this->service->dismissSuggestion(
                (int)$user['id'],
                (int)$dismissedUser['id'],
                $type
            );

            $this->jsonResponse([
                'success' => true,
                'message' => $type === 'block' ? 'Utente bloccato' : 'Suggerimento rimosso',
            ]);

        } catch (\Exception $e) {
            Logger::error('[EmoFriendly API] Failed to dismiss', [
                'user_id' => $user['id'],
                'dismissed_uuid' => $userUuid,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => ['Errore nell\'operazione'],
            ], 500);
        }
    }

    /**
     * Invia richiesta di amicizia
     *
     * POST /api/emofriendly/friend-request
     * Body: { "user_uuid": "..." }
     *
     * @return void
     */
    public function sendFriendRequest(): void
    {
        $user = $this->requireAuth();
        $input = $this->getJsonInput();

        $friendUuid = $input['user_uuid'] ?? null;

        if (!$friendUuid) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['UUID utente richiesto'],
            ], 400);
            return;
        }

        // Trova l'utente target
        $friend = $this->userModel->findByUuid($friendUuid);

        if (!$friend) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Utente non trovato'],
            ], 404);
            return;
        }

        // Verifica che non sia se stesso
        if ((int)$friend['id'] === (int)$user['id']) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Non puoi inviare una richiesta a te stesso'],
            ], 400);
            return;
        }

        try {
            // Verifica se già amici o richiesta pendente
            $existingStatus = $this->friendshipModel->getFriendshipStatus(
                (int)$user['id'],
                (int)$friend['id']
            );

            if ($existingStatus === 'friends') {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Siete già amici'],
                ], 400);
                return;
            }

            if ($existingStatus === 'pending_sent') {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Richiesta già inviata'],
                ], 400);
                return;
            }

            if ($existingStatus === 'pending_received') {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Hai già una richiesta pendente da questo utente'],
                ], 400);
                return;
            }

            if ($existingStatus === 'blocked') {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Non puoi inviare richieste a questo utente'],
                ], 400);
                return;
            }

            // Invia richiesta
            $friendshipId = $this->friendshipModel->sendRequest(
                (int)$user['id'],
                (int)$friend['id']
            );

            // Rimuovi dai suggerimenti (non serve più mostrarlo)
            $this->service->dismissSuggestion(
                (int)$user['id'],
                (int)$friend['id'],
                'remove'
            );

            Logger::info('[EmoFriendly API] Friend request sent', [
                'user_id' => $user['id'],
                'friend_id' => $friend['id'],
                'friendship_id' => $friendshipId,
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Richiesta inviata!',
                'data' => [
                    'friendship_id' => $friendshipId,
                ],
            ]);

        } catch (\Exception $e) {
            Logger::error('[EmoFriendly API] Failed to send friend request', [
                'user_id' => $user['id'],
                'friend_uuid' => $friendUuid,
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => [$e->getMessage() ?: 'Errore nell\'invio della richiesta'],
            ], 500);
        }
    }
}
