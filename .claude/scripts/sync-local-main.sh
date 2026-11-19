#!/usr/bin/env bash
set -euo pipefail

readonly repo="/home/sdu/Documents/projects/perso/evetools"
cd "$repo"

git fetch -q origin
current="$(git rev-parse HEAD)"
target="$(git rev-parse origin/main)"

[[ "$current" != "$target" ]] || { echo "local déjà à jour ($(git log --oneline -1))"; exit 0; }
git symbolic-ref -q HEAD >/dev/null && { echo "checkout principal sur une branche ($(git branch --show-current)), pas de mise à jour" >&2; exit 1; }
readonly generated_files=(config/reference.php frontend/package-lock.json)
git diff --quiet -- "${generated_files[@]}" || git restore -- "${generated_files[@]}"
git diff --quiet && git diff --cached --quiet || { echo "checkout principal modifié, pas de mise à jour" >&2; exit 1; }

changed="$(git diff --name-only "$current" "$target")"
git switch -q --detach "$target"

if grep -q '^composer.lock$' <<<"$changed"; then
  docker compose exec -T app composer install --no-interaction -q
fi
if grep -q '^migrations/' <<<"$changed"; then
  docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction -q
fi
if grep -q '^frontend/package-lock.json$' <<<"$changed"; then
  docker compose exec -T frontend npm ci --no-audit --no-fund >/dev/null
  git checkout -q -- frontend/package-lock.json
fi
if grep -qE '^(src|config)/' <<<"$changed"; then
  docker compose restart worker >/dev/null
fi
echo "local mis à jour : $(git log --oneline -1)"
