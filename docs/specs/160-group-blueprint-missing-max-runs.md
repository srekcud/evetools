# Spec #160 — Limite de runs d'un blueprint ≤ 0 : erreur explicite qui nomme le blueprint

Statut : **mini-lot, brouillon**. Réécrite le 2026-10-28 après #141 : la spec d'origine (découpage maison de la BOM de groupe, valeurs inventées 500 et 10 000) ne correspond plus au code. Il ne reste qu'un cas.

## Contexte

Code lu sur `fix/group-group-industry` (HEAD `dcba51d`, #141 livré par `cf72fc5` pour les tests et `d8f1e7e` pour le code), 2026-10-28.

### Ce que #141 a déjà livré

- **Plus de découpage maison.** `splitJobsByMaxProductionLimit()` n'existe plus dans `src/` (recherche vide), et `IndustryBlueprintRepository` n'est plus lu par `src/Service/GroupIndustry/`. Le plafond vient de `Recipe::$maxProductionLimit`, lu par `ProductionPlanning::planOf()` (`src/Industry/Application/ProductionPlanning.php:31-38`), et la répartition est égale (`ProductionPlanner::splitIntoJobs()`, `ProductionPlanner.php:182-195`, `ceil(runs / plafond)` jobs).
- **Plus de valeur inventée.** Il ne reste aucun repli 500 ou 10 000 dans `src/` (recherche de `?? 500`, `10000`, `10_000` : seuls des identifiants et des tables de volumes sans rapport). Le solo lève déjà la même erreur : `IndustryProjectFactory::maxProductionLimitOf()` (`src/Service/Industry/IndustryProjectFactory.php:667-680`) refuse une ligne absente et une limite ≤ 0.
- **Ligne `sde_industry_blueprints` absente : erreur explicite.** `SdeBlueprintCatalog::recipeOf()` lève une `\UnexpectedValueException` qui nomme le blueprint (`src/Industry/Infrastructure/Sde/SdeBlueprintCatalog.php:59-62`). C'est une `\RuntimeException` : `CreateGroupProjectProcessor::process()` la convertit en 400 (`:78-79`), et rien n'est persisté, car l'erreur part de `buildBom()` avant `persist()` (`GroupIndustryProjectService.php:86-89`). Tests : `Integration\Industry\Infrastructure\Sde\SdeBlueprintCatalogTest::testBlueprintWithoutMaxProductionLimitIsAnExplicitError` (`:214`) et `GroupIndustryBomGoldenCharacterizationTest::testBlueprintWithoutMaxProductionLimitInTheSdeThrowsNamingItAndLeavesTheBomEmpty` (`:1148`).
- **Boucle infinie.** Disparue avec l'ancien découpage.

### Ce qui reste : une limite ≤ 0

L'import écrit 0 quand le SDE ne donne pas de limite (`SdeBlueprintImporter.php:72`, `?? 0`) : une limite de 0 est donc le cas réel d'un blueprint sans limite en production. `recipeOf()` fait `new Runs($blueprint->getMaxProductionLimit())` (`SdeBlueprintCatalog.php:79`), et `Runs` lève `\InvalidArgumentException('A job has at least 1 run, got 0.')` (`src/Industry/Domain/Runs.php:16-18`). Le processor renvoie bien 400, mais le message ne nomme pas le blueprint et fait croire à une erreur de saisie des runs. Aucun test ne fige ce cas (aucun test n'insère ni ne surcharge une limite à 0 ou négative).

L'effet touche tous les appelants du catalogue (Profit Margins, Buy vs Build, coût de production), pas seulement Group Industry. Les seuls `catch` de `\InvalidArgumentException` dans `src/` sont ceux de `CreateGroupProjectProcessor.php:78` et de `CreateProjectProcessor.php:67`, qui attrapent aussi `\RuntimeException` : le changement de type d'exception ne modifie pas leur réponse (400).

## Règle métier

- **R1 — Limite ≤ 0 = limite absente.** Dans `SdeBlueprintCatalog::recipeOf()`, une limite de production ≤ 0 lève la même `\UnexpectedValueException` que la ligne absente, avec un message qui nomme le blueprint et la valeur lue. Aucune limite par défaut, aucune exception dédiée. La création d'un projet répond 400 avec ce message, et rien n'est persisté.

Tout le reste est inchangé : limite ≥ 1, ligne absente, blueprint sans limite utilisé seulement pour une copie ou une invention.

## Exemples chiffrés

SDE golden (`GroupIndustryBomGoldenCharacterizationTest`) : les blueprints ont pour identifiant typeId + 1 000 000 (`BLUEPRINT_TYPE_ID_OFFSET`), et le harnais accepte des surcharges de limite (`maxProductionLimitOverrides`).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| L1 | Nitrogen Fuel Block 1 run, limite de son blueprint à 0 | `\InvalidArgumentException` « A job has at least 1 run, got 0. », sans nom de blueprint | `\UnexpectedValueException` dont le message contient l'identifiant du blueprint (1 004 051) ; BOM vide |
| L2 | idem, limite à -1 | idem | idem |
| L3 | idem, limite à 1 | BOM construite | inchangé (garde-fou) |
| L4 | ligne `sde_industry_blueprints` absente | `\UnexpectedValueException` nommant le blueprint | inchangé (garde-fou, `testBlueprintWithoutMaxProductionLimitInTheSdeThrowsNamingItAndLeavesTheBomEmpty`) |
| L5 | `POST /group-industry/projects` sur L1 | 400, message sans blueprint | 400, message qui nomme le blueprint, rien de persisté |

## Hors périmètre

- **Lister tous les blueprints sans limite.** Le catalogue lit les recettes une par une : l'erreur part au premier blueprint rencontré, comme dans le solo.
- **`SdeBlueprintImporter`.** Il écrit 0 pour une limite absente (`?? 0`) ; R1 traite ce 0 comme une limite absente. Corriger l'import relève d'un autre sujet.
- Le découpage lui-même et son périmètre : décidés par #141 (fabrication et réaction plafonnées, répartition égale), non rouverts ici.
- Les projets déjà en base : leur BOM n'est jamais reconstruite.

## Tests

Aucun test existant ne change. Deux tests sont ajoutés, par proximité avec leurs voisins :

- un test d'intégration dans `tests/Integration/Industry/Infrastructure/Sde/SdeBlueprintCatalogTest.php`, voisin de `testBlueprintWithoutMaxProductionLimitIsAnExplicitError` (`:214`) : un blueprint dont la limite vaut 0 lève `\UnexpectedValueException` dont le message contient l'identifiant du blueprint ;
- un test golden dans `tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php`, voisin de `testBlueprintWithoutMaxProductionLimitInTheSdeThrowsNamingItAndLeavesTheBomEmpty` (`:1148`) : limite surchargée à 0, `\RuntimeException` qui nomme le blueprint, BOM vide. Le test voisin de découpage est `testJobAboveTheMaxProductionLimitOfItsBlueprintIsSplitEvenlyButNotItsCopy` (`:1078`).

## Glossaire

Le terme **Plafond de runs d'un BPC** (`docs/glossary.md`) ne cite plus `splitJobsByMaxProductionLimit()` : aucun changement. Un complément reste à proposer après validation : « Une limite absente du SDE ou ≤ 0 fait échouer le calcul (spec #160) ».

## 🚦 Questions pour Sylvain

1. **Limite ≤ 0 = limite absente, même erreur ?** Recommandé : oui, c'est le cas réel puisque l'import écrit 0.
2. **Une seule erreur (la première), sans liste de tous les blueprints concernés.** Recommandé : oui, comme `IndustryProjectFactory::maxProductionLimitOf()` : la lecture est paresseuse, recette par recette.

Questions de la spec d'origine, tranchées par #141 (livré) : erreur plutôt qu'état inconnu (#141 R7 et R10) ; périmètre du plafonnement (#141 Q2).
