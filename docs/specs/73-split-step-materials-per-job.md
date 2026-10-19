# Spec #73 — Les matériaux d'un groupe découpé sont calculés job par job

## Contexte

En jeu, l'arrondi des matériaux s'applique à chaque job : `max(runs, ceil(round(base × runs × ME × structure × rig, 2)))`. Trois jobs de 11 + 11 + 10 runs peuvent donc consommer plus qu'un job de 32 runs (D11b, R13 de la spec du noyau).

Aujourd'hui :

- **Recalcul** (`IndustryStepCalculator::recalculateStepQuantities()`) : déjà job par job, chaque étape du groupe compte avec ses propres runs (`testSplitConsumerRecomputesTheNeedOfItsMaterialJobByJob`).
- **Création et régénération** (`IndustryProjectFactory::createProject()`, `regenerateSteps()`) : les besoins des intermédiaires viennent de l'arbre et de `recalculateReactionQuantities()`, calculés sur le job non découpé du consommateur ; `splitLongJobs()` découpe ensuite sans revoir les besoins des enfants (`testSplitConsumerCurrentlyKeepsTheNeedOfTheUnsplitJobAtCreationIssue73`).
- **Découpage et fusion manuels** (`SplitStepProcessor`, `MergeStepsProcessor`) : seuls les runs et la quantité de l'étape changent, les besoins de ses matériaux restent ceux d'avant.

## Règles métier

- **R1 — Besoin d'un intermédiaire.** Le besoin d'une étape intermédiaire est la somme, sur chaque job de chaque étape qui la consomme, des matériaux de ce job calculés avec ses propres runs. Un groupe découpé compte job par job ; un job non découpé compte une fois.
- **R2 — Création et régénération.** Les besoins respectent R1 après le découpage automatique (durée maximale de job). Le traitement descend profondeur par profondeur : un consommateur est découpé avant que le besoin de ses matériaux soit fixé ; si ce besoin change les runs d'un intermédiaire, son propre découpage et ses propres matériaux suivent.
- **R3 — Découpage et fusion manuels.** Après un découpage ou une fusion, le besoin de chaque étape matériau directe du groupe varie de `Δ = Σ matériaux des nouveaux jobs − Σ matériaux des anciens jobs`. Les autres consommateurs de ce matériau ne sont pas recomptés. L'étape matériau applique alors la règle du recalcul : runs = `ceil(besoin / unités par run)`, groupe découpé réparti comme aujourd'hui (runs au prorata, quantités selon la spec #112).
- **R4 — Pas de recalcul complet.** Le découpage et la fusion manuels n'appellent pas `recalculateStepQuantities()`, qui porte encore #16 (besoins perdus entre profondeurs).
- **R5 — Runs et quantité du groupe inchangés.** La répartition des runs et des quantités du groupe découpé lui-même suit les specs #111 et #112, sans changement.

## Exemples chiffrés

Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]`. Quantum Microprocessor demande 17 Titanium Carbide par run à ME 10, sans structure : un job de 32 runs → `ceil(489,6)` = 490 ; trois jobs de 11 + 11 + 10 → 169 + 169 + 153 = 491.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Création : Quantum Microprocessor, 32 runs, ME 10 / TE 20, durée maximale 0,01 jour (11 + 11 + 10), Titanium Carbide non découpé | Titanium Carbide `[1, 490, 1, 0, 0, 0, null]` | `[1, 491, 1, 0, 0, 0, null]` |
| E2 | Régénération du même projet | 490 | 491, étapes identiques à E1 |
| E3 | Découpage manuel en 3 jobs d'une étape Quantum Microprocessor de 32 runs (Titanium Carbide 490) | 490 | 491 (Δ = +1) |
| E4 | Fusion du groupe de E3 | 491 | 490 (Δ = −1) |
| E5 | Création : intermédiaire de fabrication (pas une réaction) consommé par un groupe découpé | besoin du job unique | somme des besoins par job ; valeurs à chiffrer sur le SDE des goldens en phase rouge |
| E6 | Découpage manuel dont le Δ fait dépasser la production d'un intermédiaire (besoin > runs × unités par run) | runs inchangés | runs = `ceil(besoin / unités par run)` |
| E7 | Matériau consommé par le groupe découpé et par une autre étape : découpage manuel | inchangé | besoin de l'autre consommateur conservé, seul Δ s'ajoute |

## Cas limites

- Produit à 1 matériau par run qui retombe sur `max(runs, …)` : un job de 1 run consomme au moins 1 unité, la somme par job peut dépasser le calcul global (même règle R1).
- Étape de copie : ne consomme aucun matériau du plan, aucun Δ.
- Étape matériau achetée ou en stock : son besoin varie de Δ comme au recalcul ; le traitement de l'achat et du stock reste celui d'aujourd'hui.
- Δ nul (arrondi favorable) : aucune étape modifiée.
- Fusion qui ramène un intermédiaire à un besoin inférieur : runs réduits par la même règle, minimum 1 run par job (inchangé).

## Hors périmètre

- Liste d'achats, coût de production, arbre affiché, Buy vs Build, marges, stockpile : ils lisent `IndustryTreeService`, qui ignore le découpage. Issue séparée.
- Propagation de Δ aux petits-enfants lors d'un découpage manuel : R3 s'arrête aux matériaux directs (voir décision ci-dessous).
- #16 (besoins perdus entre profondeurs au recalcul) et `max(1, …)` d'un groupe recalculé qui garde trop de runs.
- Découpage par le plafond de runs d'un BPC (déjà job par job dans le noyau `src/Industry/Domain`).
- Projets déjà en base : corrigés à la prochaine régénération, pas de migration.

## Tests existants appelés à changer

- `testSplitConsumerCurrentlyKeepsTheNeedOfTheUnsplitJobAtCreationIssue73` : Titanium Carbide 490 → 491 (E1), le test perd `Currently…Issue73`.
- Snapshots des goldens créés avec une durée maximale qui découpe un consommateur : à vérifier en phase rouge (liste à établir dans la note de câblage).

## Décision prise en autonomie

- **Petits-enfants (R3)** : option la plus conservatrice retenue, le découpage et la fusion manuels ne modifient que les étapes matériaux directes du groupe. Un Δ qui change les runs d'un intermédiaire ne descend pas à ses propres matériaux ; la régénération du projet (R2) remet tout l'arbre en cohérence.
- **Liste d'achats, coût de production, arbre affiché** : pas d'issue GitHub créée en autonomie ; le constat est noté dans `.claude/tasks/issues-proposals.md` pour validation.

- **Périmètre de R1 à la création (option B de la note de câblage `.claude/tasks/73-wiring.md`)** : le besoin d'un intermédiaire garde sa valeur d'aujourd'hui (arbre ou `recalculateReactionQuantities()`), corrigée de `Σ matériaux des jobs − matériaux du job non découpé` pour chaque consommateur découpé. L'agrégation entre branches (#69) n'est pas corrigée ici : elle reste dans le flux principal.
- **Règles de bonus de structure** : chaque chemin garde la sienne (arbre et réactions en R2, `getStructureBonusForStep()` en R3) ; leur unification est hors périmètre.
- **Étapes de copie** : leurs runs ne suivent pas un changement de runs de la fabrication (déjà le cas au recalcul) ; hors périmètre.
- **Produit d'une étape matériau absent du SDE en R3** : refus explicite (400), aucune étape modifiée, comme `SplitStepProcessor::getOutputPerRun()` (pas de repli à 1 unité par run).
- **« Profondeur par profondeur » (R2)** : profondeur maximale du produit dans l'arbre, le `depth` persisté étant faux (#16).
- **Test #112 contredit par R1** : `testQuantitiesOfEachSplitGroupOfOneNomadAddUpToTheQuantityOfTheUnsplitStep` est réécrit, la quantité de chaque groupe découpé valant le besoin job par job de ses consommateurs.

## Glossaire

Aucun terme ajouté : **Besoin**, **Job** et **Groupe découpé** couvrent la règle.
