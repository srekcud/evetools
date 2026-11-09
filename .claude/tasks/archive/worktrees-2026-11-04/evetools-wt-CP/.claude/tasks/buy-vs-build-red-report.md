# Buy vs Build sur le noyau industrie : phase rouge (rang 2)

- Date : 2026-10-22. Worktree `evetools-wt-CP`, branche `feat/buy-vs-build-on-core` (= `main` ac0a555). Rien de commité, rien d'indexé.
- Écrit uniquement dans `tests/` et `frontend/tests/`. `src/`, `config/`, `frontend/src/` intacts (`git status --short src config frontend/src` vide).
- Spec : `.claude/tasks/industry-core/buy-vs-build-wiring.md` (§1, §2, §3, §5) ; décisions BB-a à BB-k.

## Commandes

PHP (le script `wt-php.sh` étant cassé pour les worktrees, équivalent `docker run` mode unit : image `evetools-app-dev`, worktree sur `/app`, `vendor` du dépôt principal en lecture seule, `DATABASE_URL` sur `127.0.0.1:1`) :

```
docker run --rm --user $(id -u):$(id -g) -e HOME=/tmp ... -e DATABASE_URL='postgresql://unit:unit@127.0.0.1:1/unit?serverVersion=16' \
  -v <worktree>:/app -v <main>/vendor:/app/vendor:ro -w /app --entrypoint php evetools-app-dev vendor/bin/phpunit --no-coverage ...
```

Frontend : `docker run --rm -v $PWD/frontend:/app -v evetools_frontend_node_modules:/app/node_modules:ro --tmpfs /app/node_modules/.vite-temp -w /app node:22-alpine npx vitest run tests/components/industry/buyVsBuild` puis `... npx vue-tsc --noEmit -p tsconfig.json`.

## Résultat

- Suite Unit : `Tests: 3099, Errors: 65, Failures: 1, Warnings: 1, Incomplete: 1`. Les 66 tests rouges sont exactement les 66 tests Buy vs Build (5 fichiers). L'incomplet est préexistant (`InstallCostTest::testCopyingInstallCostWithSccSurcharge`, D11a). Tout le reste est vert, `ProfitMarginGoldenCharacterizationTest` compris (39 tests, 559 assertions, après extraction du trait).
- Raisons d'échec :
  - 62 tests : `TypeError: BuyVsBuildService::__construct(): Argument #1 ($treeService) must be of type IndustryTreeService, ProductionCostCalculator given` (harnais appelant le constructeur cible) ;
  - `testAnonymousRequestIsUnauthorized` : même `TypeError`, levé avant l'exception attendue parce que `provide()` construit le service golden avant d'appeler le provider ; passera au vert sans changement ;
  - 3 tests provider à service stubé : `TypeError: Cannot assign null to property BuyVsBuildResource::$missingPriceTypeIds` (+ `Undefined array key "missingPriceTypeIds"`) : le contrat k n'est pas encore en place.
- Frontend vitest : 18 rouges sur 20 (`TypeError: Cannot read properties of undefined (reading 'length')` sur `result.unknownInstallCostReasons`, `BuyVsBuildTab.vue:389`) ; les 2 verts sont les tests de libellé sans montage. vue-tsc : 11 erreurs TS2353 `'missingData' does not exist in type 'BuyVsBuildResult' | 'BuyVsBuildComponent'`, toutes dans les 3 specs Buy vs Build.

## Socle de test

### `tests/Unit/Service/Industry/StubsGoldenSdeRepositories.php` (nouveau trait)

Extraction (§5.2) des stubs SDE privés de `ProfitMarginGoldenCharacterizationTest` : `sdeProductRepository()`, `sdeMaterialRepository()`, `sdeInvTypeRepository()`, `sdeBlueprintRepository()`, `sdeActivityTimeRepository()`, données d'invention du Hail L (blueprint T1 synthétique 2 000 000 001, produit T1 2 000 000 002 de 5 000 unités/run avec Tritanium 1 000 + Pyerite 500, datacores A/B × 2 par tentative, probabilité 0,34, 10 runs inventés), `loadGoldenSde()`, et l'état `$recipes`, `$typeNames`, `$typesMissingFromSde`, `$hailLInventionProbability`. Aucun commentaire ni docblock.

### `ProfitMarginGoldenCharacterizationTest.php` (modifié, sans changement de comportement)

Utilise le trait ; ses constantes, propriétés et méthodes SDE déplacées sont supprimées, `setUp()` appelle `loadGoldenSde()` puis ajoute les noms de decryptors. 39 tests verts avant et après.

### `BuildsBuyVsBuildOnGoldenSde.php` (réécrit)

- Construit le service cible (§2) : `new BuyVsBuildService(new ProductionCostCalculator(SdeBlueprintCatalog, SdeInventionCatalog, JitaMarketPrices, EsiAdjustedPrices, EsiSystemCostIndices, RigCategories), SdeBlueprintCatalog, UserProductionStructures, JitaMarketService, StructureMarketService, TypeNameResolver)`.
- Vrais objets du noyau et vrais adaptateurs ; stubs aux frontières seulement : dépôts SDE (trait), `JitaMarketService` (`getWeightedSellPricesWithFallback` et `getPricesWithFallback` sur la même table de prix), `StructureMarketService`, `EsiClient` (indices et prix ajustés synchronisés dans un vrai `EsiCostIndexService` sur `ArrayAdapter`), ports `RigCategories` (null) et `UserProductionStructures` (aucune structure).
- Paramètre `isT2` et stubs `IndustryTreeService`, `IndustryBonusService`, `InventionService`, `findMaterialsForBlueprints` retirés (§5.5). Signature nommée conservée (`jitaPrices`, `structurePrices`, `adjustedPrices`, `costIndices`, `structureMarket`), donc `BuyVsBuildServiceWithoutSellStructureTest` reste inchangé.
- `goldenUser()` : utilisateur avec id (réflexion sur `User::$id`, §5.6).
- Aides `marketPriceMissing()`, `adjustedPriceMissing()`, `costIndexMissing()`, `inventionProbabilityMissing()` au format de `MissingDataPresenter`.

## Formules utilisées (toutes recalculées à la main)

- Quantité d'un matériau (R1) : `max(runs, ceil(round(runs × base × (1 − ME/100), 2)))` ; réaction : ME 0.
- Composant (BB-d, BB-f) : `runs_c = ceil(quantité racine / sortie par run)`, ME 10 en fabrication, 0 en réaction ; ses matériaux sont achetés.
- EIV(runs) = `runs × Σ base × prix ajusté`.
- Installation sans structure (R6) : `EIV × (indice + 0,0025 + 0,04)`, soit `× 0,0925` à 0,05 et `× 0,1425` à 0,10.
- Invention du Hail L (BB-c, sans decryptor, compétences 0, probabilité 0,34, 10 runs) : `tentative = 2 × prix(A) + 2 × prix(B) + EIV_HailL(1 run) × 0,02 × (indice invention + 0,0425) + EIV_T1(1 run) × 0,02 × (indice copie + 0,0425)` ; `coût = tentative × runs / 3,4`. EIV_HailL(1) = 15 × 9 000 + 22 000 + 3 000 × 50 + 1 200 × 145 = 481 000 ; EIV_T1(1) = 1 000 × 4 + 500 × 8 = 8 000.
- Hail L inventé : ME du BPC inventé = 2, le ME demandé est ignoré.
- `totalProductionCost = optimalMixCost + Σ feuilles racine hors composants + installation racine + invention` ; verdict build si `|build − buy| ≤ 1 ISK` (BB-e).
- Contrôle (§2 du plan) : R.A.M. 1 run ME 10 = 50 305 + 47 236 × 0,0925 = 54 674,33.

Contrôle croisé : un calculateur indépendant (script PHP hors dépôt, `/tmp/bvb/calc.php`) applique ces formules ; les montants de chaque test en sortent et ont été revérifiés à la main. Les données sont choisies pour qu'aucun arrondi ne tombe sur une demi-valeur de centime (voir questions ouvertes, point 6). Ensuite, pour valider le câblage seulement, les tests ont tourné contre un prototype jetable de `BuyVsBuildService` dans une copie hors dépôt (`/tmp/bvb/proto`) : 66 sur 66 verts. Même chose pour le frontend dans `/tmp/bvb/fe` : 20 sur 20 verts. Aucune valeur attendue n'a été copiée d'une exécution.

## Par fichier

### `BuyVsBuildServiceCharacterizationTest.php` (réécrit, 23 tests)

Données : Hail L à `FACTORY` (fabrication 0,05, réaction 0,10, copie 0,03, invention 0,06), Jita : Morphite 10 000, R.A.M. 20 000, FC 60, Fullerides 160, minéraux 5/10/50/100/800, datacores 3 500 / 4 200, etc. Structure de vente : R.A.M. 25 000. Courtage 3 %, taxe 4,5 %.

| Test | Statut | Formules / valeurs |
|---|---|---|
| `testHailLTenRunsBuildsTenRootRunsAndSellsTenRunsIssue2` | réécrit (#2, ex-CurrentlyWrong) | 10 runs ME 2 : FC 29 400 → 3 runs (15 HFB, 300, 300 ; 465 000 + 423 000 × 0,1425 = 525 277,5) ; Fullerides 11 760 → 4 runs (800 000 + 616 000 × 0,1425 = 887 780) ; R.A.M. 10 → 1 run (54 674,33), achat structure 250 000. Racine : Morphite 147 × 10 000, installation 4 810 000 × 0,0925 = 444 925, invention 16 397,65 × 10/3,4 = 48 228,38. Total 3 430 885,21 ; marge (25 000 000 × 0,925 − total)/total = 574,02 % |
| `testComponentsAreSortedByDescendingSavings` | ajouté (ex-`BuyVsBuildServiceTest`) | 1 run : FC 176 400 − 175 092,5 = 1 307,5 ; R.A.M. 25 000 − 54 674,33 ; Fullerides 188 160 − 221 945 |
| `testTotalProductionCostIsTheOptimalMixPlusTheRootLeavesTheRootInstallCostAndTheInvention` | ajouté (ex-`testNoDoubleCounting…`) | composants = R.A.M., FC, Fullerides seulement (pas HFB/NFB) ; 388 252,5 + 150 000 + 44 492,5 + 4 822,84 = 587 567,84 ; marge 293,57 |
| `testInventedProductIgnoresTheRequestedMeAndConsumesWithTheMeOfTheInventedCopy` | ajouté | ME 10 demandé : FC 2 940, Fullerides 1 176 (ME 2) |
| `testInventedProductWithoutInventionProbabilityHasAnUnknownTotalAndListsTheMissingProbability` | ajouté | `missingData = [invention_probability, 2 000 000 001]`, total null, mix 388 252,5 |
| `testUserIndustryBlacklistIsIgnoredSoEveryComponentIsStillCompared` | ajouté (D13) | blacklist utilisateur FC + Fullerides : 3 composants, total inchangé |
| `testComponentWithoutAnyBuyPriceAndWithAnUnpricedMaterialHasAnUnknownVerdictAndUnknownTotals` | recalculé | missingData composant et résultat `[market_price 16662, market_price 16679]` |
| `testStructurePriceIsPreferredToACheaperJitaPriceForTheBuyCost` | recalculé | −29 674,33 ; 29 674,33 / 54 674,33 = 54,27 % |
| `testComponentWhoseBuildCostExceedsItsBuyCostByAtMostOneIskIsBuilt` | remplace `…EqualsItsBuyCost` (BB-e) | structure 54 673,5 : écart 0,83 → build, savings −0,83, 0,0 %, mix 54 674,33 + 175 092,5 + 188 160 = 417 926,83 |
| `testComponentWhoseBuildCostExceedsItsBuyCostByMoreThanOneIskIsBought` | ajouté (BB-e) | structure 54 673 : écart 1,33 → buy, mix 417 925,5 |
| `testWithoutAnyJitaPriceEveryComponentHasAnUnknownVerdictAndEveryCostIsUnknown` | recalculé | 17 `market_price` (canonicalisé) ; FC : `[4246, 16656, 16660, 16673]` dans l'ordre build puis achat |
| `testZeroStructureSellPriceCurrentlyHidesTheJitaPriceAndLeavesTheMarginUnknownIssue8` | renommé (+ `Issue8`, groupe `issue-8`), montant recalculé | bug #8 conservé : vente 0,0, marge null, total 587 567,84 |
| `testProductWithoutSellPriceHasAnUnknownSellPriceAndMargin` | ajouté (ex-`BuyVsBuildServiceTest`) | total 587 567,84 |
| `testPricesAndCostsAreRoundedToTwoDecimals` | recalculé | Tritanium 501 × 5,678 ; EIV R.A.M. 47 236,556 × 0,0925 = 4 369,38 ; build 55 014,06 ; FC 2 940 × 59,9993 = 176 397,94 ; total 592 567,85 ; marge 290,35 |
| 3 tests `…AbsentFromTheSde…` (#115) | ajoutés (ex-`BuyVsBuildServiceTest`) | nom null, montants inchangés |
| `testRootLeavesArePricedAtJitaSoNitrogenFuelBlockCostsItsMaterials` | recalculé | 36 295 + 34 140 × 0,0925 = 39 452,95 ; marge (600 000 × 0,97 − total)/total = 1 375,17 % |
| `testRootLeafIsPricedAtJitaWhenNoComponentConsumesIt` | recalculé | Morphite 10 400 : +15 × 400 = 593 567,84 |
| `testRootLeafAlsoUsedByAComponentIsPricedAtJita` | recalculé (Marshal) | mix 213 773 432,15 ; total 547 438 954,65 ; marge −45,2 ; seul le Capital Jump Drive acheté |
| `testFerniteCarbideTenRunsBuildsTenReactionRunsAndPricesEachComponentOnTheRootDemandOnlyIssue2` | renommé et recalculé (BB-d, ex-CurrentlyWrong) | HFB 50 → 2 runs (68 140 + 67 380 × 0,0925 = 74 372,65) ; FA 1 000 → 5 runs (560 000 + 505 000 × 0,1425) ; CP 5 runs (525 000 + 472 000 × 0,1425), achat structure 240 000 ; racine 1 410 000 × 0,1425 = 200 925 ; total 815 297,65 |
| `testFuelBlockConsumedByAReactionIsLabelledT1Component`, `testManufacturedComponentConsumingAReactionProductIsLabelledT2Component` | conservés (#101) | |

### `BuyVsBuildServiceUnknownPriceTest.php` (porté, 14 tests, groupe `issue-8`)

Les 14 cas de la version stubée (arbre Sabre) sont portés sur le harnais golden (Hail L 1 run, sans structure sauf mention). Base : R.A.M. acheté à Jita 20 000 (−34 674,33, 63,42 %), buildAll 451 711,83, buyAll 20 000 + 176 400 + 188 160 = 384 560, mix 20 000 + 175 092,5 + 188 160 = 383 252,5, total 582 567,84, marge 296,95.

| Cas (#8) | Données retirées | Attendu |
|---|---|---|
| R2 achat à Jita | aucune | ci-dessus, `missingData []` |
| Achat inconnu → build | R.A.M. | mix 54 674,33 + 175 092,5 + 188 160 = 417 926,83, total 617 242,17, `[market_price 11476]` |
| Matériau inconnu → buy | Mexallon | ligne Mexallon `null`, buildAll null, `[market_price 36]` |
| Les deux inconnus → unknown | Mexallon + R.A.M. | totaux null, `[36, 11476]` |
| Feuille racine inconnue | Morphite | total null, `[market_price 11399]` |
| Analyse complète | aucune | `assertSame` complet |
| Seulement un prix structure | R.A.M. Jita, structure 25 000 | buyAll 389 560, total 587 567,84, `missingData []` partout |
| Un build inconnu | Platinum Technite | buildAll null, `[16662]` |
| Un achat inconnu | FC | buyAll null, FC build, `[16673]` |
| Un verdict unknown | Platinum Technite + Fullerides | mix et total null, `[16662, 16679]` |
| Tri des savings null | R.A.M. + HFB | `[Fullerides −33 785, R.A.M. null, FC null]` |
| Matériau absent de deux composants listé une fois | HFB (produit Fernite Carbide) | HFB build 34 270 + 34 140 × 0,0925 = 37 427,95 ; FA et CP en buy ; mix 92 427,95 ; total + 141 000 × 0,1425 = 112 520,45 ; `[market_price 4246]` une seule fois |
| Prix 0 sur matériau et feuille | Mexallon 0, Morphite 0 | R.A.M. 44 674,33, total 432 567,84 |
| Prix d'achat 0 | R.A.M. 0 | 100 %, mix 363 252,5, total 562 567,84 |

### `BuyVsBuildServiceUnknownInstallCostTest.php` (recalculé, 9 tests, groupe `issue-7`)

Indices : `FACTORY` + copie 0,035 + invention 0,065 ; `MANUFACTURING_ONLY` + copie 0,02 + invention 0,05 ; `REACTION_ONLY` inchangé. Ajouts : datacores (3 600 / 4 100) et prix ajustés des intrants des fuel blocks (sinon l'installation du HFB serait inconnue par `adjusted_price` et non par `cost_index`). Motifs : `missingData` en ordre d'apparition, plus de tri.
- Complet : FC 154 500 + 20 092,5 ; R.A.M. 51 145 + 4 369,33 ; Fullerides 194 500 + 21 945 ; invention (15 400 + 9 620 × 0,1075 + 160 × 0,0775) / 3,4 = 4 837,22 ; total 632 236,55 ; marge 280,4.
- Système sans indice : tout en buy, `[cost_index]` une fois.
- `MANUFACTURING_ONLY` : R.A.M. 47 236 × 0,0825 = 3 896,97 ; total 640 478,54.
- Fernite Carbide à `REACTION_ONLY` : FA 109 500 + 101 000 × 0,1225 ; CP 102 900 + 94 400 × 0,1225 ; total 154 500 + 17 272,5.
- `testUnknownInstallCostReasonsAreListedOnceAndSorted` devient `testMissingInstallDataIsListedOnceInOrderOfAppearance` : `[cost_index, adjusted_price 16660]`.
- Les deux tests de système de réaction prennent le groupe `issue-99` ; à `REACTION_ONLY`, FC 141 000 × 0,1225 = 17 272,5 et total 629 416,55.

### `BuyVsBuildServiceWithoutSellStructureTest.php`

Inchangé (3 tests), rouge par le seul harnais.

### `BuyVsBuildServiceTest.php`

Supprimé (non indexé). Cas portés : tri par savings, identité du total (ex-NoDoubleCounting), 3 tests #115, produit sans prix de vente. Les autres (build moins cher, pas de composant, marge) sont couverts par le test NFB et l'analyse complète.

### `tests/Unit/State/Provider/Industry/BuyVsBuildProviderTest.php` (17 tests)

- `new User()` remplacé par `goldenUser()` partout. Docblock de classe et bandeau `Helpers` supprimés.
- Données : datacores (3 300 / 3 900), indices de copie et d'invention (Jita 0,01 / 0,03, usine 0,03 / 0,06), et Hydrogen Isotopes ajusté 35 → 36.
- Montants recalculés :
  - défaut à Jita : FC 2 940 (ME 2 du BPC inventé), installation 141 000 × 0,0825 = 11 632,5, total 559 297,9 ;
  - #99 : FA 101 000 × 0,0825 = 8 332,5, CP 7 788, HFB 34 140 × 0,0925 = 3 157,95 ; Hail L : mix 374 792,5, total 573 813,72 ; FC tout à l'usine : FA 14 392,5, total 113 791,45 ; réaction au favori : mix 93 698,95, total 105 331,45.
- Tests #8 et #7 à service stubé : `missingData` à la place des deux anciens champs.
- Ajout de `testMeQueryParameterIsForwardedForAProductThatIsNotInvented` (R.A.M., `me=0`, Jita : 55 320 + 47 236 × 0,0625 = 58 272,25). Raison : pour Hail L, le ME n'est plus observable.

### Frontend

- `buyVsBuildUnknownPrice.spec.ts` et `buyVsBuildUnknownInstallCost.spec.ts` : fixtures en `missingData` (résultat et composant). Mêmes libellés attendus.
- Ajout dans le second fichier : libellé `industry.inventionUnknown.missing_invention_probability` affiché, sans motif d'installation.
- Nouveau fichier `buyVsBuildBlacklistNotice.spec.ts` : la mention BB-h est toujours affichée, texte exact FR et EN.

## Tests appelés à casser à l'implémentation

Aucun en dehors des 66 tests listés ci-dessus et des 18 specs frontend. Ce lot les rouvre volontairement. Les autres fichiers qui figent `missingPriceTypeIds` (Pivot Advisor, BPC Kit, scanner) portent sur d'autres écrans, que ce lot ne touche pas. `grep "Currently.*Issue|CARACTÉRISATION"` ne trouve plus, pour Buy vs Build, que `…CurrentlyHides…Issue8`, conservé.

## Questions ouvertes (contrat figé par les tests, à valider)

1. `quantity` (composant et `buildMaterials`) est figé en `int`, comme sur `main` et comme dans le docblock de la resource. Le noyau donne des `float` (`LeafCost::$quantity`) : l'implémentation doit caster.
2. `missingData` est la dernière clé du résultat et la dernière clé de chaque composant : `assertSame` vérifie l'ordre des clés.
3. Le `missingData` du résultat ne reprend pas `leaves[c]` (prix Jita) d'un composant dont l'achat retenu est le prix structure (§3, « feuilles » lues comme feuilles hors composants). Cela garde le cas #8 « seulement un prix structure → aucune donnée manquante ».
4. Ordre du `missingData` résultat : vérifié exactement quand l'ordre des composants n'y change rien. Il est canonicalisé dans un seul test, le cas « sans aucun prix Jita ». Composant : build puis achat.
5. `buildTypeIds` et `buyTypeIds` suivent l'ordre des matériaux de la recette, comme sur `main`.
6. Données modifiées pour éviter un arrondi sur une demi-valeur de centime (33 690 × 0,0925 = 3 116,325) : Hydrogen Isotopes ajusté 36 au lieu de 35 dans le provider et dans le port FC, Nitrogen Isotopes 36 dans le test NFB.
7. Le nom `testWithoutParametersNorSettingsAnalyzesOneRunAtMe10InJitaWithDefaultFees` est gardé : le provider transmet bien 10, mais le Hail L l'ignore.
8. Clé i18n de la mention BB-h laissée à l'implementer : le test vérifie le texte, pas la clé ni l'emplacement.
9. Constantes typées dans un trait : `HAIL_L`, `TRITANIUM` et `PYERITE` sont définies dans `StubsGoldenSdeRepositories` et redéfinies avec la même valeur dans les classes de test. Accepté en PHP 8.5 (vérifié).

## Empreintes (`sha256sum | cut -c1-12`)

```
e9dcbc667f7c  tests/Unit/Service/Industry/StubsGoldenSdeRepositories.php
d8ce44364bd0  tests/Unit/Service/Industry/BuildsBuyVsBuildOnGoldenSde.php
c807fbf2e80a  tests/Unit/Service/Industry/ProfitMarginGoldenCharacterizationTest.php
aa132cbfede5  tests/Unit/Service/Industry/BuyVsBuildServiceCharacterizationTest.php
b73aaaf603da  tests/Unit/Service/Industry/BuyVsBuildServiceUnknownPriceTest.php
69b63ea1cdcb  tests/Unit/Service/Industry/BuyVsBuildServiceUnknownInstallCostTest.php
11a35f5ec388  tests/Unit/Service/Industry/BuyVsBuildServiceWithoutSellStructureTest.php (inchangé)
ad9fbcccfc22  tests/Unit/State/Provider/Industry/BuyVsBuildProviderTest.php
947c90e5416a  frontend/tests/components/industry/buyVsBuildUnknownPrice.spec.ts
8bf1eaaa05f8  frontend/tests/components/industry/buyVsBuildUnknownInstallCost.spec.ts
ca902683baa9  frontend/tests/components/industry/buyVsBuildBlacklistNotice.spec.ts
supprimé      tests/Unit/Service/Industry/BuyVsBuildServiceTest.php
```

## Message de commit proposé

```
test(industry): pin buy vs build on the industry core

Golden harness wires the target BuyVsBuildService on ProductionCostCalculator; stubbed unknown-price cases ported, mocked service test removed.
Specs switch to missingData and add the blacklist notice.
```
