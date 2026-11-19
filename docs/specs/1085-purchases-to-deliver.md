# Spec #1085 — Achats à amener au container du projet

Statut : validée en autonomie (mode nuit). Circuit `circuit:m`. Suite de #1022 (`docs/specs/1022-courier-origin-per-purchase.md`), voisine de #1084 (courrier depuis Jita seul).

## Problème

La grille « lignes achetées » du panneau Courrier porte l'état du contrat de courrier lié, pas l'état de livraison. Un achat fait hors de Jita (hub C-J6MT rapatrié à la main) n'a ni courrier ni état utile : rien ne dit si les marchandises sont déjà dans le container du projet.

## Termes

- **Origine d'un achat** : `PurchaseOriginResolver::solarSystemIdOf()` (inchangé), `null` = origine inconnue.
- **Stock du container** : `ProjectContainerFulfillment::of($project)` (`ProjectLineFulfillments`), quantité par type via `containerQuantityOf($line)`, état `ContainerStockState` (`known`, `unknown`, `none`).
- **Quantité manquante** : quantité achetée d'une ligne, moins le stock du container attribué à cet achat, plancher 0.

## Règles

1. **Nouvelle lecture** `GET /industry/projects/{projectId}/purchases-to-deliver` (ressource API Platform `ProjectPurchasesToDeliverResource`, `uriVariables` Link sur `IndustryProjectResource`, provider dédié, accès `assertAcceptedMember` comme le courrier). Aucune modification de `CourierOverviewBuilder` ni de `ProjectCourierResource` (le lot #1084 les modifie). Pas de migration, pas de nouveau scope ESI, rien de persisté.
2. **Lignes** : une par couple (ligne de BOM, origine) pour les achats de quantité > 0, quantités sommées par couple. Les achats faits dans le système de fabrication comptent (le container peut être ailleurs : seul le stock du container fait foi).
3. **Stock attribué** : pour une ligne, le stock du container (`containerQuantityOf`) est attribué aux couples dans cet ordre : Jita, puis les autres systèmes par identifiant croissant, puis l'origine inconnue en dernier. Chaque couple reçoit `min(achetée, stock restant)`. Quantité manquante d'un couple = achetée − reçue.
4. **État de livraison** (`deliveryState`) :
   - `missing` : stock connu, la quantité manquante vaut la quantité achetée ;
   - `partial` : stock connu, 0 < manquante < achetée ;
   - `delivered` : stock connu, manquante = 0 ;
   - `unknown` : `containerQuantityOf` est `null` (stock `unknown`, aucun nom de container `none`, ou ligne non achetable) ; `missingQuantity` vaut alors `null`, jamais 0, jamais `delivered`.
5. **Lignes livrées masquées** : un couple `delivered` n'est pas renvoyé. Si tout est livré, la liste est vide. Un couple `unknown` est toujours renvoyé.
6. **Origine** : `originKind` vaut `jita` (système `EveConstants::JITA_SOLAR_SYSTEM_ID`), `other` (autre système résolu) ou `unknown` (origine non résolue ; `solarSystemId` et `solarSystemName` à `null`). `solarSystemName` vient de `MapSolarSystemRepository`, `null` si absent.
7. **Ressource** : `{id, stockState, purchaseCount, rows}` où `stockState` est `known|unknown|none` (état global du container, pour expliquer les `unknown`) et chaque ligne de `rows` est `{bomLineId, typeId, typeName, originKind, solarSystemId, solarSystemName, purchasedQuantity, missingQuantity, deliveryState}`. Tri : `jita`, `other` (par nom de système puis identifiant), `unknown` ; dans un groupe par `typeId`. `skip_null_values` à false.
8. **Front** : carte `ProjectPurchasesToDeliver.vue` (store `purchasesToDeliver.ts`) sous la carte Courrier de `GroupIndustryDetail.vue`, groupée par origine (Jita, autre système nommé, origine inconnue), style HUD des cartes voisines, noms de type tronqués avec infobulle. Pastille de livraison par ligne ; pour les lignes `jita`, l'état du courrier (`transportState` de la ligne du même `bomLineId` dans le store du courrier) s'affiche à côté de la pastille de livraison, absent si le courrier n'a pas cette ligne. Stock `unknown` ou `none` : bandeau pointillé violet qui le dit (clés distinctes), les lignes affichent « — » à la place de la quantité manquante. Carte masquée tant qu'aucune ligne et aucun achat ; message « tout est livré » quand les achats existent mais sont tous livrés (distinction : `rows` vide ne dit pas s'il y avait des achats, donc la ressource porte aussi `purchaseCount`, nombre de couples avant masquage). Rafraîchissement : mêmes événements Mercure que le courrier.
9. **i18n** FR + EN sous `groupIndustry.purchasesToDeliver.*`.

## Exemples chiffrés (Tritanium 34, Nocxium 38 ; Jita 30000142, C-J6MT hub)

| Achats de la ligne | Stock container | Lignes renvoyées |
|---|---|---|
| Tritanium 1000 en C-J6MT | connu, 0 | C-J6MT, 1000 achetés, 1000 manquants, `missing` |
| Tritanium 1000 en C-J6MT | connu, 400 | C-J6MT, 600 manquants, `partial` |
| Tritanium 1000 en C-J6MT | connu, 1000 ou plus | aucune (`delivered`) |
| Tritanium 1000 en C-J6MT | `unknown` | C-J6MT, `missingQuantity` null, `unknown` |
| Tritanium 1000 en C-J6MT | `none` (pas de nom de container) | C-J6MT, null, `unknown`, `stockState` = `none` |
| Tritanium 600 Jita + 400 C-J6MT | connu, 700 | Jita livré (masqué) ; C-J6MT 300 manquants, `partial` |
| Tritanium 600 Jita + 400 manual | connu, 500 | Jita 100 manquants `partial` ; inconnue 400 manquants `missing` |
| Nocxium 50, source `manual` | connu, 0 | origine `unknown`, `solarSystemId` null, 50 manquants |
| Deux lignes de BOM, même type | connu | stock lu par ligne (`containerQuantityOf` de chaque ligne) |

## Décisions

- D1 placement : carte séparée sous le courrier, endpoint propre (recommandation du brief, évite les conflits avec #1084).
- D2 groupes par origine : `jita`, `other` (un sous-groupe par système), `unknown`.
- D3 livrées masquées, compteur `purchaseCount` pour distinguer « tout livré » de « aucun achat ».
- D4 attribution du stock : Jita d'abord (le courrier est le flux fiable), inconnue en dernier (jamais déclarée livrée par défaut). Limite connue : le stock ne dit pas d'où vient chaque unité.

## Hors périmètre

Quantité livrée à la main (`fulfilledQuantity`) non lue : seul le container fait foi (issue). Pas de bouton d'action, pas de suppression des blocs « autres origines » du panneau Courrier (décision de #1084).
