# #1246 câblage : passe unique notée de rattachement des jobs

## 1. Domaine pur `App\Industry\Domain\JobMatching` (`src/Industry/Domain/JobMatching/`, couche IndustryDomain, aucune dépendance)
- `MatchSignal` enum string : `Intent='intent'`, `Place='place'`, `Structure='structure'`, `ExactFit='exact_fit'` ; `weight(): int` 8/4/2/1. L'ordre des cas = ordre de stockage.
- `CandidateProject` (final readonly) : `string $id` (rfc4122), `\DateTimeImmutable $createdAt`, `?array $producerInstallerIds` (list<string> d'ids de Character, null = pas de choix), `?array $containerItemIds` (list<int>, null = périmètre inconnu).
- `CandidateLine` : `string $id` (rfc4122 de la ligne, UUID v7), `string $projectId`, `int $remainingRuns` (> 0), `?int $plannedStructureId` (null si absent ou non numérique), `array $reservingInstallerIds` (list<string>).
- `CandidateJob` : `int $jobId`, `int $runs`, `string $installerId`, `?int $blueprintLocationId`, `?int $outputLocationId`, `?int $stationId`.
- `EligiblePair` : `int $jobId`, `string $lineId`.
- `JobAssignment` : `int $jobId`, `string $lineId`, `array $signals` (list<MatchSignal>, ordre de l'enum).
- `JobMatchPlanner::plan(list<CandidateProject>, list<CandidateLine>, list<CandidateJob>, list<EligiblePair>): list<JobAssignment>` (statique comme `ProductionSchedule::earliest`), affectations dans l'ordre de la boucle gloutonne.
- Départage : projet (`createdAt`, puis `id` en chaîne), ligne (`id` en chaîne), job (`jobId`). Les ids sont des UUID v7 monotones : l'ordre des chaînes = ordre de création = `#[ORM\OrderBy(['id' => 'ASC'])]` de `IndustryProject::$bomItems`. C'est la clé stable, rien à ajouter sur `IndustryProjectBomLine`.
- Décision : les signaux sont calculés dans le domaine, à partir des faits bruts. `exact_fit` dépend du reste, qui change à chaque tour de la boucle, et la spec demande un test de domaine par signal. Le service filtre l'éligibilité statique (blueprint, personnages, date, sortie, déjà lié). Le domaine applique le reste (`runs <= remainingRuns`) et le filtre #1125, parce que #1125 croise les containers de tous les projets, y compris ceux dont aucune ligne n'a de reste.

## 2. Câblage service (`IndustryJobMatcher`)
- `matchOpenProjects(array $projects): array` : prend list<IndustryProject>, renvoie `array<string, list<LineMatchFailure>>` avec une clé par projet reçu (liste vide si aucun échec, donc pas de `??` chez l'appelant). Le statut des projets n'est pas regardé : les appelants filtrent.
- Étapes : release des liens sans production de chaque projet (variante privée sans flush), `candidatesOf` par projet (échecs `noRuns` et `producerMissingFromSde` journalisés), construction des entrées, `JobMatchPlanner::plan`, `createJobMatch(..., matchSignals)` par `JobAssignment`, un seul `flush`.
- Construction des entrées (privée, même classe) :
  - scope : `containerService->containerScopeOf($project)` une fois par projet → `containerItemIds` si `isKnown()`, sinon null ;
  - producteurs : si `getProducerCharacterIds() !== null`, ids des Character des utilisateurs du projet dont `getEveCharacterId()` y figure. Ne lire `eveCharacterId` que dans ce cas : les Character des tests n'en ont pas ;
  - réservations : nouvelle dépendance `IndustryProjectLineReservationRepository::findByProject($project)` → par ligne, ids des Character de `participant->getUser()` ;
  - jobs : `findManufacturingJobsByBlueprint` mémoïsé par (blueprint, personnages, createdAt), dédoublonnés par `jobId` ; un job indisponible (union des `findEsiJobIdsMatchedToOtherProjects` et des jobs liés aux lignes de chaque projet) n'entre pas ;
  - `plannedStructureId` : `ctype_digit` → int, sinon null.
- Entité : `IndustryProjectJobMatch::$matchSignals` (`?array`, list<string>), `setMatchSignals(?array)` et `getMatchSignals()` ; `createJobMatch` auto pose `array_map(fn ($s) => $s->value, $signals)` ; manuel = null.
- Supprimés : `matchEsiJobs`, `contestedJobIds`, `containerOwnersByLocationId`, `takesItsInputFromAnotherProjectContainer`, `jobsFittingLines`. `releaseMatchesWithoutOutput` public n'a plus d'appelant hors de la classe : à rendre privé.
- `OpenProjectsJobMatcher::matchOpenProjectsOf` : brouillons inchangés (release puis refresh). Pour les lancés (s'il y en a) : un seul `matchOpenProjects($launched)` dans un try/catch (warning `Failed to match jobs of projects` avec `projectIds`), puis pour chaque lancé `process` (recordJobsOf, refreshLine), isolé par projet.
- `MatchJobsProcessor` : `findOpenOfAcceptedParticipant` sans les brouillons, plus le projet cible s'il manque (même brouillon) ; plus d'appel à `releaseMatchesWithoutOutput` ; `$lineFailures = $failures[$project->getId()->toRfc4122()]`.

## 3. Migration et API
- `migrations/Version20261122000000.php` (la dernière est `Version20261120000000`) : `ALTER TABLE industry_project_job_matches ADD match_signals JSON DEFAULT NULL`, down `DROP COLUMN match_signals`.
- `ProjectJobMatchResource` : `/** @var list<string>|null */ public ?array $matchSignals = null;`, rempli dans `fromEntity`. Il ressort aussi dans le BOM (`ProjectBomProvider` via `get_object_vars`), `LinkJobProcessor` et `JobMatchDeleteProvider`.

## 4. Harnais
- Domaine : `tests/Unit/Industry/Domain/JobMatching/JobMatchPlannerTest.php`, objets construits à la main, sans base ni mock. Cas : chaque signal isolé (le producteur et la réservation passent tous deux par `Intent`), départage, inconnu neutre, exact fit, permutation des entrées, plusieurs jobs.
- `IndustryJobMatcherTest` : mocks existants (`findManufacturingJobsByBlueprint`, `findEsiJobIdsMatchedToOtherProjects`, `producerOf`, `containerScopeOf` via `scopesByProjectId`, EM en stub). On ajoute un 8e argument de constructeur, un stub de `IndustryProjectLineReservationRepository` avec un callback `reservationsByProject` (vide par défaut). `matchAll()` devient `matchOpenProjects($projects)`.
- `IndustryJobMatcherFailedLinesTest` : même 8e argument (stub qui renvoie []), appel `matchOpenProjects([$project])[PROJECT_ID]`.
- `OpenProjectsJobMatcherTest` et `MatchJobsProcessorTest` : stub `IndustryJobMatcher`, avec le callback `matchOpenProjects` à la place de `containerOwnersByLocationId`, `contestedJobIds` et `matchEsiJobs`. Un `method()` sur une méthode disparue lève une exception, donc tout le fichier passe au rouge tant que setUp et `matchJobs()` ne sont pas adaptés.

## 5. Tests existants
- Supprimés (fonction retirée) : `IndustryJobMatcherTest::testAContestedJobIsSkippedAndTheOtherJobsOfTheLineAreStillMatched`, `testContainerOwnersListTheProjectsOwningEachLocation` ; `OpenProjectsJobMatcherTest::testTheContestedJobsAreComputedOnce…`, `testTheContainerOwnersAreComputedOnce…`, `testAProjectWhoseReleaseFailedIsLeftOutOfTheContestedJobs` ; `MatchJobsProcessorTest::testTheJobsContestedByTheOpenProjects…`, `testTheContainerOwnersOfTheOpenProjects…`, `testTheMatchesWithoutOutputOfEveryOpenProjectAreReleasedBefore…`.
- Résultat inversé (départage au lieu d'un job contesté) : `testAJobFittingTheLinesOfTwoCandidateProjectsIsContested` (va au premier projet), `testContestationIsRepeatedOnceAContestedJobIsSetAside` (6 → B, 4 → A), `testAJobTakingItsInputFromASharedContainerIsContested…` (premier projet, `place`), `testTheOutputLocationAloneNeverDecidesWhichProjectGetsTheJob` (la sortie donne désormais `place` → second projet).
- Ordre des liens changé : `testFinalLineIsMatchedToTheJobsStartedSinceTheProjectCreationThatFitItsRuns` attendait `[7_362_003, 7_362_002]` et obtient `[7_362_002, 7_362_003]` (le plus petit jobId d'abord, puis exact fit).
- Sémantique changée côté OpenProjects : `testEveryOpenProjectOfTheUserIsReleasedThenMatched…`, `testADraftIsReleasedBefore…`, `testEveryOpenStatusOtherThanDraft…`, `testADraftThatFailsToBeReleased…`, `testLaunchedProjectsAreOnlyReleased…` (plus d'étape `release-ended`, un seul `match` pour tous), `testAProjectThatFailsToMatchIsLogged…` et `testAProjectWhoseMatchingFailsIsNotRecorded…` (la passe échoue en bloc, puis record et refresh continuent).
- Côté processeur : `testTheProjectAlreadyAmongTheOpenProjects…` (vérifie la liste passée à `matchOpenProjects`), `testListsTheLinesTheMatcherCouldNotMatch` (échecs indexés par projet ; ajouter qu'un échec d'un autre projet est tu).
- Seul l'appel est renommé (`matchEsiJobs($p)` → `matchOpenProjects([$p])`, assertions intactes) : tous les autres tests de `IndustryJobMatcherTest` et de `IndustryJobMatcherFailedLinesTest`, les autres tests d'`OpenProjectsJobMatcherTest` (151, 159, 129) et le reste de `MatchJobsProcessorTest` (seul le helper change).

## Questions ouvertes
- Échec de release d'un projet dans la passe : soit on l'exclut de la passe et on journalise (comme aujourd'hui, recommandé), soit toute la passe échoue.
- Processeur : faut-il garder les brouillons autres que la cible hors de la passe ? Proposé oui, pour qu'un brouillon ne prenne aucun job.
