# Spec #159 — Les unités par run d'une recette sont strictement positives

Statut : validée en autonomie le 2026-10-28 (mode nuit), à relire par Sylvain. Termes : [glossaire](../glossary.md) ; règles du noyau : [spec du noyau](industry-engine.md).

## Contexte

`Recipe::$outputPerRun` est une `Quantity` (`src/Industry/Domain/Recipe.php:18`), qui accepte 0 (`src/Industry/Domain/Quantity.php:14`, seul le négatif est refusé). Le noyau divise pourtant par ce nombre :

- `ProductionPlanner.php:50` : `ceil($toBuild / $recipe->outputPerRun->value)` (mode plan) ;
- `ProductionPlanner.php:96` : même division en coût marginal ;
- `ProductionCostCalculator.php:99` : `1 / $producedQuantity->value`, où `$producedQuantity = runs × unités par run` (`:89`) ;
- `BuyVsBuildService.php:121` : `ceil($quantity / $componentRecipe->outputPerRun->value)`.

Avec 0 unité par run, ces lignes lèvent une `DivisionByZeroError` loin de la cause, ou un calcul fractionnaire dérive. `Runs` refuse déjà moins d'un run à la construction (`src/Industry/Domain/Runs.php:12-17`) : le rendement d'un run n'a pas la même protection. Les numéros de ligne de l'issue datent de l'audit du 2026-10-22 ; ceux de cette spec sont ceux de la branche.

Latent : aucune des 96 recettes de `tests/Fixtures/Golden/sde-blueprint-recipes.json` n'a un rendement nul ou négatif.

## Règles métier

1. **Un value object dédié.** `App\Industry\Domain\OutputPerRun` (`final readonly`, `public int $value`), comme `Runs` et `Quantity`. Il porte les **unités par run** d'une recette, jamais une quantité de stock ni un nombre de runs.
2. **Strictement positif.** Sa construction refuse toute valeur inférieure à 1 avec `\InvalidArgumentException`, selon la convention de `Runs` : constante `MINIMUM = 1`, message formé avec `\sprintf` sur le minimum et la valeur reçue.
3. **Message.** `A recipe yields at least 1 unit per run, got <valeur>.` (calqué sur `A job has at least %d run, got %d.`, `Runs.php:17`).
4. **Le type de `Recipe` change.** `Recipe::$outputPerRun` devient `OutputPerRun`. Une `Recipe` à 0 unité par run n'est plus constructible.
5. **Aucune conversion implicite.** `OutputPerRun` n'hérite pas de `Quantity` et n'en expose pas de conversion : `ProductionCostCalculator.php:89` continue de fabriquer une `Quantity` à partir de `runs × value`.
6. **Valeur lue inchangée.** Le nom de la propriété (`value`) est conservé : les cinq lectures de `->outputPerRun->value` en `src/` ne changent pas.

## Exemples

| # | Entrée de `new OutputPerRun(...)` | Attendu |
|---|---|---|
| E1 | 0 | `\InvalidArgumentException` : `A recipe yields at least 1 unit per run, got 0.` |
| E2 | -1 | `\InvalidArgumentException` : `A recipe yields at least 1 unit per run, got -1.` |
| E3 | 1 (vaisseau) | accepté, `value` = 1 |
| E4 | 2 | accepté, `value` = 2 |
| E5 | 10 000 (fuel block de réaction, `SdeBlueprintCatalogTest.php:107`) | accepté, `value` = 10 000 |
| E6 | Recette SDE d'un produit à `quantity` = 0, seule recette de ce produit | `SdeBlueprintCatalog::recipeFor()` lève l'`\InvalidArgumentException` de E1 |
| E7 | Deux recettes SDE du même produit, l'une à 0, l'autre à 10 | recette à 10 (tri `quantity DESC`, `IndustryActivityProductRepository.php:182-185`) : pas d'erreur |

## Cas limites

- **Recette construite par l'adaptateur SDE.** `SdeBlueprintCatalog::recipeOf()` construit la recette avec `new Quantity($producer->getQuantity())` (`SdeBlueprintCatalog.php:76`) ; `getQuantity()` rend un `int` non nul au sens du type (`IndustryActivityProduct.php:27-28`, colonne `integer` non nullable). La construction de `OutputPerRun` à cet endroit lève donc pour un rendement de 0 : l'**exception de construction remonte**, sans valeur par défaut ni recette ignorée (règle du projet : une donnée SDE corrompue est une erreur explicite). Même comportement que `new Runs($blueprint->getMaxProductionLimit())` (`SdeBlueprintCatalog.php:78`) pour un plafond de runs nul.
- **Rendement « absent » dans le SDE.** Il n'atteint pas l'adaptateur comme absent : l'import écrit `(int) $product['quantity']` (`SdeBlueprintImporter.php:107`), sans test de présence. L'écart est signalé dans le rapport ; il est hors périmètre ici. Une ligne absente du JSON donne une ligne avec une valeur numérique quelconque en base ; si elle vaut 0, E6 s'applique.
- **Plusieurs blueprints pour un même produit.** `findBlueprintForProduct()` prend le rendement le plus haut (`IndustryActivityProductRepository.php:182-185`) : une ligne à 0 n'est choisie que si elle est seule (E7).
- **Aucun rattrapage.** Aucun appelant du noyau ne rattrape `\InvalidArgumentException` (aucun `catch` dans `src/Industry/` ni dans `ProfitMarginService`, `BuyVsBuildService`, `BatchProfitScannerService`, `PivotAdvisorService`) : l'erreur traverse le calcul concerné. Elle ne touche que les calculs qui atteignent la recette corrompue.
- **Message sans typeId.** L'exception de `OutputPerRun` ne dit pas quel blueprint est en cause (voir question 1).

## Invariance

Aucun résultat ne change : `Plan`, `MarginalCostPlan` et `ProductionCost` sont identiques pour toute entrée valide d'avant. Les tests dorés (`GoldenPlanTest`, `ProfitMarginGoldenCharacterizationTest`, `IndustryProjectFactoryGoldenStepsCharacterizationTest`…) restent verts sans modification de leurs attendus ni de leurs fixtures. La seule différence observable : une recette à 0 unité par run lève à sa lecture au lieu de faire diviser par zéro plus loin.

Le diviseur de `ProductionCostCalculator.php:99` reste strictement positif : `Runs` ≥ 1 et `OutputPerRun` ≥ 1.

## Sites à migrer (liste exhaustive, Grep de `new Recipe(`, `outputPerRun` et `Quantity` dans `src/` et `tests/`)

Source (1 construction, 1 déclaration) :

| Fichier:ligne | Changement |
|---|---|
| `src/Industry/Domain/Recipe.php:18` | `public Quantity $outputPerRun` → `public OutputPerRun $outputPerRun` |
| `src/Industry/Infrastructure/Sde/SdeBlueprintCatalog.php:76` | `new Quantity($producer->getQuantity())` → `new OutputPerRun(...)` ; l'import `Quantity` reste (matériaux, `:70`) |

Source, lectures sans changement (`->value` conservé) : `ProductionPlanner.php:50`, `:51`, `:96` ; `ProductionCostCalculator.php:89` ; `BuyVsBuildService.php:121`.

Tests : 7 constructions de `Recipe` dans 6 fichiers cassent (`new Quantity(...)` en 3e argument nommé `outputPerRun:`) et passent à `new OutputPerRun(...)`. L'import `Quantity` reste partout (`RecipeMaterial`).

| Fichier:ligne | Construction |
|---|---|
| `tests/Unit/Industry/Domain/ProductionPlannerTest.php:295-298` | helper `recipe()` |
| `tests/Unit/Industry/Domain/ProductionPlannerRecipeReadsTest.php:280-283` | helper |
| `tests/Unit/Industry/Domain/ProductionPlannerMarginalCostTest.php:153-156` | helper |
| `tests/Unit/Industry/Domain/Double/InMemoryBlueprintCatalog.php:41-44` | `$sdeRecipe['outputPerRun']` |
| `tests/Unit/Industry/Application/ProductionCostCalculatorRecipeReadsTest.php:214-217` | helper |
| `tests/Unit/Industry/Application/ProductionCostCalculatorTest.php:1096-1099` | `new Quantity(1)` |
| `tests/Unit/Industry/Application/ProductionCostCalculatorTest.php:1108-1111` | `new Quantity(1)` |

Tests qui lisent `->outputPerRun->value` sans changement : `tests/Integration/Industry/Infrastructure/Sde/SdeBlueprintCatalogTest.php:78`, `:107`, `:167`, `:189`.

Les autres occurrences de `outputPerRun` dans `tests/` (tableaux de fixtures, services historiques : `GroupIndustryProjectService`, `IndustryTreeService`, `ProfitMarginResource`…) sont des `int`, pas une `Recipe` : aucun changement.

Tests à ajouter : `tests/Unit/Industry/Domain/OutputPerRunTest.php` (E1 à E5, sur le modèle de `RunsTest`) ; dans `SdeBlueprintCatalogTest`, E6 (le helper `insertProducer()` accepte déjà `quantity`) et E7.

## Hors périmètre

- `Quantity` garde 0 comme valeur valide (stock, besoin nul : `QuantityTest.php:14`).
- Les `int $outputPerRun` des services historiques (`IndustryTreeService`, `GroupIndustryProjectService`, `ProfitMarginResource`, `CreateProjectProcessor`) : ils ne passent pas par `Recipe`.
- La validation à l'import du SDE (`SdeBlueprintImporter.php:107`).
- Un type pour `RecipeMaterial::$quantity` et pour `maxProductionLimit` (déjà `Runs`).

## Glossaire

Le terme **Unités par run** / *Output per run* existe déjà ; sa définition et son symbole sont mis à jour (`OutputPerRun`, strictement positif). Pas de synonyme « Rendement par run » : on garde le terme en place.

## Questions ouvertes

1. **Message sans identité du blueprint.** L'exception de `OutputPerRun` n'indique ni blueprint ni produit. Recommandation : ne rien ajouter maintenant (même limite que `Runs` pour le plafond de runs) ; si l'exploitation le réclame, `SdeBlueprintCatalog` relance en `\UnexpectedValueException` avec le typeId, comme `:60` et `:65`.
2. **Import du SDE.** Un rendement absent est écrit sans contrôle (`SdeBlueprintImporter.php:107`). Recommandation : issue séparée pour refuser la ligne à l'import, avec le blueprint nommé dans l'erreur.
3. **Réponse HTTP.** Une recette corrompue donne une erreur 500 sur les écrans qui l'atteignent. Recommandation : l'accepter, la donnée SDE étant réimportée à chaque version du SDE ; pas de traduction en 4xx.
