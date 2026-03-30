<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'need2talk'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),

    /*
    |--------------------------------------------------------------------------
    | Asset Version (Cache Busting)
    |--------------------------------------------------------------------------
    |
    | Version string appended to CSS/JS files for cache busting
    | Change this when deploying new assets to force browser re-download
    |
    | Production: Use git commit hash or timestamp
    | Development: Use timestamp for auto-invalidation
    |
    */
    'asset_version' => env('ASSET_VERSION', hash('crc32', file_get_contents(__DIR__ . '/../.env'))),

    /*
    |--------------------------------------------------------------------------
    | Database Configuration - ENTERPRISE
    |--------------------------------------------------------------------------
    */
    'database' => [
        // Docker Configuration (ENTERPRISE: Standard Laravel env vars)
        // MIGRATED TO POSTGRESQL 16 (2025-01-22)
        'host' => env('DB_HOST', 'postgres'),
        'port' => env('DB_PORT', '5432'),
        'name' => env('DB_DATABASE', 'need2talk'),        // FIXED: DB_DATABASE (not DB_NAME)
        'user' => env('DB_USERNAME', 'need2talk'),        // FIXED: DB_USERNAME (not DB_USER)
        'password' => env('DB_PASSWORD', 'YOUR_DB_PASSWORD'),  // FIXED: Secure default
        'charset' => 'utf8',  // PostgreSQL: UTF8 encoding (utf8mb4 not needed)

        // Enterprise Features
        'pool_size' => env('DB_POOL_SIZE', 50),
        'connection_timeout' => env('DB_TIMEOUT', 10),
        'query_cache_enabled' => env('DB_CACHE_ENABLED', true),
        'slow_query_log' => env('DB_SLOW_QUERY_LOG', false),
        'slow_query_threshold' => env('DB_SLOW_QUERY_THRESHOLD', 0.1), // 100ms

        // Future: Read Replicas (will be added later)
        'read_replicas' => [
            // ['host' => '127.0.0.1', 'port' => '8890'],
            // ['host' => '127.0.0.1', 'port' => '8891'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Configuration - ENTERPRISE REDIS
    |--------------------------------------------------------------------------
    */
    'session' => [
        'driver' => env('SESSION_DRIVER', 'redis'), // Redis enterprise configuration
        'lifetime' => env('SESSION_LIFETIME', 60), // minutes (ENTERPRISE GALAXY: 1h = 60min, was 1440min = 24h)
        'cookie_name' => 'need2talk_session',
        'secure' => env('SESSION_SECURE', false),
        'http_only' => true,
        'same_site' => 'lax',

        // Redis Session Config - Docker Redis port 63796379
        'redis' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_SESSION_DB', 1),
            'prefix' => 'need2talk:session:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration - MULTI-LEVEL
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'default' => env('CACHE_DRIVER', 'multilevel'),
        'enabled' => env('CACHE_ENABLED', true),

        // Multi-level cache configuration
        'multilevel' => [
            'l1_enterprise_redis' => [
                'enabled' => extension_loaded('redis'),
                'host' => env('REDIS_L1_HOST', 'redis'),
                'port' => env('REDIS_L1_PORT', 6379),
                'database' => env('REDIS_L1_DB', 1),
                'ttl' => 300, // 5 minutes max for L1
            ],
            'l2_memcached' => [
                'enabled' => class_exists('Memcached'),
                'host' => env('MEMCACHED_HOST', 'memcached'),
                'port' => env('MEMCACHED_PORT', 11211),
                'ttl' => 3600, // 1 hour for L2
            ],
            'l3_redis' => [
                'enabled' => class_exists('Redis'),
                'host' => env('REDIS_HOST', 'redis'),
                'port' => env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD', null),
                'database' => env('REDIS_CACHE_DB', 0),
                'ttl' => 7200, // 2 hours for L3
            ],
        ],

        // Cache TTL presets
        'ttl' => [
            'short' => 300,      // 5 minutes
            'medium' => 1800,    // 30 minutes
            'long' => 3600,      // 1 hour
            'very_long' => 7200, // 2 hours
            'daily' => 86400,     // 24 hours
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'default' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DB', 0),
        ],

        'session' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => 1, // Separate DB for sessions
        ],

        'cache' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => 0, // Default DB for cache
        ],

        'queue' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => 2, // Separate DB for queues
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Processing Configuration
    |--------------------------------------------------------------------------
    */
    'audio' => [
        // WebM optimized for 30s @ 48kbps = ~180KB files
        'max_duration' => 30, // 30 seconds (as specified)
        'max_file_size' => 500 * 1024, // 500KB (generous for WebM @ 48kbps)
        'allowed_formats' => ['webm'], // WebM only for enterprise consistency
        'upload_path' => 'storage/uploads/audio',
        'temp_path' => 'storage/temp',

        // WebM specific optimizations
        'webm' => [
            'bitrate' => 48000, // 48kbps as specified
            'sample_rate' => 16000, // 16kHz for voice optimization
            'channels' => 1, // Mono for voice
            'codec' => 'opus', // Opus codec in WebM container
        ],

        // Enterprise upload optimizations
        'concurrent_uploads' => 1000, // Support 1000 concurrent uploads
        'chunk_size' => 64 * 1024, // 64KB chunks for network efficiency
        'timeout' => 30, // 30s timeout for small WebM files
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration - ENTERPRISE REDIS-BASED
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'driver' => env('RATE_LIMIT_DRIVER', 'redis'), // Changed from database to Redis

        // Audio upload limits (progressive system preserved)
        'audio_upload' => [
            'max_per_day' => 10,
            'cooldown_minutes' => 5,
            'progressive' => true, // Enable progressive penalties
        ],

        // Journal audio upload limits (ENTERPRISE GALAXY+ Phase 1.4)
        // V12.1: Updated cooldown to 10 minutes
        'journal_audio_upload' => [
            'max_per_day' => 10,
            'cooldown_minutes' => 10,
            'progressive' => true, // Enable progressive penalties
        ],

        // Journal entry limits (text/mixed entries) - V12.1
        // Applies to ALL journal entries (text, photo, audio, mixed)
        'journal_entry' => [
            'max_per_day' => 25,
            'cooldown_minutes' => 10,
            'progressive' => false, // Fixed cooldown
        ],

        // API rate limits for enterprise scale
        'api' => [
            'global' => ['requests' => 1000, 'window' => 3600], // 1000/hour
            'login' => ['requests' => 5, 'window' => 300],       // 5/5min
            'register' => ['requests' => 3, 'window' => 3600],   // 3/hour
            'websocket' => ['requests' => 100, 'window' => 60],  // 100/min
        ],

        // Social features
        'friend_requests' => [
            'max_per_day' => 20,
            'cooldown_minutes' => 1,
        ],
        'comments' => [
            'max_per_hour' => 100,
            'cooldown_seconds' => 5,
        ],
        'likes' => [
            'max_per_hour' => 500,
            'cooldown_seconds' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Configuration - ENTERPRISE CLUSTERING
    |--------------------------------------------------------------------------
    */
    'websocket' => [
        'enabled' => env('WEBSOCKET_ENABLED', true),
        'host' => env('WEBSOCKET_HOST', '0.0.0.0'),
        'port' => env('WEBSOCKET_PORT', 8080),

        // Clustering support
        'cluster' => [
            'enabled' => env('WEBSOCKET_CLUSTER', false),
            'nodes' => [
                ['host' => '0.0.0.0', 'port' => 8080],
                // Future nodes will be added here
            ],
        ],

        // Performance settings
        'max_connections_per_server' => env('WEBSOCKET_MAX_CONNECTIONS', 10000),
        'heartbeat_interval' => 30,
        'max_message_size' => 16384, // 16KB
        'compression' => true,

        // Redis for message broadcasting
        'redis_channel' => 'need2talk:websocket',
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Metrics Configuration
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'enabled' => env('MONITORING_ENABLED', true),
        'metrics_collection' => env('METRICS_ENABLED', true),

        // Metrics storage
        'metrics' => [
            'driver' => 'redis',
            'retention_days' => 30,
            'aggregation_interval' => 300, // 5 minutes
        ],

        // Performance monitoring
        'performance' => [
            'slow_request_threshold' => 1.0, // 1 second
            'memory_threshold' => 128, // MB
            'cpu_threshold' => 80, // percent
        ],

        // Health checks
        'health_checks' => [
            'enabled' => true,
            'interval' => 60, // seconds
            'endpoints' => [
                'database',
                'redis',
                'memcached',
                'websocket',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Constants - ENTERPRISE OPTIMIZATION
    |--------------------------------------------------------------------------
    |
    | Centralized performance tuning constants for enterprise scale.
    | All magic numbers extracted here for maintainability.
    |
    */
    'performance' => [
        // Cache Stampede Prevention (ReactionStatsService, etc.)
        'cache_stampede' => [
            'enabled' => true,
            'mutex_lock_timeout' => env('CACHE_MUTEX_TIMEOUT', 10), // seconds
            'background_refresh_lock_timeout' => env('CACHE_BG_REFRESH_TIMEOUT', 5), // seconds
            'probabilistic_early_expiration' => [
                'enabled' => true,
                'threshold_percentage' => env('CACHE_EARLY_REFRESH_THRESHOLD', 0.1), // 10% of TTL remaining
                'probability' => env('CACHE_EARLY_REFRESH_PROBABILITY', 0.1), // 10% chance (1 in 10)
            ],
            'retry_wait_microseconds' => env('CACHE_RETRY_WAIT_US', 100000), // 100ms
        ],

        // Circuit Breaker Pattern (for external services & cache)
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => env('CIRCUIT_BREAKER_FAILURES', 10), // failures before opening
            'timeout_seconds' => env('CIRCUIT_BREAKER_TIMEOUT', 20), // seconds in open state
            'success_threshold' => env('CIRCUIT_BREAKER_SUCCESS', 3), // successes to close circuit
        ],

        // Database Performance
        'database' => [
            'slow_query_threshold_ms' => env('DB_SLOW_QUERY_MS', 100), // 100ms
            'query_timeout_seconds' => env('DB_QUERY_TIMEOUT', 30), // 30s max query time
            'transaction_timeout_seconds' => env('DB_TX_TIMEOUT', 30), // 30s max transaction
        ],

        // API Response Timeouts
        'timeouts' => [
            'http_request' => env('HTTP_TIMEOUT', 30), // seconds
            'websocket_message' => env('WS_TIMEOUT', 5), // seconds
            'stream_chunk' => env('STREAM_TIMEOUT', 10), // seconds
        ],

        // Retry Configuration
        'retry' => [
            'max_attempts' => env('RETRY_MAX_ATTEMPTS', 3),
            'backoff_multiplier' => env('RETRY_BACKOFF_MULTIPLIER', 2), // exponential: 1s, 2s, 4s
            'initial_delay_ms' => env('RETRY_INITIAL_DELAY_MS', 1000), // 1s
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration - ENTERPRISE GRADE
    |--------------------------------------------------------------------------
    */
    'security' => [
        // WAF (Web Application Firewall)
        'waf' => [
            'enabled' => env('WAF_ENABLED', true),
            'log_attacks' => true,
            'block_ips' => true,
            // ENTERPRISE: NO hardcoded IPs - use ip_whitelist database table instead
            'ip_whitelist' => array_filter(explode(',', env('WAF_IP_WHITELIST', ''))),
            'ip_blacklist' => env('WAF_IP_BLACKLIST', []),
        ],

        // DDoS protection
        'ddos' => [
            'enabled' => true,
            'requests_per_minute' => 300,
            'ban_duration' => 3600, // 1 hour
        ],

        // Bot detection
        'bot_detection' => [
            'enabled' => true,
            'challenge_threshold' => 10, // requests per minute
            'patterns' => [
                'user_agent' => ['/bot/i', '/crawler/i', '/spider/i'],
                'missing_headers' => ['Accept', 'Accept-Language'],
            ],
        ],

        // Content Security Policy
        'csp' => [
            'enabled' => true,
            'report_only' => false,
            'directives' => [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://cdn.need2talk.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: https:",
                "media-src 'self'",
                "connect-src 'self' wss: ws:",
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration - ENTERPRISE
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'default' => env('LOG_CHANNEL', 'stack'),
        'channels' => [
            'stack' => [
                'driver' => 'stack',
                'channels' => ['daily', 'redis'],
            ],
            'daily' => [
                'driver' => 'daily',
                'path' => (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/need2talk.log',
                'level' => env('LOG_LEVEL', 'info'),
                'days' => 14,
            ],
            'redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'level' => env('LOG_LEVEL', 'info'),
            ],
            'security' => [
                'driver' => 'daily',
                'path' => (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/security.log',
                'level' => 'warning',
                'days' => 90, // Keep security logs longer
            ],
            'performance' => [
                'driver' => 'daily',
                'path' => (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/performance.log',
                'level' => 'info',
                'days' => 7,
            ],
            'js_errors' => [
                'driver' => 'daily',
                'path' => (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/js_errors.log',
                'level' => 'info',
                'days' => 30, // Keep JS errors longer for debugging
            ],
            'audio' => [
                'driver' => 'daily',
                'path' => (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/audio.log',
                'level' => 'info',
                'days' => 14, // Audio processing logs (upload, worker, S3)
            ],
            'websocket' => [
                'driver' => 'daily',
                'path' => (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/websocket.log',
                'level' => 'debug', // Debug level for real-time event troubleshooting
                'days' => 7, // WebSocket server logs (connections, events, PubSub)
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration - BACKGROUND JOBS
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'default' => env('QUEUE_DRIVER', 'redis'),
        'connections' => [
            'redis' => [
                'driver' => 'redis',
                'connection' => 'queue',
                'queue' => 'default',
                'retry_after' => 300,
            ],
        ],

        // Job types
        'jobs' => [
            'audio_processing' => 'high',
            'email_notifications' => 'normal',
            'cache_cleanup' => 'low',
            'metrics_aggregation' => 'low',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags - A/B TESTING
    |--------------------------------------------------------------------------
    */
    'features' => [
        'new_audio_player' => env('FEATURE_NEW_AUDIO_PLAYER', false),
        'advanced_analytics' => env('FEATURE_ADVANCED_ANALYTICS', true),
        'websocket_clustering' => env('FEATURE_WS_CLUSTERING', false),
        'cdn_offloading' => env('FEATURE_CDN_OFFLOADING', false),
        'ml_moderation' => env('FEATURE_ML_MODERATION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Emotional Health Dashboard - Mock Data
    |--------------------------------------------------------------------------
    |
    | ENTERPRISE FEATURE: Generate realistic mock emotional data for users
    | who haven't started using the platform yet.
    |
    | DISABLED: Show real data only with elegant empty state when no data exists.
    | Users see a welcoming message encouraging them to start recording.
    |
    */
    'emotional_health_mock_enabled' => env('EMOTIONAL_HEALTH_MOCK_ENABLED', false),
];
