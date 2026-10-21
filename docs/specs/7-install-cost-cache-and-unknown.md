# Spec #7 (lots L4 + L1a) — Cache des indices et coût d'installation inconnu

## Contexte

Voir `.claude/tasks/7-design.md`. Deux défauts préparent le passage du coût d'installation à l'inconnu :

- Le TTL du cache des indices de coût (2 h) et des adjusted prices (24 h) est égal à l'intervalle du scheduler : si une sync échoue, le cache est vide jusqu'à la suivante et tous les coûts d'installation tombent à 0.
- `syncAdjustedPrices()` met en cache `0.0` pour une entrée ESI sans `adjusted_price`, et le calcul du coût d'installation renvoie 0 quand l'indice ou l'EIV manque. Aucun appelant ne peut distinguer « gratuit » de « inconnu ».

Ce lot ne change aucun montant affiché : il ajoute une méthode nullable que les lots suivants (L1b) brancheront. La formule reste celle d'aujourd'hui (L2 la corrigera).

## Règles métier

- **R1 — TTL.** Un indice de coût reste en cache 3 intervalles de sync (6 h) ; un adjusted price 3 intervalles (72 h). Les métadonnées de sync suivent le même TTL que leurs données.
- **R2 — Adjusted price absent.** Une entrée ESI sans `adjusted_price` n'est pas mise en cache ; `getAdjustedPrices()` ne la renvoie pas. Le compte renvoyé par la sync ne la compte pas.
- **R3 — Estimation nullable.** Nouvelle méthode `EsiCostIndexService::estimateJobInstallCost(list<array{materialTypeId:int, quantity:int}> $materials, int $runs, int $solarSystemId, string $activity, ?float $facilityTaxRate)` qui renvoie `array{cost: ?float, unknownReason: ?string, missingAdjustedPriceTypeIds: list<int>}` :
  - matériau sans adjusted price en cache : `cost` null, `unknownReason` `missing_adjusted_price`, typeId listé (sans doublon, trié) ;
  - indice absent pour le système et l'activité : `cost` null, `unknownReason` `missing_cost_index` (prioritaire sur l'adjusted price s'il manque aussi ; les typeId restent listés) ;
  - tout connu : `cost` = formule actuelle (`EIV × runs × index × (1 + taxe/100)`), `unknownReason` null, liste vide ;
  - aucune matière (EIV 0 légitime) : `cost` 0.0.
- **R4 — Inchangé.** `calculateEiv()`, `calculateEivFromPrices()` et `calculateJobInstallCost()` gardent leur comportement (utilisés par `ProductionCostService`, #121) jusqu'au lot L5.

## Exemples chiffrés

Les montants sont recalculés par le test-writer à partir des fixtures de `EsiCostIndexServiceTest` ; l'exemple Nitrogen Fuel Block vient de `.claude/tasks/7-design.md` et doit être vérifié.

| # | Cas | Attendu |
|---|---|---|
| E1 | Sync OK à T0, cache lu à T0 + 2 h 01 (sync suivante en échec) | indice de T0 renvoyé (aujourd'hui null) |
| E2 | Adjusted price lu à T0 + 24 h 01 | prix de T0 (aujourd'hui null) ; à T0 + 72 h 01 : absent |
| E3 | Entrée ESI `{type_id: 35, average_price: 5}` sans `adjusted_price` | non mise en cache, absente de `getAdjustedPrices([35])`, non comptée |
| E4 | NFB, tout connu, taxe 10 % | `cost` = 936,71512 (formule actuelle, inchangée) |
| E5 | NFB, Robotics (9848) sans adjusted price | `cost` null, `missing_adjusted_price`, [9848] |
| E6 | NFB, pas d'indice pour le système | `cost` null, `missing_cost_index` |
| E7 | Blueprint sans matériau | `cost` 0.0 |

## Hors périmètre

- Branchement dans les appelants (L1b), nouvelle formule SCC/taxe additive (L2), invention/copie/scanner (L3), `ProductionCostService` (L5).

## Décision prise en autonomie

TTL de 3 intervalles (valeur marquée « à confirmer » dans la note de design) : un indice de quelques heures reste plus juste qu'un 0, et l'inconnu visible arrive avec L1b.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante** (motifs `adjusted_price`, `cost_index`). Aucun ajout.
