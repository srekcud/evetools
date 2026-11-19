# #7 — Coût d'installation : conception (2026-10-22)

## 1. Déjà tranché / pas tranché

| Point | État | Source |
|---|---|---|
| EIV = runs × Σ(qté ME0 × adjusted price), matériaux de fabrication du BP pour copie/invention, jamais l'adjusted price du produit ; adjusted price absent → EIV inconnue + typeId | tranché | `docs/specs/industry-engine.md:117-126` (R5), validée 2026-10-07 (`decisions.md:22`) |
| base = EIV (fab, réaction) ou 2 % × EIV (copie, invention) ; total = base×index×mod_coût + base×taxe + base×SCC + base×alpha | tranché | `industry-engine.md:128-146` (R6) |
| Taxe **additive**, bonus de rôle sur le seul terme système | tranché | `industry-engine.md:141-142` |
| SCC 4 % fab/réaction/invention ; taxe NPC 0,25 % ; alpha 0,25 % de la base | tranché | `industry-engine.md:137,143-144` |
| Index absent / adjusted price absent → inconnu, jamais 0 | tranché | `industry-engine.md:145,180-183` (R10, D5) |
| Vecteurs chiffrés | tranché sauf copie | `industry-engine.md:291-306` (§4.5), `tests/Fixtures/Golden/install-cost-vectors.json` |
| Une seule implémentation de R6 dans `src/` | tranché | `industry-engine.md:340` |
| **SCC 4 % sur la copie** | **non tranché** | `decisions.md:15` (D11a, vérif. en jeu) |
| Taxe à appliquer quand l'appelant n'a pas de structure (scanner, BvB, Pivot) | non tranché | rien dans la spec (Q2) |
| Dépendance `Service → IndustryDomain` | non tranché ; refusée pour #105 (red-queue, DV) | `deptrac.yaml:110` (Service n'a pas IndustryDomain) (Q1) |
| TTL du cache | non tranché (aucune valeur fixée) | — |

Formule cible déjà codée : `src/Industry/Domain/InstallCost.php` + `EstimatedItemValue.php` (taux en **fraction**). Piège d'unité : `IndustryStructureConfig::getFacilityTaxRate()` rend des **pourcents** (NPC = 0.25, `Entity/IndustryStructureConfig.php:49`), le noyau des fractions (0.0025, `ProductionCostCalculator.php:35`) : diviser par 100 à la frontière. Alpha : aucune donnée de clone dans l'app → `false` partout. Bonus de rôle : `Multiplier::one()` dans ces lots (les appelants n'ont qu'un `solarSystemId`), hors #7.

## 2. Lots

Principe commun : `calculateJobInstallCost()` / `calculateEiv()` restent **intacts** pour `ProductionCostService` (#121) ; les lots ajoutent une méthode nullable sur `EsiCostIndexService` et y migrent les appelants. Forme de l'inconnu alignée sur #8 : montant `null`, `unknownReason` (`missing_cost_index`, `missing_adjusted_price`), typeId listés dans un champ distinct `missingAdjustedPriceTypeIds` (ne pas mélanger avec les prix Jita de `missingPriceTypeIds`). Chiffres NFB : golden `ProfitMarginGoldenCharacterizationTest:187-245` (EIV 17 031,184, index 0,05).

### L4 — TTL du cache (à faire en premier) — montants prod : non
- Fichiers : `EsiCostIndexService.php:26,30` ; tests `EsiCostIndexServiceTest`.
- Règle : TTL > intervalle du scheduler (`SyncScheduler.php:85,89`) pour survivre à une sync en échec ; valeur [À CONFIRMER par Sylvain, ex. 3 intervalles]. Les handlers (`SyncCostIndicesHandler:36-41`) gardent `syncTracker->fail` (pas silencieux).
- Exemples : sync OK à T0, sync en échec à T0+2 h → `getCostIndex()` à T0+2 h 01 rend l'index de T0 (aujourd'hui `null`) ; adjusted prices idem à T0+24 h 01.
- Tests cassés : aucun connu. Risque : index périmé de quelques heures (acceptable, l'ESI le rafraîchit toutes les heures environ [À CONFIRMER]).
- Pourquoi d'abord : après L1, un cache vide devient « inconnu » visible partout au lieu d'un 0 discret.

### L1a — Cache et EIV sans 0 — montants prod : oui, là où le 0 était caché
- Fichiers : `EsiCostIndexService.php` (`syncAdjustedPrices:59` n'écrit pas l'entrée sans `adjusted_price` ; nouvelle méthode nullable « EIV + coût d'installation » renvoyant montant ou motif + typeId manquants, formule **actuelle** inchangée).
- Exemples : entrée `{type_id: 35, average_price: 5}` → `getAdjustedPrice(35)` = `null` (aujourd'hui 0.0) ; NFB sans index → `null`, motif `missing_cost_index` (aujourd'hui 0) ; NFB sans adjusted price Robotics → `null`, typeId 9848 (aujourd'hui 851,0668) ; NFB tout connu, taxe 10 % → 936,71512 (inchangé).
- Tests cassés : aucun (méthodes nouvelles). Risque : faible.

### L1b — Propagation, un lot par appelant — montants prod : oui (0 → inconnu)
Ordre : Pivot (`:169,:442`), Buy vs Build (`:226,:324`, `buildJobInstallCost` → `?float` dans `BuyVsBuildResource:55` + front `BuyVsBuildTab.vue`), Profit Margins **après fusion de `fix/profit-margin-unknown-material-price`** (même fichier). Invention et scanner passent en L3.
- Exemples : Pivot candidat NFB sans index → `marginPercent` null, `unknownReason` `missing_cost_index` ; BvB job final sans index → `totalProductionCost` null ; Profit Margins NFB sans adjusted price Robotics → `jobInstallCost`, `totalCost` null, 9848 listé.
- Tests cassés : `ProfitMarginGoldenCharacterizationTest::testMissingCostIndexCurrentlyYieldsZero…`, `::testMaterialWithoutAdjustedPriceIsCurrentlyLeftOutOfEiv` (remplacés) ; tests Pivot/BvB dont le harnais n'a pas d'index [à lister en rouge].
- Risque : le motif d'inconnu de Pivot est aujourd'hui déduit de `totalCost === null` (`PivotAdvisorService:258,479`) → à rendre explicite.

### L2 — Formule R6 via `InstallCost` — montants prod : **oui**, ×2 à ×4
- Fichiers : corps de la méthode nullable de L1a = `EstimatedItemValue::of` + `InstallCost::forJob` (pas de copie de la formule) ; `deptrac.yaml` si Q1 = oui.
- Touche Profit Margins (taxe fournie), BvB et Pivot (taxe absente aujourd'hui).
- Exemples : NFB taxe 10 % → 3 235,92496 (auj. 936,71512) ; NFB structure par défaut 20 % (`:204`) → 4 939,04336 (auj. 1 021,87104) ; NFB sans taxe (BvB/Pivot) → 1 532,80656 (auj. 851,5592) ; vecteur §4.5 réaction EIV 2e6, 0,03, 5 % → 240 000 (auj. 63 000).
- Tests cassés : `ProfitMarginGoldenCharacterizationTest:187,204`, totaux de `ProfitMarginServiceTest`, `BuildsBuyVsBuildOnGoldenSde`/`BuyVsBuildServiceTest`/`BuyVsBuildProviderTest` (dont E5 67 587,5 du #99), `PivotAdvisorServiceTest` (marges).
- Risque : marges affichées en baisse sur tous les produits ; à annoncer.

### L3 — Réimplémentations — montants prod : **oui**
- L3a Invention (`InventionService:66-85,316-324,595-611`) : EIV R5 (matériaux de fabrication du BP, plus l'adjusted price du produit), base 2 %, SCC, inconnu propagé dans `costPerAttempt` (motifs déjà en place `:27-28`). Copie bloquée par D11a (Q3). Exemple §4.5 : invention 1 tentative, base 200 000, 0,08, 5 % → 34 000 (auj. 16 800 à EIV égale). Casse : `InventionServiceTest:411,440,459`, `ProfitMarginGoldenCharacterizationTest:414`, BPC kit (`:490`).
- L3b Scanner (`BatchProfitScannerService:319-321`) : EIV via prix préchargés mais inconnue si absent (`calculateEivFromPrices:174`), formule R6, taxe selon Q2. Exemple NFB 1 run : 1 532,80656 (taxe 0) ou 1 575,38452 (NPC 0,25 %) ; auj. 851,5592. Casse : `BatchProfitScanRulesTest:117`, `BatchProfitScannerServiceTest`. Risque : système sans index → tout le scan inconnu, filtré par `minMarginPercent` → liste vide.
- L3c Taxe pour BvB / Pivot / scanner selon Q2 (même résolution que `ProfitMarginService::resolveFacilityTaxRate`, ou NPC 0,25 %).

### L5 — après #121 : `ProductionCostService` + suppression des méthodes anciennes
- Exemple : `FacilityTaxInstallCostTest:84` Rifter 4 runs, Jita 0,0625, NPC → 8 400 (auj. 5 012,5) ; `:95` Merlin → 7 500 + 0 + 2 400 = 9 900 (auj. 7 500). Supprime `calculateJobInstallCost`, `calculateEiv`, `calculateEivFromPrices` (critère `industry-engine.md:340`).

## 3. Ordre recommandé

L4 → L1a → L1b (Pivot, BvB, puis Profit Margins après la branche #8 en cours) → L2 → L3a, L3b, L3c → L5 après #121. L1 avant L2 : un montant faux mais connu reste comparable à aujourd'hui ; un 0 caché est pire. Risque de travail jeté : si la migration du moteur (rangs 1-2, branches `feat/profit-margin-on-engine` / `feat/buy-vs-build-on-engine`, sans commit d'avance sur main) reprend, L1b/L2 sur Profit Margins et BvB deviennent caducs ; ils restent petits.

Questions pour Sylvain :
1. **Q1** Autoriser `Service → IndustryDomain` dans `deptrac.yaml` pour réutiliser `InstallCost`/`EstimatedItemValue` (recommandé ; seule alternative = dupliquer R6, contraire à `industry-engine.md:340`) ? Pour #105 tu avais écarté la dépendance au noyau.
2. **Q2** Taxe d'installation quand l'outil n'a pas de structure (scanner, BvB, Pivot) : (a) structure de l'utilisateur dans le système favori, sinon structure par défaut, comme Profit Margins (recommandé), (b) NPC 0,25 %, (c) 0 % ?
3. **Q3** D11a : livrer la copie avec SCC 4 % (spec) avant ta vérification en jeu, ou garder la copie hors L3a d'ici là ?
