# Spec #275 — Science et Advanced Industry sur la durée par run d'une étape de copie

**Statut : prête pour la phase rouge.** Rédigée le 2026-10-29 dans la spec commune `docs/specs/277-275-copy-science-and-duration-rounding.md` (mode nuit), reprise ici le 2026-10-30 après les décisions de Sylvain (D-3, A1, A3, voir « Décisions de Sylvain ») et la fusion de #277 (arrondi), #282 (compétences inconnues) et #283 (`TimePerRunCalculator`). État du code vérifié sur `fix/group-production-tree` au commit `fc354f30`. Aucun point n'est à trancher.

La numérotation R2 à R8 reprend celle de la spec commune (la spec #277 cite R5 dans son exemple A5) ; R1 (arrondi) est la règle de #277, fusionnée, inchangée.

## Contexte

État vérifié dans le code au 2026-10-30 :

| Élément | État actuel | Fichier |
|---|---|---|
| Décision durée par run + personnage recommandé | une seule méthode, `timePerRunAndRecommendedCharacterOf` (l. 36-45) : `skillsUnknown` vrai → durée `null` sans calcul ; sinon `timePerRunOf(…, skills du personnage retenu)` ; `recommendedCharacterName` = nom du personnage retenu dans tous les cas. Appelants : génération (`IndustryProjectFactory::addTimeDataToSteps`, l. 452-468, compétences lues une fois par `loadCharacterSkillLevels`) et affichage (`IndustryResourceMapper::stepToResource`, l. 249-258, lecture par étape) | `src/Service/Industry/TimePerRunCalculator.php` |
| Personnage d'une copie | `bestCharacterForStepTime` l. 124-126 : `copy` → `['skills' => null, 'name' => null, 'skillsUnknown' => false]`. Aucune compétence, multiplicateur 1, aucun personnage recommandé, **jamais inconnue par les compétences**. C'est ce que #275 change | `TimePerRunCalculator::bestCharacterForStepTime` |
| Multiplicateur de compétence | `calculateSkillTimeMultiplier` l. 162-184 : `reaction` → Reactions 4 %/niv. ; **tout le reste, copie comprise** → Industry 4 %/niv. × Advanced Industry 3 %/niv. ; puis sciences du blueprint 1 %/niv. Niveaux absents lus `?? 0`. Un appel direct `calculateTimePerRun($copyStep, $skills)` applique donc aujourd'hui Industry et Advanced Industry à la copie (Raven, Advanced Industry 5 → 12 240) ; jamais atteint par l'application, puisque la copie reçoit `skills` `null` | `TimePerRunCalculator::calculateSkillTimeMultiplier` |
| Sciences du blueprint | `getBlueprintScienceSkillIds` l. 192-223 : `copy` lu comme fabrication (`default`), hors `INDUSTRY_SKILL_IDS` | idem |
| Temps de base | `baseTimePerRunOf` : `copy` → activité Copying (5) ; absent → durée `null` | idem |
| Arrondi | `ceil(round(produit, 6))` (l. 97), #277 fusionnée | idem |
| Compétences inconnues (#282) | `loadCharacterSkillLevels` l. 103-116 : `skills` `null` pour un personnage sans aucune ligne en cache. `bestCharacterForStepTime` ignore ces personnages ; `skillsUnknown` vrai seulement si l'utilisateur a des personnages et qu'aucun n'est connu | idem |
| Constantes | `SKILL_INDUSTRY` 3380, `SKILL_ADVANCED_INDUSTRY` 3388, `SKILL_REACTIONS` 45746, `INDUSTRY_SKILL_IDS` (les trois), `SLOT_SKILL_IDS` (six), bonus 0,04 / 0,03 / 0,04. **Pas de Science (3402)**. `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL` (0,01) est le bonus des **sciences du blueprint**, pas celui de la compétence Science | `src/Entity/CachedCharacterSkill.php` |
| Sync des compétences | `SyncCharacterSkillsProcessor::syncCharacterSkills` (l. 88-147) : `/characters/{id}/skills/`, niveau `active_skill_level` sinon `trained_skill_level`. Liste : `getManufacturingRelevantSkillIds` (l. 155-182) = compétences SDE des activités 1 et 11 + `INDUSTRY_SKILL_IDS` + `SLOT_SKILL_IDS`. Ligne **créée seulement au niveau > 0** (l. 135) ; ligne existante non manuelle mise à jour, niveau 0 compris (l. 129-134) ; ligne manuelle jamais écrasée. Sans token ou sans scope `esi-skills.read_skills.v1` : rien n'est écrit (l. 60-63) | `src/State/Processor/Industry/SyncCharacterSkillsProcessor.php` |
| Saisie manuelle | `UpdateCharacterSkillInput` : `industry`, `advancedIndustry`, `reactions` (0 à 5) ; `UpdateCharacterSkillProcessor` écrit `isManual` vrai. Pas de Science | `src/ApiResource/Input/Industry/UpdateCharacterSkillInput.php` |
| Écran des compétences | `SkillsConfig.vue` : Industry, Adv. Industry, Reactions, source, dernière sync. `characterSkillsToResource` : `source` `esi` dès qu'une ligne n'est pas manuelle, `none` sans ligne (affiché « Manuel » par le front), `lastSyncAt` = `cachedAt` le plus récent | `frontend/src/components/industry/SkillsConfig.vue`, `IndustryResourceMapper::characterSkillsToResource` |
| Slot Tracker (#218) | connu = au moins une ligne en cache (`CachedCharacterSkillRepository::hasCachedSkills`) ; compétence de slot absente = niveau 0 (`calculateMaxSlots`) | `SlotTrackerService::getSlotStatus` |
| Découpage | une étape de copie n'est jamais découpée par la durée : `planStepsJobByJob` (l. 491-494) la met de côté, `withCopyStepsFollowingManufacturing` (l. 522-540) lui donne les runs de la fabrication du blueprint et la découpe sous le plafond de runs du BPC seulement | `IndustryProjectFactory` |
| Front | `StepTree.vue` affiche `recommendedCharacterName` sur toute étape et `UnknownValue` pour une durée par run `null` (`isStepWithUnknownTimePerRun`) ; `useProjectTime` rend la durée estimée du projet **inconnue** dès qu'une étape ni achetée ni en stock a une durée par run `null` (#282, R13) | `frontend/src/components/industry/StepTree.vue`, `frontend/src/composables/useProjectTime.ts` |

Données de jeu (ESI `/universe/types/3402/`, lue le 2026-10-29) : **Science** (3402), « 5% Bonus to blueprint copying speed per level », attribut 452 = −5. **Advanced Industry** (3388) : « 3 % reduction in all manufacturing and research times », attribut 1961 = −3 ; application à la copie décidée par Sylvain (A1). **Industry** (3380) : fabrication seule.

SDE (copie locale `var/sde/blueprints.jsonl` du dépôt principal, lue le 2026-10-30, lecture seule) : Science 3402 est une compétence requise de la **fabrication d'un seul blueprint (57516)**, et de la copie de 95 blueprints (sans effet sur la durée). Elle fait donc partie de la liste synchronisée aujourd'hui (activité 1) : un personnage synchronisé avant ce lot avec Science ≥ 1 a déjà une ligne Science non manuelle. `[TO CONFIRM: la table sde_industry_activity_skills de prod contient la ligne (type_id 57516, activity_id 1, skill_id 3402)]`. Blueprint Raven (688) : copie 14 400 s, fabrication 18 000 s.

## Décisions de Sylvain (2026-10-30)

- **D-3 retenue** : la sync des compétences écrit toujours une ligne Science, niveau 0 compris. La présence de la ligne est le marqueur « niveau Science connu » (R3, R7). Pas de colonne ni de migration.
- **A1 : Advanced Industry s'applique aussi à la copie.** Multiplicateur de copie `(1 − 0,05 × Science) × (1 − 0,03 × Advanced Industry)`. Raven sans structure, Science 5 + Advanced Industry 5 → **9 180** (exemple de l'issue).
- **A3 acceptée** : après le déploiement, la durée de copie des personnages déjà synchronisés sans ligne Science passe à « inconnue » jusqu'à une nouvelle sync des compétences. Note de version à prévoir (voir « Risques de production »).

## Règles métier

**R2 — Multiplicateur de compétence d'une copie.**

```
multiplicateur de compétence (copie) = (1 − 0,05 × niveau Science) × (1 − 0,03 × niveau Advanced Industry)
```

Science = typeId 3402, 5 % par niveau : constantes nommées dans `CachedCharacterSkill` (`SKILL_SCIENCE`, `SCIENCE_COPY_TIME_BONUS_PER_LEVEL`, distincte de `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL`). Advanced Industry : constante existante `ADVANCED_INDUSTRY_TIME_BONUS_PER_LEVEL` (0,03). Pour une copie, ni Industry, ni Reactions, ni les sciences du blueprint (`getBlueprintScienceSkillIds` n'est pas appelée pour `copy`). La fabrication et la réaction ne changent pas : Science ne s'y applique que comme science du blueprint (seul cas SDE : fabrication de 57516, 1 %/niv.), règle actuelle. `SKILL_SCIENCE` n'entre **pas** dans `INDUSTRY_SKILL_IDS` (elle cesserait d'être une science du blueprint 57516 et sa durée de fabrication changerait).

**R3 — Niveau Science connu.** Le niveau Science d'un personnage est connu si et seulement si le cache a une ligne Science pour lui, niveau 0 compris. Sans ligne, il est **inconnu** : personnage jamais synchronisé, synchronisé avant ce lot sans Science entraînée, sans token ou sans scope des compétences, ou aux compétences saisies à la main seulement. Pour un personnage au niveau Science connu, le niveau Advanced Industry est sa ligne si elle existe (manuelle ou ESI), sinon 0 : c'est une valeur vraie, pas un défaut, parce qu'une ligne Science ne vient que d'une sync ESI et que cette sync lit toujours Advanced Industry (`INDUSTRY_SKILL_IDS`) et crée sa ligne dès le niveau 1.

**R4 — Personnage retenu pour une copie.** Chaque étape de copie retient son propre personnage, indépendamment de l'étape de fabrication du même blueprint : parmi les personnages de l'utilisateur au niveau Science connu, celui au multiplicateur de copie (R2) le plus faible ; à égalité, le premier dans l'ordre des personnages de l'utilisateur (règle actuelle de `bestCharacterForStepTime`). Les personnages aux compétences inconnues (`skills` `null`) et ceux dont les compétences sont connues sans ligne Science sont ignorés. `recommendedCharacterName` de l'étape de copie porte son nom. Les jobs d'un groupe découpé de copie (plafond de runs d'un BPC, spec #235) retiennent tous le même personnage et la même durée par run.

**R5 — Science inconnue pour tous les personnages.** L'utilisateur a au moins un personnage et aucun n'a de niveau Science connu : `bestCharacterForStepTime(…, 'copy', …)` rend `['skills' => null, 'name' => null, 'skillsUnknown' => true]` et `timePerRunAndRecommendedCharacterOf` rend `['timePerRun' => null, 'recommendedCharacterName' => null]` (contrat existant #282/#283, aucune nouvelle branche dans la factory ni le mapper). Jamais un niveau 0, jamais un multiplicateur 1 par défaut. Aucun effet sur le découpage : une copie n'est jamais découpée par la durée.

**R6 — Utilisateur sans personnage.** Aucun personnage : multiplicateur 1, `recommendedCharacterName` `null`, `skillsUnknown` faux, règle de toutes les activités, inchangée. `calculateTimePerRun($copyStep)` sans compétences (`null`) garde le même sens : temps de base × structure.

**R7 — Sync des compétences.** Science (3402) est toujours dans la liste synchronisée (comme `INDUSTRY_SKILL_IDS` et `SLOT_SKILL_IDS`, sans dépendre du SDE) et sa ligne est **toujours** enregistrée, niveau 0 compris, non manuelle : Science absente de la réponse ESI = niveau 0 connu. Ligne Science existante non manuelle : mise à jour (niveau 0 compris), règle actuelle. Les autres compétences gardent la règle actuelle (ligne créée seulement au niveau > 0). Personnage sans token, sans scope, ou dont l'appel ESI échoue : rien n'est écrit, son niveau Science reste ce qu'il était (inconnu, ou la valeur de la dernière sync). Aucune saisie manuelle de Science (hors périmètre).

**R8 — Structure de la copie inchangée.** Le bonus de temps de structure appliqué à la copie reste celui d'aujourd'hui (`structureBonusOf(…, 'copy', …)`, #276 hors périmètre). Formule complète, TE d'une copie 0, arrondi R1 de #277 :

```
durée par run (copie) = ceil(round(temps de base de copie × (1 − bonus de temps de la structure/100) × (1 − 0,05 × Science) × (1 − 0,03 × Advanced Industry), 6))
```

**R9 — Appel direct sans niveau Science.** `timePerRunOf` / `calculateTimePerRun` d'une copie avec des compétences non `null` sans ligne Science rend `null` (niveau inconnu), jamais un Science 0 par défaut. `timePerRunAndRecommendedCharacterOf` ne passe jamais par ce cas (R4 ne retient qu'un personnage au Science connu) ; la règle ferme le contrat public, qui applique aujourd'hui Industry et Advanced Industry à la copie.

**R10 — Copie sans temps de base.** Blueprint sans activité de copie dans le SDE : durée par run `null`, personnage recommandé quand même s'il en existe un (même contrat que la fabrication, D5 de #283).

## Exemples chiffrés (tests rouges)

Hypothèses communes sauf mention : aucune structure, aucun système favori, aucune science du blueprint, TE 0, durée maximale de job 2 jours (172 800 s). Valeurs recalculées en IEEE avec `ceil(round(x, 6))`. « Science 0 » = ligne présente au niveau 0 ; « sans Science » = pas de ligne Science.

### Durée par run et personnage recommandé (`TimePerRunCalculatorTest`)

Entrée : `timePerRunAndRecommendedCharacterOf($user, RAVEN_BLUEPRINT, RAVEN, 'copy', 0, null)` sur le harnais `userWithCachedSkills` (lecture du cache par `loadCharacterSkillLevels`). Assertion `['timePerRun' => …, 'recommendedCharacterName' => …]`.

| # | Personnages (lignes en cache, dans l'ordre) | Attendu | Aujourd'hui |
|---|---|---|---|
| C1 | `Main` : Science 0 | 14 400, `Main` | 14 400, `null` |
| C2 | `Main` : Science 5 | **10 800** (× 0,75) | 14 400, `null` |
| C3 | `Main` : Science 3 | **12 240** (× 0,85) | 14 400, `null` |
| C4 | `Main` : Science 5, Advanced Industry 5 | **9 180** (× 0,75 × 0,85, exemple de l'issue) | 14 400, `null` |
| C5 | `Main` : Science 0, Advanced Industry 5 | **12 240** (× 0,85 : niveau 0 connu, Advanced Industry s'applique) | 14 400, `null` |
| C6 | `Main` : Science 5, Industry 5, Advanced Industry 5 | **9 180** (Industry ne s'applique pas, R2) | 14 400, `null` |
| C7 | `Main` : Science 4, Advanced Industry 3 | **10 484** (`10 483,2`, vraie fraction arrondie au plafond) | 14 400, `null` |
| C8 | `Alt` : Science 5 ; `Main` : Science 4, Advanced Industry 5 | **9 792**, `Main` (0,68 < 0,75 : Advanced Industry départage) | 14 400, `null` |
| C9 | `Twin1` : Science 5, Advanced Industry 5 ; `Twin2` : idem | 9 180, `Twin1` (premier à égalité) | 14 400, `null` |
| C10 | `Old` : Industry 5, Advanced Industry 5, sans Science ; `New` : Science 3 | 12 240, `New` (seul Science connu, R4) | 14 400, `null` |
| C11 | `Fresh` : aucune ligne ; `Main` : Science 5 | 10 800, `Main` | 14 400, `null` |
| C12 | `Old` : Industry 5, Advanced Industry 5, sans Science | **`null`, `null`** (R5) | 14 400, `null` |
| C13 | `Fresh` : aucune ligne | **`null`, `null`** (R5) | 14 400, `null` (test #283 D3, à réécrire) |
| C14 | aucun personnage | 14 400, `null` (R6) | 14 400, `null` (non-régression) |
| C15 | `Main` : Science 5 ; copie de `CAPITAL_JUMP_DRIVE_BLUEPRINT` (pas de temps de copie) | `null`, `Main` (R10) | `null`, `null` |
| C16 | `Main` : Science 5 seule ; **fabrication** du Raven | 18 000, `Main` (Science ne s'applique pas à la fabrication) | 18 000, `Main` (non-régression) |

### Contrat de `bestCharacterForStepTime` et appel direct

| # | Appel | Attendu | Aujourd'hui |
|---|---|---|---|
| B1 | `bestCharacterForStepTime($user, RAVEN_BLUEPRINT, 'copy', loadCharacterSkillLevels($user))`, `Old` : Industry 5, Advanced Industry 5, sans Science | `['skills' => null, 'name' => null, 'skillsUnknown' => true]` | `skillsUnknown` faux |
| B2 | idem, `Fresh` : aucune ligne | `['skills' => null, 'name' => null, 'skillsUnknown' => true]` | `skillsUnknown` faux (test #282, à réécrire) |
| B3 | idem, `Main` : Science 5, Advanced Industry 5 | `['skills' => [3402 => 5, 3388 => 5], 'name' => 'Main', 'skillsUnknown' => false]` | `skills` `null`, `name` `null` |
| T1 | `calculateTimePerRun(copie du Raven, [3388 => 5])` | `null` (R9) | 12 240 |
| T2 | `calculateTimePerRun(copie du Raven, [3402 => 5, 3380 => 5])` | 10 800 (Industry ignorée) | 11 520 |
| T3 | `calculateTimePerRun(copie du Raven)` (`null`) | 14 400 (R6) | 14 400 (non-régression, `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint`) |

### Génération et affichage (`IndustryProjectFactoryTest`, `displayedJobsOf`)

Forme d'une ligne : `[runs, totalGroupRuns, durée par run affichée, personnage recommandé]`. Temps de base 19 000 (fabrication) et 14 100 (copie), `hasCopy` vrai.

| # | Personnages | Projet | Fabrication | Copie | Aujourd'hui (copie) |
|---|---|---|---|---|---|
| G1 | `Industrialist` : Industry 5, Advanced Industry 5, sans Science | 13 runs ; 14 runs | `[[13, null, 12920, 'Industrialist']]` ; `[[7, 14, 12920, 'Industrialist'], [7, 14, 12920, 'Industrialist']]` (inchangé) | **`[[13, null, null, null]]`** ; **`[[14, null, null, null]]`** | 14 100, `null` (cas existant à réécrire) |
| G2 | `Industrialist` : Industry 5, Advanced Industry 5, Science 5 | 13 runs ; 14 runs | identique à G1 | **`[[13, null, 8989, 'Industrialist']]`** ; **`[[14, null, 8989, 'Industrialist']]`** (`14 100 × 0,6375 = 8 988,75`) | 14 100, `null` |
| G3 | `Fresh` : aucune ligne | 13 runs | `[[13, null, null, null]]` (inchangé, #282) | **`[[13, null, null, null]]`** | 14 100, `null` (test existant à réécrire) |
| G4 | `Builder` : Industry 5, Advanced Industry 5, Science 0 ; `Copier` : Science 5 | 13 runs | `[[13, null, 12920, 'Builder']]` | **`[[13, null, 10575, 'Copier']]`** (0,75 < 0,85 : chaque étape retient son personnage, D-4) | 14 100, `null` |
| G5 | `Industrialist` : Industry 5, Advanced Industry 5, Science 5 ; fabrication 1 000 s, plafond de runs du BPC 10 (`bpcMaxRunsByBlueprintTypeId`) | 25 runs | `[[9, 25, 680, 'Industrialist'], [8, 25, 680, 'Industrialist'], [8, 25, 680, 'Industrialist']]` | **`[[9, 25, 8989, 'Industrialist'], [8, 25, 8989, 'Industrialist'], [8, 25, 8989, 'Industrialist']]`** (groupe découpé de copie : même durée, même personnage) | 14 100, `null` sur chaque job |

G1 et G2 se rangent dans le fournisseur `generatedJobsOfTwoProjectsAndTheirDisplayedTimePerRun` (harnais `userWithCharacters`, clé `CachedCharacterSkill::SKILL_SCIENCE`). Le harnais SDE de la factory ne déclare aucune science du blueprint (`fetchAllAssociative` → `[]`) : Science 5 ne change pas la fabrication.

### Sync des compétences (`SyncCharacterSkillsProcessorTest`)

| # | Réponse ESI | Lignes avant | Lignes après (écrites ou persistées) |
|---|---|---|---|
| S1 | Science `active_skill_level` 4 | aucune | Science 4, non manuelle |
| S2 | aucune compétence | aucune | **Science 0**, non manuelle ; aucune autre ligne (Industry, Advanced Industry, Reactions, slots gardent la règle « > 0 ») |
| S3 | Industry 5, pas de Science | Industry 5 (sync antérieure au lot) | Industry 5 mise à jour ; **Science 0** créée |
| S4 | Science 4 | Science 3 non manuelle | Science 4 (mise à jour, règle actuelle) |
| S5 | pas de Science | Science 2 non manuelle | Science 0 (mise à jour, règle actuelle) |
| S6 | personnage sans scope `esi-skills.read_skills.v1` | aucune | aucune (Science inconnue), aucun appel ESI |
| S7 | ESI 502 | aucune | aucune (échec nommé, règle #197) |

Harnais : le stub ESI rend toujours `['skills' => []]` et le `persist` du stub `EntityManagerInterface` ne capture rien. Les tests S1 à S7 demandent une réponse ESI par endpoint et la capture des lignes persistées (`[skillId, level, isManual]`) ; les lignes existantes passent par le stub `findBy` déjà en place.

## Cas limites

- **Personnages synchronisés avant le déploiement** : avec Science ≥ 1, ils ont déjà leur ligne Science (Science est dans la liste SDE, `[TO CONFIRM]` ci-dessus) et leur copie est connue dès le déploiement, au niveau de leur dernière sync. Sans Science entraînée (aucune ligne), leur copie devient inconnue jusqu'à la prochaine sync (A3). Jamais synchronisés ou sans scope : inconnus, comme pour la fabrication depuis #282.
- **Personnage aux compétences saisies à la main seulement** : niveau Science inconnu, sans moyen de le saisir (A4, hors périmètre). Un utilisateur dont tous les personnages sont dans ce cas garde des durées de copie inconnues ; un autre personnage synchronisé peut porter la copie (R4).
- **Advanced Industry manuelle sur un personnage synchronisé** : la ligne manuelle s'applique à la copie comme à la fabrication (R3).
- **Interaction avec #218 et #282** : un personnage synchronisé sans aucune compétence de la liste entraînée a désormais une ligne (Science 0). Il devient connu partout : Slot Tracker avec 1 slot par catégorie (règle #218), fabrication avec Industry 0 au lieu d'une durée inconnue (le « faux inconnu » signalé par la spec #282 disparaît). Écran des compétences : `source` `esi` et une date de dernière sync, au lieu de `none` (affiché « Manuel »).
- **Durée estimée du projet** : une étape de copie ni achetée ni en stock à durée inconnue la rend inconnue (R13 de #282). Une copie achetée ou en stock n'est pas comptée.
- **Découpage** : inchangé, une copie n'est découpée que par le plafond de runs du BPC ; aucune étape enregistrée ne change (la durée par run n'est pas persistée, elle est recalculée à l'affichage).
- **Niveau Science périmé** : la ligne garde le niveau de la dernière sync (pas de sync automatique), comme toutes les compétences.
- **Arrondi** : R1 de #277 s'applique à la copie (C7 : `10 483,2` → 10 484).
- **Science, science du blueprint 57516** : la fabrication de 57516 garde son bonus de 1 %/niv. (R2 : Science hors `INDUSTRY_SKILL_IDS`).

## Tests existants appelés à casser

| Test | Aujourd'hui | Après #275 | Réécriture |
|---|---|---|---|
| `TimePerRunCalculatorTest::testSkillsOfACopyStepAreNotUnknownEvenWhenTheOnlyCharacterHasNoCachedSkill` (#282, l. 362-371) | `skillsUnknown` faux | B2 : `skillsUnknown` vrai | renommer (ex. `testSkillsOfACopyStepAreUnknownWhenTheOnlyCharacterHasNoCachedSkill`), attendu `['skills' => null, 'name' => null, 'skillsUnknown' => true]` |
| `TimePerRunCalculatorTest::testCopyStepHasItsCopyingTimePerRunAndNoRecommendedCharacterEvenWithUnknownSkills` (#283 D3, l. 479-488) | `[14400, null]` | C13 : `[null, null]` | renommer (ex. `testCopyStepOfACharacterWithoutCachedSkillHasAnUnknownTimePerRunAndNoRecommendedCharacter`) |
| `IndustryProjectFactoryTest`, fournisseur `generatedJobsOfTwoProjectsAndTheirDisplayedTimePerRun`, cas `'copy of the blueprint, never split, without character and with its copying base time'` (l. 1020-1033) | copie `[[13, null, 14100, null]]` / `[[14, null, 14100, null]]` | G1 : copie `[[13, null, null, null]]` / `[[14, null, null, null]]` ; fabrication inchangée (12 920) | renommer (ex. `'copy of the blueprint, never split, with an unknown Science level'`) |
| `IndustryProjectFactoryTest::testManufacturingOfACharacterWithoutCachedSkillIsUnknownAndUnsplitWhileItsCopyKeepsItsCopyingTimePerRun` (#282, l. 1184-1199) | copie `[[13, null, 14100, null]]` | G3 : copie `[[13, null, null, null]]` | renommer (ex. `testManufacturingAndCopyOfACharacterWithoutCachedSkillAreUnknownAndUnsplit`) |

Les tests réécrits gardent leur groupe et prennent `#[Group('issue-275')]` ; les nouveaux tests aussi.

Restent verts sans changement :

- `TimePerRunCalculatorTest` : `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint` (T3), `testTimePerRunOfACopyStepIsUnknownWhenTheSdeHasNoCopyingTimeForTheBlueprint` (sans compétences), tous les cas de fabrication et de réaction (branche de copie séparée).
- `IndustryResourceMapperTest` : **aucun cas de copie** (ses deux tests de durée, #282, portent sur une fabrication) ; rien à réécrire. L'affichage de la copie est couvert par `displayedJobsOf` de la factory, qui passe par le mapper.
- `SyncCharacterSkillsProcessorTest` (9 tests, #197) : `persist` du stub sans effet et `findBy` statique, la ligne Science ajoutée ne change aucune assertion.
- `SlotTrackerServiceTest`, `SlotTrackerProviderTest` (doublent `hasCachedSkills`), `CachedCharacterSkillRepositoryTest`.
- Tests de caractérisation golden (`IndustryProjectFactoryCopyStepsTest`, `IndustryProjectFactoryBpcMaxRunsTest`, `…GoldenSteps…`, `ProjectProviderGoldenTreeCharacterizationTest`) : ne lisent pas `timePerRun`, et la copie n'est jamais découpée par la durée.
- Front : les fixtures fixent `timePerRun` à la main (ex. `useProjectTime.spec.ts` l. 144, copie 14 100), aucun calcul.

Mutation après la phase verte : `make infection FILTER=src/Service/Industry/TimePerRunCalculator.php`, puis `FILTER=src/State/Processor/Industry/SyncCharacterSkillsProcessor.php`.

## Hors périmètre

- **#276** : bonus de temps de structure propre à la copie (rigs de science, bonus de rôle) ; R8 garde le bonus actuel.
- **Saisie manuelle de Science** (A4) : `UpdateCharacterSkillInput`, `UpdateCharacterSkillProcessor` et `SkillsConfig.vue` restent à Industry, Advanced Industry, Reactions. Aucune colonne Science sur l'écran des compétences.
- **Sync automatique des compétences** (à la connexion ou planifiée) : alternative à A3 écartée.
- Lecture `?? 0` des compétences de fabrication et de réaction d'un personnage connu (règle #282) : inchangée.
- Noyau : `Domain\SkillTimeModifier` (pas de copie) et `Domain\JobDuration` restent sans appelant.
- Renommage de `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL` (sciences du blueprint).
- Frontend : aucun changement (personnage recommandé et `UnknownValue` déjà affichés sur toute étape, durée estimée inconnue déjà gérée par #282).

## Décisions

### Décisions de Sylvain (2026-10-30)

D-3, A1, A3 : voir plus haut. Elles remplacent D-2 de la spec commune (Science seule sur la copie) et ses points « À trancher » A1 et A3.

### Prises en autonomie (2026-10-29, maintenues)

- **D-4 — Meilleur personnage pour la copie**, pas celui de la fabrication : chaque étape retient son personnage pour son activité (G4).
- **D-5 — Inconnue seulement si aucun niveau Science n'est connu** : un personnage inconnu n'empêche pas de retenir un personnage connu (C10, C11). Alternative écartée : inconnue dès qu'un personnage est inconnu (masquerait la durée de tout utilisateur ayant un alt non synchronisé).
- **D-6 — Sans personnage, multiplicateur 1** (R6).
- **D-7 — Noms** : `SKILL_SCIENCE`, `SCIENCE_COPY_TIME_BONUS_PER_LEVEL` dans `CachedCharacterSkill`.

### Prises en autonomie (2026-10-30)

- **D-8 — Advanced Industry absente = 0 pour un personnage au Science connu** (R3) : la ligne Science prouve une sync ESI qui a lu Advanced Industry. Pas de marqueur propre à Advanced Industry.
- **D-9 — Appel direct sans Science → `null`** (R9), pour qu'aucun chemin ne lise un Science 0 par défaut (règle « pas de `?? 0` sur une donnée métier »).
- **D-10 — Tests d'entrée par `timePerRunAndRecommendedCharacterOf`** (C1 à C16) : la seule méthode qui décide durée et personnage depuis #283 ; `bestCharacterForStepTime` n'est testée que pour son contrat `skillsUnknown` (B1 à B3).

## Risques de production

- **Durées affichées** : toute copie d'un utilisateur dont un personnage a Science connue raccourcit (Science 5 + Advanced Industry 5 : −36,25 %, Raven 14 400 → 9 180) et affiche un personnage recommandé. Une copie dont aucun personnage n'a de ligne Science affiche une durée inconnue, et la **durée estimée de tout projet** qui contient une telle copie à faire devient inconnue (R13 de #282). Aucune donnée enregistrée ne change, aucune migration, aucune commande à lancer en prod.
- **Ampleur de A3** : limitée aux personnages synchronisés sans Science entraînée, si la ligne SDE (57516, 1, 3402) existe en prod. Mesure avant le déploiement (lecture seule) : `SELECT count(*) FROM characters c WHERE EXISTS (SELECT 1 FROM cached_character_skills s WHERE s.character_id = c.id) AND NOT EXISTS (SELECT 1 FROM cached_character_skills s WHERE s.character_id = c.id AND s.skill_id = 3402);`
- **Note de version** (A3) : les durées de copie tiennent compte de Science et d'Advanced Industry ; relancer la synchronisation des compétences depuis l'écran des compétences de l'Industrie, sans quoi la durée d'une copie et la durée estimée des projets avec copie peuvent s'afficher inconnues. `[TO CONFIRM: libellé exact de l'écran et du bouton de sync dans la note]`.

## Termes du glossaire (à mettre à jour en phase verte)

- **Durée par run** : ajouter le cas inconnu de la copie (aucun personnage au niveau Science connu, #275).
- **Personnage recommandé** : la copie a désormais un personnage recommandé (Science connue, multiplicateur de copie le plus faible) ; retirer « Aucun pour une copie ».
- **Compétences inconnues** : un personnage synchronisé a toujours au moins sa ligne Science ; niveau Science inconnu = pas de ligne Science.
- **Multiplicateur de compétence** (*Skill time multiplier*), à créer : fabrication (Industry, Advanced Industry, sciences du blueprint), réaction (Reactions, sciences du blueprint), copie (Science, Advanced Industry, R2) ; distinction entre la compétence Science (5 %/niv., copie) et les sciences du blueprint (1 %/niv.).
- `docs/modules/industry.md` (lignes `TimePerRunCalculator` et « Durée de job, compétences » : « copie : aucun ») et `docs/specs/126-134-job-duration.md` (A1, levé par #275) : à mettre à jour avec le code.
