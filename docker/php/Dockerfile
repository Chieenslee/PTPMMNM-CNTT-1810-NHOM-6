# ==============================================================================
# XANH HUB (ĐỀ TÀI 6) - PRODUCTION MULTI-STAGE DOCKERFILE
# Stage 1: Builder (Composer autoloader optimization)
# Stage 2: Runtime (PHP 8.2-FPM Alpine hardened production image)
# ==============================================================================

# ------------------------------------------------------------------------------
# STAGE 1: BUILDER
# ------------------------------------------------------------------------------
FROM composer:2-alpine AS builder

WORKDIR /app
COPY composer.json ./
RUN composer dump-autoload --optimize --no-dev --no-interaction

# ------------------------------------------------------------------------------
# STAGE 2: PRODUCTION RUNTIME
# ------------------------------------------------------------------------------
FROM php:8.2-fpm-alpine

LABEL maintainer="Xanh Hub Engineering Team <dev@xanhhub.org>"
LABEL description="Xanh Hub Production PHP 8.2-FPM Multi-Stage Container Image"

# Install native runtime dependencies
RUN apk add --no-cache \
        libpq \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip \
        tzdata \
        fcgi \
    && cp /usr/share/zoneinfo/Asia/Ho_Chi_Minh /etc/localtime \
    && echo "Asia/Ho_Chi_Minh" > /etc/timezone

# Install build dependencies, compile extensions, and clean up build cache
RUN apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        opcache \
        gd \
        zip \
        bcmath \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# Apply production php.ini base
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Enable ping path and status path for php-fpm healthchecks
RUN set -xe \
    && echo "ping.path = /ping" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "pm.status_path = /status" >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Copy custom INI configurations (Opcache & Custom runtime tuning)
COPY docker/php/conf.d/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini
COPY docker/php/conf.d/custom.ini $PHP_INI_DIR/conf.d/custom.ini

# Set application working directory
WORKDIR /var/www/html

# Copy application source code and optimized vendor from builder stage
COPY . /var/www/html
COPY --from=builder /app/vendor /var/www/html/vendor

# Set non-root ownership and execution permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

USER www-data

EXPOSE 9000

# FastCGI Healthcheck using cgi-fcgi
HEALTHCHECK --interval=20s --timeout=5s --start-period=5s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

CMD ["php-fpm"]
