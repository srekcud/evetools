# Spec — Le planning compte les copies et signale les inventions laissées de côté (#1204)

## Contexte

`ProjectPlanningInputLoader::load` ne garde que les lignes `manufacturing` et `reaction`. Sur un projet T2, la fin planifiée, la fin attendue et le retard ignorent donc les copies (et l'invention) sans le dire. Le planificateur au plus tôt (`ProductionSchedule::earliest`) ne connaît que deux réserves de slots (fabrication, réaction) ; les copies et inventions occupent les slots de laboratoire (compétences Laboratory Operation et Advanced Laboratory Operation, `SlotTrackerService::calculateMaxSlots()['science']`).

Décision : les copies entrent dans le plan ; les inventions n'y entrent pas (leur ligne n'a ni structure prévue ni durée de base modélisée : `SdeResearchBaseTimes` refuse `ActivityKind::Invention`) et sont listées explicitement comme laissées de côté, la fin planifiée ne les compte pas.

## Règles

- **R1.** Le noyau connaît une réserve de slots de laboratoire : `ScheduleWorker` gagne un 4e paramètre `int $laboratorySlots = 0`. `ProductionSchedule` classe `ActivityKind::Copying`, `Invention`, `ResearchMaterial` et `ResearchTime` (`isLaboratory()`) dans une seule réserve « laboratoire » ; la fabrication et la réaction gardent chacune la leur. Un worker sans slot de laboratoire ne peut lancer aucun job de laboratoire (`NO_ELIGIBLE_WORKER`).
- **R2.** `PlanningCharacter` gagne `?int $scienceSlots` (après `reactionSlots`), rempli depuis `SlotTrackerService::calculateMaxSlots($skills)['science']` ; null quand aucune compétence n'est en cache (comme les autres slots). `ProjectPlanningBuilder::workersOf` le passe en `laboratorySlots` et lève `UnexpectedValueException` si les compétences sont connues mais les slots de laboratoire inconnus (même garde que `slotsMissing`).
- **R3.** `ProjectPlanningInputLoader` charge aussi les lignes `isJob` d'activité `copy` non couvertes par le stock, en plus de `manufacturing` et `reaction`. `PlanningLine::$activity` devient `'manufacturing'|'reaction'|'copy'`. Un `activityType` d'un autre type reste une `UnexpectedValueException`, sauf `invention` (R6).
- **R4.** Durée d'une ligne `copy` par personnage : `JobDuration::forCopyJob(tempsDeBase, runs, SkillTimeModifier::forCopy(Science, AdvancedIndustry)->times(modificateurDeStructure))`. Temps de base : `ResearchBaseTimes::of(typeId, ActivityKind::Copying)`, injecté dans le loader ; absent → `baseTimeSeconds` null. Niveau absent d'une compétence = 0 (`levelOf`, comme la fabrication). Le modificateur de structure vient de `ProductionStructure::timeMultiplierFor(ActivityKind::Copying)` de la structure prévue de la ligne (via une méthode `ofCopy(?string $plannedStructureId, array $structures): ?Multiplier` ajoutée à `PlannedJobTimeModifier` : structure supprimée → null → `structureTimeKnown` false, comme la fabrication).
- **R5.** Une ligne `copy` sans structure prévue (`plannedStructureId` null) a une durée inconnue, jamais un modificateur par défaut (même règle que `PlannedCopyDurations::of`, cause `copyStructureNotPlanned`) : elle apparaît dans `unscheduled` avec la raison `duration_unknown`, et la fin planifiée est null.
- **R6.** Dépendances : une ligne `manufacturing` de type X dépend des lignes `copy` de type X (la copie précède la fabrication qu'elle alimente). La règle existante (`parentTypeId === typeId`) reste. Une ligne `copy` n'a pas de dépendance propre.
- **R7.** Les lignes `invention` (activité `invention`, `isJob`) ne sont pas planifiées : le loader les expose dans `ProjectPlanningInput::$inventionLines` (`list<PlanningLeftOutLine>`, `lineId` et `typeName`, défaut `[]`). Le builder les ajoute à `ProjectPlanning::$unscheduled`, après les lignes du plan, avec la raison `PlanningUnscheduled::INVENTION_NOT_PLANNED = 'invention_not_planned'`. Elles n'influencent ni `plannedEnd`, ni `expectedEnd`, ni `delaySeconds`, ni les voies. Une ligne `invention` couverte par le stock (0 run) n'est pas listée.
- **R8.** Fin attendue et retard : les lignes `copy` sans job lié (aucun rapprochement avant #1193) sont replanifiées à partir de maintenant comme les autres lignes sans job ; le calcul existant (`replanned`, `fixedEnds`, `waitsOf`) s'applique tel quel, `dependenciesOf` couvre la copie de R6.
- **R9.** API `GET /industry/projects/{projectId}/planning` : `characters[].jobs[].activity` peut valoir `copy` ; `characters[].slots` gagne `science` ; `unscheduled[].reason` peut valoir `invention_not_planned` (`blueprintName` null pour une invention). Aucun autre champ ne change.
- **R10.** Front : `PlanningActivity` ajoute `'copy'`, `PlanningUnscheduledReason` ajoute `'invention_not_planned'`, `PlanningCharacter.slots` ajoute `science: number` ; clés i18n FR et EN `groupIndustry.planning.activity.copy` (« Copy » / « Copie ») et `groupIndustry.planning.unscheduledReason.invention_not_planned` (« Invention not planned: the planned end leaves it out » / « Invention non planifiée : la fin planifiée ne la compte pas »). Aucune autre modification d'écran.
- **R11.** Glossaire : les entrées « Planning du projet » et « Planification au plus tôt » mentionnent les copies (réserve de laboratoire) et les inventions laissées de côté ; l'entrée « Durée prévue de copie » précise qu'elle sert aussi au planning.

## Exemples

Un seul personnage, 1 slot de laboratoire, 1 de fabrication, ancre T.

| # | Situation | Résultat |
|---|---|---|
| E1 | Copie X (runs 10, base 480 s/run, Science 5, Adv. Industry 5, structure sans bonus) puis fabrication X (3 600 s) | copie [T, T+3 060 s] (10 × 480 × 0,75 × 0,85), fabrication démarre à la fin de la copie, `plannedEnd` = fin de fabrication |
| E2 | Même cas, 2 copies (X et Y) et 1 seul slot de laboratoire | les deux copies se suivent sur le slot, la seconde commence à la fin de la première |
| E3 | Deux copies, 2 slots de laboratoire (Laboratory Operation 1) | elles tournent en parallèle |
| E4 | Copie sans structure prévue | `unscheduled` : `duration_unknown` ; la fabrication de X : `dependency_unscheduled` ; `plannedEnd` null |
| E5 | Projet T2 avec une ligne `invention` de Z et sa copie T1 | la copie T1 est planifiée ; Z est dans `unscheduled` avec `invention_not_planned` ; `plannedEnd` compte la copie mais pas l'invention |
| E6 | Aucune ligne `copy` ni `invention` | résultat identique à aujourd'hui (aucune régression) |
| E7 | Personnage sans compétence en cache | aucune voie planifiable, comportement actuel (`skillsUnknown`) |
| E8 | Structure de copie supprimée depuis la création | `structureTimeKnown` false, durée sans bonus de structure, avis existant « structure plus configurée » |

## Cas limites

- Une ligne `copy` couverte par le stock (0 run) reste exclue du plan.
- Plusieurs copies du même type (agrégées par la BOM) : une seule ligne, aucune fusion à faire.
- Une copie de blueprint T1 pour invention (type T1 sans ligne de fabrication dans le projet) n'alimente aucune fabrication via R6 ; elle compte seulement dans la fin planifiée. Limite connue : si T1 est aussi fabriqué dans le projet, sa fabrication attend cette copie à tort (la ligne ne porte pas son origine).
- Une ligne `copy` n'a aucune dépendance : elle n’attend pas les composants du produit (revue). Son retard n'est jamais attribué à des matériaux manquants (`unknown`).
- Tant que #1193 ne rapproche pas de jobs de copie, une copie est toujours replanifiée depuis maintenant : fin attendue et retard d'un projet en cours sont surestimés.

## Hors périmètre

- Planifier l'invention (durée, structure, décrypteur : #1205 et suites) et rapprocher les jobs ESI de copie et d'invention (#1193).
- Afficher le planning en flux (#1312) ; aucun nouvel écran, aucun nouveau topic Mercure, pas de migration.
- Rapprochement des jobs réels de copie : les lignes de copie n'ont pas de job lié tant que #1193 n'est pas fait.

## Décisions

- Invention laissée de côté et listée, plutôt que planifiée avec une durée inventée (consigne « jamais par défaut »).
- Raison `invention_not_planned` ajoutée à `unscheduled` plutôt qu'un nouveau champ : l'écran existant l'affiche déjà.
- `ScheduleWorker::$laboratorySlots` a pour défaut 0 (les 21 sites d'appel des tests du noyau restent valides) ; `PlanningCharacter::$scienceSlots` est obligatoire.
