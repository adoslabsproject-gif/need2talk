<?php
/**
 * Settings Sidebar Navigation - Enterprise Galaxy
 *
 * Shared sidebar for all settings pages
 */

// Current page detection
$currentPage = $_SERVER['REQUEST_URI'] ?? '';
$isOverview = strpos($currentPage, '/settings/account') === false &&
              strpos($currentPage, '/settings/privacy') === false &&
              strpos($currentPage, '/settings/notifications') === false &&
              strpos($currentPage, '/settings/security') === false &&
              strpos($currentPage, '/settings/data-export') === false;
?>

<div class="lg:col-span-1">
    <nav class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-4 sticky top-24">
        <ul class="space-y-2">
            <li>
                <a href="<?= url('/settings') ?>"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl <?= $isOverview ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> font-medium transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                    <span>Panoramica</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/settings/account') ?>"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl <?= strpos($currentPage, '/settings/account') !== false ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> font-medium transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <span>Account</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/settings/privacy') ?>"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl <?= strpos($currentPage, '/settings/privacy') !== false ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> font-medium transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <span>Privacy</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/settings/notifications') ?>"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl <?= strpos($currentPage, '/settings/notifications') !== false ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> font-medium transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                    <span>Notifiche</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/settings/security') ?>"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl <?= strpos($currentPage, '/settings/security') !== false ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> font-medium transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    <span>Sicurezza</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/settings/data-export') ?>"
                   class="flex items-center gap-3 px-4 py-3 rounded-xl <?= strpos($currentPage, '/settings/data-export') !== false ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> font-medium transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Dati e Privacy</span>
                </a>
            </li>
        </ul>
    </nav>
</div>
