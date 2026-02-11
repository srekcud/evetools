# Dockerfile for Symfony 7 with FrankenPHP
FROM dunglas/frankenphp:php8.4-alpine

# Install system dependencies
RUN apk add --no-cache \
    acl \
    fcgi \
    file \
    gettext \
    git \
    icu-dev \
    libpq-dev \
    libsodium-dev \
    rabbitmq-c-dev \
    linux-headers

# Install PHP extensions
RUN install-php-extensions \
    amqp \
    apcu \
    intl \
    opcache \
    pdo_pgsql \
    redis \
    sodium \
    uuid \
    zip

# Configure PHP
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Set production environment
ENV APP_ENV=prod

# Copy composer files first for better caching
COPY composer.json composer.lock* ./

# Install dependencies (no scripts yet, no autoloader)
RUN composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Copy the rest of the application
COPY . .

# Generate optimized autoloader and run post-install scripts
RUN composer dump-autoload --optimize && \
    composer run-script post-install-cmd --no-interaction || true

# Set permissions
RUN mkdir -p var/cache var/log && \
    chmod -R 777 var

# Configure FrankenPHP/Caddy
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile

# Create health check endpoint
RUN mkdir -p public && echo '{"status":"ok"}' > public/health.json

EXPOSE 80 443

# Use FrankenPHP as the entrypoint
ENTRYPOINT ["frankenphp"]
CMD ["run", "--config", "/etc/caddy/Caddyfile"]
