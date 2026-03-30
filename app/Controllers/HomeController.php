<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * HomeController - Pagine pubbliche need2talk (pre-login)
 *
 * ULTRA-ENTERPRISE PERFORMANCE:
 * - ZERO query al database (nessun model caricato)
 * - ZERO overhead (solo pagine statiche)
 * - Homepage ultra-veloce (entry point del sito)
 *
 * Gestisce SOLO pagine pubbliche:
 * - Homepage (redirect se autenticato)
 * - Pagine legali (privacy, terms, contacts, report)
 * - Pagine help (faq, guide, safety)
 * - About us
 */
class HomeController extends BaseController
{
    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
        // ULTRA-ENTERPRISE: ZERO dependencies - ultra-fast initialization
        // No models, no Redis, no database - ZERO overhead on public pages
        $this->logger = new Logger();
    }

    /**
     * Homepage pubblica need2talk - redirect se autenticato
     *
     * ULTRA-ENTERPRISE: ZERO database queries, ZERO overhead
     * Fastest possible entry point for new users
     *
     * PERFORMANCE GALAXY: Early session_write_close() to release Redis lock
     * This prevents session lock contention under high concurrency (1000+ req/sec)
     */
    public function index(): void
    {
        // ENTERPRISE GALAXY (2025-11-11): Check authentication BEFORE closing session
        // CRITICAL: Must check $_SESSION BEFORE session_write_close()!
        // After session_write_close(), $_SESSION is no longer accessible
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Check if user is logged in
            // ENTERPRISE FIX: Can't use isset() on function return value (PHP error)
            $currentUser = current_user();
            if (isset($currentUser) && !empty($currentUser['id'])) {
                // User is logged in → redirect to feed (2025-12-20: changed from profile)
                // ENTERPRISE FIX (2025-11-12): Changed from /dashboard to /profile
                // 2025-12-20: Changed from /profile to /feed for better UX
                // This prevents logged-in users from seeing public homepage via back button
                $this->redirect(url('/feed'));

                return;
            }

            // User is NOT logged in → close session to release Redis lock
            // This eliminates Redis session locking overhead for anonymous users
            session_write_close();
        }

        // ULTRA-ENTERPRISE: Landing page with ZERO queries
        // No emotions, no audio, no stats - ultra-fast first impression
        $this->view('pages.home');
    }

    // =================================================================
    // PAGINE STATICHE (ZERO QUERY, ULTRA-FAST)
    // =================================================================

    /**
     * About Us page.
     */
    public function about(): void
    {
        $this->view('pages.about', [
            'title' => 'Chi Siamo - need2talk',
        ]);
    }

    /**
     * Privacy Policy page.
     */
    public function privacy(): void
    {
        // ENTERPRISE SECURITY LOG: Privacy page accessed (info level - legal compliance)
        Logger::security('info', 'LEGAL: Privacy policy accessed', [
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $this->view('legal.privacy', [
            'title' => 'Privacy Policy - need2talk',
        ]);
    }

    /**
     * Terms of Service page.
     */
    public function terms(): void
    {
        // ENTERPRISE SECURITY LOG: Terms page accessed (info level - legal compliance)
        Logger::security('info', 'LEGAL: Terms of service accessed', [
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $this->view('legal.terms', [
            'title' => 'Termini di Servizio - need2talk',
        ]);
    }

    /**
     * Contacts page.
     */
    public function contacts(): void
    {
        $this->view('legal.contacts', [
            'title' => 'Contatti - need2talk',
        ]);
    }

    /**
     * Report Issues page.
     */
    public function report(): void
    {
        $this->view('pages.legal.report', [
            'title' => 'Segnala Problema - need2talk',
        ]);
    }

    /**
     * FAQ page.
     */
    public function faq(): void
    {
        $this->view('help.faq', [
            'title' => 'Domande Frequenti - need2talk',
        ]);
    }

    /**
     * User Guide page.
     */
    public function guide(): void
    {
        $this->view('help.guide', [
            'title' => 'Guida all\'uso - need2talk',
        ]);
    }

    /**
     * Safety Guidelines page.
     */
    public function safety(): void
    {
        // ENTERPRISE SECURITY LOG: Safety page accessed (info level - user safety tracking)
        Logger::security('info', 'HELP: Safety guidelines accessed', [
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
        ]);

        $this->view('help.safety', [
            'title' => 'Sicurezza - need2talk',
        ]);
    }

    /**
     * PWA Offline fallback page
     */
    public function offline(): void
    {
        $this->view('pages.offline', [
            'title' => 'Sei Offline - need2talk',
        ]);
    }
}
