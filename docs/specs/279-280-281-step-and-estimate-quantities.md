# Spec #279 + #280 + #281 — Quantités des étapes de copie, des étapes ajoutées à la main et prix de vente par unité

**Statut : rédigée en mode nuit le 2026-10-29, à valider par Sylvain.** Les trois corrections sont celles décidées dans les issues (points T1, T3 et T4 de `docs/specs/137-125-units-per-run-from-sde.md`) ; elles ne sont pas rediscutées ici. Les choix de mise en œuvre faits en autonomie sont listés dans « Décisions prises en autonomie ».

## Contexte

Depuis le lot #137/#125, les **unités par run** d'une **étape** se lisent par `SplitGroupMaterials::outputPerRunOf()` et le **producteur** du produit d'un projet par `SplitGroupMaterials::producerOf()`. Trois écarts restaient hors de ce lot :

- **#279 (T1)** : le PATCH des runs d'une **étape de copie** compte les unités par run du produit fabriqué par le blueprint, alors que la génération et le découpage comptent un BPC par run ;
- **#280 (T3)** : une étape ajoutée à la main (`POST /industry/projects/{id}/steps` avec `typeId`) a une quantité égale à ses runs, et un type sans producteur reçoit son propre typeId comme blueprint ;
- **#281 (T4)** : l'onglet d'estimation de coût divise le prix de vente saisi par les runs du projet, pas par la **quantité produite**.

État vérifié dans le code au 2026-10-29 :

| Site | Aujourd'hui | Appelants |
|---|---|---|
| `SplitGroupMaterials::outputPerRunOf()` | `findOneBy(typeId, activityIdOf(activité))` pour toute activité ; `activityIdOf('copy')` = 1, donc une copie lit le produit **fabriqué** par le blueprint (fuel block : 40) ; produit absent → `BlueprintProductMissingFromSdeException` | `UpdateStepProcessor::update()`, `SplitStepProcessor::getOutputPerRun()` (hors copie), `SplitGroupMaterialNeeds::applyChangeOfJobs()` (étapes matériaux, jamais de copie : `materialStepsByProduct()` les écarte) |
| `SplitStepProcessor::getOutputPerRun()` (privée) | copie → constante privée `OUTPUT_PER_COPY_RUN = 1`, sinon `outputPerRunOf()` | `process()` (découpage manuel) |
| `UpdateStepProcessor::update()` | runs saisis ≥ 1 → `outputPerRunOf($repo, blueprint, activité)` puis `setQuantity($runs × unités par run)` ; `needRunsOf()` rend `null` pour une copie (`ProductionStepGraph::productionStepsByProduct()` écarte les copies), donc runs = runs saisis | PATCH d'une étape |
| `IndustryProjectFactory::collectStepsFromTree()` / `withCopyStepsFollowingManufacturing()` | étape de copie : `outputPerRun` 1, `quantity` = `runs` | création, régénération des étapes |
| `CreateStepProcessor::process()`, branche « nouvelle étape depuis `typeId` » (l. 148-197) | `findOneBy(productTypeId, activityId 1)`, sinon `activityId 11` ; `blueprintTypeId = $activityProduct?->getTypeId() ?? $typeId` ; `activityType` = `reaction` si activité 11, sinon `manufacturing` ; `setQuantity($runs)` | POST d'une étape (`ProjectStepResource`, `CreateStepInput`), `stores/industry/steps.ts::createStep()` |
| `CreateStepProcessor::process()`, branches `stepId` (l. 70-106) et `splitGroupId` (l. 109-146) | `setQuantity($runs)`, `totalGroupRuns` non mis à jour, aucun recalcul des matériaux par job | ajout d'un job à une étape ou à un **groupe découpé** (#191) |
| `ProductionCostService::estimateTotalCost()` | calcule la quantité produite par `calculateTotalOutputQuantity()` (`runs du projet × quantity du producteur`, `producerOf()`), s'en sert pour `perUnit` et ne la rend pas | `CostEstimationProvider::provide()` |
| `CostEstimationResource` | `id`, `materialCost`, `jobInstallCost`, `bpoCost`, `totalCost`, `perUnit`, `materials`, `jobInstallSteps`, `failedProducts` : aucune quantité produite | `GET /industry/projects/{id}/cost-estimation` |
| `frontend/src/components/industry/CostEstimationTab.vue` | `runs = project.runs ?? 1` ; en-tête de la comparaison `{{ runs }} unit(s)` ; ligne `perUnitShort` affichée si `runs > 1`, avec `formatIsk(sellPrice / runs)` ; section affichée seulement si `sellPrice > 0` et projet hors usage personnel | onglet Estimation d'un projet |

`ProducerMissingFromSdeException` (`\RuntimeException`, message `No blueprint or reaction found for product type ID %d`) n'a pas de statut HTTP ; `CreateStepProcessor` ne l'utilise pas encore. Aucun test unitaire ne couvre `CreateStepProcessor`.

Le champ « prix de vente » de l'onglet est `IndustryProject::$sellPrice`, saisi par l'utilisateur pour **toute** la production (le profit vaut `sellPrice − coût total`, `IndustryProject` l. 452-455).

Un nom existe déjà pour la quantité produite dans une ressource voisine : `ProfitMarginResource::$outputQuantity` (`int`), avec `$outputPerRun`.

## Règles métier

### #279 — Étape de copie

- **R1 — Unités par run d'une étape de copie.** Une étape de copie produit un BPC par run : `SplitGroupMaterials::outputPerRunOf()` rend 1 pour l'activité `copy`, sans lire le SDE. Les autres activités gardent la règle R1 de la spec #137 (produit du SDE, sinon `BlueprintProductMissingFromSdeException`).
  Fichier touché : `src/Service/Industry/SplitGroupMaterials.php::outputPerRunOf()`.
- **R2 — Une seule règle de copie.** La constante `OUTPUT_PER_COPY_RUN = 1` passe de `SplitStepProcessor` à `SplitGroupMaterials` ; `SplitStepProcessor::getOutputPerRun()` disparaît et `process()` appelle `SplitGroupMaterials::outputPerRunOf()` directement. Comportement du découpage inchangé.
  Fichiers touchés : `src/State/Processor/Industry/SplitStepProcessor.php::process()` (et suppression de `getOutputPerRun()`), `SplitGroupMaterials` (constante).
- **R3 — PATCH des runs d'une copie.** Runs saisis sur une étape de copie : `quantité = runs` (R1). `UpdateStepProcessor::update()` ne change pas de code : il lit déjà les unités par run par `outputPerRunOf()`. Une copie dont le blueprint n'a pas de produit de fabrication dans le SDE n'est plus refusée (400) au PATCH des runs.
  Fichier concerné (sans modification) : `src/State/Processor/Industry/UpdateStepProcessor.php::update()`.

### #280 — Étape ajoutée à la main depuis un `typeId`

- **R4 — Producteur de l'étape.** Le blueprint de l'étape est le **producteur** du type : `SplitGroupMaterials::producerOf($this->activityProductRepository, $typeId)` (fabrication, puis réaction ; plusieurs producteurs pour une même activité → le plus grand `quantity`, règle de `findBlueprintForProduct()`). Les deux `findOneBy` (activités 1 et 11) disparaissent.
- **R5 — Champs posés.**
  - `blueprintTypeId` = `typeId` du producteur (le blueprint de fabrication ou la formule de réaction), jamais le typeId du produit ;
  - `activityType` = `reaction` si l'activité du producteur est `IndustryActivityType::Reaction` (11), sinon `manufacturing` (`producerOf()` ne rend que ces deux activités) ;
  - `runs` = runs saisis ; `quantity` = `runs × quantity du producteur` ;
  - inchangés : `productTypeId` = `typeId`, `depth` 0, `sortOrder` = plus grand `sortOrder` du projet + 1, `meLevel` / `teLevel` = saisis ou 0.
- **R6 — Type sans producteur.** `ProducerMissingFromSdeException` est attrapée autour du seul appel à `producerOf()` et traduite en `BadRequestHttpException` (400) avec le message de l'exception (`No blueprint or reaction found for product type ID {typeId}`) et l'exception d'origine en `previous`. Aucune étape n'est ajoutée au projet, aucun `flush`.
- **R7 — Ordre des refus inchangé.** Sans `typeId`, `splitGroupId` ni `stepId` : 400 `typeId, splitGroupId or stepId is required` ; type absent de `InvTypeRepository` : 400 `Unknown type`, avant toute recherche du producteur. R6 vient ensuite.
  Fichier touché pour R4 à R7 : `src/State/Processor/Industry/CreateStepProcessor.php::process()` (branche `typeId` seulement).
- **R8 — Limite avec #191.** Les branches `stepId` et `splitGroupId` de `CreateStepProcessor::process()` ne changent pas : leur `setQuantity($runs)`, le `totalGroupRuns` non mis à jour et l'absence de recalcul des matériaux par job (`SplitGroupMaterialNeeds`) restent à #191. Ce lot couvre, de #191, la quantité de la branche `typeId` (l. 186) et le repli `?? $typeId` (l. 170).

### #281 — Prix de vente par unité de l'onglet d'estimation

- **R9 — Quantité produite exposée.** `ProductionCostService::estimateTotalCost()` rend une clé `outputQuantity` (`int`) = `runs du projet × quantity du producteur` (la valeur déjà calculée par `calculateTotalOutputQuantity()`, qui sert aussi `perUnit`). Elle est connue même quand `totalCost` est inconnu. Producteur absent : `ProducerMissingFromSdeException`, inchangé (spec #125 R4).
  Fichier touché : `src/Service/Industry/ProductionCostService.php::estimateTotalCost()` (et son type de retour PHPStan).
- **R10 — Champ d'API.** `CostEstimationResource` gagne `public int $outputQuantity;` (sans valeur par défaut), recopié par `CostEstimationProvider::provide()` depuis `$estimation['outputQuantity']`. Champ JSON : `outputQuantity`.
  Fichiers touchés : `src/ApiResource/Industry/CostEstimationResource.php`, `src/State/Provider/Industry/CostEstimationProvider.php::provide()`.
- **R11 — Type front.** `CostEstimation` (`frontend/src/stores/industry/types.ts`) gagne `outputQuantity: number`.
- **R12 — Prix de vente par unité.** Dans `CostEstimationTab.vue`, un `computed` `sellPricePerUnit` vaut `sellPrice / costData.outputQuantity`, ou `null` si le prix de vente est inconnu (`null`) : jamais 0, jamais une division par les runs. La ligne `perUnitShort` s'affiche quand `outputQuantity > 1` et montre `formatIsk(sellPricePerUnit)`, ou le libellé `industry.costEstimation.unknown` si `null`. Le `computed` `runs` (`project.runs ?? 1`) disparaît.
- **R13 — En-tête de la comparaison.** L'en-tête de la section « Comparaison prix de vente » montre la quantité produite (`80,000 units`), plus les runs.
  Fichier touché pour R11 à R13 : `frontend/src/components/industry/CostEstimationTab.vue` (script : `sellPricePerUnit`, suppression de `runs` ; template : en-tête et ligne `perUnitShort`), `frontend/src/stores/industry/types.ts` (`CostEstimation`).

Contrat proposé (noms indicatifs, l'implémentation tranche) :

```php
public const int OUTPUT_PER_COPY_RUN = 1; // SplitGroupMaterials
public function estimateTotalCost(IndustryProject $project): array // + outputQuantity: int
public int $outputQuantity; // CostEstimationResource
```

## Exemples chiffrés (tests rouges)

Données : Hydrogen Fuel Block (4246) et Helium Fuel Block (4247) à 40 par run en fabrication, sur des blueprints synthétiques des harnais existants ; Fernite Carbide (16673), formule 46166 à 10 000 par run ; produit fabriqué et réagi 990 457 (blueprint 991 457 à 40, formule 992 457 à 200), constantes de `SplitGroupMaterialsTest` ; Tritanium (34), minéral sans producteur.

### #279 — `SplitGroupMaterialsTest`, `UpdateStepProcessorTest` (`#[Group('issue-279')]`)

| # | Entrée | Attendu |
|---|---|---|
| H1 | `outputPerRunOf()`, blueprint Helium Fuel Block 1 004 247 (produit de fabrication à 40), activité `copy` | **1** (aujourd'hui 40). Réécrit `testUnitsPerRunOfACopyStepAreCurrentlyTheUnitsPerRunOfTheProductManufacturedByItsBlueprintIssue137` |
| H2 | `outputPerRunOf()`, blueprint 999 999 999 absent du SDE, activité `copy`, dépôt en mock | **1**, aucune exception, `findOneBy` jamais appelé (aujourd'hui `BlueprintProductMissingFromSdeException`) |
| H3 | `outputPerRunOf()`, blueprint Helium Fuel Block, activité `manufacturing` | 40 (non-régression) |
| H4 | PATCH `runs` = 10 sur l'étape de copie Hydrogen Fuel Block (runs 6, quantité 6, profondeur 1) | runs **10**, quantité **10** (aujourd'hui 400). Réécrit `testRunsEnteredOnACopyStepCurrentlyCountTheUnitsPerRunOfTheProductManufacturedByItsBlueprintIssue137` |
| H5 | PATCH `runs` = 10 sur une étape de copie dont le blueprint (999 999 999) n'a pas de produit dans le SDE | runs 10, quantité 10, pas de 400 (aujourd'hui 400 `Product of blueprint 999999999 missing from the SDE`) |
| H6 | Découpage manuel d'une copie Nomad de 10 runs en 2 jobs ; copie Quantum Microprocessor de 32 runs en 3 jobs | inchangé : `[[5, 5, 0, 10], [5, 5, 1, 10]]` et `[[11, 11, 0, 32], [11, 11, 1, 32], [10, 10, 2, 32]]` (tests `SplitStepProcessorTest` existants, R2) |
| H7 | PATCH `runs` = 3 sur une étape de réaction Fernite Carbide | quantité 30 000 (non-régression, test existant) |

### #280 — nouveau `tests/Unit/State/Processor/Industry/CreateStepProcessorTest.php` (`#[Group('issue-280')]`)

| # | Entrée (POST avec `typeId`) | Attendu sur l'étape ajoutée |
|---|---|---|
| C1 | Helium Fuel Block 4247, runs 5 ; producteur : blueprint 1 004 247, fabrication, 40 par run | blueprint **1 004 247**, produit 4247, `manufacturing`, runs 5, quantité **200**, profondeur 0 (aujourd'hui quantité 5) |
| C2 | Fernite Carbide 16673, runs 3 ; aucun producteur en fabrication, formule 46166 à 10 000 | blueprint **46 166**, `reaction`, runs 3, quantité **30 000** (aujourd'hui 3) |
| C3 | Produit 990 457, runs 2 ; blueprint 991 457 à 40 et formule 992 457 à 200 | blueprint **991 457**, `manufacturing`, quantité **80** (la fabrication prime) |
| C4 | Tritanium 34, runs 4, type connu, aucun producteur | `BadRequestHttpException`, message **`No blueprint or reaction found for product type ID 34`** ; le projet n'a aucune étape ; `flush` jamais appelé (aujourd'hui : étape créée avec blueprint 34, `manufacturing`, quantité 4) |
| C5 | Type 999 999 999 absent de `InvTypeRepository` | 400 `Unknown type` ; `findBlueprintForProduct` jamais appelé (non-régression, R7) |
| C6 | Helium Fuel Block, runs 2, `meLevel` 10, `teLevel` 20, projet dont le plus grand `sortOrder` vaut 4 | ME 10, TE 20, `sortOrder` 5, quantité 80 |

Harnais : `Security` qui rend le propriétaire du projet, `IndustryProjectRepository::find` qui rend un `IndustryProject` réel, `InvTypeRepository::find` qui rend un `InvType` pour les types connus, `IndustryActivityProductRepository::findBlueprintForProduct` en callback (même forme que `SplitGroupMaterialsTest::productRepositoryWith()`), `IndustryResourceMapper` en stub (comme `UpdateStepProcessorTest::patch()`), `EntityManagerInterface` en mock (`flush` une fois pour C1-C3 et C6, jamais pour C4).

### #281 — `ProductionCostServiceTest`, `CostEstimationProviderTest` (`#[Group('issue-281')]`)

| # | Entrée | Attendu |
|---|---|---|
| P1 | `estimateTotalCost()`, projet Fernite Carbide de 8 runs (fixture `ferniteCarbideReactionProjectOfEightRuns([])`), formule à 10 000 | `outputQuantity` **80 000** ; `perUnit` 1,15 (inchangé) |
| P2 | `estimateTotalCost()`, produit 990 457 de 5 runs, blueprint à 40 et formule à 200 | `outputQuantity` **200** |
| P3 | `estimateTotalCost()`, projet Fernite Carbide de 8 runs avec un produit en erreur (coût total inconnu) | `totalCost` et `perUnit` `null`, `outputQuantity` **80 000** (connu) |
| P4 | `CostEstimationProvider::provide()`, estimation avec `outputQuantity` 80 000 | `$resource->outputQuantity` = **80 000** |

### #281 — nouveau `frontend/tests/components/industry/costEstimationTabSellPricePerUnit.spec.ts` (Vitest)

Même harnais que `costEstimationTabUnknownJobInstallCost.spec.ts` (`apiRequest` mocké, `useProjectsStore().currentProject`, i18n `en` et `fr`, `activate()` puis `flushPromises()`). Projet hors usage personnel.

| # | Projet / estimation | Attendu dans la section « Comparaison prix de vente » |
|---|---|---|
| G1 | Fernite Carbide, runs 8, `sellPrice` 2 400 000 ; `outputQuantity` 80 000 | ligne `@ 30 / unit` en `en`, `@ 30 / unite` en `fr` (2 400 000 / 80 000) ; en-tête `80,000 units` (aujourd'hui `@ 300.00 K / unit` et `8 units`) |
| G2 | Helium Fuel Block, runs 1, `sellPrice` 100 000 ; `outputQuantity` 40 | ligne `@ 2.50 K / unit` (aujourd'hui absente : 1 run) |
| G3 | Rifter, runs 2, `sellPrice` 50 000 ; `outputQuantity` 2 | ligne `@ 25.00 K / unit` (non-régression) |
| G4 | Rifter, runs 1, `sellPrice` 50 000 ; `outputQuantity` 1 | aucune ligne `perUnitShort` ; en-tête `1 unit` |
| G5 | Rifter, runs 2, `sellPrice` `null` ; `outputQuantity` 2 | section absente, aucun `@ 0 / unit` (non-régression) |

## Cas limites

- **Copie et SDE** : R1 ne lit plus le SDE pour une copie ; une copie sur un blueprint absent du SDE n'est plus refusée au PATCH (H5). Le reste de la cascade d'un PATCH ignore déjà les copies (`ProductionStepGraph`), donc rien d'autre ne lève pour elle.
- **`activityIdOf('copy')` reste 1** : il sert aux matériaux et à la clé blueprint-activité (factory, `SplitGroupMaterialNeeds::materialsOf()`), pas aux unités par run d'une copie. Inchangé.
- **Plusieurs producteurs pour un même type et une même activité** (#280) : `findBlueprintForProduct()` garde le plus grand `quantity` et journalise un warning (inchangé). Aujourd'hui, `findOneBy` en prenait un sans ordre.
- **Produit fabriqué et réagi** (#280) : la fabrication prime (C3), comme l'arbre et le glossaire.
- **Type sans producteur** (#280) : refus en 400 (C4). Les étapes déjà créées avec `blueprintTypeId = typeId` restent en base ; leur cascade lève `BlueprintProductMissingFromSdeException` (spec #137). Pas de migration de données dans ce lot.
- **Prix de vente inconnu** (#281) : `sellPricePerUnit` vaut `null`, jamais 0. La section reste masquée sans prix de vente (règle existante), donc le libellé « inconnu » de R12 n'est pas visible aujourd'hui ; il protège un affichage futur de la section sans prix.
- **Quantité produite nulle** (#281) : impossible (runs ≥ 1, `quantity` du SDE ≥ 1) ; pas de garde ajoutée côté front.
- **Projet à plusieurs produits racines** (#281) : `outputQuantity` suit `IndustryProject::$runs` et le producteur de `IndustryProject::$productTypeId`, comme `perUnit`. Inchangé.
- **Prix de vente estimé** (`estimatedSellPrice`) : non concerné ; il est déjà multiplié par les unités par run à la création (spec #125 R5).

## Décisions prises en autonomie

1. **Règle de copie dans `outputPerRunOf()`** (R1) plutôt que dans `UpdateStepProcessor` : c'est la fonction que nomme l'issue et qu'appellent le PATCH et le découpage ; la règle « un BPC par run » vit ainsi en un seul endroit.
2. **Constante déplacée, `SplitStepProcessor::getOutputPerRun()` supprimée** (R2) : sans cela, la règle de copie serait écrite deux fois. Aucun changement de comportement, couvert par les tests de découpage existants (H6).
3. **Pas de lecture du SDE pour une copie** (R1, H2) : la quantité d'un BPC ne dépend pas du produit fabriqué.
4. **Noms des tests réécrits** : ils perdent `Currently` et le suffixe `Issue137`, et passent du groupe `issue-137` au groupe `issue-279`, l'issue qui fixe le comportement.
5. **Refus en 400 dans `CreateStepProcessor`** (R6), par un `try`/`catch` limité à l'appel `producerOf()`, comme `UpdateStepProcessor`, `SplitStepProcessor` et `MergeStepsProcessor` le font pour `BlueprintProductMissingFromSdeException`. Pas de `#[WithHttpStatus]` sur l'exception : `estimateTotalCost()` doit rester en erreur serveur (spec #125 R4).
6. **`activityType` par le producteur** (R5) : `reaction` pour l'activité 11, `manufacturing` sinon. Le `match` existant est gardé, avec `IndustryActivityType::Reaction->value` à la place du littéral 11.
7. **Périmètre #191** (R8) : seule la branche `typeId` est traitée. Les branches `stepId` et `splitGroupId` demandent `totalGroupRuns` et le recalcul des matériaux par job, que #280 ne décide pas.
8. **Nom du champ `outputQuantity`** (R10) : même nom et même sens que `ProfitMarginResource::$outputQuantity`, ressource voisine du même onglet de projet. La quantité produite est exposée plutôt que les unités par run, pour que le front divise sans refaire `runs × unités par run`.
9. **`int` non nullable, sans valeur par défaut** (R10) : la quantité produite est toujours connue quand l'estimation répond ; un producteur absent fait déjà échouer l'estimation.
10. **En-tête de la comparaison corrigé aussi** (R13) : il affiche aujourd'hui les runs comme des unités, à côté du prix par unité corrigé ; laisser `8 units` contredirait `@ 30 / unit`. Le texte reste en dur (`unit`/`units`) comme aujourd'hui, seule la valeur change.
11. **Ligne par unité affichée dès que `outputQuantity > 1`** (R12, G2) : la condition suit la quantité produite, plus les runs.

## Hors périmètre

- Branches `stepId` et `splitGroupId` de `CreateStepProcessor` (#191, R8).
- Étapes existantes créées avec `blueprintTypeId = typeId` : pas de nettoyage ni de commande.
- Prix de vente estimé des projets de réaction existants (T2 de la spec #137).
- Les autres copies de la recherche du producteur (`IndustryTreeService::findProducerFor`, `PlansStockpileImports::producerOf`, `GroupIndustryProjectService::producerOf`, `CreateGroupProjectProcessor`, `PivotAdvisorService`).
- La durée par run d'une copie et le passage activité → `activityId` de la durée (#126).
- La traduction i18n de l'en-tête `unit`/`units`.
- La branche `default => 0.0` de `perUnit`.

## Tests existants appelés à casser

Backend :

- `tests/Unit/State/Processor/Industry/UpdateStepProcessorTest.php::testRunsEnteredOnACopyStepCurrentlyCountTheUnitsPerRunOfTheProductManufacturedByItsBlueprintIssue137` : attend `[10, 400]`. Réécrit en **`testRunsEnteredOnACopyStepCountOneBlueprintCopyPerRun`**, `#[Group('issue-279')]`, attendu `[10, 10]` (H4).
- `tests/Unit/Service/Industry/SplitGroupMaterialsTest.php::testUnitsPerRunOfACopyStepAreCurrentlyTheUnitsPerRunOfTheProductManufacturedByItsBlueprintIssue137` : attend 40. Réécrit en **`testUnitsPerRunOfACopyStepAreOneBlueprintCopy`**, `#[Group('issue-279')]`, attendu 1 (H1).
- `tests/Unit/State/Provider/Industry/CostEstimationProviderTest.php` : les trois tests (groupes `issue-119` et `issue-7`) passent à `estimateTotalCost` un tableau sans clé `outputQuantity` ; le provider lèverait une clé indéfinie. Harnais à compléter (`'outputQuantity' => 2`, projet de 2 runs), assertions inchangées.

Frontend :

- `frontend/tests/components/industry/costEstimationTabUnknownJobInstallCost.spec.ts` et `costEstimationTabFailedProducts.spec.ts` : projet avec `sellPrice` 50 000 et `runs` 2, section de comparaison affichée ; leurs estimations n'ont pas `outputQuantity` (en-tête `undefined units`). Fixtures à compléter avec `outputQuantity: 2`, assertions inchangées.
- `frontend/tests/components/industry/costEstimationTabScrollContainer.spec.ts` : `VEXOR_COST_ESTIMATION` est typée `CostEstimation` sans cast ; le type-check casse sans `outputQuantity` (`3`, projet Vexor de 3 runs).

À vérifier en phase rouge, sans casse attendue : `SplitStepProcessorTest` (copies, H6), `SplitGroupMaterialNeedsTest`, `IndustryStepCalculatorTest`, `tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest`.

## Termes du glossaire

- À ajouter : **Quantité produite** (Output quantity) : `runs × unités par run` d'un projet ou d'une étape ; pour un projet, unités par run du **producteur** de son produit. Symboles : `CostEstimationResource::$outputQuantity`, `ProfitMarginResource::$outputQuantity`, noyau `producedQuantity`. **Prix de vente** (Sell price) : montant saisi pour toute la production d'un projet ; le prix de vente par unité est `prix de vente / quantité produite`, inconnu si le prix de vente l'est. Symboles : `IndustryProject::$sellPrice`, `CostEstimationTab.vue` (`sellPricePerUnit`).
- À mettre à jour à l'implémentation : **Unités par run** (étape de copie : 1, sans lecture du SDE, `SplitGroupMaterials::outputPerRunOf()`, spec #279) ; **Étape de copie** (ajouter `SplitGroupMaterials::outputPerRunOf()` aux symboles) ; **Producteur** (`CreateStepProcessor` passe par `producerOf()`, 400 sans producteur, spec #280).
- `docs/modules/industry.md`, ligne `SplitGroupMaterials` : ajouter `CreateStepProcessor` aux appelants de `producerOf` et la règle de copie (1 par run) à `outputPerRunOf`.
