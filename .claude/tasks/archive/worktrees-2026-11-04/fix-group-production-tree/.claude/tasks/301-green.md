# #301, phase verte

`src/Service/Industry/ProjectByProjectCorrection.php` seul modifié (+ `docs/modules/industry.md` l. 38).

- `outcomeOf` : libellé = `resolveTypeName($project->getProductTypeId())`, repli `UnknownTypeName::label`. `getProjectDisplayName()` intact.
- `reasonOf(\Throwable)` : `App\` ou classe native (sans backslash) -> `<FQCN>: <message>` coupé à 200 + `...` ; sinon classe seule. Constantes `MAX_FAILURE_MESSAGE_LENGTH`, `APPLICATION_NAMESPACE_PREFIX`.
- `ProjectCorrectionConsoleReport` inchangé.

Validation : suite Unit 14508 tests OK (notices préexistantes), `--group issue-301` 17/17, phpstan 0 erreur, deptrac 0 violation. Test d'intégration `ReactionStepMeLevelResetRetryTest` non exécuté (stack Docker arrêtée), à lancer par la session principale.
