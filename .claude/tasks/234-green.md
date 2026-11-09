# #234 (plafond de runs d'un BPC) — phase verte

## Changement
Seul fichier touché : `src/Service/Industry/IndustryProjectFactory.php`.
- `planStepsJobByJob()` relève les blueprints ayant une étape de copie dans le plan et passe `bpcMaxRunsOf()` à `jobsOf()` (chemin non fixé uniquement : les jobs aux runs fixés gardent leurs jobs, E14).
- `bpcMaxRunsOf()` : étape `manufacturing` dont le blueprint a une étape de copie (R1, aucune réaction) ; lit `blueprintRepository->findBy(['typeId' => …])` ; ligne absente ou `maxProductionLimit <= 0` : `\RuntimeException` nommant le blueprint (R7, aucun défaut).
- `maxRunsPerJob()` : minimum du plafond et des runs max par la durée, soit `max(jobs durée, ceil(N / plafond))` (R2) ; `null` si aucune contrainte.
- `jobsOf()` : même répartition `SplitGroupQuantities::runsBySplitIndex()` / `share()` (R3) ; moins de 2 jobs : étape intacte. Le besoin matériaux par job et l'aval ne changent pas (R4), création et régénération partagent le chemin (R5).

## Résultats
- `IndustryProjectFactoryBpcMaxRunsTest` : 22 OK (16 rouges passés, 6 garde-fous verts).
- Suite Unit entière : 3477 tests OK (260 notices PHPUnit existantes, 1 incomplet existant).
- Intégration `IndustryProjectFactoryRegenerationTest` + `UpdateStepFixedRunsTest` : 48 OK.
- PHPStan sur `IndustryProjectFactory.php` : aucune erreur.

## Notes
- Type d'exception R7 (question 1 du rouge) : `\RuntimeException` (donnée SDE manquante), à confirmer.
- Non fait : glossaire (entrée « Plafond de runs d'un BPC » prévue par la spec), note `.claude/tasks/todo.md` sur l'écart Group Industry, `docs/modules/industry.md` : à traiter à part.
- Pas de commit, `.claude/state/tdd-phase` intact. La batterie complète (deptrac, Infection) est pour `/review`.
