# #69 (Stockpile) — note de câblage

Spec : `docs/specs/69-group-bom-stockpile-demand-aggregation.md` (validée le 2026-10-28, périmètre Stockpile seul). Un lot, circuit l. La BOM de groupe est couverte par #141, déjà dans main.

## 1. Dépendances de l'implémentation cible

### Noyau : extension, pas de classe parallèle

- `App\Industry\Application\ProductionPlanRequest` gagne `public array $startingStock = []` (`array<int, Quantity>` par typeId), en dernier paramètre nommé.
- `ProductionPlanning::planOf()` le transmet aux **deux** `PlanRequest` : la passe sans plafond et la passe plafonnée.
- `GroupIndustryProjectService` ne le renseigne pas : la BOM de groupe ne change pas.

### `StockpileService`

- Retirer `IndustryTreeService` du constructeur.
- Ajouter :
  - `ProductionPlanning` ;
  - `UserProductionStructures`, pour `configuredBy(id du pilote)` avec `FavoriteSystems::none()` ;
  - `IndustryActivityProductRepository`, pour le producteur du produit importé : unités par run, réaction ou non (ME 0), et l'erreur « No blueprint or reaction found for product type ID … », comme `GroupIndustryProjectService::producerOf()`.
- Noms des types (S6) : `EntityManagerInterface::getRepository(InvType::class)`, déjà injecté, comme `GroupIndustryProjectService::sdeTypeNames()`.
- Le `flattenTree()` public disparaît. Le niveau S3 se lit sur les matériaux des jobs de la cible (`Plan::jobsFor($typeId)`).
- `\DomainException` du planificateur : convertie en `\RuntimeException`, comme `GroupIndustryProjectService::planOf()`.

### `StockpileStartingStockReducer`

- Retirer `IndustryTreeService` du constructeur.
- Ajouter `ProductionPlanning`, `UserProductionStructures` et `IndustryActivityProductRepository` (unités par run du produit importé).
- Le stock de départ passé au plan réduit est le réservoir restreint aux intermédiaires du plan complet (clés de `Plan::$intermediates`). Les feuilles gardent ainsi leur besoin (R4 de #118).
- Le stock consommé est `Intermediate::$consumedStock`.

### Partage et suppression

- Le besoin par type d'un `Plan` (demande des intermédiaires, puis feuilles) sert à l'import et à la réduction. Une seule fonction, partagée entre les deux classes.
- `idOf(User)` existe déjà dans `GroupIndustryProjectService` et `BuyVsBuildService` ; le Stockpile en ajouterait deux copies. À extraire si possible, sinon à accepter comme duplication connue.
- `IndustryTreeService::buildIntermediateTree()` n'a plus d'appelant : à supprimer. `buildProductionTree()` ne garde que `IndustryProjectFactory`.
- Câblage : rien à ajouter à `config/services.yaml` (ports déjà liés, classes autowirées). Deptrac : `Service → IndustryApplication`, autorisé.

## 2. Comment le harnais fournit chaque dépendance

Même modèle que `GroupIndustryBomGoldenCharacterizationTest` (`serviceInventing()`, l. ~1581) : un vrai `ProductionPlanning` sur un vrai `SdeBlueprintCatalog`, construit sur des stubs de repositories qui lisent la fixture au moment de l'appel. Le noyau n'est jamais mocké (classes `final readonly`).

| Dépendance | Double |
|---|---|
| `SdeBlueprintCatalog`, goldens Stockpile | Stubs à ajouter à `StockpileGoldenImportCharacterizationTest`, sur le modèle des helpers `*RepositoryOnGoldenSde()` du test de groupe (l. 1600-1710) : `IndustryActivityProductRepository::findBlueprintForProduct`, `IndustryActivityMaterialRepository::findByBlueprintAndActivity` (le catalogue n'utilise pas `findBy`), `IndustryBlueprintRepository::find` (`maxProductionLimit` de la fixture), `IndustryActivityRepository::findOneBy` (`baseTimeSeconds`). Ils remplacent `treeServiceOnGoldenSde()` |
| `SdeBlueprintCatalog`, Widget (`BuildsStockpileOnWidgetSde`) | Mêmes stubs sur `$this->recipes`, lus à l'appel (`useTheTwoFrameFormula()` et `removeTheRecipeOf()` modifient les recettes en cours de test). `maxProductionLimit` large (ex. 1 000) pour qu'aucun job ne soit plafonné ; temps de base quelconque. Remplace `treeServiceOnWidgetSde()` |
| `SystemCostIndices`, `RigCategories` | `InMemorySystemCostIndices::none()`, `new InMemoryRigCategories()` |
| `UserProductionStructures` | `InMemoryUserProductionStructures([PILOT_ID => []])` |
| Pilote (`User`) | Mocks et stubs actuels sans id : `getId()` doit rendre un `Uuid` fixe (`$this->owner->method('getId')` du test de groupe, l. 190) |
| `IndustryActivityProductRepository` du service et du réducteur | Le même stub que le catalogue |
| Statut sans import (`StockpileStatusCharacterizationTest`, `StockpileStatusProviderTest`) | `ProductionPlanning` réel sur un `InMemoryBlueprintCatalog` vide : aucun import enregistré, donc le plan n'est jamais appelé |

## 3. Tests existants qui casseront

Tous les tests des fichiers ci-dessous cassent à la construction tant que le harnais n'est pas migré. La liste donne en plus ceux dont la valeur ou l'objet change.

### `StockpileGoldenImportCharacterizationTest` (18 tests, harnais)

Valeurs qui changent :
- `testPreviewOfOneRunOfFerniteCarbideCurrentlyBuildsTheFuelBlockOncePerBranchIssue69` : agrégation (E1) ; perd `Currently…Issue69`, garde `#[Group('issue-69')]`.
- `testPreviewOfOneNomadCurrentlyOverestimatesTheNonAggregatedLeavesAndIntermediatesIssue69` : golden Ravworks plus les 5 écarts du plafond (E2).
- `testPreviewOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyReadsTheRunsAsAQuantityIssue2` : runs lus comme une quantité (E3).
- `testPreviewOfNitrogenFuelBlockAtMeTenCurrentlyReadsFortyOneRunsAsUnitsIssue2` : idem (E4).
- `testPreviewOfTenRunsOfFerniteCarbideCurrentlyReadsTheRunsAsAQuantityIssue2` : idem (E5).

Objet qui disparaît :
- `testFlattenTreeReadsTheStageFromTheDepthOfEachBlueprintNodeAndDefaultsMissingFields` : `flattenTree()` supprimé. À remplacer par un test de S3.

Inchangés en valeur :
- les tests #8 de l'aperçu, tous à 1 run de Nitrogen Fuel Block ;
- les deux tests d'erreur (S6 ; ordre `[4051, 9848]` déjà croissant) ;
- `testPreviewOfOneRunOfNitrogenFuelBlockAtMeZeroKeepsTheBaseQuantities` ;
- `testPreviewOfFerniteCarbideIgnoresTheMe` (17 items) ;
- les deux tests d'import `merge` / `replace`, à 1 run.

### `StockpileServiceTest` (20 tests, constructeur)

Ces 5 tests stubbent `buildProductionTree` avec des arbres écrits à la main. Ils sont à réécrire sur des recettes :
- `testPreviewImportReturnsCorrectStagesAndEstimatedCost` ;
- `testImportMergeAddsQuantities` ;
- `testImportReplaceDeletesExisting` ;
- `testImportInMergeModeAddsOneStockpileImport` ;
- `testImportInReplaceModeKeepsOnlyTheStockpileImportOfTheLastImport`.

### `StockpileImportPreviewProcessorTest` (2 tests)

- Arbre Widget écrit à la main, au ME 10 de l'entrée, sur un stub `IndustryTreeService`. Il devient une recette Widget (Tritanium, Pyerite) dans le catalogue.
- Les constantes `TRITANIUM_QUANTITY` et `PYERITE_QUANTITY` deviennent des quantités après ME 10 : choisir des quantités de base qui gardent `totalItems = 3` et un coût calculable.
- Le `User` stub doit avoir un id.

### `StockpileStartingStockReducerTest` (11 tests, harnais Widget)

Les valeurs sont inchangées : recalcul sur S5, Ore 280 ; Ore 350 ; Frame 4 et Ore 630. Le parcours en profondeur n'existe plus, ces 3 noms sont donc à renommer :
- `testStockIsConsumedDepthFirstInTheOrderOfTheMaterials` ;
- `testRawMaterialDoesNotStopTheWalkOfItsSiblings` ;
- `testMaterialFullyCoveredDoesNotStopTheWalkOfItsSiblings`.

`testImportWhoseTreeCannotBeRebuiltReducesNothingConsumesNoStockAndIsReportedWithItsReason` garde son message (S6).

### Autres fichiers

- `StockpileStatusIntermediateStockTest` (18) et `StockpileStatusDeficitTest` (8) : harnais Widget, valeurs #118 inchangées (E9).
- `StockpileStatusCharacterizationTest` (35) et `StockpileStatusProviderTest` (4) : constructeur seulement.
- `IndustryTreeServiceTest::testIntermediateTreeOfATypeWithoutBlueprintOrReactionThrowsAnExplicitError` : supprimé avec `buildIntermediateTree()`.
- `ProductionPlanningTest` : aucun test existant ne casse (stock vide par défaut). Nouveaux tests du stock de départ : transmis aux deux passes ; stock partiel d'un intermédiaire ; stock total, sans sous-arbre ; jobs plafonnés calculés sur le reste.
- Non touchés : tests Group Industry, factory, arbre affiché, liste d'achats.

## 4. Questions de spec ouvertes

- **Q1 (pour Sylvain)** : plafond appliqué au Stockpile par `ProductionPlanning`. Option A : l'accepter (+1 sur 5 types d'un Nomad). Option B : une option sans plafond. Recommandation : A. Si B est retenue, ajouter le drapeau à `ProductionPlanRequest` dans ce lot ; E2 redevient alors exactement Ravworks.
