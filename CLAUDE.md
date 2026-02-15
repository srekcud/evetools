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
# Exécuter une commande Symfony
docker compose exec app php bin/console <commande>

# Ou via make shell puis exécuter les commandes
make shell
php bin/console <commande>
```

---

## API EVE Online (ESI)

### Base URL
```
https://esi.evetech.net/latest
```

### Authentification
- OAuth 2.0 via EVE SSO
- Token en header : `Authorization: Bearer <token>`
- Scopes requis selon endpoints (ex: `esi-assets.read_assets.v1`)

### Endpoints principaux utilisés

| Catégorie | Endpoint | Usage |
|-----------|----------|-------|
| **Character** | `GET /characters/{id}/` | Info personnage |
| **Assets** | `GET /characters/{id}/assets/` | Assets personnels |
| **Assets** | `GET /corporations/{id}/assets/` | Assets corporation |
| **Universe** | `POST /universe/names/` | Résoudre type_id → name |
| **Industry** | `GET /characters/{id}/industry/jobs/` | Jobs industrie |
| **Mining** | `GET /characters/{id}/mining/` | Historique minage |
| **Skills** | `GET /characters/{id}/skills/` | Compétences |
| **Wallet** | `GET /characters/{id}/wallet/` | Portefeuille |
| **Market** | `GET /markets/{region_id}/orders/` | Ordres de marché |
| **Blueprints** | `GET /characters/{id}/blueprints/` | Blueprints |
| **Contracts** | `GET /characters/{id}/contracts/` | Contrats |
| **Killmails** | `GET /characters/{id}/killmails/recent/` | Killmails |
| **Fleet** | `GET /fleets/{id}/` | Info flotte |

### Rate Limiting
- Code **420** = rate limit dépassé
- Support ETag / Cache-Control
- Pagination via `page` param (max ~1000 items/page)

### Format
- JSON uniquement
- Multi-langue via `Accept-Language` (en, fr, de, ja, ru, zh, ko, es)

---

## Static Data Export (SDE)

### URLs de téléchargement
```bash
# JSON Lines (recommandé - streaming)
https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip

# YAML
https://developers.eveonline.com/static-data/eve-online-static-data-latest-yaml.zip
```

### Tables requises pour le projet

#### Priorité 1 - Core (Assets & Inventaire)

| Fichier | Description | Champs clés |
|---------|-------------|-------------|
| `types.yaml` | Tous les objets du jeu | `typeID`, `typeName`, `groupID`, `mass`, `volume`, `basePrice`, `marketGroupID`, `published` |
| `groups.yaml` | Groupes d'objets | `groupID`, `categoryID`, `groupName` |
| `categories.yaml` | Catégories | `categoryID`, `categoryName`, `published` |
| `marketGroups.yaml` | Hiérarchie marché | `marketGroupID`, `parentGroupID`, `marketGroupName` |

#### Priorité 2 - Localisation

| Fichier | Champs clés |
|---------|-------------|
| `mapRegions` | `regionID`, `regionName`, `x`, `y`, `z`, `factionID` |
| `mapConstellations` | `constellationID`, `constellationName`, `regionID` |
| `mapSolarSystems` | `solarSystemID`, `solarSystemName`, `security`, `factionID` |
| `mapSolarSystemJumps` | `fromSolarSystemID`, `toSolarSystemID` (stargates NPC pour pathfinding) |
| `ansiblex_jump_gates` | Jump bridges joueurs (données dynamiques, import manuel/ESI) |
| `staStations` | `stationID`, `stationName`, `solarSystemID`, `operationID` |

#### Priorité 3 - Industrie

| Fichier | Description |
|---------|-------------|
| `blueprints` | Données blueprints (manufacturing, invention) |
| `industryActivity` | Types d'activités (Manufacturing, Research, etc.) |
| `industryActivityMaterials` | Matériaux requis par blueprint |
| `industryActivityProducts` | Produits résultants |
| `industryActivitySkills` | Compétences requises |

#### Priorité 4 - Dogma (Attributs & Effets)

| Fichier | Description |
|---------|-------------|
| `dgmAttributeTypes` | Types d'attributs (DPS, HP, résistances) |
| `dgmTypeAttributes` | Attributs par item |
| `dgmEffects` | Effets des modules |
| `dgmTypeEffects` | Effets par type |

#### Priorité 5 - Référentiel

| Fichier | Description |
|---------|-------------|
| `chrRaces` | Races (Caldari, Gallente, etc.) |
| `chrFactions` | Factions NPC |
| `invFlags` | Flags de location (Cargo, CorpSAG1, etc.) |
| `eveIcons` | Icônes des items |

### Relations hiérarchiques
```
categories (Ships, Modules, Skills...)
    └── groups (Frigates, Battleships...)
            └── types (Rifter, Raven...)
```

### Changelog
Les modifications du SDE sont documentées dans `schema-changelog.yaml` disponible à :
```
https://developers.eveonline.com/static-data/tranquility/changes/<build-number>.jsonl
```

---

## Scopes OAuth2 EVE (24 scopes - lecture seule)

### Assets & Corporation
- `esi-assets.read_assets.v1` - Inventaire personnel
- `esi-assets.read_corporation_assets.v1` - Inventaire corporation
- `esi-characters.read_corporation_roles.v1` - Vérifier rôles (Director)
- `esi-corporations.read_divisions.v1` - Noms des hangars
- `esi-corporations.read_structures.v1` - Structures corp (Ansiblex)

### Wallet & Contrats (PVE)
- `esi-wallet.read_character_wallet.v1` - Journal + transactions
- `esi-contracts.read_character_contracts.v1` - Contrats (dépenses)

### Industrie & Minage
- `esi-industry.read_character_jobs.v1` - Jobs industrie perso
- `esi-industry.read_corporation_jobs.v1` - Jobs industrie corp
- `esi-industry.read_character_mining.v1` - Ledger minage perso
- `esi-industry.read_corporation_mining.v1` - Ledger minage corp
- `esi-characters.read_blueprints.v1` - Blueprints perso
- `esi-corporations.read_blueprints.v1` - Blueprints corp

### Skills
- `esi-skills.read_skills.v1` - Compétences (calculs ME/TE)

### Location & Fleet
- `esi-location.read_location.v1` - Position du joueur
- `esi-location.read_ship_type.v1` - Vaisseau actuel
- `esi-location.read_online.v1` - Status en ligne
- `esi-fleets.read_fleet.v1` - Info flotte

### Universe & Search
- `esi-universe.read_structures.v1` - Info structures (citadelles)
- `esi-search.search_structures.v1` - Recherche structures

### Intel
- `esi-characters.read_notifications.v1` - Notifications (alertes)
- `esi-killmails.read_killmails.v1` - Kills récents

### Market & UI
- `esi-markets.structure_markets.v1` - Prix en citadelle
- `esi-ui.open_window.v1` - Ouvrir fenêtre in-game

### Planetary Interaction
- `esi-planets.manage_planets.v1` - Colonies et pins PI

### Corporation Projects
- `esi-corporations.read_projects.v1` - Opportunités/projets corporation (contributions)

---

## Structure du projet

```
src/
├── ApiResource/        # Resources API Platform (DTOs)
├── Controller/
│   ├── Auth/           # OAuth EVE
│   └── Api/            # Endpoints API
├── Command/            # Console commands
│   └── SdeImportCommand.php
├── Entity/             # Doctrine entities
│   ├── User.php
│   ├── Character.php
│   ├── EveToken.php
│   ├── CachedAsset.php
│   ├── AnsiblexJumpGate.php
│   └── Sde/            # SDE entities
│       ├── InvCategory.php
│       ├── InvGroup.php
│       ├── InvType.php
│       ├── InvMarketGroup.php
│       ├── MapRegion.php
│       ├── MapConstellation.php
│       ├── MapSolarSystem.php
│       ├── MapSolarSystemJump.php
│       └── StaStation.php
├── Service/
│   ├── ESI/            # Clients API EVE
│   │   ├── EsiClient.php
│   │   ├── TokenManager.php
│   │   ├── AuthenticationService.php
│   │   ├── CharacterService.php
│   │   ├── AssetsService.php
│   │   └── CorporationService.php
│   ├── Sde/            # SDE import
│   │   └── SdeImportService.php
│   └── Sync/           # Synchronisation async
├── Message/            # Messages Messenger
├── MessageHandler/     # Handlers async
└── Scheduler/          # Tâches planifiées

frontend/
├── src/
│   ├── components/
│   ├── views/
│   ├── stores/         # Pinia stores
│   └── router/
└── vite.config.ts
```

---

## Commandes utiles

```bash
# Docker
make up              # Démarrer
make down            # Arrêter
make logs            # Logs
make shell           # Shell container

# Database
make db-migrate      # Migrations
make db-create       # Créer DB

# SDE (Static Data Export)
make sde-import      # Importer données statiques EVE

# Ansiblex Sync
php bin/console app:ansiblex:sync "character name"  # Sync manuel

# Tests
make test            # Tous les tests

# Messenger (async)
make messenger       # Consumer
```

### Mise en production (MTP)

```bash
# Déploiement standard (une seule commande)
make deploy

# Déploiement complet (rebuild image de base + deploy)
# À utiliser si changement de version PHP ou d'extensions
make deploy-full

# Rebuild image de base uniquement
make base-build
```

**Architecture Docker** :
- `evetools-base:latest` — Image de base (PHP, extensions, Composer). Rebuild rare via `make base-build`.
- `evetools-app` — Image applicative (code + vendor). `app` et `worker` partagent la même image.

**Note**: Le service `worker` réutilise l'image `evetools-app` (pas de build séparé). `make deploy` redémarre automatiquement le worker après le déploiement.

**IMPORTANT pour Claude**: Lors de changements impliquant des seeds, fixtures ou commandes console (ex: `app:seed-rig-categories`), toujours rappeler à l'utilisateur d'exécuter ces commandes en production après le déploiement.

---

## Préférences Git

- **NE PAS ajouter de "Co-Authored-By: Claude"** dans les commits
- Format de version : `V0.1.x` (ex: V0.1, V0.1.1, V0.1.2)

---

## Préférences de développement

- **Toujours utiliser API Platform** pour les endpoints API, jamais de contrôleurs Symfony classiques
- Pour les opérations POST sans body, utiliser un DTO vide (`input: EmptyInput::class`) plutôt que `input: false`
- Pour les opérations DELETE, toujours fournir un `provider` qui renvoie la resource (même minimal) - le processor doit retourner `void`
- Les ApiResources sans identifiant ne doivent pas avoir de propriété `$id` avec `#[ApiProperty(identifier: true)]` pour éviter les routes GET parasites

---

## API Endpoints

### Ansiblex Jump Gates

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/me/ansiblex` | Liste les Ansiblex de l'alliance |
| `POST` | `/api/me/ansiblex/refresh` | Lance un sync (async par défaut) |
| `POST` | `/api/me/ansiblex/refresh?async=false` | Sync synchrone |
| `GET` | `/api/me/ansiblex/graph` | Graphe pour pathfinding |

### Scheduler

| Tâche | Intervalle |
|-------|------------|
| Assets sync | 30 minutes |
| Structure owner warmup | Après chaque sync d'assets |
| Ansiblex sync | 12 heures |
| Industry jobs sync | 15 minutes |
| PVE data sync | 20 minutes |
| Mining ledger sync | 30 minutes |
| Market sync (Jita + Structure) | 2 heures |
| Wallet transactions sync | 20 minutes |
| Planetary colonies sync | 30 minutes |

---

## Migrations en attente (Production)

**Note** : Toutes les migrations ci-dessous doivent être exécutées lors du prochain déploiement (V0.8).

| Migration | Version | Description |
|-----------|---------|-------------|
| `Version20260131130430` | V0.2 | Ajoute `location_id` et `corporation_id` à `industry_structure_configs` |
| `Version20260131133840` | V0.2 | Ajoute `location_owner_corporation_id` à `cached_assets` |
| `Version20260131133934` | V0.2 | Ajoute `owner_corporation_id` à `cached_structure` |
| `Version20260131223001` | V0.2 | Ajoute `te_level` à `industry_projects` |
| `Version20260202100000` | V0.3 | Ajoute `in_stock_quantity` à `industry_project_steps` |
| `Version20260205080822` | V0.4 | Crée la table `escalations` |
| `Version20260209200000` | V0.5 | Migre jobs ESI → `industry_step_job_matches`, supprime anciennes colonnes |
| `Version20260210121432` | V0.5 | Renommage index + ajustements types |
| `Version20260210123656` | V0.5 | Ajoute `solar_system_id` aux structure configs |
| `Version20260211135848` | V0.5 | Ajoute `station_id` aux cached_industry_jobs + facility tracking |
| `Version20260211143214` | V0.5 | Ajoute `planned_structure_name` + `planned_material_bonus` aux job matches |
| `Version20260212142627` | V0.6 | Planetary Interaction : colonies, pins, routes, SDE schematics |

```bash
# Exécuter en production
php bin/console doctrine:migrations:migrate
```

### Actions post-déploiement V0.8

- [ ] **Réimporter le SDE** (JSONL, pour planet schematics)
  ```bash
  php bin/console app:sde:import --force
  ```
- [ ] **Re-authentifier les utilisateurs** (nouveau scope `esi-planets.manage_planets.v1`)
- [ ] **Seed rig categories**
  ```bash
  php bin/console app:seed-rig-categories
  ```
- [ ] **Sync Jita market** (pour peupler le cache buy prices)
  ```bash
  php bin/console app:sync-jita-market
  ```
- [ ] **Redémarrer le worker** pour les nouveaux handlers

---

## V0.6 - Module Planetary Interaction ✅

**Statut** : Implémenté

### Fonctionnalités
- Dashboard PI : vue d'ensemble de toutes les colonies par personnage
- KPI : colonies actives, extracteurs actifs/expirants/expirés, revenu estimé/jour
- Timers extracteurs temps réel avec code couleur (vert >24h, ambre <24h, rouge expiré)
- Détail colonie : extracteurs, factories (schematic SDE), stockage avec snapshot
- Tableau de production par tier (P0→P4) avec volumes et valorisation ISK Jita
- Badges planètes par type (temperate, barren, lava, ice, gas, oceanic, plasma, storm)
- Synchronisation ESI automatique (30 min) + sync manuelle
- Import SDE planet schematics (cycles, inputs/outputs)
- Notifications temps réel via Mercure

### Fichiers clés
```
Backend:
- src/Entity/PlanetaryColony.php, PlanetaryPin.php, PlanetaryRoute.php
- src/Entity/Sde/PlanetSchematic.php, PlanetSchematicType.php
- src/Repository/Planetary*Repository.php
- src/ApiResource/Planetary/ (ColonyResource, StatsResource, ProductionResource)
- src/State/Provider/Planetary/ (5 providers + mapper)
- src/State/Processor/Planetary/SyncPlanetaryProcessor.php
- src/Service/ESI/PlanetaryService.php
- src/Service/Sync/PlanetarySyncService.php
- src/Service/Planetary/PlanetaryProductionCalculator.php

Frontend:
- frontend/src/views/PlanetaryInteraction.vue
- frontend/src/stores/planetary.ts
```

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/planetary` | Liste des colonies |
| `GET` | `/api/planetary/{id}` | Détail colonie (pins, routes) |
| `GET` | `/api/planetary/stats` | KPIs globaux |
| `GET` | `/api/planetary/production` | Production par tier |
| `POST` | `/api/planetary/sync` | Synchroniser depuis ESI |

---

## V0.3 - Module LEDGER (fusion PVE + Mining) ✅

**Statut** : Implémenté

Module unifié "Ledger" fusionnant PVE et Mining :
- Route `/ledger` (remplace `/pve`)
- Dashboard avec KPI combinés, graphique stacked bar
- Mining ledger avec valorisation des minerais (prix Jita)
- Anti-double comptage via setting `corpProjectAccounting`

---

## V0.4 - Module Escalations ✅

**Statut** : Implémenté

### Fonctionnalités
- Suivi des escalations DED (3/10 à 10/10) pour 6 factions
- Timer 72h avec compte à rebours temps réel
- 3 niveaux de visibilité : Perso, Corporation, Public
- Partage WTS (format in-game) et Discord
- Page publique accessible sans authentification
- Escalations corporation visibles en lecture seule
- KPI : total, nouveau/BM, en vente, vendues
- Filtres : statut, visibilité, personnage
- Session JWT augmentée à 1 semaine

### Fichiers clés
```
Backend:
- src/Entity/Escalation.php
- src/Repository/EscalationRepository.php
- src/ApiResource/Escalation/EscalationResource.php
- src/ApiResource/Input/Escalation/CreateEscalationInput.php
- src/State/Provider/Escalation/*.php (5 providers + mapper)
- src/State/Processor/Escalation/*.php (3 processors)

Frontend:
- frontend/src/views/Escalations.vue
- frontend/src/stores/escalation.ts
```

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/escalations` | Mes escalations (filtres: visibility, saleStatus, active) |
| `GET` | `/api/escalations/corp` | Escalations corporation |
| `GET` | `/api/escalations/public` | Escalations publiques (pas d'auth) |
| `GET` | `/api/escalations/{id}` | Détail d'une escalation |
| `POST` | `/api/escalations` | Créer une escalation |
| `PATCH` | `/api/escalations/{id}` | Modifier (visibilité, BM, vente, prix, notes) |
| `DELETE` | `/api/escalations/{id}` | Supprimer |

---

## V0.8 - Stack Upgrade, Valuator/Appraisal, PHPStan 8 ✅

**Statut** : Implémenté

### Fonctionnalités
- Stack upgrade: PHP 8.5, API Platform 4.2, Tailwind CSS 4
- PHPStan level 5 → 8 : null-safety stricte, types union, assertions ESI
- GDPR/Legal compliance : page mentions légales, footer, politique cookies
- Fix Ledger : valorisation mining cohérente dashboard ↔ onglet (MiningBestValueCalculator)
- Valuator : mode Appraisal (sell/buy/split Jita), renommage Shopping List → Valuator
- Settings : choix format de date JJ/MM/AA ou MM/DD/YY
- i18n : Évaluateur (FR), Registre (FR)

### Fichiers clés
```
Backend:
- src/Service/MiningBestValueCalculator.php (valorisation mining best price)
- src/Service/ItemParserService.php (extraction parsing shopping → réutilisable)
- src/Service/JitaMarketService.php (buy + sell prices, order_type=all)
- src/State/Processor/ShoppingList/AppraiseProcessor.php
- src/ApiResource/ShoppingList/AppraisalResultResource.php
- src/ApiResource/ShoppingList/AppraisalItemResource.php

Frontend:
- frontend/src/components/shopping/AppraisalResults.vue
- frontend/src/views/ShoppingList.vue (mode toggle Appraisal/Import)
- frontend/src/composables/useFormatters.ts (date format preference)
```

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/shopping-list/appraise` | Appraise items (sell/buy/split) |

---

## Roadmap V0.9 — Quick Wins

### 1. Prix pondéré par volume (Weighted Price)
**Statut** : Planifié | **Complexité** : S

Au lieu du meilleur sell order, calculer le prix moyen pondéré en empilant les orders jusqu'à couvrir la quantité demandée. Indicateur de profondeur de marché. Warning si la profondeur est insuffisante.

**Impact** : `JitaMarketService`, `StructureMarketService`, `PlanetaryProductionCalculator`, `ShoppingTab`
**Changement** : Stocker les N meilleurs orders (pas juste le min price) dans le cache

### 2. Open In-Game Window
**Statut** : Planifié | **Complexité** : S

Boutons "ouvrir en jeu" (marché, info, contrat) sur les items, systèmes, personnages dans toute l'app. Composant réutilisable `<OpenInGameButton>`.

**Endpoints ESI** : `POST /ui/openwindow/marketdetails/`, `POST /ui/openwindow/information/`, `POST /ui/openwindow/contract/` (scope `esi-ui.open_window.v1`, déjà demandé)

---

## Roadmap V0.10 — Analytique & Intelligence

### 4. Profit Tracker Industrie
**Statut** : Planifié | **Complexité** : M

Calcul automatique du profit par item fabriqué : coût matériaux + coût job install + taxe structure vs prix de vente (wallet transactions). Historique des marges. Cross-reference jobs terminés → transactions de vente.

**Synergies** : Industry (jobs, blueprints, ME), Ledger (wallet), Market (prix)

### 5. Market Browser & Historique de prix
**Statut** : Planifié | **Complexité** : M/L

Navigateur marché intégré avec historique de prix (graphique Chart.js), spread buy/sell, volume quotidien. Comparaison Jita vs structure locale. Alertes de prix (notification quand seuil atteint).

**Endpoints ESI** : `GET /markets/{region_id}/history/` (pas besoin d'auth)
**Stockage** : Table dédiée en DB (rétention 90 jours, sync quotidien des types suivis)

### 6. Notifications centralisées
**Statut** : Planifié | **Complexité** : M

Hub unifié : timers PI expirants, jobs industrie terminés, escalations expirantes, alertes prix, notifications ESI in-game. Push browser via Service Workers.

**Endpoints ESI** : `GET /characters/{id}/notifications/` (scope déjà demandé)
**Synergies** : Mercure déjà en place pour le temps réel

---

## Roadmap V0.11 — Map & Corp

### 7. Intel Map (phases 1+2)
**Statut** : Planifié | **Complexité** : L

Carte 2D interactive de New Eden. Affichage systèmes solaires avec couleurs de sécurité. Pathfinding A→B via stargates + Ansiblex (Dijkstra). Options : éviter lowsec, préférer Ansiblex. Overlay colonies PI, structures industry, escalations.

**Données SDE** : `MapSolarSystem`, `MapSolarSystemJump`, `MapConstellation`, `MapRegion` (déjà importés)
**Rendu** : Canvas (pixi.js) ou SVG (d3.js), ~5000 systèmes

### 8. Corporation Projects Dashboard
**Statut** : Planifié | **Complexité** : M

Tableau de bord des projets corporation (feature ESI récente). Projets actifs, progression, contributions par membre, récompenses ISK. Cursor-based pagination (nouveau pattern ESI).

**Endpoints ESI** : `GET /corporations/{id}/projects/` (scope `esi-corporations.read_projects.v1`, déjà demandé)

---

## Upgrades infrastructure planifiés

- **PostgreSQL 18** : Migrer de PG16 à PG18 (attendre release stable, prévu Q3 2026)
- **Symfony 8 LTS** : Migrer de Symfony 7.4 vers Symfony 8 quand la LTS sera disponible

---

## Roadmap V1.0 — Maturité

### 9. Intel Map (phases 3+4)
Plugin logs Windows/Python (lit chatlogs EVE), intel temps réel via Mercure, threat assessment zKillboard (score de menace par pilote).

### 10. Simulateur & Templates PI
**Complexité** : M/L

**Niveau 1 — Ranking profitabilité** : Classement des produits PI (P1→P4) par ISK/jour selon les prix Jita, par type de planète.

**Niveau 2 — Builder visuel** : Placer extracteurs + processeurs + factories sur une planète virtuelle, définir les routes, voir la production théorique.

**Niveau 3 — Templates importables** : Génération de fichiers JSON au format natif EVE (importable directement dans le client). Format :
```json
{
  "CmdCtrLv": 5, "Cmt": "Robotics", "Diam": 10780.0, "Pln": 2016,
  "P": [{"H": 0, "La": 0.91761, "Lo": 1.60449, "S": 3689, "T": 2474}, ...],
  "L": [{"D": 19, "Lv": 0, "S": 15}, ...],
  "R": [{"P": [3, 2, 17], "Q": 5, "T": 3689}, ...]
}
```

Fonctionnalités :
- Export des colonies existantes (ESI → JSON template)
- Bibliothèque de templates optimisés par produit/planète
- Partage de templates entre utilisateurs
- Import en jeu sans configuration manuelle

### 11. Comptabilité Corporation
**Complexité** : L

Dashboard comptable : soldes 7 divisions wallet, journal revenus/dépenses, contrats corp, ordres marché corp. Graphiques + export CSV.

**Scopes ESI à ajouter** : `esi-wallet.read_corporation_wallets.v1`, `esi-contracts.read_corporation_contracts.v1`, `esi-markets.read_corporation_orders.v1`
**Rôles in-game** : Accountant, Junior Accountant ou Director

### 12. Fleet Tracker
**Complexité** : L

Suivi flotte minage/PVE en temps réel. Qui mine quoi, répartition des gains, loot partagé.

**Endpoints ESI** : `GET /fleets/{id}/members/` (scope `esi-fleets.read_fleet.v1`, déjà demandé)

### 13. Skill Planner
**Complexité** : M

Arbre de compétences, prérequis par blueprint/ship/module, calcul temps de training, priorités basées sur les jobs industry en cours.

**Données SDE** : `IndustryActivitySkills`, `DgmTypeAttribute`

---

## Roadmap V0.7 - Internationalisation (i18n)

**Statut** : Implémenté (V0.7.0)

Ajouter le support anglais/français à l'ensemble du site via **vue-i18n v10**.

### Architecture
- Fichiers : `frontend/src/i18n/index.ts` + `locales/fr.json` + `locales/en.json`
- Clés : `module.section.key` en camelCase (~820 chaînes)
- Switch langue : `localStorage` + détection `navigator.language`
- Sélecteur FR/EN dans la sidebar MainLayout
- Messages Mercure backend → anglais neutre, traduits côté frontend dans `useNotificationFeed.ts`

### Phases

| Phase | Contenu | Fichiers |
|-------|---------|----------|
| 1 | Infrastructure (install vue-i18n, config, sélecteur langue) | `package.json`, `main.ts`, `i18n/index.ts`, `MainLayout.vue` |
| 2 | Composables (useFormatters locale dynamique, useNotificationFeed, useProjectTime) | 3 composables |
| 3 | Backend Mercure → messages anglais (parallélisable avec P2) | `MercurePublisherService.php`, 6 sync services |
| 4 | Vues simples (Login, Dashboard, Shopping, Assets, Contracts, Characters) | 7 vues |
| 5 | Vues complexes (Industry+11 composants, Ledger+PVE, Escalations, PI, Admin) | ~20 fichiers |
| 6 | Traduction anglaise du fr.json | `en.json` |
| 7 | QA : test visuel 2 langues, nombres, dates, pluriels | - |

### Points d'attention
- Templates WTS/Discord (Escalations) : textes EVE universels, **pas à traduire**
- Noms d'items/systèmes/structures : viennent du SDE, restent en anglais
- Labels métier universels (ISK, P0-P4, m3, ME, TE, BPO, DED) : pas à traduire
- Formatage nombres : `1 000,00` (FR) vs `1,000.00` (EN) — géré par `toLocaleString(locale)`
- Chart.js labels : computed recalculés au changement de langue

---

## TODO / Points en suspens

### Ansiblex Jump Gates
**Solution implémentée** : `syncViaSearch()` permet de découvrir les Ansiblex via l'API search ESI (scope `esi-search.search_structures.v1`). Fonctionne sans rôle Director.

**Commandes** :
- CLI : `php bin/console app:test-ansiblex-discover "character name"`
- API : `POST /api/me/ansiblex/discover`

**À faire** : Ajouter un scheduler quotidien (Phase 1 du roadmap)

### Sessions PVE
**Feature supprimée** : Les sessions PVE (start/stop/tracking) ne font pas partie du périmètre de l'application. Ne pas implémenter les endpoints `/api/pve/sessions/*`.

### Synchronisation temps réel avec Mercure
**Implémenté**: Mercure est intégré via FrankenPHP pour les mises à jour en temps réel.

**Architecture**:
- Hub Mercure: `/.well-known/mercure` (intégré dans FrankenPHP/Caddy)
- Backend publie via `MercurePublisherService`
- Frontend s'abonne via `useSyncStore` (Pinia store)
- Topics: `/user/{userId}/sync/{syncType}`

**Types de sync supportés**:
- `character-assets` - Assets personnage
- `corporation-assets` - Assets corporation
- `ansiblex` - Ansiblex jump gates
- `industry-jobs` - Jobs industrie
- `pve` - Données PVE
- `market-jita` / `market-structure` - Données marché

**Fichiers clés**:
- `src/Service/Mercure/MercurePublisherService.php` - Service de publication
- `src/Controller/Api/MercureController.php` - Endpoint JWT token
- `frontend/src/stores/sync.ts` - Store Pinia pour l'état sync
- `frontend/src/composables/useMercure.ts` - Composable EventSource

**Statut**: Implémenté (Assets character/corporation)

### Rigs de structure (Industrie)

**Rigs intégrés** (Manufacturing & Reactions) :
- M-Set Material Efficiency (Raitaru)
- M-Set Time Efficiency (Raitaru)
- L-Set Efficiency (Azbel) - ME + TE combinés
- XL-Set Efficiency (Sotiyo) - ME + TE combinés
- M-Set Reactor Material Efficiency (Athanor)
- L-Set Reactor Efficiency (Tatara) - ME + TE combinés
- Laboratory Optimization (Research/Invention/Copy)

**Rigs NON intégrés** :
- Reprocessing rigs (L-Set Reprocessing Monitor I/II) - Affectent le rendement de retraitement de minerai, pas la production
- Moon Ore Reprocessing rigs

---

## Ressources externes

- [EVE Developers Portal](https://developers.eveonline.com/)
- [ESI API Explorer](https://developers.eveonline.com/api-explorer)
- [Static Data](https://developers.eveonline.com/static-data)
- [ESI Documentation](https://docs.esi.evetech.net/)
- [EVE University Wiki - SDE](https://wiki.eveuniversity.org/Static_Data_Export)
- [Fuzzwork SDE Conversions](https://www.fuzzwork.co.uk/dump/)
