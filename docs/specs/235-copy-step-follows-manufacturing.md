# Spec #235 — L'étape de copie suit les runs et le plafond de la fabrication

## Contexte

Depuis #234, une étape de fabrication dont le blueprint est copié est découpée pour qu'aucun job ne dépasse les runs maximum d'une copie (`maxProductionLimit`). L'étape de copie, elle, reste une étape unique dont les runs sont la somme des runs des branches de l'arbre par produit (`IndustryProjectFactory::collectStepsFromTree()`), sans lien avec le plafond.

Constat en lisant les étapes générées sur le SDE golden (sans structure, ME 10 / TE 20) : les runs de copie ne sont pas ceux de la fabrication. La fabrication reçoit le besoin agrégé sur tout le plan (#69) ; la copie garde la somme des runs arrondis branche par branche. Nomad 2 runs : Hydrogen Fuel Block copie 662 runs, fabrication 642 ; Nomad 1 run : 345 contre 322 ; Oxygen Fuel Block : 66 contre 44 ; Scorpion 25 runs, plaque de blindage : 840 contre 841. L'écart va dans les deux sens.

Le coût de copie est `0.02 × EIV × runs × cost index × (1 + taxe %)` par étape de copie (`InventionService::getCopyJobCost()`) : linéaire en runs, il suit donc la somme des runs de copie.

## Règles métier

- **R1 — Runs de copie.** Le blueprint d'une étape de fabrication qui a une activité de copie (comme R1 de #234) a des étapes de copie dont la somme des runs vaut la somme des runs des jobs de fabrication de ce blueprint (hors étapes à 0 run ignorées). Elle ne dépend plus de la somme par branche de l'arbre.
- **R2 — Nombre de copies.** N = somme de R1 ; plafond = `maxProductionLimit` du blueprint. Le nombre de jobs de copie vaut `ceil(N / plafond)`. La durée maximale de job ne découpe pas la copie : un plan de 300 runs, plafond 200, fabrication en 3 jobs de 100 par la durée, donne 2 copies de 150 runs.
- **R3 — Répartition.** Runs répartis également comme pour la fabrication (`SplitGroupQuantities::runsBySplitIndex()`), les premiers jobs prenant le reste. Aucun job de copie ne dépasse le plafond. Quand le plafond est la contrainte qui décide du nombre de jobs de fabrication, les copies et les jobs de fabrication ont les mêmes runs, dans le même ordre de `splitIndex`.
- **R4 — Forme d'un groupe de copies.** Deux copies ou plus forment un groupe découpé (`splitGroupId` commun, `splitIndex` à partir de 0, `totalGroupRuns` = N) ; une seule copie reste une étape intacte (`splitGroupId` et `totalGroupRuns` nuls, #111). `quantity` = `runs` (une copie par run), profondeur et niveau de planification de l'étape de copie d'avant, ME et TE à 0, pas de structure.
- **R5 — Création = régénération.** Régénérer un projet juste créé donne les mêmes étapes de copie ; le recalcul (#16) d'un projet juste créé ne modifie aucune étape de copie.
- **R6 — Écrans dérivés.** Le coût de copie (`CopyCostsProvider`, une ligne par job de copie) et le total des copies suivent les étapes sans changement de code. Le total change là où les runs de R1 diffèrent de l'ancienne somme par branche.
- **R7 — Plafond absent.** Même erreur que R7 de #234 (blueprint absent de `sde_industry_blueprints` ou limite ≤ 0) : la génération échoue en nommant le blueprint.

## Exemples

SDE golden, ME 10 / TE 20, sans structure, mêmes plafonds que la spec #234. Colonnes : liste des runs des jobs de copie, dans l'ordre de `splitIndex`. « Aujourd'hui » lu sur la factory ; « Attendu » dérivé de R1 à R3, à recouper par les tests rouges.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| C1 | Nomad 2 runs, durée max 2 j : copie de Hydrogen Fuel Block (fabrication 161 + 161 + 160 + 160) | `[662]` | `[161, 161, 160, 160]` |
| C2 | C1 : copie de Nitrogen et de Helium Fuel Block (fabrication 106 + 105 ; 106 + 106) | `[219]` ; `[220]` | `[106, 105]` ; `[106, 106]` |
| C3 | C1 : copie d'Oxygen Fuel Block (fabrication 86) | `[106]` | `[86]` |
| C4 | Nomad 1 run, durée max 2 j : copies de Hydrogen, Oxygen, Nitrogen, Helium Fuel Block (fabrication 161 + 161 ; 44 ; 106 ; 107) | `[345]` ; `[66]` ; `[114]` ; `[115]` | `[161, 161]` ; `[44]` ; `[106]` ; `[107]` |
| C5 | Nomad 1 run, sans durée max : copie de Capital Electrolytic Capacitor Unit (fabrication 476 + 475, plafond 800) | `[951]` | `[476, 475]` |
| C6 | Scorpion 25 runs : copies du Scorpion (fabrication 9 + 8 + 8), de la Auto-Integrity Preservation Seal (841 runs), de la Life Support Backup Unit (421 runs), plafond 200 | `[25]` ; `[840]` ; `[420]` | `[9, 8, 8]` ; `[169, 168, 168, 168, 168]` ; `[141, 140, 140]` |
| C7 | Widow 3 runs, plafond 1 | `[3]` | `[1, 1, 1]` |
| C8 | C1 : toutes les autres copies du plan | runs de l'ancienne somme par branche | runs de la fabrication de leur blueprint (ex. blueprint 1021017 : 9 devient 10 ; 1021027 : 63 devient 64) |

## Cas limites

- N égal au plafond : une seule copie. N d'un run au-dessus : deux copies (R2, comme E9 de #234).
- Plafond de 1 (Nomad, Fenrir, Widow) : une copie par run.
- Jobs de fabrication fixés (#185) : la copie suit la somme de leurs runs, plafond appliqué comme pour tout N. Un job fixé au-dessus du plafond (322 runs, plafond 200) donne deux copies ; il ne tient pas dans une seule, c'est le cas déjà admis par #234 (G7).
- Étape de fabrication achetée, en stock, ou produit blacklisté : l'étape de copie n'existe que si l'arbre en porte une aujourd'hui ; le critère ne change pas.
- Blueprint sans activité de copie (Marshal, T2 inventés, réactions) : pas d'étape de copie, rien ne change.
- Étape ajoutée à la main : hors génération.

## Hors périmètre

- Kit de BPC et BPC déjà possédé (copie à ne pas planifier) : inchangés.
- Durée d'un job de copie, `jobMatchMode` et rapprochement avec les jobs ESI de copie : lus tels quels sur les nouvelles étapes.
- Mise à jour d'une étape de fabrication après la génération (changement de runs, découpage ou fusion manuels) : les étapes de copie ne suivent pas ; la cascade (#16) ignore la copie aujourd'hui.
- Group Industry (`GroupIndustryProjectService`) et ses lignes de copie.
- Projets déjà en base : corrigés à la prochaine régénération, sans migration.
- Branchement du `ProductionPlanner` (rang 7).

## Décisions validées (2026-10-23)

1. **Source des runs de copie.** Somme des runs des jobs de fabrication, pas la somme par branche : corrige l'écart avec #69 en même temps que #235.
2. **Représentation.** Un groupe découpé de jobs de copie, sans champ « nombre de copies » ni migration.
3. **Nombre de copies.** `ceil(N / plafond)`, même répartition ; la durée maximale ne découpe pas la copie.

## Glossaire

- **Plafond de runs d'un BPC** : ajouter qu'il découpe aussi l'étape de copie de ce blueprint, en autant de copies que de jobs nécessaires (spec #235).
- **Étape** : la copie d'un blueprint est une étape (activité `copy`) qui peut former un groupe découpé.
