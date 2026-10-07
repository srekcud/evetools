# EVE Tools - Contexte Projet

## Description

Application web d'utilitaires pour EVE Online :
- Gestion de flottes de minage
- Projets industriels
- Calcul de gains PVE
- Alertes intel

## Stack Technique

- **Backend**: Symfony 7.4 LTS + API Platform 4.2
- **Frontend**: Vue.js 3.5 + Vite + Tailwind CSS 4
- **Runtime**: FrankenPHP 8.5 Alpine
- **Database**: PostgreSQL 16
- **Queue**: RabbitMQ (Symfony Messenger)
- **Cache**: Redis
- **Auth**: JWT + OAuth2 EVE SSO
- **Real-time**: Mercure (SSE via FrankenPHP)

---

## Environnement de développement

**IMPORTANT**: PHP n'est PAS installé sur la machine locale. Toutes les commandes PHP/Symfony doivent être exécutées via Docker :

```bash
docker compose exec app php bin/console <commande>
```

---

## API EVE Online (ESI)

- **Base URL**: `https://esi.evetech.net/latest`
- **Auth**: OAuth 2.0 via EVE SSO, `Authorization: Bearer <token>`
- **Rate Limit**: **420** = budget d'erreurs épuisé (`x-esi-error-limit-*`), **429** = rate limit. Support ETag / Cache-Control. Comportement du client : `.claude/skills/esi-api/SKILL.md`.
- **Pagination**: `page` param + header `X-Pages` → toujours `EsiClient::getPaginated()` (un `get()` ne lit que la page 1)
- **27 scopes** (`AuthenticationService::REQUIRED_SCOPES`) : assets, wallet, contracts, industry, mining, blueprints, skills, location, fleet, structures, search, notifications, killmails, market, UI, corp projects, PI

---

## Static Data Export (SDE)

```bash
# JSON Lines (recommandé - streaming)
https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip
```

Tables importées : types, groups, categories, marketGroups, map (regions/constellations/systems/jumps), stations, blueprints, industryActivity (materials/products/skills), planet schematics. Entités Doctrine dans `src/Entity/Sde/`.

Hiérarchie : `categories → groups → types`

---

## Commandes utiles

```bash
# Docker
make up / make down / make logs / make shell

# Database
make db-migrate / make db-create

# SDE
make sde-import

# Tests
make test               # toutes les suites, sans couverture (Integration nécessite make test-db)
make test-unit
make test-db            # (re)crée la base de test eve_app_test
make test-integration
make infection          # mutation testing (FILTER=src/...)
make deptrac            # rapport de couches (n'échoue jamais)

# Messenger
make messenger

# Deploy
make deploy          # Standard
make deploy-full     # Rebuild image base + deploy
make base-build      # Rebuild image base uniquement
```

**Architecture Docker** :
- `evetools-base:latest` — Image de base (PHP, extensions, Composer). Rebuild rare.
- `evetools-app` — Image applicative. `app` et `worker` partagent la même image.

**IMPORTANT**: Lors de changements impliquant des seeds, fixtures ou commandes console, toujours rappeler à l'utilisateur d'exécuter ces commandes en production après le déploiement.

---

## Préférences Git

- **NE PAS ajouter de "Co-Authored-By: Claude"** dans les commits
- Format de version : `V0.x` (ex: V0.1, V0.2, V0.10)

---

## Règle absolue — Ne jamais deviner

**JAMAIS deviner** un nom de queue, de config, d'endpoint, de table, de variable d'env, de méthode, etc. **TOUJOURS vérifier dans le code source** (config/, .env, entities, etc.) AVANT de répondre à l'utilisateur ou d'écrire du code. Lire le fichier de config pertinent prend 2 secondes, deviner et se tromper fait perdre du temps.

---

## Documentation de référence

- [`docs/glossary.md`](docs/glossary.md) — langage ubiquitaire (FR + EN)
- [`docs/adr/`](docs/adr/) — décisions d'architecture
- [`docs/quality/baseline.md`](docs/quality/baseline.md) — métriques de qualité de référence

### Règles

- **Un symbole métier porte un nom du glossaire.** Si le terme manque, proposer de l'ajouter au glossaire plutôt qu'inventer un synonyme.
- **Pas de `|| true`, pas de `?? 0` ni de catch silencieux sur une donnée métier.** Une donnée ESI manquante doit remonter une erreur explicite ou un état « inconnu », jamais une valeur par défaut plausible.

---

## Préférences de développement

- **Toujours utiliser API Platform** pour les endpoints API, jamais de contrôleurs Symfony classiques
- POST sans body : `input: EmptyInput::class` (pas `input: false`)
- DELETE : toujours fournir un `provider` qui renvoie la resource
- ApiResources sans identifiant : pas de `$id` avec `#[ApiProperty(identifier: true)]`
- PATCH content type : `application/merge-patch+json`

### Règles API Platform — Sub-resources (OBLIGATOIRE)

Toute opération utilisant un paramètre parent dans le `uriTemplate` (ex: `{projectId}`) **DOIT** déclarer `uriVariables` avec `Link`. Sans ça, API Platform 4 ne résout pas les variables et renvoie des erreurs 400.

```php
use ApiPlatform\Metadata\Link;

// Collection sous un parent
new GetCollection(
    uriTemplate: '/parent/{parentId}/children',
    uriVariables: ['parentId' => new Link(fromClass: ParentResource::class)],
)

// Item sous un parent (2 variables)
new Patch(
    uriTemplate: '/parent/{parentId}/children/{id}',
    uriVariables: [
        'parentId' => new Link(fromClass: ParentResource::class),
        'id' => new Link(fromClass: self::class),
    ],
)
```

### Noms de méthodes Entity — Pièges connus

- `Character` : le champ EVE est `eveCharacterId` → `getEveCharacterId()` (PAS `getCharacterId()`)
- Toujours vérifier les getters/setters existants dans l'entité avant d'écrire du code qui les appelle

---

## Mercure (temps réel)

- Hub : `/.well-known/mercure` (intégré dans FrankenPHP/Caddy)
- Backend : `MercurePublisherService` (syncStarted → syncProgress → syncCompleted/syncError)
- Frontend : `stores/sync.ts` (EventSource + token via `/api/mercure/token`)
- Topics : `/user/{userId}/sync/{syncType}`
- Types sync (`MercurePublisherService::getTopicsForUser()`) : character-assets, corporation-assets, ansiblex, industry-jobs, industry-job-completed, industry-project, pve, mining, wallet-transactions, market-structure, planetary, public-contracts, admin-sync
- Autres topics utilisateur : `/user/{userId}/alerts/planetary-expiry`, `/user/{userId}/alerts/market-price`, `/user/{userId}/notifications`
- Les syncs globales (market-jita, alert-prices, cost-indices, adjusted-prices…) n'ont pas de topic dédié : `SyncTracker` les suit et notifie via `admin-sync`

---

## Scheduler

Source : `src/Scheduler/SyncScheduler.php`.

| Tâche | Intervalle |
|-------|------------|
| Industry jobs sync | 30 min |
| PVE data sync | 1h |
| Mining ledger sync | 1h |
| Wallet transactions sync | 1h |
| Market sync (Jita + Structure) | 1h |
| Planetary colonies sync | 30 min |
| Alert prices check | 30 min |
| Public contracts (The Forge) | 30 min |
| Cost indices | 2h |
| Adjusted prices | 24h |
| Ansiblex sync | 12h |
| Purge notifications + historique marché | 1 jour |

Les assets ne sont pas planifiés : sync à la demande (refresh, ou admin via `TriggerAssetsSync`).

---

## Roadmap

### Implémenté
- V0.1–V0.6 : Auth, assets, PVE, contracts, industry, escalations, PI
- V0.7 : i18n bilingue
- V0.8 : Stack upgrade, Valuator/Appraisal, PHPStan 8, GDPR
- V0.9 : Weighted Price + Open In-Game Window
- V0.10 : Market Browser, Notifications
- V0.11 : Cost Estimation, Profit Margins, BPC Kit, Public Contracts
- V0.12 : Industry Scanner, Slot Tracker, Stockpile
- V0.13 : Group Industry, BPC Prices, Corp Assets Sharing, Scanner Favorites
- Notifications Hub (partiel) : notifications in-app, push Web (Service Worker), alertes PI, jobs terminés, alertes prix

### Planifié
- **Notifications Hub — reste** : notifications ESI in-game (scope demandé, non consommé)
- **Intel Map** : Carte 2D (pixi.js/d3.js), pathfinding Dijkstra (stargates + Ansiblex), overlays PI/industry/escalations
- **Corp Projects Dashboard** : ESI `GET /corporations/{id}/projects/`, cursor-based pagination
- **Simulateur PI** : Ranking profitabilité → Builder visuel → Templates importables JSON natif EVE
- **Comptabilité Corp** : 7 divisions wallet, journal, contrats, ordres marché. Scopes: wallet/contracts/orders corp
- **Fleet Tracker** : Suivi minage/PVE temps réel
- **Skill Planner** : Arbre compétences, prérequis par blueprint/ship

### Upgrades infrastructure
- PostgreSQL 18 (Q3 2026)
- Symfony 8.4 LTS (fin 2027) — rester sur 7.4 LTS d'ici là (ADR-0007)

---

## Points en suspens

- **Ansiblex** : la sync planifiée (12h) passe par `syncFromCharacter()` (structures corpo du main). `syncViaSearch()` reste à la demande uniquement (`POST /api/me/ansiblex/discover`).
- **Sessions PVE** : Feature supprimée (table `pve_sessions` supprimée). Ne pas implémenter `/api/pve/sessions/*`.

---

## Ressources externes

- [EVE Developers Portal](https://developers.eveonline.com/)
- [ESI API Explorer](https://developers.eveonline.com/api-explorer)
- [Static Data](https://developers.eveonline.com/static-data)
- [ESI Documentation](https://docs.esi.evetech.net/)

