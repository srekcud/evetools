# Spec #250 : le ME d'une réaction n'est jamais appliqué, même enregistré

## Contexte

Une réaction n'a pas de ME (glossaire « ME », « Réaction »). Le noyau l'ignore (`MaterialQuantity::forReactionJob`). La formule historique (`MaterialQuantityFormula::forJob`) applique le ME qu'on lui passe, sans connaître l'activité : chaque appelant doit passer 0.

L'investigation (`.claude/tasks/250-investigation.md`) montre qu'aucun chemin d'écriture ne crée plus de ME non nul sur une réaction (#190 : 400 au PATCH, ignoré à l'ajout, `ReactionStepMeLevelReset` sur les projets `active`). Mais des projets créés avant ce correctif peuvent encore porter un ME de réaction enregistré (projets `completed` ou `archived` hors de la commande, commande pas encore lancée en production : inconnu), et quatre endroits le lisent ou le recopient sans regarder l'activité :

| Endroit | Effet |
|---|---|
| `ProductionStepNeedsResolver::materialQuantityOf` | liste d'achats et arbre affiché |
| `IndustryStepCalculator` (cascade, consommateur) | quantité et runs des étapes de matériaux |
| `SplitGroupMaterialNeeds::needOfConsumer` / `applyChangeOfJobs` | écart de besoin au découpage ou à la fusion de jobs |
| `SplitStepProcessor` (nouveaux jobs) | recopie le ME de l'étape sur les jobs créés |

Les appelants qui passent déjà 0 (`PivotAdvisorService`, `BatchProfitScannerService`, `IndustryTreeService`, `IndustryProjectFactory` via `SplitGroupMaterials`) ne changent pas.

## Règles métier

- **R1 : ME appliqué.** Le ME appliqué à une étape est 0 si son activité est une réaction, son ME enregistré sinon. Une seule méthode de l'étape (`appliedMeLevel()`) porte cette règle.
- **R2 : lecteurs.** `ProductionStepNeedsResolver::materialQuantityOf`, `IndustryStepCalculator` (consommateur) et `SplitGroupMaterialNeeds` calculent la quantité de matériau avec le ME appliqué, jamais avec `getMeLevel()`.
- **R3 : découpage.** Les jobs créés par `SplitStepProcessor` prennent le ME appliqué de l'étape découpée : une réaction donne des jobs à ME 0, une fabrication garde son ME.
- **R4 : ME enregistré inchangé.** Aucune de ces corrections ne réécrit le ME enregistré d'une étape existante ni n'expose un autre ME à l'écran : la normalisation des données reste à `app:industry:reset-reaction-me-levels` (#190). `IndustryResourceMapper` et `GroupBomProvider` ne changent pas.
- **R5 : fabrication inchangée.** Pour une étape de fabrication ou une copie, toutes les quantités sont identiques à aujourd'hui.

## Exemples chiffrés

Matériau à 100 unités par run, bonus de structure de base 0, rig 2,64, ME enregistré 10 sur la réaction (valeurs de `testLegacyFormulaCurrentlyAppliesTheMeToAReactionIssue141`). Les nombres sont ceux de la formule historique ; le test rouge les recalcule avec elle.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | `materialQuantityOf`, job de réaction, 10 runs, ME enregistré 10 | 877 | 974 |
| E2 | `materialQuantityOf`, job de fabrication, 10 runs, ME 10 | 877 | 877 |
| E3 | `materialQuantityOf`, job de réaction, 10 runs, ME enregistré 0 | 974 | 974 |
| E4 | Cascade `IndustryStepCalculator` : consommateur réaction à ME 10, 10 runs, un matériau produit par une étape du projet | besoin 877 | besoin 974, identique au même projet à ME 0 |
| E5 | `SplitGroupMaterialNeeds`, consommateur réaction à ME 10, jobs 10 → 4 + 6 | écart 0 (877 → 351 + 526) | écart +1 (974 → 390 + 585) |
| E6 | `SplitGroupMaterialNeeds`, consommateur fabrication à ME 10, jobs 10 → 4 + 6 | écart 0 | écart 0 |
| E7 | `SplitStepProcessor`, réaction à ME 10 découpée en 2 jobs | nouveau job à ME 10 | nouveau job à ME 0 |
| E8 | `SplitStepProcessor`, fabrication à ME 10 découpée en 2 jobs | nouveau job à ME 10 | nouveau job à ME 10 |

## Cas limites

- **Groupe découpé dont certains jobs sont à ME 10 et d'autres à 0** (ME enregistrés mixtes) : chaque job est lu avec 0, le besoin du groupe est le même qu'à ME 0 partout.
- **Réaction sans bonus de structure ni rig** : 100 × 10 = 1 000, quel que soit le ME enregistré.
- **Ancienne donnée à ME 10 après la commande #190** : plus aucun écart, les deux chemins donnent 0.
- **Activité `copy`** : jamais traitée comme une réaction ; son ME enregistré reste lu comme avant (R5).

## Hors périmètre

- Changer la signature de `MaterialQuantityFormula::forJob` (7 appelants, aucune connaissance de l'activité).
- Étendre `app:industry:reset-reaction-me-levels` aux projets `completed` et `archived` (#190 R2 les exclut, décision de Sylvain) ; le lancer en production est une action de Sylvain.
- Faire utiliser `appliedMeLevel()` par `CreateStepProcessor:205` ni par `ReactionStepMeLevelReset` : ils fonctionnent, le refactor n'apporte rien à ce lot.
- Migration de ces écrans sur le noyau (#141).

## Termes du glossaire

- **ME appliqué** (Applied ME) : ME réellement utilisé dans le calcul des matériaux d'une étape : 0 pour une réaction, ME enregistré sinon (`IndustryProjectStep::appliedMeLevel()`). Ajouté.
- **ME** : ligne complétée, un ME de réaction déjà enregistré est ignoré en lecture. Modifié.

## Documents à mettre à jour (phase verte)

- `docs/modules/industry.md:82` : la ligne « les ME de réaction déjà enregistrés ne sont pas normalisés par une commande (hors lot) » contredit `ReactionStepMeLevelReset` (:41) ; la remplacer par la règle R1 à R4.

## Décisions prises en autonomie (mode nuit)

- Correction côté lecteurs plutôt que de s'en remettre à la seule commande #190 : les projets `completed` ou `archived` qui repassent actifs, et une commande pas encore lancée en production, laissent des ME de réaction lus.
- Une méthode d'étape (R1) plutôt que trois ternaires ; formule historique inchangée (7 appelants).
- Quantités affichées : pour les seules réactions à ME enregistré non nul, les matériaux montent d'environ 11 % (877 → 974). C'est la valeur correcte (glossaire : une réaction n'a pas de ME), elle ne concerne aucune donnée créée après #190.
