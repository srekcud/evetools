# #190 M5 : phase verte (identifiants avant flush)

Fichier modifie : src/Service/Industry/ReactionStepMeLevelReset.php (reset()).
- Lecture de l'id du proprietaire (throw `A project owner read from the database has no identifier`) puis construction de l'outcome (throw `A project read from the database has no identifier` via outcomeOf) AVANT le flush.
- `?? ''` supprime : projectId publie = `$outcome->projectId`.
- Mercure publie en dernier, apres le flush. Mode dry-run : outcome seul.
- ProjectByProjectCorrection et failedOutcomeOf() inchanges.

Validation : suite Unit 14501 tests OK (0 echec, 296 notices PHPUnit), phpstan 0 erreur, deptrac 0 violation.
