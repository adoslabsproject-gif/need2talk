# ================================================================================
# NEED2TALK - AUDIO UPLOAD WORKER (ENTERPRISE GALAXY)
# ================================================================================
#
# PURPOSE: Isolated container for audio processing and S3 upload
#
# FEATURES:
# - ffmpeg/ffprobe for metadata extraction (duration, bitrate, codec)
# - AWS SDK for S3 upload (DigitalOcean Spaces compatible)
# - Redis for queue management + heartbeat
# - PostgreSQL for status updates
# - Auto-scaling: 1-12 workers (docker-compose scale)
# - Resource limits: 0.5 CPU, 512MB RAM per worker
#
# SECURITY:
# - ACL PRIVATE enforced (no public URLs)
# - Signed URLs only (1h expiration)
# - Limited S3 credentials (Read+Write only)
# - No shell access (PHP CLI only)
#
# PERFORMANCE:
# - Batch processing: 50 files per cycle
# - Cycle time: 30 seconds
# - Throughput: 12 workers × 50 files × 2 cycles/min = 1,200 uploads/min
# - Memory efficient: Stream processing (no full file load)
#
# ================================================================================

FROM php:8.3-cli-alpine

LABEL maintainer="need2talk.it"
LABEL description="Audio Upload Worker - Enterprise Galaxy"
LABEL version="1.0.0"

# ================================================================================
# SYSTEM DEPENDENCIES
# ================================================================================

RUN apk add --no-cache \
    # Core tools
    curl \
    git \
    # Audio processing (ffmpeg + ffprobe)
    ffmpeg \
    ffmpeg-libs \
    # Image libraries (for future thumbnail generation)
    libpng-dev \
    libjpeg-turbo-dev \
    # Compression
    libzip-dev \
    zip \
    unzip \
    # Internationalization
    icu-dev \
    # Build tools (for PHP extensions)
    autoconf \
    g++ \
    make \
    # SSL/TLS (for S3 HTTPS)
    openssl-dev \
    ca-certificates \
    # Process management
    supervisor \
    # Timezone data
    tzdata \
    # PostgreSQL client libraries (for pdo_pgsql)
    postgresql-dev

# ENTERPRISE: Configure timezone for filesystem timestamps
# TZ env var fixes PHP but NOT filesystem in Alpine Linux
RUN cp /usr/share/zoneinfo/Europe/Rome /etc/localtime && \
    echo "Europe/Rome" > /etc/timezone

# ================================================================================
# PHP EXTENSIONS (Minimal for CLI worker)
# ================================================================================

RUN docker-php-ext-install \
    # Database (ENTERPRISE: PostgreSQL-only, MySQL removed)
    pdo \
    pdo_pgsql \
    # Math (for calculations)
    bcmath \
    # Internationalization
    intl \
    # Compression (for AWS SDK)
    zip \
    # Semaphore (for process locking)
    sysvsem \
    # OPcache (for performance)
    opcache \
    # PCNTL (for signal handling - SIGTERM/SIGINT graceful shutdown)
    pcntl

# ================================================================================
# REDIS EXTENSION (for queue + heartbeat)
# ================================================================================

RUN pecl install redis && docker-php-ext-enable redis

# ================================================================================
# MEMCACHED EXTENSION (for multi-level cache compatibility)
# ================================================================================

RUN apk add --no-cache libmemcached-dev zlib-dev \
    && pecl install memcached \
    && docker-php-ext-enable memcached

# ================================================================================
# COMPOSER (for AWS SDK)
# ================================================================================

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ================================================================================
# VERIFY FFMPEG/FFPROBE INSTALLATION
# ================================================================================

RUN ffmpeg -version && ffprobe -version && \
    echo "✅ ffmpeg/ffprobe installed successfully"

# ================================================================================
# WORKING DIRECTORY
# ================================================================================

WORKDIR /var/www/html

# ================================================================================
# NON-ROOT USER (security best practice)
# ================================================================================

RUN addgroup -g 1000 worker && \
    adduser -D -u 1000 -G worker worker

# ================================================================================
# PERMISSIONS
# ================================================================================

RUN chown -R worker:worker /var/www/html

# ================================================================================
# PHP CONFIGURATION (CLI optimized)
# ================================================================================

RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory.ini && \
    echo "max_execution_time = 300" > /usr/local/etc/php/conf.d/execution.ini && \
    echo "error_log = /var/www/html/storage/logs/worker-php-errors.log" > /usr/local/etc/php/conf.d/errors.ini && \
    echo "date.timezone = Europe/Rome" > /usr/local/etc/php/conf.d/timezone.ini

# OPcache configuration (CLI mode)
RUN echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini

# ================================================================================
# SWITCH TO NON-ROOT USER
# ================================================================================

USER worker

# ================================================================================
# HEALTHCHECK (Redis heartbeat verification)
# ================================================================================

HEALTHCHECK --interval=60s --timeout=10s --start-period=30s --retries=3 \
    CMD php /var/www/html/scripts/audio-worker-health-check.php || exit 1

# ================================================================================
# ENTRYPOINT: Audio Upload Worker Loop
# ================================================================================

CMD ["php", "/var/www/html/scripts/audio-upload-worker.php"]
