<?php
/**
 * NEED2TALK - EMOFRIENDLY / ANIME AFFINI PAGE (ENTERPRISE GALAXY)
 *
 * Suggerisce amicizie basate sulla compatibilità emotiva:
 * - Anime Affini: utenti con pattern emotivi simili
 * - Anime Complementari: utenti con pattern opposti (bilancianti)
 *
 * FEATURES:
 * - Due carousel orizzontali scorrevoli
 * - Card utente con nickname, avatar, % compatibilità
 * - Bottoni: Richiedi amicizia, Rimuovi, Blocca
 * - Optimistic UI updates
 * - Toast notifications
 *
 * CSS RULES:
 * - Tutti i selettori con prefisso .emofriendly
 * - BEM naming convention
 * - Nessun override di classi esistenti
 *
 * @version 1.0.0
 * @author Claude Code (AI-Orchestrated Development)
 */

// Hide FloatingRecorder
$hideFloatingRecorder = true;

// SECURITY: Access control
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Page-specific CSS and JS files
$pageCSS = ['pages/emofriendly'];
$pageJS = ['pages/emofriendly'];

// Current user
$currentUser = $user ?? null;
if (!$currentUser) {
    header('Location: ' . url('/login'));
    exit;
}
?>

<div class="emofriendly min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="emofriendly__container container mx-auto px-4 max-w-7xl pt-4">

        <!-- Header -->
        <div class="emofriendly__header mb-8 pt-8">
            <h1 class="emofriendly__title text-3xl md:text-4xl font-bold text-white mb-2">
                <span class="emofriendly__title-emoji">💜</span>
                Anime Affini
            </h1>
            <p class="emofriendly__subtitle text-gray-400">
                Scopri persone con cui potresti connetterti emotivamente
            </p>
        </div>

        <!-- Anime Affini Section -->
        <section class="emofriendly__section emofriendly__section--affine mb-12">
            <div class="emofriendly__section-header mb-4">
                <h2 class="emofriendly__section-title text-xl md:text-2xl font-bold text-white">
                    <span class="emofriendly__section-emoji">🪞</span>
                    Anime Affini
                </h2>
                <p class="emofriendly__section-subtitle text-gray-400 text-sm">
                    Utenti che esprimono emozioni simili alle tue
                </p>
            </div>

            <!-- Carousel Affine -->
            <div class="emofriendly__carousel emofriendly__carousel--affine" id="carousel-affine">
                <!-- Loading state -->
                <div class="emofriendly__loading" id="loading-affine">
                    <div class="emofriendly__loading-spinner"></div>
                    <span class="emofriendly__loading-text text-gray-400 ml-3">Caricamento...</span>
                </div>
            </div>
        </section>

        <!-- Anime Complementari Section -->
        <section class="emofriendly__section emofriendly__section--complementary">
            <div class="emofriendly__section-header mb-4">
                <h2 class="emofriendly__section-title text-xl md:text-2xl font-bold text-white">
                    <span class="emofriendly__section-emoji">☯️</span>
                    Anime Complementari
                </h2>
                <p class="emofriendly__section-subtitle text-gray-400 text-sm">
                    Utenti che potrebbero bilanciare la tua personalità
                </p>
            </div>

            <!-- Carousel Complementary -->
            <div class="emofriendly__carousel emofriendly__carousel--complementary" id="carousel-complementary">
                <!-- Loading state -->
                <div class="emofriendly__loading" id="loading-complementary">
                    <div class="emofriendly__loading-spinner"></div>
                    <span class="emofriendly__loading-text text-gray-400 ml-3">Caricamento...</span>
                </div>
            </div>
        </section>

        <!-- Info Box -->
        <div class="emofriendly__info mt-12 p-6 bg-gray-800/30 backdrop-blur-lg rounded-2xl border border-gray-700/50">
            <h3 class="emofriendly__info-title text-lg font-semibold text-white mb-2">
                <span class="mr-2">💡</span>
                Come funziona?
            </h3>
            <p class="emofriendly__info-text text-gray-400 text-sm leading-relaxed">
                Analizziamo le emozioni che esprimi e ricevi attraverso le tue reazioni ai post audio.
                <strong class="text-purple-400">Anime Affini</strong> sono utenti con un profilo emotivo simile al tuo,
                mentre <strong class="text-cyan-400">Anime Complementari</strong> hanno pattern opposti che potrebbero
                bilanciare e arricchire le tue interazioni.
            </p>
        </div>

    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>
