# EVE Tools - Contexte Projet

## Description

Application web d'utilitaires pour EVE Online :
- Gestion de flottes de minage
- Projets industriels
- Calcul de gains PVE
- Alertes intel

## Stack Technique

- **Backend**: Symfony 7.4 + API Platform 4.2
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
- **Rate Limit**: Code **420** = rate limit dépassé. Support ETag / Cache-Control.
- **Pagination**: `page` param (max ~1000 items/page)
- **24 scopes** en lecture seule (assets, wallet, industry, mining, skills, blueprints, market, fleet, PI, notifications, UI, corp)

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
make test
docker compose exec app php vendor/bin/phpunit --no-coverage

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
- Frontend : `stores/sync.ts` + `composables/useMercure.ts`
- Topics : `/user/{userId}/sync/{syncType}`
- Types sync : character-assets, corporation-assets, ansiblex, industry-jobs, pve, market-jita, market-structure, mining-ledger, planetary-colonies, wallet-transactions, alert-prices, cost-indices, adjusted-prices

---

## Scheduler

| Tâche | Intervalle |
|-------|------------|
| Assets sync | 30 min |
| Industry jobs sync | 15 min |
| PVE data sync | 20 min |
| Mining ledger sync | 30 min |
| Wallet transactions sync | 20 min |
| Market sync (Jita + Structure) | 2h |
| Planetary colonies sync | 30 min |
| Alert prices check | 30 min |
| Ansiblex sync | 12h |

---

## Roadmap

### Implémenté
- V0.1–V0.6 : Auth, assets, PVE, contracts, industry, escalations, PI
- V0.7 : i18n bilingue
- V0.8 : Stack upgrade, Valuator/Appraisal, PHPStan 8, GDPR
- V0.9 : Weighted Price + Open In-Game Window
- V0.10 (en cours) : Market Browser, Profit Margins, Cost Estimation, BPC Kit

### Planifié
- **Notifications Hub** : timers PI, jobs terminés, alertes prix, notifications ESI. Push via Service Workers.
- **Intel Map** : Carte 2D (pixi.js/d3.js), pathfinding Dijkstra (stargates + Ansiblex), overlays PI/industry/escalations
- **Corp Projects Dashboard** : ESI `GET /corporations/{id}/projects/`, cursor-based pagination
- **Simulateur PI** : Ranking profitabilité → Builder visuel → Templates importables JSON natif EVE
- **Comptabilité Corp** : 7 divisions wallet, journal, contrats, ordres marché. Scopes: wallet/contracts/orders corp
- **Fleet Tracker** : Suivi minage/PVE temps réel
- **Skill Planner** : Arbre compétences, prérequis par blueprint/ship

### Upgrades infrastructure
- PostgreSQL 18 (Q3 2026)
- Symfony 8 LTS (quand disponible)

---

## Points en suspens

- **Ansiblex** : `syncViaSearch()` implémenté. TODO : scheduler quotidien.
- **Sessions PVE** : Feature supprimée. Ne pas implémenter `/api/pve/sessions/*`.
- **Corp assets partagés** : Director sync les assets corpo, choisit les divisions visibles aux membres.
- **Profit Tracker (legacy)** : Backend files conservés mais déconnectés de l'UI. Remplacé par Profit Margins.

---

## Ressources externes

- [EVE Developers Portal](https://developers.eveonline.com/)
- [ESI API Explorer](https://developers.eveonline.com/api-explorer)
- [Static Data](https://developers.eveonline.com/static-data)
- [ESI Documentation](https://docs.esi.evetech.net/)

---

## AI Coding Rules — 4 Rules of Simple Design

Follow Kent Beck's 4 Rules of Simple Design in priority order. When rules conflict, higher-priority rules always win.

### Rule 1: Passes the Tests (Highest Priority)
- Every function written or modified MUST have corresponding tests
- If modifying existing code, run existing tests first — never break them
- Write the test BEFORE or alongside the implementation, never as an afterthought
- Tests must cover: expected behaviour, edge cases, and error paths
- If unsure whether behaviour is correct, ask — do not guess
- Never mark a task as complete if tests are failing

### Rule 2: Reveals Intention
- Use descriptive, specific names. Bad: `data`, `process`, `handle`. Good: `unpaidInvoices`, `calculateShippingCost`
- Avoid over-descriptive names that repeat context already clear from the module or type signature
- Extract magic numbers and strings into named constants
- Use TypeScript types to document data shapes. Prefer `type` over `interface` (use `interface` only for declaration merging)
- Prefer named functions over inline lambdas for non-trivial logic
- Each function should do one thing. If you need "and" to describe it, split it
- Comments explain WHY, never WHAT

### Rule 3: No Duplication
- Never copy-paste logic — extract shared behaviour into a function, type, or module
- Duplication includes: repeated business rules, conditionals, data transformations, structural patterns
- When you spot duplication, refactor it — even if you didn't introduce it
- Do NOT force an abstraction when two pieces of code only look similar but represent different concepts (Rule 2 takes priority)

### Rule 4: Fewest Elements (Lowest Priority)
- Do not create abstractions for hypothetical future requirements
- Prefer functions over classes when no state management is required
- Prefer module-level functions with namespace imports over classes with methods
- Remove dead code, unused imports, and unnecessary parameters
- If an abstraction makes the code harder to follow without reducing real duplication, remove it
- One file with 3 clear functions is better than 3 files with 1 function each (unless genuinely different domains)

### Conflict Resolution
1. Working, tested code (Rule 1) > everything
2. Clarity (Rule 2) > DRY (Rule 3) — a little repetition is OK if the alternative is an unclear abstraction
3. DRY (Rule 3) > Minimalism (Rule 4) — an extra function to eliminate duplication is justified
4. Never add complexity to satisfy Rule 4

### Refactoring Loop
After every change, mentally run: (1) Tests green? Fix if not. (2) Code clearly expresses intent? Rename/restructure if not. (3) Duplication? Extract. (4) Can anything be removed without breaking rules 1-3? Remove.
