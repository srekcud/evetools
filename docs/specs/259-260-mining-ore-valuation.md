# Spec #259 + #260 — Minage : prix du minerai compressé et minerai sans prix

**Statut : validée en autonomie en mode nuit le 2026-10-29 (option A du blocage du tableau de bord retenue), à relire par Sylvain.**

Lot `group:mining-ops`. Les deux issues touchent les mêmes fichiers et sont traitées ensemble ; #259 passe en premier dans la phase rouge (les exemples de #260 n'utilisent volontairement aucun prix compressé, pour rester indépendants de #259).

## Contexte

La valeur du minage d'un utilisateur est calculée à trois endroits :

- `OreValueService::getBatchOrePrices()` rend, par type miné, cinq sources de prix unitaire : prix brut Jita (`rawUnitPrice`, net de la taxe d'export), prix équivalent compressé Jita (`compressedEquivalentPrice`), valeur de retraitement Jita (`reprocessValue`), prix compressé structure (`structureCompressedUnitPrice`, prix d'une unité compressée), valeur de retraitement structure (`structureReprocessValue`).
- `MiningBestValueCalculator` en tire le meilleur prix unitaire d'un type et les totaux servis par cinq providers : `MiningStatsProvider`, `MiningStatsDailyProvider`, `MiningStatsByTypeProvider`, `LedgerDashboardProvider`, `LedgerDailyStatsProvider`. Il appelle `getBatchOrePrices($typeIds)` sans structure ni taxe d'export : dans le calculateur, les deux sources structure sont toujours absentes (comportement figé par `testBestUnitPriceCurrentlyIgnoresTheStructureMarketAndUsesTheDefaultYieldIssue38`, hors de ce lot).
- Le front (`useMiningPricing.ts`) refait le choix du meilleur prix pour la liste des entrées (`MiningEntryCollectionProvider`, `GET /ledger/mining/entries`, 500 lignes au plus) et les cartes « Personnel / Corpo / Non assigné » de l'onglet Minage.

**#259.** `OreValueService::COMPRESSION_RATIO = 100` divise le prix d'une unité compressée par 100 pour obtenir un prix par unité brute (`:132`). Le calculateur (`:174`), `useMiningPricing.ts` (`:19`, `:42`) et `MiningEntriesTable.vue` (`:277`) divisent aussi `structureCompressedUnitPrice` par 100. Or le SDE (build 3654997 du 2026-10-27, vérifié par le thread) montre que 1 unité « Compressed X » = 1 unité brute X : pour les 186 types « Compressed X » dont le brut a des matériaux de retraitement (minerais et glaces), `portionSize` et matériaux sont identiques à ceux du brut (Veldspar 1230 et Compressed Veldspar 62516 : portionSize 100, matériaux {Tritanium : 400}). Le rapport 100 est celui des volumes (0,1 m³ contre 0,001 m³), pas des unités. Le compressé est donc valorisé 100 fois trop bas et n'est presque jamais retenu comme meilleure option.

Seuls les types « Batch Compressed X » (63 types, ex. 28432 Batch Compressed Veldspar : portionSize 1, matériaux {Tritanium : 400}) valent 100 unités brutes par unité. `OreValueService::findCompressedVariant()` ne les trouve jamais : il cherche le nom exact `'Compressed ' . $rawName` et refuse tout nom brut contenant « Compressed ».

**#260.** Un type sans aucun prix compte pour 0 : `($bestPrices[$typeId] ?? 0.0) * quantité` (`:46`, `:84`, `:123`) et le repli `(float) ($priceData['rawUnitPrice'] ?? 0.0)` (`:182`). Le total paraît connu et sous-estimé, sans signal (`testCurrentlyValuesAnOreWithoutAnyPriceAtZeroIskMiningAuditP2_5`). Modèle retenu ailleurs (glossaire, **Coût inconnu** ; Stockpile, liste d'achats, scanner) : montant `null` + `missingPriceTypeIds`, une somme qui contient un inconnu est inconnue, une quantité nulle vaut 0.

## Règles métier

### #259 — Prix équivalent compressé

- **M1 — Unités brutes par unité compressée.** C'est une donnée du SDE, pas une constante : `portionSize du brut / portionSize du compressé`. Le prix équivalent par unité brute vaut `prix d'une unité compressée × portionSize du compressé / portionSize du brut`. Pour un « Compressed X » du SDE actuel, le rapport vaut 1 (prix équivalent = prix compressé). `COMPRESSION_RATIO` disparaît.
- **M2 — PortionSize inconnu.** Si le `portionSize` du brut ou du compressé est absent (`null`) ou ≤ 0, le prix équivalent compressé est inconnu (`null`), Jita comme structure. Aucun rapport par défaut. Le prix d'une unité compressée (`compressedUnitPrice`, `structureCompressedUnitPrice`) reste exposé s'il existe.
- **M3 — Jita.** `compressedEquivalentPrice` = prix équivalent (M1) du prix compressé Jita net de la taxe d'export (calcul de la taxe inchangé : taxe × volume du compressé, prix ramené à 0 au plus bas, puis M1).
- **M4 — Structure.** Nouveau champ `structureCompressedEquivalentPrice` rendu par `getBatchOrePrices()` (clé placée juste après `structureCompressedUnitPrice`), même règle M1 appliquée à `structureCompressedUnitPrice` (sans taxe d'export, comme aujourd'hui).
- **M5 — Une seule règle.** M1 vit dans `OreValueService` seulement. Le calculateur prend `structureCompressedEquivalentPrice` au lieu de diviser `structureCompressedUnitPrice` ; le front lit `compressedEquivalentPrice` et `structureCompressedEquivalentPrice` de l'API et ne divise plus rien.
- **M6 — Variante compressée.** La recherche de variante est inchangée : seul « Compressed X » est retenu, jamais « Batch Compressed X ». Si un type compressé de forme « lot » (portionSize 1 pour un brut à 100) était un jour retenu, M1 donnerait le bon rapport (100) sans constante.
- **M7 — Montants affichés.** Le compressé devient souvent la meilleure option : la valeur unitaire, les totaux du minage et ceux du tableau de bord du ledger augmentent pour les minerais dont le compressé se vend plus cher que le brut et le retraitement. C'est le but de la correction.

### #260 — Minerai sans prix

- **M8 — Meilleur prix unitaire.** Ordre inchangé, seul le dernier cas change :
  1. au moins une des quatre sources (`compressedEquivalentPrice`, `reprocessValue`, `structureCompressedEquivalentPrice`, `structureReprocessValue`) est > 0 : le maximum de celles-ci ;
  2. sinon, `rawUnitPrice` non `null` : ce prix (0 compris) ;
  3. sinon, une des quatre sources est présente à 0 (prix compressé Jita ramené à 0 par la taxe d'export) : 0, connu ;
  4. sinon : **sans prix**, meilleur prix unitaire `null`.
- **M9 — Sans prix.** Un type est sans prix quand aucune des cinq sources n'existe (toutes `null`). Un prix présent à 0 est un prix connu : il vient d'un prix de marché réel diminué de la taxe d'export (`max(0, …)`), le minerai ne rapporte rien une fois transporté. La sélection entre sources garde la règle `> 0` actuelle (M8, cas 1), pour qu'un compressé ramené à 0 n'écarte pas un brut positif.
- **M10 — Valeur d'un ensemble de types.** Pour un ensemble (quantité par type), la valeur est `Σ meilleur prix × quantité`. Elle est inconnue (`null`) dès qu'un type de quantité > 0 est sans prix. Un type de quantité 0 vaut 0, connu, même sans prix, et n'est pas listé (`0 × prix` vaut 0 quel que soit le prix, comme la **Valeur du stock** d'un stock nul). `missingPriceTypeIds` liste les types sans prix de quantité > 0, sans doublon, triés par typeId croissant. Un ensemble vide vaut 0.0 avec une liste vide.
- **M11 — Jour, usage.** Chaque jour (`getDailyBestValues`) et chaque usage (`getTotalsByUsageBestValue`) est un ensemble au sens de M10 : un jour ou un usage qui contient un type sans prix est inconnu, les autres restent connus. La quantité totale d'un usage reste connue.
- **M12 — Jour sans entrée.** Un jour sans aucune entrée de minage vaut 0.0, connu (absence d'entrée ≠ absence de prix). Les providers distinguent une date absente du résultat (0.0) d'une date présente à `null` : `?? 0.0` est interdit sur ces valeurs (`array_key_exists`).
- **M13 — Dérivés.** Une valeur dérivée d'une valeur inconnue est inconnue : ISK/jour du minage, total du tableau de bord (`pve + mining`), profit, ISK/jour du tableau de bord, parts PVE et minage (`pvePercent`, `miningPercent`), total et profit d'un jour du ledger. Les montants PVE, les dépenses et les quantités restent connus et affichés.
- **M14 — Types de retour du calculateur.** Pas de nouvelle classe ; tableaux typés, comme `StockpileService` (`estimatedCost` + `missingPriceTypeIds`), avec une méthode privée unique qui applique M10 (fin des trois boucles dupliquées) :

  | Méthode | Aujourd'hui | Attendu |
  |---|---|---|
  | `getBestUnitPrices(array $typeIds)` | `array<int, float>` | `array<int, ?float>` (`null` = sans prix) |
  | `getTotalBestValue(...)` | `float` | `array{totalValue: ?float, missingPriceTypeIds: list<int>}` |
  | `getDailyBestValues(...)` | `array<string, float>` | `array{byDate: array<string, ?float>, missingPriceTypeIds: list<int>}` (liste = union des jours) |
  | `getTotalsByUsageBestValue(...)` | `array<string, array{totalValue: float, totalQuantity: int}>` | `array<string, array{totalValue: ?float, totalQuantity: int, missingPriceTypeIds: list<int>}>` |

- **M15 — API.** Aucune migration : ce sont des ressources DTO, pas des entités.

  | Ressource | Champs qui deviennent nullables | Champ ajouté |
  |---|---|---|
  | `MiningEntryResource` | — | `structureCompressedEquivalentPrice: ?float` (#259), `rawUnitPrice: ?float` (#260, déjà calculé par `getBatchOrePrices()`, jamais exposé) |
  | `MiningStatsResource` | `totals.totalValue`, `iskPerDay`, `byUsage.*.totalValue` | `missingPriceTypeIds: list<int>` (du total, usages exclus non comptés) ; `byUsage.*.missingPriceTypeIds` |
  | `MiningStatsDailyResource` / `MiningDailyStatsResource` | `daily[].totalValue` | `missingPriceTypeIds` sur `MiningStatsDailyResource` (union des jours) |
  | `MiningStatsByTypeResource` / `MiningTypeStatsResource` | `byType[].totalValue` ; tri : valeurs connues décroissantes, puis types inconnus | aucun (la ligne porte déjà son `typeId`) |
  | `LedgerDashboardResource` | `totals.mining`, `totals.total`, `totals.profit`, `iskPerDay`, `pvePercent`, `miningPercent`, `miningBreakdown.*` | `missingPriceTypeIds` (du total minage du tableau de bord) |
  | `LedgerDailyStatsResource` / `LedgerDayResource` | `daily[].mining`, `daily[].total`, `daily[].profit` | `missingPriceTypeIds` sur `LedgerDailyStatsResource` |

- **M16 — Front, meilleur prix d'une entrée.** `getBestPriceValue()` rend `number | null` : sources > 0 (avec `structureCompressedEquivalentPrice`) → maximum ; sinon valeur stockée `totalValue / quantity` si `totalValue !== null` et `quantity > 0` (repli existant gardé, front seulement) ; sinon `rawUnitPrice` non `null` ; sinon une source présente à 0 → 0 ; sinon `null`. `getBestPrice()` suit la même règle (couleur `text-slate-400` et source vide pour les deux replis). Une entrée de quantité 0 vaut 0.
- **M17 — Front, agrégats.** `miningByUsage` : un usage qui contient une entrée inconnue est `null`, et `total` aussi. Aucun calcul front ne traite `null` comme 0 (`null / n` vaut 0 en JS : `iskPerActiveDay` et les pourcentages doivent tester `null` avant de diviser).
- **M18 — Front, affichage.** Tout montant `null` passe par `IskAmount` / `UnknownValue` (« — », infobulle `common.unknownValue`, « Valeur inconnue » / « Unknown value »). La liste des types sans prix s'affiche avec deux nouvelles clés i18n (FR, EN) :
  - `ledger.mining.missingPriceTypes` : « Minerais sans prix : {types} » / « Ores without a price: {types} », sous la carte « Valeur totale » de l'onglet Minage ; noms pris dans les entrées chargées, sinon `#typeId` ;
  - `ledger.dashboard.miningMissingPrices` : « {count} minerai sans prix : valeur du minage inconnue | {count} minerais sans prix : valeur du minage inconnue » / « {count} ore without a price: mining value unknown | {count} ores without a price: mining value unknown », sur la carte Minage du tableau de bord.

  | Écran | Champ | Aujourd'hui | Attendu si inconnu |
  |---|---|---|---|
  | `MiningEntriesTable.vue` | colonne structure | `structureCompressedUnitPrice / 100` | `structureCompressedEquivalentPrice` (infobulle : prix d'une unité compressée) |
  | `MiningEntriesTable.vue` | colonne Total (`bestPrice × quantity`) | « - » | « — » + « Valeur inconnue » |
  | `MiningStatsCards.vue` | Valeur totale, bandeau répartition | déjà `IskAmount` | + ligne `ledger.mining.missingPriceTypes` |
  | `MiningStatsCards.vue` | Personnel, Corpo, Non assigné, leurs % et la barre | `formatIskShort`, 0 | « — » ; segment de barre absent |
  | `MiningTab.vue` | `iskPerActiveDay`, `mainSystem.percent` | `null / jours` = 0 | `null` |
  | `LedgerDashboardTab.vue` | total, Mining, ISK/jour, PVE %, Mining %, répartition minage | `formatIskShort`, `formatPercent` | « — » + `ledger.dashboard.miningMissingPrices` ; PVE et dépenses inchangés |
  | `LedgerDashboardTab.vue` | courbe journalière minage (`d.mining`) | 0 | point `null` (trou dans la courbe) |

  Types TS à mettre à jour dans `frontend/src/stores/ledger.ts` : `MiningEntry` (`structureCompressedEquivalentPrice`, `rawUnitPrice`), `MiningStats`, `LedgerDashboard`, `LedgerDay`, `MiningDailyStats`, `MiningStatsByType`. `fetchMiningDailyStats` et `fetchMiningStatsByType` n'ont aucun écran (grep) : type mis à jour, pas d'affichage à changer.

## Exemples chiffrés

Harnais : `BuildsOreValueServiceOnSdeOres` (prix Jita par défaut : Veldspar 18,37, Compressed Veldspar 19,61, Bitumens 213,9, Compressed Bitumens 221,45, Clear Icicle 152 300, Compressed Clear Icicle 158 700, Fullerite-C50 2 741, Compressed Fullerite-C50 2 813, Tritanium 4,27 ; portionSize Veldspar et Compressed Veldspar 100, glace et gaz 1). Rendement par défaut 0,78, taxe d'export 0 sauf mention.

### #259 — `OreValueService::getBatchOrePrices()`

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| A1 | Veldspar, Compressed Veldspar à 1 000 à Jita (test de l'issue) | `compressedEquivalentPrice` 10.0 | 1000.0 ; `compressedUnitPrice` 1000.0 |
| A2 | Veldspar, structure 1022734985679 vend Compressed Veldspar à 1 100 | `structureCompressedUnitPrice` 1100.0, pas d'équivalent (front : 11.0) | `structureCompressedUnitPrice` 1100.0, `structureCompressedEquivalentPrice` 1100.0 |
| A3 | Glace : Clear Icicle, compressé à 158 700 | 1587.0 | 158700.0 |
| A4 | A1 avec taxe d'export 500 ISK/m³ (compressé 0,001 m³ → 0,5 ISK) | `compressedUnitPrice` 999.5, équivalent 9.995 | `compressedUnitPrice` 999.5, équivalent 999.5 |
| A5 | A1 + A2, Compressed Veldspar sans `portionSize` | équivalent 10.0 | `compressedUnitPrice` 1000.0, `compressedEquivalentPrice` `null`, `structureCompressedEquivalentPrice` `null` (M2) |
| A6 | A1, Veldspar sans `portionSize` | équivalent 10.0 | `compressedEquivalentPrice` `null` (M2) |
| A7 | Bitumens sans prix Jita pour Compressed Bitumens | `null` | `null` (inchangé) |
| A8 | Forme « lot » : Compressed Veldspar `portionSize` 1 (brut 100), vendu 2 500 | 25.0 | 25.0 (rapport 100 lu dans le SDE, M1, M6) |
| A9 | A1, Compressed Veldspar `portionSize` 0 | 10.0 | `null` (M2) |
| A10 | Clés du résultat | 10 clés | 11 clés, `structureCompressedEquivalentPrice` après `structureCompressedUnitPrice` |

### #259 — `MiningBestValueCalculator::getBestUnitPrices()`

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| A11 | Veldspar brut 12,4, compressé 19,61, retraitement 13,3224 | 13.3224 (retraitement) | 19.61 (compressé) |
| A12 | Fullerite-C50 sans prix brut, compressé 2 813 | 28.13 | 2813.0 |
| A13 | Bitumens : compressé 221,45, retraitement 831,2148 | 831.2148 | 831.2148 (inchangé, le compressé reste plus bas) |

### #260 — calculateur (prix compressés retirés, comme `createCalculator()` par défaut ; type 81143 sans aucun prix, absent du SDE et de Jita)

Meilleurs prix : Bitumens 831,2148 (retraitement), Tritanium 4,27 (brut).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| B1 | Total : Bitumens 1 000 u, 81143 3 150 u (test de l'issue) | 831 214.8 | `totalValue` `null`, `missingPriceTypeIds` [81143] |
| B2 | Total : Bitumens 1 000 u, Tritanium 2 500 u | 841 889.8 | `totalValue` 841 889.8, `missingPriceTypeIds` [] |
| B3 | `getBestUnitPrices([Bitumens, 81143])` | [831.2148, 0.0] | [831.2148, `null`] |
| B4 | Jours : 2026-10-09 Bitumens 1 000 ; 2026-10-10 Tritanium 2 500 + 81143 3 150 | 831 214.8 et 10 675.0 | `byDate` : 831 214.8 et `null` ; `missingPriceTypeIds` [81143] |
| B5 | Usages : `sold` Bitumens 1 000 ; `industry` Tritanium 2 500 + 81143 3 150 | `sold` 831 214.8 ; `industry` 10 675.0, 5 650 u | `sold` 831 214.8, 1 000 u, [] ; `industry` `null`, 5 650 u, [81143] |
| B6 | Total : 81143 0 u, Tritanium 2 500 u | 10 675.0 | 10 675.0, [] (M10) |
| B7 | Total : Tritanium 2 500 u à 0 ISK à Jita | 0.0 | 0.0, [] (M9) |
| B8 | Total : 81144 1 200 u, 81143 3 150 u, Tritanium 2 500 u, 81143 aussi sur un 2e jour | 10 675.0 | `null`, [81143, 81144] (trié, sans doublon) ; jours : union triée |
| B9 | Aucune entrée | 0.0 / [] / [] | total 0.0 et [] ; jours `byDate` [] et [] ; usages [] — aucune lecture de prix |

### #260 — providers (calculateur bouchonné sur B1, B4, B5 ; 30 jours)

| # | Provider | Entrée | Attendu |
|---|---|---|---|
| C1 | `MiningStatsProvider` | total B1, usages B5, quantité totale 4 150 | `totals.totalValue` `null`, `totals.totalQuantity` 4 150, `iskPerDay` `null`, `missingPriceTypeIds` [81143], `byUsage.industry` {`null`, 5 650, [81143]}, `byUsage.sold` {831 214.8, 1 000, []} |
| C2 | `MiningStatsProvider` | total B2 | `totalValue` 841 889.8, `iskPerDay` 28 062.993… (inchangé), [] |
| C3 | `MiningStatsDailyProvider` | jours B4 | 2026-10-09 : 831 214.8 ; 2026-10-10 : `null` ; autres jours 0.0 ; `missingPriceTypeIds` [81143] |
| C4 | `MiningStatsByTypeProvider` | Bitumens 1 000 u à 831,2148 ; 81143 3 150 u à `null` | Bitumens 831 214.8 puis 81143 `null` (3 150 u), dans cet ordre |
| C5 | `LedgerDashboardProvider` | primes 5 000 000, dépenses 400 000, minage B1, usages B5, réglage `pve` | `totals.pve` 5 000 000, `totals.expenses` 400 000, `totals.mining`, `totals.total`, `totals.profit`, `iskPerDay`, `pvePercent`, `miningPercent` `null` ; `miningBreakdown.sold` 831 214.8, `industry` `null`, `unknown` 0.0, `corpProject` 0.0 ; `missingPriceTypeIds` [81143] |
| C6 | `LedgerDashboardProvider` | C5 avec minage B2 | total 5 841 889.8, profit 5 441 889.8, parts et ISK/jour calculés comme aujourd'hui |
| C7 | `LedgerDailyStatsProvider` | 2026-10-10 : PVE 120 000, dépenses 15 000, minage `null` ; 2026-10-09 : minage 831 214.8 | 10-10 : `pve` 120 000, `expenses` 15 000, `mining`, `total`, `profit` `null` ; 10-09 : `mining` et `total` 831 214.8 ; jours sans minage : `mining` 0.0 ; `missingPriceTypeIds` [81143] |
| C8 | `MiningEntryCollectionProvider` | `getBatchOrePrices` rend pour Veldspar `structureCompressedEquivalentPrice` 1 100.0, `rawUnitPrice` 18.37 | ressource : `structureCompressedEquivalentPrice` 1100.0, `rawUnitPrice` 18.37 |

### Front

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| D1 | Entrée : `structureCompressedUnitPrice` 1 100, `structureCompressedEquivalentPrice` 1 100, `reprocessValue` 13,32 | `getBestPriceValue` 13.32 | 1100, source « Structure compresse » |
| D2 | D1 dans `MiningEntriesTable`, structure choisie | colonne structure « 11.0 » | « 1,100.0 » |
| D3 | Entrée 81143, 3 150 u, aucune source, `totalValue` `null`, `rawUnitPrice` `null` | valeur 0, Total « - » | `null`, Total « — » infobulle « Unknown value » ; `miningByUsage` de son usage et `total` `null` |
| D4 | Entrée Tritanium 2 500 u, seulement `rawUnitPrice` 4,27 | 0 | 4.27 → 10 675 |
| D5 | Entrée avec `totalValue` 2 400 000, 12 000 u, sans source | 200 | 200 (inchangé) |
| D6 | Entrée 81143, 0 u, sans prix | 0 | 0, connu |
| D7 | `miningStats.totals.totalValue` `null`, `missingPriceTypeIds` [81143, 81144], 81143 présent dans les entrées (« Mystery Ore ») | — | carte « — » + « Ores without a price: Mystery Ore, #81144 » |
| D8 | D7, 2 jours actifs | ISK/jour actif 0 | « — » |
| D9 | Tableau de bord avec `totals.mining` `null`, `missingPriceTypeIds` [81143] | montants 0 | Mining, total, ISK/jour, parts « — », « 1 ore without a price: mining value unknown » ; PVE affiché |

## Cas limites

- **Batch Compressed.** Jamais retenu comme variante (M6) ; le cas « lot » est couvert par A8 sans constante. Étendre la recherche aux « Batch Compressed » est hors périmètre.
- **Compressed Dense Polycrase** (79785, portionSize 1 contre 100 pour Dense Polycrase 72902, non publié, sans matériaux) : M1 donnerait un rapport 100. Type jamais miné, aucun test.
- **Gaz.** Le harnais donne portionSize 1 au gaz brut et compressé (rapport 1). Les 186 types vérifiés sont minerais et glaces : la donnée SDE réelle des gaz et une éventuelle perte à la décompression du gaz restent [À CONFIRMER] ; M1 applique la donnée du SDE telle quelle.
- **Brut sans portionSize** : prix équivalent compressé inconnu (M2), mais le retraitement garde son repli `?? 100` actuel (`testCurrentlyReprocessesPerHundredUnitsWhenSdePortionSizeIsMissing`, groupe `mining-sde-fallbacks`), hors périmètre.
- **Prix compressé ramené à 0 par la taxe** avec brut positif : le brut l'emporte (M8 cas 2). Sans brut : 0 connu (M8 cas 3).
- **Entrées tronquées à 500** : la liste de types sans prix du back peut contenir un type absent des entrées chargées : affiché `#typeId` (D7).
- **Divergence front/back** : le front garde le repli `totalValue / quantity` stocké avant le brut (M16). `MiningEntry::setUnitPrice()` n'est appelé nulle part dans `src/` (grep) : ce repli ne vaut que pour d'éventuelles lignes anciennes [À CONFIRMER en base].
- **Type en double dans plusieurs jours ou usages** : listé une fois par ensemble, une fois dans l'union.

## Hors périmètre

- Unifier les trois implémentations du meilleur prix (back, `useMiningPricing`, tableau) ; exposer un meilleur prix calculé par le back sur `MiningEntryResource`.
- Prix structure, taxe d'export et rendement personnalisé dans le calculateur (#38, `issue-38`) ; brut plus élevé ignoré quand une autre source existe (`mining-audit-P2-5`) ; taxe d'export du retraitement (`mining-audit-P3-3`) ; replis SDE du volume et du portionSize de retraitement (`mining-sde-fallbacks`).
- Calendrier et opérations de minage groupé.
- Section Minage du glossaire (branche `docs/mining-specs`, non fusionnée, non touchée).
- Libellés français codés en dur de `getBestPrice()` (« Jita compresse »…).

## Décisions retenues en autonomie (mode nuit)

1. **Rapport compressé/brut** : lu dans le SDE (`portionSize` brut / `portionSize` compressé), pas une constante 1 : aussi simple (`InvType::getPortionSize()` existe, le harnais fournit déjà `portionSize`), juste pour les 186 types vérifiés et pour une forme « lot ». PortionSize absent ou ≤ 0 : inconnu (M1, M2).
2. **Batch Compressed** : non couvert par la recherche de variante (inchangée) ; aucune constante 100 ne subsiste (M6).
3. **Règle unique côté back** : nouveau champ `structureCompressedEquivalentPrice` ; front et calculateur ne divisent plus (M4, M5).
4. **Prix à 0** : prix connu ; « sans prix » = les cinq sources `null` ; sélection `> 0` conservée (M8, M9).
5. **Quantité 0 sans prix** : 0 connu, non listé (M10).
6. **Forme de retour** : tableaux typés + `missingPriceTypeIds`, sans nouvelle classe (M14) ; `missingPriceTypeIds` au niveau de la ressource pour les séries journalières (union), par usage pour `byUsage`, aucun pour le détail par type.
7. **Tableau de bord du ledger** : option conservatrice, total général, profit, ISK/jour et parts `null` dès que le minage est inconnu (voir Blocages potentiels).
8. **`rawUnitPrice` exposé sur `MiningEntryResource`** pour que « inconnu » au front ait le même sens qu'au back (sinon un type au seul prix brut serait affiché inconnu).
9. **Repli front `totalValue / quantity` conservé** (test `miningTabUnknownStats.spec.ts` existant, données anciennes éventuelles).
10. **Libellés** : `IskAmount` / `UnknownValue` existants + deux nouvelles clés i18n (M18).

## Blocages potentiels

- **Total général du tableau de bord `null`** (C5). Un seul minerai sans prix rend inconnus le total, le profit, l'ISK/jour et les parts PVE/minage du ledger, alors que le PVE est connu.
  - Option A (recommandée) : `null` + `missingPriceTypeIds`, conforme au glossaire (« une somme qui contient un inconnu est inconnue ») et aux specs #8 et #255. Impact pratique faible : un minerai avec matériaux de retraitement a toujours une valeur de retraitement (minéraux cotés à Jita) ; « sans prix » ne vise que des types sans matériaux ni ordre (type absent du SDE, gaz sans ordre).
  - Option B : total connu hors types sans prix, marqué partiel. Contredit le glossaire et demande un nouveau concept (« total partiel » existe pour les soldes du dashboard, pas ici).

## Tests existants appelés à changer

Backend (changement voulu) :

- `OreValueServiceCharacterizationTest::testCompressedEquivalentPriceCurrentlyDividesTheCompressedPriceByOneHundredMiningAuditP1_1` (groupe `mining-audit-P1-1`) : 0.1961 → 19.61, 2.2145 → 221.45, 1587.0 → 158700.0, 28.13 → 2813.0, taxé 0.195725 → 19.5725. Renommé sans `Currently…`, le groupe devient un groupe de régression.
- `OreValueServiceCharacterizationTest::testPricesVeldsparRawCompressedAndReprocessedOnJitaAtDefaultYield` : liste des clés (A10), + `structureCompressedEquivalentPrice` `null`.
- `OreValueServiceCharacterizationTest::testPricesTheStructureMarketWithoutExportTaxAndOnlyWithEveryMineralListed` : reste vert sur ses assertions ; à compléter avec `structureCompressedEquivalentPrice` 20.38 (Veldspar) et `null` (Bitumens).
- `MiningBestValueCalculatorCharacterizationTest::testBestUnitPriceCurrentlyWeighsCompressedOreAtOneHundredthOfItsPriceMiningAuditP1_1` : Veldspar 13.3224 → 19.61, Fullerite-C50 28.13 → 2813.0 (A11, A12). Renommé.
- `MiningBestValueCalculatorCharacterizationTest::testCurrentlyValuesAnOreWithoutAnyPriceAtZeroIskMiningAuditP2_5` (groupe `mining-audit-P2-5`) : prix `null`, total `null` + [81143], jour `null`, usage `null` (3 150 u). Renommé sans `Currently…`.
- Même classe, forme de retour seulement (valeurs inchangées : les prix compressés sont retirés par `createCalculator()`) : `testTotalBestValueSumsBestUnitPriceTimesQuantityOverEveryType` (6 892 553.0, []), `testTotalBestValueIsZeroWithoutMinedQuantityNorPriceLookup` (0.0, []), `testDailyBestValuesSumBestUnitPriceTimesQuantityPerDay` (`byDate`, []), `testDailyBestValuesAreEmptyWithoutMinedQuantityNorPriceLookup` (`byDate` [], []), `testTotalsByUsageSumBestValueAndQuantityPerUsage` (+ `missingPriceTypeIds` []).

Restent verts (vérifiés à la lecture, à confirmer par la phase rouge) :

- `MiningBestValueCalculatorCharacterizationTest` : `testReturnsNoBestUnitPricesForNoTypeIdsWithoutLookingUpPrices`, `testBestUnitPriceIsTheReprocessValueAboveTheRawPriceCurrentlyAtTheDefaultYieldIssue38`, `testBestUnitPriceIsTheRawPriceWithoutAnyOtherSource`, `testBestUnitPriceCurrentlyIgnoresAHigherRawPriceWhenAnotherSourceExistsMiningAuditP2_5`, `testBestUnitPriceCurrentlyIgnoresTheStructureMarketAndUsesTheDefaultYieldIssue38`, `testTotalsByUsageAreEmptyWithoutMinedQuantityNorPriceLookup`.
- Autres tests d'`OreValueServiceCharacterizationTest` (aucun n'asserte d'équivalent compressé non `null` hors P1-1).
- `MiningStatsByTypeProviderTest` (bouchon `getBestUnitPrices` à prix connus) ; `MiningEntryCollectionProviderTest` (`getBatchOrePrices` rend []).
- Front : `frontend/tests/components/ledger/miningTabUnknownStats.spec.ts` (repli `totalValue` conservé) ; ses fixtures `MiningStats` et `MiningEntry` seront à compléter (`missingPriceTypeIds: []`, nouveaux champs `null`) si le contrôle de types l'exige.

Tests à créer : aucun test `useMiningPricing` ni `MiningEntriesTable` ni `LedgerDashboardTab` n'existe ; D1 à D9 sont à créer. Aucun test de `MiningStatsProvider`, `MiningStatsDailyProvider`, `LedgerDashboardProvider`, `LedgerDailyStatsProvider` : C1 à C7 sont à créer.

## Fichiers de production à modifier

- `src/Service/OreValueService.php` : M1 à M4, retrait de `COMPRESSION_RATIO`.
- `src/Service/MiningBestValueCalculator.php` : M8 à M11, M14 ; `structureCompressedEquivalentPrice`.
- `src/State/Provider/Ledger/{MiningStats,MiningStatsDaily,MiningStatsByType,LedgerDailyStats,LedgerDashboard,MiningEntryCollection}Provider.php` : M12, M13, M15.
- `src/ApiResource/Ledger/{MiningEntry,MiningStats,MiningStatsDaily,MiningDailyStats,MiningTypeStats,LedgerDashboard,LedgerDailyStats,LedgerDay}Resource.php` : M15 (la docblock « compressedPrice / 100 » de `MiningEntryResource` et celle du calculateur deviennent fausses : à retirer).
- `frontend/src/stores/ledger.ts` (types), `frontend/src/composables/useMiningPricing.ts`, `frontend/src/components/ledger/{MiningEntriesTable,MiningStatsCards,MiningTab,LedgerDashboardTab}.vue`, `frontend/src/i18n/locales/{fr,en}.json` : M16 à M18.

## Termes du glossaire à ajouter

Section Minage absente de `docs/glossary.md` (branche `docs/mining-specs` non fusionnée) ; propositions à reporter quand elle sera fusionnée :

- **Meilleur prix unitaire** (Best unit price) : prix retenu pour valoriser une unité minée (M8) ; inconnu si le type est **sans prix** (M9). `MiningBestValueCalculator::getBestUnitPrices()`.
- **Prix équivalent compressé** (Compressed equivalent price) : prix d'une unité compressée ramené à une unité brute par le rapport des `portionSize` du SDE (M1). `compressedEquivalentPrice`, `structureCompressedEquivalentPrice`.
- **Minerai compressé / Batch Compressed** : « Compressed X » = 1 unité brute ; « Batch Compressed X » = un lot de 100 unités brutes, jamais retenu.
- **Valeur du minage** (Mining value) : `Σ meilleur prix unitaire × quantité` d'un ensemble d'entrées (total, jour, usage), inconnue si un type de quantité > 0 est sans prix (M10).
- **Usage** d'une entrée de minage (`sold`, `corp_project`, `industry`, `unknown`).

Modification proposée de l'entrée **Coût inconnu** (non appliquée ici, à reporter par le thread) : ajouter « La valeur du minage (`MiningBestValueCalculator` : `getBestUnitPrices`, `getTotalBestValue`, `getDailyBestValues`, `getTotalsByUsageBestValue` ; stats minage, détail par type, tableau de bord et stats journalières du ledger) est aussi `null` dès qu'un type de quantité positive n'a aucune des cinq sources de prix, et liste les types dans `missingPriceTypeIds` ; une quantité nulle vaut 0 (#260). »

## Décisions (à reporter au journal)

- #259 : rapport unités brutes / unité compressée = `portionSize` brut / `portionSize` compressé (SDE) ; inconnu si un portionSize manque ou vaut ≤ 0 ; `COMPRESSION_RATIO` supprimé.
- #259 : « Batch Compressed X » jamais retenu comme variante (recherche inchangée).
- #259 : nouveau champ API `structureCompressedEquivalentPrice` ; le front et le calculateur ne divisent plus par 100.
- #259 : les valeurs du minage et du ledger augmentent (le compressé devient retenu) : changement voulu.
- #260 : « sans prix » = cinq sources `null` ; un prix à 0 est connu ; sélection `> 0` inchangée.
- #260 : quantité 0 sans prix = 0 connu, non listée.
- #260 : calculateur en tableaux typés + `missingPriceTypeIds` (pas de nouvelle classe).
- #260 : `missingPriceTypeIds` ajouté à `MiningStatsResource` (+ par usage), `MiningStatsDailyResource`, `LedgerDashboardResource`, `LedgerDailyStatsResource` ; pas sur le détail par type.
- #260 : tableau de bord du ledger : total, profit, ISK/jour, parts `null` si le minage est inconnu (option A, à valider).
- #260 : `rawUnitPrice` exposé sur `MiningEntryResource` ; repli front `totalValue / quantity` conservé.
- Glossaire : entrée **Coût inconnu** à compléter (texte ci-dessus) ; termes de minage à ajouter avec la section Minage.
