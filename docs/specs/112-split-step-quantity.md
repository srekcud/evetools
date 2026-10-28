# Spec #112 — La quantité d'un job découpé porte le besoin

## Contexte

La quantité d'une étape non découpée est le besoin (unités demandées). Dès qu'une étape est découpée en groupe, chaque job reçoit runs × unités par run, donc la somme du groupe vaut la production et non le besoin. Trois chemins écrivent ces quantités, avec trois règles différentes :

- découpage automatique à la génération (`IndustryProjectFactory::splitLongJobs()`) : runs × unités par run ;
- découpage manuel (`SplitStepProcessor`, `POST …/split`) : quantité = runs, sans tenir compte des unités par run ;
- recalcul des quantités (`IndustryStepCalculator::recalculateStepQuantities()`) : runs × unités par run.

La fusion (`MergeStepsProcessor`) somme les quantités du groupe : elle retrouve le besoin dès que les trois chemins le respectent.

## Règles métier

- **R1 — La somme des quantités d'un groupe découpé vaut le besoin de l'étape.** Elle ne dépend ni de la durée maximale de job, ni du nombre de jobs.
- **R2 — Répartition.** Les jobs sont pris dans l'ordre de `splitIndex`. Chacun reçoit `min(runs × unités par run, besoin restant)` ; le besoin restant ne descend jamais sous 0. En pratique, tous les jobs sont à pleine production sauf le dernier, qui prend le reste.
- **R3 — Les runs ne changent pas.** La répartition des runs (découpage automatique, manuel, recalcul) reste celle d'aujourd'hui.
- **R4 — Mêmes règles sur les trois chemins.** Découpage automatique, découpage manuel et recalcul appliquent R1 et R2. Pour le découpage manuel, le besoin est la quantité de l'étape avant découpage.

## Exemples chiffrés

Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]`.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Quantum Microprocessor, 700 runs, ME 10 / TE 0, durée maximale 0,01666 jour : Oxygen Fuel Block, besoin 55, 40 par run, 2 jobs d'un run | `[1, 40, 2, 10, 20, 0, 2]`, `[1, 40, 2, 10, 20, 1, 2]` (80) | `[1, 40, 2, 10, 20, 0, 2]`, `[1, 15, 2, 10, 20, 1, 2]` (55) |
| E2 | Même projet à durée maximale 0,01667 jour (non découpé) | `[2, 55, 2, 10, 20, 0, null]` | inchangé ; somme égale à E1 (R1) |
| E3 | Quantum Microprocessor, 25 runs, 1 unité par run, durée maximale 0,008 jour | `9, 8, 8` runs et quantités | inchangé (besoin = production) |
| E4 | Découpage manuel en 2 jobs d'une étape de 5 runs, besoin 190, 40 unités par run | runs 3 + 2, quantités 3 + 2 | runs 3 + 2, quantités 120 + 70 |
| E5 | Découpage manuel en 3 jobs d'une étape de 10 runs, besoin 990, 100 unités par run | runs 4 + 3 + 3, quantités 4 + 3 + 3 | runs 4 + 3 + 3, quantités 400 + 300 + 290 |
| E6 | Recalcul : 11 runs de Fernite Carbide demandent 1 100 Fernite Alloy (200 par run), groupe de 3 jobs redistribué à 2 + 2 + 2 runs | quantités 400, 400, 400 | quantités 400, 400, 300 |
| E7 | Recalcul : besoin de 100 Fernite Alloy (1 run), groupe de 2 jobs gardé à 1 + 1 run (minimum 1 run par job) | quantités 200, 200 | quantités 100, 0 |
| E8 | Fusion d'un groupe issu de E5 | 4 + 3 + 3 = 10 | 990 (somme des quantités, besoin retrouvé) |

## Cas limites

- Produit à 1 unité par run : besoin = production, rien ne change (E3).
- Besoin restant épuisé avant le dernier job (E7, conséquence du minimum d'un run par job) : les jobs suivants reçoivent 0. Le nombre de runs en trop n'est pas traité ici.
- Étape de copie : jamais découpée automatiquement (inchangé).
- Réaction : même règle, avec les unités par run de la formule.

## Hors périmètre

- Groupe recalculé qui garde plus de runs que le besoin (E7, test `testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOneIssue131`) : seule la quantité change ici.
- `totalGroupRuns` d'un job inchangé au recalcul (`testUnchangedJobOfARecalculatedSplitGroupCurrentlyKeepsTheOldTotalGroupRunsIssue252`).
- Matériaux d'un job découpé recalculés job par job : #73.
- Saisie manuelle des runs d'une étape (`UpdateStepProcessor`) et rattachement d'un job ESI (`LinkJobProcessor`), qui posent runs × unités par run.
- Projets déjà en base : corrigés à la prochaine régénération ou au prochain recalcul, pas de migration.

## Tests existants appelés à changer

- `testDurationOfAnIntermediateCurrentlyUsesTeTwentyWhateverTheProjectTeIssue116` (groupes `issue-116`, `issue-112`) : le second job d'Oxygen Fuel Block passe de 40 à 15 (E1). #116 est fermée comme voulue : le test peut perdre `Currently…Issue116` et le groupe `issue-116`.
- `testUnchangedJobOfARecalculatedSplitGroupCurrentlyKeepsTheOldTotalGroupRunsIssue252` : quantité du job 2 de 400 à 300 (E6), le reste inchangé.
- `testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOneIssue131` : quantités 200, 200 → 100, 0 (E7), runs inchangés.
- `testRecalculatingTheStepsOfOneNomadCurrentlyKeepsOnlyTheNeedOfTheDeepestConsumerIssue16` : job 1 d'Hydrogen Fuel Block 160 → 155 (besoin 355).
- Snapshots des goldens : aucun ne change (les snapshots Nomad ne portent pas de quantité, celui de Fernite Carbide Tatara n'a pas de groupe découpé).
- Aucun test de `SplitStepProcessor` ni de `MergeStepsProcessor` n'existait : créés.

## Décision prise en autonomie

- E7 portait un besoin de 98 (chiffre du cas Tatara) : sans structure, le besoin vaut 100. Le test Issue16 du Nomad change aussi (besoin d'Hydrogen Fuel Block 355). Valeurs vérifiées sur le SDE des goldens.
- **R5 — Découpage manuel d'une étape de copie** : une copie produit 1 unité (un run de BPC) par run, sans lire le produit de fabrication. Exemple E9 : copie de 10 runs, besoin 10, 2 jobs → 5 + 5.
- **R6 — Produit absent du SDE au découpage manuel** : erreur explicite (400, « Product of blueprint <id> missing from the SDE »), aucune étape modifiée, au lieu d'une unité par run par défaut (règle du projet : pas de valeur plausible pour une donnée manquante). Exemple E10.

## Glossaire

Terme ajouté : **Besoin** (Need).


Précision proposée pour **Groupe découpé** dans [`docs/glossary.md`](../glossary.md) : « La somme des quantités des jobs vaut le besoin de l'étape : chaque job, dans l'ordre de `splitIndex`, reçoit sa production jusqu'à épuisement du besoin (spec #112). »
