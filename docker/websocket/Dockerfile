# WebSocket Server Dockerfile - Enterprise Galaxy Ultimate (Swoole)
# Real-time event-driven WebSocket server using Swoole (C extension)
#
# Base: PHP 8.3 CLI (non-FPM, for long-running process)
# Purpose: Handle 100,000+ concurrent WebSocket connections
# Performance: Coroutine-based async I/O via Swoole (C-powered)
#
# ENTERPRISE FEATURES:
# - Swoole 5.x (latest stable with PHP 8.3 support)
# - Native coroutines (async/await style)
# - Built-in WebSocket server (RFC 6455 compliant)
# - Event-driven I/O in C (10x faster than pure PHP)
# - Memory efficiency: ~20KB per connection

FROM php:8.3-cli

LABEL maintainer="need2talk Enterprise Team"
LABEL description="WebSocket Server - Swoole Enterprise"
LABEL version="2.0.0"

# Install system dependencies (Swoole compilation requirements + PostgreSQL)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    procps \
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (required for Swoole + Redis + PostgreSQL)
# ENTERPRISE V9.1 (2025-12-06): Added pdo_pgsql for ChatRoomService DB access
RUN docker-php-ext-install \
    zip \
    pcntl \
    sockets \
    pdo \
    pdo_pgsql

# Install Swoole extension via PECL (ENTERPRISE GALAXY)
# Version: 5.x (latest stable with PHP 8.3 support)
# Features: coroutines, WebSocket, HTTP server, async I/O
RUN pecl install swoole \
    && docker-php-ext-enable swoole

# Install Redis extension for Swoole\Coroutine\Redis compatibility
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer (for autoloading)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# PHP configuration for long-running Swoole process
RUN echo "memory_limit = 1G" > /usr/local/etc/php/conf.d/memory-limit.ini && \
    echo "max_execution_time = 0" > /usr/local/etc/php/conf.d/max-execution-time.ini && \
    echo "error_log = /var/www/html/storage/logs/websocket_errors.log" > /usr/local/etc/php/conf.d/error-log.ini && \
    echo "date.timezone = Europe/Rome" > /usr/local/etc/php/conf.d/timezone.ini

# Swoole-specific PHP configuration (ENTERPRISE OPTIMIZATION)
RUN echo "swoole.use_shortname = On" > /usr/local/etc/php/conf.d/swoole.ini && \
    echo "swoole.enable_coroutine = On" > /usr/local/etc/php/conf.d/swoole.ini && \
    echo "swoole.display_errors = Off" > /usr/local/etc/php/conf.d/swoole.ini

# Expose WebSocket port
EXPOSE 8090

# Health check (verify Swoole WebSocket server is listening)
# Swoole health check tests TCP connection + HTTP upgrade
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD php /var/www/html/scripts/websocket-health-check.php || exit 1

# Run Swoole WebSocket server (long-running coroutine process)
CMD ["php", "/var/www/html/scripts/websocket-server.php"]
