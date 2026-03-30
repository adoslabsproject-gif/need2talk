<?php

/**
 * Enterprise Configuration for need2talk
 * Optimized for 100,000+ concurrent users with WebM audio files
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enterprise Scalability Configuration
    |--------------------------------------------------------------------------
    */
    'scalability' => [
        // Target concurrent users
        'max_concurrent_users' => 100000,

        // Database connection pool
        'database_pool_size' => 200,
        'database_timeout' => 10,

        // Redis configuration
        'redis_max_memory' => '4gb',
        'redis_max_clients' => 50000,
        'redis_policy' => 'allkeys-lru',

        // Session management
        // ENTERPRISE GALAXY V6.6: Read from env via centralized method
        'session_lifetime' => \Need2Talk\Core\EnterpriseGlobalsManager::getSessionLifetimeSeconds(),
        'session_gc_probability' => 1,
        'session_gc_divisor' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | WebM Audio Optimization
    |--------------------------------------------------------------------------
    */
    'webm_audio' => [
        // File specifications (as requested)
        'max_duration' => 30, // 30 seconds
        'bitrate' => 48000, // 48kbps
        'expected_file_size' => 180 * 1024, // ~180KB

        // Upload limits
        'max_file_size' => 500 * 1024, // 500KB (generous buffer)
        'allowed_formats' => ['webm'],
        'codec' => 'opus',
        'sample_rate' => 16000, // 16kHz for voice
        'channels' => 1, // Mono

        // Processing optimizations
        'upload_timeout' => 30,
        'processing_timeout' => 45,
        'concurrent_uploads' => 1000,
        'chunk_size' => 64 * 1024, // 64KB chunks
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory and Performance Optimization
    |--------------------------------------------------------------------------
    */
    'performance' => [
        // PHP memory optimization
        'memory_limit' => '512M', // Increased for enterprise
        'max_execution_time' => 30,
        'max_input_time' => 30,

        // Upload optimization
        'upload_max_filesize' => '1M',
        'post_max_size' => '2M',
        'max_file_uploads' => 5,

        // Output optimization
        'output_buffering' => 4096,
        'compression' => true,

        // OpCache optimization
        'opcache_memory' => 256, // MB
        'opcache_max_files' => 20000,
        'opcache_validate_timestamps' => false, // Disable in production
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Rate limiting (per minute)
        'api_rate_limit' => 60,
        'upload_rate_limit' => 10,
        'login_rate_limit' => 5,

        // Content Security Policy
        'csp_enabled' => true,
        'frame_options' => 'DENY',
        'content_type_options' => 'nosniff',

        // HTTPS enforcement
        'force_https' => true,
        'hsts_max_age' => 31536000, // 1 year
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Analytics
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        // Performance thresholds
        'slow_query_threshold' => 100, // milliseconds
        'memory_usage_threshold' => 80, // percent
        'cpu_usage_threshold' => 70, // percent

        // Error tracking
        'error_reporting' => true,
        'log_slow_requests' => true,
        'log_memory_usage' => true,

        // Health checks
        'health_check_interval' => 60, // seconds
        'metrics_retention' => 30, // days
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration - Enterprise Redis Architecture
    |--------------------------------------------------------------------------
    */
    'cache' => [
        // Multi-level caching with Redis-based L1
        'l1_cache' => 'enterprise_redis', // Ultra-fast Redis L1 cache
        'l2_cache' => 'memcached', // Distributed Memcached cache
        'l3_cache' => 'redis', // Persistent Redis cache

        // Cache TTL (seconds)
        'audio_metadata_ttl' => 3600,
        'user_session_ttl' => 1800,
        'static_content_ttl' => 86400,

        // Cache sizes - Enterprise Redis architecture
        'l1_redis_memory' => 512, // MB for ultra-fast L1
        'l2_memcached_memory' => 1024, // MB for distributed L2
        'l3_redis_memory' => 4096, // MB for persistent L3
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN and Asset Optimization
    |--------------------------------------------------------------------------
    */
    'assets' => [
        // CDN regions
        'cdn_enabled' => false, // Disabled for development
        'cdn_regions' => ['eu-west', 'us-east', 'asia-pacific'],

        // Asset optimization
        'js_minification' => true,
        'css_minification' => true,
        'image_optimization' => true,

        // Caching headers
        'static_cache_ttl' => 31536000, // 1 year
        'dynamic_cache_ttl' => 300, // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Optimization
    |--------------------------------------------------------------------------
    */
    'database' => [
        // Connection optimization
        'persistent_connections' => true,
        'connection_pool_size' => 200,
        'idle_timeout' => 300,

        // Query optimization
        'query_cache_enabled' => true,
        'slow_query_log' => true,
        'slow_query_threshold' => 100, // ms

        // Indexing optimization
        'auto_optimize_tables' => true,
        'index_optimization' => true,
    ],
];
