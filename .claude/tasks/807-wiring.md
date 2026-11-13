# #807 : note de câblage (marque d'étape de la chaîne)

Spec : `docs/specs/807-chain-step-mark.md`. Modèle le plus proche : la réservation de ligne (`ProjectLineReservationResource`, POST et DELETE sur `/bom/{lineId}/reservation`, `LineReservationProvider`, `ReleaseLineProcessor`, `ProjectLineReservationApiTest`) ; accès propriétaire comme `LinkJobProcessor`.

## 1. Backend

| Pièce | Cible | À imiter / dépendances | Harnais |
|---|---|---|---|
| Enum | `src/Enum/BomLineMarkState.php` (`Launched = 'launched'`, `Done = 'done'`) | `ContributionStatus` | direct |
| Entité | `src/Entity/IndustryProjectBomLineMark.php`, table `industry_project_bom_line_marks` : uuid v7, `line` OneToOne **propriétaire** (`inversedBy: 'mark'`, JoinColumn `nullable: false, onDelete: CASCADE`, unicité implicite), `state` (`enumType: BomLineMarkState::class`, string 20, comme `MiningSite::$status`), `runs` int, `markedAt` datetime_immutable ; `replace(state, runs, markedAt)` | `IndustryProjectDeclaredBlueprintCopy` ; pas de repository (lecture par la ligne) | `new` en mémoire |
| Relation | `IndustryProjectBomLine::$mark` OneToOne inverse `mappedBy: 'line', cascade: ['persist','remove'], orphanRemoval: true, fetch: 'EAGER'` ; `getMark()`, `setMark(?Mark)`, `getCountedRuns()` = max(`getMatchedRuns()`, min(runs marqués, `getRuns()`)) | côté inverse non proxifiable : sans EAGER une requête par ligne hydratée | `IndustryProjectBomLineTest` (`tests/Unit/Entity/`) |
| Migration | `migrations/Version20261113140000.php` (la PR #806 prend `Version20261113130000`) : CREATE TABLE, UNIQ sur `line_id`, FK `ON DELETE CASCADE NOT DEFERRABLE`, `down` | `Version20261113100000` ; noms d'index générés par `doctrine:migrations:diff` | `MigrationsReplayTest` compare au mapping |
| Ressource | `src/ApiResource/Industry/Project/ProjectBomLineMarkResource.php`, shortName `ProjectBomLineMark`, `id` (= id de ligne), `lineId`, `state`, `runs`, `markedAt` ; `fromLine(IndustryProjectBomLine)` ; `Post` (`status: 200`, `input: MarkBomLineInput::class`) et `Delete` (`provider`) sur `/industry/projects/{projectId}/bom/{lineId}/mark`, `uriVariables` = Link `IndustryProjectResource` + `ProjectBomLineResource` | `ProjectLineReservationResource` (même gabarit à 2 variables) | fonctionnel |
| Input | `src/ApiResource/Input/Industry/Project/MarkBomLineInput.php` : `?string $state`, `?int $runs`, **sans Assert de bornes** | `ReserveLineInput` : ici un Assert donne 422 (`ProjectT2BlueprintCopyApiTest` l.195), la spec veut 400 | — |
| POST | `src/State/Processor/Industry/Project/MarkBomLineProcessor.php` : user, projet (404), `assertOwner`, ligne du projet (404), `isCompleted()` 400, `!isJob()` 400, `BomLineMarkState::tryFrom` null 400, runs < 1 ou > `getRuns()` 400 ; marque existante → `replace` en place (un remove + insert sur la même ligne viole l'UNIQ : Doctrine insère avant de supprimer), sinon `setMark(new …)` ; `flush` ; publication | `LinkJobProcessor` l.47-63 ; `Security`, `IndustryProjectRepository`, `IndustryProjectBomLineRepository`, `ProjectAccessChecker`, `EntityManagerInterface`, `MercurePublisherService` | unitaire : trait `TracksJobsAndPurchasesOnIshtarBom` (`tests/Unit/State/Processor/Industry/`, stubs `securityOf`, `projectRepository`, `bomLineRepository`, `accessChecker`, `lineUriVariables`) |
| DELETE | `src/State/Provider/Industry/Project/BomLineMarkProvider.php` (mêmes 404/403, 404 « No mark on this line ») + `src/State/Processor/Industry/Project/RemoveBomLineMarkProcessor.php` (400 si `Completed`, `setMark(null)`, `flush`, publication) | `LineReservationProvider` + `ReleaseLineProcessor` | idem |
| deptrac | `deptrac.yaml` l.176+ : les deux processors → `App\State\Provider\Industry\Project\ProjectAccessChecker` | entrée `DeclareT2BlueprintCopiesProcessor` | `make deptrac` |
| Mercure | `syncCompleted($userId, 'industry-project', null, ['projectId' => …, 'lineId' => …])` pour chaque `$project->getMembers()` où `getStatus() === ParticipantStatus::Accepted` → `getUser()->getId()->toRfc4122()` (le propriétaire a son participant accepté : `DerivedContributionService::ownerParticipantOf`) ; méthode privée partagée ou petit service `ProjectMarkNotifier` | injection directe de `MercurePublisherService` (`ReleaseLineProcessor`) | `final readonly` : jamais mocké, `new MercurePublisherService($hubStub, new NullLogger())` (unitaire, `TriggerSyncProcessorMarketAlertsTest` l.52-61 ; fonctionnel, `ProjectLineReservationApiTest` l.26-36 par `getContainer()->set`) |
| Chaîne | `ProjectChainNode` : dernier paramètre `public ?array $mark = null` (`array{state: string, runs: int}|null`) ; `ProjectChainBuilder::nodeOf` le remplit pour `isJob` ; `computedJobStatusOf` l.357 : `deliveredRuns = max(livrés, runs marque done)`, marque `launched` → `hasJobInProgress` ; la boucle des liens (et son `return unknown`) reste en tête | — | `ProjectChainBuilderTest` (930 l., trait `BuildsChainBomLines` : `jobLine`, `withLineId`) |
| Ressource chaîne | rien à mapper : `ProjectChainProvider` sérialise les `ProjectChainNode` ; `withJobs` et `withPlannedCopyDurationSeenBy` passent par `clone`, le champ suit ; `skip_null_values: false` sort `mark: null` | — | `ProjectChainApiTest` |
| Cockpit | `ProjectCockpitInputFactory::toCockpitBomLine` l.46 : `getCountedRuns()` | — | `ProjectCockpitInputFactoryTest` |
| Recalcul | `IndustryProjectService::assertNoWorkedLineIsDropped` l.245 : `|| null !== $droppedLine->getMark()`, message inchangé ; `assertNoLineIsCutBelowItsProgress` l.260 : nouveau refus si runs marqués > runs planifiés, après le contrôle des runs liés ; `updateLineInPlace` ne touche pas la marque | — | `IndustryProjectServiceBlacklistSwitchTest` (l.75-89, patron du refus) |

## 2. Front

- Types (`stores/group-industry/types.ts` l.674) : `ChainStepMarkState = 'launched' | 'done'`, `ChainStepMark = { state; runs }`, `ChainNode.mark: ChainStepMark | null`.
- Store : étendre `stores/group-industry/chain.ts` : `'mark'` dans `NULLABLE_NODE_FIELDS`, `markStep(projectId, lineId, state, runs)` (POST JSON), `unmarkStep(projectId, lineId)` (DELETE), `markError` (message de `ApiError`), puis `fetchChain` ; modèle `changeReservation` de `companion.ts` l.94.
- Composant : `components/group-industry/ChainStepMark.vue` (props `node`, `canMark` ; badge pour tous, contrôle HUD si `canMark`), monté dans la cellule `status` de `ProductionChainJobTree.vue` (l.206, la seule vue qui rend chaque nœud de job ; `ProductionChainFlowNode` reste une carte de vue d'ensemble). Pas de nouvelle colonne (`COLUMN_KEYS` inchangé).
- Fil : `ProductionChain.vue` prop optionnelle `canMark?: boolean` + emit `marked` → `ProductionChainJobTree` (prop `canMark`, emit) ; `GroupIndustryDetail.vue` l.722 : `:can-mark="isOwner && !isCompleted"` (`isOwner` l.131, `myRole === 'owner'`), `@marked` → `refreshGroupProject.cockpit(projectId)` (le cockpit est une copie locale, l.161-166 : appeler le store seul ne suffit pas).
- Mercure déjà branché : `ProductionChain.vue` l.48 et `GroupIndustryDetail.vue` l.212 rechargent chaîne, BOM et cockpit sur `industry-project` du projet ; le rechargement local reste requis (spec R11).
- i18n : `groupIndustry.chain.mark.*` dans `fr.json` et `en.json` (bloc `chain` l.2718 en EN).

## 3. Tests existants touchés

- Aucun test ne construit `ProjectChainNode` ; `new CockpitBomLine` : `ProjectCockpitCalculatorTest` (2), `ProjectCockpitInputFactoryTest` (2), signature inchangée.
- `ProjectChainApiTest` l.51 et l.55 : listes de champs nuls (matériau, vente), ajouter `mark` est un ajout, pas une casse.
- Front : `ChainNode` complet construit à un seul endroit, `tests/productionChainNodes.ts` (`chainNode`, l.3) → `mark: null` ; `productionChain.spec.ts` l.120 (`OMITTED_NULL_FIELDS`) et `groupChain.spec.ts` l.56-63 à compléter ; les 6 specs qui montent `ProductionChain` restent vertes si `canMark` est optionnel.
- À relancer (EAGER ajoute une jointure) : tests à compte de requêtes `ProjectContributionCollectionProviderTest`, `ProjectCollectionProviderTest` (`tests/Integration/State/Provider/Industry/Project/`).
- Issue de bout en bout : unitaire `ProjectChainBuilderTest` (composant `done` 10 → parent `blocked` devient `todo` ; invention `blueprint` marquée → `done` ; marque 10 + lien livré 6 → `done`, puis cockpit reste 0 via `IndustryProjectBomLine::getCountedRuns`) ; fonctionnel `ProjectBomLineMarkApiTest` avec `BuildsIshtarGroupProject` : POST `done` 3 sur `ramJobLine` → GET chain : RAM `done` + `mark`, Ishtar quitte `blocked` (devient `unknown` : besoins matériaux non calculables dans ce fixture, l.32-33).

## 4. Squelettes avant le rouge

Enum ; entité + relation (`getMark` null, `getCountedRuns` = `getMatchedRuns`) ; migration ; input ; ressource ; provider et processors qui lèvent `\LogicException('not implemented')` ; `ProjectChainNode::$mark = null` ; deptrac ; front : type `mark`, `markStep`/`unmarkStep` vides, `ChainStepMark.vue` vide monté, props `canMark` passées.

## 5. Points d'attention

- PR #806 (#845) touche `ProductionChain.vue` (props, watchers), `GroupIndustryDetail.vue`, `types.ts`, `fr/en.json`, `IndustryProjectService.php`, docs : rebaser après sa fusion.
- Copie et invention : `LinkJobProcessor` les refuse, la marque les admet (stage `blueprint`).
- `IndustryJobMatcher` inchangé : il ignore la marque pour le reste à apparier (R7).

## Questions ouvertes

- Q1 : `mark.runs` exposé = runs stockés ou plafonnés aux runs de la ligne ? Proposé : plafonnés (même valeur que le compte).
- Q2 : message du refus de coupe sous les runs marqués : nouveau texte proposé « has %d runs marked, more than the %d the plan now needs ».
