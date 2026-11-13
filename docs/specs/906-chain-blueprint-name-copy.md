# 906 — Copier le nom du blueprint depuis l'arbre de production

## Contexte
Le Planning (#747) porte un bouton « copier le nom du blueprint ». La vue « Arbre des jobs » de l'onglet Production (`ProductionChainJobTree.vue`) n'en a pas, alors que c'est de là que le joueur lance ses jobs.

## Règles
1. Chaque nœud job de la chaîne (`ProjectChainNode`) expose `blueprintName` (`string|null`), résolu à la lecture par `ChainBlueprintNames` au-dessus de `PlanningBlueprintNames::byKey` (aucune résolution SDE dupliquée).
2. Activité `manufacturing` : blueprint de fabrication du `typeId`. Activité `reaction` : formule de réaction du `typeId`. Activité `copy` : blueprint de fabrication du `typeId` (le produit dont on copie le blueprint).
3. Activité `invention`, nœuds `material` et `sale`, `typeId` nul : `blueprintName` nul.
4. Blueprint absent du SDE ou sans nom : `blueprintName` nul, jamais un nom deviné ou le nom du produit.
5. Front : chaque ligne de l'arbre porte `BlueprintNameCopyButton` à côté du nom ; le clic copie `blueprintName`, pas `typeName` ; nom nul, pas de bouton.

## Exemples
| Nœud | Activité | `typeId` | `blueprintName` |
|---|---|---|---|
| Ishtar | manufacturing | 12005 | Ishtar Blueprint |
| Nocxium Atoms | reaction | 16637 | Nocxium Atoms Reaction Formula |
| Ishtar (copie) | copy | 12005 | Ishtar Blueprint |
| Hammerhead II (invention) | invention | 2205 | nul |
| Raw Specimen | manufacturing | 99001 (sans blueprint) | nul |
| Tritanium, sale | n/a | n/a | nul |

## Cas limites
- Plusieurs nœuds du même produit : chacun garde son nom (la clé est l'id du nœud).
- Aucun nœud job : aucune requête SDE de types.

## Accès
Le nom est une donnée SDE publique ; le contrôle d'accès de `GET /industry/projects/{projectId}/chain` (`assertAcceptedMember`) est inchangé.

## Hors périmètre
- Vue d'ensemble (flow), copie groupée.
- Invention : le blueprint T1 à copier ne se déduit pas du `typeId` de la ligne (produit T2), donc pas de bouton plutôt qu'un nom deviné. Question à Sylvain notée dans le journal.

## Décisions
- Copie : blueprint de fabrication du produit (le nom que le joueur cherche pour copier), jugé sans ambiguïté.
- Invention sans bouton, voir ci-dessus.

## Tests
`ChainBlueprintNamesTest`, `ProjectChainProviderTest` (`issue-906`), `productionChainBlueprintCopy.spec.ts`.
