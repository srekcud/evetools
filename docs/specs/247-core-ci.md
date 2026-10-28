# Spec #247 — CI du noyau : tests, deptrac et Infection sur chaque pull request

## Contexte

Au 2026-10-28, aucun workflow n'existe sous `.github/` : la suite Unit, `make deptrac` (bloquant depuis [#155](155-quality-gates.md)) et `make infection-core` (cliquet de MSI du noyau, `infection-core.json5`) ne tournent que lancés à la main. La règle R5 de #155 laissait le choix du workflow à Sylvain : GitHub Actions, deux jobs, Unit et Integration.

Mesures locales dans l'image `evetools-app-dev` (PHP 8.5, pcov), branche à jour de `main`, `.env` vide et `APP_ENV=test` :

- Unit : 11 617 tests verts en 11 s, sans autre variable d'environnement que celles de `.env.test` ;
- deptrac : `Violations 0`, `Skipped violations 33` ;
- infection-core : MSI 95 %, covered MSI 98 %, 53 s ;
- Integration : `OK (299 tests, 1328 assertions)` sur une `postgres:16-alpine` vierge, base créée puis migrée, sans Redis, RabbitMQ ni hub Mercure joignables. Le démarrage du conteneur exige cinq variables absentes de `.env.test` : `ESI_TOKEN_ENCRYPTION_KEY`, `EVE_CLIENT_ID`, `EVE_CLIENT_SECRET`, `MERCURE_URL`, `MERCURE_PUBLIC_URL`.

En environnement `test`, les pools de cache sont `cache.adapter.array` (`config/packages/cache.yaml`), la session est `mock_file` (`framework.yaml`), Messenger est `in-memory://` (`messenger.yaml`) et `LOCK_DSN` vaut `flock` (`.env.test`) : seule PostgreSQL est un service réel.

## Règles

- **R1 — Déclencheurs.** Le workflow `.github/workflows/ci.yml` tourne sur chaque pull request et sur chaque push sur `main`.
- **R2 — Job Unit, sans base.** Il enchaîne les commandes de `make test-unit`, `make deptrac` et `make infection-core`, hors `docker compose exec` : `bin/phpunit --no-coverage --testsuite=Unit`, `vendor/bin/deptrac analyse --no-progress`, `vendor/bin/infection --configuration=infection-core.json5 --threads=max --with-uncovered`. Aucun service. L'échec de l'une des trois fait échouer le job.
- **R3 — Job Integration.** Un service PostgreSQL, et lui seul. La base `eve_app_test` est créée puis migrée comme dans `make test-db`, puis `bin/phpunit --no-coverage --testsuite=Integration` tourne.
- **R4 — Une seule variable pour la version de PostgreSQL.** `matrix.postgres-version` du job Integration, posée à `16` (version de `docker-compose.yaml` et `docker-compose.prod.yml`), fixe l'image du service et le `serverVersion` de `DATABASE_URL`. C'est une matrice et non une variable `env` : le contexte `env` n'est pas disponible dans `jobs.<id>.services`. L'upgrade vers 18 (#248) ne change que cette valeur dans le workflow.
- **R5 — Runtime aligné sur les images.** PHP 8.5 et les extensions de `Dockerfile.base` (amqp, apcu, intl, pdo_pgsql, redis, sodium, uuid, zip), `memory_limit=512M` ; pcov (`Dockerfile.dev`) dans le job Unit pour Infection. Version de PHP, extensions et `ini` sont déclarées une fois, au niveau du workflow.
- **R6 — Aucun secret.** Le workflow crée un `.env` vide (`bootEnv` exige le fichier) ; les variables requises reçoivent des valeurs factices, PostgreSQL des identifiants jetables. Aucune référence à `secrets.*`.
- **R7 — Moindre privilège.** `permissions: contents: read`, `persist-credentials: false` au checkout, actions épinglées sur une version complète (`actions/checkout@v7.0.1`, `shivammathur/setup-php@2.40.0`).

## Critères d'acceptation

1. `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint .github/workflows/ci.yml` sort en 0.
2. Les trois commandes du job Unit, rejouées dans `evetools-app-dev` avec un `.env` vide et `APP_ENV=test` seul, sortent en 0.
3. Les étapes du job Integration, rejouées contre une `postgres:16-alpine` vierge, sans Redis, avec les variables `env` du job, donnent `OK (299 tests, …)` (nombre à la date de la mesure).
4. Sur la première pull request, les jobs Unit et Integration sont verts.
5. Une pull request qui ajoute une violation de couche (une classe de `src/Industry/Domain` qui instancie `App\Entity\User`) ou un mutant survivant dans le noyau qui fait passer le MSI sous le cliquet échoue sur le job Unit.
6. Passer `postgres-version` à `'18'` change l'image du service et le `serverVersion`, sans autre modification du workflow.

## Hors périmètre

- Upgrade vers PostgreSQL 18 (#248), y compris le `serverVersion=16` de `.env.test`.
- Suite Functional, PHPStan, frontend (lint, tests, build).
- Checks requis et protection de branche : réglage GitHub, à faire par Sylvain. Le check de la matrice porte la version dans son nom (`Integration (16)`) : il change au passage à 18.
- Cache Composer et artefacts des rapports Infection.
