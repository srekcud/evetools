# Spec #137 + #125 — Unités par run lues dans le SDE, jamais 1 par défaut

**Statut : rédigée en mode nuit le 2026-10-29, à valider par Sylvain.** Aucune question n'a pu être posée : les choix faits en autonomie sont listés dans « Décisions prises en autonomie », ceux qui changeraient un montant affiché sans que les issues le prévoient sont dans « À trancher par Sylvain » et restent hors du lot.

## Contexte

Les **unités par run** d'une **étape** (ou du produit d'un projet) sont lues dans le SDE à plusieurs endroits, avec deux règles différentes :

- `SplitGroupMaterials::outputPerRunOf()` (découpage et fusion d'un **groupe découpé**, besoins des enfants) lève `BlueprintProductMissingFromSdeException` quand le blueprint de l'étape n'a pas de produit pour son activité ;
- quatre autres sites retombent sur 1 unité par run (`?? 1`) : un produit absent donne des runs et des quantités plausibles mais fausses, et deux chemins de calcul d'un même projet peuvent diverger (#137).

Pour un projet dont le produit est une **réaction**, le coût par unité et le prix de vente estimé cherchent le **producteur** seulement en fabrication, ne le trouvent pas et prennent 1 unité par run : faux d'un facteur 10 000 pour Fernite Carbide (#125).

État vérifié dans le code au 2026-10-29 :

| Site | Aujourd'hui | Appelants |
|---|---|---|
| `IndustryStepCalculator::outputPerRunOf()` (privée) | lit le produit dans la lecture groupée `findProductsForBlueprints()` par clé `blueprint-activityId` ; absent → `?? 1` | `recalculateStepQuantities()` et `needRunsOf()` ← `UpdateStepProcessor` (PATCH d'une étape), `IndustryJobMatcher::persistAndRecalculate()` ← `LinkJobProcessor`, `MatchJobsProcessor`, `MatchProjectJobsCommand` |
| `UpdateStepProcessor::getQuantityPerRun()` (privée) | réécrit le passage activité → `activityId` (`manufacturing` → 1, `reaction` → 11, `default` → 1), puis `findOneBy` ; absent → `?? 1` | PATCH d'une étape, saisie de runs (`quantity = runs × unités par run`) |
| `CreateProjectProcessor::process()` (prix de vente estimé) | `findBlueprintForProduct($productTypeId, 1)` : fabrication seulement ; absent → `?? 1`. Le bloc est dans un `catch (\Throwable)` qui journalise un warning `sell_price` et laisse le prix inconnu | POST d'un projet |
| `ProductionCostService::calculateTotalOutputQuantity()` (privée) | `findBlueprintForProduct(…, Manufacturing)` : fabrication seulement ; absent → `?? 1`. Sert à `perUnit = totalCost / (runs × unités par run)` | `estimateTotalCost()` ← `CostEstimationProvider` (GET de l'estimation de coût) |
| `IndustryProjectFactory::collectStepsFromTree()` (privée) | `$node['outputPerRun'] ?? 1` ; le nœud vient toujours de `IndustryTreeService::buildNode()`, qui pose la clé à partir d'un producteur trouvé (sinon `\RuntimeException` « No blueprint or reaction found for product type ID … » → 400 à la création). Repli inatteignable | `createProject()`, `regenerateSteps()` |
| `SplitGroupMaterials::outputPerRunOf()` | `findOneBy(typeId, activityIdOf(activité))` ; absent → `BlueprintProductMissingFromSdeException` | `SplitStepProcessor`, `MergeStepsProcessor` (qui la traduisent en `BadRequestHttpException`, 400), `SplitGroupMaterialNeeds` |

`AdaptStockProcessor`, cité par #137, n'existe plus.

`BlueprintProductMissingFromSdeException` (`\RuntimeException`, message `Product of blueprint %d missing from the SDE`) n'a aucun statut HTTP déclaré : seuls `SplitStepProcessor` et `MergeStepsProcessor` l'attrapent pour répondre 400. Ailleurs, elle remonterait en erreur serveur.

`SplitGroupMaterials::activityIdOf()` envoie `reaction` vers 11 et toute autre activité (dont `copy`) vers 1 : il couvre les produits et matériaux d'une étape de fabrication, de réaction ou de copie (la copie lit le produit fabriqué du blueprint), pas la durée d'une copie (activité 5, sujet de #126).

## Règles métier

- **R1 — Unités par run d'une étape.** Les unités par run d'une étape sont la `quantity` du produit du SDE pour le blueprint de l'étape et l'activité `activityIdOf(activité de l'étape)`. Sans ce produit : `BlueprintProductMissingFromSdeException` avec le typeId du blueprint. Jamais 1 par défaut.
- **R2 — Un seul accès pour R1.** La règle de R1 vit en un seul endroit, à côté de `activityIdOf()` dans `SplitGroupMaterials` :
  - `outputPerRunOf(repository, blueprintTypeId, activityType)` reste l'accès étape par étape ;
  - l'absence du produit est jugée par une seule fonction, que `outputPerRunOf()` appelle et que `IndustryStepCalculator` applique au produit de sa lecture groupée (le calculateur garde `findProductsForBlueprints()` : pas une requête par produit).
  `IndustryStepCalculator::outputPerRunOf()` et `UpdateStepProcessor::getQuantityPerRun()` disparaissent ; le `match` d'activité de `UpdateStepProcessor` aussi.
- **R3 — Producteur du produit d'un projet.** Le producteur du produit d'un projet est cherché en fabrication, puis en réaction (règle du **producteur**, la même que l'arbre qui a généré les étapes). Ses unités par run sont sa `quantity`. Sans producteur : nouvelle `ProducerMissingFromSdeException` (`\RuntimeException`, message `No blueprint or reaction found for product type ID %d`, le même texte que `IndustryTreeService`). Un seul accès, dans `SplitGroupMaterials`, appelé par `ProductionCostService` et `CreateProjectProcessor`.
- **R4 — Coût par unité.** `perUnit = totalCost / (runs du projet × unités par run du producteur, R3)`. `totalCost` inconnu → `perUnit` inconnu (`null`, inchangé). Producteur absent → `estimateTotalCost()` lève `ProducerMissingFromSdeException` ; `CostEstimationProvider` ne l'attrape pas (erreur serveur, comme les autres données de référence manquantes, spec #258 R2).
- **R5 — Prix de vente estimé.** À la création d'un projet : `meilleur prix × runs × unités par run du producteur (R3)`, taxe estimée `= prix de vente estimé × (courtage + taxe de vente)`, règles de choix du prix inchangées. Producteur absent : traitement existant d'un échec d'estimation (warning `Industry project estimation failed`, `estimation: sell_price`, `reason` = message de R3 ; prix, source et taxe restent `null`). Ce n'est pas un catch silencieux : la raison est journalisée.
- **R6 — Mise à jour d'étape.** `UpdateStepProcessor` lit les unités par run par R1 et attrape `BlueprintProductMissingFromSdeException` (lecture de R1, `needRunsOf()`, `recalculateStepQuantities()`) pour répondre 400 avec le message de l'exception, comme `SplitStepProcessor` et `MergeStepsProcessor`. Une mise à jour refusée ainsi ne persiste rien : aucun `flush` avant que la cascade ait réussi (aujourd'hui, le processor flushe avant `recalculateStepQuantities()`).
- **R7 — Rattachement de jobs.** `IndustryJobMatcher` ne change pas : l'exception de R1 levée par `recalculateStepQuantities()` remonte (erreur serveur pour `LinkJobProcessor` et `MatchJobsProcessor`, échec de `MatchProjectJobsCommand`). Les **jobs matchés** déjà flushés restent : ce sont des faits ESI.
- **R8 — Génération des étapes.** `collectStepsFromTree()` lit `$node['outputPerRun']` sans repli. Aucun changement de comportement : les goldens de la factory ne bougent pas. La création d'un projet dont le produit n'a pas de producteur reste refusée en amont par l'arbre (400, message `No blueprint or reaction found for product type ID …`).

Contrat proposé (noms indicatifs, l'implémentation tranche) :

```php
public static function outputPerRunOf(IndustryActivityProductRepository $productRepository, int $blueprintTypeId, string $activityType): int
public static function outputPerRunOfProduct(?IndustryActivityProduct $product, int $blueprintTypeId): int
public static function producerOf(IndustryActivityProductRepository $productRepository, int $productTypeId): IndustryActivityProduct
```

## Exemples chiffrés (tests rouges)

Fernite Carbide : typeId 16673, formule de réaction 46166 dans `ProductionCostServiceTest`, 10 000 unités par run (`GroupIndustryBomGoldenCharacterizationTest`, `StockpileGoldenImportCharacterizationTest`).

| # | Entrée | Attendu |
|---|---|---|
| E1 | `estimateTotalCost()`, projet Fernite Carbide de 8 runs, BPO 12 000, matériaux 800 Fernite Alloy × 95,5 = 76 400, installation 3 600 ; producteur : aucun en fabrication, réaction 46166 à 10 000 par run | `totalCost` 92 000 ; `perUnit` = 92 000 / (8 × 10 000) = **1,15** (aujourd'hui 11 500). Réécrit `ProductionCostServiceTest::testPerUnitCostOfAReactionProjectIsCurrentlyComputedAtOneUnitPerRunIssue125` |
| E2 | Même projet, aucun producteur ni en fabrication ni en réaction | `ProducerMissingFromSdeException`, message `No blueprint or reaction found for product type ID 16673` |
| E3 | `estimateTotalCost()`, produit à 5 runs qui a un producteur en fabrication à 40 par run et un en réaction à 200 par run, `totalCost` 200 000 | la fabrication prime : `perUnit` = 200 000 / (5 × 40) = **1 000** |
| E4 | `estimateTotalCost()`, `totalCost` inconnu (produit en erreur) et producteur réaction à 10 000 | `perUnit` `null` (inchangé) |
| E5 | Création d'un projet Fernite Carbide de 8 runs, prix Jita 30, pas de prix structure, courtage 0,01, taxe de vente 0,02 ; producteur réaction à 10 000 | prix de vente estimé = 30 × 8 × 10 000 = **2 400 000**, source `jita`, taxe estimée **72 000** (aujourd'hui 240 et 7,2) |
| E6 | Création, même projet, aucun producteur | prix de vente estimé, source et taxe `null` ; un warning `Industry project estimation failed` avec `estimation` = `sell_price` et `reason` = `No blueprint or reaction found for product type ID 16673` ; le projet est créé |
| E7 | Création d'un Rifter (587) de 10 runs, 1 par run en fabrication, prix Jita 100 000, courtage 0,01 et taxe 0,02 | 1 000 000 et 30 000 (non-régression, tests #21 existants) |
| E8 | `recalculateStepQuantities()` : Quantum Microprocessor (11540), fabrication, 32 runs, ME 10, profondeur 0 ; Titanium Carbide (16671), réaction, profondeur 1, sur le blueprint 999 999 999 absent du SDE | `BlueprintProductMissingFromSdeException`, message `Product of blueprint 999999999 missing from the SDE` ; aucun `flush` (aujourd'hui : 490 runs, 490 unités). Réécrit `IndustryProjectFactoryGoldenStepsCharacterizationTest::testChildWithoutProductInTheSdeIsCurrentlyRecalculatedAtOneUnitPerRunIssue137` |
| E9 | `needRunsOf()` sur le même projet, étape Titanium Carbide | même exception |
| E10 | `recalculateStepQuantities()` : enfant en réaction à 200 par run, besoin 500 | 3 runs, quantité 500 (non-régression, `IndustryStepCalculatorTest::testReactionStepUsesReactionActivityId`) |
| E11 | PATCH `runs` = 5 sur une étape dont le blueprint n'a pas de produit pour son activité | 400, `detail` = `Product of blueprint {id} missing from the SDE` ; runs et quantité de l'étape inchangés ; aucun `flush` |
| E12 | PATCH `meLevel` sur l'étape racine du projet de E8 (la cascade lève) | 400, même message ; le ME de l'étape racine reste celui d'avant en base |
| E13 | PATCH `runs` = 3 sur une étape de réaction dont le produit fait 10 000 par run | quantité 30 000 (non-régression : activité 11) |
| E14 | PATCH `runs` = 10 sur une étape de copie d'un blueprint de fuel block (fabrication 40 par run) | quantité 400 : comportement actuel conservé dans ce lot (voir T1) |
| E15 | Génération des étapes de tous les goldens de la factory | étapes identiques (R8) |

## Cas limites

- **Plusieurs unités par run** : fuel block (40), réaction (10 000) : R1 et R3 donnent la `quantity` du SDE, jamais arrondie ni ramenée à 1 (E3, E5, E13).
- **Produit fabriqué et réagi** : R3 garde la fabrication (E3), comme l'arbre et le **producteur** du glossaire.
- **Plusieurs producteurs pour un même produit et une même activité** : `findBlueprintForProduct()` garde celui de plus grande quantité (inchangé).
- **Réaction** : `activityIdOf('reaction')` = 11 pour R1 ; R3 la cherche après la fabrication.
- **Copie** : R1 avec `activityIdOf('copy')` = 1, donc les unités par run du produit fabriqué par le blueprint, comme aujourd'hui dans `UpdateStepProcessor` (E14). `SplitStepProcessor` garde son `OUTPUT_PER_COPY_RUN` = 1 et la factory son `outputPerRun` = 1 pour une copie. L'écart est T1.
- **Invention, activité inconnue** : une `IndustryProjectStep` n'a que `manufacturing`, `reaction` ou `copy` (factory, `CreateStepProcessor`, `SplitStepProcessor`) ; aucune entrée d'API ne pose une autre activité. Pas de validation ajoutée.
- **Données SDE manquantes** : produit d'un blueprint absent (R1, E8, E11), producteur absent (R3, E2, E6). Cas réels : SDE réimporté sans le blueprint, ou étape ajoutée à la main par `CreateStepProcessor` sur un type sans producteur (il pose alors `blueprintTypeId = typeId`). Une telle étape consommée par une autre fait désormais échouer la cascade du projet : c'est l'erreur explicite voulue par #137.
- **Échec en cours de cascade** : `recalculateStepQuantities()` applique les besoins produit par produit ; si l'exception tombe au milieu, les étapes déjà modifiées en mémoire ne sont pas flushées par le calculateur, et `UpdateStepProcessor` ne flushe pas (R6).
- **Projet à l'étape racine découpée** : R3 ne lit pas les étapes, le découpage n'a pas d'effet.

## Décisions prises en autonomie

1. **Producteur du projet par le produit, pas par l'étape racine** (R3). Lire l'étape racine aurait réutilisé R1, mais un projet peut avoir plusieurs étapes de profondeur 0 (ajout manuel) et la règle du **producteur** dans le glossaire est « fabrication, puis réaction ».
2. **Nouvelle `ProducerMissingFromSdeException`** plutôt que `BlueprintProductMissingFromSdeException` : sans producteur, il n'y a pas de blueprint à nommer. Même message que l'arbre, pour un seul texte côté utilisateur.
3. **Accès unique dans `SplitGroupMaterials`**, qui porte déjà `outputPerRunOf()` et `activityIdOf()`. Pas de nouvelle classe ; la lecture groupée du calculateur est gardée (pas de N+1, harnais de `IndustryStepCalculatorTest` intact).
4. **400 dans `UpdateStepProcessor`**, comme ses voisins `SplitStepProcessor` et `MergeStepsProcessor` sur la même ressource et pour la même cause. Pas de `#[WithHttpStatus]` sur l'exception : il changerait aussi la réponse d'autres chemins.
5. **Aucune écriture partielle** sur un PATCH refusé (R6), parce que la nouvelle exception arrive après le `flush` actuel.
6. **Estimation de coût et rattachement de jobs non traduits** (R4, R7) : erreur serveur explicite, aucun catch. Traduire en 400 le rattachement serait trompeur, les matchs étant déjà enregistrés.
7. **Prix de vente estimé : traitement d'échec existant** (R5), déjà testé pour les autres estimations à la création.
8. **Repli de la factory supprimé sans test rouge** (R8) : branche inatteignable, couverte par E15.
9. **`activityIdOf()` inchangé**, y compris `copy` → 1 ; le passage activité → `activityId` de la durée (`addTimeDataToSteps`, copie → 5) reste à #126.

## À trancher par Sylvain

- **T1 — Unités par run d'une étape de copie dans le PATCH.** Aujourd'hui `runs × unités par run du produit fabriqué` (fuel block : 10 runs → quantité 400), alors que la génération et le découpage comptent 1 BPC par run (10). Recommandation : 1 par run. Change la quantité affichée d'une étape de copie : hors lot.
- **T2 — Prix de vente estimé des projets de réaction existants.** Il est figé à la création (`CreateProjectProcessor` est le seul à l'écrire) : les projets déjà créés gardent la valeur à 1 unité par run. Recommandation : commande ponctuelle de recalcul, à lancer en prod après déploiement. Change des montants affichés : hors lot.
- **T3 — Étape ajoutée à la main** (`CreateStepProcessor`) : quantité = runs sans unités par run, et `blueprintTypeId = typeId` quand le type n'a pas de producteur. Recommandation : quantité = runs × unités par run (R3), et refuser un type sans producteur (400). Change la quantité affichée : hors lot.
- **T4 — « Prix de vente / unité » de l'onglet d'estimation** (`CostEstimationTab.vue`) : divise le prix de vente saisi par les runs, pas par `runs × unités par run`. Recommandation : exposer les unités par run du projet et diviser par la quantité produite. Change un montant affiché : hors lot.

## Hors périmètre

- Les autres copies de la recherche du producteur (`IndustryTreeService::findProducerFor`, `PlansStockpileImports::producerOf`, `GroupIndustryProjectService::producerOf`, `CreateGroupProjectProcessor`, `CreateStepProcessor`, `PivotAdvisorService`) : à brancher plus tard sur l'accès de R3.
- `GroupIndustryProjectService` (unités par run 1 sur les lignes de copie et d'invention de la BOM de groupe).
- La durée par run et le passage activité → `activityId` de la copie (#126).
- La branche `default => 0.0` de `perUnit` quand la quantité produite vaut 0 (inatteignable avec runs ≥ 1 et unités par run ≥ 1).
- T1 à T4.

## Tests existants touchés

- Réécrits : `ProductionCostServiceTest::testPerUnitCostOfAReactionProjectIsCurrentlyComputedAtOneUnitPerRunIssue125` (E1) et `IndustryProjectFactoryGoldenStepsCharacterizationTest::testChildWithoutProductInTheSdeIsCurrentlyRecalculatedAtOneUnitPerRunIssue137` (E8) : leur nom perd `Currently`, le groupe `issue-125` / `issue-137` reste.
- Harnais à vérifier en phase rouge : `CreateProjectProcessorTest::processor()` stubbe `findBlueprintForProduct` pour toute activité (inchangé pour la fabrication, il faut un stub par activité pour E5 et E6) ; `IndustryJobMatcherTest::createMatcherWithRealStepCalculator()` fournit bien le produit du composant ; `tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest` sème un produit pour chaque blueprint.

## Termes du glossaire

- Ajoutés : **Coût par unité** (Cost per unit), **Prix de vente estimé** (Estimated sell price).
- À mettre à jour à l'implémentation : **Unités par run** (produit absent → `BlueprintProductMissingFromSdeException`, jamais 1 ; accès de R2) et **Producteur** (accès de R3, `ProducerMissingFromSdeException`).
