# 1051 (#1006 lot 3) : coût incomplet et répartition bloquée tant que du stock est non réclamé

Lots 1 (#1053) et 2 (#1055) livrés : prix de lancement figé, stock non réclamé calculé en continu, réclamer / attribuer / donner. Ce lot fait du stock non réclamé un coût inconnu : tant qu'une ligne en porte, le coût du projet n'est pas complet.

## Règles

1. **Lignes à stock non réclamé** (`UnclaimedStockLines::of(project)`) : lignes matériau dont `UnclaimedStock` (lot 1) est strictement positif. Projet ni `in_progress` ni `selling`, ligne de job, ou stock du container inconnu : aucune ligne (rien d'inventé, comme au lot 1).
2. **Coût du projet** (`ProjectDistributionService`) : `totalProjectCost` vaut `null` (inconnu) tant que la liste n'est pas vide, même si tous les apports approuvés ont une valeur. Les effets en cascade existants s'appliquent seuls : marge, marge projetée, parts, payouts, coût unitaire, point mort et profit du spectateur passent à `null`.
3. **Raison** : quand la liste n'est pas vide, `breakEvenUnknownReason` et `costPerUnitUnknownReason` valent `unclaimed_stock` à la place de `cost_unknown` (les raisons plus amont, produit final absent ou jobs non livrés, restent prioritaires).
4. **Ressource** `ProjectDistributionResource` : `unclaimedStockLines: list<{bomLineId, typeId, typeName, quantity}>` (vide si aucune).
5. **Répartition bloquée** : `ProjectSettlementCalculator` renvoie `transfersUnavailableReason = unclaimed_stock` (nouveau cas de `TransfersUnavailableReason`) dès que la liste n'est pas vide, après `awaiting_sales` et `unsold_products`, avant les frais et le coût inconnu. Aucun virement n'est proposé.
6. **Front** : quand `unclaimedStockLines` n'est pas vide, `DistributionTable` et `FinancialSummary` affichent à la place du message « apport sans prix connu » un message qui liste les lignes (nom et quantité) ; `ProjectTransfers` et `FinancialCascade` affichent la raison `unclaimed_stock`. FR et EN. Pages d'aide (calculs) mises à jour.

## Exemples

| Apports approuvés | Non réclamé | Coût du projet | Point mort | Virements |
|---|---|---|---|---|
| 1 000 ISK (tous valorisés) | 0 | 1 000 | calculé | calculés |
| 1 000 ISK | 50 Tritanium | `null` | `unclaimed_stock` | `unclaimed_stock` |
| 1 000 ISK | container illisible | 1 000 (comme avant) | calculé | calculés |
| 1 000 ISK | 50, projet `completed` | 1 000 | calculé | calculés |

## Cas limites

- Réclamer, attribuer ou donner tout le non réclamé vide la liste : le coût redevient connu à la lecture suivante, rien n'est persisté.
- Un participant sans prix de lancement (ligne sans prix figé) : la ligne reste listée, le propriétaire peut la donner (lot 2).

## Hors périmètre

Produits intermédiaires (`IntermediateStockProposer`), rattrapage des projets déjà en cours, suppression d'un message existant (« apport sans prix connu » reste pour le cas d'une valeur manquante).

## Décisions prises en autonomie

- Stock du container inconnu : ne bloque pas (on ne peut pas lister de ligne, comportement antérieur conservé).
- Le total inconnu reprend le chemin existant `null` plutôt qu'un second drapeau : un seul état « coût incomplet » pour toutes les surfaces.
- `UnclaimedStock::ofLine` factorise le calcul partagé par la BOM et la liste de blocage.
