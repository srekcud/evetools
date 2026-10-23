# Module Industry — carte du code

But : trouver la bonne classe sans relire des fichiers entiers. Les noms de méthodes sont stables, pas les numéros de ligne : cherche par `grep -n 'function nom'`. Vérifié sur le code au 2026-10-23. Termes : [glossaire](../glossary.md).

## 1. Couches

| Couche | Chemin | État |
|---|---|---|
| Noyau Domain | `src/Industry/Domain/` | PHP pur, value objects, `ProductionPlanner`. ADR-0009, [spec du noyau](../specs/industry-engine.md) |
| Noyau Application | `src/Industry/Application/` | Cas d'usage (`ProductionCostCalculator`, `StructureSelector`) et ports (`MarketPrices`, `AdjustedPrices`, `SystemCostIndices`, `UserProductionStructures`, `Decryptors`, `InventionCatalog`, `RigCategories`) |
| Noyau Infrastructure | `src/Industry/Infrastructure/{Sde,Esi,Market,Doctrine}` | Adaptateurs des ports, liés dans `config/services.yaml` (section « Industry engine ») |
| Services historiques | `src/Service/Industry/` | Règles encore dupliquées, en cours de migration (strangler) |
| Group Industry | `src/Service/GroupIndustry/` | Projets collaboratifs. `GroupIndustryProjectService::buildBom` appelle `IndustryTreeService` |
| API | `src/ApiResource/Industry/`, `src/State/Provider/Industry/`, `src/State/Processor/Industry/` | Un Provider ou Processor par opération. `IndustryResourceMapper` (Provider) convertit entités en ressources |
| Persistance | `src/Entity/Industry*.php` | `IndustryProject`, `IndustryProjectStep`, `IndustryStructureConfig`, `IndustryUserSettings`, `IndustryStepJobMatch`, `IndustryStockpileTarget`… |
| Frontend | `frontend/src/views/Industry.vue`, `components/industry/`, `stores/industry/` | Un onglet par écran (`*Tab.vue`), un store par domaine |

Services déjà branchés sur le noyau (`grep -rln 'App\\Industry\\' src`) :

| Service | Ce qu'il prend du noyau |
|---|---|
| `ProfitMarginService`, `BuyVsBuildService` | `ProductionCostCalculator` (et `ProductionPlanner` pour Buy vs Build) : coûts, installation, invention |
| `JobInstallCostEstimator`, `FacilityTaxResolver` | `InstallCost`, `EstimatedItemValue` ; `StructureSelector` |
| `MissingDataPresenter` | `Cost`, `MissingData` (coût inconnu) |
| `BatchProfitScannerService`, `PivotAdvisorService` | seulement `ActivityKind` ; matériaux par `MaterialQuantityFormula`, install par `JobInstallCostEstimator` / `FacilityTaxResolver` |

**Pas encore migrés** : `IndustryProjectFactory`, `IndustryTreeService`, `IndustryCalculationService`, `IndustryBonusService`, `IndustryStepCalculator`, `IndustryShoppingListBuilder`, `StockpileService`, `InventionService`, `EsiCostIndexService`, `ProductionCostService`, `GroupIndustry*`, tous les Processors. `Domain\JobDuration` et `Domain\SkillTimeModifier` n'ont aucun appelant dans `src/` (tests seuls).

## 2. Classes importantes

| Classe (`src/Service/Industry/` sauf mention) | Rôle | Méthodes clés | Appelants | Tests (`tests/Unit/Service/Industry/` sauf mention) |
|---|---|---|---|---|
| `IndustryProjectFactory` | Génère et régénère les étapes d'un projet : arbre, durées, découpage, besoins par job | `createProject`, `regenerateSteps` | `CreateProjectProcessor`, `UpdateProjectProcessor`, `RegenerateProjectStepsCommand` | `IndustryProjectFactoryGoldenStepsCharacterizationTest`, `IndustryProjectFactoryTest` ; support `BuildsProjectOnGoldenSde` |
| `IndustryTreeService` | Arbre de production récursif (runs en entrée, besoin pour `buildIntermediateTree`) | `buildProductionTree`, `buildIntermediateTree` | factory, `StockpileService`, `StockpileStartingStockReducer`, `GroupIndustryProjectService`, `SdeBlueprintCatalog` (plus `ProjectProvider` : l'arbre affiché vient de `ProductionTreeFromSteps`) | `IndustryTreeServiceTest` |
| `IndustryStepCalculator` | Cascade : recalcule besoins et runs des étapes, de l'aval vers les matériaux | `recalculateStepQuantities`, `needRunsOf` | `UpdateStepProcessor`, `IndustryJobMatcher` | `IndustryStepCalculatorTest` |
| `SplitGroupQuantities` / `SplitGroupMaterials` / `SplitGroupMaterialNeeds` | Groupes découpés : runs et quantités par job, matériaux par job, besoins des enfants | `runsBySplitIndex`, `share`, `runsOfNeed`, `applyNeedToSteps` ; `materialsOfJobs` ; `applyChangeOfJobs` | factory, `SplitStepProcessor`, `MergeStepsProcessor`, `UpdateStepProcessor` | `SplitGroupQuantitiesTest`, `SplitGroupMaterialNeedsTest`, `JobSplitCalculationTest` |
| `ProductionStepGraph` | Parcours consommateurs vers matériaux des étapes | `productionStepsByProduct`, `productsFromConsumersToMaterials` | `IndustryStepCalculator`, `ProductionStepNeedsResolver` | couvert via ces classes |
| `ProductionStepNeedsResolver` | Passe partagée sur les étapes : produits en erreur, besoin, stock, acheté ou blacklisté, runs effectifs et Δ par produit (spec #121, R4 et R5). Résultat : `ProductionStepNeeds` et un `StepProductNeed` par produit | `resolve`, `materialQuantityOf` | `IndustryShoppingListBuilder`, `ProductionTreeFromSteps` | via `IndustryShoppingListBuilderGoldenCharacterizationTest`, `…FromStepsTest` et `ProjectProviderGoldenTreeCharacterizationTest` |
| `ProductionTreeFromSteps` | Arbre affiché d'un projet, dérivé des étapes : un nœud par produit, références chez les autres consommateurs (spec #121 arbre) | `buildTree` | `ProjectProvider` | `ProjectProviderGoldenTreeCharacterizationTest` (`tests/Unit/State/Provider/Industry/`) |
| `IndustryCalculationService` | Quantité matériau, bonus de la structure d'une étape, durée par run, compétences | `calculateMaterialQuantity`, `getStructureBonusForStep`, `calculateTimePerRun`, `calculateSkillTimeMultiplier` | factory, `SlotTrackerService`, `ProductionCostService`, `IndustryJobMatcher`, providers | `IndustryCalculationTest` |
| `IndustryBonusService` | Bonus de structure et de rig (tables codées en dur) et durée ajustée | `findBestStructureForProduct`, `findBestStructureForCategory`, `calculateAdjustedTimePerRun`, `getCategoryForProduct` | factory, `IndustryTreeService`, `IndustryCalculationService`, `IndustryShoppingListBuilder`, `ProductionTreeFromSteps` | `IndustryBonusServiceTest` |
| `IndustryShoppingListBuilder` | Liste d'achats d'un projet (matières premières sur la passe de `ProductionStepNeedsResolver`), produits en erreur | `getShoppingList`, `getPurchasedQuantities` | `ShoppingListProvider`, `ProductionCostService` | `IndustryShoppingListBuilderGoldenCharacterizationTest`, `…FromStepsTest` ; `ShoppingListProviderGoldenCharacterizationTest` |
| `StockpileService` | Import d'arbre en Stockpile, statut, déficit, produit prêt | `previewImport`, `importFromBlueprint`, `getStockpileStatus`, `flattenTree` | `StockpileImportProcessor`, `StockpileImportPreviewProcessor`, `StockpileStatusProvider` | `StockpileServiceTest`, `StockpileGoldenImportCharacterizationTest`, `StockpileStatus*Test` |
| `StockpileStartingStockReducer` | Réduit les cibles par le stock de départ des fabricables | `reduce` | `StockpileService` | `StockpileStartingStockReducerTest` |
| `IndustryJobMatcher` | Rattache les jobs ESI aux étapes (auto ou manuel) | `matchEsiJobs`, `linkJobToStep`, `isJobOfStepActivity` | `MatchJobsProcessor`, `LinkJobProcessor`, `MatchProjectJobsCommand` | `IndustryJobMatcherTest` |
| `InventionService` | Invention et copie (coût, probabilité, decryptors), kit de BPC | `getInventionData`, `calculateInventionCost`, `getCopyJobCost`, `buildDecryptorOptions`, `getBpcKitBreakdown`, `getProjectCopyCosts` | `BpcKitProvider`, `CopyCostsProvider`, scanner, pivot, `GroupIndustryProjectService`, `ProfitMarginService` | `InventionServiceTest` |
| `EsiCostIndexService` | Sync et lecture des cost indices et adjusted prices ; coût d'installation | `syncCostIndices`, `syncAdjustedPrices`, `getCostIndex`, `estimateJobInstallCost` | commandes et handlers de sync, `ProductionCostService`, `InventionService`, scanner, pivot | `EsiCostIndexServiceTest` |
| `ProductionCostService` | Coût matériaux, installation et total d'un projet | `estimateMaterialCost`, `estimateJobInstallCosts`, `estimateTotalCost` | `CostEstimationProvider`, `CreateProjectProcessor` | `ProductionCostServiceTest` |
| `ProfitMarginService` (noyau) | Marge d'un produit : Jita, structure, contrat | `analyze` | `ProfitMarginProvider` | `ProfitMarginGoldenCharacterizationTest` ; `ProfitMarginProviderTest` (`tests/Unit/State/Provider/Industry/`) |
| `BuyVsBuildService` (noyau) | Compare acheter ou fabriquer chaque composant | `analyze` | `BuyVsBuildProvider` | `BuyVsBuildServiceCharacterizationTest`, `…UnknownPriceTest`, `…UnknownInstallCostTest`, `…WithoutSellStructureTest` ; support `BuildsBuyVsBuildOnGoldenSde` |
| `PivotAdvisorService` | Propose des pivots de production | `analyze` | `PivotAdvisorProvider` | `PivotAdvisorServiceTest`, `…UnknownMaterialPriceTest`, `…UnknownJobInstallCostTest` |
| `BatchProfitScannerService` | Scanner de rentabilité par lot | `scan` | `BatchScanProvider`, `PivotAdvisorService` | `BatchProfitScannerServiceTest`, `BatchProfitScanRulesTest` |
| `SlotTrackerService` | Slots et avancement des jobs en cours | `getSlotStatus`, `calculateMaxSlots` | `SlotTrackerProvider` | `SlotTrackerServiceTest` |
| `IndustryBlacklistService` | Types blacklistés d'un utilisateur | `resolveBlacklistedTypeIds`, `getCategories` | factory, `ProductionStepNeedsResolver`, `ProfitMarginService`, `UpdateBlacklistProcessor` | aucun test dédié ; doublé dans les tests de la factory et du golden Profit Margins |
| `ProductionCostCalculator` (`src/Industry/Application/`) | Coût d'un produit en mode plan ou coût marginal | `costOf` | `ProfitMarginService`, `BuyVsBuildService` | `tests/Unit/Industry/Application/ProductionCostCalculatorTest` |
| `ProductionPlanner` (`src/Industry/Domain/`) | Plan : demande agrégée, runs entiers, jobs découpés | `plan`, `marginalCost` | `ProductionCostCalculator`, `BuyVsBuildService` | `tests/Unit/Industry/Domain/{GoldenPlanTest,ProductionPlannerTest,ProductionPlannerMarginalCostTest}` |

Tests d'intégration : `tests/Integration/Service/Industry/IndustryProjectFactoryRegenerationTest`, `tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest`. Autres tests du noyau : `tests/Unit/Industry/Domain/GoldenJobMaterialQuantityTest`, `InstallCostTest`, `Invention*Test`, `RigBonusTest` ; `Application/StructureSelectorTest`, `StructureMaterialModifiersTest` ; `Infrastructure/{Doctrine,Esi,Market,Sde}`. Les `Golden*` se lisent avec la spec du noyau (§4).

## 3. Où chercher

| Sujet | Règle portée par |
|---|---|
| Runs vs quantité, unités par run | Entrée de l'arbre de la factory : `IndustryTreeService::buildProductionTree` (runs) et `buildIntermediateTree` (quantité). Arbre affiché : `ProductionTreeFromSteps::buildTree`, sur les étapes. Conversion besoin vers runs : `SplitGroupQuantities::runsOfNeed`. Noyau : `Domain\Runs`, `Domain\Quantity` |
| Quantité d'un matériau (ME, bonus) | Historique : `MaterialQuantityFormula::forJob`. Noyau : `Domain\MaterialQuantity::forManufacturingJob` / `forReactionJob`, appelés par `ProductionPlanner` |
| ME/TE d'une étape | `IndustryProjectFactory` (`collectStepsFromTree`, `withSettingsOfExistingStep`) ; ME/TE de l'étape : `IndustryProjectStep` |
| Decryptors | Noyau : port `Application\Decryptors`, `Infrastructure\Sde\SdeDecryptors`, `Domain\Decryptor`. Historique : `InventionService::buildDecryptorOptions` |
| Bonus de structure et de rig | Historique : `IndustryBonusService` (tables `STRUCTURE_*`), `IndustryCalculationService::getStructureBonusForStep`, `assignedStructureBonusOf`. Noyau (valeurs dogma du SDE) : `StructureMaterialModifiers::forJob`, `StructureSelector::select`, `Infrastructure\Sde\SdeStructureBonusReader`, `SdeRigBonusReader`, `Domain\RigBonus` |
| Choix de la structure, système favori | Noyau : `StructureSelector::select`, `FavoriteSystems`. Historique : `IndustryCalculationService::findBestInFavoriteSystem` (privée) ; système et taxe d'une étape : `ProjectStepStructureResolver` |
| Coût d'installation (système, taxe, SCC) | Noyau : `Domain\InstallCost::forJob`, `EstimatedItemValue`. Entrée historique : `JobInstallCostEstimator::estimate`, `EsiCostIndexService::estimateJobInstallCost`. Taxe : `FacilityTaxResolver::percentFor`. Copie : `InventionService::getCopyJobCost`. Projet : `ProductionCostService::estimateJobInstallCosts` |
| Prix Jita, structure, meilleur prix | Jita : `JitaMarketService` (`getWeightedSellPrice`, `getPricesWithFallback`), port noyau `JitaMarketPrices`. Structure : `StructureMarketService::getLowestSellPrice`, `getHighestBuyPrice`. Contrats : `PublicContractPriceService::getLowestUnitPrice`. Prix de vente de Buy vs Build : `BuyVsBuildService::sellPriceOf` (privée ; structure, sinon Jita). Marges par lieu : `ProfitMarginService::marginsOf` (privée) |
| Coût d'invention | Noyau : `ProductionCostCalculator` (méthode privée `inventionCost`), `Domain\InventionExpectation`, `InventionChance`, `InventionOutcome`. Historique : `InventionService::calculateInventionCost` |
| Groupes découpés, cascade des étapes | Découpe à la génération : `IndustryProjectFactory::jobsOf` (privée), `SplitGroupQuantities`. Découpe manuelle : `SplitStepProcessor`, `MergeStepsProcessor`. Cascade : `IndustryStepCalculator::recalculateStepQuantities`, appelée par `UpdateStepProcessor` et `IndustryJobMatcher` |
| Liste d'achats, runs effectifs | `IndustryShoppingListBuilder::getShoppingList`, passe partagée `ProductionStepNeedsResolver::resolve` ; `ShoppingListProvider`, `PurchaseSuggestionProvider` ; achats : `IndustryStepPurchase`, `CreatePurchaseProcessor` |
| Stock, Stockpile | `StockpileService::getStockpileStatus` (déficit, produit prêt), `StockpileStartingStockReducer::reduce`, `IndustryStockpileTarget::isMetBy` ; stock d'une étape : `IndustryProjectStep::getInStockQuantity` |
| Blacklist | `IndustryBlacklistService::resolveBlacklistedTypeIds` ; écriture : `UpdateBlacklistProcessor`. Buy vs Build ne l'applique pas |
| Durée de job, compétences | `IndustryCalculationService::calculateTimePerRun`, `calculateSkillTimeMultiplier` ; `IndustryProjectFactory::addTimeDataToSteps` ; durée maximale : `IndustryProject::$maxJobDurationDays` ; compétences : `SyncCharacterSkillsProcessor`, `UpdateCharacterSkillProcessor`. Noyau (non branché) : `Domain\JobDuration`, `SkillTimeModifier` |
| Marge et profit | Marge : `ProfitMarginService::analyze`, `marginsOf`. Coût marginal : `ProductionPlanner::marginalCost`. Coût inconnu : `Domain\Cost`, `MissingDataPresenter::of` |
| Mercure, sync des jobs | `SyncCharacterIndustryJobsHandler` (message `SyncCharacterIndustryJobs`) puis `Sync\IndustryJobSyncService::syncCharacterJobs` : `syncStarted`, `syncProgress`, `syncCompleted`, `syncError` sur le type `industry-jobs`. Topics : `MercurePublisherService::getTopicsForUser`. Planification : `SyncScheduler` (message `SyncIndustryJobs`). Front : `stores/sync.ts` |

## 4. Lire sans tout charger

Fichiers de plus de 400 lignes (`wc -l`) :

| Backend | Lignes |
|---|---|
| `src/Service/JitaMarketService.php` | 1081 |
| `src/Service/Industry/IndustryBonusService.php` | 674 |
| `src/Service/Industry/BatchProfitScannerService.php` | 670 |
| `src/Service/Industry/IndustryProjectFactory.php` | 661 |
| `src/Service/Industry/PivotAdvisorService.php` | 631 |
| `src/Service/Industry/InventionService.php` | 599 |
| `src/Entity/IndustryStructureConfig.php` | 507 |
| `src/Service/GroupIndustry/GroupIndustryProjectService.php` | 475 |
| `src/Entity/IndustryProject.php` | 467 |
| `src/Service/Industry/StockpileService.php` | 466 |
| `src/State/Provider/Industry/IndustryResourceMapper.php` | 462 |
| `src/Service/Sync/IndustryJobSyncService.php` | 404 |

`ProfitMarginService` (349), `BuyVsBuildService` (352), `IndustryCalculationService` (379) et `IndustryStepCalculator` (108) tiennent sous 400 lignes.

Frontend : `StructureConfig.vue` 1033, `StepTree.vue` 968, `BatchScanTab.vue` 852, `BuyVsBuildTab.vue` 838, `types.ts` (stores) 828, `ProfitMarginTab.vue` 797, `Industry.vue` 785, `IndustrySlotsSection.vue` 730, `IndustryDashboard.vue` 668, `ProjectTable.vue` 658, `ShoppingTab.vue` 644, `CostEstimationTab.vue` 610, `ProjectDetail.vue` 606, `PivotAdvisorTab.vue` 581, `BpcKitTab.vue` 466.

Tests de plus de 400 lignes, à ne jamais lire en entier : `InventionServiceTest` 1590, `ProfitMarginGoldenCharacterizationTest` 1371, `PivotAdvisorServiceTest` 1348, `IndustryProjectFactoryGoldenStepsCharacterizationTest` 1343, `IndustryJobMatcherTest` 1339, `BatchProfitScannerServiceTest` 1219, `ProductionCostServiceTest` 959, `BatchProfitScanRulesTest` 829, `SlotTrackerServiceTest` 818, `IndustryBonusServiceTest` 778, `BuyVsBuildServiceCharacterizationTest` 772, `IndustryProjectFactoryTest` 766, `PivotAdvisorServiceUnknownMaterialPriceTest` 707, `EsiCostIndexServiceTest` 654, `StockpileServiceTest` 616, `IndustryTreeServiceTest` 612, `PivotAdvisorServiceUnknownJobInstallCostTest` 549, `BuyVsBuildServiceUnknownPriceTest` 500, `StockpileGoldenImportCharacterizationTest` 452, `StockpileStatusIntermediateStockTest` 452, `IndustryShoppingListBuilderGoldenCharacterizationTest` 451, `StockpileStatusCharacterizationTest` 446, `BuyVsBuildServiceUnknownInstallCostTest` 413, `IndustryStepCalculatorTest` 402.

Méthode :

1. Lister les points d'entrée : `grep -n 'function ' <fichier>` (les méthodes privées montrent l'enchaînement, ex. dans la factory `productionTreeOf`, `collectStepsFromTree`, `addTimeDataToSteps`, `planStepsJobByJob`, `applyPlanToExistingSteps`).
2. Pour un test : `grep -n 'function test' <fichier>`. Les noms décrivent le cas ; `Currently…IssueN` fige un bug connu et `#[Group('issue-N')]` le rattache à l'issue (`grep -n "Group('issue-" <fichier>`).
3. Lire une plage : `sed -n 'début,finp' <fichier>` autour du nom trouvé, une méthode à la fois.
4. Les goldens partagent des supports (`BuildsProjectOnGoldenSde`, `BuildsBuyVsBuildOnGoldenSde`, `BuildsShoppingListOnGoldenSde`, `BuildsStockpileOnWidgetSde`, `StubsGoldenSdeRepositories`) : lire le support une fois, pas à chaque test.
5. Frontend : `grep -n 'const .* = \|function ' <fichier>` puis plage ; pour un type d'API, `grep -n 'interface ' frontend/src/stores/industry/types.ts`.
