# #235 (l'étape de copie suit la fabrication) — phase verte

## Changement
Fichier : `src/Service/Industry/IndustryProjectFactory.php`.
- `planStepsJobByJob` termine par `withCopyStepsFollowingManufacturing` : pour chaque étape de copie, runs = somme des runs des jobs de fabrication planifiés du même blueprint (jobs fixés #185 compris, puisque les jobs fixés sont déjà planifiés), puis découpe sous `maxProductionLimit`. Les réglages des étapes existantes (`withSettingsOfExistingStep`) sont réappliqués par `splitIndex`.
- `jobsOf` délègue à un nouveau `splitUnderMaxRuns($step, ?int $maxRunsPerJob)` (corps de découpe inchangé, réutilisé tel quel : `SplitGroupQuantities::runsBySplitIndex` / `share`, nouveau `splitGroupId`, `totalGroupRuns` = N, une seule copie = étape intacte).
- Extraction de `maxProductionLimitOf(int $blueprintTypeId)` depuis `bpcMaxRunsOf` (mêmes erreurs R7, mêmes messages).
- La durée maximale n'entre pas dans le découpage de la copie (on passe le plafond seul).

Docs : `docs/modules/industry.md` (ligne « Groupes découpés, cascade des étapes »). Glossaire déjà à jour (spec).

## Question 2 du rapport rouge
La cascade ne touche pas aux copies : `ProductionStepGraph::productionStepsByProduct` filtre l'activité `copy`. Aucun changement dans `IndustryStepCalculator`.

## Résultats
- `Unit/Service/Industry` : 906 OK (dont les 16 rouges, les 3 garde-fous, BpcMaxRuns).
- `Unit/State` (Processor + Provider) : 1022 OK ; `Unit/Industry` : 347 OK (1 incomplet existant) ; `Unit/Service/GroupIndustry` : 129 OK.
- Intégration (mode integration) : répertoires `Integration/Service/Industry` 30 OK (dont IndustryProjectFactoryRegenerationTest) et `Integration/State/Processor/Industry` 67 OK (dont UpdateStepFixedRunsTest).
- PHPStan sur `IndustryProjectFactory.php` : aucune erreur.

## Notes
- Première itération verte, aucune modification de test.
- Comportement nouveau avec jobs fixés : le plafond de la copie est lu même si la fabrication ne l'a pas lu (R7 plus strict, conforme à la spec).
- Non commité ; `.claude/state/tdd-phase` non touché.
