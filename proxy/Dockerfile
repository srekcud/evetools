# Stage 1: Build frontend
FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install
COPY frontend/ .
ARG VITE_VAPID_PUBLIC_KEY=""
ENV VITE_VAPID_PUBLIC_KEY=$VITE_VAPID_PUBLIC_KEY
ARG VITE_APP_VERSION=""
ENV VITE_APP_VERSION=$VITE_APP_VERSION
RUN npm run build

# Stage 2: Caddy serving static frontend + reverse proxy
FROM caddy:2-alpine
COPY proxy/Caddyfile /etc/caddy/Caddyfile
COPY proxy/maintenance.html /srv/maintenance.html
COPY --from=frontend-build /app/dist /srv/frontend
