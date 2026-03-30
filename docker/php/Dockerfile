FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    autoconf \
    g++ \
    make \
    openssl-dev \
    linux-headers \
    tzdata \
    postgresql-dev \
    ffmpeg

# ENTERPRISE: Configure timezone for filesystem timestamps
# TZ env var fixes PHP/PostgreSQL but NOT filesystem in Alpine Linux
RUN cp /usr/share/zoneinfo/Europe/Rome /etc/localtime && \
    echo "Europe/Rome" > /etc/timezone

# Configure GD with JPEG, PNG, WebP, FreeType support
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp

# Install PHP extensions (ENTERPRISE: PostgreSQL-only, MySQL removed)
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    opcache \
    intl \
    zip \
    bcmath \
    sysvsem \
    pcntl \
    gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Memcached extension
RUN apk add --no-cache libmemcached-dev zlib-dev \
    && pecl install memcached \
    && docker-php-ext-enable memcached

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ENTERPRISE: Install Docker CLI + Compose for worker management via socket
# This allows PHP container to control worker container via Docker socket
RUN apk add --no-cache docker-cli docker-cli-compose

# ENTERPRISE: Install fcgi for PHP-FPM health checks
RUN apk add --no-cache fcgi

# ENTERPRISE: Copy and install PHP-FPM health check script
COPY docker/php/php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck
RUN chmod +x /usr/local/bin/php-fpm-healthcheck

# Set working directory
WORKDIR /var/www/html

# Create non-root user and Docker group for socket access
RUN addgroup -g 1000 www && \
    adduser -D -u 1000 -G www www && \
    addgroup -g 987 docker && \
    adduser www docker

# Set permissions
RUN chown -R www:www /var/www/html

# Switch to non-root user
USER www

# Expose port 9000 for PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
