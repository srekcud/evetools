# 1013 : lignes de job intermédiaires couvertes par le container

Suite de #1006 (prix de lancement, stock non réclamé), #1009 (valorisation) et #1050 (réclamer, attribuer, donner). Périmètre fixé par le dernier commentaire de l'issue : #1050 fusionnée, #1013 reprend.

## Règles

1. **Quantité du container d'une ligne de job** : `ProjectLineFulfillments::containerQuantityOf` renvoie la quantité du type dans le container pour une ligne de job du groupe `component` (stock lisible), comme pour un matériau : type absent = 0 connu, jamais 0 par défaut si le stock est inconnu. Lignes `final` et `blueprint` : toujours `null`, comportement inchangé (hors périmètre de l'issue).
2. **Effet d'affichage** : `fulfilledOf`, `remainingOf`, `percentOf`, `isFulfilled`, `containerQuantity`, `containerStockState`, `unclaimedQuantity`, `unclaimedValue` suivent la règle des matériaux (`max(fulfilledQuantity, min(container, requis))`). `stateOf` d'une ligne `component` renvoie l'état du container (`known`, `unknown`, `none`), jamais `none` à tort.
3. **Stock inconnu** : la ligne garde son état actuel (rien n'est compté), `containerStockState: unknown` est exposé, jamais de ligne traitée comme couverte.
4. **Prix de lancement** : `ProjectLaunchPriceFreezer::freeze` fige aussi le prix Jita (5e centile) des lignes `component` (`IndustryProjectBomLine::isPurchasable`), jamais celui des lignes `final` ni `blueprint`. Projet déjà en cours sans prix figé : « prix de lancement inconnu » (réclamation refusée, don possible), comme #1006.
5. **Réclamer** (participant) : `POST /contributions` avec `valueSource: launch_price` sur une ligne `component` fabriquée ou réagie, projet en cours ou en vente. Garde `UnclaimedStockGuard` inchangée (stock connu, quantité au plus le non réclamé). Valeur = quantité × prix de lancement de la ligne. Sans `valueSource` `launch_price`, un apport d'intermédiaire hors projet solo reste refusé (message « intermediate contribution … solo project »). `wallet_purchase` et `jita_price` restent refusés sur une ligne de job.
6. **Attribuer, donner** (propriétaire, `POST /contributions/unclaimed-stock`) : acceptés aussi sur une ligne `component` ; refusés sur toute autre ligne de job (`DomainException`).
7. **Apport approuvé = stock de départ** : l'approbation d'un apport matériau sur une ligne `component` recalcule la BOM (`recalculateBomOf`, déjà en place pour le solo) : `findIntermediateStockByTypeId` alimente `ProductionPlanRequest::$startingStock`, les runs de la ligne baissent, les entrées des réactions ou jobs couverts sortent de la BOM, la quantité consommée reste dans `requiredQuantity`. Un apport en attente ne change pas le plan. Un recalcul refusé (job lié, feuille déjà apportée) = 400 avec la raison, rien n'est enregistré.
8. **Front, Jobs to launch** (`JobsTable.vue`) : ligne `component` sans job lié dont `containerStockState` est `known` :
   - `containerQuantity >= requiredQuantity` : statut « Couvert par le container » (ni « À lancer », ni « Actif »), ni « I'll handle it » ni saisie de quantité ;
   - `0 < containerQuantity < requiredQuantity` : statut « À lancer », avec « Container : X / Y, reste Z à lancer » (Z = `remainingQuantity`) ;
   - stock inconnu : statut actuel et état du container affiché ;
   - le compteur d'en-tête compte les lignes couvertes à part ;
   - `UnclaimedStockBadge` s'affiche sous la ligne (attribuer et donner pour le propriétaire).
9. **Front, Contribute** (`ContributeModal.vue`) : en projet de groupe, une ligne `component` fabriquée ou réagie avec `unclaimedQuantity > 0` et `launchPrice` connu est proposée parmi les matériaux, quantité préremplie au non réclamé, envoie `valueSource: launch_price` (seule source). Hors groupe (solo) : comportement inchangé.

## Exemples

| Requis | Container | Réclamé | Résultat |
|---|---|---|---|
| 120 | 360 | 0 | ligne couverte, non réclamé 120, runs inchangés |
| 120 | 360 | 30 approuvé | runs 3 → 2, requis 110 conservé, non réclamé 80 |
| 120 | 50 | 0 | « À lancer », container 50 / 120, reste 70 |
| 120 | inconnu | 0 | état actuel, `unknown`, rien de couvert |
| `final` 1 | 1 | 0 | inchangé (hors périmètre) |

## Hors périmètre

Lignes `final` et `blueprint` ; proposition de stock d'intermédiaires (#708, solo) ; coût inconnu tant que du stock n'est pas réclamé sur les lignes de job (#1051 gère les matériaux ; à reprendre si elle est étendue aux jobs).

## Décisions ouvertes

- Ligne entièrement couverte : le bouton « Contribute » de la ligne n'est pas affiché (`isFulfilled`), comme pour un matériau ; le « Contribute » général de la page la propose tant que `unclaimedQuantity > 0` (aucun filtre `isFulfilled` pour une ligne `component` réclamable), le propriétaire attribue ou donne par le badge.
- Le cockpit lit `fulfilledOf` : une ligne `component` couverte par le container compte comme faite avant la réclamation, comme un matériau couvert.
