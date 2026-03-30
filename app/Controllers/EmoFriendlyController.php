<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;

/**
 * EmoFriendlyController - Enterprise Galaxy
 *
 * Controller per la pagina Anime Affini (EmoFriendly).
 * Mostra suggerimenti di amicizia basati sulla compatibilità emotiva.
 *
 * @package Need2Talk\Controllers
 */
class EmoFriendlyController extends BaseController
{
    /**
     * Pagina principale EmoFriendly
     *
     * GET /emofriendly
     */
    public function index(): void
    {
        $user = $this->requireAuth();

        $this->view('emofriendly.index', [
            'user' => $user,
            'title' => 'Anime Affini - need2talk',
            'description' => 'Scopri persone con cui connetterti emotivamente',
            'pageCSS' => ['pages/emofriendly'],
            'pageJS' => ['pages/emofriendly'],
            'hideFloatingRecorder' => true,
        ], 'app-post-login');
    }
}
