# #131 — phase verte (jobs en trop d'un groupe découpé)

## État

- PHP : `--testsuite Unit` complet vert (13982 tests, 24487 assertions ; notices PHPUnit préexistantes). Les 20 tests rouges passent. Les chemins `tests/…` sont refusés par le hook en phase verte, la suite visée a donc tourné par `--testsuite Unit` et `--filter`.
- Intégration : non exécutée (stack arrêtée). P1 à P4 et le garde-fou R14 relus et vérifiés à la main sur le code (voir plus bas).
- Vitest : 180 fichiers, 1305 tests verts (dont les 4 rouges de `stepsSurplusJobs.spec.ts`). `vue-tsc --noEmit -p tsconfig.json` et `eslint` sans erreur.
- PHPStan sur les fichiers touchés : aucune erreur. Deptrac : 0 violation.

## Fichiers touchés

- `src/Service/Industry/SplitGroupQuantities.php` : `applyNeedToSteps` choisit (R2, R3 par `IndustryProjectStep::isCommitted`), absorbe (R4), renumérote (R5), dissout (R6, R7), corrige la répartition (R8, `withoutExcessRuns`). Tri par `splitIndex` factorisé dans `stepIndexesInSplitIndexOrder`.
- `src/Entity/IndustryProjectStep.php` : `isCommitted()`. `src/Entity/IndustryProject.php` : `removeStep()`.
- `src/Service/Industry/IndustryStepCalculator.php` : le rappel de la marche rend les jobs en trop (retirés du projet) ; la marche met à jour sa liste (R11) ; `needRunsOf` ne supprime rien.
- `src/Service/Industry/SplitGroupMaterialNeeds.php`, `IndustryProjectFactory.php` (`jobsKeepingFixedRuns` : retrait avant l'appariement, seuls les jobs gardés sont planifiés) : retrait des jobs en trop.
- `src/State/Processor/Industry/UpdateStepProcessor.php` : la réponse n'inclut l'étape éditée que si elle est encore dans le projet (R15). `releaseFixedRunsOfTheGroup` inchangé : il répartit `runsBySplitIndex` sur tous les jobs, puis la cascade supprime les jobs en trop.
- `frontend/src/stores/industry/steps.ts` : `updateStep` recharge le projet (R16) ; il rend l'étape éditée si elle est dans la réponse, sinon `undefined`. `StepsTab.vue` inchangé : `updateStepRuns` lisait déjà l'étape dans le store après l'appel.
- `docs/glossary.md`, `docs/modules/industry.md` : termes et carte du module.

## Écarts et points non vérifiés

- R13 : pas de répartition « uniquement sur les jobs gardés » dans le processeur. Quand un job engagé est gardé à la place d'un job de plus petit `splitIndex`, la cascade (prorata + R8) redonne une répartition égale dans les cas relus (3 jobs/2 runs, 4 jobs/3 runs) ; non démontré pour tous les cas.
- Absorption (`absorb`) puis `orphanRemoval` des jobs matchés et achats déplacés : même mécanisme que la régénération existante, mais P4 n'a pas tourné.
- L'aperçu de régénération retire les jobs en trop du projet en mémoire (comme il modifie déjà les jobs fixés) : le `clear()` de l'appelant les remet.
- `StepsTab.vue` : aucun changement, aucun test de composant (cf. 131-red.md).
- Groupe `issue-131` : non repris sur les tests, décision laissée à Sylvain.
- Commandes à rappeler après déploiement : aucune migration ; recalculs de projets existants au prochain PATCH ou à la régénération (voir « Impact prod » de la spec).
