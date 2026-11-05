# Spec #348 — Où vendre : prix, volume, frais et jours par marché, vente immédiate

Mode nuit : spec validée en autonomie, sur les décisions de Sylvain du 2026-11-05 (commentaires de #348, issue #385) et la maquette `industry-project-v3.html#p=25` (`renderMarketDepth`, notes 17, 21, 13).

## Contexte

Le projet ne compare pas les marchés de vente. Les briques existent : frais du vendeur (#347, `SellerSalesFeesResolver`), prix pondéré du carnet Jita (`JitaMarketService`), volume de région (historique ESI), volume de structure estimé (#385, `StructureTradedVolumeService`). Cette issue les assemble dans une ressource API et une carte front.

## Règles métier

- **R1 — Ressource.** `GET /api/industry/sell-markets/{typeId}?quantity=N` (API Platform, `ROLE_USER`, Provider). `quantity` obligatoire, entier de 1 à `MAXIMUM_QUANTITY` (100 000 000) : absent ou hors bornes → 400. `typeId` inconnu du SDE : pas d'erreur, tout est inconnu.
- **R2 — Deux marchés.** Ligne `jita` (The Forge, Jita IV - Moon 4) toujours. Ligne `capital` (structure de marché préférée, `User::getPreferredMarketStructureId()`, nom `getPreferredMarketStructureName()`) seulement si l'utilisateur en a une ; sinon la ligne est absente (pas « inconnue »).
- **R3 — Prix.** Jita : prix pondéré de la quantité sur le carnet de vente (`getWeightedSellPricesWithFallback`), avec sa couverture ; couverture < 1 = prix partiel (garde le montant, `coveragePercent` via `PartialPricePresenter::coveragePercentOfCoverage`, null si carnet complet). Capitale : plus bas prix de vente de la structure (`StructureMarketService::getLowestSellPrice`), même règle que Profit Margins, pas de couverture. Sans ordre : prix `null`.
- **R4 — Volume journalier.** Jita : `JitaMarketService::getAverageDailyVolumes` ; clé absente (ESI injoignable) ou `0.0` (historique vide, indistinguable d'un marché inactif, voir glossaire) → `null`, jamais 0. Capitale : `StructureTradedVolumeService::getAverageDailyVolumes($structureId, [$typeId], today)` (déjà `null` sous 7 jours couverts ou sans baisse observée). `volumeKind` : `region_history` (Jita) ou `structure_estimate` (capitale, borne basse).
- **R5 — Frais.** `SellerSalesFeesResolver::forUser($user)` : courtage et taxe de vente du personnage principal, appliqués aux deux marchés comme dans Profit Margins (taux de courtage propre à une structure : non synchronisé, hors périmètre). Résolveur à `null` (standings ou compétences absents) : frais, net et vente nette inconnus (`null`), prix et jours restent.
- **R6 — Net par unité.** `netUnitPrice = unitPrice × (1 − brokerFeeRate − salesTaxRate)`, `null` si prix ou frais inconnus. `feeRate = brokerFeeRate + salesTaxRate`.
- **R7 — Jours pour tout vendre.** `daysToSell = quantity / (0,10 × dailyVolume)` (constante `SELL_SHARE_OF_DAILY_VOLUME` = 0,10 : on reste à 10 % du volume quotidien), `null` si volume inconnu. Sur la capitale c'est un maximum (volume borne basse), à dire dans l'interface.
- **R8 — Vente immédiate.** Prix moyen d'une vente de `quantity` unités aux ordres d'achat Jita (`getWeightedBuyPricesWithFallback`) : `averageUnitPrice`, `coveragePercent` (même présentation que R3), `netUnitPrice = averageUnitPrice × (1 − salesTaxRate)` (taxe seule, pas de courtage sur un ordre d'achat existant). Carnet d'achat vide : `averageUnitPrice` et `netUnitPrice` `null`. Résolveur à `null` : `netUnitPrice` `null`.
- **R9 — Forme de la réponse.** `typeId`, `typeName`, `quantity`, `sellShareOfDailyVolume` (0,1), `markets` (liste de lignes `{key, name, unitPrice, coveragePercent, dailyVolume, volumeKind, feeRate, netUnitPrice, daysToSell}`), `immediateSale` `{averageUnitPrice, coveragePercent, netUnitPrice}`. Montants en `float`, jamais arrondis par l'API ; `coveragePercent` arrondi à 1 décimale par le présentateur existant.
- **R10 — Front.** Carte `SellMarketsCard.vue` (props `typeId`, `quantity`, saisie de la quantité modifiable) : tableau « Ordre de vente » (Prix, Volume, Frais, Net / u, Pour tout vendre) et encart « Vente immédiate · ordres d'achat Jita » (prix moyen, net taxe seule). Donnée inconnue : violet pointillé avec sa cause, jamais 0 ni tiret muet. Prix partiel : `PartialPriceMarker`. Jours > 14 : ambre. Capitale : volume et jours marqués « estimation, borne basse ». Direction HUD EVE.
- **R11 — Branchement.** La carte est montée dans l'onglet Profit Margins (`ProfitMarginTab.vue`, résultat chargé : `typeId` et `outputQuantity`). Le rattachement à la quantité restante à vendre d'un projet unifié suit le lot 2 de #362 (hors périmètre : rien ne dépend des étapes ni des routes `/group-industry`).
- **R12 — Pas de Mercure.** Lecture synchrone, aucune action asynchrone : aucun topic.

## Exemples chiffrés (quantité 50 sauf mention)

| # | Cas | Attendu |
|---|---|---|
| E1 | Jita 760 000, volume 20/j, frais 1,38 % + 3,375 % | net 760 000 × (1 − 0,04755) = 723 862 ; jours = 50 / (0,1 × 20) = 25 |
| E2 | Capitale 800 000, volume estimé 2/j | jours = 50 / 0,2 = 250 ; `volumeKind` `structure_estimate` |
| E3 | Capitale, volume `null` (moins de 7 jours) | `dailyVolume` et `daysToSell` `null`, prix et net présents |
| E4 | Jita, `getAverageDailyVolumes` renvoie 0.0 | `dailyVolume` `null`, `daysToSell` `null` |
| E5 | Jita, clé du type absente du volume | idem E4 |
| E6 | Résolveur de frais `null` | `feeRate`, `netUnitPrice` des deux lignes `null`, `immediateSale.netUnitPrice` `null`, prix et jours gardés |
| E7 | Carnet de vente Jita couvre 30 unités sur 50 | `unitPrice` gardé, `coveragePercent` 60,0 |
| E8 | Aucun ordre de vente Jita | `unitPrice` `null`, `netUnitPrice` `null`, `daysToSell` gardé |
| E9 | Pas de structure préférée | `markets` ne contient que `jita` |
| E10 | Ordres d'achat Jita : 20 à 700 000 et 30 à 690 000 | `averageUnitPrice` 694 000 ; net = 694 000 × (1 − 0,03375) = 670 577,5 |
| E11 | Carnet d'achat couvre 25 unités sur 50 | `averageUnitPrice` sur les 25, `coveragePercent` 50,0 |
| E12 | Carnet d'achat vide | `averageUnitPrice` et `netUnitPrice` `null`, `coveragePercent` `null` |
| E13 | `quantity` absent, 0, négatif, ou 100 000 001 | 400 |
| E14 | Quantité 1 | `daysToSell` = 1 / (0,1 × volume) (aucun arrondi à l'unité) |

## Cas limites

- Aucun défaut de 0 sur une donnée de marché : prix, volume, frais, jours, couverture sont `null` quand ils sont inconnus.
- Le prix capitale est le plus bas prix de vente (une unité), pas un prix pondéré : un volume demandé supérieur au carnet de la structure n'est pas signalé (comme Profit Margins).
- `daysToSell` n'est pas arrondi par l'API ; l'interface affiche une décimale.
- L'échec de lecture d'un marché (carnet ou ESI injoignable) donne `null` pour ce prix, sans exception ni 0.

## Accès et fraîcheur

- Aucun identifiant reçu d'un autre périmètre : `typeId` est un type SDE public, `quantity` un entier ; la structure est celle des réglages de l'utilisateur connecté, les frais ceux de son personnage principal (pas d'accès à un autre personnage, pas de corporation).
- Les prix et le carnet viennent des caches de marché partagés (Jita : sync horaire ou lecture ESI à la demande ; structure : sync horaire) ; le volume de région est en cache 24 h, le volume de structure est mis à jour par la synchro horaire (#385).
- Défaut connu repris tel quel : le vendeur est l'utilisateur connecté. Dans un projet à plusieurs, les frais affichés sont ceux de la personne qui regarde, pas du propriétaire ; ce choix change avec le rattachement au projet (lot 2 de #362).

## Tests existants appelés à changer

Aucun : service, résolveur et routes nouveaux. `ProfitMarginTab` reçoit la carte : ses tests front existants ne changent pas.

## Hors périmètre

- Rattachement à un projet (quantité restante à vendre, ventes déjà faites), seuil de rentabilité (« à éviter sauf urgence »), canal contrat.
- Taux de courtage propre à une structure, frais lus dans le journal wallet.
- Prix pondéré du carnet de la capitale (couverture), volume de région autre que The Forge.

## Glossaire

- **Jours pour tout vendre** (Days to sell) : `quantité / (10 % × volume journalier)` ; sur la capitale, un maximum (volume borne basse). Inconnu sans volume.
- **Vente immédiate** (Immediate sale) : vente de la quantité aux ordres d'achat existants du carnet Jita ; prix moyen pondéré, net de la taxe de vente seule.
- **Marché de vente** (Sell market) : Jita ou la structure de marché préférée ; `SellMarketComparison`.
