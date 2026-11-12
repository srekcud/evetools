# #742 + #743 : production PI nette et usines approvisionnées

Mode nuit : spec validée en autonomie. Service : `PlanetaryProductionCalculator::calculateProduction()`. Consommateurs : `PlanetaryProductionProvider` (tableau par tier), `PlanetaryStatsProvider` (KPI via `calculateTotalDailyIsk()`).

## Contexte vérifié (origin/main e216ec22)

- `totalDailyIsk` additionne `dailyQty × prix` de chaque type produit, intermédiaires consommés compris (#742).
- Une usine compte `quantité de sortie × cycles/jour` sans regarder ses entrées (#743).
- `PlanetaryRoute` (source, destination, type, quantité) est stockée, mais les fixtures et la plupart des colonies n'ont pas de routes exploitables pour tracer un flux pin à pin : le calcul reste au niveau de la colonie.
- `PlanetaryPin::getContents()` : `list<{type_id, amount}>|null`. La synchro ne stocke pas un contenu vide (`PlanetarySyncService`), donc `null` = pin vide, pas donnée manquante.

## Règles #742 (production nette)

1. Par type, sur toutes les colonies du joueur : `consommé` = somme des entrées réellement consommées par les usines, `vendable = max(0, produit − consommé)`, `consommé en interne = min(produit, consommé)`.
2. `dailyIskValue` d'un produit = `vendable × prix Jita` ; celui d'un tier en est la somme ; `totalDailyIsk` = somme des tiers ; `totalMonthlyIsk = totalDailyIsk × 30`.
3. Chaque produit garde `dailyQuantity` (volume brut) et gagne `dailyConsumedInternally` et `dailySellableQuantity`. Les entrées de la chaîne (`inputs`) gardent leur forme.
4. Un type consommé mais jamais produit n'apparaît pas (inchangé). Un prix Jita absent garde son comportement actuel (0 ISK, `unitPrice` null) : hors périmètre.

| Cas | Brut | Consommé | Vendable | ISK/jour |
|---|---|---|---|---|
| P2 entièrement consommé par un P3 | 960 | 960 | 0 | 0 |
| P1 en surplus (produit 3840, consommé 1920) | 3840 | 1920 | 1920 | 1920 × prix |
| P0 extrait 583 200, consommé 576 000 | 583 200 | 576 000 | 7 200 | 7 200 × prix |
| Aucune usine en aval | 960 | 0 | 960 | 960 × prix |

Chaîne de référence des tests (`suppliedChainColony`) : le total passe de 15 446 450,71 à la somme des surplus seuls.

## Règles #743 (usine approvisionnée, niveau colonie)

5. Pour chaque colonie, le débit d'une entrée vaut : extracteurs actifs de la colonie (expiration future ou absente, comme aujourd'hui) + sorties effectives des usines de la colonie.
6. Une entrée est couverte à 100 % si la colonie en détient un stock (somme des `amount` des contenus de tous ses pins, usines comprises, pour ce `type_id`). Sans stock, sa couverture vaut `min(1, débit / demande)`, la demande étant la somme des besoins de toutes les usines de la colonie pour ce type.
7. Le taux de marche d'une usine est `min` de la couverture de ses entrées (0 si une entrée n'a ni débit ni stock). Sortie effective et consommation effective = valeur à plein régime × taux de marche. Le calcul est itéré (au plus 6 passes) car le débit d'une usine dépend de ses entrées.
8. Une usine à l'arrêt (taux 0) reste listée avec `dailyQuantity` 0, ISK 0 et `inputs[].dailyConsumed` 0 : la chaîne arrêtée est visible, pas masquée.
9. `inputs[].dailyProduced` et `delta` utilisent les sorties et consommations effectives.
10. Une usine dont le schéma, le cycle ou la sortie est inconnu reste exclue (inchangé, #222).

| Colonie | Résultat |
|---|---|
| Une usine P2, aucun extracteur, aucun stock | sortie 0, consommation 0 |
| Même usine, extracteur expiré depuis 2 h | sortie 0 |
| Même usine, 1 unité du P1 requis en stock | sortie à plein régime |
| Extracteur couvrant 50 % de la demande, pas de stock | sortie à 50 % |
| Usine P3 dont le P2 amont est à l'arrêt | sortie 0 |

## Cas limites

- Plusieurs usines du même schéma dans une colonie se partagent le débit (couverture commune).
- Stock de produit fini d'une usine : compté comme stock de ce type pour les usines consommatrices (approximation colonie, voir Décisions).
- Débit d'entrée venu d'une autre colonie (route douanière) : seulement via le stock présent.

## Tests existants contredits

- `PlanetaryProductionCalculatorCharacterizationTest::testSuppliedChainTotalCurrentlyAddsTheValueOfEveryTier…PiAudit1` : total et `dailyIskValue` passent au net (#742).
- `…testFactoryWhoseExtractorExpiredCurrentlyProducesAtFullRatePiAudit2` et `…testAdvancedCommodityInputsKeepTheirOwnSchematicQuantityCurrentlyWithoutSupplyPiAudit2` : usine sans entrée = arrêtée (#743).
- Le helper `item()` gagne les deux champs de #742.

## Hors périmètre

- Réglage « vendu ou consommé » par produit (décision ouverte, recommandation : calcul net automatique d'abord).
- Tracé des routes pin à pin, stock fini qui s'épuise dans le temps (#744 pour les colonies sans extracteur).
- Calcul client de production par colonie (`getColonyProduction`), prix Jita absents.

## Glossaire

Propositions ajoutées : **Production nette PI** (`vendable`), **Consommé en interne**, **Usine à l'arrêt**.
