# Application image — requires evetools-base:latest (see: make base-build)
FROM evetools-base:latest

ENV APP_ENV=prod

# Composer install (cache layer: only invalidated if composer.json/lock change)
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Application code
COPY . .

# Autoloader + bundle assets (assets:install does not need DATABASE_URL)
RUN composer dump-autoload --optimize && \
    php bin/console assets:install public --no-interaction

# Warmup cache + Doctrine proxies (baked into image)
RUN php bin/console cache:warmup --env=prod || true

# Permissions
RUN mkdir -p var/cache var/log && chmod -R 777 var

# Caddy config
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile

# Health check
RUN mkdir -p public && echo '{"status":"ok"}' > public/health.json

EXPOSE 80 443

ENTRYPOINT ["frankenphp"]
CMD ["run", "--config", "/etc/caddy/Caddyfile"]
