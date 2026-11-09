# #190 M5 — phase verte

## Fichiers
- `src/Service/Industry/ReactionStepMeLevelReset.php` (nouveau, non final, étend `ProjectByProjectCorrection`)
- `src/Command/ResetReactionMeLevelsCommand.php` (nouveau)
- `src/Service/Industry/IndustryStepCalculator.php` : `applyStepQuantities()` (cascade sans flush) ; `recalculateStepQuantities()` = cette méthode + flush si une étape change ou si le nombre d'étapes baisse (équivalent de l'ancien `hasRemovedJob`)
- `docs/modules/industry.md`, `docs/glossary.md`

## Choix
- Constructeur : repository, EntityManager, `IndustryCalculationService`, `IndustryStepCalculator`, `MercurePublisherService`. Pas de logger (R12 : jamais appelé).
- Mercure : `syncCompleted($ownerId, 'industry-project', 'Reaction material efficiency reset', ['projectId' => ...])` après le flush, hors dry-run, sans `syncStarted`.
- Le service compte les ME distincts avant (triés numériquement), les étapes avant/après et les étapes rendues par la cascade.

## Validation
- 23 tests M5 verts (79 assertions).
- Suite Unit : 14496 tests, OK ; notices PHPUnit et incomplet InstallCostTest D11a préexistants.
- phpstan sur les 3 fichiers src : aucune erreur.
- deptrac : 0 violation.

## Actions prod (rappel)
Aucune migration. Après déploiement, avant `regenerate-multi-unit-steps` : `app:industry:reset-reaction-me-levels --dry-run` puis sans option (spec, section Actions prod).
