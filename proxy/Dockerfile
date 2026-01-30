# Stage 1: Build frontend
FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install
COPY frontend/ .
RUN npm run build

# Stage 2: Caddy serving static frontend + reverse proxy
FROM caddy:2-alpine
COPY proxy/Caddyfile /etc/caddy/Caddyfile
COPY --from=frontend-build /app/dist /srv/frontend
