# Spec #217 — Contrats publics : inconnu au lieu de 0, types disparus, items en cache

## Contexte

`SyncPublicContractsHandler` (toutes les 30 min) lit les contrats `item_exchange` de The Forge, puis les items de chaque contrat, et indexe dans le pool `public_contracts.cache` (TTL 1 h) les contrats mono-type par `typeId` (`public_contract_prices_{typeId}`) avec une méta (`public_contract_prices_meta`). `PublicContractPriceService` lit cet index pour Profit Margins (`contractSell`, `contractCount`).

Défauts constatés sur `main` 3a38bad :
- un contrat dont les items ne sont pas lisibles n'est journalisé qu'en `debug` (`SyncPublicContractsHandler.php:176-182`) ;
- un type qui n'a plus de contrat garde son ancienne liste jusqu'à l'expiration (`:136-143`) ;
- `getContractCount()` renvoie 0 sur cache vide (`PublicContractPriceService.php:57-59`), donc « aucun contrat » quand la sync n'a jamais tourné ;
- les items de chaque contrat sont relus à chaque sync.

## Règles métier

- **R1 — Index connu.** L'index est connu quand la méta est présente. Méta absente (sync jamais faite ou expirée) : `getLowestUnitPrice()` et `getContractCount()` renvoient `null`.
- **R2 — Aucun contrat.** Méta présente et type absent de l'index : `getContractCount()` = 0, `getLowestUnitPrice()` = `null`.
- **R3 — Types disparus.** La méta porte la liste des `typeIds` indexés. Une sync supprime l'entrée de chaque type indexé à la sync précédente et absent de la nouvelle.
- **R4 — Items illisibles signalés.** Un contrat dont les items ne sont pas lisibles est journalisé en `warning` (avec son `contractId`). La fin de sync l'indique : `"<N> types indexed, <M> contracts unread"` quand M > 0, message inchangé sinon.
- **R5 — Cache des items par contrat.** Les items lus d'un contrat sont mis en cache (`public_contract_items_{contractId}`, même pool) jusqu'à son `date_expired`. Une sync suivante ne relit pas un contrat en cache. Un échec de lecture n'est jamais mis en cache. Un contrat sans `date_expired` n'est pas mis en cache.
- **R6 — API.** `ProfitMarginResource::$sellPrices['contractCount']` est `int|null`, `null` par défaut. Le frontend accepte déjà `null` (`types.ts:356`, `VenueComparisonTable.vue:78`).

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| E1 | Aucune sync (cache vide) | `getContractCount(34)` null, `getLowestUnitPrice(34)` null |
| E2 | Sync faite, Tritanium indexé 2 contrats, Pyerite absent | `getContractCount(34)` 2 ; `getContractCount(35)` 0, `getLowestUnitPrice(35)` null |
| E3 | Sync 1 indexe Tritanium et Pyerite ; sync 2 n'a plus de contrat Pyerite | après sync 2 : entrée Pyerite absente du cache, `getContractCount(35)` 0 ; Tritanium à jour |
| E4 | 3 contrats, items du contrat 20002 en erreur ESI | log `warning` avec `contractId` 20002 ; SyncTracker complete `"<N> types indexed, 1 contracts unread"` |
| E5 | Sync 1 lit les items des contrats 20001 et 20003 ; sync 2 liste 20001, 20003 et un nouveau 20009 | sync 2 ne requête que `/contracts/public/items/20009/` ; index identique à une lecture complète |
| E6 | Items de 20002 en erreur à la sync 1, lisibles à la sync 2 | sync 2 requête `/contracts/public/items/20002/` |
| E7 | Contrat expirant dans 2 h | entrée `public_contract_items_{id}` présente, expire à `date_expired` |
| E8 | Profit Margins sans sync | `sellPrices.contractCount` null, `contractSell` null |

## Cas limites

- Les entrées par type et la méta partagent le TTL de 1 h : si la méta expire, R1 rend tout inconnu même si une entrée de type subsiste.
- Un contrat accepté ou expiré disparaît de la liste ESI : son cache d'items n'est plus lu et expire seul.

## Hors périmètre

- Pagination de `/contracts/public/items/{id}/` (page 1 seule, comme aujourd'hui) : un contrat de plus de 5 000 lignes d'items reste lu partiellement. Non vérifié, à ouvrir en issue séparée si besoin.
- Autres régions que The Forge, contrats `auction`.

## Décisions (validées par Sylvain le 2026-10-22)

- Cache des items par contrat jusqu'à `date_expired` (R5).
- Inconnu = méta absente ; méta présente et type absent = 0 contrat (R1, R2).

## Glossaire

Termes existants : **Donnée manquante**. Aucun ajout.
