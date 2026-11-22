# #1193 : câblage phase rouge (copie et invention rapprochées)

## 1. Production à modifier
- `src/Entity/IndustryProjectBomLine.php:344` : ajouter `ACTIVITY_COPY`, `ACTIVITY_INVENTION`, la table unique `ESI_JOB_ACTIVITY_IDS_BY_ACTIVITY_TYPE` (`manufacturing`→[1], `reaction`→[9, 11], `copy`→[5], `invention`→[8]), le prédicat `isMatchableJob()` (isJob et clé de la table, terme « job lié » du glossaire) et `esiJobActivityIds(): list<int>` (LogicException hors table). `isManufacturingOrReactionJob()` inchangé.
- `CachedIndustryJobRepository:166` : `findManufacturingJobsByBlueprint` devient `findJobsByBlueprintAndActivities(int $blueprintTypeId, list<int> $activityIds, array $characterIds, ?int $targetRuns = null, ?\DateTimeImmutable $startedAfter = null)`, `j.activityId IN (:activityIds)` au lieu de `IN (1, 9, 11)` l.174. `:206` `findByBlueprintsAndCharacters(array $blueprintTypeIds, list<int> $activityIds, array $characterIds, ?\DateTimeImmutable $startedAfter = null)`, même remplacement l.213.
- `IndustryJobMatcher` : supprimer la table privée l.39-42 (lue sur l'entité) ; nouvelle méthode publique `expectedBlueprintTypeIdOf(IndustryProjectBomLine): int` : `producerOf(typeId)` pour fabrication, réaction et copie ; pour l'invention, `findBlueprintForProduct(typeId, 1)` puis `findBlueprintForProduct(t2Bp, 8)` (mêmes requêtes que `InventionService::getInventionData`, sans charger les matières), exception dédiée si l'un manque. `candidatesOf` l.368 l'appelle et convertit l'exception en `LineMatchFailure::inventionDataMissing` (nouveau cas `LineMatchFailureReason::InventionDataMissing`). **Clé de requête l.123 : ajouter les activités de la ligne**, sinon copie et invention du même BPO partagent le résultat en cache (exemple 7). l.124 passe `$line->esiJobActivityIds()`. l.175, l.386, l.434 : `isMatchableJob()`.
- `AvailableJobsProvider` l.62 `isMatchableJob()` ; l.68 `jobMatcher->expectedBlueprintTypeIdOf($line)` (ligne d'invention sans donnée : ignorée, voir question 2) ; l.84 passe l'union des `esiJobActivityIds()` des lignes.
- `ManualJobLinker:33` : comparer à `jobMatcher->expectedBlueprintTypeIdOf($line)` ; dépendance `productRepository` devenue inutile (à retirer en vert ou garder, au choix du refactor).
- `UnassignedJobCandidates:31`, `MatchJobsProcessor:148`, `OpenProjectsJobMatcher:64` : `isMatchableJob()`.
- Front : `jobLinking.ts:3` ajoute `copy`, `invention` ; `types.ts:154` et `fr.json`/`en.json` (l.3644) gagnent `invention_data_missing`. Glossaire « Job lié » l.162.

## 2. Appelants de `isManufacturingOrReactionJob()`
| Appelant | Sort | Raison |
|---|---|---|
| `IndustryJobMatcher:175, 386, 434` | migre | rapprochement, jobs indisponibles, lignes recalculables |
| `AvailableJobsProvider:62` | migre | liste manuelle |
| `UnassignedJobCandidates:31` | migre | candidats à attribuer (règle 5) |
| `MatchJobsProcessor:148`, `OpenProjectsJobMatcher:64` | migre | refresh des contributions dérivées (règle 8) |
| `IndustryProjectBomLine:367` `getQuantityOfRuns` | reste | quantité produite |
| `IndustryProjectService:633` | reste | stock consommé ajouté à la plus grosse ligne de fabrication |
| `ProjectBomProvider:114` | reste | nom du blueprint de fabrication/réaction |

## 3. Lecteurs de `jobMatches` avec un lien sur copie/invention
- Sans casse : `ProjectChainBuilder:383-428` (statut par runs livrés, générique ; `done` si delivered ≥ runs), `ProjectCockpitInputFactory:69-95` + `ProjectCockpitCalculator:120,137,320` (matchedRuns/deliveredRuns, `blueprint` rang 0 l.54), `ProjectChainProvider:89` et `ProjectJobLocationResolver`, `ProjectPlanningInputLoader:118` (copie déjà dans les lignes planifiées l.58, ses jobs liés deviennent des `PlanningLinkedJob` l.201 ; invention exclue l.58 et passée en `PlanningLeftOutLine` l.101, liens ignorés), `ProjectJobGroup:26`, `ProjectJobActivityRecorder:26-65` (libellé neutre « Jobs démarrés »), `OwnProjectJobsLoader:26` (puis `SkillGainEstimator:100,139` lit l'activité ESI : invention enfin comptée, voulu), `CompletedProjectFiguresLoader:81` (heures de slot science désormais incluses : correct), `StartedJobConsumptionReader` (repo `:48` limite à `component`/`final` : pas de consommation de matières).
- Signalés : `LineRentalSuggester:27` prend la catégorie de barème du `typeId` de la ligne (produit fabriqué) : une location sur ligne de copie serait valorisée au barème fabrication pour des jours de slot science (question 3). `ProjectWorkStations:52-60` : un job lié sur ligne `blueprint` verrouille désormais la station `WorkType::Blueprints` (`JobLaunched`), cohérent mais nouveau.

## 4. Argent : `refreshLine` sur ligne `blueprint`
- `DerivedContributionService:60-67` crée une `JobInstall` auto-approuvée (l.269-280), comptée par `ProjectDistributionService:191` puis `ProjectSettlementCalculator:37`. `shiftFulfillment` l.313 ne touche que `Material` : pas d'effet sur `fulfilledQuantity`.
- **Double comptage réel** : sur une ligne copie/invention, le front ne propose que le type `bpc` (`ContributeModal.vue:32,89`). L'installateur qui a déclaré son BPC (coût de copie inclus) garde sa `Bpc` et reçoit en plus la `JobInstall` dérivée : la dédup ne compare que le même type (`rejectPendingDeclared` l.174, `hasApprovedDeclared` l.198, `IndustryProjectContribution::duplicatesDerived` l.171-183). Au déploiement, `OpenProjectsJobMatcher` rapproche rétroactivement les copies des projets ouverts : doublon immédiat en prod. Question 1.
- Achats : sans effet (`isPurchasable()` faux sur `blueprint`, achats filtrés par `typeId` l.327).

## 5. Harnais et tests qui cassent
- `IndustryJobMatcherTest` : stub l.102 renommé, filtre en plus sur `$activityIds` (2e argument), `jobQueries` garde le tuple [bp, chars, runs, date] (assertion l.226-230 inchangée) ; `producerOf` l.127 et nouveau stub `findBlueprintForProduct` (T2 Ishtar → BPO T2 → BPO T1 par activité 8). `job(..., activityId: 5|8)` l.1609 existe. **Casse voulue** : `testMaterialAndInventionLinesAreNotMatched` l.333 (sa ligne d'invention porte le `typeId` du blueprint : à réécrire avec le produit T2).
- `IndustryJobMatcherFailedLinesTest:59` : stub renommé, pas d'assertion changée.
- `CachedIndustryJobRepositoryTest:276` (intégration) : appel à la nouvelle signature, plus un cas activité 5/8.
- `AvailableJobsProviderTest:250,271` : stub `findByBlueprintsAndCharacters` (nouvel argument), `IndustryJobMatcher` stubé : **doit stuber `expectedBlueprintTypeIdOf`** (sinon 0) ; `isJobOfBomLineActivity` stub reste.
- `BuildsManualJobLinker` : matcher réel construit, constructeur inchangé ; ajouter `findBlueprintForProduct` au stub produit. `ManualJobLinkerTest:60` reste vert (job 8 sur ligne fabrication).
- Front : `jobLinkOfferedWhereItCanSucceed.spec.ts:117` renversé ([true, true, true, true]), raison au journal (#1199 → #1193).
- `InventionService` non injecté : aucun test de constructeur ne casse.

## 6. Tests exhaustifs voisins
`IndustryProjectBomLineTest` (nouveau prédicat sur les 4 activités, `material`, activité inconnue) ; tests de `LineMatchFailureReason`/i18n (`groupIndustrySourcingKeys.spec.ts` si la clé y est énumérée) ; `OpenProjectsJobMatcherTest:69` et `MatchJobsProcessorTest:664` (refresh appelé sur copie/invention) ; `UnassignedJob*`/`AssignUnassignedJobProcessorTest` (ligne copie assignable) ; `ProjectChainBuilderTest` et cockpit (exemple 9).

## Questions ouvertes
1. Doublon `Bpc` déclaré / `JobInstall` dérivée (bloquant argent) : étendre la dédup au couple `Bpc`↔`JobInstall` du même installateur sur ligne `blueprint` (recommandé), ou dériver le type `Bpc` sur ces lignes.
2. Ligne d'invention sans donnée dans `AvailableJobsProvider` (ignorer, recommandé) et `ManualJobLinker` (400 « Job blueprint does not match BOM line blueprint » ?).
3. `LineRentalSuggester` sur ligne copie/invention : catégorie inconnue (pas de suggestion) ou hors périmètre.
