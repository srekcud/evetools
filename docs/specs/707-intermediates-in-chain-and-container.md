# Spec #707 : la Chaîne et le container vérifient les intermédiaires

Mode nuit (2026-11-11) : spec rédigée sans gate, branche empilée sur `fix/night-706` (#710). Affichage seulement, tous projets (solo et groupe) : rien n'est déduit de la BOM. Plan : `.claude/tasks/intermediate-stock-plan.md`, lot 2.

## Constat

- `ChainMaterialSupply::allocate` ne compte que les lignes de matériaux (`isJob = false`) : un intermédiaire consommé par un job est sauté (`continue`), donc un job « approvisionné » peut attendre un intermédiaire dont le job producteur n'a rien livré.
- `ProjectContainerService::verifyContainer` ne lit que `findMaterialsByProject` : les intermédiaires attendus dans le container ne sont jamais vérifiés.

## Règles

1. **Stock disponible d'un type** dans `ChainMaterialSupply` : pour une ligne de matériau, `fulfilledQuantity` (inchangé) ; pour une ligne de job `component` ou `final`, `fulfilledQuantity` (apports d'intermédiaire, #706) ; une ligne `component` ajoute sa production livrée (une ligne `final` est le produit du projet, jamais fourni à un autre job).
2. **Production livrée d'une ligne de job** : `intdiv(remainingQuantity × min(runsLivrés, runs), runs)`, où `runsLivrés` = somme des `runs` des jobs liés au statut `delivered` et `remainingQuantity = max(0, requiredQuantity − fulfilledQuantity)` (la production prévue, spec #706 règle 4). Une ligne à 0 run (couverte par le stock) ne livre rien, sans division par zéro.
3. Un job est approvisionné si chacun de ses besoins (matériaux et intermédiaires) reçoit sa quantité du stock disponible. Les jobs sont servis par niveau croissant puis nom, comme aujourd'hui ; le stock d'un type se partage entre ses consommateurs.
4. Un besoin dont le type n'a aucune ligne (matériau ou job) dans la BOM reste ignoré (comportement actuel).
5. Un job lié en cours (non livré) ne compte pas : la production livrée ne regarde que `delivered`.
6. **Container** : `verifyContainer` ajoute aux lignes de matériaux les lignes de job `component` (intermédiaires consommés par un autre job du projet), avec `requiredQuantity` de la ligne de job et les mêmes statuts (`verified`, `partial`, `missing`, `container_not_found`, `unknown`). Les lignes `final` et `blueprint` (copie, invention) ne sont pas vérifiées. Ordre : matériaux puis intermédiaires. Sans container nommé, ou sans aucune ligne, la réponse reste `[]` et les assets ne sont pas lus.
7. **Front** : `JobsTable` affiche sur les lignes de job `component` le badge d'état du container (vérifié, partiel, absent, même rendu que `BomTable`) ; `ContainerBanner` liste déjà l'écart « approuvé mais absent du container » pour toute ligne de `bomItems`, intermédiaires compris.

## Exemples chiffrés

| Cas | Entrée | Résultat |
|---|---|---|
| Composant rien livré | final consomme 5 du composant (10 runs, 0 livré) | final non approvisionné |
| Composant livré en totalité | 10 runs livrés sur 10, requis 10 | final approvisionné |
| Composant livré en partie | 4 runs livrés sur 10, requis 10, besoin 5 | non approvisionné |
| Stock plus livraison | requis 10, apporté 4, 6 runs dont 3 livrés : 3 + 4 = 7 | besoin 7 couvert, besoin 8 non |
| Composant en cours | job lié `active` | non approvisionné |
| Container | composant requis 80, 30 dans le container | `partial`, 30 / 80 |

## Hors périmètre

Aucune déduction de BOM, aucun nouveau champ ni endpoint, `BomTable`, `GroupIndustryDetail` et `containerStockOf` (#708) inchangés, statut `blocked` de la Chaîne inchangé, apport d'un container à la proposition de stock.
