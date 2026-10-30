# Spec #126 + #134 — Durée d'un job : une seule durée par run, celle de l'activité de l'étape

**Statut : rédigée en mode nuit le 2026-10-29, à valider par Sylvain.** Aucune question n'a pu être posée : les choix faits en autonomie sont listés dans « Décisions prises en autonomie », ceux qui changeraient une durée affichée sans que les issues le prévoient sont dans « À trancher par Sylvain » et restent hors du lot.

## Contexte

La **durée par run** d'une **étape** est calculée par deux chemins :

- l'affichage (`IndustryResourceMapper::stepToResource` → `IndustryCalculationService::calculateTimePerRun`), dont la valeur est aussi additionnée dans la durée totale du projet (`frontend/src/composables/useProjectTime.ts`, `timePerRun × runs`) ;
- la génération des étapes (`IndustryProjectFactory::addTimeDataToSteps`), dont la valeur décide seule du découpage en **groupe découpé** (`IndustryProjectFactory::maxRunsPerJob`), sans jamais être enregistrée.

État vérifié dans le code au 2026-10-29 (branche `fix/group-production-tree`, lot A #137/#125 compris) :

| Élément | Affichage (`IndustryCalculationService`) | Génération (`IndustryProjectFactory`) |
|---|---|---|
| Activité SDE lue pour le temps de base | `getBaseTimePerRun` : `reaction` → 11, **tout le reste → 1 (fabrication), copie comprise** (#126) | `'copy'` → 5 (copie), `reaction` → 11, sinon 1 |
| Source du temps de base | SQL brut `sde_industry_activities` (`Connection::fetchOne`) | `IndustryActivityRepository::findByTypeIdsAndActivityIds` (lecture groupée) |
| Structure sans structure assignée | `getStructureBonusForStep` : système favori d'abord (`findBestInFavoriteSystem`), sinon meilleure structure en **matériaux** (`findBestStructureForProduct`) | `IndustryBonusService::findBestStructureForProductTimeBonus` : meilleure structure en **temps**, sans système favori |
| Produit sans catégorie de rig | bonus de rôle de la structure (`getBaseTimeBonus`, ex. Raitaru 15 %) | **bonus 0** (`findBestStructureForProductTimeBonus` renvoie `0.0`) |
| Arrondi | un seul `ceil` sur `base × (1 − TE) × structure × compétences` | `ceil` dans `IndustryBonusService::calculateAdjustedTimePerRun`, puis second `ceil` après le multiplicateur de compétence |
| Personnage | `IndustryResourceMapper::findBestCharacterForStep` (formule recopiée en dur) ; copie → aucun personnage, multiplicateur 1 | `findBestSkillMultiplierForBlueprint` sur tous les personnages ; copie → compétences de **fabrication** (Industry, Advanced Industry) |

Données SDE de référence (blueprint Raven, typeId 688, lues sur `ref-data.everef.net/blueprints/688` le 2026-10-29, miroir du SDE) : copie 14 400 s, fabrication 18 000 s, `maxProductionLimit` 10, aucune compétence requise pour la copie.

`Domain\JobDuration` (noyau) n'a toujours aucun appelant dans `src/` : il arrondit **une fois par job** (`round(runs × base × (1 − TE) × modificateur)`), pas par run.

La copie ne pèse pas sur le découpage : `withCopyStepsFollowingManufacturing` découpe une étape de copie au seul **plafond de runs d'un BPC** (glossaire : « une étape de copie n'est jamais découpée » par la durée). Sa durée par run ne sert qu'à l'affichage et à la durée totale du projet.

## Règles métier

**R1 — Temps de base de l'activité de l'étape (#126).** Le temps de base d'une étape est celui de l'activité de l'étape dans le SDE : fabrication (1), réaction (11), copie (5). Une étape de copie lit le temps de copie, jamais celui de la fabrication. Si le SDE n'a pas de temps pour ce blueprint et cette activité, la durée par run est **inconnue** (`null`), jamais le temps d'une autre activité.

**R2 — Une seule durée par run (#134).** La durée par run d'une étape est calculée par une seule fonction, appelée par l'affichage et par la génération des étapes :

```
durée par run = ceil(temps de base × (1 − TE/100) × (1 − bonus de temps de la structure/100) × multiplicateur de compétence)
```

- un seul arrondi, au plafond, sur le produit complet ;
- même **choix de structure** que l'affichage : structure assignée de l'étape, sinon meilleure structure du **système favori** de l'activité, sinon meilleure structure de l'utilisateur (règle actuelle de `getStructureBonusForStep`, inchangée) ;
- même multiplicateur de compétence et même personnage : le personnage de l'utilisateur au multiplicateur le plus faible pour ce blueprint et cette activité (`calculateSkillTimeMultiplier`, sciences du blueprint comprises) ; à multiplicateur égal, le premier personnage de l'utilisateur (règle actuelle de l'affichage, qui fixe `recommendedCharacterName`).

La génération des étapes découpe sur cette valeur : une étape est découpée si et seulement si `durée par run × runs > durée maximale de job` (règle `maxRunsPerJob` inchangée). Pour toute étape générée, la durée par run affichée est celle qui a décidé de son découpage.

**R3 — Produit sans catégorie de rig.** Un produit dont le groupe n'a pas de catégorie de rig (ex. déployables) n'est pas une donnée manquante : aucun rig ne s'applique, le bonus de rôle de la structure s'applique (Raitaru 15 %, Azbel 20 %, Sotiyo 30 %, Tatara 25 % en réaction, Athanor 0). Le bonus 0 de `findBestStructureForProductTimeBonus` disparaît avec cette fonction. Sans aucune structure (station NPC), le bonus de temps est 0 : c'est la valeur réelle d'une station, pas un défaut.

**R4 — Durée par run d'une copie.** Temps de base de copie (R1) × (1 − TE/100) avec le TE de l'étape de copie (0) × bonus de temps de la structure selon la règle actuelle de l'affichage × multiplicateur de compétence 1 (aucun personnage, règle actuelle de l'affichage). Le bonus de structure et le multiplicateur de compétence propres à la copie sont à confirmer (voir « À trancher »), ils restent ceux d'aujourd'hui à l'affichage.

**R5 — Durée par run inconnue.** Temps de base absent du SDE → durée par run `null` à l'affichage, et étape jamais découpée par la durée (comportement actuel, glossaire « Durée maximale de job »). Aucune valeur de repli.

## Exemples chiffrés (tests rouges)

Hypothèses communes sauf mention : aucun personnage (multiplicateur 1), aucune structure, aucun système favori, durée maximale de job en jours, `86 400 s` par jour.

| # | Étape | Données | Durée par run affichée | Durée par run de la génération | Découpage (runs par job, total du groupe) | Aujourd'hui |
|---|---|---|---|---|---|---|
| C1 | Copie du blueprint Raven (688) | SDE : copie 14 400, fabrication 18 000 ; TE 0 | **14 400** | 14 400 | — (copie : plafond BPC seul) | affichage 18 000 |
| C2 | Fabrication du Raven (688) | mêmes données ; TE 0 | 18 000 | 18 000 | — | 18 000 (non-régression) |
| C3 | Copie du Raven, personnage Industry 5 / Advanced Industry 5 | comme C1 | **14 400** (aucun personnage pour la copie, R4) | 14 400 | — | affichage 18 000, génération 9 792 (compétences de fabrication) |
| C4 | Copie d'un blueprint sans activité de copie dans le SDE | SDE : fabrication 18 000 seulement | **`null`** (inconnue) | `null` | — | affichage 18 000 |
| D1 | Fabrication, cas figé de #134 | base 16 003 ; TE 20 ; personnage Industry 5 / Advanced Industry 5 ; 14 400 runs ; durée max 1 451 jours (125 366 400 s) | 8 706 | **8 706** | **un job de 14 400 runs** (8 706 × 14 400 = 125 366 400, pas au-delà) | génération 8 707 (deux `ceil`) → 2 jobs de 7 200 (total 14 400) |
| D2 | Fabrication, système favori | base 10 000 ; TE 0 ; produit à catégorie de rig ; Raitaru sans rig dans le système favori de fabrication, Azbel sans rig ailleurs ; 21 runs ; durée max 2 jours (172 800 s) | 8 500 (Raitaru, 15 %) | **8 500** | **2 jobs : 11 et 10 (total 21)** (21 × 8 500 = 178 500 > 172 800 ; `floor(172 800 / 8 500)` = 20) | génération 8 000 (Azbel 20 %, hors favori) → un job de 21 |
| D3 | Fabrication, produit sans catégorie de rig | base 10 000 ; TE 0 ; une Raitaru sans rig, pas de favori ; 18 runs ; durée max 2 jours | 8 500 (bonus de rôle) | **8 500** | **un job de 18 runs** (153 000 ≤ 172 800) | génération 10 000 (bonus 0) → 2 jobs de 9 (total 18) |
| D4 | Fabrication sans temps de base dans le SDE | 1 000 runs ; durée max 2 jours | `null` | `null` | un job de 1 000 runs | identique (non-régression, `testStepWithoutBaseTimeInTheSdeIsNeverSplitWhateverItsRuns`) |

Contrôle d'égalité (forme du test #134) : pour chaque étape générée en D1, D2, D3, `IndustryCalculationService::calculateTimePerRun(étape, compétences du personnage retenu)` vaut la durée par run qui a décidé du découpage, et `durée par run × runs du job ≤ durée maximale de job` pour chaque job d'un groupe découpé.

Calcul D1 : `16 003 × 0,8 × 1 × 0,68 = 8 705,632` → 8 706 ; aujourd'hui `ceil(16 003 × 0,8) = 12 803`, puis `ceil(12 803 × 0,68) = ceil(8 706,04) = 8 707` ; `8 707 × 14 400 = 125 380 800 > 125 366 400` → `floor(125 366 400 / 8 707) = 14 398` runs par job → 2 jobs.

## Cas limites

- **Égalité exacte avec la durée maximale** (D1) : pas de découpage, la comparaison reste stricte (`>`).
- **Groupe découpé à structures différentes par job** : après régénération, chaque job reprend la structure assignée de son `splitIndex` ; la décision de découpage se prend sur l'étape consolidée avec la structure du job d'index 0 (`withSettingsOfExistingStep`, comportement actuel). Un job d'index > 0 assigné ailleurs peut afficher une autre durée par run. Hors lot, voir « Hors périmètre ».
- **Réaction** : temps de base de l'activité 11, compétence Reactions (4 %/niveau), choix de structure par le favori de réaction ; mêmes règles R2/R3 (non-régression de `testReactionsFourCutsTheTimePerRunThatDecidesTheSplit`).
- **Plusieurs personnages** : même personnage à l'affichage et à la génération (non-régression de `testSplitUsesTheBestCharacterOfTheUser`).
- **Étape de copie** : jamais découpée par la durée, même avec une durée par run connue (glossaire « Durée maximale de job »).
- **Type du produit absent du SDE** : la génération ne l'atteint pas (`IndustryTreeService::buildProductionTree` lève `TypeMissingFromSdeException` avant) ; à l'affichage, voir « À trancher » (A3).

## Hors périmètre

- Brancher `Domain\JobDuration` : il arrondit une fois **par job** avec `round`, pas par run avec `ceil` ; le brancher changerait les durées affichées et la règle de découpage (décision D-3).
- Migrer le choix de structure de la durée sur `StructureSelector` (noyau, spec #151) : changerait la structure affichée et la durée affichée des étapes sans structure assignée.
- Bonus de structure et multiplicateur de compétence propres à la copie (A1, A2).
- Découpage par job quand les jobs d'un groupe découpé ont des structures assignées différentes.
- Invention : pas d'étape d'invention dans la génération des étapes.
- Frontend : aucun changement, `useProjectTime.ts` additionne déjà `timePerRun × runs` ; la durée totale suit C1.

## Décisions prises en autonomie

- **D-1 — La fonction unique est celle de l'affichage.** `IndustryCalculationService` porte la seule durée par run (choix de structure de `getStructureBonusForStep`, un seul `ceil`, multiplicateur `calculateSkillTimeMultiplier`, sélection du personnage déplacée depuis le mapper), et la génération l'appelle. Raison : la durée affichée ne bouge pas (sauf la copie, voulue par #126) ; seul le découpage change, ce que #134 demande. L'inverse (aligner l'affichage sur la génération) changerait les durées affichées.
- **D-2 — Une seule source du temps de base : `IndustryActivityRepository`.** Le SQL brut `getBaseTimePerRun` disparaît. Raison : c'est la source de la génération et des harnais golden (`BuildsProjectOnGoldenSde::activityRepositoryOnGoldenSde`) ; garder le SQL brut ferait lire 0 aux goldens (`entityManagerWithoutRows`, `fetchOne` → 0) et supprimerait tout découpage par la durée dans ces tests.
- **D-3 — `JobDuration` n'est pas branché** (voir hors périmètre). Il reste sans appelant ; sa suppression ou son usage futur est une question à part (A4).
- **D-4 — Le bonus 0 sans catégorie est remplacé par le bonus de rôle** (R3), pas par un état inconnu : l'absence de catégorie de rig est une donnée connue du SDE (`industry_rig_categories`), pas une donnée manquante. L'affichage applique déjà cette règle : aucune durée affichée ne change.
- **D-5 — Code mort retiré** : `IndustryBonusService::findBestStructureForProductTimeBonus`, `findBestStructureForCategoryTimeBonus`, `calculateAdjustedTimePerRun` (plus aucun appelant dans `src/`), `IndustryProjectFactory::loadAllCharacterSkills`, `findBestSkillMultiplierForBlueprint`, `IndustryResourceMapper::findBestCharacterForStep` (remplacée par la sélection partagée, qui garde la règle « premier personnage à multiplicateur égal »).
- **D-6 — Copie : on garde l'affichage actuel pour la structure et les compétences** (R4). Conséquence sans effet visible : la génération n'applique plus les compétences de fabrication à la copie (9 792 en C3 aujourd'hui), valeur jamais affichée ni utilisée pour un découpage.
- **D-7 — Le temps de base de la copie** passe par le même passage activité → `activityId` que la génération (`'copy'` → 5). `SplitGroupMaterials::activityIdOf` n'est pas réutilisé : il renvoie la fabrication pour une copie (correct pour les matériaux, faux pour le temps).

## À trancher par Sylvain

- **A1 — Compétences qui réduisent la durée d'une copie (levé par #275 : Science 5 %/niv. et Advanced Industry 3 %/niv.).** `[TO CONFIRM: compétences de durée de copie]`. Le dépôt ne les connaît pas (`CachedCharacterSkill` n'a ni Science 3402 ni bonus de copie). L'ESI (`/universe/types/3402/`, lue le 2026-10-29) donne pour **Science** « 5 % Bonus to blueprint copying speed per level » (attribut 452 = −5) ; **Advanced Industry** (3388) : « 3 % reduction in all manufacturing and research times » (attribut 1961 = −3), application à la copie `[TO CONFIRM]` ; **Industry** (3380) : fabrication seule (attribut 440). Effet sur Raven copie, sans structure : aujourd'hui (lot) 14 400 ; Science 5 seule → 10 800 ; Science 5 + Advanced Industry 5 → 9 180 ; règle actuelle de la génération (Industry 5 + Advanced Industry 5) → 9 792. Recommandation : appliquer Science (5 %/niv.) et Advanced Industry (3 %/niv.) à la copie après vérification en jeu, dans une issue dédiée (change une durée affichée).
- **A2 — Bonus de structure d'une copie.** `[TO CONFIRM: bonus de temps de structure en copie]`. Aujourd'hui l'affichage applique à la copie le bonus de temps de **fabrication** (bonus de rôle + rigs de fabrication de la catégorie du produit). Avec une Raitaru sans rig, Raven copie : lot → `14 400 × 0,85` = 12 240 (avant lot 15 300). Les rigs de fabrication ne réduisent pas la copie en jeu (les rigs de science ont leurs propres catégories), et le dépôt ne connaît pas les rigs de science. Recommandation : bonus de rôle seul pour la copie, rigs de fabrication exclus, après vérification en jeu ; issue dédiée.
- **A3 — Durée par run quand le type du produit est absent du SDE (affichage).** `getCategoryForProduct` renvoie `null` aussi bien pour « pas de catégorie de rig » que pour « type absent », et l'affichage applique alors le bonus de rôle. Recommandation : durée par run inconnue (`null`) pour un type absent, distinguée de « sans catégorie » ; change une durée affichée dans un cas de donnée manquante, donc hors lot.
- **A4 — `Domain\JobDuration` sans appelant** : supprimer, ou le garder pour la migration du découpage vers le noyau. Recommandation : le garder jusqu'à la décision sur la migration de la génération des étapes vers le noyau.

## Termes du glossaire ajoutés

- **Durée par run** (Time per run) et **Temps de base** (Base time per run), ajoutés à `docs/glossary.md`.
