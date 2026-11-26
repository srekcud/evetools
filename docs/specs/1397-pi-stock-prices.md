# Spec #1397 : onglet Stock, grille de prix, signaux et bloc « À traiter »

Circuit M, mode nuit : validée en autonomie, décisions en bas. Livre l'issue entière. S'appuie sur #1395 (grille de couverture, `GET /planetary/stock`), #1396 (prix payé, `PlanetaryPaidPriceCalculator`), #1393 (ordres d'achat stockés) et #1394 (réglages : hub). Maquette locale non versionnée : onglet Stock, grille `priceGrid` et bloc `todo` (fonction `renderStock`).

## Contexte

- Types : ceux des lignes de la grille de couverture (entrées consommées par les usines, `PlanetaryInputNeedsCalculator::calculate()['weekly']`), pas les 100 entrées de schémas.
- Prix payé : `PlanetaryPaidPriceCalculator::calculate()['prices']` (`paidPrice` null = jamais acheté sur 90 jours, inconnu, jamais 0).
- Sell et meilleur ordre d'achat du hub : caches du marché de la structure (`StructureMarketService::getLowestSellPrices`, `getHighestBuyPrices`, hub = `hubStructureId` des réglages). Sell Jita : `JitaMarketService::getPrices`. Une valeur absente du cache reste null.
- Tendance : snapshots quotidiens de la structure (`StructureMarketSnapshotRepository::findHistory`, `sellMin`).
- Mon ordre d'achat : ordres d'achat stockés des personnages de l'utilisateur (`CachedCharacterBuyOrderRepository::findByCharactersAndTypes`, `getPrice()`).
- `RevenueTodoPanel` (onglet Revenus) affiche déjà un bloc numéroté « À traiter » ; il est réutilisé.

## Backend

1. Ressource `PlanetaryStockPriceResource` (`GET /planetary/stock-prices`, id fixe `stock-prices`, `ROLE_USER`, provider dédié, API Platform, `skip_null_values` faux). Champs : `hubStructureId`, `hubStructureName`, `paidPriceDays` (= `PAID_PRICE_DAYS`), `complete` (bool), `unsyncedWallets[]` (même forme que #1396), `rows[]`.
2. Lignes : une par type de la grille de couverture, même tri (`typeName` puis `typeId`). Champs : `typeId`, `typeName`, `paidPrice`, `hubSell`, `jitaSell`, `paidVsHubPercent`, `myBuyOrder`, `buyOrderVsHubPercent`, `trend7dPercent`, `hubBestBuy`, `signal`. Tout est `?float` ou `?string`, null = inconnu.
3. `hubSell`, `jitaSell`, `hubBestBuy` : lus tels quels dans les caches (null si absent ou si le cache n'existe pas). `paidPrice` : lu dans le calcul #1396 (null si jamais acheté).
4. `myBuyOrder` : prix le plus haut parmi les ordres d'achat stockés de l'utilisateur pour ce type dont le `locationId` est le hub ; null sans ordre au hub (les ordres d'un autre lieu, ou d'un autre utilisateur, ne comptent pas).
5. `paidVsHubPercent` = `(paidPrice / hubSell − 1) × 100`, arrondi à 1 décimale ; null si `paidPrice` ou `hubSell` est null ou si `hubSell` vaut 0. `buyOrderVsHubPercent` = `(myBuyOrder / hubSell − 1) × 100`, mêmes règles. Négatif = en dessous du sell du hub (bonne marge).
6. `trend7dPercent` : snapshots des 7 derniers jours (`findHistory(hub, type, 7)`), `(dernier sellMin − premier sellMin) / premier sellMin × 100`, arrondi à 1 décimale. Null s'il y a moins de 2 snapshots avec `sellMin` non null ou si le premier vaut 0.
7. `signal` (`buy_jita`, `buy_hub`, `outbid`, `place_order` ou null), première règle qui s'applique, dans cet ordre :
   1. `buy_jita` : `jitaSell` et `paidPrice` connus et `jitaSell < paidPrice` ;
   2. `buy_hub` : `hubSell` et `paidPrice` connus et `hubSell < paidPrice` ;
   3. `outbid` : `myBuyOrder` et `hubBestBuy` connus et `hubBestBuy > myBuyOrder` ;
   4. `place_order` : `myBuyOrder` null et `trend7dPercent` connue et `< 0`.
   Une donnée inconnue ne déclenche jamais un signal : aucune comparaison contre 0.
8. `complete` est faux si le cache du marché du hub n'existe pas ou si le cache Jita n'existe pas (`hasCachedData`) ; `unsyncedWallets` dit que le prix payé peut être incomplet.
9. Accès : personnages, ordres et wallets de l'utilisateur connecté seulement (aucun identifiant reçu). Aucun appel ESI, pas de Mercure (lecture seule, données déjà synchronisées), pas de migration.
10. Calcul des écarts et du signal dans une classe pure sans dépendance (`PlanetaryStockPriceCalculator`, `src/Service/Planetary/Stock/`) qui reçoit les lectures ; la lecture des caches et des dépôts est dans `PlanetaryStockPriceReader`.

## Frontend

11. Store `planetaryStockPrices` (`load()`, `prices`, `error`), chargé avec celui de `planetaryStock` par `PiStockTab.vue` et rechargé quand un réglage est enregistré (`saveRevision`). Une erreur de ce chargement n'efface pas la grille de couverture : message d'erreur propre à la grille de prix.
12. `PiStockPriceGrid.vue`, sous la grille de couverture et avant la liste d'achat : colonnes Type, Prix payé, Sell hub, Sell Jita, Payé vs hub, Mon BO, BO vs hub, Tendance 7 j, Signal. Une valeur null s'affiche par `UnknownValue`, jamais 0 ; « Mon BO » null s'affiche « aucun » (information connue : pas d'ordre). Écart : vert sous −10 %, ambre sous −3 %, rouge sinon. Tendance : ▲ rouge si > 0, ▼ vert si < 0, « = 0 % » à 0. Signal : pastille « Acheter à Jita », « Acheter au hub », « Surenchéri », « Poser un BO », ou « — ». Une note sous la grille dit que le prix payé peut être incomplet (`unsyncedWallets` non vide) ou que le marché n'est pas synchronisé (`complete` faux).
13. Bloc « À traiter » (`RevenueTodoPanel`, `actionLabel` devient optionnel : sans libellé, pas de bouton), sous la liste d'achat. Les points sont numérotés dans cet ordre : pour chaque ligne de la grille de couverture, « Rapatrier `hub` du hub vers la maison » si `hub` > 0 puis « Acheter `toBuy` » si `toBuy` > 0 (non null) ; puis, pour chaque ligne de prix, un point si le signal est `buy_jita` (sell Jita et prix payé, écart par unité) ou `outbid` (mon BO et meilleur BO du hub). `buy_hub` et `place_order` n'ajoutent pas de point.
14. Pastilles : le numéro du point s'affiche à côté de la valeur de la cellule Hub (rapatrier), de la cellule À acheter (acheter) et du signal (`buy_jita`, `outbid`), avec un `data-testid` stable (`stock-pin-<genre>-<typeId>`). La numérotation vient d'une fonction pure de `frontend/src/utils/` (items + pastilles par type), pas des composants.
15. Aucun libellé, colonne ou onglet existant n'est retiré. FR et EN, clés sous `pi.stock.prices.*` et `pi.stock.todo.*`.

## Exemples chiffrés (tests rouges)

| Cas | Attendu |
|---|---|
| payé 100, hub 125 | `paidVsHubPercent` −20,0 |
| payé 100, hub 0 ou null | écart null |
| BO 90, hub 100 | `buyOrderVsHubPercent` −10,0 |
| payé 100, Jita 95, hub 98 | `buy_jita` (Jita gagne sur hub) |
| payé 100, Jita 105, hub 98 | `buy_hub` |
| payé null, Jita 5, hub 5 | signal null (pas de prix payé) |
| payé 100, hub 120, BO 100, meilleur BO du hub 105 | `outbid` |
| payé 100, hub 120, BO 100, meilleur BO du hub 100 | null (c'est le mien) |
| payé 100, hub 120, sans BO, tendance −3,2 | `place_order` ; tendance +1 ou null : null |
| snapshots sell 100 puis 110 | tendance +10,0 ; un seul snapshot : null |
| Ordres d'achat à 80 et 85 au hub, 95 à Jita | `myBuyOrder` 85 (le plus haut au hub) |
| Autre utilisateur | ses ordres et ses achats n'apparaissent pas |
| Cache du hub absent | `hubSell` et `hubBestBuy` null, `complete` faux |
| Points « À traiter » : hub 500 + toBuy 600 sur A, `buy_jita` sur B, `outbid` sur A | 1 rapatrier A, 2 acheter A, 3 `buy_jita` B, 4 `outbid` A |

## Cas limites

- Aucune colonie ou aucune entrée : `rows` vide, grille vide, bloc « À traiter » absent (le panneau se masque sans point).
- Type absent du paid-price (type hors schéma) : `paidPrice` null.
- Hub identique au lieu maison : `hub` vaut 0 côté couverture, aucun point « Rapatrier ».

## Hors périmètre

Bouton d'action sur un point, copie d'une ligne seule, achat ou pose d'ordre depuis l'app (lecture seule), alerte ou notification de prix (#1391 pour les colonies), décision acheter ou produire (Planificateur), historique des prix payés par lieu, graphique de tendance.

## Décisions prises en autonomie

- Endpoint séparé de `GET /planetary/stock` : la couverture reste rapide, la grille de prix charge le wallet et les caches de marché.
- Le sell Jita est comparé au prix payé tel quel (pas de coût de transport), comme la maquette ; le point « À traiter » le dit (« à comparer au coût du transport »).
- Tendance sur les snapshots déjà enregistrés (aucun nouveau relevé).
- `buy_hub` et `place_order` restent des signaux de ligne sans point « À traiter », comme la maquette (seuls Jita et surenchéri y entrent).
