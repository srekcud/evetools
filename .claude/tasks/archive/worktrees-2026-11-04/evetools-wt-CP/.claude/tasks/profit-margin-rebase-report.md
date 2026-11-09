# Rebase de 5bdfa57 (phase rouge Profit Margins sur le noyau) sur main 97e7271

Date : 2026-10-22. Résolution indexée (`git add` / `git rm`), `git rebase --continue` non lancé.

## Résolution

| Fichier | Résolution |
|---|---|
| `tests/Unit/Service/Industry/ProfitMarginGoldenCharacterizationTest.php` | Base = version rouge (harnais du constructeur cible, tests A-D) ; tests de main portés dessus |
| `tests/Unit/Service/Industry/ProfitMarginServiceTest.php` | `git rm` : les 5 tests ajoutés par main sont portés dans le golden (voir e30d996, 927cd61, 8661e28) |
| `tests/Unit/State/Provider/Industry/ProfitMarginProviderTest.php` | Fusion : `Decryptors` + test 400 + `missingData` du rouge, tests de main, `analysis(array $overrides)` de main |

Changements du harnais rouge nécessaires sur main :
- `TypeNameResolver::resolve()` n'existe plus (b8bdab8) : le stub du rouge aurait levé une erreur. Remplacé par un vrai `TypeNameResolver` sur un `InvTypeRepository` stubé (`find`, `findByTypeIds`), qui renvoie `null` pour les types de `$typesMissingFromSde`.
- Nouveaux réglages : `$brokerFeeRate`, `$salesTaxRate`, `$leavesWithZeroJitaPrice`, `$withReactionCostIndex`, `$typesMissingFromSde`.
- `assertJobInstallSteps` : `installCost` null vérifié par `assertNull` (avec un delta, `null` passait pour `0.0`), 5e élément optionnel = `missingData` de l'étape.
- `assertMargin` : version de main (sans `$expectedMargin`, plus utilisé).
- Constantes `HAIL_L_ONE_RUN_ENGINE_TOTAL_COST` et `HAIL_L_ONE_RUN_PROCESS_TOTAL_COST` passées à 12 décimales (694548.564245115, 673574.595153614, valeurs du tableau d'options du rouge) : à 6 décimales, l'erreur dépassait le delta 1e-9 d'`assertMargin` sur des marges de l'ordre de 2 800 %.
- Écart avec la consigne : le rouge ne câble pas les vrais adaptateurs `SdeRigCategories`, `ConfiguredProductionStructures`, `SdeDecryptors` ; il stube les ports `RigCategories`, `UserProductionStructures`, `Decryptors`. Harnais gardé tel quel.

## Données de calcul (toutes du harnais)

Prix Jita = (typeId mod 100 000) / 100, prix ajusté = (typeId mod 100 000) / 1 000. Installation sans structure = EIV(ME 0) x (indice + 0,25 % + 4 % SCC) = EIV x 0,0925 en fabrication (indice 0,05), x 0,0825 en réaction (0,04). Mode coût marginal : quantités = runs x base x (1 - ME/100), sans arrondi ; intermédiaires ME 10.

- Nitrogen Fuel Block, 1 run ME 10 : matériaux 153 280,656 ; EIV 17 031,184 → installation 1 575,38452 ; total 154 856,04052 ; par unité / 40 = 3 871,401013. Robotics 1 x 0,9 = 0,9 x 98,48 = 88,632.
- Hail L, 1 run : installation = Hail L 70 216,261 x 0,0925 = 6 495,0041425 + R.A.M. (1 - ME/100)/100 run x 46,838 x 0,0925.
  - ME 5 (Process) : R.A.M. 0,0095 run → 6 495,0453013925 ; matériaux 666 949,462149.
  - ME 3 (Parity, 2 + 1) : R.A.M. 0,0097 run → 6 495,0461678955 ; matériaux = 15 x 0,97 x 113,99 + 3 000 x 0,97 x 166,73 + 1 200 x 0,97 x 166,79 + 0,0097 x 0,9 x (556 x 0,34 + 444 x 0,35 + 222 x 0,36 + 82 x 0,37 + 36 x 0,38) = 680 990,5034574. Contrôle : 680 990,5034574 + 6 495,0461678955 + 73,38129865 = 687 558,930924 = total Parity du tableau rouge.
  - Coûts d'invention par option et totaux : tableau du rouge (`testHailLListsTheNoDecryptorOption...`), non recalculés.
- Fernite Carbide, 10 runs : Hydrogen Fuel Block 2,5 runs x EIV 17 031,634 x 0,0925 = 3 938,5653625 ; Robotics 2,5 x 1 x 0,9 = 2,25 ; matériaux 715 971,765 (rouge).

## Par commit de main

### f60575c, marges et volume inconnus
| Test main | Sort | Détail |
|---|---|---|
| golden `testZeroProductionCostLeavesVenueMarginsUnknownButKeepsRevenueFeesAndProfit` | Abandonné | Inatteignable sur le noyau : coût total connu ⇒ > 0 (SCC 4 % x EIV, un prix ajusté ≤ 0 est une donnée manquante dans `EsiAdjustedPrices`) ; sans système le coût est inconnu. Remplacé dans le rouge par `testProductWithoutAnyKnownCostHasUnknownCostAndNoMarginIssue8`. Voir Q4 |
| golden `testProductWithoutDailyVolumeHasUnknownDailyVolume` | Porté tel quel | Remplace la caractérisation `...CurrentlyReportsZeroVolume` du rouge |
| golden `testDecryptorOptionWithoutAnySellPriceHasUnknownBestMargin` | Déjà couvert | `testDecryptorOptionWithoutAnySellPriceHasNoBestMarginIssue8` du rouge, même assertion |
| golden `testDecryptorOptionBestMarginKeepsItsOnlyVenueMarginBelowMinus999Percent` | Réexprimé | Ancien : -1 496,80 / -1 494,61 (coût 652 513,73). Nouveau : (9 100 000 - 18 200 000 - T) / T x 100, T = 694 548,564245115 → -1 410,2036 ; Process T = 673 574,595153614 → -1 451,0011 ; `jitaSell` = assertMargin(9 100 000, 18 200 000, T) |
| provider `testUnknownDailyVolumeAndMarginsReachTheResource` | Porté tel quel | |

### e30d996, nom inconnu des datacores
| `ProfitMarginServiceTest::testInventionDatacoreMissingFromTheSdeIsRelayedWithANullName` | Réexprimé dans le golden | Stub Datacore B absent du SDE : `datacores` = ['Stub Datacore A', null] ; invention 42,493532368 et total 694 548,564245115 inchangés (au lieu de 405 000 / 465 000 du mock) ; `missingData` [] |

### 927cd61, nom inconnu du produit
| `ProfitMarginServiceTest::testProductKnownToTheSdeKeepsItsSdeNameInTheMarginAnalysis` | Déjà couvert | `testMarginsChargeBrokerFee...` vérifie typeId, `typeName` 'Nitrogen Fuel Block', quantité, marges |
| `ProfitMarginServiceTest::testProductAbsentFromTheSdeHasAnUnknownTypeNameAndUnchangedMargins` | Réexprimé dans le golden (`...UnchangedCostsAndMargins`) | Nitrogen Fuel Block absent du SDE : `typeName` null, typeId 4051, 40 unités, matériaux 153 280,656, installation 1 575,38452, total 154 856,04052, par unité 3 871,401013, `jitaSell` = assertMargin(180 000, 12 960, 154 856,04052). Ancien : Robotics mocké, total 500 000, marge 15 % |
| golden : harnais `TypeNameResolver` réel | Porté | Voir Résolution |

### 6b1a8bd, nom inconnu jusqu'à la resource
| provider `testProductMissingFromTheSdeReachesTheResourceWithAnUnknownTypeNameAndItsTypeId` | Porté tel quel | |

### 8c2323a, l'ordre d'achat ne paie que la taxe de vente
| golden `testMarginsChargeBrokerFeeAndSalesTaxOnSellOrdersButOnlySalesTaxOnBuyOrders` | Porté (renomme le test rouge) | `structureBuy` frais 160 000 x 3,6 % = 5 760 (rouge : 11 520) ; commentaires de caractérisation retirés comme sur main |
| golden `testBuyOrderWithoutSalesTaxHasNoFeeWhileSellOrdersStillPayTheBrokerFee` | Porté, coût réexprimé | Frais 9 000 / 8 800 / 0 ; coût = constante 154 856,04052 |
| golden `testDecryptorOptionBestMarginIsTheBuyOrderWhenOnlyTheBrokerFeeMadeItLose` | Réexprimé | Frais 1 452 500 / 1 442 000 / 400 000 inchangés ; meilleur lieu = ordre d'achat 19 600 000 ; bestMargin = (19 600 000 - T) / T x 100 : sans decryptor 2 721,9769 (ancien T 652 513,73), Process 2 809,8485 |

### 8661e28, invention inconnue sans prix de decryptor
| `ProfitMarginServiceTest::testDecryptorOptionWithoutMarketPriceHasUnknownInventionCostProductionCostAndMargin` | Réexprimé dans le golden | Parity (34204) sans prix Jita, Jita produit 140 : option Parity `inventionCost`, `totalProductionCost`, `bestMargin` null, `probability` 0,51 = 0,34 x 1,5 ; sans decryptor 42,493532368 / 694 548,564245115 / (649 600 - T) / T x 100 = -6,4716 ; Accelerant 108,397506696 / 680 573,42604454 ; `missingData` [] (aucun decryptor choisi). Ancien (mock Sabre) : 2 400 / 11 400, 92 400 / 101 400 |
| `ProfitMarginServiceTest::testSelectedDecryptorWithoutMarketPriceMakesTheTotalCostAndMarginsUnknown` | Réexprimé dans le golden | Parity choisi : matériaux ME 3 680 990,5034574, installation 6 495,0461678955 (ancien 5 000 / 4 000) ; invention, total, par unité null ; marges null ; `invention.unknownReason` 'missing_market_price' gardé ; `missingData` [['market_price', 34204]]. Voir Q2 |

### 9653926, marges inconnues sans prix d'un matériau
| golden `testFullyPricedMaterialsKeepTheirCostsAndHaveNoMissingPriceTypeIds` | Réexprimé (`...WithoutMissingData`) | Robotics 0,9 x 98,48 = 88,632 (ancien 1 / 98,48) ; matériaux 153 280,656 ; total 154 856,04052 (ancien 154 252,8492) ; par unité 3 871,401013 ; `missingPriceTypeIds` [] → `missingData` [] |
| golden `testMaterialWithoutJitaPriceMakesMaterialCostTotalCostAndMarginsUnknown` | Fusionné dans `testMaterialWithoutJitaPriceLeavesMaterialCostUnknownIssue8` du rouge | Ajouts : 4 lieux de vente, par unité null, marges null, `sellPrices.jitaSell` 4 500 ; installation 1 575,38452 (ancien 851,5592) ; `[ROBOTICS]` → `missingData` [['market_price', 9848]] |
| golden `testMaterialWithoutJitaPriceLeavesTheSelectedDecryptorInventionCostKnownButItsProductionCostAndMarginUnknown` | Réexprimé | Morphite sans prix, Process : installation 6 495,0453013925 (ancien constante structure), invention 130,087703222 (ancien 1 280,574), 9 coûts d'invention du tableau rouge (ancien 254,424…), `copyCost` retiré (§4 D du plan), `missingData` [['market_price', 11399]] |
| golden `testTwoMaterialsWithoutJitaPriceAreListedByAscendingTypeId` | Réexprimé (`...AreEachListedInMissingData`) | Les deux `totalPrice` null, matériaux et total null, `missingData` contient Tritanium et Morphite, ordre libre. Abandonné : l'ordre croissant (Q3) et l'ordre des lignes `materials` (ordre de l'arbre sur main, pas une règle) |
| golden `testMaterialWithoutJitaPriceNeededByThreeFuelBlocksIsListedOnce` | Réexprimé (`...ThreeFuelBlockConsumersIsListedOnce`) | Robotics 2,25 (ancien 3), matériaux null, `missingData` = exactement [['market_price', 9848]] |
| golden `testZeroWeightedPriceIsAKnownPrice` | Réexprimé | Robotics 0,9 / 0,0 / 0,0 ; matériaux 153 280,656 - 88,632 = 153 192,024 (ancien 153 302,81) ; total + 1 575,38452 = 154 767,40852 ; `missingData` [] |
| provider `testUnknownMaterialCostAndMissingPriceTypeIdsReachTheResource` | Réexprimé (`...AndItsMissingMarketPricesReachTheResource`) | `missingPriceTypeIds` → `missingData` [market_price 9848, market_price 17888] ; quantités en float |
| provider `testFullyPricedAnalysisReachesTheResourceWithoutMissingPriceTypeIds` | Fusionné | `testFullyFiguredAnalysisReachesTheResourceWithoutMissingData` (`missingData` []) |

### f00e391, marges inconnues sans coût d'installation
Vocabulaire : `unknownReason` d'étape → `missingData` d'étape (règle §0.2 du plan : tout objet portant un montant expose `missingData`) ; `unknownInstallCostReasons` → `missingData` global ; `missing_cost_index` → `cost_index` (typeId null), `missing_adjusted_price` → `adjusted_price` + typeId.

| Test main | Sort | Détail |
|---|---|---|
| golden `testKnownCostIndexAndAdjustedPricesKeepEveryJobInstallStepFigured...` | Fusionné dans `testJobInstallCostAddsFacilityTaxAndSccToTheCostIndexTermIssue7` | Étape 1 575,38452 (ancien 851,5592), `missingData` d'étape [], total 154 856,04052 (ancien 154 252,8492), `missingData` [] |
| golden `testReactionTreeWithEveryCostIndexKeepsEveryJobInstallStepFigured...` | Fusionné dans le test Fernite du rouge | 4 étapes agrégées (ancien 6, un Hydrogen Fuel Block par consommateur) avec `missingData` [] ; `missingData` global [] |
| golden `testNitrogenFuelBlockInASystemWithoutManufacturingCostIndex...` | Fusionné dans `testMissingCostIndexLeavesInstallCostAndMarginsUnknownIssue7` | Ajouts : 4 lieux, `sellPrices.jitaSell` 4 500, étape null avec [['cost_index', null]] |
| golden `testMaterialWithoutAdjustedPriceMakesTheJobInstallStep...WithoutMissingMarketPrice` | Fusionné dans `testMaterialWithoutAdjustedPriceLeavesInstallCostUnknownIssue7` | Ajouts : étape null [['adjusted_price', 9848]], Robotics au prix 0,9 / 98,48 / 88,632, par unité et marges null ; `missingData` exactement [['adjusted_price', 9848]], donc sans market_price |
| golden `testReactionTreeWithoutReactionCostIndexHasOnlyItsReactionStepsUnknownAndTheReasonListedOnce` | Réexprimé | 3 réactions null [['cost_index', null]], Hydrogen Fuel Block 2,5 runs 3 938,5653625 (ancien 3 x 936,73987) ; installation, total, par unité null ; matériaux 715 971,765 (ancien 521 337,57) ; `missingData` = exactement [['cost_index', null]] |
| golden `testUnknownInstallCostReasonsOfDifferentStepsAreListedOnceEachInAscendingOrder` | Réexprimé (`testUnknownInstallCostsOfDifferentStepsListEachMissingDataOnce`) | Réactions [['cost_index', null]], Hydrogen Fuel Block [['adjusted_price', 9848]] ; `missingData` global = ces deux entrées, une fois chacune, ordre libre (Q3) |
| golden `testUnknownJobInstallCostLeavesEveryDecryptorOptionInventionCostKnown...` | Réexprimé | Hail L 1 run et R.A.M. 0,0095 run null [['cost_index', null]] ; matériaux 666 949,462149 (ancien constante structure) ; invention 130,087703222 ; 9 coûts d'invention du tableau rouge ; `copyCost` retiré ; `missingData` [['cost_index', null]] |
| golden `testWithoutManufacturingSystemThereIsNoJobInstallStepNorUnknownInstallCostReason` | Abandonné, contradictoire | Main : sans système, pas d'étape, installation 0,0. Rouge (R6, R10) : sans système ni structure, installation inconnue avec `cost_index` (`testT2WithoutSolarSystemHasUnknownInventionAndInstallCostIssue18`, `testProductWithoutAnyKnownCost...`). Rouge gardé. Voir Q1 |
| `ProfitMarginServiceTest` (mocks `estimateJobInstallCost`) | Sans objet | Adaptation des mocks uniquement |
| provider `testUnknownJobInstallCostStepsAndReasonsReachTheResource` | Réexprimé (`...StepsAndTheirMissingDataReachTheResource`) | Étapes avec `missingData` au lieu d'`unknownReason`, runs en float ; global [cost_index, adjusted_price 9848] |
| provider `testFiguredJobInstallCostReachesTheResourceWithoutUnknownInstallCostReasons` | Fusionné | `testFullyFiguredAnalysisReachesTheResourceWithoutMissingData` (installation 15 279, `missingData` []) |

## Questions ouvertes

1. **Pas de système de fabrication** (f00e391) : main dit « aucune étape, installation 0 » ; le rouge dit « coût inconnu, `cost_index` ». Décision produit. Le rouge est gardé.
2. **`invention.unknownReason = 'missing_market_price'`** (8661e28) : le plan (§0.2) dit « pas de nouveau `unknownReason` », mais main l'a livré et le frontend le lit (`inventionMissingMarketPrice.spec.ts`). Test gardé avec cette valeur et `missingData`. À trancher : le garder ou laisser `missingData` seul.
3. **Ordre de `missingData`** : main triait `missingPriceTypeIds` et `unknownInstallCostReasons` par ordre croissant ; le noyau garde l'ordre d'apparition (`Cost`, `MissingDataPresenterTest`). Assertions sans ordre dans le golden.
4. **Coût total nul** (f60575c) : inatteignable sur le noyau. La garde « marge inconnue si coût nul, revenu, frais et profit gardés » n'a plus de test d'intégration. À confirmer : abandon, ou test unitaire de la règle de marge une fois extraite.
5. **`missingData` par étape d'installation** : déduit de §0.2 du plan pour remplacer `unknownReason` d'étape. Non étendu aux lignes `materials` ni aux options de decryptor (montants nuls sans `missingData` asserté). À confirmer.
6. **Contrat frontend** : `missingPriceTypeIds`, `unknownInstallCostReasons` et `jobInstallSteps[].unknownReason` (lus par `profitMarginTabUnknownMaterialPrice.spec.ts`, `profitMarginTabUnknownJobInstallCost.spec.ts`) disparaissent de la resource côté tests backend. La phase verte doit migrer ces specs vers `missingData`.
7. **`wt-php.sh`** : refuse ce worktree (`grep -q` + `pipefail` : SIGPIPE sur `git worktree list`). Tests lancés avec la même commande `docker run` en direct.

## Tests

| Fichier | Tests | Résultat (rouge attendu) |
|---|---|---|
| ProfitMarginGoldenCharacterizationTest | 39 (rouge : 24, +15) | 39 erreurs, toutes `TypeError: ProfitMarginService::__construct(): Argument #1 ($treeService) must be of type IndustryTreeService, ProductionCostCalculator given` |
| ProfitMarginProviderTest | 11 | 10 `TypeError: Cannot assign null to property ProfitMarginResource::$copyCost` (le rouge retire `copyCost`) ; 1 échec : decryptor inconnu non refusé (`analyze` appelé) |
| MissingDataPresenterTest | 3 | 1 succès, 2 échecs (le squelette renvoie []) |

Aucune erreur de syntaxe (`php -l`). PHPStan : pas de cible Makefile, et `phpstan.neon` ne couvre que `src/` ; non lancé.

## Empreintes (`sha256sum | cut -c1-12`)

- `tests/Unit/Service/Industry/ProfitMarginGoldenCharacterizationTest.php` : 15b2b42e82a7
- `tests/Unit/State/Provider/Industry/ProfitMarginProviderTest.php` : a5139a0ea24d
- `tests/Unit/Service/Industry/MissingDataPresenterTest.php` : 1087ee7f7b77
