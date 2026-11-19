# Propositions d'issues — Phase 1 (consolidées, gate 1)

**Créées sur GitHub le 2026-10-06** : 2ᵉ lot T1 = #54, T2 = #55, T3 = #56, T4 = #57, M1 = #58, M4 = #59, M5 = #60 ; M2, M3 en commentaire sur #3, M6 sur #4. 1er lot : proposition n → issue #(n+1) (B1 = #2 … n° 45 = #46). I2 = #47, U3 = #48, F4 = #49, F5 = #50, F6 = #51, F7 = #52, S7 (RGPD) = #53. Les failles S1-S6 et S8 restent privées.

Fichier local non versionné. Les failles de sécurité S1 à S8 sont à part, dans `security-private.md` (pas d'issue publique détaillée). Légende : ✅ vérifié par moi dans le code. Les autres constats viennent d'un audit, avec `fichier:ligne`, et seront revérifiés au moment du correctif.
Labels proposés : type (`bug`, `investigation`, `ux`, `feature`, `perf`, `tech-debt`, `docs`) + module (`industry`, `corp-industry`, `pi`, `market`, `mining`, `pve`, `intel`, `infra`).

## P1 — Justesse des calculs Industry (cœur de l'outil)

| # | Titre | Labels | Sources |
|---|---|---|---|
| 1 | Les runs du projet sont interprétés comme une quantité (B1), et la liste d'achats divise deux fois | bug, industry | brief ✅, Industry, test-skeptic |
| 2 | Le matcher réduit l'étape aux runs déjà lancés, d'où le symptôme « seul le premier lot » | bug, industry | Industry ✅ (`IndustryJobMatcher:138-146`, `LinkJobProcessor:121-140`) |
| 3 | Les jobs annulés sont matchés comme de la production | bug, industry | Industry ✅ (pas de filtre de statut) |
| 4 | La sync d'un personnage marque « livrés » les jobs de ses alts, et leurs notifications sont perdues | bug, industry | Messenger ✅ (`IndustryJobSyncService:168-182`) |
| 5 | Trois règles de bonus de structure : arbre, étapes et liste d'achats divergent | bug, industry | Industry |
| 6 | Coût d'installation faux : taxe × index, pas de SCC, 0 si l'index ou l'adjusted price manque, 4 implémentations | bug, industry | ESI, Industry, Duplication, Messenger |
| 7 | Un prix manquant rend le matériau gratuit et gonfle la marge (Industry, scanner, contribution Group Industry) | bug, industry, market | Duplication, Industry |
| 8 | Marge Group Industry affichée 100× trop petite | bug, corp-industry | Duplication ✅ |
| 9 | Exclure un membre supprime ses contributions approuvées et son payout | bug, corp-industry | Doctrine ✅ |

## P1 — Fiabilité des syncs ESI

| # | Titre | Labels | Sources |
|---|---|---|---|
| 10 | Pagination tronquée à la page 1 : journal, contrats, minage (et la doc de la skill `esi-api` est fausse) | bug, pve, mining, infra | ESI |
| 11 | Une sync en échec est annoncée « terminée » (mining, PVE, wallet, handlers planifiés, Ansiblex) | bug, infra | ESI, Scheduler, Messenger |
| 12 | Un token révoqué n'est jamais détecté (catch mort, erreur réseau prise pour une 401, assets non planifiés) | bug, infra | ESI, Scheduler, Messenger |
| 13 | Une erreur ESI vide l'inventaire : assets supprimés avant l'appel, sans transaction ; divisions corpo perdues | bug, infra | Messenger, Doctrine |

## P2

| # | Titre | Labels |
|---|---|---|
| 14 | Quantité des réactions avec ME 10 codé en dur | bug, industry |
| 15 | `recalculateStepQuantities` écrase au lieu d'additionner un matériau partagé entre profondeurs | bug, industry |
| 16 | La formule de quantité matériau est dupliquée 4 fois (base du futur module unique, golden tests) | tech-debt, industry |
| 17 | Profit Margins : le décrypteur ignore le ME, invention comptée pour 1 succès, `ceil(1/p)`, broker fee sur une vente à un buy order | bug, industry |
| 18 | Profit Margins : deux définitions de marge sur le même écran (front profit/revenu, back profit/coût) | bug, industry |
| 19 | La liste d'achats ignore le stock partiel d'un intermédiaire et retire en silence un produit en erreur | bug, industry |
| 20 | Processors Industry : catch vides, frais en dur, ME/TE de T2 forcés, statut invalide ignoré, changer les runs efface les liens de jobs | bug, industry |
| 21 | Le scanner exclut en silence les produits hors du sync Jita | bug, market |
| 22 | Group Industry : job final forcé en ME 0 / TE 0 | investigation, corp-industry |
| 23 | Le split de jobs n'est pas testé (le test porte sur une copie divergente de l'algorithme) | tech-debt, industry |
| 24 | Client ESI : 429 ignoré, POST rejoué en GET sur un 420, en-têtes perdus, pas de retry 420 en pagination | bug, infra |
| 25 | 7 clients HTTP contournent `EsiClient` (aucune protection error-limit) | tech-debt, infra |
| 26 | Course au refresh de token entre l'app et le worker ; refresh dupliqué à environ 18 endroits | investigation, infra |
| 27 | Sync marché structure : personnage arbitraire, résultat ignoré, Mercure envoyé au mauvais utilisateur | bug, market |
| 28 | Jobs corpo lus une fois par heure (état du service jamais remis à zéro dans le worker) | bug, industry |
| 29 | Les handlers planifiés tournent dans le processus scheduler et bloquent les refresh manuels | perf, infra |
| 30 | Intervalles du scheduler : 3 sources de vérité divergentes (code, `SyncTracker`, `CLAUDE.md`) | docs, infra |
| 31 | N+1 sur la liste des projets Industry | perf, industry |
| 32 | Recherche de type par nom sans index (appraisal de 500 lignes en 10 à 25 s) | perf, market |
| 33 | Purges jamais déclenchées : listes partagées expirées, portes Ansiblex détruites toujours actives | bug, infra |
| 34 | 4 tests `MercurePublisherServiceTest` rouges sur `main` | bug, infra |

## P3

| # | Titre | Labels |
|---|---|---|
| 35 | Supprimer une structure industrielle n'a aucun effet (`hiddenByUsers` jamais lu) | bug, industry |
| 36 | Total wallet faux sans avertissement si un personnage échoue | ux, infra |
| 37 | Totaux minage incohérents entre la liste et le dashboard | bug, mining |
| 38 | N+1 Group Industry | perf, corp-industry |
| 39 | Pas de verrou sur le schedule (double exécution possible) | bug, infra |
| 40 | ETag inutile dans `getWithCache` | perf, infra |
| 41 | 9 scopes ESI demandés jamais utilisés | investigation, infra |
| 42 | Aucun test sur `EsiClient` ni sur le refresh de token | tech-debt, infra |
| 43 | Endpoints API non consommés (13 opérations) | tech-debt |
| 44 | Code mort backend : voter, message, environ 40 méthodes, champs écrits jamais lus | tech-debt |
| 45 | Ménage : composants frontend, commandes one-shot et debug, fichiers racine ; mise à jour du `CLAUDE.md` | tech-debt, docs |

## Issues du feedback testeur (brief §8)

| # | Titre | Labels |
|---|---|---|
| 46 | I2 — Création de structures indisponible puis disponible sans déploiement | investigation, industry |
| 47 | U3 — Configuration des structures industrielles difficile à trouver | ux, industry |
| 48 | F4 — Saisie de BPC T2 en lot (N BPC × R runs) | feature, industry |
| 49 | F5 — Valeur de vente estimée depuis les ordres de la station choisie | feature, industry |
| 50 | F6 — Source de prix des matériaux : « meilleur prix » ou « station de vente principale » | feature, industry, market |
| 51 | F7 — Les étapes marquées « Acheté » apparaissent dans Approvisionnement | feature, industry |

## Sécurité (privé, cf. `security-private.md`)

- S1 à S6 : correctifs directs à l'étape 5, sans issue publique détaillée.
- S7 (suppression de compte RGPD absente) peut devenir une issue publique : c'est de la conformité, pas un exploit.
- S8 (nom de personnage dans `config/services.yaml`) : à corriger, avec une décision sur la réécriture de l'historique git.

---

# Annexe — constats bruts des agents

Fichier local non versionné. Constats bruts des agents, à vérifier et dédoublonner avant le gate 1.

## Audit ESI (vague 1)

1. [bug][pve,industry,infra] Pagination tronquée à la page 1 : journal (`PveSyncService.php:198`), contrats (`:371`, `:593`), minage (`MiningSyncService.php:118`), `ScanLootContractsProcessor.php:76`, `StatsByTypeProvider.php:87` utilisent `get()` au lieu de `getPaginated()`. Dérive : `ScanContractsProcessor.php:78` et `ScanLootSalesProcessor.php:164` paginent `/contracts/`. Doc fausse : `esi-api/SKILL.md:675` (« mining non paginé »). Test : `X-Pages: 2` → entrées des 2 pages importées. `MiningSyncServiceTest:173` fige le bug.
2. [bug][pve,industry] Sync annoncée terminée malgré l'échec : catch « Global » `MiningSyncService.php:149-152` puis `lastMiningSyncAt` (`:155-157`) puis `syncCompleted` (`:167`). Même schéma en PVE (`PveSyncService.php:110-143`, `:236`, `:146`). Test : `get()` qui lève pour tous les persos → `syncError` publié et `lastSyncAt` inchangé.
3. [bug][infra] Token révoqué : `TokenManager.php:96-100` transforme toute erreur (réseau compris) en `unauthorized`, sans `previous`. Pas de gestion `invalid_grant`, pas d'état « reauth requise ». Test : 400 `invalid_grant` → token marqué ; timeout réseau → pas de 401.
4. [investigation][infra] Course au refresh app/worker : `TokenManager.php:58-95` sans verrou ni `#[ORM\Version]`. Refresh explicite dupliqué à environ 18 endroits (ex. `PveSyncService.php:195`, `WalletProvider.php:52`, `ScanLootSalesProcessor.php:91`) en plus de `EsiClient.php:299`. Test : 2 appels concurrents → 1 seul POST `/oauth/token`.
5. [bug][infra] 429 ignoré (`EsiClient.php:334-344`, pas de `Retry-After`) ; le retry d'un POST sur 420 repart en GET (`:253` → `rawGet()` `:330`) ; le retry de `get()` perd les `$extraHeaders` ; pas de retry 420 dans `getPaginated()` (`:182`).
6. [investigation][market,industry,infra] 7 clients HTTP ESI qui contournent `EsiClient` : `JitaMarketService.php:445,613,1114`, `MarketService.php:101`, `EsiCostIndexService.php:54,106`, `SyncPublicContractsHandler.php:171,230`, `ScanLootSalesProcessor.php:469`. Aucun ne gère l'error-limit ni le 420. `/latest` codé en dur à 3 endroits alors que `ESI_BASE_URL` existe. Test : règle deptrac ou d'architecture.
7. [bug][industry] Adjusted price absent mis en cache à 0.0 : `EsiCostIndexService.php:70` (`?? 0.0`). L'EIV est sous-estimée. Test : entrée sans `adjusted_price` → `getAdjustedPrice()` renvoie `null`.
8. [ux][infra] Solde wallet total faux sans avertissement : `EsiClient.php:84-106` + `WalletProvider.php:61-76` sautent les persos en échec. Test : perso en échec → « inconnu ».
9. [investigation][infra] ETag inutile dans `getWithCache` (`:128-129`, `:142`, `:407-415`).
10. [investigation][infra] 9 scopes demandés jamais utilisés (`AuthenticationService.php:27-73`).
11. [investigation][infra] Aucun test sur `EsiClient` ni sur `refreshAccessToken`.

Vérifié OK : aucun token en log ; refresh token chiffré (sodium) ; `getPaginated` lit `X-Pages` sans troncature ; le 420 fait un seul retry, sans boucle ; `syncError` présent dans les 7 syncs ; topics Mercure cohérents ; pagination `from_id` du wallet correcte.

## Test-skeptic Industry (vague 1) — entrée de l'étape 2 (caractérisation) et 4 (mutation)

Bugs ou suspicions métier :
- [bug?][corp-industry] `GroupIndustryProjectService.php:167/178` force ME 0 / TE 0 sur le job final (profondeur 0) alors que l'item est en ME 10 (`GroupIndustryProjectServiceTest.php:461-477`). À confirmer côté métier.
- [bug?][industry] `JobSplitCalculationTest.php:24-58` teste une copie privée de l'algorithme de split, et cette copie diverge de `IndustryProjectFactory::splitLongJobs` (`:478-525`) : reste en fin de liste dans le test, répartition équilibrée en production. Aucun test réel du split.
- [bug?][industry] `IndustryTreeService.php:132-135` duplique la formule de `IndustryCalculationService::calculateMaterialQuantity`, sans test de cohérence entre les deux.
- [cleanup][industry] Paramètre `$isRoot` mort dans `IndustryTreeService::buildNode` (`:36`, `:44`).

Tests complaisants (à reprendre en caractérisation) :
- `IndustryTreeServiceTest` : lignes 272-278, 457-478 et 501-505 figent B1 (quantité au lieu de runs) ; `:218-236` T2 avec `assertCount` seul ; aucune division inexacte (`ceil` vs `round`).
- `ProfitMarginServiceTest:682-709` : un arbre mocké masque B1.
- `IndustryProjectFactoryTest:509-593` : le multiplicateur de skill n'est pas stubbé (mock à 0.0, donc `timePerRun = 0`), les assertions sont des `assertCount`.
- `IndustryCalculationTest:46-49` : `calculateRuns()` est un `ceil` local, donc tautologique.
- Assertions faibles : `BatchProfitScannerServiceTest:160-171`, `:536` ; `PivotAdvisorServiceTest:210-230` ; `ProfitMarginServiceTest:419-428`, `473-474`, `636-644` ; `IndustryStepCalculatorTest:256`, `396-400`.
- Cas absents : `StockpileService` (bornes à 50 %, `deficitCost`), `PlanetaryProductionCalculator` (inputs, delta, valeurs non entières), ME/TE extrêmes, catégorie propre à chaque matériau dans les bonus, `?? 1` de `IndustryStepCalculator:130`.
- `EsiCostIndexServiceTest:104`, `:156` figent la sous-estimation de l'EIV : à réécrire après la décision sur l'EIV.

Hotspots de mutants échappés :

| Méthode | Échappés |
|---|---|
| `IndustryBonusService::getRigOptions` | 142 (table de constantes, faible valeur) |
| `PivotAdvisorService::analyze` | 137 |
| `BuyVsBuildService::analyze` | 107 |
| `BatchProfitScannerService::scan` | 105 |
| `PivotAdvisorService::buildSourceProductSummary` | 75 |
| `StockpileService::getStockpileStatus` | 68 |
| `PlanetaryProductionCalculator::calculateProduction` | 56 |
| `IndustryProjectFactory::sortAndCreateSteps` | 48 |
| `IndustryTreeService::buildNode` | 36 |
| `IndustryStepCalculator::recalculateStepQuantities` | 31 |

## Audit Industry (vague 1)

### Les appelants de B1
Ils passent tous des **runs** :
- `IndustryProjectFactory:51/92` ;
- `RegenerateStepsProcessor:60` ;
- `ProjectProvider:57` ;
- `StockpileService:39/85` ;
- `ProfitMarginService:61` ;
- `BuyVsBuildService:65` ;
- `GroupIndustryProjectService:88`.

`IndustryShoppingListBuilder:104/124` est encore plus touché : il passe des runs d'étape **déjà divisés**, donc `ceil(ceil(r/o)/o)`, une double division.

`CreateProjectProcessor:122` lit `runs` comme des runs (`price*runs*outputPerRun`). Le même champ a donc deux sens selon l'endroit.

### Symptôme « seul le premier lot est généré »
Deux causes possibles :
- (a) B1 lui-même ;
- (b) `IndustryJobMatcher.php:143-146` `adaptStepRuns()` réduit l'étape au total des jobs ESI déjà matchés (un job de 5 runs sur 10 → l'étape passe à 5), puis propage la réduction via `recalculateStepQuantities` (:152). `LinkJobProcessor.php:121-140` duplique cette logique.

⚠️ Le matcher est la fonctionnalité la plus appréciée des testeurs : caractériser avant de toucher.

### Propositions
- **P1 [security][industry] — à traiter EN PRIVÉ** : `LinkJobProcessor.php:67` `findByJobId()` (`CachedIndustryJobRepository.php:23-26`) ne filtre pas sur les personnages de l'utilisateur. On peut lier le job d'un autre utilisateur et voir le nom du personnage, le coût et le lieu d'installation (:88-98).
- **P1 [bug][industry]** Le matcher interprète un avancement partiel comme un changement de plan (b).
- **P1 [bug][industry]** Les jobs annulés sont matchés : `include_completed=true` (`IndustryJobSyncService.php:57`), sans filtre de statut dans `findManufacturingJobsByBlueprint` (:90-108).
- **P1 [bug][industry]** Trois règles différentes pour le bonus de structure :
  - arbre : catégorie du matériau, avec `isReaction=false` en dur (`IndustryTreeService:119-125`) ;
  - factory : catégorie du consommateur (`Factory:318-330`) ;
  - `getStructureBonusForStep` : système favori d'abord (`CalculationService:128`).

  L'arbre, les étapes et la liste d'achats divergent.
- **P1 [bug][industry]** La formule de quantité matériau est dupliquée 4 fois : `Tree:132`, `Factory:335`, `CalculationService:87`, `BatchProfitScanner:298` (ce dernier sans structure ni `round`).
- **P2 [bug][industry]** Quantité des réactions avec ME 10 codé en dur (`Factory:312`), même à la profondeur 0 où le ME vient de l'utilisateur.
- **P2 [bug][industry]** `recalculateStepQuantities` réinitialise `$neededByTypeId` à chaque profondeur (:79), donc écrase au lieu d'additionner pour un matériau partagé. `max(1,…)` sur le dernier split (:164) peut dépasser le total.
- **P2 [bug][industry]** Coût d'installation : `eiv*runs*index*(1+tax)` au lieu de `eiv*(index+tax)` (`EsiCostIndexService:273`) ; pas de surcharge SCC ; index manquant → 0.0 (:268). Formule réimplémentée dans `InventionService:79,297` et `BatchProfitScanner:311` (sans taxe).
- **P2 [bug][industry]** Profit Margins :
  - le décrypteur ne modifie pas le ME du coût matériaux (`ProfitMarginService:156`) ;
  - l'invention est comptée pour 1 seul succès quel que soit le nombre de runs (:131) ;
  - `ceil(1/p)` (`InventionService:270`) ;
  - un prix manquant vaut 0.0 (`ProfitMargin:85`, `InventionService:263`) ;
  - frais de courtage appliqués à `structureBuy` (:453).
- **P2 [bug][industry]** La liste d'achats ignore le stock partiel d'un intermédiaire (`ShoppingListBuilder:191-201`). Le `catch RuntimeException { continue; }` (:112, :133) retire en silence un produit racine.
- **P3 [bug][industry]** Processors :
  - `catch (\Throwable)` vides (`CreateProjectProcessor:78,85,132`) ;
  - taux 4,6 % et 6,1 % codés en dur (:128) ;
  - ME/TE de toute T2 forcés (:56-59), en ignorant la saisie de l'utilisateur ;
  - statut invalide ignoré en silence (`UpdateProjectProcessor:77`) ;
  - un changement de runs → `regenerateSteps` supprime étapes, liens de jobs, achats et splits (`Factory:86-89`).
- **P3 [tech-debt][industry]** Conventions et code mort :
  - aucune ressource Industry ne déclare `uriVariables`/`Link` (ex. `ProjectStepResource.php:40-65`) ;
  - `getDefaultIntermediateTE()` (:496) n'a aucun appelant ;
  - `$te` inutilisé dans `StockpileService` ;
  - un GET sur un projet reconstruit l'arbre avec la config du moment (`ProjectProvider:55-63`).

Vérifié OK :
- pas de ME sur les réactions ;
- défauts ME 10 / TE 20 cohérents ;
- bonus de base et de rig cumulés de façon multiplicative ;
- propriété du projet et des étapes vérifiée ;
- `regenerateSteps` publie `syncError` ;
- un job n'est pas compté dans deux projets.

## Audit duplication prix/coûts (vague 1)

Matrice (résumé) : chaque concept existe en plusieurs versions qui ne s'accordent pas entre elles.
- **Jita sell** : 3 implémentations, dont un 2ᵉ fetch avec sa propre clé de cache. Le sync de fond ne couvre que les matériaux, donc un produit fini vaut `null` sans fallback. Appelants sans fallback : `BatchProfitScanner`, `PivotAdvisor`, `Stockpile`, `PI`, `OreValue`, `CreateProject`, et le décrypteur dans `InventionService`.
- **Ravworks 5 %** : calculé sur les 20 ordres conservés, pas sur le carnet complet.
- **EIV** : 3 définitions, dont `InventionService:567`, basée sur l'adjusted price du **produit**, ce qui contredit le glossaire.
- **Job install** : la taxe de structure est omise dans `BuyVsBuild`, `Pivot` et le scanner. 4 formules différentes de coût matériaux.
- **Broker / taxe** : 3 sources, en ratio dans un cas, en pourcentage dans un autre, et en dur dans un 3ᵉ.
- **Transport** : traité de façon différente selon l'écran.
- **Marge %** : 3 définitions : profit/coût (backend), profit/revenu (`ProfitMarginTab.vue:89`), ratio 0..1 (`GroupIndustry`).
- **Valeur minage** : 3 copies, deux dans le backend et une dans le frontend.

Code mort :
- `OreValueService::getOrePrices` ;
- `JitaMarketService::getBuyPrice` et `getWeightedBuyPrice` ;
- `GroupIndustryContributionService::submitAutoDetected` (appelée seulement par les tests) ;
- branche `jitaBuy` dans `ProfitMarginTab.vue:129` ;
- barèmes de location de ligne stockés mais jamais utilisés.

Le Profit Tracker legacy a déjà été supprimé : la section « Points en suspens » du `CLAUDE.md` est périmée.

Propositions :
- **P1 [bug][corp-industry] ✅ vérifié** : marge Group Industry affichée 100× trop petite. `GroupIndustryDistributionService.php:38-40` renvoie un ratio, que `FinancialSummary.vue:112` affiche avec `toFixed(1)%` (58 % s'affiche « 0.6% »). Le payout utilise bien le ratio, donc il est correct. `GroupIndustryDistributionServiceTest.php:150` fige le défaut.
- **P2 [bug][industry]** Deux définitions de marge sur le même écran Profit Margin : front `profit/revenue` contre back `profit/totalCost` (`InventionOptionsPanel.vue:176` / `:218`).
- **P3 [bug][market,industry]** Prix absent → matériau gratuit → marge gonflée : `ProfitMargin:85`, `ProductionCost:54`, `BuyVsBuild:126`, `BatchProfitScanner:300`, `Pivot:171`, `calculateMaterialValue:172`. En Group Industry, la contribution vaut alors 0 et le payout aussi.
- **P4 [bug][market]** Le scanner exclut en silence les produits absents du sync Jita (`BatchProfitScanner:237`, `:334`).
- **P5 [bug][industry]** Coût d'installation différent selon l'écran (cf. matrice).
- **P6 [bug][industry]** Frais en dur à la création de projet (`CreateProjectProcessor:128`), plus des catch silencieux (doublon de l'audit Industry).
- **P7 [bug][market]** Totaux minage incohérents : `MiningBestValueCalculator:147` utilise les paramètres par défaut, pas ceux de l'utilisateur, et `MiningTab.vue:189` mélange deux bases de calcul.
- **P8 [bug][industry]** Broker fee appliqué aux ventes à un buy order (`ProfitMarginService:374`) (doublon de l'audit Industry).

Phase 3 : un module Pricing posséderait :
- `PriceQuote`, avec une absence explicite à la place de 0 ;
- une seule source d'order books ;
- un seul calcul d'EIV et de coût d'installation ;
- une `FeePolicy` et une `TransportPolicy` ;
- une seule définition de marge ;
- la valorisation du minerai.

## Audit Scheduler (vague 2)

Intervalles réels, tirés du code (`SyncScheduler.php`), comparés au `CLAUDE.md` :

| Sync | `CLAUDE.md` | Code |
|---|---|---|
| assets | 30 min | **non planifié** (retiré dans 5f732dc pour éviter les 420) |
| industry | 15 min | **30 min** |
| PVE | 20 min | **1 h** |
| mining | 30 min | **1 h** |
| wallet | 20 min | **1 h** |
| Jita | 2 h | **1 h** |
| structure | 2 h | **1 h** |
| PI | 30 min | 30 min, identique |
| alertes | 30 min | 30 min, identique |
| Ansiblex | 12 h | 12 h, identique (le TODO du `CLAUDE.md` est obsolète) |

Absents du `CLAUDE.md` : adjusted prices 24 h, cost indices 2 h, contrats publics 30 min, purges quotidiennes. → La table du `CLAUDE.md` est à corriger.

Propositions :
- **[bug][infra]** Assets retirés du scheduler, mais `SyncTracker.php:19` attend toujours 1800 s. L'import est inutilisé (`SyncScheduler.php:14`). `cost-indices` et `adjusted-prices` ne sont pas suivis. 3 sources de vérité pour les intervalles. Test : chaque clé de `EXPECTED_INTERVALS` doit correspondre à un message planifié, avec le même intervalle.
- **[perf][infra]** Les messages planifiés sont traités en ligne dans le processus scheduler (`ReceivedStamp`, donc pas de renvoi vers `async`). Les handlers bouclent sur tous les personnages avec `usleep(500ms)` (`SyncIndustryJobsHandler:33-49`, wallet, PI) et bloquent les refresh manuels pendant ce temps.
- **[bug][infra]** Pas de `->lock()` sur un schedule `stateful` (`SyncScheduler.php:39`). `make scheduler` lance un 2ᵉ consommateur en dev, d'où des syncs doublées.
- **[bug][infra]** Token révoqué non détecté hors assets : seul `SyncCharacterAssetsHandler:64` appelle `markAuthInvalid()`. Comme les assets ne sont plus planifiés, un token révoqué reste valide pour les autres syncs. `TriggerStructureMarketSyncHandler:36,44` prend un personnage arbitraire (`[0]`). Lié à l'issue ESI n° 3.
- **[bug][infra]** Erreurs avalées dans les handlers Jita, alertes, cost indices, adjusted prices et purges : le statut final est `complete` même avec `0/12 chars synced`. `TriggerPlanetarySyncHandler` n'a aucun suivi d'erreur. Lié à l'issue ESI n° 2.

Vérifié OK : transport scheduler consommé en prod ; état dans Redis avec `processOnlyLastMissedRun` ; pas de chevauchement avec 1 seul worker ; filtre sur les utilisateurs actifs depuis 7 j. Aucun test sur `SyncScheduler` ni sur ces handlers.

## Audit Messenger (vague 2)

- **P1 [bug][infra]** Un token révoqué n'est jamais marqué invalide. Aucun code ne lève `EveAuthRequiredException`, alors que `TokenManager:98-99` lève `EsiApiException::unauthorized`. Les catch prévus pour ce cas sont donc morts (`SyncCharacterAssetsHandler:58-64`, `SyncCorporationAssetsHandler:52`, `SyncAnsiblexGatesHandler:55`). Effet : 3 retries puis queue `failed`, 4 couples start/error côté frontend, et un retry toutes les 30 min sans fin. **Fusion avec l'issue ESI n° 3 et l'issue Scheduler sur les tokens révoqués : même cause.**
- **P1 [bug][industry]** La sync d'un personnage marque `delivered` les jobs `active`/`ready` de ses alts (`IndustryJobSyncService:169-185`). Les notifications « Job completed » des alts sont perdues (`:130`, `:143`). ⚠️ Touche le matcher et le suivi des jobs.
- **P1 [bug][industry]** Coût d'installation à 0 ISK sans avertissement : TTL du cache = intervalle du scheduler (`EsiCostIndexService:27,31` vs `SyncScheduler:79,83`) + handlers qui avalent l'erreur + `return 0.0` (`:263`, `:268`). Recoupe les issues ESI n° 7 et Industry (coût d'installation).
- **P2 [bug][assets]** Assets supprimés avant l'appel ESI, hors transaction (`AssetsSyncService:45,129` puis `:48,132`). Une erreur ESI laisse un inventaire vide.
- **P2 [bug][wallet]** Échecs de sync wallet comptés comme des succès (`WalletTransactionSyncService:120-131`, `SyncWalletTransactionsHandler:40-41`). Un flush en échec ferme l'EntityManager, et tous les personnages suivants échouent en silence.
- **P2 [bug][market]** Sync marché structure : `$characters[0]` pris au hasard, sans vérifier le scope ; statut `complete` posé avant la sync (`:66`) ; `success:false` ignoré ; Mercure envoyé à un utilisateur arbitraire. Recoupe le Scheduler.
- **P2 [bug][industry]** `syncedCorporations` n'est jamais remis à zéro dans le worker (`IndustryJobSyncService:22,72,80`, pas de `ResetInterface`). Les jobs corpo ne sont lus qu'une fois par heure.
- **P3 [chore]** `CheckMarketAlerts` est routé mais jamais dispatché. `SyncAnsiblexGatesHandler:60-73` avale tout, y compris les 420. Tests sans valeur : `IndustryJobSyncServiceTest:291`, `WalletTransactionSyncServiceTest:323` (fige le bug).

Vérifié OK : les 24 messages sont routés vers `async` ; ils transportent des ids ; wallet idempotent (contrainte d'unicité) ; pas de notification dupliquée au rejeu. Aucun usage de `UnrecoverableMessageHandlingException`, donc les 4xx partent en retry 3 fois.

## Audit Doctrine (vague 2)

Schéma synchronisé avec le mapping (`schema:validate` OK, `--dump-sql` vide).

- **P1 [bug][corp-industry]** Exclure un membre supprime en silence ses contributions approuvées (`GroupIndustryContribution.php:31-32` en `onDelete CASCADE`). `KickGroupMemberProcessor:84-85` n'a aucune garde, alors que `LeaveGroupProjectProcessor:69-72` en a une. Le `fulfilledQuantity` du BOM reste incrémenté, et le payout du membre disparaît. Viole la règle « une contribution approuvée ne diminue plus ». Aucun test de `KickGroupMemberProcessor`.
- **P2 [bug][assets]** DELETE en autocommit avant l'appel ESI (doublon de l'audit Messenger, à fusionner). Aussi : un échec de `getDivisions()` est seulement logué (`AssetsSyncService:114-121`), les assets corpo n'ont alors pas de `divisionName`, et les divisions partagées deviennent invisibles.
- **P2 [perf][industry]** N+1 sur la liste des projets (`ProjectCollectionProvider:35-43`, `IndustryResourceMapper:139,177`, `getJobsCost`) : environ 1+P+S requêtes, soit environ 84 avec les données locales.
- **P2 [perf][corp-industry]** N+1 sur Group Industry (`GroupIndustryResourceMapper.php:25,37,48-50,56,75-85`) : environ 5 requêtes par projet.
- **P2 [perf][sde]** Recherche par nom de type : seq scan sur 51 135 lignes (10 à 28 ms), sans index sur `type_name` ni sur `lower(type_name)`. Appelée en boucle par `ItemParserService:80-89`, `ApplyStockProcessor:64` et `CreateGroupProjectProcessor:105`. Un appraisal de 500 lignes prend 10 à 25 s.
- **P3** Purge du marché implémentée 3 fois ; la purge admin (`TriggerSyncProcessor:154-157`) oublie les snapshots. L'import SDE se fait sans transaction (tables vides pendant l'import). Index manquants : `characters.corporation_id`, `industry_step_job_matches(status,end_date)`. Index en double : `cached_structure.structure_id`.

Vérifié OK : index des tables chaudes ; déduplication wallet en lot ; notifications paginées ; purge quotidienne ; cascade sûre à la suppression d'un personnage ; aucun chemin de suppression d'un User (cf. RGPD S7).

## Triage fil Discord (2026-02 → 2026-08) — à valider avant création

Nouvelles issues publiques proposées :
- **T1 [investigation][infra]** Assets corpo : le contenu des containers est absent des divisions partagées. Hypothèse : un item dans un container a `location_type=item` et un flag ≠ `CorpSAG{n}`, donc il est filtré (`CachedAssetRepository.php:115,138`) et sans nom de division (`AssetsSyncService.php:162-167`). Il faut le rattacher à la division de son container racine. À confirmer sur des données ESI réelles.
- **T2 [ux][industry]** Stock industrie : le contenu des containers perso est déjà compté (`CachedAssetRepository.php:268-301`, `StockpileService.php:139`), mais il apparaît en « Location #id » brut (`AssetsService.php:78-86,111` ignore `location_type=item`) ✅. À trancher : faut-il compter aussi les modules fittés et les soutes des vaisseaux ?
- **T3 [bug][infra]** Logs du worker : `messenger:consume … -vv` en prod (`docker-compose.yaml:54` ✅, pas de surcharge dans `docker-compose.prod.yml`), pas de `monolog.yaml` ✅, aucune rotation Docker (`logging:`/`max-size`) ✅. Incident : environ 32 Go de logs ont saturé le disque. À ajouter : rotation, verbosité normale, alerte sur l'espace disque.
- **T4 [docs][infra]** `CONTRIBUTING.md` absent : expliquer le parcours fork puis PR et revue, le lancement local avec Docker, les tests attendus et les conventions de commit.

Privé (ajouté à S6) : visibilité corpo en fail-open, à l'opposé de l'intention whitelist exprimée dans le fil ✅. Les flags hors hangar (livraisons, fourrière, service slots, wallet) sont stockés et renvoyés par les chemins non filtrés (`CachedAssetRepository.php:75-102`).

Contexte, sans nouvelle issue :
- un contributeur veut reprendre le scanner → #22, #18, #19 ;
- assets jugés instables → #12-#14 ;
- escalations capitales : PR #1 mergée.

À protéger par des tests : le choix des divisions par un directeur ; la sync avec le token du directeur qui a configuré (`AssetsSyncService.php:266-284`), avec repli sur un autre personnage ; le mapping flag → nom de division.

## Caractérisation `IndustryJobMatcher` — nouveaux constats, à valider avant création

Couverture du matcher : 0/102 → 101/102 lignes, avec 34 tests dans `tests/Unit/Service/Industry/IndustryJobMatcherTest.php`.

- **M1 [bug][industry] ✅** Le mode `manual` n'est pas respecté : seul `none` est ignoré (`IndustryJobMatcher.php:79`), et tous les matches sont supprimés avant le rematching (`:48-55`). Les liens manuels créés par `LinkJobProcessor` sont détruits à chaque relance. `manual` est pourtant une valeur autorisée (`UpdateStepInput.php:28`).
- **M2 [bug][industry]** Le fallback ajoute un job surdimensionné par-dessus des matches partiels : étape de 10 runs, jobs de 8 et 5 → 13 runs (`:119-136`). Variante de #3, à ajouter en commentaire sur #3.
- **M3 [bug][industry]** `adaptStepRuns` ne met pas à jour `totalGroupRuns` d'une étape splittée, et une étape splittée peut absorber les jobs destinés à sa sœur (`:159-173`). Lié à #3.
- **M4 [investigation][industry]** Une étape achetée perd ses matches, alors qu'une étape seulement en stock reste matchée (`:50-55`, `:73`). À trancher côté métier.
- **M5 [bug][industry]** La requête de matching inclut l'activité 9, qui n'existe pas dans `IndustryActivityType`, et exclut l'invention (8) et la copie (5). Une étape d'invention n'est jamais matchée (`CachedIndustryJobRepository.php:92`).
- **M6 [bug][industry]** La structure de l'étape est corrigée d'après la station d'un job, même annulé (`:205-218`). Aggrave #4 : à ajouter en commentaire sur #4.
- Code mort : `IndustryJobMatcher.php:127` (`continue` inatteignable).
- Reste à faire : un test d'intégration du repository (filtres de la requête, absence de filtre de statut) sur une vraie base.

## Nettoyage frontend — constats annexes (à valider avant création)

- **[bug][industry] → #61** Profit Margins : une revente instantanée sur la structure (`structureBuy`) est affichée sous le libellé « Jita », à cause du fallback de `bestVenueLabel` dans `frontend/src/components/industry/ProfitMarginTab.vue`. Laissé tel quel pendant le nettoyage.
- **[tech-debt]** `frontend/src/stores/industry/index.ts` (barrel) est probablement inatteignable : `@/stores/industry` se résout vers `stores/industry.ts`. À confirmer avant de le supprimer.

## Nouveaux constats (2026-10-07, en corrigeant) — à valider avant création

- **[bug][infra]** Les migrations ne se rejouent pas sur une base vide : `Version20260223100944` échoue (`index "idx_9299b28a613fecdf" does not exist`). La base de test est construite par `schema:create` en attendant. Risque : impossible de recréer la prod à partir des migrations.
- **[bug][industry]** Importer deux fois la même structure (même `locationId`, ligne active) crée un doublon : aucune contrainte d'unicité (user, locationId). Faut-il un 409 ou une mise à jour de la ligne existante ?
- **[investigation][corp-industry]** `AvailableGroupProjectsProvider` masque un projet dès qu'une ligne de membership existe, y compris `Removed` : un membre retiré ne le voit plus dans la découverte corpo (il peut le rejoindre par le lien court).
- **[investigation][corp-industry]** `LeaveGroupProjectProcessor` refuse le départ d'un membre qui a des contributions et fait encore un `remove()`. Faut-il l'aligner sur le retrait doux ?
- **[tech-debt][infra]** Horloge injectable dans `EsiClient` (`sleep()` direct) pour tester les durées d'attente et le plafond de 60 s.
- **[bug][infra] Dépendances : `composer audit` signale 52 avis de sécurité sur 14 paquets** (2026-10-07). À traiter en Phase 2 (/deps).
- **[bug][market]** `ContractCollectionProvider::getPublicContractsForComparison()` avale toute exception (`catch \Throwable` → warning → `[]`) : une erreur ESI fait silencieusement retomber la comparaison sur Jita (catch silencieux sur donnée métier, contraire au CLAUDE.md).
- **[bug][pve]** `SyncPveProcessor` répond toujours `status: success` même quand tous les personnages ont échoué (Mercure publie pourtant `syncError`).
- **[investigation][infra]** Statut d'auth porté par l'utilisateur et non par le personnage : un alt révoqué sort tous les personnages des syncs, un login avec le main remet l'utilisateur valide alors que l'alt reste révoqué (`TokenManager.php:127`, `AuthenticationService.php:189`).
- **[bug][infra]** Topic Mercure `/user/{id}/sync/public-contracts` déclaré mais jamais publié (`MercurePublisherService.php:289`).
- **[bug][infra]** `market-alerts` : bouton admin dont le spinner ne s'arrête jamais (aucune completion Mercure, absent de `ACTION_TO_SYNC_TYPE`).
- **[tech-debt][market]** `SyncStructureMarket` (message, handler, routage) sans producteur après PR #65.

## Nouveaux constats (2026-10-09, caractérisation) — à valider avant création
- **[bug][industry]** Stockpile : le statut se calcule sur le pourcentage brut, l'affichage et la santé de l'étape sur le pourcentage arrondi (99,96 % affiché 100.0 en « partial »). Figé par `StockpileStatusCharacterizationTest` (worktree CG).
- **[bug][industry]** Stockpile : `estOutput` exige que le produit final soit lui-même en stock.
- **[bug][industry] (vérifié)** Buy vs Build : les matériaux consommés directement par le produit final ne sont jamais pricés. `BuyVsBuildService.php:75-81` ne les met pas dans `$allTypeIds`, puis `:198` les compte `?? 0.0`. Morphite du Hail L, tous les inputs du Nitrogen Fuel Block à 0 ISK ; coût total 0. Les anciens tests le masquaient (mock qui renvoie tous les prix). Figé par `BuyVsBuildServiceCharacterizationTest` (worktree CF).
- **[bug?][industry]** Buy vs Build : les réactions utilisent le système favori de fabrication au lieu de celui des réactions.
- **[bug][industry]** Buy vs Build : sans structure préférée, l'id de station de Jita est passé comme id de structure.
- **[ux][industry]** Buy vs Build : tout composant fabriqué de profondeur 1 est libellé « T2 Component », fuel blocks compris.
- **[bug][industry]** Liste d'achats : un intermédiaire marqué acheté qui a aussi un stock partiel n'est plus traité comme acheté, tout son sous-arbre est fabriqué (`elseif` de `IndustryShoppingListBuilder.php:44-47`). Proche de #20, cause propre.
- **[bug][industry]** Liste d'achats : `totals.savingsVsJitaWithImport` devient négatif (−30 600) quand un matériau n'est vendu que dans la structure.
- **[bug][industry]** Liste d'achats : un volume manquant compte 0 m³, coût d'import nul (`ShoppingListProvider.php:91`, `:128`).
- **[bug][corp-industry]** Group Industry : un T2 (Hail L) reçoit un job de copie de son blueprint T2 et aucun job d'invention dans la BOM.
- **[bug][corp-industry]** Group Industry : un produit à la fois item du projet et intermédiaire d'un autre item partage une ligne de job, perd le groupe `final` et prend le parent de l'autre item.
- **[tech-debt][corp-industry]** Group Industry : `requiredQuantity` d'une ligne de job contient des runs (contraire au glossaire, cf. B1).
- **[note migration, pas d'issue]** `buildBom()` rappelé ajoute une seconde BOM sans effacer la première ; aujourd'hui appelé seulement à la création (`GroupIndustryProjectService.php:61`), donc latent. À traiter si la migration régénère la BOM.
- **[bug][industry]** Profit Margins : valeurs par défaut plausibles sur donnée manquante (règle CLAUDE.md) : `bestMargin` = 0.0 quand aucun prix (sentinelle -999), marge 0 quand le coût de production est nul, `dailyVolume` absent = 0. Figés comme suspects dans `ProfitMarginGoldenCharacterizationTest`.
- **[bug][industry]** Arbre de production : un nom SDE manquant devient `"Type #id"` (`IndustryTreeService.php:59`, `:138`), valeur plausible interdite par la règle du projet.
- **[bug][industry]** Étapes : l'ordre des groupes découpés suit leur UUID aléatoire, l'ordre des étapes change à chaque génération.
- **[bug][industry]** Étapes : au recalcul, un groupe découpé ne perd jamais de job (Fernite Alloy garde 35 jobs d'un run pour un total de 4).
- **[bug][industry]** Étapes : un job d'un seul run plus long que la durée max forme un groupe découpé d'un seul job.
- **[bug][industry]** Étapes : un job découpé compte runs × unités par run (990 demandées → 1000), une étape non découpée garde la quantité demandée.

## Créées sur GitHub le 2026-10-11
Constats « Nouveaux constats (2026-10-09, caractérisation) » :
- Stockpile statut brut / pourcentage arrondi → #96
- Stockpile `estOutput` exige le produit final en stock → #97
- Buy vs Build matériaux du produit final jamais pricés → #98
- Buy vs Build réactions sur le système favori de fabrication → #99
- Buy vs Build id de station Jita passé comme structure → #100
- Buy vs Build libellé « T2 Component » → #101
- Liste d'achats intermédiaire acheté + stock partiel (`elseif`) → #102
- Liste d'achats `savingsVsJitaWithImport` négatif → #103
- Liste d'achats volume manquant à 0 m³ → #104
- Group Industry T2 : copie du BP T2, pas d'invention → #105
- Group Industry ligne de job partagée item / intermédiaire → #106
- Group Industry `requiredQuantity` en runs → #107
- `buildBom()` rappelé (note migration) → pas d'issue
- Profit Margins valeurs par défaut plausibles → #108
- Arbre `"Type #id"` → #109
- Étapes ordre des groupes découpés par UUID → #110
- Étapes groupe découpé ne perd jamais de job → commentaire sur #16 (doublon partiel, `max(1, …)` déjà cité)
- Étapes job d'un run plus long que la durée max → #111
- Étapes job découpé runs × unités par run → #112
Ajout : taxe d'installation des structures non saisissable → #113
#47 : commentaire (liste corpo vide, libellé corrigé par 653e617 / #88) puis fermée (completed).

## Constats du lot de mutation (2026-10-11) — à valider avant création
- **[bug][industry]** Scanner : volumes journaliers, de matériau et d'export manquants valent 0.0 au lieu d'inconnu ; un prix de vente ou un coût à 0 retire le produit sans signalement (`BatchProfitScannerService`).
- **[tech-debt][industry]** Scanner : `$allBpIds` (`BatchProfitScannerService.php:202`) jamais utilisé.
- **[bug][market]** Contrats : un contrat sans item inclus disparaît ; `price`, `volume`, `reward`, `acceptor_id`, `for_corporation` prennent une valeur par défaut s'ils manquent (cf. aussi audit Market n° 9 pour jitaValue/delveValue partiels).

## Nouveau constat (2026-10-19, spec #73) — créé : #121

- **Liste d'achats, coût de production et arbre affiché ignorent le découpage des étapes.** `IndustryTreeService::buildProductionTree()` construit un nœud par produit sur les runs totaux (`IndustryTreeService.php:160-167`) ; `IndustryShoppingListBuilder` (l.52-74), `ProductionCostService`, `ProjectProvider`, `BuyVsBuildService`, `ProfitMarginService`, `StockpileService` le lisent. Les matières premières d'un intermédiaire découpé y sont calculées sur un seul job, alors que les étapes (après #73) les comptent job par job. Test : Quantum Microprocessor 32 runs découpé 11 + 11 + 10, la liste d'achats doit refléter 491 Titanium Carbide (et ses matières) au lieu de 490.

## Nouveau constat (2026-10-19, spec #16) — à valider avant création

- **Recalcul : un groupe découpé garde au moins 1 run par job, même quand le besoin en demande moins.** `IndustryStepCalculator` (répartition au prorata, `max(1, …)`) : besoin de 1 run réparti sur 2 jobs → 1 + 1 = 2 runs (`testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOne`). Décision produit : supprimer des jobs (et leurs jobs matchés, achats) ou fusionner le groupe.
