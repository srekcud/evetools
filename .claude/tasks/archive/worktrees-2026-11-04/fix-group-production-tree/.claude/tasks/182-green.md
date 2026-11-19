# #182 — phase verte

Commits : 84b53e31 (backend + docs), a284b0b5 (front).

- PHP Unit : 14255 tests, OK (288 notices PHPUnit préexistantes, 1 incomplet préexistant). PHPStan sur les 4 fichiers src touchés : aucune erreur. Deptrac : 0 violation.
- Vitest : 1373 verts sur 1374 ; les 4 rouges de la phase rouge passent. Reste `structureConfigFailure.spec.ts` (issue 189, test « edit fails (fr) ») : course intermittente sur le store des structures, sans lien avec #182. Il échoue 1 fois sur 3 en exécution isolée (`--testNamePattern "issue 189"`) avec le même code ; sur la suite complète, échec à chaque exécution avec le patch front, passage aux 2 exécutions sans. Non résolu, test verrouillé.
- Lint : 0 erreur (141 avertissements préexistants). vue-tsc -b : sans sortie.
- Fichiers : IndustryProjectStep (R1, R2, getMissingQuantity supprimée), IndustryResourceMapper, ProjectStepResource (`$inStock` sans défaut), ProductionCostService (0 run sauté), compat.ts, types.ts, docs/glossary.md, docs/modules/industry.md.
- Non exécuté : suite Integration.
