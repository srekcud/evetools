# #334 Ventes par contrat : historique des contrats publics et estimation des ventes

Décision de Sylvain (2026-11-07) : la région vient de la structure de marché préférée de chaque utilisateur (la capitale) ; les contrats publics sont relevés pour chaque région distincte en usage, en plus de The Forge. Historique 90 jours, enchères ignorées, utilisé pour les types absents ou rares du marché.

## Périmètre de ce lot
Historique, déduction des ventes, indicateurs 30 jours, source « Contrats » du scanner (Quoi produire). Hors lot, à dire sur l'issue : vaisseaux équipés regroupés par coque, ressource API d'affichage des indicateurs par type, délai médian affiché (calculé, pas encore exposé), enchères.

## Règles
1. Régions relevées : The Forge, plus la région de chaque `User::preferredMarketStructureId` distinct (structure → système solaire → région, via `CachedStructure` et `MapSolarSystem`). Structure à région inconnue : ignorée et citée dans le résumé de sync, jamais une région par défaut. Régions dédoublonnées.
2. `SyncPublicContractsHandler` relève chaque région (même pagination, même filtre `item_exchange` non expiré, même lecture d'objets avec cache). L'index de prix Redis reste alimenté par The Forge seule, comportement inchangé.
3. Historique : un `PublicContractListing` par contrat d'échange d'objets de prix > 0 dont les objets sont lus : `contractId` (unique), `regionId`, `issuerId`, `price`, objets inclus (`typeId`, `quantity`, agrégés par type, triés), `dateExpired`, `firstSeenAt`, `lastSeenAt`, `missedPolls`, `disappearedAt`. `typeId` et `quantity` totale renseignés seulement pour un contrat mono-type, sinon `typeId` nul.
4. Relevé d'une région réussi : un contrat suivi présent est « revu » (`lastSeenAt` = maintenant, `missedPolls` 0, `disappearedAt` nul) ; un contrat suivi absent compte une absence (`missedPolls` +1, `disappearedAt` fixé à la première absence). Un contrat absent seulement une fois n'est pas conclu. Un contrat dont les objets n'ont pas pu être lus mais présent dans la liste n'est ni créé ni compté absent. Un relevé de région en échec ne compte aucune absence.
5. Disparition confirmée = `missedPolls` ≥ 2 (2 relevés consécutifs sans le contrat).
6. Issue d'une disparition confirmée :
   - `disappearedAt` ≥ `dateExpired` : expiré, jamais une vente ;
   - sinon, un autre contrat du même `issuerId` et de même objets (même signature) vu pour la première fois à `lastSeenAt` du contrat ou après, et au plus 24 h après `disappearedAt` : republié (changement de prix), pas une vente ;
   - sinon : vente probable (suppression sans republication, indiscernable d'une vente).
7. Indicateurs par type sur 30 jours (fenêtre sur `disappearedAt`, contrats mono-type seulement) : `estimatedSales` (contrats vendus probablement), `soldQuantity` (unités), `sellThroughRate` = ventes / (ventes + expirés) sur les lots clos de la fenêtre, republiés exclus, `null` si aucun lot clos ; `medianSoldUnitPrice` (médiane de prix / quantité), `medianDaysToSell` (médiane de `disappearedAt` − `firstSeenAt`, en jours) ; `null` si aucune vente.
8. Indice de confiance : `low` si `estimatedSales` < 5 ou si l'historique de la région (plus ancien `firstSeenAt`) a moins de 7 jours, sinon `medium` ; jamais `high`, car une suppression sans republication reste indiscernable d'une vente.
9. Rétention : à la fin de chaque sync, les contrats de `lastSeenAt` plus vieux que 90 jours sont supprimés.
10. Scanner, lieu de vente `sellVenue=contracts` (option « Public Contracts » déjà présente côté front, qui retombait sur les prix Jita) : région = région de `structureId` (défaut : structure préférée de l'utilisateur, comme `structure`) ; prix de vente = `medianSoldUnitPrice` du produit ; aucune vente sur 30 jours, type absent de l'historique ou région inconnue : prix de vente inconnu (`missing_sale_price`), jamais 0. Aucun frais de courtage ni de taxe (contrat), aucun coût d'export (vente à la capitale, comme `structure`). `dailyVolume` = `soldQuantity` / 30 (inconnu si le type est absent de l'historique), donc `iskPerDay` est plafonné par le rythme de vente ; le filtre de volume minimal s'applique à ce `dailyVolume`. Forme du résultat du scanner inchangée.
11. Front : sur le lieu `contracts`, sélecteur de structure affiché, volume journalier remplacé par « N / mois » (`round(dailyVolume × 30)`, inconnu reste inconnu) et avis sous le tableau : les ventes sont déduites des contrats disparus (limite de confiance). L'indice de confiance par type n'est pas encore exposé (pas d'endpoint dans ce lot).
12. Un type n'a d'indicateurs que s'il a au moins un lot clos (vendu ou expiré) dans la fenêtre. Un contrat sans `issuer_id` dans la réponse ESI n'est pas historisé (listé, jamais créé).

## Exemples chiffrés (règle 6 et 7)
| Cas | Résultat |
|---|---|
| vu 10:00 à 10:30, absent 11:00 et 11:30, expire J+7 | vente probable, délai = 11:00 − 10:00 |
| absent à 11:00 seulement, revu à 11:30 | listé, `missedPolls` 0 |
| absent à 11:00 et 11:30, expire à 10:45 | expiré |
| absent 11:00 et 11:30, même émetteur et mêmes objets créés à 10:30 (prix plus bas) | republié |
| même cas, republication à 11:00 + 25 h | vente probable |
| deux lots identiques du même émetteur, l'un disparaît, l'autre était déjà là avant | vente probable |
| 3 ventes à 100, 200, 300 ISK/u (quantités 1, 2, 1), 1 expiré, 1 republié | ventes 3, unités 4, taux 3/4, prix médian 200 |

## Hors lot
Enchères (`/contracts/public/bids/`, ignorées par décision de Sylvain), contrats multi-types autres que les coques équipées.

## Lot 2 (reliquat) : coques équipées et indicateurs affichés
Recommandations de l'issue appliquées : « regrouper par coque, déduire l'équipement au prix du marché pour isoler le prix de la coque » ; « indicateurs par type : ventes estimées, taux d'écoulement, prix médian, délai médian » avec l'indice de confiance affiché comme limite.

13. Coque équipée : un contrat multi-types dont les objets contiennent exactement un type de la catégorie SDE Vaisseau (6), en quantité 1, est attribué à ce type. Valeur de l'équipement = somme (quantité × prix de vente Jita le plus bas, `JitaMarketService::getPrices`) de tous les autres objets. Prix de la coque = prix du contrat − valeur de l'équipement.
14. Écarts : un type d'équipement sans prix Jita, un prix de coque ≤ 0, zéro ou plusieurs vaisseaux, ou une quantité de vaisseau ≠ 1 laissent le contrat hors des indicateurs par type (ni vendu ni expiré compté), jamais un prix de coque inventé. Les prix Jita sont ceux du jour de l'analyse, pas du jour de la vente (limite assumée, pas de colonne ajoutée, aucune migration).
15. Les contrats attribués à une coque passent par les règles 5 à 8 comme un mono-type : même issue (vendu, expiré, republié), quantité 1, prix unitaire = prix de la coque, confiance identique. La règle de republication reste sur la signature complète des objets.
16. Seuls les contrats multi-types à disparition confirmée dans la fenêtre de 30 jours sont évalués (pas de prix chargé pour les autres).
17. Affichage : chaque ligne du scanner porte `contractSales` (`estimatedSales`, `sellThroughRate`, `medianDaysToSell`, `confidence` `low|medium`) quand le lieu de vente est `contracts` et que le type a des indicateurs, sinon `null` ; hors lieu `contracts`, toujours `null`. Pas de nouvel endpoint : le scanner est le seul consommateur. La forme des autres champs est inchangée.
18. Front, lieu `contracts` : sous le « N / mois » de la ligne, une ligne de détail « écoulement X % · délai médian Y j · confiance faible|moyenne » (taux ou délai inconnu : « inconnu », jamais 0). L'avis sous le tableau reste.

## Exemples chiffrés (règles 13 et 14)
| Cas | Résultat |
|---|---|
| contrat 1 coque + 2 modules (qté 1 chacun à 100 et 200 ISK Jita), prix 1 000 | coque à 700, vendu probable |
| même contrat, un module sans prix Jita | hors indicateurs |
| coque + modules, prix 250 (équipement 300) | hors indicateurs |
| 2 coques + 1 module | hors indicateurs |
| coque équipée expirée sans vente | compte comme lot clos expiré du type de la coque |
