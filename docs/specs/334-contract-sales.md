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
Vaisseaux équipés par coque, endpoint d'affichage, enchères (`/contracts/public/bids/`), contrats multi-types dans les indicateurs par type.
