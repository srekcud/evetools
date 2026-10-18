# Spec #111 — Pas de groupe découpé d'un seul job

## Contexte

À la génération des étapes, `IndustryProjectFactory::splitLongJobs()` découpe une étape dont la durée totale dépasse la durée maximale de job du projet (`maxJobDurationDays`). Le nombre de runs par job vaut au moins 1. Une étape d'un seul run plus longue que la durée maximale donne donc un seul job, mais ce job reçoit quand même un `splitGroupId`, un `splitIndex` et un `totalGroupRuns`, et sa quantité devient runs × unités par run au lieu du besoin. L'étape est affichée comme découpée et passe ensuite par la redistribution des groupes découpés au recalcul.

## Règles métier

- **R1 — Un découpage d'un seul job n'est pas un découpage.** Quand le découpage automatique donne un seul job, l'étape reste telle quelle : `splitGroupId` null, `splitIndex` 0, `totalGroupRuns` null, `runs` et `quantity` inchangés (la quantité garde le besoin).
- **R2 — Découpage à partir de deux jobs.** Le découpage automatique ne change pas quand il donne au moins deux jobs.
- **R3 — Découpage manuel.** Un découpage manuel (`POST …/split`) en un seul job est refusé. Déjà en place : `SplitStepInput::$numberOfJobs` porte `Assert\Range(min: 2, max: 20)`, la réponse est une erreur de validation (422). Un test le fige.

## Exemples chiffrés

Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]` et `splitGroupId`.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Quantum Microprocessor, 32 runs, ME 10 / TE 20, durée maximale 0,1 jour (8 640 s). Titanium Carbide : 1 run de 10 800 s, besoin 490 | `[1, 10000, 1, 0, 0, 0, 1]`, `splitGroupId` non null | `[1, 490, 1, 0, 0, 0, null]`, `splitGroupId` null : identique au même projet à durée maximale par défaut |
| E2 | Quantum Microprocessor, 25 runs, ME 10 / TE 20, durée maximale 0,0001 jour (60 s par run) | 25 jobs d'un run, un groupe | inchangé (R2) |
| E3 | Quantum Microprocessor, 25 runs, ME 10 / TE 0, durée maximale 0,02 jour | `[13, 13, 0, 10, 0, 0, 25]`, `[12, 12, 0, 10, 0, 1, 25]` | inchangé (R2) |
| E4 | Découpage manuel d'une étape de 5 runs, `numberOfJobs` = 1 | 422 | 422, aucune étape modifiée |

## Cas limites

- Étape de copie ou sans durée par run (`timePerRun` null) : jamais découpée (inchangé).
- Recalcul d'un projet existant : la régénération applique R1.

## Hors périmètre

- Projets déjà en base portant un groupe découpé d'un seul job : corrigés à la prochaine régénération, pas de migration.
- Signal « dépasse la durée maximale » exposé par l'API.
- Quantité d'une étape découpée en au moins deux jobs (unités produites au lieu du besoin) : #112.
- Ordre des groupes découpés : #110.

## Tests existants appelés à changer

- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testSplitJobHoldsTheProducedUnitsWhileAnUnsplitStepKeepsTheNeed` fige le comportement actuel sur E1 : à remplacer par le test de R1.
- `…CurrentlyOverplanTheFuelBlocksSummedAcrossBranchesIssue69` et `…CurrentlyKeepsOnlyTheNeedOfTheDeepestConsumerIssue16` : sur le Nomad à durée maximale par défaut, Fenrir et Nomad forment aujourd'hui un groupe découpé d'un seul job ; `totalGroupRuns` passe de 1 à null (snapshots `nomad-1run-me10-no-structure-*.json`).
- `testSplitGroupsAreCurrentlyOrderedByTheirRandomIdentifier` : 7 niveaux attendus au lieu de 8, le groupe découpé d'un seul job disparaît.
- `testOneTypeWithoutBaseTimeDoesNotStopTheSplitOfTheOthers` : Nanotransistors, Nonlinear Metamaterials et Phenolic Composites font 1 run chacune (cas E1), elles ne forment plus de groupe découpé ; Quantum Microprocessor reste découpé.
- `testDurationOfAnIntermediateCurrentlyUsesTeTwentyWhateverTheProjectTe` : reposait sur un Oxygen Fuel Block d'un run découpé en un job ; réécrit sur 700 runs de Quantum Microprocessor (2 runs d'Oxygen Fuel Block), vert avant et après R1.

## Glossaire

Terme proposé pour [`docs/glossary.md`](../glossary.md) :

- **Groupe découpé** (Split group) : au moins deux jobs issus d'une même étape, partageant un `splitGroupId`, dont la somme des runs vaut `totalGroupRuns`. Code : `IndustryProjectStep::$splitGroupId`, `isSplit()`.
