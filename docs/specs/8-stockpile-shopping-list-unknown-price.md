# Spec #8 (lot Stockpile et liste d'achats) — Prix de marché inconnu dans le Stockpile et la liste d'achats d'un projet

Statut : validée par Sylvain le 2026-10-28 (Q1, Q2 et Q3 : option A, voir « Décisions »). Termes : [glossaire](../glossary.md).

## Contexte

Dernier lot de #8 (rouverte le 2026-10-28). Trois endroits comptent encore un prix absent comme 0 ou le laissent hors d'un total sans le dire :

- **Aperçu d'import du Stockpile** (`StockpileService::previewImport()`, `POST /industry/stockpile-targets/preview`) : `estimatedCost` n'additionne que les types qui ont un prix (`if ($unitPrice !== null)`). Le golden Nitrogen Fuel Block affiche 25 471,25 ISK alors que 7 des 10 types n'ont pas de prix.
- **Statut du Stockpile** (`StockpileService::getStockpileStatus()`, `GET /industry/stockpile-status`) : `$prices[$tid] ?? 0.0` fait d'une cible sans prix une cible gratuite : `unitPrice`, `stockValue` et `deficitCost` valent 0, et elle pèse 0 dans `stages.*.totalValue` et `kpis.totalInvested`.
- **Liste d'achats chiffrée d'un projet** (`ShoppingListProvider`, `GET /industry/projects/{id}/shopping-list`) : les lignes sans prix ont déjà des montants `null`, mais les totaux `jita`, `jitaWithImport`, `import`, `structure` et `best` ne les comptent pas. Quand le marché est injoignable (`priceError`), tous les totaux en ISK valent 0.

Les deux méthodes du Stockpile lisent le prix par `JitaMarketService::getPrices()`, qui ne lit que le cache du sync de fond (matériaux, minerais, PI) : composants, intermédiaires et produits finaux n'y sont presque jamais. La liste d'achats lit déjà Jita avec le repli ESI (`MarketService::getJitaPrices()` → `getPricesWithFallback()`).

Modèle des autres lots de #8 : montant inconnu = `null`, jamais 0 ; la réponse porte `missingPriceTypeIds`.

## Règles métier

### Source du prix (Stockpile)

- **R1 — Repli ESI.** `previewImport()` et `getStockpileStatus()` lisent le prix Jita par `JitaMarketService::getPricesWithFallback()` (cache du sync, puis ESI à la demande), en un seul appel par requête. Un type sans prix après repli a un prix inconnu.

### Aperçu d'import du Stockpile

- **R2 — Ligne.** `unitPrice` reste `null` pour un type sans prix (inchangé).
- **R3 — Coût estimé.** `estimatedCost = Σ quantité × unitPrice` sur toutes les lignes (produit final compris, comme aujourd'hui), arrondi à 2 décimales. Il vaut `null` dès qu'une ligne a `unitPrice` `null`.
- **R4 — Types sans prix.** La réponse porte `missingPriceTypeIds` : les typeId des lignes sans prix, sans doublon, triés par ordre croissant ; `[]` si tout est chiffré.

### Statut du Stockpile

- **R5 — Prix d'une cible.** `unitPrice` vaut `null` pour une cible sans prix (plus 0).
- **R6 — Valeur du stock.** `stockValue = stock × unitPrice`. Stock nul : `0.0` (connu, quel que soit le prix). Stock positif et prix inconnu : `null`.
- **R7 — Coût du déficit.** `deficitCost = deficit × unitPrice`. Déficit nul : `0.0`. Déficit positif et prix inconnu : `null`. `deficit` reste la quantité (spec #120, inchangé).
- **R8 — Totaux.** `stages.<étape>.totalValue` vaut `null` dès qu'une cible de l'étape a `stockValue` `null` ; une étape sans cible garde `0.0`. `kpis.totalInvested` vaut `null` dès qu'une cible a `stockValue` `null`. Les arrondis sont inchangés (totalValue additionne les valeurs arrondies, totalInvested arrondit la somme brute).
- **R9 — Types sans prix.** Le statut porte `missingPriceTypeIds` : les typeId des cibles sans prix, sans doublon, triés ; `[]` sinon (et pour un Stockpile vide).
- **R10 — Inchangé.** Statut, pourcentage, santé, goulot, produits prêts, contenu et ordre de `shoppingList` ne dépendent pas du prix. Les cibles de `shoppingList` portent les mêmes `unitPrice`, `stockValue`, `deficitCost` que celles des étapes.

### Liste d'achats chiffrée d'un projet

- **R11 — Lignes.** Inchangées : un prix absent donne déjà `jitaTotal`, `structureTotal`, `bestPrice`, etc. à `null` (#103, #104).
- **R12 — Total Jita.** `totals.jita = Σ jitaTotal`, `null` dès qu'une ligne a `jitaTotal` `null`.
- **R13 — Total import.** `totals.import = Σ importCost` de toutes les lignes : le coût d'import ne dépend que du volume. `null` dès qu'un volume est inconnu (#104 R4, inchangé).
- **R14 — Total Jita + import.** `totals.jitaWithImport = totals.jita + totals.import`, `null` dès que l'un des deux est `null`.
- **R15 — Total structure.** `totals.structure = Σ structureTotal`, `null` dès qu'une ligne a `structureTotal` `null`.
- **R16 — Meilleur total.** `totals.best = Σ bestPrice`, `null` dès qu'une ligne a `bestPrice` `null` (aucun prix utilisable, ou volume inconnu sans prix structure, #104 R3).
- **R17 — Économies.** `totals.savingsVsJitaWithImport` et `totals.savingsVsStructure` gardent le périmètre commun de #103 (matériaux qui ont les deux totaux) et la règle volume de #104. Quand le marché est injoignable (`priceError` non nul), les deux valent `null` (aucune comparaison n'a été faite).
- **R18 — Volume.** `totals.volume` ne dépend pas du prix (inchangé).
- **R19 — Types sans prix.** La réponse porte `missingPriceTypeIds` : les typeId des lignes dont `bestPrice` est `null` faute de tout prix (ni Jita ni structure), sans doublon (un type sur une ligne de fabrication et une ligne d'invention compte une fois), triés ; `[]` sinon. Marché injoignable : tous les types listés.

### API et frontend

- **R20 — API.** `StockpileImportPreviewResource::$estimatedCost` et les montants du statut deviennent nullables ; `StockpileImportPreviewResource` et `StockpileStatusResource` gagnent `missingPriceTypeIds` (recopié par le processor et le provider). `ShoppingListTotalsResource` : `jita`, `structure`, `best`, `savingsVsStructure` deviennent `?float` ; `ShoppingListResource` gagne `missingPriceTypeIds`.
- **R21 — Frontend.** Tout montant `null` s'affiche inconnu avec `<IskAmount>` (`—` et « Valeur inconnue »), jamais 0 ; aucune somme front ne traite `null` comme 0 :
  - aperçu d'import (`StockpileImportPanel.vue`) : coût estimé ;
  - tableau de bord Stockpile (`StockpileDashboard.vue` KPI investi, `StockpileColumn.vue` valeur d'étape, `StockpileShoppingList.vue` coût par ligne et total des déficits filtrés, `null` dès qu'une ligne filtrée a un `deficitCost` `null`) ;
  - alerte « stock critique » de `IndustryDashboard.vue` : coût affiché « inconnu » ;
  - onglet liste d'achats du projet (`ShoppingTotalsSummary.vue`) et totaux recalculés avec stock collé (`missingTotals` de `ShoppingTab.vue`) : un total vaut `null` dès qu'une ligne manquante (`missing > 0`) a la valeur correspondante `null` ;
  - un avertissement nomme les types sans prix (`missingPriceTypeIds`, libellé existant `industry.inventionUnknown.missingPriceTypes`) sur l'aperçu, le Stockpile et la liste d'achats ; il est masqué quand `priceError` est affiché.
  - « Appliquer comme coût matériaux » reste bloqué tant que `totals.best` est `null` (garde `isMaterialCostKnown` existante).

## Exemples chiffrés

### Aperçu d'import — golden Nitrogen Fuel Block (4051), 1 run, ME 10

Quantités : 4051 ×40 ; 44 ×4, 3683 ×20, 3689 ×4, 9832 ×9, 9848 ×1, 16272 ×153, 16273 ×315, 16275 ×18, 17888 ×405.

| # | Prix | Attendu |
|---|---|---|
| P1 | 4051 600,5 ; 17888 0,25 ; 9832 150 ; les 7 autres absents (cas figé) | `unitPrice` des lignes inchangés ; `estimatedCost` `null` (au lieu de 25 471,25) ; `missingPriceTypeIds` [44, 3683, 3689, 9848, 16272, 16273, 16275] |
| P2 | P1 et les 7 autres à 2,0 | 24 020 + 101,25 + 1 350 + 515 × 2 = **26 501,25** ; `missingPriceTypeIds` [] |
| P3 | 17888 0,333, les 9 autres à 0,0 | 405 × 0,333 = 134,865 → **134,87** (arrondi ; un prix 0 est connu) |
| P4 | P2 sauf 9848 absent | `estimatedCost` `null` ; `missingPriceTypeIds` [9848] |
| P5 | 9848 absent du cache, 5 000 via ESI | 9848 chiffré à 5 000 (R1) |

### Statut du Stockpile

| # | Cibles | Attendu |
|---|---|---|
| S1 | « Unpriced Component » 57486, cible 100, stock 40, composant, sans prix ; Mexallon 36, cible 10, stock 4, 50 ISK (cas figé) | 57486 : `unitPrice` `null`, `stockValue` `null`, `deficit` 60, `deficitCost` `null` ; Mexallon : `stockValue` 200,0, `deficitCost` 300,0 ; `component.totalValue` `null` ; `raw_material.totalValue` 200,0 ; `totalInvested` `null` (au lieu de 200,0) ; `missingPriceTypeIds` [57486] |
| S2 | 57486 sans prix, cible 100, stock 0 (seule cible) | `stockValue` 0,0 ; `deficitCost` `null` ; `component.totalValue` 0,0 ; `totalInvested` 0,0 ; `missingPriceTypeIds` [57486] |
| S3 | 57486 sans prix, cible 100, stock 150 | `stockValue` `null` ; `deficit` 0 ; `deficitCost` 0,0 ; `totalInvested` `null` |
| S4 | Tritanium 0,125, Pyerite 0,375, Mexallon 0,0625 (cas existant) | inchangé : 22,51 / 0,19 / 22,69 ; `missingPriceTypeIds` [] |
| S5 | Cible au prix 0,0 renvoyé par le marché, stock 10 | prix connu : `stockValue` 0,0 |
| S6 | S1 | l'entrée 57486 de `shoppingList` porte les mêmes `null` |

### Liste d'achats — golden Nitrogen Fuel Block, 1 run, transport 1 200 ISK/m³

Volumes du harnais : total 414,625 m³ → import de toutes les lignes 497 550.

| # | Prix | Attendu |
|---|---|---|
| L1 | 17888 Jita 500 / structure 1 200 ; 9832 Jita 100 ; 16272 structure 200 ; 6 types sans prix (cas figé) | `totals.jita`, `jitaWithImport`, `structure`, `best` `null` (au lieu de 203 400 / 462 600 / 516 600 / 493 200) ; `import` 497 550,0 ; `volume` 414,625 ; `savingsVsJitaWithImport` 0,0 et `savingsVsStructure` 40 500,0 (#103, inchangé) ; `missingPriceTypeIds` [44, 3683, 3689, 9848, 16273, 16275] |
| L2 | Jita seul : 17888 500, les 8 autres 100 | `jita` 202 500 + 524 × 100 = **254 900,0** ; `import` 497 550,0 ; `jitaWithImport` **752 450,0** ; `best` 752 450,0 ; `structure` `null` ; économies 0,0 ; `missingPriceTypeIds` [] |
| L3 | L2 sauf 9848 (Robotics) sans prix | `jita`, `jitaWithImport`, `best` `null` ; ligne Robotics à `null` (inchangé) ; `missingPriceTypeIds` [9848] |
| L4 | L2 sauf 9848 structure 90 000 sans prix Jita | `jita`, `jitaWithImport`, `structure` `null` ; `best` = 748 750 + 90 000 = **838 750,0** ; `missingPriceTypeIds` [] |
| L5 | Marché injoignable (`priceError`, cas figé) | `jita`, `jitaWithImport`, `structure`, `best`, les deux économies `null` (au lieu de 0,0) ; `import` 497 550,0 ; `volume` 414,625 ; quantités inchangées ; `missingPriceTypeIds` les 9 types listés, triés |
| L6 | L3 et Robotics aussi en matériau d'invention | 9848 une seule fois dans `missingPriceTypeIds` |
| L7 | L2 avec 9848 à 0,0 | prix connu : `best` connu, `missingPriceTypeIds` [] |

## Cas limites

- Prix à 0 renvoyé par le marché : prix connu, partout.
- Stockpile vide : statut inchangé (0,0, santé 100), `missingPriceTypeIds` [].
- Liste d'achats vide : totaux à 0,0 (somme vide), `missingPriceTypeIds` [].
- Volume inconnu et prix connu : règles de #104 inchangées ; un type peut être à la fois dans `missingVolumeTypeIds` et `missingPriceTypeIds`.
- Échec du repli ESI pour un type : prix inconnu, pas d'erreur de la requête.

## Hors périmètre

- Le coût estimé de l'aperçu additionne le produit final et ses matériaux (double compte) : comportement inchangé, à traiter à part s'il gêne.
- Liste d'achats collée (`ParseProcessor`, `AppraiseProcessor`, `/shopping-list/parse`) et liste partagée (`SharedShoppingListProvider`).
- Prix Jita pondérés des lignes (`jitaWeighted*`) : pas de total côté backend.
- Migration sur le noyau (`missingData`) : Stockpile et liste d'achats restent hors noyau.
- Pas de `unknownReason` : un seul motif possible (prix de marché absent) ; l'échec du marché est déjà porté par `priceError`.

## Tests impactés

Chaque test ci-dessous change d'attendu ; les autres tests de ces fichiers restent verts tels quels (vérifié en lecture des assertions).

### Tests qui figent le bug (`#[Group('issue-8')]`), remplacés par les exemples

| Test | Raison |
|---|---|
| `StockpileGoldenImportCharacterizationTest::testPreviewEstimatedCostCurrentlyLeavesOutTheItemsWithoutJitaPriceIssue8` | `estimatedCost` 25 471,25 → `null` (R3) ; devient P1, sans `Currently…Issue8` ni groupe |
| `StockpileStatusCharacterizationTest::testTargetWithoutJitaPriceIsCurrentlyValuedAtZeroIssue8` | `unitPrice`/`stockValue`/`deficitCost` 0,0 → `null`, `component.totalValue` 0,0 → `null`, `totalInvested` 200,0 → `null` (R5-R8) ; devient S1 |
| `ShoppingListProviderGoldenCharacterizationTest::testPricedShoppingListCurrentlyLeavesAMaterialWithoutPriceOutOfTheTotalsIssue8` | `totals.jita` 203 400, `structure` 516 600, `best` 493 200 → `null` (R12, R15, R16) ; devient L1 |
| `ShoppingListProviderGoldenCharacterizationTest::testPricedShoppingListWithoutMarketKeepsTheQuantitiesAndCurrentlyTotalsZeroIssue8` | `totals.best` et `totals.jita` 0,0 → `null` (R12, R16) ; devient L5 |

### Attendus qui changent dans des tests d'intention

| Test | Raison |
|---|---|
| `StockpileGoldenImportCharacterizationTest::testPreviewRoundsTheEstimatedCostToTwoDecimals` | seul 17888 est chiffré : `estimatedCost` 134,87 → `null` (R3). Donner 0,0 aux 9 autres types (P3) pour garder l'intention d'arrondi |
| `StockpileServiceTest::testPreviewImportReturnsCorrectStagesAndEstimatedCost` | les clés de l'aperçu gagnent `missingPriceTypeIds` (R4) ; le bouchon `getPrices` passe à `getPricesWithFallback` (R1) |
| `StockpileStatusProviderTest::testExposesTheStockpileStatusOfTheConnectedPilot` | la resource porte `missingPriceTypeIds` (R9, R20) ; bouchon `getPrices` → `getPricesWithFallback` |
| `ShoppingListProviderGoldenCharacterizationTest::testPricedShoppingListComparesJitaWithImportToTheStructureForEachMaterial` | `totals.import` 259 200 → 497 550 (R13, toutes les lignes) ; `totals.jitaWithImport` 462 600 → `null` (R14) |
| `…::testPricedShoppingListAddsTheImportCostOfEveryMaterialBoughtInJita` | `totals.import` 245 700 → 484 050 (403,375 m³ × 1 200, R13) ; le nom devient faux (« every listed material ») |
| `…::testPricedShoppingListWithoutStructurePriceHasNoSavingVersusTheStructure` | `totals.structure` 0,0 → `null` (R15) ; économies 0,0 inchangées |
| `…::testPricedShoppingListWithoutJitaPriceHasNoSavingVersusJita` | `totals.jitaWithImport` 0,0 → `null` (R14) ; économies 0,0 inchangées |
| `…::testPricedShoppingListSavingsLeaveOutTheMaterialsPricedOnlyInJitaOrOnlyInTheStructure` | `totals.best` 493 200 → `null` (R16) ; économies inchangées |
| `…::testPricedShoppingListBuysAMaterialWithoutSdeVolumeInTheStructureWithoutSaving` | `totals.best` et `totals.structure` 182 250 → `null` : les 8 autres lignes n'ont pas de prix (R15, R16) |
| `…::testPricedShoppingListKeepsTheImportOfAMaterialWithAKnownVolumeWhenAnotherVolumeIsUnknown` | `totals.jita` 203 400 et `totals.best` 17 100 → `null` (R12, R16) |
| `…::testPricedShoppingListLeavesTheImportOfAMaterialWithANullSdeVolumeUnknown` et `…AbsentFromTheSdeUnknown` (support `assertIsotopesImportIsUnknownWithAJitaPriceOfFiveHundredOnly`) | `totals.jita` 202 500 et `totals.best` 0,0 → `null` (R12, R16) |
| `…::testShoppingListWithoutMarketHasNoSaving` | économies 0,0 → `null` sur `priceError` (R17) |

### Harnais à adapter sans changement d'attendu (R1)

`getPrices` → `getPricesWithFallback` dans les bouchons de `StockpileServiceTest` (6 bouchons), `StockpileGoldenImportCharacterizationTest`, `StockpileStatusCharacterizationTest`, `StockpileStatusIntermediateStockTest`, `StockpileStatusDeficitTest`. Sans ce changement, le mock renvoie `[]` et tous les prix deviennent inconnus.

### Frontend

Types `frontend/src/stores/industry/types.ts` : `StockpileItemStatus` (`unitPrice`, `stockValue`, `deficitCost`), `StockpileStageStatus.totalValue`, `StockpileKpis.totalInvested`, `StockpileShoppingItem.deficitCost`, `StockpileImportPreview.estimatedCost` en `number | null` ; `missingPriceTypeIds` sur `StockpileStatus`, `StockpileImportPreview`, `ShoppingListResponse` ; `ShoppingListTotals` `jita`, `structure`, `best`, `savingsVsStructure` en `number | null`. Les tests Vitest des composants listés en R21, s'il y en a, suivent.

## Découpage en lots

1. **Backend Stockpile, aperçu** : R1 (repli ESI, harnais), R2-R4, R20 côté aperçu. Tests P1-P5.
2. **Backend Stockpile, statut** : R5-R10, R20 côté statut. Tests S1-S6.
3. **Backend liste d'achats** : R11-R19, R20 côté liste d'achats. Tests L1-L7 et les attendus listés ci-dessus.
4. **Frontend Stockpile** : types, `StockpileImportPanel`, `StockpileDashboard`, `StockpileColumn`, `StockpileShoppingList`, alerte d'`IndustryDashboard`, avertissement des types sans prix.
5. **Frontend liste d'achats** : types, `ShoppingTotalsSummary`, `missingTotals` de `ShoppingTab.vue`, avertissement (masqué sous `priceError`).

Chaque lot backend laisse l'API cohérente (champs nullables plus `missingPriceTypeIds`) ; les lots frontend 4 et 5 suivent leur lot backend (1-2, puis 3).

## Décisions (Sylvain, 2026-10-28)

- **Q1 — A.** Totaux du Stockpile (`estimatedCost`, `stages.*.totalValue`, `kpis.totalInvested`) `null` dès qu'un prix manque, avec `missingPriceTypeIds` (pas de somme partielle).
- **Q2 — A.** Liste d'achats : chaque total de colonne (Jita, Jita + import, structure, meilleur) vaut `null` dès qu'une ligne n'a pas la valeur de cette colonne.
- **Q3 — A.** Le Stockpile lit les prix par `getPricesWithFallback()`.

Choix faits par défaut dans le brouillon et conservés : total import indépendant du prix (R13), économies `null` sur `priceError` (R17), pas d'`unknownReason`.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante**, **Déficit**, **Stockpile**. Ajoutés : **Valeur du stock** (Stock value) et **Coût du déficit** (Deficit cost).
