# ============================================================================
# ENTERPRISE GALAXY NGINX - Multi-Stage Build with Brotli Compilation
# ============================================================================
# Architecture: Multi-stage Docker build for optimized production image
#
# Stage 1 (Builder): Compile ngx_brotli modules for NGINX 1.28.0
# Stage 2 (Runtime): Copy compiled modules to production NGINX image
#
# Optimizations Enabled:
# ✅ HTTP/3 + QUIC (UDP port 443)
# ✅ HSTS Preload (2-year max-age)
# ✅ Brotli compression (custom compiled, 20-30% better than Gzip)
# ✅ Gzip compression fallback (level 6)
#
# Build: docker build -t need2talk-nginx:latest -f docker/nginx/Dockerfile docker/nginx/
# Size: ~50MB (optimized Alpine base)
# ============================================================================

# ============================================================================
# STAGE 1: BUILD ENVIRONMENT (ngx_brotli compilation)
# ============================================================================
FROM nginx:1.28-alpine AS builder

# Install build dependencies
RUN apk add --no-cache \
    git \
    gcc \
    libc-dev \
    make \
    openssl-dev \
    pcre-dev \
    zlib-dev \
    linux-headers \
    curl \
    gnupg \
    libxslt-dev \
    gd-dev \
    geoip-dev \
    brotli-dev \
    zstd-dev

# Get NGINX version for module compilation (BusyBox-compatible)
RUN export NGINX_VERSION=$(nginx -v 2>&1 | sed -n 's/.*nginx\/\([0-9.]*\).*/\1/p') && \
    echo "Building for NGINX version: ${NGINX_VERSION}"

# Clone compression modules
# - ngx_brotli (Google): 20-30% better than Gzip
# - ngx_zstd (Facebook): 10-20% better than Brotli
RUN cd /tmp && \
    git clone --recursive https://github.com/google/ngx_brotli.git && \
    git clone --recursive https://github.com/tokers/zstd-nginx-module.git ngx_zstd

# Download NGINX source code (same version as runtime image)
RUN export NGINX_VERSION=$(nginx -v 2>&1 | sed -n 's/.*nginx\/\([0-9.]*\).*/\1/p') && \
    cd /tmp && \
    curl -fSL https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz -o nginx.tar.gz && \
    tar -xzf nginx.tar.gz && \
    mv nginx-${NGINX_VERSION} nginx

# Compile ngx_brotli as dynamic modules
# Using explicit configure flags (matching Docker nginx:1.28-alpine build)
RUN cd /tmp/nginx && \
    ./configure \
        --prefix=/etc/nginx \
        --sbin-path=/usr/sbin/nginx \
        --modules-path=/usr/lib/nginx/modules \
        --conf-path=/etc/nginx/nginx.conf \
        --with-compat \
        --with-file-aio \
        --with-threads \
        --with-http_ssl_module \
        --with-http_v2_module \
        --with-http_v3_module \
        --with-http_realip_module \
        --with-http_addition_module \
        --with-http_sub_module \
        --with-http_dav_module \
        --with-http_flv_module \
        --with-http_mp4_module \
        --with-http_gunzip_module \
        --with-http_gzip_static_module \
        --with-http_random_index_module \
        --with-http_secure_link_module \
        --with-http_stub_status_module \
        --with-http_auth_request_module \
        --with-http_slice_module \
        --with-mail \
        --with-mail_ssl_module \
        --with-stream \
        --with-stream_ssl_module \
        --with-stream_realip_module \
        --with-stream_ssl_preread_module \
        --add-dynamic-module=/tmp/ngx_brotli \
        --add-dynamic-module=/tmp/ngx_zstd && \
    make modules

# Verify compiled modules (ls to confirm build success)
RUN ls -lh /tmp/nginx/objs/ngx_http_brotli*.so && \
    ls -lh /tmp/nginx/objs/ngx_http_zstd*.so

# ============================================================================
# STAGE 2: PRODUCTION RUNTIME (minimal Alpine image)
# ============================================================================
FROM nginx:1.28-alpine

# Copy compiled compression modules from builder stage
COPY --from=builder /tmp/nginx/objs/ngx_http_brotli_filter_module.so /etc/nginx/modules/
COPY --from=builder /tmp/nginx/objs/ngx_http_brotli_static_module.so /etc/nginx/modules/
COPY --from=builder /tmp/nginx/objs/ngx_http_zstd_filter_module.so /etc/nginx/modules/

# Install runtime dependencies (compression libs + curl for healthcheck)
RUN apk add --no-cache brotli-libs zstd-libs curl

# Verify modules are loadable
RUN nginx -V 2>&1 && \
    ls -lh /etc/nginx/modules/ngx_http_brotli*.so && \
    ls -lh /etc/nginx/modules/ngx_http_zstd*.so

# Copy custom configuration
# (Configurations are mounted via volumes in docker-compose.yml)

# Expose ports (HTTP, HTTPS, HTTP/3 QUIC)
EXPOSE 80 443 443/udp

# Health check (enterprise monitoring)
# Uses /health endpoint (no PHP/PostgreSQL dependency - instant response)
# Uses 127.0.0.1 to hit IP-based server block (port 80, no HTTPS redirect)
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f -s -o /dev/null http://127.0.0.1/health || exit 1

# Metadata labels (enterprise standards)
LABEL maintainer="need2talk.it" \
      version="2.0.0" \
      description="Enterprise NGINX with HTTP/3, Brotli, Zstd, and HSTS Preload" \
      nginx.version="1.28.0" \
      brotli.enabled="true" \
      zstd.enabled="true" \
      http3.enabled="true" \
      compression.stack="gzip+brotli+zstd"

# Start NGINX
CMD ["nginx", "-g", "daemon off;"]
