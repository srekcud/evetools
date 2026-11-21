# #1152 — Record all wallet sales publishes one project event

Mode nuit, circuit:m, niveau 3 (code GroupIndustry, PR seule). Backend (nouvelle opération API Platform sur `IndustryProjectSale`, un service Mercure) et front (`SaleSuggestionsPanel.vue`, store `distribution.ts`). Aucune migration, aucun nouveau topic Mercure (topic existant `/group-project/{id}/events`).

## Contexte

« Record all as sales » (#1137) envoie un `POST .../sales/from-wallet-transaction` par transaction wallet ; chacun publie un événement `sale_recorded`, donc N événements et N salves de rechargements chez chaque client ouvert sur le projet (saturation de production du 2026-11-21). Le bouton « Record as sale » d'une ligne garde son appel et son événement unique.

## Règles

1. Nouvelle opération `POST /industry/projects/{projectId}/sales/from-wallet-transactions` (pluriel), corps `{ transactions: [{ transactionId, quantity? }] }`, mêmes contraintes par ligne que `RecordSaleFromWalletTransactionInput`, au moins 1 ligne et au plus `MAXIMUM_TRANSACTIONS_PER_BATCH = 100`.
2. Mêmes gardes d'accès que l'opération unitaire : utilisateur connecté, propriétaire ou admin (`ProjectAccessChecker::assertAdminOrOwner`), projet non terminé (refus de toute la requête, 400). Une transaction n'est lue que parmi les personnages de l'utilisateur.
3. Chaque ligne passe par la même logique d'enregistrement que l'opération unitaire (`RecordSaleFromWalletTransactionProcessor::record`), dans l'ordre reçu, un flush par ligne.
4. Échec partiel : une ligne refusée (`HttpException` : introuvable, achat, hors projet, antérieure au projet, quantité > transaction, déjà liée) n'interrompt pas les suivantes. La réponse (`201`) liste `recorded` (les `ProjectSaleResource`) et `failures` (`{ transactionId, message }`, message de l'exception).
5. Une transaction répétée dans la même requête : la 2e occurrence échoue comme « déjà liée » (conflit), la 1re est enregistrée.
6. Mercure et journal : si au moins une vente est enregistrée, **un seul** `Update` Mercure `sale_recorded` est publié sur `/group-project/{id}/events`, avec `data = { count, quantity, totalPrice }` (nombre de ventes, quantité totale, total ISK). Aucune vente enregistrée : aucun événement.
7. Le journal du projet garde **une entrée `sale_recorded` par vente** (`saleId`, `typeName`, `quantity`, `totalPrice`, comme l'opération unitaire) : `MercurePublisherService::publishGroupProjectEvents` envoie chaque entrée au listener d'activité et un seul `Update` à Mercure.
8. Front : `recordAllPlanned` envoie une seule requête avec les lignes du plan (ordre actuel : plus ancienne d'abord, `quantity` seulement pour une saisie valide), puis recharge les suggestions une fois. Chaque `failures[]` devient une ligne d'échec du panneau (date et personnage retrouvés par `transactionId` dans le plan, message du serveur). Une erreur de la requête entière (réseau, 400, 403) affiche le message dans `linkingError` et laisse `isRecordingAll` retomber.
9. Le store expose `recordSalesFromWalletTransactions(projectId, inputs)` ; les ventes enregistrées entrent dans `sales` comme avec l'appel unitaire.

## Exemples (tests rouges)

| Requête | Résultat |
|---|---|
| T1 (3 × 61,25 M) et T7 (12 × 60 M) du propriétaire | 2 ventes, 1 seul `Update` `sale_recorded` `{count: 2, quantity: 15, totalPrice: 903750000.0}`, 2 entrées de journal |
| T1, T7, un identifiant inconnu | 2 ventes, `failures` = 1 « Transaction not found », 1 `Update` `count: 2` |
| Une seule ligne refusée | `recorded: []`, 1 échec, aucun `Update` |
| T7 deux fois | 1 vente, 1 échec « This transaction is already linked to a sale. » |
| Utilisateur non propriétaire | 403, aucune vente, aucun `Update` |
| Projet terminé | 400 `Cannot record a sale on a completed project` |

## Hors périmètre

Les ventes manuelles et par contrat, le bouton unitaire, les watchers de `syncStore.getGroupProjectEvent()` (#1151, autre thread), tout plafond par quantité à vendre.

## Glossaire

Aucun terme nouveau : **Vente**, **Transaction wallet**.
