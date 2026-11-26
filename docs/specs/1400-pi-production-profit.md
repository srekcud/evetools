# #1400 : bénéfice de production par jour dans Revenus

Circuit M, mode nuit. Après #1396 (prix payé) et #1399 (taxes POCO). Décisions de Sylvain (commentaire du 2026-11-26) : une entrée extraite par le joueur est valorisée au **prix d'achat du hub**, avec ce libellé dans l'onglet Revenus ; le transport hub vers maison est ignoré, aucun réglage.

## Sources (lues, pas recalculées)

- Production par type et par jour : `PlanetaryProductionCalculator::calculateProduction()` (extracteurs et usines, au rythme réel des usines). Pour chaque type : `dailyQuantity`, `dailyConsumedInternally`, `dailySellableQuantity`, `inputs[].dailyConsumed`. Le chiffre d'affaires théorique au sell Jita (`unitPrice`, `dailyIskValue`) reste affiché par ligne.
- Prix payé : `PlanetaryPaidPriceCalculator` (#1396).
- Hub : `User::getEffectiveMarketStructureId()`, sinon la structure par défaut (`app.default_market_structure_id`), comme `UserSettings`. Prix du hub : `StructureMarketService::getLowestSellPrices` (sorties) et `getHighestBuyPrices` (entrées extraites).
- Taxes POCO : `CachedWalletJournalEntryRepository::planetaryTaxesByColonyAndDay` (#1399), sur les 30 derniers jours.

## Règles

1. Une ligne par produit P1 à P4 produit. Les types extraits (P0) n'ont pas de ligne.
2. **Quantité vendue** `sold = dailySellableQuantity` (production moins ce que la chaîne du joueur consomme). Une ligne à `sold = 0` est une **étape interne** : pas de marge propre (`internal = true`, `profitPerDay = null`), sa marge est comptée sur le produit qui la consomme.
3. **Ventes au hub** : `salesIsk = sold × prix de vente le plus bas du hub`. `null` si `sold > 0` et le prix du hub est inconnu.
4. **Coût unitaire d'un type produit** `u(t) = Σ entrées i de t : (dailyConsumed_i / dailyQuantity_t) × c(i)`.
5. **Coût d'une entrée consommée** `c(i)` : la consommation totale de `i` est répartie entre la part **propre** `min(sortie propre de i, consommation de i)` et la part **achetée** `max(0, consommation − sortie propre)`. Part propre d'un type P0 (extrait) : prix d'achat du hub (le plus haut ordre d'achat). Part propre d'un type P1 à P3 : `u(i)` (récursif, la marge d'une étape interne n'existe pas). Part achetée : prix payé (#1396). `c(i) = (part propre × coût propre + part achetée × prix payé) / consommation`.
6. **Inconnu, jamais zéro** : si une part non nulle a un prix inconnu (extrait sans ordre d'achat au hub, acheté sans achat wallet sur 90 jours), `c(i)` est `null`, donc `u(t)` aussi, donc la ligne passe en inconnu (`inputsIsk = null`, `profitPerDay = null`). Une part à 0 ne demande pas de prix.
7. **Entrées de la ligne** : `inputsIsk = sold × u(t)`. **Bénéfice** : `profitPerDay = salesIsk − inputsIsk`, `marginPercent = profitPerDay / salesIsk × 100` (null si `salesIsk = 0` ou inconnu).
8. `valuesExtractedAtHubBuy = true` quand une entrée extraite par le joueur entre (même indirectement) dans `u(t)` : le front affiche « extrait : valorisé au prix d'achat du hub ».
9. **Taxes POCO** : les taxes (import + export) des 30 derniers jours divisées par 30, tous personnages du joueur. Elles ne se rattachent pas à un produit (le journal les donne par planète, pas par objet) : une ligne de total, pas une colonne. L'API l'expose en `taxes: {days, perDay}` (une propriété nulle de premier niveau est omise par API Platform). Aucune ligne de taxe lue : `perDay = null` (inconnu, pas 0) et le net ne les déduit pas.
10. **Totaux** : `salesIsk`, `inputsIsk`, `profitPerDay` = somme des lignes à bénéfice connu (donc ventes − entrées = bénéfice), `unknownLineCount` = lignes de produits vendus au bénéfice inconnu (les étapes internes ne comptent pas), `netProfitPerDay = profitPerDay − taxesPerDay` quand les taxes sont connues, sinon `profitPerDay`.
11. `unsyncedWallets` : repris de #1396. `hub` : `{structureId, structureName, hasData}` (`hasData` faux = marché du hub jamais synchronisé, toutes les ventes inconnues).
12. Endpoint `GET /api/planetary/production-profit` (ROLE_USER). Lecture seule, aucune donnée d'un autre utilisateur.
13. Front : une grille « Bénéfice de production par jour » dans l'onglet Revenus (sous les grilles existantes) : produit, sorti par jour, ventes au hub, entrées au prix payé, bénéfice par jour, marge ; étape interne : « étape interne, marge comptée sur le produit final » ; ligne inconnue : « inconnu » (hachuré), jamais 0 ; pied : total connu, taxes POCO par jour, bénéfice net, libellé des entrées extraites, chiffre d'affaires théorique Jita par ligne. i18n fr + en.

## Exemple chiffré

Usine P2 : sortie 10 000 u/jour, tout vendu, entrées 20 000 u/jour d'un P1 non produit par le joueur (prix payé 150) et 2 000 u/jour d'un P0 extrait en quantité suffisante (achat hub 40). Hub : vente 700 le P2. `u = (20 000 × 150 + 2 000 × 40) / 10 000 = 308`. Ventes 7 000 000, entrées 3 080 000, bénéfice 3 920 000, marge 56 %. Prix payé du P1 inconnu : la ligne est inconnue, ni 0 ni 3 920 000.

## Cas limites

- Aucune colonie ou aucune usine : aucune ligne, totaux à 0, `unknownLineCount = 0`.
- Production sans prix de vente au hub : `salesIsk = null`, ligne inconnue.
- Étape interne dont une entrée est inconnue : la ligne interne n'est pas comptée en inconnu, mais le produit qui la consomme l'est (le coût remonte).

## Hors périmètre

Rattachement des taxes à un produit, coût de transport hub vers maison (ignoré par décision), réglage du hub (#1394), tendance et signaux (#1397).

## Actions prod

Aucune (pas de migration, pas de commande). Les taxes POCO apparaissent après la prochaine sync du journal wallet (#1399).
