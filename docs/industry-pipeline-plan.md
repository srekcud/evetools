# Industry Module — Plan d'Amelioration "Stockpile Pipeline"

> Ref: philosophie jEveAssets "build towards stockpiles, not towards batches"
> Date: 2026-02-23
> Statut: Design valide, mockups approuves

---

## Probleme

Le module Industry actuel est **projet-centrique** : l'utilisateur cree un projet (N runs d'un produit), build les composants, termine, recommence. C'est le modele "spreadsheet" classique d'EVE.

Limitations :
- Pas de vision globale du pipeline de production
- Pas de suivi des stocks intermediaires entre projets
- Pas de detection des bottlenecks avant qu'ils bloquent la production
- Analyse de profit mono-item (pas de scan batch)
- Pas de recommandation build vs buy par composant
- Pas de suivi d'utilisation des slots industrie
- Pas de suggestion de pivot quand un produit perd en rentabilite

## Vision

Transformer le module en **cockpit de production continue** : l'utilisateur gere un flux de production, les projets deviennent un outil au service de ce flux.

Principes directeurs (tires du guide jEveAssets) :
1. **Throughput > Margins** — maximiser le debit, ne jamais laisser les slots inactifs
2. **Stockpile targets** — maintenir des niveaux de stock par etape, pas des batches ponctuels
3. **Chaque etape profitable individuellement** — ne pas fabriquer un composant si l'acheter est moins cher
4. **Pivot sans penalite** — les composants intermediaires servent plusieurs produits finaux
5. **Green good, red bad** — dashboard visuel instantanement lisible

---

## Architecture du module

```
Industry (menu principal)
|
+-- Pipeline              [NOUVEAU - vue par defaut]
|   +-- Stockpile Dashboard   (stocks vs cibles, colore vert/orange/rouge)
|   +-- Pipeline Alerts        (bottlenecks, stall warnings)
|   +-- Throughput             (KPIs historiques, ISK/jour, items/semaine)
|
+-- Projects              [EXISTANT - inchange, enrichi de liens]
|   +-- Liste des projets actifs/termines
|   +-- Detail projet (steps, shopping, cost, BPC kit)
|   +-- [NEW] Lien "Create from Stockpile deficit"
|
+-- Scanner               [NOUVEAU]
|   +-- Batch Profit Scanner   ("Qu'est-ce que je produis ?")
|   +-- Buy vs Build Advisor   ("Je fabrique ou j'achete ?")
|   +-- Pivot Advisor          ("Mon produit n'est plus rentable, quoi d'autre ?")
|
+-- Slots                 [NOUVEAU]
|   +-- Slot Tracker par personnage
|   +-- Timeline Gantt 72h
|
+-- Config                [EXISTANT - inchange]
    +-- Structures
    +-- Skills
    +-- Blacklist
    +-- [NEW] Stockpile Targets (seuils d'alerte)
```

## Flux utilisateur typique

```
1. SCANNER   -> "Qu'est-ce qui est profitable ?"
                Batch Profit Scanner -> identifie Sabre, Ishtar, Jackdaw

2. SCANNER   -> "Je fabrique ou j'achete les composants ?"
                Buy vs Build -> BUILD les T2 components, BUY le Fernite Carbide

3. PIPELINE  -> "Je definis mes cibles de stock"
                Stockpile Dashboard -> importer targets depuis "10 Sabres + 5 Ishtars"

4. PROJECTS  -> "Je lance la production"
                Creer un projet depuis le deficit stockpile -> steps auto-generes

5. SLOTS     -> "Je remplis mes slots"
                Slot Tracker -> voir les slots libres, lancer les jobs

6. PIPELINE  -> "Je surveille"
                Alerts -> Tritanium critique, Tungsten Carbide epuise
                Throughput -> 6.6B ISK de profit cette semaine

7. SCANNER   -> "Le Sabre crash, je pivote"
                Pivot Advisor -> mes composants T2 matchent aussi le Heretic
```

## Connexions entre features

| Depuis | Vers | Action |
|--------|------|--------|
| Profit Scanner | Projects | "Build this" -> cree un projet pre-rempli |
| Profit Scanner | Buy vs Build | "Analyze costs" -> ouvre l'advisor pour cet item |
| Stockpile Dashboard | Projects | "Fix deficit" -> cree un projet pour combler le rouge |
| Stockpile Dashboard | Shopping (existant) | "Buy materials" -> shopping list des items en deficit |
| Pipeline Alerts | Stockpile | "View stockpile" -> scroll vers l'item en alerte |
| Pipeline Alerts | Slots | "Start Production" -> ouvre le slot tracker |
| Slot Tracker | Projects | "View project" -> lien vers le projet du job |
| Slot Tracker | Stockpile | Suggestion -> "Build X (stockpile at 34%)" |
| Pivot Advisor | Profit Scanner | Compare les marges des alternatives |
| Pivot Advisor | Projects | "Pivot" -> cree un nouveau projet avec composants reaffectes |
| Buy vs Build | Projects | "Create with optimal mix" -> projet avec blacklist auto |

---

## Phase 1 : Scanner (Batch Profit + Buy vs Build)

**Pourquoi en premier** : repond a la question fondamentale "Quoi produire ?". Pas de nouvelles entites, s'appuie sur les services existants.

### 1.1 Batch Profit Scanner

**Mockup** : `mockups/batch-profit-scanner.html`

**Concept** : Scanner tous les items manufacturables, calculer marge x volume, classer par ISK/jour potentiel.

**Backend** :
- Nouvel endpoint `GET /api/industry/profit-scanner`
  - Params : category (T1/T2/Capital/...), minMargin, minVolume, sellVenue, structureId, page
  - Itere sur les blueprints de la SDE ayant une activite manufacturing
  - Pour chaque blueprint : calcule cout materiaux (Jita weighted), job install cost, prix de vente, volume journalier
  - Retourne une liste paginee triee par ISK/jour
- S'appuie sur :
  - `ProfitMarginService` (marge par item — deja existant, a generaliser pour le batch)
  - `EsiCostIndexService` (adjusted prices + cost indices — existant)
  - `JitaMarketService` (prix materiaux — existant)
  - `StructureMarketService` (prix structure — existant)
  - `MarketHistoryService` (volume journalier — existant)
- Optimisation : pre-calculer les marges en batch plutot qu'item par item, cache Redis 1h

**Frontend** :
- Nouveau sub-tab "Scanner" dans Industry.vue
- Composant `ProfitScannerTab.vue` avec filtres, KPIs, table paginee
- Chaque ligne cliquable -> ouvre le detail Profit Margin existant
- Action "Build this" -> redirige vers creation de projet pre-rempli

### 1.2 Buy vs Build Advisor

**Mockup** : `mockups/buy-vs-build.html`

**Concept** : Pour un produit donne, comparer pour chaque composant intermediaire le cout de fabrication vs le prix d'achat.

**Backend** :
- Nouvel endpoint `GET /api/industry/buy-vs-build`
  - Params : typeId, runs, me, structureId, systemId
  - Decompose l'arbre de production (via `IndustryTreeService` existant)
  - Pour chaque noeud intermediaire :
    - Cout de build : materiaux (Jita) + job install cost
    - Prix d'achat : Jita weighted + structure price
    - Verdict : BUILD si build < buy, BUY sinon
  - Calcule 3 totaux : all-build, all-buy, optimal-mix
- S'appuie sur : `IndustryTreeService`, `IndustryCalculationService`, `ProfitMarginService`, `EsiCostIndexService`

**Frontend** :
- Composant `BuyVsBuildTab.vue` dans le sous-tab Scanner
- Product selector (reutiliser `ProductSearch.vue` existant)
- Grid de cartes composants avec verdict BUILD/BUY
- Summary panel avec optimal mix
- Action "Create Project with Optimal Mix" -> cree un projet avec blacklist auto-configuree (les items "BUY" vont dans la blacklist)

---

## Phase 2 : Stockpile Dashboard + Targets

**Pourquoi ensuite** : la feature signature du pipeline. Necessite une nouvelle entite.

### 2.1 Stockpile Targets

**Mockup** : `mockups/stockpile-dashboard.html`

**Backend** :
- Nouvelle entite `IndustryStockpileTarget`
  - `id` (UUID), `user` (ManyToOne), `typeId` (int), `targetQuantity` (int)
  - `stage` (enum : raw_material, intermediate, component, final_product)
  - `createdAt`, `updatedAt`
- CRUD API Platform standard
- Endpoint d'import : `POST /api/industry/stockpile-targets/import`
  - Input : typeId (produit final) + runs + ME
  - Utilise `IndustryTreeService` pour decomposer et generer les targets pour chaque etape
  - Merge avec targets existants (additionne si meme typeId)

### 2.2 Stockpile Dashboard

**Backend** :
- Endpoint `GET /api/industry/stockpile-status`
  - Croise les `StockpileTarget` avec les assets ESI de l'utilisateur
  - Pour chaque target : stock actuel, pourcentage, statut (green/orange/red)
  - Groupe par stage (raw_material, intermediate, component, final_product)
  - Calcule les KPIs : pipeline health %, total invested, bottleneck item, estimated outputs
- Endpoint `GET /api/industry/stockpile-shopping-list`
  - Items en deficit, tries par severite
  - Prix Jita pour cout estime du deficit

**Frontend** :
- Vue Pipeline comme nouveau sub-tab principal dans Industry.vue
- Composant `StockpileDashboard.vue` : 4 colonnes avec barres de progression
- Composant `StockpileTargetConfig.vue` dans Config (CRUD targets + import)
- Lien "Fix deficit" -> cree un projet pour les items en rouge

---

## Phase 3 : Slot Tracker

**Pourquoi apres** : qualite de vie. Les donnees ESI (`IndustryJob`) sont deja syncees. Principalement du frontend.

**Mockup** : `mockups/slot-tracker.html`

**Backend** :
- Endpoint `GET /api/industry/slots`
  - Agrege les jobs ESI actifs par personnage
  - Calcule slots utilises/total par activite (manufacturing, reaction, science)
  - Temps restant par job
  - Slots libres + suggestions depuis les stockpile targets (items les plus en deficit)
- Les donnees de slots max viennent des skills du personnage :
  - Manufacturing : Mass Production (1-5) + Advanced Mass Production (1-5) + base 1 = max 11
  - Reactions : idem avec Reactions + Advanced Reactions
  - Science : idem avec Laboratory Operation + Advanced

**Frontend** :
- Nouveau sub-tab "Slots" dans Industry.vue
- Composant `SlotTracker.vue` : KPIs globaux + cartes par personnage
- Composant `SlotTimeline.vue` : barres Gantt 72h (CSS pur, pas de lib charting)
- Suggestions liees au Stockpile Dashboard (si Phase 2 implementee)

---

## Phase 4 : Pipeline Alerts + Throughput + Pivot

**Pourquoi en dernier** : necessite les phases 1-3 comme fondation.

### 4.1 Pipeline Alerts

**Mockup** : `mockups/pipeline-alerts.html`

**Prerequis** : Stockpile Targets (Phase 2)

**Backend** :
- Service `PipelineAlertService`
  - Verifie periodiquement (scheduler 30min) les stockpile targets vs stock actuel
  - Calcule le taux de consommation (basé sur les jobs actifs et l'historique)
  - Genere des alertes : CRITICAL (<25%), WARNING (<50%), INFO (prix spikes, slots libres)
  - Estime le temps avant stall pour chaque composant
- Mercure : push les alertes en temps reel
- Notification Hub : integration avec le systeme de notifications existant

**Frontend** :
- Section dans la vue Pipeline (au-dessus du Stockpile Dashboard)
- Cartes d'alerte avec severite, impact chain, actions
- Lien vers les actions correctives (buy, start production, view stockpile)

### 4.2 Throughput Dashboard

**Mockup** : `mockups/pipeline-alerts.html` (section basse)

**Prerequis** : Historique de jobs (deja dans ESI sync)

**Backend** :
- Endpoint `GET /api/industry/throughput`
  - Params : period (7/30/90 jours)
  - Agrege les jobs termines par jour
  - Calcule revenue (prix de vente au moment de la completion), cout, profit
  - Items produits par type avec quantites
  - Utilisation slots moyenne sur la periode
- Nouvelle entite possible : `IndustryProductionLog` pour tracker les ventes reelles (optionnel, peut se baser sur les jobs ESI + prix marche)

**Frontend** :
- Section dans la vue Pipeline (sous les alertes)
- KPIs avec tendance vs periode precedente
- Bar chart CSS (jours x ISK)
- Table top produced items

### 4.3 Pivot Advisor

**Mockup** : `mockups/pivot-suggestions.html`

**Prerequis** : Stockpile Targets (Phase 2) + Profit Scanner (Phase 1)

**Backend** :
- Endpoint `GET /api/industry/pivot-suggestions`
  - Input : typeId du produit actuel
  - Decompose ses composants intermediaires
  - Cherche tous les blueprints qui utilisent les memes composants (reverse lookup dans la SDE)
  - Pour chaque candidat : calcule coverage % (stock actuel / besoin), marge, volume
  - Trie par coverage x marge
- Matrice de composants partages : quels composants sont communs entre produits

**Frontend** :
- Composant `PivotAdvisor.vue` dans le sous-tab Scanner
- Current product card avec trend de marge
- Matrice de composants partages (table)
- Cartes candidats avec coverage, missing, cout additionnel
- Action "Pivot" -> cree un nouveau projet

---

## Ce qui ne change PAS

- **Projects** (CRUD, steps, shopping, cost estimation, BPC kit, job matching) : inchange
- **Config** (structures, skills, blacklist) : inchange, on ajoute juste Stockpile Targets
- **Profit Margins existant** (mono-item) : absorbe dans le Scanner comme vue detail
- **Toutes les APIs existantes** : compatibilite complete
- **Mercure** : memes patterns (syncStarted/Progress/Completed/Error)

## Services backend existants reutilises

| Service | Utilise par |
|---------|-------------|
| `IndustryTreeService` | Buy vs Build, Stockpile import, Pivot |
| `IndustryCalculationService` | Buy vs Build, Profit Scanner |
| `ProfitMarginService` | Profit Scanner, Buy vs Build, Pivot |
| `EsiCostIndexService` | Profit Scanner, Buy vs Build |
| `JitaMarketService` | Profit Scanner, Buy vs Build, Stockpile |
| `StructureMarketService` | Profit Scanner, Buy vs Build |
| `MarketHistoryService` | Profit Scanner (volume), Pivot (volume) |
| `IndustryBlacklistService` | Buy vs Build (auto-blacklist) |
| `IndustryBonusService` | Profit Scanner, Buy vs Build |

## Nouvelles entites

| Entite | Phase | Description |
|--------|-------|-------------|
| `IndustryStockpileTarget` | 2 | Cible de stock par type et par utilisateur |
| `IndustryProductionLog` | 4 (optionnel) | Historique de production pour throughput |

## Estimation d'effort

| Phase | Backend | Frontend | Total |
|-------|---------|----------|-------|
| Phase 1 (Scanner) | Moyen (2 endpoints, reutilise services existants) | Moyen (2 composants, reutilise ProductSearch) | ~3-4 sessions |
| Phase 2 (Stockpile) | Moyen (1 entite, 3 endpoints, croisement assets) | Eleve (dashboard multi-colonnes, config targets) | ~4-5 sessions |
| Phase 3 (Slots) | Faible (1 endpoint, calcul depuis ESI jobs) | Moyen (2 composants, timeline CSS) | ~2-3 sessions |
| Phase 4 (Alerts+Throughput+Pivot) | Eleve (scheduler, Mercure, reverse SDE lookup) | Eleve (3 composants, alertes, chart) | ~5-6 sessions |
