# Spec #282 — Durée par run : compétences non synchronisées = durée inconnue, distincte d'un niveau 0

**Statut : rédigée en autonomie le 2026-10-30, à valider par Sylvain.** Aucune question posée : les choix pris sont listés dans « Décisions prises en autonomie ». La spec s'applique **avant #283** : tout le code visé est encore dans `IndustryCalculationService`.

## Contexte

État vérifié sur `fix/group-production-tree` au commit `a96c1dce` (correctif #277 compris, #275 et #283 non implémentées, toutes deux ouvertes).

| Élément | État actuel | Fichier |
|---|---|---|
| Lecture des compétences | `loadCharacterSkillLevels(User)` : pour chaque personnage, dans l'ordre de `User::getCharacters()`, `findAllSkillsForCharacter` → `['name' => …, 'skills' => array<skillId, level>]`. Un personnage **sans aucune ligne** en cache donne `skills: []`, identique à un personnage connu dont aucune compétence n'est entraînée | `IndustryCalculationService::loadCharacterSkillLevels` |
| Personnage retenu | `bestCharacterForStepTime` : copie → `['skills' => null, 'name' => null]` ; sinon le personnage au multiplicateur le plus faible, le premier à égalité. Un personnage à `[]` a le multiplicateur 1 et peut être retenu. Sans personnage : `skills` `null`, `name` `null` | `IndustryCalculationService::bestCharacterForStepTime` |
| Multiplicateur | Industry 4 %/niv., Advanced Industry 3 %/niv. (tout sauf `reaction`), Reactions 4 %/niv. (`reaction`), sciences du blueprint 1 %/niv. Niveau absent lu `?? 0` | `IndustryCalculationService::calculateSkillTimeMultiplier` |
| Durée par run | `ceil(round(base × (1 − TE/100) × (1 − bonus temps structure/100) × multiplicateur, 6))` ; `$characterSkills === null` → multiplicateur 1 ; base absente → `null` | `IndustryCalculationService::timePerRunOf`, `calculateTimePerRun` |
| Appelants | génération : `IndustryProjectFactory::addTimeDataToSteps` (compétences lues une fois, `bestCharacterForStepTime` puis `timePerRunOf`) ; affichage : `IndustryResourceMapper::stepToResource` (`bestCharacterForStepTime` puis `calculateTimePerRun`, `recommendedCharacterName`) | |
| Découpage | `IndustryProjectFactory::maxRunsPerJob` : une durée par run `null` ne découpe pas ; le plafond de runs d'un BPC s'applique quand même | |
| Écriture du cache (ESI) | `SyncCharacterSkillsProcessor::syncCharacterSkills` : une ligne n'est **créée** que si le niveau est > 0 ; une ligne existante non manuelle est mise à jour, niveau 0 compris. Personnage sans token ou sans scope des compétences : rien n'est écrit | |
| Écriture du cache (manuelle) | `UpdateCharacterSkillProcessor` : Industry, Advanced Industry, Reactions, `isManual` vrai, **niveau 0 accepté** (seule voie qui crée une ligne au niveau 0) | |
| Précédent | #218 : compétences inconnues = aucune compétence en cache (`CachedCharacterSkillRepository::hasCachedSkills`) ; un personnage connu sans compétence de slot a le niveau 0 | `SlotTrackerService::getSlotStatus` |
| API | `ProjectStepResource::$timePerRun` (`?int`), `$recommendedCharacterName` (`?string`) : `null` déjà possible | |
| Front, étape | `StepTree.vue` : la ligne de durée (`v-if="stepTotalDuration(step) && …"`) **disparaît** quand `timePerRun` est `null` ; le badge du personnage recommandé disparaît quand le nom est `null`. `formatDuration(null)` rend `-` mais n'est pas atteint pour la ligne d'étape | `StepTree.vue` l. 70-90, 759, 813-821 |
| Front, groupe découpé | `groupTotalDuration` : maximum des jobs à durée connue, `-` si aucune | `StepTree.vue` l. 76-90, 588 |
| Front, projet | `useProjectTime` **ignore** une étape à `timePerRun` `null` (`continue`) : le total est la somme des autres, sans signal ; `ProjectDetail.vue` masque le total s'il vaut `null` (`v-if="estimatedProjectTime"`) | `useProjectTime.ts`, `ProjectDetail.vue` l. 253, 294 |

Composant existant pour un état inconnu : `frontend/src/components/common/UnknownValue.vue` (`—`, infobulle `common.unknownValue`), déjà utilisé par #218 et les coûts inconnus.

## Règles métier

**R1 — Compétences inconnues d'un personnage.** Les compétences d'un personnage sont **inconnues** si et seulement si le cache n'a **aucune ligne** `CachedCharacterSkill` pour lui (manuelle ou synchronisée). C'est la règle #218, sans nouveau marqueur. Un personnage qui a au moins une ligne a des compétences **connues** : une compétence absente y vaut le niveau 0 (l'ESI ne renvoie que les compétences entraînées et la sync ne crée de ligne qu'au niveau > 0).

**R2 — Lecture.** `loadCharacterSkillLevels` rend `skills: null` pour un personnage aux compétences inconnues, `array<skillId, level>` sinon (niveaux 0 compris). Aucune requête en plus : un `findAllSkillsForCharacter` vide suffit.

**R3 — Personnage retenu (fabrication, réaction).** Parmi les personnages de l'utilisateur aux compétences **connues**, celui au multiplicateur le plus faible ; à égalité, le premier dans l'ordre des personnages (règle actuelle). Un personnage aux compétences inconnues n'est jamais retenu et n'empêche pas d'en retenir un autre (même choix que la décision D-5 de #275).

**R4 — Compétences inconnues pour tous les personnages.** L'utilisateur a au moins un personnage et aucun n'a de compétences connues : pour toute étape de fabrication ou de réaction, la **durée par run est inconnue** (`null`) et `recommendedCharacterName` est `null`. Jamais un niveau 0, jamais un multiplicateur 1 par défaut.

**R5 — Niveaux à 0.** Un personnage connu dont les compétences de l'activité valent 0 (ligne au niveau 0, ou compétence absente d'un personnage connu) a le multiplicateur 1 : durée par run sans réduction par les compétences.

**R6 — Inchangé.** Utilisateur sans personnage : multiplicateur 1, `recommendedCharacterName` `null` (R6 de #275, ce n'est pas une donnée manquante). Étape de copie : aucun personnage, multiplicateur 1 (la compétence de la copie est #275). Temps de base absent : `null` (#126).

**R7 — Contrat de `bestCharacterForStepTime`.** Retour `array{skills: array<int, int>|null, name: string|null, skillsUnknown: bool}`. `skillsUnknown` est vrai dans le seul cas R4. Copie et utilisateur sans personnage : `skillsUnknown` faux. Le paramètre `$characterSkillLevels` accepte la forme de R2. `timePerRunOf`, `calculateTimePerRun` et `calculateSkillTimeMultiplier` gardent leur signature : `$characterSkills === null` reste « sans personnage » (multiplicateur 1).

**R8 — Une seule décision, deux appelants.** `IndustryProjectFactory::addTimeDataToSteps` et `IndustryResourceMapper::stepToResource` donnent une durée par run `null` quand `skillsUnknown` est vrai, sans appeler `timePerRunOf` / `calculateTimePerRun`. Affichage et génération restent égaux (R2 de #126/#134).

**R9 — Découpage.** Une durée par run inconnue ne découpe pas l'étape par la durée (règle `maxRunsPerJob` et glossaire « Durée maximale de job », inchangées) ; le plafond de runs d'un BPC s'applique toujours. À la création comme à la régénération des étapes.

**R10 — API.** Aucun champ ajouté : `timePerRun: null` et `recommendedCharacterName: null` portent l'état inconnu. Le motif (temps de base absent ou compétences inconnues) n'est pas exposé (hors périmètre).

**R11 — Front, ligne de durée d'une étape.** Étape ni achetée ni en stock, `timePerRun` `null` : la ligne de durée s'affiche (icône horloge) avec `UnknownValue` à la place de la durée, sans « /run ». Étape achetée ou en stock : pas de ligne (inchangé). Vaut aussi pour un temps de base absent du SDE (inconnu aussi).

**R12 — Front, total d'un groupe découpé.** Un job compté (ni acheté ni en stock) à durée inconnue rend le total du groupe inconnu : `UnknownValue` à la place de `-`. Aucun job compté : `-` (inchangé).

**R13 — Front, durée estimée du projet.** Une étape comptée (ni achetée ni en stock) à durée par run inconnue rend la durée estimée du projet **inconnue** (une somme ou un maximum qui contient un terme inconnu est inconnu, comme le **coût inconnu**). `ProjectDetail.vue` affiche alors `UnknownValue` aux deux emplacements du total (projet mono-produit et multi-produits). Aucune étape comptée : total masqué (inchangé). `useProjectTime` expose cet état à côté de `estimatedProjectTime` (nom suggéré : `isEstimatedProjectTimeUnknown`), `estimatedProjectTime` restant `null` quand il est inconnu.

## Exemples chiffrés (tests rouges)

Hypothèses communes : aucune structure, aucun système favori, aucune science du blueprint, TE 0, durée maximale de job 2 jours (172 800 s), plafond de runs d'un BPC au-dessus des runs (harnais actuel). « Inconnu » = personnage sans ligne en cache (`'Fresh' => []` dans `userWithCharacters`) ; « connu à 0 » = ligne au niveau 0 (`[CachedCharacterSkill::SKILL_INDUSTRY => 0]`).

### Lecture et personnage retenu (`IndustryCalculationTest`, groupe `issue-282`)

| # | Appel | Personnages (lignes en cache, dans l'ordre) | Attendu | Aujourd'hui |
|---|---|---|---|---|
| L1 | `loadCharacterSkillLevels` | `Fresh` : aucune ; `Zero` : Industry 0 ; `Main` : Industry 5, Advanced Industry 4 | `[['name' => 'Fresh', 'skills' => null], ['name' => 'Zero', 'skills' => [3380 => 0]], ['name' => 'Main', 'skills' => [3380 => 5, 3388 => 4]]]` | `Fresh` : `skills: []` |
| L2 | `loadCharacterSkillLevels` | `Manual` : Industry 4, `isManual` vrai | `[['name' => 'Manual', 'skills' => [3380 => 4]]]` (connu) | identique |
| B1 | `bestCharacterForStepTime(…, 'manufacturing', L)` | `Fresh` : `null` | `['skills' => null, 'name' => null, 'skillsUnknown' => true]` | `['skills' => [], 'name' => 'Fresh']` |
| B2 | idem | `Fresh` : `null` ; `Zero` : `[3380 => 0]` | `['skills' => [3380 => 0], 'name' => 'Zero', 'skillsUnknown' => false]` | `Fresh` retenu (premier à égalité) |
| B3 | idem | aucun personnage (`[]`) | `['skills' => null, 'name' => null, 'skillsUnknown' => false]` | sans la clé `skillsUnknown` |
| B4 | `bestCharacterForStepTime(…, 'copy', L)` | `Fresh` : `null` | `['skills' => null, 'name' => null, 'skillsUnknown' => false]` | sans la clé |
| B5 | `bestCharacterForStepTime(…, 'reaction', L)` | `Fresh1` : `null` ; `Fresh2` : `null` | `skillsUnknown` vrai, `name` `null` | `Fresh1` |
| T1 | `calculateTimePerRun(fabrication du Raven, [3380 => 0, 3388 => 0])` | — | 18 000 (R5) | 18 000 (non-régression) |

### Génération et affichage (`IndustryProjectFactoryTest`, forme `displayedJobsOf` : `[runs, totalGroupRuns, timePerRun, recommendedCharacterName]`, groupe `issue-282`)

| # | Étape | Personnages | Runs | Attendu | Aujourd'hui |
|---|---|---|---|---|---|
| G1 | Fabrication, base 10 000 | `Fresh` : aucune ligne | 18 | `[[18, null, null, null]]` (un job, R4 + R9) | `[[9, 18, 10000, 'Fresh'], [9, 18, 10000, 'Fresh']]` |
| G2 | idem | `Zero` : Industry 0, Advanced Industry 0 | 18 | `[[9, 18, 10000, 'Zero'], [9, 18, 10000, 'Zero']]` (180 000 > 172 800, `floor(172 800 / 10 000)` = 17) | identique (non-régression, R5) |
| G3 | idem | `Novice` : Mass Production 1 seule | 18 | `[[9, 18, 10000, 'Novice'], [9, 18, 10000, 'Novice']]` (connu, Industry absente = 0) | identique |
| G4 | idem | `Fresh` : aucune ; `Main` : Industry 5 | 18 | `[[18, null, 8000, 'Main']]` (144 000 ≤ 172 800) | identique (non-régression, R3) |
| G5 | idem | `Fresh` : aucune ; `Zero` : Industry 0 | 18 | `[[9, 18, 10000, 'Zero'], [9, 18, 10000, 'Zero']]` | personnage `'Fresh'` |
| G6 | idem | `Fresh1`, `Fresh2` : aucune ligne | 18 | `[[18, null, null, null]]` | `[[9, 18, 10000, 'Fresh1'], [9, 18, 10000, 'Fresh1']]` |
| G7 | idem | aucun personnage (`userWithCharacters([])`) | 18 | `[[9, 18, 10000, null], [9, 18, 10000, null]]` | identique (R6) |
| G8 | Réaction, base 3 600 | `Fresh` : aucune ligne | 50 | `[[50, null, null, null]]` | `[[25, 50, 3600, 'Fresh'], [25, 50, 3600, 'Fresh']]` |
| G9 | idem | `Industrialist` : Industry 5, Advanced Industry 5 (pas de Reactions) | 50 | `[[25, 50, 3600, 'Industrialist'], [25, 50, 3600, 'Industrialist']]` (180 000 > 172 800 ; Reactions absente = 0) | identique |
| G10 | Fabrication 19 000 + copie 14 100 (`hasCopy`) | `Fresh` : aucune ligne | 13 | fabrication `[[13, null, null, null]]` ; copie `[[13, null, 14100, null]]` | fabrication `[[7, 13, 19000, 'Fresh'], [6, 13, 19000, 'Fresh']]` (247 000 > 172 800, `floor` = 9) ; copie identique |
| G11 | Fabrication sans temps de base dans le SDE | `Fresh` : aucune ligne | 1 000 | `[[1000, null, null, null]]` | `[[1000, null, null, 'Fresh']]` |

G1, G5, G6, G8, G10 et G11 sont rouges ; G2, G3, G4, G7, G9 sont des garde-fous. G10 se range dans le fournisseur `generatedJobsOfTwoProjectsAndTheirDisplayedTimePerRun` ou dans un test dédié, au choix du rédacteur des tests.

### Front (Vitest)

| # | Écran | Données | Attendu | Aujourd'hui |
|---|---|---|---|---|
| F1 | `StepTree`, étape | non achetée, pas en stock, `timePerRun: null`, 18 runs | ligne de durée visible, `—` avec l'infobulle `common.unknownValue`, pas de « /run » | ligne absente |
| F2 | `StepTree`, étape | achetée, `timePerRun: null` | pas de ligne de durée | identique |
| F3 | `StepTree`, groupe découpé | 2 jobs non achetés, `timePerRun: null` | en-tête du groupe : `—` (`UnknownValue`) | `-` |
| F4 | `useProjectTime` | profondeur 0 : 9 000 s × 2 runs ; profondeur 1 : `timePerRun: null`, ni achetée ni en stock | durée estimée inconnue (`estimatedProjectTime` `null`, état inconnu vrai) ; `ProjectDetail` affiche `—` | 18 000 s, « 5h 0m » |
| F5 | `useProjectTime` | même projet, étape de profondeur 1 en stock | 18 000 s, « 5h 0m », état inconnu faux | identique |
| F6 | `useProjectTime` | toutes les étapes achetées | `null`, état inconnu faux, total masqué | identique |

Fichiers de test suggérés : `frontend/tests/composables/useProjectTime.spec.ts` (nouveau, F4 à F6), `frontend/tests/components/industry/stepTreeUnknownTimePerRun.spec.ts` (nouveau, F1 à F3).

## Cas limites

- **Personnage synchronisé dont aucune compétence de la liste n'est entraînée** : la sync ne crée aucune ligne, il reste « inconnu » (même faux inconnu que le Slot Tracker #218). Il disparaît avec #275 R7 (la sync écrira toujours la ligne Science, niveau 0 compris), qui fait de tout personnage synchronisé un personnage connu. `[TO CONFIRM: un personnage neuf a-t-il au moins une compétence de la liste synchronisée entraînée au départ ?]` ; sans effet sur les règles.
- **Personnage aux compétences saisies à la main seulement** : connu (R1). Les sciences du blueprint, jamais saisies à la main, y valent 0 : la durée affichée peut être un peu plus longue que la vraie (au plus 1 %/niveau par science). Accepté (D-4).
- **Compétences périmées** (token qui a perdu son scope après une sync) : les lignes restent, le personnage reste connu avec ses niveaux de la dernière sync. Hors périmètre, comme pour #218.
- **Régénération d'un projet existant** pour un utilisateur aux compétences inconnues : les groupes découpés par la durée sont fusionnés en un job à la régénération suivante (runs, ME, TE, durée maximale, commandes `app:industry:regenerate-steps` / `app:industry:regenerate-multi-unit-steps`). Les groupes découpés par le plafond de runs d'un BPC restent découpés. Les runs fixés (#185) sont gardés.
- **Personnage inconnu listé avant des personnages connus à multiplicateur égal** (G5) : la durée ne change pas, le nom recommandé passe au premier personnage connu.
- **Copie** : inchangée dans ce lot (B4, G10). Quand #275 passera, sa règle R5 (Science inconnue pour tous → `null`) suivra le même contrat `skillsUnknown`.
- **Durée estimée du projet avec une copie connue et une fabrication inconnue** (G10 côté front) : inconnue (R13), et non plus la seule durée de la copie.

## Tests existants appelés à casser

- **`tests/Unit/Service/Industry/JobSplitCalculationTest.php`** : le pilote `Industrialist` a par défaut `NO_SKILL = []`, soit aucune ligne, donc des compétences inconnues ; plus de découpage par la durée. Cassent : `testCapitalJumpDriveAtTeTwentyWithoutSkillsIsSplitIntoThreeJobsOfNineRuns`, les cas `'TE 0 without skills…'` et `'TE 20 without skills…'` de `capitalJumpDriveSplitsAtOneDayByTeAndSkills`, `testOneRunMoreThanTheMaximumDurationSplitsTheCapitalJumpDriveIntoTwoJobs`, `testMaximumDurationShorterThanOneRunGivesOneJobPerRun`, le cas `'without skills…'` de `reinforcedCarbonFiberOfACapitalJumpDriveSplitsBySkills`, `testTimeBonusOfATataraShortensTheReactionJobsOfTheSplit`. Restent verts par accident (attendent un job non découpé) : `testCapitalJumpDriveExactlyAsLongAsTheMaximumDurationIsNotSplit`, `testOneRunLongerThanTheMaximumDurationStaysOneUnsplitJob`. Réécriture du harnais seul, **aucune valeur attendue ne change** : `NO_SKILL` devient `SKILLS_AT_LEVEL_ZERO = [SKILL_INDUSTRY => 0, SKILL_ADVANCED_INDUSTRY => 0, SKILL_REACTIONS => 0]` (personnage connu, multiplicateur 1) ; « WithoutSkills » / « without skills » deviennent « WithSkillsAtLevelZero » / « skills at level 0 » dans les noms et libellés.
- **`tests/Integration/Service/Industry/IndustryProjectFactoryRegenerationTest.php`** et **`tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest.php`** : le personnage du pilote (`createCharacter`) n'a aucune ligne en cache ; tous les découpages par la durée disparaissent. Cassent au moins : `testRaisingTheMaximumJobDurationMergesTheSecondJobOfTheRootIntoTheFirst`, `testLoweringTheMaximumJobDurationKeepsTheRootStepAsTheFirstJobAndCreatesTheSecond`, `testChangingTheRunsOfASplitRootKeepsEachJobMatchOnTheJobOfTheSameSplitIndex`, `testMergingTheSecondJobIntoAPurchasedFirstJobKeepsItPurchased`, `testMergingTheSecondJobOfTheComponentGivesItsJobMatchPurchaseAndStockToTheComponentNotToTheRoot`, `testRegenerationSplitsTheHullComponentWithItsTe`, `testASecondJobCreatedByLoweringTheMaximumJobDurationTakesTheMeTeAndStructureOfTheFirstJob`, `testChangingTheRunsKeepsTheMeTeAndStructureOfEachJobOfASplitHullComponent`, `testRegenerationSplitsTheHullComponentWithTheTimeBonusOfItsStructureRatherThanTheBestOne` ; et dans `UpdateStepFixedRunsTest` tout test qui part d'un groupe découpé par la durée (`testRegenerationKeepsTheSplitGroupOfAFixedJobWhenTheMaximumJobDurationIsRaised`, `testRunsEnteredOnAJobWhoseCapFallsBelowOneRunAreFixedAtOneRun`, les tests de groupe de fuel blocks, entre autres : lancer la classe entière). Réécriture du `setUp` seul : persister pour le personnage du pilote une ligne `CachedCharacterSkill` Industry niveau 0, `isManual` vrai (saisie manuelle d'un niveau 0, état réel possible), avant le `flush`. Aucune assertion ne change.
- **`tests/Unit/Service/Industry/IndustryProjectFactoryTest.php`** : aucun cassé. `testSplitUsesTheBestCharacterOfTheUser` (`'Alt' => []`) reste vert, `Alt` passant de « niveau 0 » à « inconnu » sans changer le personnage retenu (`Main`). Les cas à `[]` du fournisseur et `userWithCharacters([])` n'ont aucun personnage (R6).
- **Goldens** (`IndustryProjectFactoryGoldenStepsCharacterizationTest`, `IndustryProjectFactoryCopyStepsTest`, `IndustryProjectFactoryBpcMaxRunsTest`, `IndustryProjectFactoryRegenerationOnGoldenSdeTest`, `UpdateStepProcessorTest`) : utilisateurs sans personnage (`new User()`), inchangés.
- **`IndustryCalculationTest`** : `calculateTimePerRun` reçoit des tableaux explicites, inchangé.
- **Front** : aucun test n'affirme l'absence de la ligne de durée ni ne prend d'instantané ; les fixtures à `timePerRun: null` (`stepsTab*.spec.ts`, `shoppingTab*.spec.ts`, `timeEfficiencyInputStep.spec.ts`…) affichent désormais une ligne `—`. À vérifier en phase rouge.

## Fichiers à modifier (phase verte)

| Fichier | Changement |
|---|---|
| `src/Service/Industry/IndustryCalculationService.php` | `loadCharacterSkillLevels` (R2), `bestCharacterForStepTime` (R3, R4, R7), types PHPDoc des deux méthodes |
| `src/Service/Industry/IndustryProjectFactory.php` | `addTimeDataToSteps` : `null` si `skillsUnknown` (R8) |
| `src/State/Provider/Industry/IndustryResourceMapper.php` | `stepToResource` : `timePerRun` `null` si `skillsUnknown` (R8) |
| `frontend/src/components/industry/StepTree.vue` | R11, R12 (`UnknownValue`) |
| `frontend/src/composables/useProjectTime.ts` | R13 (état inconnu) |
| `frontend/src/components/industry/ProjectDetail.vue` | R13, deux emplacements du total |
| `docs/glossary.md`, `docs/modules/industry.md` | voir « Termes du glossaire » |
| `docs/specs/283-extract-step-duration-service.md` | inventaire à refaire : nouvelle clé `skillsUnknown`, nouveaux tests `issue-282` à migrer avec les autres |

Aucune migration, aucune commande à lancer en production.

## Hors périmètre

- **#275** : compétence Science sur la copie, ligne Science toujours écrite par la sync (D-3, R7 de #275), durée de copie inconnue.
- **#283** : extraction de `TimePerRunCalculator` ; cette spec s'applique avant, dans `IndustryCalculationService`.
- **#276** : bonus de structure propre à la copie.
- Motif de l'inconnu dans l'API (`timePerRunUnknownReason`) et lien « synchroniser les compétences » depuis la ligne de durée.
- Sync automatique des compétences ; détection de compétences périmées.
- Saisie manuelle des sciences du blueprint.
- Noyau : `Domain\SkillTimeModifier`, `Domain\JobDuration` restent sans appelant.

## Décisions prises en autonomie

- **D-1 — « Non synchronisées » = aucune ligne en cache** (R1), la règle #218, et non un marqueur de sync (colonne `skillsSyncedAt` sur `Character`, ou ligne sentinelle). Raison : pas de migration, un seul sens de « compétences inconnues » dans l'application, et #275 D-3 (ligne Science toujours écrite) supprime le faux inconnu restant. Alternative écartée : connu compétence par compétence (exiger la ligne Industry, Advanced Industry…) : la sync ne crée pas de ligne au niveau 0, un personnage Industry 5 sans Advanced Industry serait inconnu à tort.
- **D-2 — Le `?? 0` de `calculateSkillTimeMultiplier` reste.** Après le lot, la méthode ne reçoit que les niveaux d'un personnage connu, pour qui une compétence absente vaut réellement 0 (R1, #218). Le défaut interdit portait sur un personnage sans données ; il est traité en amont (R2, R4).
- **D-3 — Le signal passe par `skillsUnknown`** dans le retour de `bestCharacterForStepTime`, et les deux appelants rendent `null` (R8). Les signatures de `timePerRunOf` / `calculateTimePerRun` ne changent pas : les 30 cas de durée de `IndustryCalculationTest` restent intacts. Nom aligné sur `SlotTrackerService` (`skillsUnknown`) et le glossaire « Compétences inconnues ». Alternative écartée : passer le retour de `bestCharacterForStepTime` à `timePerRunOf` (réécrit tous les appels de test) ; #283 pourra replier la décision dans le nouveau service.
- **D-4 — Un personnage aux seules lignes manuelles est connu**, sciences du blueprint à 0. Le rendre inconnu casserait l'usage de la saisie manuelle (personnage sans scope des compétences), qui sert justement à obtenir une durée.
- **D-5 — Inconnu seulement si aucun personnage n'est connu** (R3, R4), même choix que D-5 de #275.
- **D-6 — Durée inconnue : pas de découpage par la durée** (R9), règle existante d'une durée `null`. Alternative écartée : découper sur la durée sans compétences (borne haute, multiplicateur 1) tout en affichant `null` : chaque job tiendrait sous la durée maximale quelles que soient les compétences, mais le découpage reposerait sur une valeur non affichée, ce que #134 a supprimé. Voir « À signaler ».
- **D-7 — Front inclus** (R11 à R13), parce que l'issue demande l'état inconnu « côté API et front » et que le front masque aujourd'hui une durée `null` sans signal. Réutilise `UnknownValue` (pattern #218, coûts inconnus) : pas de nouvel élément visuel, donc pas de maquette.
- **D-8 — Total du projet inconnu, pas « partiel »** (R13) : même règle que le **coût inconnu** (une somme qui contient un terme inconnu est inconnue). Tranche du même coup le point A2 de #275 dans ce sens ; un total partiel reste possible dans une issue dédiée.

## À signaler à Sylvain (effets en production)

- **Utilisateurs dont aucun personnage n'a de compétence en cache** (compétences jamais synchronisées, ni saisies) : toutes les étapes de fabrication et de réaction affichent `—` au lieu de la durée sans compétences, plus de personnage recommandé, durée estimée du projet `—`. Note de version conseillée : « synchronisez vos compétences pour voir les durées ».
- **Même population, découpage** : à la prochaine régénération (changement de runs, ME, TE, durée maximale, ou commande `app:industry:regenerate-steps` / `app:industry:regenerate-multi-unit-steps`), les groupes découpés par la durée sont fusionnés en un job. Si ce n'est pas voulu : alternative D-6 (borne haute).
- **Projets avec une étape sans temps de base dans le SDE** : la ligne `—` apparaît et la durée estimée du projet passe à `—` (elle ignorait l'étape).
- **Personnage inconnu listé avant un personnage connu à multiplicateur égal** : le nom recommandé change, pas la durée.

## Termes du glossaire (à appliquer en phase verte, après validation)

- **Compétences inconnues** : étendre la définition : un personnage aux compétences inconnues n'est jamais retenu pour la durée par run ; si aucun personnage de l'utilisateur n'est connu, la durée par run d'une fabrication ou d'une réaction est inconnue (spec #282). Symboles : ajouter `IndustryCalculationService::loadCharacterSkillLevels()` (`skills` `null`), `bestCharacterForStepTime()` (`skillsUnknown`).
- **Durée par run** : « Inconnue (`null`) sans temps de base, ou quand aucun personnage de l'utilisateur n'a de compétences connues (fabrication, réaction, spec #282). »
- `docs/modules/industry.md` : ligne `IndustryCalculationService` (`bestCharacterForStepTime` : personnages aux compétences inconnues ignorés, `skillsUnknown`) et ligne « Durée de job, compétences ».
