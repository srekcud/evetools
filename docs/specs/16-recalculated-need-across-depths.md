# Spec #16 — Le recalcul additionne le besoin d'un matériau consommé à plusieurs profondeurs

## Contexte

`IndustryStepCalculator::recalculateStepQuantities()` (appelé par `UpdateStepProcessor`, `LinkJobProcessor`, `IndustryJobMatcher`) parcourt les étapes profondeur par profondeur et remet les besoins à zéro à chaque profondeur. Un intermédiaire consommé par des étapes de profondeurs différentes ne garde que le besoin de la dernière profondeur qui le consomme. Sur Fernite Carbide (Tatara), le Hydrogen Fuel Block ne garde que les 5 unités du job racine et perd les 10 des réactions (`testRecalculatedFuelBlockOfFerniteCarbideCurrentlyKeepsOnlyTheNeedOfTheRootIssue16`).

## Règles métier

- **R1 — Besoin d'un intermédiaire.** Le besoin recalculé d'une étape est la somme, sur **toutes** les étapes du projet qui consomment son produit (quelle que soit leur profondeur), des matériaux de chaque job calculés avec ses propres runs (règle job par job de #73 inchangée).
- **R2 — Ordre.** Le besoin d'un produit n'est fixé qu'après que tous ses consommateurs ont leurs runs définitifs : les étapes sont traitées dans l'ordre du graphe consommateur → matériau, pas dans l'ordre du `depth` persisté.
- **R3 — Le reste inchangé.** Formule des matériaux, bonus de structure (`getStructureBonusForStep()`), répartition d'un groupe découpé (runs au prorata, quantités selon #112), étapes de copie ignorées, liste des étapes modifiées renvoyée.

## Exemples chiffrés

Sans structure sauf mention, ME 10 / TE 20, golden SDE.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Fernite Carbide 1 run, Tatara/Raitaru : Hydrogen Fuel Block consommé par la racine (5) et par les réactions de profondeur 1 (10) | `[1, 5, …]` | besoin 15, runs `ceil(15 / unités par run)` |
| E2 | Nomad 1 run : Hydrogen et Nitrogen Fuel Block consommés à plusieurs profondeurs | Hydrogen 324 → 9 runs, Nitrogen 107 → 1 run (besoin de la seule profondeur la plus profonde) | somme de tous les consommateurs ; valeurs à chiffrer sur le SDE des goldens en phase rouge (attendu proche du plan Ravworks : Hydrogen 322 runs, Nitrogen 106) |
| E3 | Matériau consommé à une seule profondeur | inchangé | inchangé (garde-fou) |
| E4 | Recalcul deux fois de suite | — | le second ne modifie aucune étape (idempotence) |
| E5 | Groupe découpé 3 + 2 runs, nouveau total 7 | `[4, 3]` | `[4, 3]` (garde-fou) |

## Cas limites

- Produit consommé par une étape et par le groupe découpé d'une autre : chaque job compte (R1).
- Graphe sans cycle (un produit ne se consomme pas lui-même dans un plan) : si un cycle apparaissait, erreur explicite plutôt qu'un besoin partiel.
- Étape achetée ou en stock : traitement d'aujourd'hui (le recalcul n'en tient pas compte), inchangé.

## Hors périmètre

- Groupe découpé qui garde plus de runs que le besoin (`max(1, …)` par job, `testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOneIssue131`) : noté dans `.claude/tasks/issues-proposals.md`.
- Création et régénération (agrégation entre branches de l'arbre, #69).
- Correction du champ `depth` persisté.
- Arbre affiché : un job de fuel block par branche (`testTreeOfOneRunOfFerniteCarbideInATataraCurrentlyHasOneFuelBlockJobPerBranchIssue16`, lecture de `IndustryTreeService`, voir #69 et #121).

## Décision prise en autonomie

- Le `max(1, …)` d'un groupe découpé cité dans l'issue est sorti du périmètre : le corriger demande de supprimer des jobs (avec leurs jobs matchés et achats) ou d'accepter des jobs à 0 run, une décision produit. L'option conservatrice garde le comportement actuel.
- R2 par ordre du graphe plutôt que par `depth` : le `depth` persisté ne reflète pas la profondeur maximale d'un produit consommé à plusieurs niveaux.

## Tests existants appelés à changer

- `testRecalculatedFuelBlockOfFerniteCarbideCurrentlyKeepsOnlyTheNeedOfTheRootIssue16` (E1), `testRecalculatingTheStepsOfOneNomadCurrentlyKeepsOnlyTheNeedOfTheDeepestConsumerIssue16` (E2, snapshot `nomad-1run-me10-no-structure-recalculated-per-product`) : perdent `Currently…Issue16`.

## Glossaire

Aucun terme ajouté.
