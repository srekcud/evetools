# Spec #283 — Extraire la durée par run d'une étape de `IndustryCalculationService`

**Statut : rédigée en mode nuit le 2026-10-29, mise à jour le 2026-10-30 après la fusion de #282, à valider par Sylvain.** Refactor sans changement de comportement : aucune durée, aucun personnage recommandé, aucune réponse d'API ne change. État vérifié sur `fix/group-production-tree` = `main` au commit `075465d3` (#277 et #282 comprises, #275 non implémentée).

## Contexte

`src/Service/Industry/IndustryCalculationService.php` fait **473 lignes** pour **9 dépendances**. Il mélange trois sujets : quantité matériau et noms, bonus de structure (assignée, système favori, meilleure), et **durée par run** d'une étape avec ses compétences.

Le bloc durée occupe les lignes **252 à 449** (198 lignes, de la docblock de `calculateTimePerRun` à la fin de `baseTimePerRunOf`), plus deux caches (l. 25-29) et trois dépendances (l. 36, 39, 40) qu'il est seul à utiliser :

| Dépendance de `IndustryCalculationService` | Utilisée par |
|---|---|
| `EntityManagerInterface $entityManager` | `getBlueprintScienceSkillIds` seul (SQL brut `sde_industry_activity_skills`) |
| `IndustryActivityRepository $activityRepository` | `baseTimePerRunOf` seul (temps de base) |
| `CachedCharacterSkillRepository $skillRepository` | `loadCharacterSkillLevels` seul |

Le bloc durée n'a qu'**un seul appel vers le reste du service** : `timePerRunOf` lit `$this->structureBonusOf(...)['timeBonus']` (l. 294). Le reste du service n'appelle jamais le bloc durée.

### Ce que #282 a changé dans le bloc

- `loadCharacterSkillLevels` (l. 308-321) rend `skills: null` pour un personnage sans aucune ligne en cache (compétences inconnues), `array<skillId, level>` sinon.
- `bestCharacterForStepTime` (l. 327-356) ignore les personnages aux compétences inconnues et rend une troisième clé `skillsUnknown` (vraie seulement si l'utilisateur a des personnages et qu'aucun n'est connu). Copie : `['skills' => null, 'name' => null, 'skillsUnknown' => false]`.
- La règle « `skillsUnknown` donne une durée par run `null` » est **écrite deux fois**, dans les appelants :
  - `IndustryProjectFactory::addTimeDataToSteps` l. 458 : `$step['timePerRun'] = $bestCharacter['skillsUnknown'] ? null : $this->calculationService->timePerRunOf(...)` ;
  - `IndustryResourceMapper::stepToResource` l. 248 : `$resource->timePerRun = $bestCharacter['skillsUnknown'] ? null : $this->calculationService->calculateTimePerRun($step, $bestCharacter['skills'])`.

  La relecture DDD de #282 recommande de replier cette règle dans le nouveau service pour qu'elle n'existe qu'une fois (décision R7).

### Appelants

Appelants dans `src/` (grep `timePerRunOf|calculateTimePerRun|bestCharacterForStepTime|loadCharacterSkillLevels|calculateSkillTimeMultiplier|getBlueprintScienceSkillIds|baseTimePerRunOf`) : **deux seulement**.

| Appelant | Appels aujourd'hui | Garde aussi `IndustryCalculationService` pour |
|---|---|---|
| `IndustryProjectFactory::addTimeDataToSteps` (l. 451-469 ; appels l. 453, 456, 458) | `loadCharacterSkillLevels`, `bestCharacterForStepTime`, `timePerRunOf` | `structureBonusOf` (l. 572), `SplitGroupMaterials::materialsOfJobs($this->calculationService, …)` (l. 578) |
| `IndustryResourceMapper::stepToResource` (l. 247-249) | `bestCharacterForStepTime`, `calculateTimePerRun`, `recommendedCharacterName` = `name` | noms, `getStructureBonusForStep`, `getBestStructureBonusForProduct`, `resolveFacilityName`, `getProjectDisplayName` |

`SlotTrackerService` (qui a sa propre clé `skillsUnknown`, #218), `ProductionCostService`, `IndustryJobMatcher`, `IndustryStepCalculator` et les providers n'appellent aucune méthode du bloc durée. Aucun test ne configure une méthode du bloc durée sur un double (`createStub`/`createMock`) : le seul test qui les appelle directement est `IndustryCalculationTest`.

## Règles

**R1 — Comportement inchangé.** Mêmes valeurs de durée par run, même personnage recommandé, même `null` sans temps de base ou avec compétences inconnues (#282), mêmes lectures SDE (une lecture du temps de base et des sciences par blueprint et activité), une lecture des compétences par génération (factory), une par étape affichée (mapper, comme aujourd'hui). Aucun test existant ne change d'assertion ni de valeur attendue.

**R2 — Nom du service : `App\Service\Industry\TimePerRunCalculator`** (glossaire « Durée par run » = *Time per run*). Écartés : `StepDurationService` (« durée d'étape » absent du glossaire), `JobDuration` (pris par `Domain\JobDuration`, durée d'un job entier). Classe non `final` (doublée par `createStub` dans `SyncCharacterSkillsProcessorTest` et `IndustryResourceMapperTest`), non `readonly` (deux caches mutables), comme `IndustryCalculationService`. Reste dans `src/Service/Industry/` : il dépend de Doctrine et d'entités, il ne peut pas aller dans `src/Industry/Application/` (ADR-0009), et le harnais `AutowiresByType` ne construit récursivement que `App\Service\Industry\*`.

**R3 — Méthodes déplacées telles quelles** (nom, signature, visibilité, docblock, corps) :

| Méthode | Visibilité | Terme du glossaire |
|---|---|---|
| `calculateTimePerRun(IndustryProjectStep, ?array $characterSkills = null): ?int` | public | Durée par run |
| `timePerRunOf(User, int, int, string, int, ?IndustryStructureConfig, ?array): ?int` | public | Durée par run |
| `loadCharacterSkillLevels(User): list<array{name: string, skills: array<int, int>\|null}>` | public | Compétences inconnues (`skills` `null`) |
| `bestCharacterForStepTime(User, int, string, ?array = null): array{skills, name, skillsUnknown}` | public | Compétences inconnues (`skillsUnknown`) |
| `calculateSkillTimeMultiplier(array, string, array = []): float` | public | — |
| `getBlueprintScienceSkillIds(int, string): array` | public | — |
| `baseTimePerRunOf(int, string): ?int` | private | Temps de base |
| propriétés `$blueprintSkillCache`, `$baseTimeCache` | private | Temps de base (cache) |

Seule ligne réécrite dans les corps déplacés : `$this->structureBonusOf(` → `$this->calculationService->structureBonusOf(` dans `timePerRunOf`. `getBlueprintScienceSkillIds` et `calculateSkillTimeMultiplier` n'ont plus d'appelant hors du nouveau service, mais restent publiques dans ce lot.

**R4 — Dépendances du nouveau service** (constructeur de 4 paramètres, dans cet ordre) :

1. `IndustryCalculationService $calculationService` — bonus de temps de la structure (`structureBonusOf`), seul lien vers l'ancien service ; pas de cycle (l'ancien n'appelle pas le nouveau).
2. `IndustryActivityRepository $activityRepository` — temps de base.
3. `EntityManagerInterface $entityManager` — sciences du blueprint.
4. `CachedCharacterSkillRepository $skillRepository` — compétences des personnages.

**R5 — `IndustryCalculationService` après le déplacement** : 6 dépendances (`TypeNameResolver`, `IndustryBonusService`, `IndustryStructureConfigRepository`, `IndustryUserSettingsRepository`, `StaStationRepository`, `CachedStructureRepository`, dans cet ordre), imports retirés : `CachedCharacterSkill`, `IndustryActivityType`, `CachedCharacterSkillRepository`, `IndustryActivityRepository`, `EntityManagerInterface`. Environ **260 lignes** (473 − 199 du bloc et sa ligne vide − 6 des caches − 3 du constructeur − 5 imports) : **cible < 300, contrôlée par `wc -l`**.

**R6 — Appelants rebranchés** :

- `IndustryProjectFactory` : nouveau paramètre `TimePerRunCalculator $timePerRunCalculator` juste après `$calculationService` (9 → 10 dépendances). `$calculationService` reste (l. 572, 578).
- `IndustryResourceMapper` : nouveau paramètre `TimePerRunCalculator $timePerRunCalculator` juste après `$calculationService` (3 → 4 dépendances). `$calculationService` reste.

**R7 — Une seule méthode rend la durée par run et le personnage recommandé** (décision issue de la relecture DDD de #282). `TimePerRunCalculator` expose :

```php
/**
 * @param list<array{name: string, skills: array<int, int>|null}>|null $characterSkillLevels
 * @return array{timePerRun: int|null, recommendedCharacterName: string|null}
 */
public function timePerRunAndRecommendedCharacterOf(User $user, int $blueprintTypeId, int $productTypeId, string $activityType, int $teLevel, ?IndustryStructureConfig $structureConfig, ?array $characterSkillLevels = null): array
```

- Elle appelle `bestCharacterForStepTime($user, $blueprintTypeId, $activityType, $characterSkillLevels)` ; si `skillsUnknown` est vrai, `timePerRun` vaut `null` sans appeler `timePerRunOf` ; sinon `timePerRun` = `timePerRunOf(…, $bestCharacter['skills'])`. `recommendedCharacterName` = `$bestCharacter['name']` dans tous les cas. **C'est le seul endroit du code où `skillsUnknown` décide de la durée par run.**
- `IndustryProjectFactory::addTimeDataToSteps` garde la lecture unique des compétences (`$this->timePerRunCalculator->loadCharacterSkillLevels($user)` avant la boucle) et pose `$step['timePerRun'] = $this->timePerRunCalculator->timePerRunAndRecommendedCharacterOf(…, $characterSkillLevels)['timePerRun']`. Plus de ternaire.
- `IndustryResourceMapper::stepToResource` appelle la même méthode avec les champs de l'étape (`$step->getProject()->getUser()`, `getBlueprintTypeId()`, `getProductTypeId()`, `getActivityType()`, `getTeLevel()`, `getStructureConfig()`) sans `$characterSkillLevels` (lecture par étape, comme aujourd'hui) et pose `timePerRun` et `recommendedCharacterName` depuis le résultat. Plus de ternaire.
- Forme de retour : tableau typé, comme `bestCharacterForStepTime` et `structureBonusOf` du même module. Écarté : un objet valeur dédié (une classe de plus pour deux champs sans comportement).
- Après le lot, `calculateTimePerRun` n'a plus d'appelant dans `src/` (seulement les tests migrés, qui l'appellent 23 fois) ; `timePerRunOf` et `bestCharacterForStepTime` ne sont plus appelées que par la nouvelle méthode et les tests. Elles restent publiques dans ce lot (voir Hors périmètre).

**R8 — Nouveau terme du glossaire : « Personnage recommandé »** (*Recommended character*), aujourd'hui sans entrée alors que la nouvelle méthode et `ProjectStepResource::$recommendedCharacterName` le nomment : personnage de l'utilisateur aux compétences connues dont le multiplicateur de compétence est le plus faible pour l'activité de l'étape, le premier dans l'ordre des personnages à égalité ; aucun pour une copie, sans personnage, ou si aucun personnage n'est connu (#165, #282). Code : `TimePerRunCalculator::bestCharacterForStepTime()`, `timePerRunAndRecommendedCharacterOf()`, `ProjectStepResource::$recommendedCharacterName`.

## Tests qui prouvent R7 (`TimePerRunCalculatorTest`, groupe `issue-283`)

Écrits en phase rouge (la méthode n'existe pas), sur le harnais migré : `timePerRunCalculatorOnSdeActivities()`, `userWithCachedSkills()`, constantes `RAVEN`, `RAVEN_BLUEPRINT` (fabrication 18 000 s, copie 14 400 s), `BLUEPRINT_WITHOUT_BASE_TIME`. TE 0, aucune structure, aucun système favori, aucune science. Assertion : `assertSame(['timePerRun' => …, 'recommendedCharacterName' => …], …)`.

| # | Étape | Personnages (lignes en cache, dans l'ordre) | `$characterSkillLevels` | Attendu |
|---|---|---|---|---|
| D1 | Fabrication du Raven | `Fresh` : aucune | non fourni | `[null, null]` (compétences inconnues : la règle vit dans la méthode) |
| D2 | Fabrication du Raven | `Fresh` : aucune ; `Main` : Industry 5, Advanced Industry 4 | non fourni | `[12672, 'Main']` (18 000 × 0,80 × 0,88) |
| D3 | Copie du Raven | `Fresh` : aucune | non fourni | `[14400, null]` (copie : aucun personnage, jamais inconnue par les compétences) |
| D4 | Fabrication du Raven | aucun personnage | non fourni | `[18000, null]` (multiplicateur 1, R6 de #282) |
| D5 | Fabrication de `BLUEPRINT_WITHOUT_BASE_TIME` | `Main` : Industry 5 | non fourni | `[null, 'Main']` (temps de base absent : durée inconnue, personnage toujours recommandé, comportement actuel du mapper) |
| D6 | Fabrication du Raven | `Fresh` : aucune (cache vide) | `[['name' => 'Main', 'skills' => [SKILL_INDUSTRY => 5]]]` | `[14400, 'Main']` (les niveaux fournis par la factory sont utilisés sans relire le cache) |

Noms suggérés : `testTimePerRunAndRecommendedCharacterAreUnknownWhenTheOnlyCharacterHasNoCachedSkill` (D1), `testTimePerRunIsThatOfTheRecommendedCharacterWithKnownSkills` (D2), `testCopyStepHasItsCopyingTimePerRunAndNoRecommendedCharacterEvenWithUnknownSkills` (D3), `testStepOfAUserWithoutCharacterHasTheTimePerRunWithoutSkillReductionAndNoRecommendedCharacter` (D4), `testStepWithoutBaseTimeInTheSdeHasAnUnknownTimePerRunButARecommendedCharacter` (D5), `testGivenSkillLevelsOfTheCharactersDecideTheTimePerRunWithoutReloadingTheCache` (D6).

Preuve que factory et mapper passent par la même règle (gardes-fous existants, sans modification de leurs méthodes) :

- `IndustryProjectFactoryTest`, groupe `issue-282` : 6 méthodes, 14 cas, toutes par `displayedJobsOf` (génération par la factory, affichage par le mapper sur la même instance de `TimePerRunCalculator`) ;
- `IndustryResourceMapperTest`, groupe `issue-282` : `testStepOfAPilotWhoseOnlyCharacterHasNoCachedSkillHasAnUnknownTimePerRunAndNoRecommendedCharacter`, `testStepOfAPilotWithAKnownCharacterHasTheTimePerRunOfItsSkills`.

Mutation : après le lot, `make infection FILTER=src/Service/Industry/TimePerRunCalculator.php` ; le ternaire de R7 n'existe plus dans la factory ni le mapper, ses mutants vivent dans le nouveau fichier et doivent être tués par D1 à D6 et les gardes-fous ci-dessus.

## Câblage

### Symfony

Aucun changement de `config/services.yaml` : `App\` est chargé en autowiring depuis `src/` (seuls `Entity/`, `Industry/Domain/`, `DependencyInjection/`, `Kernel.php` exclus), le nouveau service est partagé comme l'ancien. Les caches ont la même durée de vie qu'aujourd'hui (instance partagée du conteneur, worker FrankenPHP compris). Vérification : `docker compose exec app php bin/console lint:container` et `debug:container 'App\Service\Industry\TimePerRunCalculator'`.

### Harnais de test aujourd'hui

- **`new IndustryCalculationService(...)` positionnel, 9 arguments** : 8 sites (dont 1 ajouté par #282 dans `IndustryResourceMapperTest`). Après le changement, l'argument 5 (`EntityManagerInterface`) tombe sur `StaStationRepository` → **`TypeError`**. Chaque site retire les arguments 5 (EM), 8 (`IndustryActivityRepository`) et 9 (`CachedCharacterSkillRepository`).
- **`AutowiresByType::autowired(Classe, [services])`** : prend le premier service de la liste qui satisfait le type, sinon construit récursivement une classe `App\Service\Industry\*`, sinon lève `LogicException('The harness has no …')`. Le nouveau paramètre de la factory et du mapper est donc construit récursivement **avec le premier `EntityManagerInterface` de la liste**, souvent l'EM des projets (mock) et non l'EM SDE donné aujourd'hui à `IndustryCalculationService`. Pour ne pas déplacer le comportement d'un test, les harnais concernés construisent `TimePerRunCalculator` explicitement avec les doublures SDE actuelles et le passent dans la liste.
- `phpunit.xml` : `failOnWarning="true"`. Un `createStub(TimePerRunCalculator::class)` utilisé par `stepToResource` rendrait `[]` pour `timePerRunAndRecommendedCharacterOf` → clé absente → warning → échec. Un stub n'est acceptable que là où `stepToResource` n'est jamais appelé.
- `createStub/createMock(IndustryCalculationService::class)` (13 fichiers) : non touchés, aucun ne configure une méthode déplacée.

### Sites à modifier (harnais seul, aucune assertion touchée)

| Fichier | Site | Casse aujourd'hui | Changement |
|---|---|---|---|
| `tests/Unit/Service/Industry/IndustryCalculationTest.php` | `setUp` (l. 86) | `TypeError` | retirer les arguments 5, 8, 9 |
| `tests/Unit/Service/Industry/IndustryJobMatcherTest.php` | `$realCalculationService = new IndustryCalculationService(` (l. 1242) | `TypeError` | retirer les arguments 5, 8, 9 |
| `tests/Unit/Service/Industry/ResolvesTypeNamesFromSde.php` | `calculationServiceOnSdeTypeRepository` (l. 71) | `TypeError` (trait utilisé par ~20 classes de test) | retirer les arguments 5, 8, 9 et les 3 imports devenus inutiles |
| `tests/Unit/State/Processor/Industry/BuildsSplitGroupMaterialNeeds.php` | `new IndustryCalculationService(` (l. 195) | `TypeError` | retirer les arguments 5, 8, 9 |
| `tests/Unit/Service/Industry/BuildsProjectOnGoldenSde.php` | `calculationServiceOnGoldenSde` (l. 98-112) | `TypeError` | retirer les arguments 5, 8, 9 et le paramètre `?CachedCharacterSkillRepository $skillRepository` devenu inutile |
| idem | `projectFactoryOnGoldenSde` (l. 64-81) | glissement (EM des projets au lieu de `entityManagerWithoutRows()` quand `$projectEntityManager` est fourni : `BuildsProjectsOfTheOldRootCalculationOnGoldenSde`, `IndustryProjectFactoryCopyStepsTest` l. 395, `IndustryProjectFactoryBpcMaxRunsTest` l. 341) | construire `new TimePerRunCalculator($calculationService, $this->activityRepositoryOnGoldenSde(), $this->entityManagerWithoutRows(), $skillRepository ?? $this->createStub(CachedCharacterSkillRepository::class))` et le mettre dans la liste |
| `tests/Unit/Service/Industry/JobSplitCalculationTest.php` | `setUp` (l. 70), appel de `calculationServiceOnGoldenSde` | 3e argument orphelin | retirer `$skillRepository` de cet appel (il reste passé à `projectFactoryOnGoldenSde`) |
| `tests/Unit/Service/Industry/IndustryProjectFactoryTest.php` | `setUp` (l. 105-130) | `TypeError` + glissement (`$this->entityManager` mock au lieu de `sdeEntityManagerWithTheBaseTimes()`) | retirer les arguments 5, 8, 9 ; nouvelle propriété `$this->timePerRunCalculator = new TimePerRunCalculator($this->calculationService, $activityRepository, $this->sdeEntityManagerWithTheBaseTimes(), $this->skillRepository)` passée dans la liste de la factory |
| idem | `displayedJobsOf` (l. 293-310, mapper) | `LogicException` (pas d'`IndustryActivityRepository` ni d'EM dans la liste) | passer `$this->timePerRunCalculator` : **même instance que la factory**, condition des tests d'égalité affichage/découpage #134 et #282 |
| `tests/Unit/State/Provider/Industry/IndustryResourceMapperTest.php` | 5 appels `autowired(IndustryResourceMapper::class, …)` : 3 tests de structure (l. 51, 73, 94), `testProjectWithTwoKnownRootProductsIsNamedAfterBoth` (l. 299), `mapperOnSdeKnowingOnlyNitrogenFuelBlock` (l. 521) | `LogicException` | ajouter `$this->createStub(IndustryActivityRepository::class)` et `$this->createStub(EntityManagerInterface::class)` à chaque liste : la construction récursive reproduit les stubs que `ResolvesTypeNamesFromSde` donnait à l'ancien service |
| idem | `mapperOnTheBaseTimeOfTheNitrogenFuelBlockAndTheCachedSkills` (l. 488-517, ajouté par #282) | `TypeError` | construire l'ancien service avec ses 6 arguments, puis `new TimePerRunCalculator($calculationService, $activityRepository, $this->createStub(EntityManagerInterface::class), $skillRepository)` passé dans la liste avec lui |
| `tests/Unit/State/Processor/Industry/SyncCharacterSkillsProcessorTest.php` | `new IndustryResourceMapper(` positionnel (l. 291) | `ArgumentCountError` | ajouter `$this->createStub(TimePerRunCalculator::class)` en 2e argument (le processor n'appelle que `characterSkillsToResource`) |

Sans changement : `tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest.php` (l. 107) et `tests/Integration/Service/Industry/IndustryProjectFactoryRegenerationTest.php` (l. 152) : la liste `autowired` contient déjà `IndustryCalculationService`, `IndustryActivityRepository`, `CachedCharacterSkillRepository` du conteneur et `$this->em`, la construction récursive donne le service réel ; leur ligne `CachedCharacterSkill` Industry 0 ajoutée par #282 reste. Aussi sans changement : `FavoriteSystemStructureChoiceTest`, `ImportedStructureSolarSystemTest` (bonus de structure seul), `CreateProjectProcessorTest`, `MultiUnitProjectStepsRegenerationTest`, `ProjectProviderGoldenTreeCharacterizationTest` (factory et mapper doublés ou déjà construits), tous les `createStub(IndustryResourceMapper::class)` et `createStub(IndustryProjectFactory::class)`.

### Tests migrés vers le nouveau service

De `tests/Unit/Service/Industry/IndustryCalculationTest.php` (879 lignes ; tests l. 298-675, supports l. 677-790) vers **`tests/Unit/Service/Industry/TimePerRunCalculatorTest.php`**, classe `TimePerRunCalculatorTest`, `#[CoversClass(TimePerRunCalculator::class)]`. **30 méthodes de test, 44 cas** (trois data providers de 10, 5 et 2 cas), corps et valeurs attendues identiques, `#[Group]` gardés :

- `#[Group('issue-165')]` (10 méthodes, 23 cas) : `testTimePerRunOfAManufacturingStepFollowsItsTeAndTheIndustrySkills` (+ `manufacturingTimesPerRunByTeAndSkills`, 10 cas), `testTimePerRunOfAReactionStepFollowsItsTeAndTheReactionsSkill` (+ `reactionTimesPerRunByTeAndSkills`, 5 cas), `testTimePerRunOfAManufacturingStepInARaitaruTakesItsStructureTimeBonus`, `testTimePerRunOfAReactionStepInATataraTakesItsStructureTimeBonus`, `testScienceSkillOfTheBlueprintTakesOnePercentPerLevelOnTopOfTheIndustrySkills`, `testTwoScienceSkillsBothApply`, `testScienceSkillsAreResolvedPerBlueprintAndActivityOnTheSameService`, `testScienceSkillOfTheBlueprintNotTrainedLeavesTheTimePerRunOfTheIndustrySkills`, `testTimePerRunIsUnknownWhenTheSdeHasNoBaseTimeForTheBlueprint`, `testTimePerRunOfAReactionIsUnknownWhenTheSdeHasABaseTimeForTheManufacturingOfTheBlueprintOnly`
- `#[Group('issue-134')]` (1) : `testTimePerRunIsRoundedUpToTheNextSecondEvenWhenItsFractionIsBelowOneHalf`
- `#[Group('issue-277')]` (4 méthodes, 5 cas) : `testTimePerRunIsNotRoundedUpByTheFloatingPointNoiseOfItsProduct` (+ `timesPerRunWhoseProductCarriesFloatingPointNoise`, 2 cas), `testTimePerRunWithATrueFractionOfASecondIsStillRoundedUp`, `testTimePerRunWithATrueFractionOfFourMillionthsOfASecondIsRoundedUp`, `testTimePerRunWithATrueFractionBelowHalfAMillionthOfASecondIsNotRoundedUp`
- `#[Group('issue-126')]` (3) : `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint`, `testTimePerRunOfAManufacturingStepIsTheManufacturingBaseTimeEvenWhenTheBlueprintHasACopyingTime`, `testTimePerRunOfACopyStepIsUnknownWhenTheSdeHasNoCopyingTimeForTheBlueprint`
- `#[Group('issue-282')]` (12, ajoutées par #282) : `testLoadedSkillLevelsAreUnknownForACharacterWithoutCachedSkillAndKnownForACharacterWithACachedLevelZero`, `testLoadedSkillLevelsOfACharacterWithManualSkillsOnlyAreKnown`, `testSkillsOfAManufacturingStepAreUnknownWhenTheOnlyCharacterHasNoCachedSkill`, `testCharacterWithKnownSkillsAtLevelZeroIsRetainedRatherThanAFirstCharacterWithUnknownSkills`, `testSkillsOfAManufacturingStepAreNotUnknownForAUserWithoutCharacter`, `testSkillsOfACopyStepAreNotUnknownEvenWhenTheOnlyCharacterHasNoCachedSkill`, `testSkillsOfAReactionStepAreUnknownWhenNoCharacterHasCachedSkills`, `testTimePerRunOfAManufacturingStepWithIndustryAndAdvancedIndustryAtLevelZeroIsItsBaseTime`, `testGivenSkillLevelsOfTheCharactersAreRetainedWithoutReloadingTheCache`, `testFirstOfTwoCharactersWithTheSameSkillTimeMultiplierIsRetained`, `testFastestCharacterAfterACharacterWithUnknownSkillsIsRetained`, `testRetainedCharacterFollowsTheActivityOfTheStep`

Partent avec eux : toutes les constantes l. 45-78 (aucune n'est lue ailleurs dans le fichier), la propriété `$cachedSkillsByCharacterName` (l. 82), et les supports `bestCharacterOfTheLoadedSkillLevels`, `userWithCachedSkills`, `skillRepositoryOfTheCachedSkills`, `stepOfAProject`, et `calculationServiceOnSdeActivities` renommé **`timePerRunCalculatorOnSdeActivities`** (retourne `new TimePerRunCalculator(new IndustryCalculationService(<6 arguments actuels>), $activityRepository, $entityManager, $this->skillRepositoryOfTheCachedSkills())`). Seuls changements dans les corps : ce renommage, et la variable `$calculationService` renommée `$timePerRunCalculator` dans `testScienceSkillsAreResolvedPerBlueprintAndActivityOnTheSameService` et `bestCharacterOfTheLoadedSkillLevels`. Le stub `fetchOne` du support est mort depuis #134 (temps de base lu par le repository) : il part tel quel, son retrait est hors lot. Les tests D1 à D6 (R7) s'ajoutent à cette classe dans le commit 2.

Restent dans `IndustryCalculationTest` (~350 lignes) : formules matériau, bonus de structure, cas limites, nom affiché du projet (#115). Imports qui y restent : `IndustryProject`, `User`, `IndustryCalculationService`, `SplitGroupQuantities`, `Group`, `TestCase` ; les autres partent ou disparaissent (vérifier avec PHPStan / php-cs-fixer).

### Gardes-fous non migrés (méthodes inchangées, seul le harnais bouge)

| Fichier | Tests | Ce qu'ils gardent |
|---|---|---|
| `IndustryProjectFactoryTest` | `issue-165` : 4 méthodes (`testIndustryFiveAndAdvancedIndustryFiveCutTheTimePerRunThatDecidesTheSplit`, `testReactionsFourCutsTheTimePerRunThatDecidesTheSplit`, `testSplitUsesTheBestCharacterOfTheUser`, `testStepWithoutBaseTimeInTheSdeIsNeverSplitWhateverItsRuns`) ; `issue-134` / `issue-126` : 7 méthodes, 10 cas (`testRunsWhoseJobFillsExactlyTheMaximumDurationWithTheDisplayedTimePerRunStayInOneJob`, `testSplitTakesTheTimeBonusOfTheRaitaruOfTheFavoriteSystemRatherThanOfTheFasterAzbelElsewhere`, `testSplitOfAProductWithoutRigCategoryTakesTheRoleTimeBonusOfTheStructure`, `testEveryGeneratedJobDisplaysTheTimePerRunThatDecidedItsSplit` (4 cas), `testDisplayedTimePerRunIsThatOfTheFirstCharacterAmongThoseWithTheLowestSkillTimeMultiplier`, `testSplitAndDisplayedTimePerRunTakeTheSkillsOfTheFastestCharacterBetweenSlowerOnes`, `testRegeneratedStepWithAnAssignedAzbelIsSplitAndDisplayedWithTheTimeBonusOfTheAzbelRatherThanOfTheFavoriteRaitaru`) ; `issue-282` : 6 méthodes, 14 cas (`testTimePerRunOfAManufacturingStepIsUnknownAndUnsplitOnlyWhenNoCharacterOfTheUserHasKnownSkills` (7), `testTimePerRunOfAReactionStepIsUnknownAndUnsplitWhenNoCharacterOfTheUserHasKnownSkills` (2), `testManufacturingOfACharacterWithoutCachedSkillIsUnknownAndUnsplitWhileItsCopyKeepsItsCopyingTimePerRun`, `testStepWithoutBaseTimeInTheSdeOfACharacterWithoutCachedSkillHasNoRecommendedCharacter`, `testRegenerationMergesTheJobsOfAStepSplitByTheMaximumDurationWhenNoCharacterOfTheUserHasKnownSkills`, `testRegenerationSplitsAStepOfUnknownTimePerRunByTheBpcMaxRunsOnly` (2)) ; plus les 3 tests de découpage sans groupe (`testLongJobsAreSplitByMaxDuration`, `testShortJobsAreNotSplit`, `testCopyStepLongerThanTheMaximumDurationIsNeverSplit`) | génération = affichage (R7), découpage par la durée |
| `IndustryResourceMapperTest` | `issue-282` : `testStepOfAPilotWhoseOnlyCharacterHasNoCachedSkillHasAnUnknownTimePerRunAndNoRecommendedCharacter`, `testStepOfAPilotWithAKnownCharacterHasTheTimePerRunOfItsSkills` | affichage par le mapper (R7) |
| `JobSplitCalculationTest` (groupe de classe `issue-171`) | 9 méthodes, 15 cas, dont `issue-165` : `testTeAndSkillsOfThePilotSetTheTimePerRunThatSplitsACapitalJumpDriveInJobsOfOneDay` (5 cas), `testReactionsSkillOfThePilotSetsTheTimePerRunThatSplitsTheReinforcedCarbonFiberOfACapitalJumpDrive` (3 cas) ; libellés « skills at level 0 » depuis #282 | découpage sur le SDE golden avec compétences du pilote |
| `IndustryProjectFactoryGoldenStepsCharacterizationTest` | 1 méthode `issue-134` (l. 713) ; snapshots JSON inchangés | génération golden |

## Ordre des commits

1. `refactor(industry): move the time per run of a step out of IndustryCalculationService (#283)` — création de `TimePerRunCalculator` par **copier-couper** du bloc (aucune réécriture hors R3), rebranchement factory + mapper sur les méthodes déplacées (ternaires `skillsUnknown` encore en place), harnais du tableau ci-dessus, `TimePerRunCalculatorTest` par déplacement. Vert à lui seul (le changement de constructeur casse le harnais, les deux ne peuvent pas être séparés). Revue : `git diff -M --color-moved=dimmed-zebra main` doit montrer le bloc en « moved », seule `structureBonusOf` changée.
2. `refactor(industry): give the time per run and the recommended character of a step in one place (#283)` — tests D1 à D6 (rouges puis verts), `timePerRunAndRecommendedCharacterOf` (R7), factory et mapper sur elle, ternaires retirés. Aucun autre test ne bouge.
3. `docs(industry): record TimePerRunCalculator and the recommended character in the module map and the glossary (#283)`.

Vérifications avant chacun des commits 1 et 2 : `make test-unit`, `make test-db && make test-integration`, PHPStan, `make deptrac`, `wc -l src/Service/Industry/IndustryCalculationService.php` (< 300), `lint:container` ; après le commit 2 : `make infection FILTER=src/Service/Industry/TimePerRunCalculator.php`.

## Docs à mettre à jour (commit 3)

- `docs/glossary.md` :
  - « Durée par run » : code `TimePerRunCalculator::timePerRunOf()`, `calculateTimePerRun()`, `timePerRunAndRecommendedCharacterOf()` (au lieu de `IndustryCalculationService::…`) ;
  - « Temps de base » : ajouter `TimePerRunCalculator::baseTimePerRunOf()` (privée) ;
  - « Compétences inconnues » : `TimePerRunCalculator::loadCharacterSkillLevels()`, `bestCharacterForStepTime()` au lieu de `IndustryCalculationService::…` ;
  - nouveau terme « Personnage recommandé » (R8).
- `docs/modules/industry.md` :
  - ligne 3 : date de vérification #283 ;
  - §1 « Pas encore migrés » : ajouter `TimePerRunCalculator` ;
  - §2, ligne `IndustryCalculationService` : rôle réduit à quantité matériau, noms, bonus de structure ; méthodes `calculateMaterialQuantity`, `getStructureBonusForStep`, `structureBonusOf`, `assignedStructureBonusOf` ; retirer la durée par run, le personnage, les compétences et `skillsUnknown` ; appelants corrigés (`SlotTrackerService` n'y lit rien du bloc durée) ;
  - §2, nouvelle ligne `TimePerRunCalculator` : durée par run et personnage recommandé d'une étape, une seule méthode pour la génération et l'affichage (`timePerRunAndRecommendedCharacterOf` : compétences inconnues → `null`, #126 #134 #282), temps de base (`IndustryActivityRepository`, `null` sans temps de base), personnage recommandé (copie : aucun ; compétences inconnues ignorées), compétences et sciences du blueprint ; méthodes `timePerRunAndRecommendedCharacterOf`, `timePerRunOf`, `calculateTimePerRun`, `bestCharacterForStepTime`, `loadCharacterSkillLevels`, `calculateSkillTimeMultiplier` ; appelants `IndustryProjectFactory::addTimeDataToSteps`, `IndustryResourceMapper::stepToResource` ; tests `TimePerRunCalculatorTest` ;
  - §2, ligne `IndustryBonusService` : appelants inchangés (le nouveau service passe par `IndustryCalculationService::structureBonusOf`) ;
  - §3 « Durée de job, compétences » : `TimePerRunCalculator::timePerRunAndRecommendedCharacterOf` (seule décision durée + personnage, génération et affichage), `timePerRunOf`, `bestCharacterForStepTime`, `calculateSkillTimeMultiplier` au lieu de `IndustryCalculationService::…` ;
  - §4 : retirer `IndustryCalculationService.php` de la liste > 400 lignes ; mettre à jour par `wc -l` `IndustryProjectFactory.php` (700 avant #283) ; tests : retirer `IndustryCalculationTest` si `wc -l` < 400, ajouter `TimePerRunCalculatorTest` (~560 attendues), mettre à jour `IndustryProjectFactoryTest` (1407 avant #283) et `IndustryResourceMapperTest` (581 avant #283).

## Risques

- **Ordre avec #275.** #275 (Science sur la copie) reste ouverte et touche `bestCharacterForStepTime` et `calculateSkillTimeMultiplier` : elle se rebase sur `TimePerRunCalculator` (conflit de déplacement, résolu en réappliquant son diff dans le nouveau fichier). Sa règle « Science inconnue pour tous → durée de copie `null` » doit passer par `skillsUnknown` et `timePerRunAndRecommendedCharacterOf`, jamais par un nouveau ternaire dans un appelant.
- **Méthodes publiques qui contournent R7.** `timePerRunOf`, `calculateTimePerRun` et `bestCharacterForStepTime` restent publiques (44 cas de test les appellent) et ignorent `skillsUnknown` : un futur appelant pourrait réécrire la règle à côté. La carte du module désigne `timePerRunAndRecommendedCharacterOf` comme seule entrée des appelants ; les rendre privées est hors lot.
- **Glissement silencieux du harnais** : un `autowired` qui construit le nouveau service récursivement avec l'EM des projets resterait vert aujourd'hui (les sciences lues valent `[]` des deux côtés) mais ne testerait plus la même chose, et un EM mock pourrait recevoir un appel `getConnection` non attendu. D'où la construction explicite dans `IndustryProjectFactoryTest`, `BuildsProjectOnGoldenSde` et `mapperOnTheBaseTimeOfTheNitrogenFuelBlockAndTheCachedSkills`.
- **Instance partagée factory/mapper** dans `IndustryProjectFactoryTest` : deux instances distinctes donneraient la même valeur (caches seuls), mais l'égalité affichage/découpage #134 / #282 se lit mieux sur une seule.
- **D5 fige un comportement discutable** (personnage recommandé alors que la durée est inconnue faute de temps de base) : c'est le comportement actuel du mapper, R1 interdit de le changer ici.
- Constructeurs : factory 9 → 10 dépendances, mapper 3 → 4. Hors lot : réduire la factory.

## Hors périmètre

- Rendre privées `timePerRunOf`, `calculateTimePerRun`, `bestCharacterForStepTime`, `getBlueprintScienceSkillIds`, `calculateSkillTimeMultiplier` (réécrire d'abord leurs tests sur `timePerRunAndRecommendedCharacterOf`).
- Lire les compétences une fois par projet affiché dans le mapper (aujourd'hui une fois par étape).
- Brancher `Domain\JobDuration` / `SkillTimeModifier` (noyau, ADR-0009).
- Retirer le stub `fetchOne` mort des harnais.
- Toute correction de #275, #276.

## Tranché

- **A1 — Ordre #282 / #283** : #282 est fusionnée (`33901ad9..075465d3`) ; #283 s'applique après, inventaire refait sur `075465d3`.
