# Spec #277 + #275 — Durée par run : arrondi sans bruit flottant, compétence Science sur la copie

**Statut : rédigée en mode nuit le 2026-10-29, à valider par Sylvain.** Aucune question n'a pu être posée : les choix faits en autonomie sont listés dans « Décisions prises en autonomie », ceux qui changeraient une durée sans que les issues le prévoient sont dans « À trancher par Sylvain » et restent hors du lot. Périmètre de #275 **réduit** : seule la compétence Science s'applique à la copie ; Advanced Industry reste bloquée par une vérification en jeu.

**Périmètre du lot en cours : #277 seul (règle de l'arrondi, exemples A1 à A5).** #275 est mise en attente de Sylvain : la spec le garde pour mémoire, rien n'en est implémenté. Trois points à trancher d'abord : D-3 (la sync des compétences doit toujours écrire la ligne Science, niveau 0 compris), A1 (Advanced Industry sur la copie, à vérifier en jeu) et A3 (toutes les durées de copie des personnages déjà synchronisés passent à « inconnue » après le déploiement, tant que la sync des compétences n'est pas relancée). Pour #277 seul, le cas A5 garde la copie à 14 100 et le personnage `null` : seule la fabrication passe de 12 921 à 12 920.

## Contexte

La **durée par run** d'une **étape** est calculée par une seule fonction depuis le lot #126/#134 (`docs/specs/126-134-job-duration.md`) : `IndustryCalculationService::timePerRunOf`, appelée par l'affichage (`IndustryResourceMapper::stepToResource` via `calculateTimePerRun`) et par la génération des étapes (`IndustryProjectFactory::addTimeDataToSteps`).

État vérifié dans le code au 2026-10-29 (branche `fix/group-production-tree`) :

| Élément | État actuel | Fichier |
|---|---|---|
| Arrondi | `(int) ceil($baseTime * $teMultiplier * $structureTimeMultiplier * $skillMultiplier)` : le `ceil` porte sur un flottant IEEE (#277) | `IndustryCalculationService::timePerRunOf` |
| Personnage d'une copie | `bestCharacterForStepTime` renvoie `['skills' => null, 'name' => null]` pour `copy` : aucun personnage, multiplicateur 1 | `IndustryCalculationService::bestCharacterForStepTime` |
| Multiplicateur de compétence | fabrication (et tout ce qui n'est pas `reaction`, copie comprise) : Industry 4 %/niv., Advanced Industry 3 %/niv. ; réaction : Reactions 4 %/niv. ; puis sciences du blueprint 1 %/niv. Niveau absent lu comme 0 (`?? 0`) | `IndustryCalculationService::calculateSkillTimeMultiplier` |
| Sciences du blueprint | compétences requises par le blueprint pour la fabrication (`copy` lu comme fabrication) ou la réaction, hors Industry, Advanced Industry, Reactions | `getBlueprintScienceSkillIds` |
| Compétences connues du dépôt | constantes `SKILL_INDUSTRY` 3380, `SKILL_ADVANCED_INDUSTRY` 3388, `SKILL_REACTIONS` 45746, compétences de slot ; **pas de Science (3402)**. `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL` (0,01) est le bonus des **sciences du blueprint**, pas celui de la compétence Science | `CachedCharacterSkill` |
| Stockage | table `cached_character_skills`, une ligne par (personnage, `skillId`) avec `level` (0 à 5), `isManual`, `cachedAt` | `CachedCharacterSkill` |
| Lecture | `loadCharacterSkillLevels(User)` : pour chaque personnage de l'utilisateur, dans l'ordre de `User::getCharacters()`, `findAllSkillsForCharacter` → `array<skillId, level>` | `IndustryCalculationService`, `CachedCharacterSkillRepository` |
| Écriture (ESI) | sync à la demande (`SyncCharacterSkillsProcessor`), `/characters/{id}/skills/`, niveau `active_skill_level` sinon `trained_skill_level`. Liste synchronisée : compétences SDE des activités 1 et 11 (`sde_industry_activity_skills`) + Industry, Advanced Industry, Reactions + compétences de slot. Une ligne **n'est créée que si le niveau est > 0** ; une ligne existante non manuelle est mise à jour (niveau 0 compris). Personnage sans token ou sans scope `esi-skills.read_skills.v1` : rien n'est écrit | `SyncCharacterSkillsProcessor::syncCharacterSkills`, `getManufacturingRelevantSkillIds` |
| Écriture (manuelle) | Industry, Advanced Industry, Reactions seulement | `UpdateCharacterSkillProcessor`, `UpdateCharacterSkillInput` |
| Précédent « inconnu » | spec #218 : compétences inconnues = aucune compétence en cache ; un personnage synchronisé sans compétence de slot a le niveau 0, parce que les compétences de slot ont toujours fait partie de la liste synchronisée | `SlotTrackerService::getSlotStatus` (`hasCachedSkills`) |

Données de jeu (ESI `/universe/types/3402/`, lue le 2026-10-29) : **Science**, « 5% Bonus to blueprint copying speed per level », attribut 452 = −5. **Advanced Industry** (3388) : « 3 % reduction in all manufacturing and research times » (attribut 1961 = −3), application à la copie `[TO CONFIRM: Advanced Industry réduit-elle la durée de copie en jeu ?]` (spec #126/#134, A1). **Industry** (3380) : fabrication seule.

Donnée SDE de référence : blueprint Raven (688), temps de base de copie 14 400 s, de fabrication 18 000 s (spec #126/#134).

Présence de Science dans les compétences SDE des activités 1 et 11 (donc déjà synchronisée pour les niveaux > 0) : `[TO CONFIRM: Science 3402 figure-t-elle dans sde_industry_activity_skills pour activity_id 1 ou 11 ?]`. Sans effet sur les règles ci-dessous (R5 impose sa ligne au niveau 0 aussi).

## Règles métier

**R1 — Arrondi de la durée par run (#277).**

```
durée par run = ceil(round(temps de base × (1 − TE/100) × (1 − bonus de temps de la structure/100) × multiplicateur de compétence, 6))
```

Le produit est arrondi à 6 décimales avant le `ceil` : une partie fractionnaire inférieure à 0,000 000 5 est du bruit flottant (`19 000 × 0,68` vaut `12 920,000000000002` en IEEE), pas une seconde à ajouter. La précision (6) est une constante nommée. Même schéma que la quantité de matériau (`ceil(round(…, 2))`, `calculateMaterialQuantity`). S'applique à toute activité (fabrication, réaction, copie), à l'affichage et à la génération, puisque les deux passent par `timePerRunOf`.

**R2 — Multiplicateur de compétence d'une copie (#275).**

```
multiplicateur de compétence (copie) = 1 − 0,05 × niveau Science
```

Science = typeId 3402 (constante nommée dans `CachedCharacterSkill`, ex. `SKILL_SCIENCE`), 5 % par niveau (constante nommée, ex. `SCIENCE_COPY_TIME_BONUS_PER_LEVEL`, distincte de `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL`). Pour une copie, ni Industry, ni Advanced Industry, ni Reactions, ni les sciences du blueprint (`getBlueprintScienceSkillIds` n'est pas appelé pour `copy`). Science ne s'applique ni à la fabrication ni à la réaction (sauf si elle est une science du blueprint, règle actuelle inchangée).

**R3 — Niveau Science connu.** Le niveau Science d'un personnage est connu si et seulement si le cache a une ligne Science pour lui, niveau 0 compris. Sans ligne, il est **inconnu** : personnage jamais synchronisé, synchronisé avant ce lot, sans token ou sans scope des compétences, ou saisi à la main seulement.

**R4 — Personnage retenu pour une copie.** Chaque étape de copie retient son propre personnage, indépendamment de l'étape de fabrication du même blueprint : parmi les personnages de l'utilisateur dont le niveau Science est connu, celui au multiplicateur le plus faible (Science la plus haute) ; à égalité, le premier dans l'ordre des personnages de l'utilisateur (règle actuelle de `bestCharacterForStepTime`). `recommendedCharacterName` de l'étape de copie porte son nom (aujourd'hui toujours `null`). Les jobs d'un groupe découpé de copie (plafond de runs d'un BPC, spec #235) retiennent tous le même personnage.

**R5 — Science inconnue pour tous les personnages.** L'utilisateur a au moins un personnage et aucun n'a de niveau Science connu : la durée par run de la copie est **inconnue** (`null`) et `recommendedCharacterName` est `null`. Jamais un niveau 0, jamais un multiplicateur 1 par défaut. Effet sur la génération : aucun, une étape de copie n'est jamais découpée par la durée (glossaire « Durée maximale de job »).

**R6 — Utilisateur sans personnage.** Aucun personnage (`calculateTimePerRun($step, null)`, `bestCharacterForStepTime` sur une liste vide) : multiplicateur 1, règle « sans personnage » actuelle de toutes les activités, inchangée. Ce n'est pas une donnée manquante : aucune compétence ne s'applique faute de personnage.

**R7 — Sync des compétences.** La sync ESI ajoute Science (3402) à la liste synchronisée et enregistre **toujours** sa ligne, niveau 0 compris (l'ESI ne renvoie que les compétences entraînées : absente de la réponse = niveau 0 connu). Les autres compétences gardent la règle actuelle (ligne créée seulement au niveau > 0). Une ligne Science manuelle n'est pas écrasée (règle actuelle `isManual`), mais aucune saisie manuelle de Science n'est ajoutée (hors périmètre).

**R8 — Structure de la copie inchangée.** Le bonus de temps de structure appliqué à la copie reste celui d'aujourd'hui (#276, hors périmètre). Formule complète d'une copie :

```
durée par run (copie) = ceil(round(temps de base de copie × (1 − bonus de temps de la structure/100) × (1 − 0,05 × Science), 6))
```

(TE d'une étape de copie : 0.)

## Exemples chiffrés (tests rouges)

Hypothèses communes sauf mention : aucune structure, aucun système favori, aucune science du blueprint, durée maximale de job 2 jours (172 800 s).

### #277 — arrondi

| # | Étape | Données | Durée par run attendue | Aujourd'hui | Test |
|---|---|---|---|---|---|
| A1 | Fabrication | base 19 000 ; TE 0 ; Industry 5 + Advanced Industry 5 (0,68) | **12 920** | 12 921 (`12 920,000000000002`) | `IndustryCalculationTest`, nouveau cas |
| A2 | Fabrication | base 10 000 ; TE 0 ; Industry 5 + Advanced Industry 5 | **6 800** | 6 801 (`6 800,000000000001`) | `IndustryCalculationTest`, nouveau cas |
| A3 | Fabrication | base 16 003 ; TE 20 ; Industry 5 + Advanced Industry 5 | 8 706 (`8 705,632` : vraie fraction, toujours arrondie au supérieur) | 8 706 (non-régression) | `IndustryCalculationTest` |
| A4 | Fabrication | base 16 000 ; TE 2 ; Industry 5 + Advanced Industry 5 | 10 663 (`10 662,4`) | 10 663 (non-régression, `testTimePerRunIsRoundedUpToTheNextSecondEvenWhenItsFractionIsBelowOneHalf`) | existant |
| A5 | Projets de 13 et 14 runs | base fabrication 19 000 ; copie 14 100 ; personnage `Industrialist` Industry 5 + Advanced Industry 5, sans ligne Science | fabrication **12 920** ; découpage inchangé : 13 runs → un job (13 × 12 920 = 167 960 ≤ 172 800), 14 runs → 2 jobs de 7 (180 880 > 172 800, `floor(172 800 / 12 920)` = 13) ; copie **`null`**, personnage `null` (R5) | fabrication 12 921, copie 14 100 | réécriture du cas figé de `IndustryProjectFactoryTest` (voir « Tests existants appelés à casser ») |

### #275 — Science sur la copie

| # | Étape | Personnages (compétences en cache) | Durée par run attendue | Personnage retenu | Aujourd'hui |
|---|---|---|---|---|---|
| C1 | Copie du Raven (688) | `Main` : Science 0 (ligne présente) | 14 400 | `Main` | 14 400, `null` |
| C2 | Copie du Raven | `Main` : Science 5 | **10 800** (`14 400 × 0,75`) | `Main` | 14 400, `null` |
| C3 | Copie du Raven | `Main` : Science 3 | **12 240** (`14 400 × 0,85`) | `Main` | 14 400, `null` |
| C4 | Copie du Raven | `Main` : Science 5, Industry 5, Advanced Industry 5 | **10 800** (Industry et Advanced Industry ne s'appliquent pas, R2) | `Main` | 14 400, `null` |
| C5 | Copie du Raven | `Alt` : Science 4 ; `Main` : Science 5 | 10 800 | `Main` (Science la plus haute) | 14 400, `null` |
| C6 | Copie du Raven | `Twin1` : Science 5 ; `Twin2` : Science 5 | 10 800 | `Twin1` (premier à égalité) | 14 400, `null` |
| C7 | Copie du Raven | `Old` : Industry 5, sans ligne Science ; `New` : Science 3 | 12 240 | `New` (seul niveau connu, R4) | 14 400, `null` |
| C8 | Copie du Raven | `Old` : Industry 5 + Advanced Industry 5, sans ligne Science | **`null`** (R5) | `null` | 14 400, `null` |
| C9 | Copie du Raven | aucun personnage (`calculateTimePerRun($copyStep)` sans compétences) | 14 400 (R6) | `null` | 14 400 (non-régression, `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint`) |
| C10 | Copie d'un blueprint sans activité de copie dans le SDE | `Main` : Science 5 | `null` (temps de base absent) | sans objet | `null` (non-régression, `testTimePerRunOfACopyStepIsUnknownWhenTheSdeHasNoCopyingTimeForTheBlueprint`) |
| C11 | Fabrication du Raven | `Main` : Science 5 seule | 18 000 (Science ne s'applique pas à la fabrication) | `Main` | 18 000 |
| C12 | Génération, projet de 13 runs avec copie | base copie 14 100 ; `Industrialist` : Industry 5, Advanced Industry 5, Science 5 | copie **10 575** (`14 100 × 0,75`), affichée par le mapper | `Industrialist` | 14 100, `null` |

Forme des tests C1 à C11 : `IndustryCalculationService::calculateTimePerRun($step, $skills)` pour la durée, `bestCharacterForStepTime($user, 688, 'copy', $characterSkillLevels)` pour le personnage ; C12 dans `IndustryProjectFactoryTest::testEveryGeneratedJobDisplaysTheTimePerRunThatDecidedItsSplit` (nouveau cas du fournisseur `generatedJobsOfTwoProjectsAndTheirDisplayedTimePerRun`, harnais `userWithCharacters` avec la clé `3402`). Un niveau Science « connu à 0 » s'écrit `[3402 => 0]` dans les harnais ; « inconnu » s'écrit sans la clé `3402`.

### Sync des compétences (R7)

| # | Réponse ESI | Cache avant | Cache après (Science) |
|---|---|---|---|
| S1 | Science `active_skill_level` 4 | aucune ligne | ligne Science niveau 4, `isManual` false |
| S2 | pas de Science (non entraînée) | aucune ligne | **ligne Science niveau 0** |
| S3 | pas de Science, Industry 5 | ligne Industry 5, aucune ligne Science (sync antérieure au lot) | ligne Science niveau 0 ; Industry 5 inchangée |
| S4 | personnage sans scope `esi-skills.read_skills.v1` | aucune ligne | aucune ligne (Science inconnue) |
| S5 | pas de compétence de slot, pas de Science | aucune ligne | ligne Science 0 seulement ; aucune ligne de slot créée (règle actuelle) |

`SyncCharacterSkillsProcessor` n'a aucun test aujourd'hui : harnais à créer (`EsiClient`, `TokenManager`, `Connection::fetchAllAssociative` pour la liste SDE, `EntityManagerInterface::persist`).

## Cas limites

- **Personnages synchronisés avant le déploiement** : aucune ligne Science, durée de copie inconnue (R5) tant que l'utilisateur ne relance pas la sync des compétences. Aucune migration de schéma : la table ne change pas.
- **Personnage aux compétences saisies à la main** : la saisie manuelle ne couvre pas Science, son niveau Science reste inconnu ; un autre personnage synchronisé de l'utilisateur peut porter la copie (R4).
- **Interaction avec #218** : une ligne Science niveau 0 est une compétence en cache ; un personnage synchronisé a donc toujours au moins une ligne, ce qui ne change rien pour lui (il était déjà « connu » s'il avait une compétence entraînée de la liste). Un personnage synchronisé sans aucune compétence de la liste passe de « compétences inconnues » à « connues » dans le Slot Tracker, avec ses slots à 1 : c'est la valeur vraie (règle #218 : synchronisé sans compétence de slot = niveau 0).
- **Groupe découpé de copie** (plafond de runs d'un BPC) : même personnage et même durée par run sur chaque job.
- **Arrondi R1, vraie fraction inférieure à 0,000 000 5** : elle serait perdue. Les entrées (temps de base entier, TE entier, bonus de structure et de rig à quelques décimales, multiplicateurs de compétence à 2 décimales) rendent ce cas théorique ; le compromis est accepté (décision D-1).
- **Durée totale du projet** : `useProjectTime.ts` ignore une étape à durée par run `null` ; une copie à Science inconnue sort donc du total sans signal (voir A2).

## Tests existants appelés à casser

- `IndustryProjectFactoryTest::testEveryGeneratedJobDisplaysTheTimePerRunThatDecidedItsSplit`, cas `'copy of the blueprint, never split, without character and with its copying base time'` (lignes 1012-1025) : fabrication **12 921 → 12 920** (#277) sur les deux projets, et copie **14 100 → `null`** avec personnage `null` (#275, R5 : `Industrialist` n'a pas de ligne Science). Réécriture : renommer le cas (ex. `'copy of the blueprint, never split, with an unknown Science level'`), attendus `['manufacturing' => [[13, null, 12920, 'Industrialist']], 'copy' => [[13, null, null, null]]]` et `['manufacturing' => [[7, 14, 12920, 'Industrialist'], [7, 14, 12920, 'Industrialist']], 'copy' => [[14, null, null, null]]]`. Le découpage ne change pas.
- Aucun autre test ne fige un `ceil` flottant : toutes les durées attendues de `IndustryCalculationTest`, `IndustryProjectFactoryTest` et `JobSplitCalculationTest` ont été recalculées en IEEE (`ceil(x)` contre `ceil(round(x, 6))`) et sont identiques (16 000, 12 800, 13 600, 10 880, 10 240, 8 704, 9 072, 8 640, 7 258, 7 399, 6 804, 8 443, 8 275, 8 530, 8 891, 10 663, 7 140, 9 350, 9 180, 10 400, 10 068). Les tests de caractérisation golden ne lisent pas `timePerRun` ; leur découpage dépend de la durée : la suite complète doit rester verte, sinon le cas est à documenter ici.
- `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint` reste vert (R6, appel sans compétences).

## Hors périmètre

- **#276** : bonus de temps de structure propre à la copie (rigs de science, bonus de rôle).
- **#283** : extraction d'un service de durée hors de `IndustryCalculationService`.
- **Advanced Industry sur la copie** : bloquée par `[TO CONFIRM: effet d'Advanced Industry sur la durée de copie en jeu]` (sources contradictoires). Le test proposé par l'issue (Science 5 + Advanced Industry 5 → 9 180) n'est **pas** la cible de ce lot : C4 attend 10 800.
- Saisie manuelle du niveau Science (`UpdateCharacterSkillInput`, écran des compétences).
- Lecture `?? 0` des compétences de fabrication et de réaction dans `calculateSkillTimeMultiplier` pour un personnage jamais synchronisé : même écart à la règle « pas de `?? 0` sur une donnée métier », mais ces compétences ont toujours été dans la liste synchronisée (cas #218) ; à traiter dans une issue dédiée si besoin.
- Noyau : `Domain\SkillTimeModifier` (pas de copie) et `Domain\JobDuration` restent sans appelant.
- Sync automatique des compétences (hors périmètre de #218 aussi).
- Frontend : aucun changement. `StepTree.vue` affiche déjà `recommendedCharacterName` sur toute étape et `-` pour une durée `null` (`formatDuration`).

## Décisions prises en autonomie

- **D-1 — Précision 6 (issue #277).** Arrondi à 6 décimales avant le `ceil`, dans `timePerRunOf` seulement. Alternative écartée : multiplier par des entiers (pourcentages) pour rester exact ; plus intrusif, et le dépôt applique déjà `round` avant `ceil` aux matériaux.
- **D-2 — Science seule sur la copie.** Advanced Industry n'est pas appliquée faute de confirmation en jeu ; c'est un blocage consigné (A1), pas une valeur devinée. Industry : fabrication seule (issue #275).
- **D-3 — Ligne Science toujours écrite par la sync.** La règle #218 (« synchronisé = niveau 0 pour une compétence absente ») ne suffit pas : Science n'était pas dans la liste synchronisée, un personnage synchronisé avant le lot qui a entraîné Science lirait un 0 plausible et faux. La présence de la ligne est le marqueur « niveau connu ». Pas de colonne ni de migration.
- **D-4 — Meilleur personnage pour la copie**, pas celui de la fabrication : chaque étape retient son personnage pour son activité (R2 de la spec #126/#134) ; copie et fabrication sont deux jobs qu'un joueur lance souvent avec des personnages différents.
- **D-5 — Inconnu seulement si aucun niveau n'est connu.** Un personnage à Science inconnue n'empêche pas de retenir un personnage à Science connue : la durée affichée est alors vraie pour le personnage affiché. Alternative écartée : inconnue dès qu'un personnage est inconnu (masquerait la durée pour tout utilisateur ayant un alt non synchronisé).
- **D-6 — Sans personnage, multiplicateur 1** (R6) : règle existante de toutes les activités, garde `testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint` vert.
- **D-7 — Noms** : `SKILL_SCIENCE` et `SCIENCE_COPY_TIME_BONUS_PER_LEVEL` (ou équivalents) dans `CachedCharacterSkill`, à côté des constantes existantes. `SCIENCE_SKILL_TIME_BONUS_PER_LEVEL` (sciences du blueprint) n'est pas renommée dans ce lot, mais la confusion est signalée au glossaire (« Multiplicateur de compétence »).

## À trancher par Sylvain

- **A1 — Advanced Industry sur la copie.** `[TO CONFIRM: en jeu, lancer une copie avec un personnage Science 5 et Advanced Industry 0, puis 5, et comparer la durée affichée]`. Si elle s'applique : multiplicateur copie `(1 − 0,05 × Science) × (1 − 0,03 × Advanced Industry)`, Raven sans structure, Science 5 + Advanced Industry 5 → 9 180. Advanced Industry fait déjà partie de la liste synchronisée : son niveau suivrait la règle #218, sans marqueur.
- **A2 — Durée totale du projet avec une copie à durée inconnue.** `useProjectTime.ts` ignore les étapes à `timePerRun` `null` : le total est sous-estimé sans signal. Recommandation : total marqué partiel (même traitement que les KPI partiels #218), issue dédiée.
- **A3 — Après déploiement**, toutes les copies des personnages déjà synchronisés afficheront une durée inconnue jusqu'à la prochaine sync des compétences. Options : l'accepter et le dire dans les notes de version (recommandé), ou relancer la sync des compétences à la connexion (change le flux d'authentification).
- **A4 — Saisie manuelle de Science** pour un personnage sans scope des compétences : à ajouter à l'écran des compétences si le besoin existe.

## Termes du glossaire

- **Durée par run** : formule mise à jour (`ceil(round(…, 6))`, #277) et cas inconnu de la copie (#275), marqués « à créer ».
- **Multiplicateur de compétence** (Skill time multiplier) : ajouté, avec la distinction entre la compétence Science et les sciences du blueprint, et le niveau Science inconnu.
- `docs/modules/industry.md` (`bestCharacterForStepTime` « copie : aucun personnage ») est à mettre à jour avec le code, en phase verte.
