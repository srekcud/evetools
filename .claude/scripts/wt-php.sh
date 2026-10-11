#!/usr/bin/env bash
set -euo pipefail

readonly script_dir="$(cd "$(dirname "$0")" && pwd)"
readonly main_repo="$(dirname "$(git -C "$script_dir" rev-parse --path-format=absolute --git-common-dir)")"
readonly compose_network="evetools_evetools_net"
readonly dev_image="evetools-app-dev"

usage() {
  echo "usage: $0 <worktree> unit|integration <php args...>" >&2
  exit 64
}

write_placeholder_env() {
  cat > "$1/.env" <<'ENV'
APP_ENV=test
APP_SECRET=worktree-placeholder-secret
DOMAIN=evetools.local
TLS_EMAIL=worktree@evetools.local
POSTGRES_USER=placeholder
POSTGRES_PASSWORD=placeholder
POSTGRES_DB=placeholder
RABBITMQ_USER=placeholder
RABBITMQ_PASSWORD=placeholder
EVE_CLIENT_ID=placeholder
EVE_CLIENT_SECRET=placeholder
EVE_CALLBACK_URL=https://evetools.local/api/auth/eve/callback
ESI_TOKEN_ENCRYPTION_KEY=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
JWT_PASSPHRASE=placeholder
MERCURE_URL=http://127.0.0.1:1/.well-known/mercure
MERCURE_PUBLIC_URL=https://evetools.local/.well-known/mercure
MERCURE_JWT_SECRET=worktree-placeholder-mercure-secret-0000000000
VAPID_PUBLIC_KEY=placeholder
VAPID_PRIVATE_KEY=placeholder
VAPID_SUBJECT=mailto:worktree@evetools.local
LOCK_DSN=flock
ENV
}

[[ $# -ge 3 ]] || usage
worktree="$(cd "$1" && pwd)"
mode="$2"
shift 2

[[ "$worktree" != "$main_repo" ]] || { echo "$worktree est le dépôt principal : utiliser docker compose exec" >&2; exit 65; }
git -C "$main_repo" worktree list --porcelain | grep -qx "worktree $worktree" \
  || { echo "$worktree n'est pas un worktree de $main_repo" >&2; exit 65; }

write_placeholder_env "$worktree"

run_args=(
  --rm --user "$(id -u):$(id -g)"
  -e HOME=/tmp
  -e DEFAULT_URI=https://evetools.local
  -e ESI_BASE_URL=https://esi.evetech.net/latest
  -e CORS_ALLOW_ORIGIN=x
  -e JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
  -e JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
  -e MESSENGER_TRANSPORT_DSN=in-memory://
  -e LOCK_DSN=flock
  -v "$worktree":/app -v "$main_repo/vendor":/app/vendor:ro -w /app
  --entrypoint php
)

case "$mode" in
  unit)
    run_args+=(
      -e DATABASE_URL='postgresql://unit:unit@127.0.0.1:1/unit?serverVersion=16'
      -e REDIS_URL=redis://127.0.0.1:1
    )
    ;;
  integration)
    if ! git -C "$worktree" diff --quiet "$(git -C "$main_repo" rev-parse main)" -- migrations/; then
      echo "Le worktree modifie migrations/ : la base eve_app_test partagée ne correspond pas. Lancement par la session principale." >&2
      exit 66
    fi
    DATABASE_URL="$(docker compose -f "$main_repo/docker-compose.yaml" exec -T app printenv DATABASE_URL)" \
      || { echo "DATABASE_URL illisible dans le conteneur app (stack arrêtée ?)" >&2; exit 67; }
    [[ -n "$DATABASE_URL" ]] || { echo "DATABASE_URL vide dans le conteneur app" >&2; exit 67; }
    export DATABASE_URL
    run_args+=(--network "$compose_network" -e APP_ENV=test -e DATABASE_URL -e REDIS_URL=redis://redis:6379)
    ;;
  *) usage ;;
esac

exec docker run "${run_args[@]}" "$dev_image" "$@"
