# Spec #142 #149 — Plafond de runs et structure assignée dans la requête de coût

## Contexte

Le noyau sait déjà plafonner un job aux runs du BPC et le découper (`PlanRequest::$blueprintCopyMaxRuns`, `ProductionPlanner::splitIntoJobs()`, R3 et R13 de `industry-engine.md`), et choisir une structure assignée à une étape (`StructureMaterialModifiers`, troisième paramètre). `ProductionCostCalculator` ne transmet ni l'un ni l'autre : `ProductionCostRequest` n'a aucun champ pour les porter.

Le choix de structure est en outre fait deux fois : pour les matériaux (`StructureMaterialModifiers::forJob()`) puis pour le coût d'installation (`structureChoiceOf()`, sans structure assignée). Les deux peuvent diverger.

Décisions du 2026-10-23 : champ de plafond optionnel, vide par défaut ; une seule sélection de structure par job, partagée entre matériaux et installation. Spec validée le 2026-10-26.

## Règles métier

1. `ProductionCostRequest` porte `blueprintCopyMaxRuns` : plafond de runs par typeId de produit. Vide par défaut : un produit absent de la map n'a pas de plafond (BPO).
2. En mode plan, le calculateur transmet ce plafond au planificateur. Un job dont les runs dépassent le plafond est découpé (R3), les matériaux sont recalculés job par job (R13), et chaque job découpé produit son propre `JobCost`.
3. En mode coût marginal, le plafond est ignoré (D4b, R12).
4. `ProductionCostRequest` porte `assignedStructures` : structure assignée par typeId du produit de l'étape. Vide par défaut.
5. Pour un job, la structure est sélectionnée une seule fois (R4, D3 : assignée, sinon la meilleure de l'utilisateur pour l'activité et le produit). Le `JobCost` porte les bonus de matériaux et le coût d'installation de cette structure.
6. Aucun appelant de production ne change de résultat dans ce lot : sans plafond ni structure assignée, les sorties actuelles sont inchangées.
7. `Recipe::$maxProductionLimit` n'est pas utilisé comme plafond de repli : un BPO n'a pas de plafond.

## Exemples chiffrés (tests rouges)

| Cas | Entrée | Attendu | Aujourd'hui |
|---|---|---|---|
| Plafond (issue #142) | produit T2, 32 runs, plafond du produit à 10 runs, ME 5, mode plan | 4 `JobCost` de 8 runs ; somme des runs = 32 ; aucun job au-dessus de 10 | 1 job de 32 runs |
| Matériaux recalculés (issue #142) | même cas, un matériau consommé | 216 unités sur les 4 jobs de 8 runs | 213 sur le job unique |
| Plafond absent | même produit, 32 runs, aucun plafond | 1 job de 32 runs, 213 unités du même matériau | 1 job de 32 runs |
| Plafond en coût marginal | même cas, mode coût marginal | aucun découpage, quantités fractionnaires inchangées | identique |
| Structure assignée (issue #149) | intermédiaire X assigné à une structure A différente de la meilleure structure B de l'utilisateur | le `JobCost` de X porte les bonus de matériaux de A et le coût d'installation de A | matériaux de A, installation de B |
| Sans structure assignée | même requête, `assignedStructures` vide | le `JobCost` de X porte matériaux et installation de B | identique |
| Sélection unique | structure assignée à X | la structure est choisie une seule fois pour X (statut `Assigned` identique pour matériaux et installation) | deux sélections |

Les valeurs `[TO CONFIRM: quantités de matériaux et montants d'installation du cas à structure assignée]` sont à calculer par `test-writer` à partir des goldens existants, pas inventées ici.

## Cas limites

| Cas | Comportement attendu |
|---|---|
| Plafond ≥ runs du job | aucun découpage |
| Plafond ≤ 0 | refusé à la construction (`Runs`) |
| Structure assignée à un typeId qui n'est pas dans le plan | ignorée, sans erreur |
| Structure assignée incapable de faire l'activité de l'étape (`ProductionStructure::canRun()` faux) | assignation ignorée : meilleure structure adaptée selon D3, avec un avertissement (décision du 2026-10-26). Aujourd'hui `StructureSelector::select()` la prend telle quelle |
| Produit consommé par plusieurs jobs | une seule structure et un seul plafond par typeId |

## Hors périmètre

- Fournir plafond et structures depuis les écrans (migration des appelants, #141).
- Lire `maxProductionLimit` du SDE ou les runs d'un BPC possédé.
- Critère de choix de structure pour copie et invention (#151).

## Termes du glossaire

Ajoutés : **Plafond de runs d'un BPC** (fusionné avec le terme de la spec #234), **Structure assignée**.
