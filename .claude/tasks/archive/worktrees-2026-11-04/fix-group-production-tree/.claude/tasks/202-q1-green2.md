# #202 Q1 (O2 f révisée) - phase verte

- `App\Exception\StepMaterialsMissingFromSdeException` (message : clé de blueprint absente), namespace des tests.
- `StepMaterialTypeIdsReader` : lit les clés manquantes du projet à chaque appel (la lecture ne se fait que s'il en manque), met en cache les clés trouvées, lève dès qu'une clé est absente de la réponse ; plus de `?? []`. Aucun état d'erreur mémorisé : chaque appel suivant lève tant que la clé manque.
- `DeleteStepProcessor` : 400 `Cannot delete a step while the materials of a blueprint of the project are missing from the SDE`, avant toute écriture.
- `IndustryResourceMapper::isDeletable()` : attrape l'exception, `deletable` à faux.
- `docs/modules/industry.md` : ligne de suppression complétée.

Validation : suite Unit 14435 tests OK (294 notices PHPUnit et 1 incomplet antérieurs), phpstan 4 fichiers sans erreur, deptrac 0 violation.
