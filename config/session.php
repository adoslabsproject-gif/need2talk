<?php

/**
 * Session Configuration - ENTERPRISE
 *
 * Settings moved from database to config for ultra-performance
 * No DB query needed for session management
 */

return [
    // Session lifetime in minutes (default: 1 hour)
    'lifetime_minutes' => (int) ($_ENV['SESSION_LIFETIME_MINUTES'] ?? 60),

    // Remember me token duration in days (default: 30 days)
    'remember_token_days' => (int) ($_ENV['REMEMBER_TOKEN_DAYS'] ?? 30),

    // Maximum concurrent sessions per user (default: 5)
    'max_sessions_per_user' => (int) ($_ENV['MAX_SESSIONS_PER_USER'] ?? 5),

    // Session cookie configuration
    'cookie_name' => 'need2talk_session',
    'cookie_lifetime' => 0, // Session cookie (expires on browser close)
    'cookie_path' => '/',
    'cookie_domain' => '',
    'cookie_secure' => true, // HTTPS only
    'cookie_httponly' => true, // No JavaScript access
    'cookie_samesite' => 'Lax', // CSRF protection
];
