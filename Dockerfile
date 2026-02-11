# Application image — requires evetools-base:latest (see: make base-build)
FROM evetools-base:latest

ENV APP_ENV=prod

# Composer install (cache layer: only invalidated if composer.json/lock change)
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Application code
COPY . .

# Autoloader + post-install scripts
RUN composer dump-autoload --optimize && \
    composer run-script post-install-cmd --no-interaction || true

# Permissions
RUN mkdir -p var/cache var/log && chmod -R 777 var

# Caddy config
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile

# Health check
RUN mkdir -p public && echo '{"status":"ok"}' > public/health.json

EXPOSE 80 443

ENTRYPOINT ["frankenphp"]
CMD ["run", "--config", "/etc/caddy/Caddyfile"]
