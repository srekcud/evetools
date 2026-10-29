# Spec #283 — Extraire la durée par run d'une étape de `IndustryCalculationService`

**Statut : rédigée en mode nuit le 2026-10-29, à valider par Sylvain.** Refactor pur : aucune durée, aucun personnage retenu, aucune réponse d'API ne change. État vérifié sur `fix/group-production-tree` au commit `562693ec` (correctif #277 `8ebc0971` compris).

## Contexte

`src/Service/Industry/IndustryCalculationService.php` fait **466 lignes** pour **9 dépendances**. Il mélange trois sujets : quantité matériau et noms, bonus de structure (assignée, système favori, meilleure), et **durée par run** d'une étape avec ses compétences.

Le bloc durée occupe les lignes 252 à 442 (191 lignes), plus deux caches (l. 25-29) et trois dépendances (l. 36, 39, 40) qu'il est seul à utiliser :

| Dépendance de `IndustryCalculationService` | Utilisée par |
|---|---|
| `EntityManagerInterface $entityManager` | `getBlueprintScienceSkillIds` seul (SQL brut `sde_industry_activity_skills`) |
| `IndustryActivityRepository $activityRepository` | `baseTimePerRunOf` seul (temps de base) |
| `CachedCharacterSkillRepository $skillRepository` | `loadCharacterSkillLevels` seul |

Le bloc durée n'a qu'**un seul appel vers le reste du service** : `timePerRunOf` lit `$this->structureBonusOf(...)['timeBonus']` (l. 294). Le reste du service n'appelle jamais le bloc durée.

Appelants dans `src/` (grep `timePerRunOf|calculateTimePerRun|bestCharacterForStepTime|loadCharacterSkillLevels|calculateSkillTimeMultiplier|getBlueprintScienceSkillIds|baseTimePerRunOf`) : **deux seulement**.

| Appelant | Appel | Garde aussi `IndustryCalculationService` pour |
|---|---|---|
| `IndustryProjectFactory::addTimeDataToSteps` (l. 455, 458, 460) | `loadCharacterSkillLevels`, `bestCharacterForStepTime`, `timePerRunOf` | `assignedStructureBonusOf` (l. 591) |
| `IndustryResourceMapper::stepToResource` (l. 247, 248) | `bestCharacterForStepTime`, `calculateTimePerRun` | noms, `getStructureBonusForStep`, `getBestStructureBonusForProduct`, `resolveFacilityName`, `getProjectDisplayName` |

`SlotTrackerService`, `ProductionCostService`, `IndustryJobMatcher`, `IndustryStepCalculator` et les providers n'appellent aucune méthode du bloc durée. Aucun test ne configure une méthode du bloc durée sur un double (`createStub`/`createMock`) : le seul test qui les appelle est `IndustryCalculationTest`.

**Pré-requis (issue #283 : « à faire après l'issue sur la durée inconnue »)** : #282 (compétences non synchronisées → durée inconnue) est **ouverte** et réécrira `calculateSkillTimeMultiplier`, `loadCharacterSkillLevels` et `bestCharacterForStepTime`. #275 (Science sur la copie) est en attente et touche le même code. Voir « À trancher ».

## Règles

**R1 — Comportement inchangé.** Mêmes valeurs de durée par run, même personnage recommandé, même `null` sans temps de base, mêmes lectures SDE (une lecture du temps de base et des sciences par blueprint et activité, une lecture des compétences par génération). Aucun test de durée ne change d'assertion ni de valeur attendue.

**R2 — Nom du service : `App\Service\Industry\TimePerRunCalculator`** (glossaire « Durée par run » = *Time per run*). Écartés : `StepDurationService` (« durée d'étape » absent du glossaire), `JobDuration` (pris par `Domain\JobDuration`, durée d'un job entier). Classe non `final` (doublée par `createStub` dans `SyncCharacterSkillsProcessorTest`), non `readonly` (deux caches mutables), comme `IndustryCalculationService`. Reste dans `src/Service/Industry/` : il dépend de Doctrine et d'entités, il ne peut pas aller dans `src/Industry/Application/` (ADR-0009), et le harnais `AutowiresByType` ne construit récursivement que `App\Service\Industry\*`.

**R3 — Méthodes déplacées telles quelles** (nom, signature, visibilité, docblock, corps) :

| Méthode | Visibilité | Terme du glossaire |
|---|---|---|
| `calculateTimePerRun(IndustryProjectStep, ?array $characterSkills = null): ?int` | public | Durée par run |
| `timePerRunOf(User, int, int, string, int, ?IndustryStructureConfig, ?array): ?int` | public | Durée par run |
| `loadCharacterSkillLevels(User): array` | public | — |
| `bestCharacterForStepTime(User, int, string, ?array = null): array` | public | — |
| `calculateSkillTimeMultiplier(array, string, array = []): float` | public | — |
| `getBlueprintScienceSkillIds(int, string): array` | public | — |
| `baseTimePerRunOf(int, string): ?int` | private | Temps de base |
| propriétés `$blueprintSkillCache`, `$baseTimeCache` | private | Temps de base (cache) |

`getBaseTimePerRun` n'existe plus (remplacée par `baseTimePerRunOf` en #126/#134) : rien à déplacer sous ce nom.

Seule ligne réécrite dans les corps : `$this->structureBonusOf(` → `$this->calculationService->structureBonusOf(` dans `timePerRunOf`. `getBlueprintScienceSkillIds` et `calculateSkillTimeMultiplier` n'ont plus d'appelant hors du nouveau service, mais restent publiques dans ce lot (déplacer sans réécrire).

**R4 — Dépendances du nouveau service** (constructeur de 4 paramètres, dans cet ordre) :

1. `IndustryCalculationService $calculationService` — bonus de temps de la structure (`structureBonusOf`), seul lien vers l'ancien service ; pas de cycle (l'ancien n'appelle pas le nouveau).
2. `IndustryActivityRepository $activityRepository` — temps de base.
3. `EntityManagerInterface $entityManager` — sciences du blueprint.
4. `CachedCharacterSkillRepository $skillRepository` — compétences des personnages.

**R5 — `IndustryCalculationService` après le déplacement** : 6 dépendances (`TypeNameResolver`, `IndustryBonusService`, `IndustryStructureConfigRepository`, `IndustryUserSettingsRepository`, `StaStationRepository`, `CachedStructureRepository`, dans cet ordre), imports retirés : `CachedCharacterSkill`, `IndustryActivityType`, `CachedCharacterSkillRepository`, `IndustryActivityRepository`, `EntityManagerInterface`. Environ **260 lignes** (466 − 191 du bloc − 6 des caches − 3 du constructeur − 5 imports) : **cible < 300, contrôlée par `wc -l`**.

**R6 — Appelants rebranchés** :

- `IndustryProjectFactory` : nouveau paramètre `TimePerRunCalculator $timePerRunCalculator` juste après `$calculationService` ; les 3 appels de `addTimeDataToSteps` passent par lui. `$calculationService` reste (l. 591).
- `IndustryResourceMapper` : nouveau paramètre `TimePerRunCalculator $timePerRunCalculator` juste après `$calculationService` ; les 2 appels de `stepToResource` passent par lui. `$calculationService` reste.

## Câblage

### Symfony

Aucun changement de `config/services.yaml` : `App\` est chargé en autowiring depuis `src/` (seuls `Entity/`, `Industry/Domain/`, `DependencyInjection/`, `Kernel.php` exclus), le nouveau service est partagé comme l'ancien. Les caches ont la même durée de vie qu'aujourd'hui (instance partagée du conteneur, worker FrankenPHP compris). Vérification : `docker compose exec app php bin/console lint:container` et `debug:container 'App\Service\Industry\TimePerRunCalculator'`.

### Harnais de test aujourd'hui

- **`new IndustryCalculationService(...)` positionnel, 9 arguments** : 7 sites. Après le changement, l'argument 5 (`EntityManagerInterface`) tombe sur `StaStationRepository` → **`TypeError`**. Chaque site retire les arguments 5 (EM), 8 (`IndustryActivityRepository`) et 9 (`CachedCharacterSkillRepository`).
- **`AutowiresByType::autowired(Classe, [services])`** : prend le premier service de la liste qui satisfait le type, sinon construit récursivement une classe `App\Service\Industry\*`, sinon lève `LogicException('The harness has no …')`. Le nouveau paramètre de la factory et du mapper est donc construit récursivement **avec le premier `EntityManagerInterface` de la liste**, qui est souvent l'EM des projets (mock) et non l'EM SDE donné aujourd'hui à `IndustryCalculationService`. Pour ne pas déplacer le comportement d'un test, les harnais concernés construisent `TimePerRunCalculator` explicitement avec les doublures SDE actuelles et le passent dans la liste.
- `phpunit.xml` : `failOnWarning="true"`. Un `createStub(TimePerRunCalculator::class)` utilisé par `stepToResource` ferait `$bestCharacter['skills']` sur `[]` → warning → échec. Un stub n'est acceptable que là où `stepToResource` n'est jamais appelé.
- `createStub/createMock(IndustryCalculationService::class)` (13 fichiers) : non touchés, aucun ne configure une méthode déplacée.

### Sites à modifier (harnais seul, aucune assertion touchée)

| Fichier | Site | Casse aujourd'hui | Changement |
|---|---|---|---|
| `tests/Unit/Service/Industry/IndustryCalculationTest.php` | `setUp` | `TypeError` | retirer les arguments 5, 8, 9 |
| `tests/Unit/Service/Industry/IndustryJobMatcherTest.php` | `$realCalculationService = new IndustryCalculationService(` | `TypeError` | retirer les arguments 5, 8, 9 |
| `tests/Unit/Service/Industry/ResolvesTypeNamesFromSde.php` | `calculationServiceOnSdeTypeRepository` | `TypeError` (trait utilisé par ~20 classes de test) | retirer les arguments 5, 8, 9 |
| `tests/Unit/State/Processor/Industry/BuildsSplitGroupMaterialNeeds.php` | `new IndustryCalculationService(` | `TypeError` | retirer les arguments 5, 8, 9 |
| `tests/Unit/Service/Industry/BuildsProjectOnGoldenSde.php` | `calculationServiceOnGoldenSde` | `TypeError` | retirer les arguments 5, 8, 9 et le paramètre `?CachedCharacterSkillRepository $skillRepository` devenu inutile |
| idem | `projectFactoryOnGoldenSde` | glissement (EM des projets au lieu de `entityManagerWithoutRows()`) | construire `new TimePerRunCalculator($calculationService, $this->activityRepositoryOnGoldenSde(), $this->entityManagerWithoutRows(), $skillRepository ?? $this->createStub(CachedCharacterSkillRepository::class))` et le mettre dans la liste |
| `tests/Unit/Service/Industry/JobSplitCalculationTest.php` | `setUp`, appel de `calculationServiceOnGoldenSde` | 3e argument orphelin | retirer `$skillRepository` de cet appel (il reste passé à `projectFactoryOnGoldenSde`) |
| `tests/Unit/Service/Industry/IndustryProjectFactoryTest.php` | `setUp` | `TypeError` + glissement (`$this->entityManager` mock au lieu de `sdeEntityManagerWithTheBaseTimes()`) | retirer les arguments 5, 8, 9 ; nouvelle propriété `$this->timePerRunCalculator = new TimePerRunCalculator($this->calculationService, $activityRepository, $this->sdeEntityManagerWithTheBaseTimes(), $this->skillRepository)` passée dans la liste de la factory |
| idem | `displayedJobsOf` (mapper) | `LogicException` (pas d'`IndustryActivityRepository` ni d'EM dans la liste) | passer `$this->timePerRunCalculator` : **même instance que la factory**, condition des tests d'égalité affichage/découpage #134 |
| `tests/Unit/State/Provider/Industry/IndustryResourceMapperTest.php` | 5 appels `autowired(IndustryResourceMapper::class, …)` (3 tests de structure, `testProjectWithTwoKnownRootProductsIsNamedAfterBoth`, `mapperOnSdeKnowingOnlyNitrogenFuelBlock`) | `LogicException` | ajouter `$this->createStub(IndustryActivityRepository::class)` et `$this->createStub(EntityManagerInterface::class)` à chaque liste : la construction récursive reproduit les stubs que `ResolvesTypeNamesFromSde` donnait à l'ancien service |
| `tests/Unit/State/Processor/Industry/SyncCharacterSkillsProcessorTest.php` | `new IndustryResourceMapper(` positionnel | `ArgumentCountError` | ajouter `$this->createStub(TimePerRunCalculator::class)` en 2e argument (le processor n'appelle que `characterSkillsToResource`) |

Sans changement : `tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest.php` et `tests/Integration/Service/Industry/IndustryProjectFactoryRegenerationTest.php` (la liste `autowired` contient déjà les 4 dépendances réelles du conteneur et `$this->em`), `FavoriteSystemStructureChoiceTest`, `ImportedStructureSolarSystemTest` (bonus de structure seul), tous les `createStub(IndustryResourceMapper::class)` et `createStub(IndustryProjectFactory::class)`.

### Tests migrés vers le nouveau service

De `tests/Unit/Service/Industry/IndustryCalculationTest.php` (l. 291-560) vers **`tests/Unit/Service/Industry/TimePerRunCalculatorTest.php`**, classe `TimePerRunCalculatorTest`, `#[CoversClass(TimePerRunCalculator::class)]`. **16 méthodes de test, 30 cas** (deux data providers de 10 et 5 cas, un de 2), corps et valeurs attendues identiques, `#[Group]` gardés :

- `#[Group('issue-165')]` : `testTimePerRunOfAManufacturingStepFollowsItsTeAndTheIndustrySkills` (+ `manufacturingTimesPerRunByTeAndSkills`), `testTimePerRunOfAReactionStepFollowsItsTeAndTheReactionsSkill` (+ `reactionTimesPerRunByTeAndSkills`), `testTimePerRunOfAManufacturingStepInARaitaruTakesItsStructureTimeBonus`, `testTimePerRunOfAReactionStepInATataraTakesItsStructureTimeBonus`, `testScienceSkillOfTheBlueprintTakesOnePercentPerLevelOnTopOfTheIndustrySkills`, `testTwoScienceSkillsBothApply`, `testScienceSkillsAreResolvedPerBlueprintAndActivityOnTheSameService`, `testScienceSkillOfTheBlueprintNotTrainedLeavesTheTimePerRunOfTheIndustrySkills`, `testTimePerRunIsUnknownWhenTheSdeHasNoBaseTimeForTheBlueprint`, `testTimePerRunOfAReactionIsUnknownWhenTheSdeHasABaseTimeForTheManufacturingOfTheBlueprintOnly`
- `#[Group('issue-134')]` : `testTimePerRunIsRoundedUpToTheNextSecondEvenWhenItsFractionIsBelowOneHalf`
- `#[Group('issue-277')]` : `testTimePerRunIsNotRoundedUpByTheFloatingPointNoiseOfItsProduct` (+ `timesPerRunWhoseProductCarriesFloatingPointNoise`), `testTimePerRunWithATrueFractionOfASecondIsStillRoundedUp`
- `#[Group('issue-126')]` : `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint`, `testTimePerRunOfAManufacturingStepIsTheManufacturingBaseTimeEvenWhenTheBlueprintHasACopyingTime`, `testTimePerRunOfACopyStepIsUnknownWhenTheSdeHasNoCopyingTimeForTheBlueprint`

Partent avec eux : toutes les constantes l. 44-73 (aucune n'est lue ailleurs dans le fichier), `stepOfAProject`, et `calculationServiceOnSdeActivities` renommé **`timePerRunCalculatorOnSdeActivities`** (retourne `new TimePerRunCalculator(new IndustryCalculationService(<6 arguments actuels>), $activityRepository, $entityManager, createStub(CachedCharacterSkillRepository))`). Le renommage du helper et de la variable `$calculationService` de `testScienceSkillsAreResolvedPerBlueprintAndActivityOnTheSameService` est le seul changement dans les corps. Le stub `fetchOne` du helper est mort depuis #134 (temps de base lu par le repository) : il part tel quel, son retrait est hors lot.

Restent dans `IndustryCalculationTest` : formules matériau, bonus de structure, cas limites, nom affiché du projet (#115). Imports qui y restent : `IndustryProject`, `User`, `IndustryCalculationService`, `SplitGroupQuantities`, `Group`, `TestCase` ; les autres partent ou disparaissent (vérifier avec PHPStan / php-cs-fixer).

Garde-fous non migrés, qui doivent rester verts sans modification de leurs méthodes : 12 tests `issue-126/134/165/277` de `IndustryProjectFactoryTest`, 2 de `JobSplitCalculationTest`, 1 de `IndustryProjectFactoryGoldenStepsCharacterizationTest` (snapshots JSON inchangés).

## Ordre des commits

Un seul commit de code, vert à lui seul (le changement de constructeur casse le harnais, les deux ne peuvent pas être séparés) :

1. `refactor(industry): move the time per run of a step out of IndustryCalculationService (#283)` — création de `TimePerRunCalculator` par **copier-couper** du bloc (aucune réécriture hors R3), rebranchement factory + mapper, harnais du tableau ci-dessus, `TimePerRunCalculatorTest` par déplacement. Revue : `git diff -M --color-moved=dimmed-zebra main` doit montrer le bloc en « moved », seule `structureBonusOf` changée.
2. `docs(industry): record TimePerRunCalculator in the module map and the glossary (#283)`.

Vérifications avant le commit 1 : `make test-unit`, `make test-db && make test-integration`, PHPStan, `make deptrac`, `wc -l src/Service/Industry/IndustryCalculationService.php` (< 300), `lint:container`.

## Docs à mettre à jour (commit 2)

- `docs/glossary.md`, ligne « Durée par run » : code `TimePerRunCalculator::timePerRunOf()`, `calculateTimePerRun()` (au lieu de `IndustryCalculationService::…`). Ligne « Temps de base » : ajouter `TimePerRunCalculator::baseTimePerRunOf()` (privée). Aucun nouveau terme.
- `docs/modules/industry.md` :
  - ligne 3 : date de vérification #283 ;
  - §1 « Pas encore migrés » : ajouter `TimePerRunCalculator` ;
  - §2, ligne `IndustryCalculationService` : rôle réduit à quantité matériau, noms, bonus de structure ; méthodes `calculateMaterialQuantity`, `getStructureBonusForStep`, `structureBonusOf`, `assignedStructureBonusOf` ; retirer la durée par run, le personnage et les compétences ;
  - §2, nouvelle ligne `TimePerRunCalculator` : durée par run (une seule fonction pour l'affichage et la génération, #126 #134), temps de base (`IndustryActivityRepository`, `null` sans temps de base), personnage retenu (copie : aucun), compétences et sciences du blueprint ; méthodes `timePerRunOf`, `calculateTimePerRun`, `bestCharacterForStepTime`, `loadCharacterSkillLevels`, `calculateSkillTimeMultiplier` ; appelants `IndustryProjectFactory::addTimeDataToSteps`, `IndustryResourceMapper::stepToResource` ; tests `TimePerRunCalculatorTest` ;
  - §2, ligne `IndustryBonusService` : appelants inchangés (le nouveau service passe par `IndustryCalculationService::structureBonusOf`) ;
  - §3 « Durée de job, compétences » : `TimePerRunCalculator::timePerRunOf` au lieu de `IndustryCalculationService::timePerRunOf` ;
  - §4 : retirer `IndustryCalculationService.php` (445, en réalité 466 avant #283) de la liste > 400 lignes ; retirer `IndustryCalculationTest 552` de la liste des tests > 400 lignes si `wc -l` le confirme.

## Risques

- **Ordre avec #282 et #275.** L'issue demande #282 d'abord. Si #282 passe avant, refaire le grep du Contexte : les signatures de `bestCharacterForStepTime` / `calculateSkillTimeMultiplier` et les tests à migrer auront changé. Si #283 passe avant, #282 et #275 se rebasent sur un fichier neuf (conflit de déplacement, résolu en réappliquant leur diff dans `TimePerRunCalculator`).
- **Glissement silencieux du harnais** : un `autowired` qui construit le nouveau service récursivement avec l'EM des projets resterait vert aujourd'hui (les sciences lues valent `[]` des deux côtés) mais ne testerait plus la même chose. D'où la construction explicite dans `IndustryProjectFactoryTest` et `BuildsProjectOnGoldenSde`.
- **Instance partagée factory/mapper** dans `IndustryProjectFactoryTest` : deux instances distinctes donneraient la même valeur (caches seuls), mais l'égalité affichage/découpage #134 se lit mieux sur une seule.
- Constructeurs : factory 10 → 11 dépendances, mapper 3 → 4. Hors lot : réduire la factory.

## Hors périmètre

- Rendre privées `getBlueprintScienceSkillIds` et `calculateSkillTimeMultiplier`.
- Brancher `Domain\JobDuration` / `SkillTimeModifier` (noyau, ADR-0009).
- Retirer le stub `fetchOne` mort des harnais.
- Toute correction de #275, #282.

## À trancher par Sylvain

- **A1 — Ordre #282 / #283** : attendre #282 (recommandé par l'issue, un seul refactor du code de compétences) ou passer #283 d'abord (mécanique, plus simple à relire avant que #282 ne réécrive les méthodes). Par défaut, cette spec suit l'issue : #283 après #282, inventaire à revérifier à ce moment.
