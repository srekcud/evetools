# #190 M5 : rouges (identifiants) et tueurs

Branche `fix/group-production-tree`, base 63517239. Tests seulement, `src/` intact.

## Commit 1 (rouge) : cc0f5626

`test(industry): fail a reaction ME reset without owner or project identifier before any flush (#190)`

Défaut : `ReactionStepMeLevelReset::reset()` fait `flush()` puis lit `getUser()->getId()` et `getId()`. Si l'un manque, la base est écrite alors que le projet est rapporté `failed`. Et `getId()?->toRfc4122() ?? ''` publie `projectId: ''` sur Mercure.

Fichier : `tests/Unit/Service/Industry/ReactionStepMeLevelResetTest.php`
- `testOwnerWithoutIdentifierFailsTheProjectBeforeAnyFlush` : propriétaire `new User()` (id null). Attendu : `failed`, `A project owner read from the database has no identifier`, appels EM `['clear']`, aucune publication.
  Aujourd'hui : le résumé passe, l'échec porte sur `['flush', 'clear']` au lieu de `['clear']`.
- `testProjectWithoutIdentifierFailsBeforeAnyFlush` : projet lu sous l'id `…016673`, id mis à null par réflexion. Attendu : `failed`, `A project read from the database has no identifier`, appels EM `['clear']`, aucune publication.
  Aujourd'hui : même échec sur le flush. Sans assertion, la publication `projectId: ''` serait elle aussi visible.
  Libellé attendu : `type 16673 (name missing from the SDE)`. C'est le repli actuel de `ProjectByProjectCorrection::failedOutcomeOf()` quand `outcomeOf()` lève faute d'id. Si la phase verte rend un autre libellé, ce test le fige : à arbitrer.
- Harnais : `resetOf()` délègue à `resetReadingEachProjectUnderItsId(array $projectsById)`. Le dépôt doublé rend l'UUID de la clé, indépendamment de `getId()`.

Les deux tests échouent sur l'assertion métier (0 flush), pas sur une erreur.
Recherche de tests liés : `grep -rn "has no identifier\|ReactionStepMeLevelReset\|applyStepQuantities" tests frontend/tests` ne donne que `ResetReactionMeLevelsCommandTest`, qui double le service et n'est pas concerné. Aucun test `Currently…Issue190`.

## Commit 2 (tueurs, verts) : b83ef889

1. `ReactionStepMeLevelResetTest::testUpdatePublishedToTheOwnerCarriesTheIdOfTheResetProject` : `data.projectId` publié sur `/user/<pilot>/sync/industry-project` = `…116673`. Il tue le mutant `?? ''` et le mutant qui retire le tableau `data`.
2. `#[CoversMethod(IndustryStepCalculator::class, 'applyStepQuantities')]` sur `ReactionStepMeLevelResetTest`, en plus de `CoversClass(ReactionStepMeLevelReset)`. L'attribut est placé ici, et non dans `IndustryStepCalculatorTest` : ce dernier a déjà `CoversClass(IndustryStepCalculator)` et n'exécute pas le service. L'attribut ne crédite que les lignes de `applyStepQuantities`, pas la marche privée.
3. `IndustryStepCalculatorTest` (méthodes `#[Group('issue-190')]`) :
   - `testApplyStepQuantitiesReturnsTheChangedStepsAndNeverFlushes` : cas parent/enfant 5 par run → 45. Vérifie que la méthode rend `[$child]` avec `[201, 45, 45]`, que `flush` n'est jamais appelé, et que le résultat est identique à `recalculateStepQuantities()` sur un projet jumeau (second calculateur, EM stub).
   - `testApplyStepQuantitiesRemovesTheSurplusJobAndNeverFlushes` : scénario de la l. 403 (deux jobs fixés 6/60 et 4/40, job à 0 run supprimé). Vérifie `[]` rendu, étapes `[consumer, first, second]` et `flush` jamais appelé ; le jumeau sous `recalculateStepQuantities()` rend aussi `[]` et garde 3 étapes.
   - Ces deux tests ajoutent 2 « PHPUnit Notices ». Elles viennent des mocks de `setUp()` sans attente, comme les 8 tests existants.
4. `tests/Integration/Service/Industry/ReactionStepMeLevelResetRetryTest.php` (R13), **non exécuté** : `wt-php.sh … integration` refuse de démarrer (`service "app" is not running`, `DATABASE_URL illisible`). Lint `php -l` OK.
   Scénario : SDE avec la formule Carbide (3 000 par run, 50 Alloy par run) sans le produit de la formule Alloy. Le projet a Carbide à 12 runs, ME 10, et Alloy `[14, 540]`.
   - 1er passage : `failed: Product of blueprint 999190202 missing from the SDE`, base inchangée (ME 10, `[14, 540]`).
   - Ajout de la ligne produit Alloy (40 par run), puis 2e passage : projet sélectionné, `changed`, ligne `1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 2 -> 2`, base `[0, 12, 36000]` et `[0, 15, 600]`.
   - 3e passage : `[]`.
   À lancer par la session principale : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter ReactionStepMeLevelResetRetryTest`. Hypothèse non vérifiée : aucun autre projet dans `eve_app_test` au début du test (transaction DAMA).

## Sortie

Filtre `IndustryStepCalculatorTest|ReactionStepMeLevelResetTest|ResetReactionMeLevelsCommandTest` : 36 tests, 2 échecs (les deux rouges), 10 notices.
Groupe `issue-190` (suite Unit) : 41 tests, 2 échecs (les mêmes rouges).
