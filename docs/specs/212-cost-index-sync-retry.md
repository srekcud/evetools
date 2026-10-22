# Spec #212 — Sync des indices de coût et des prix ajustés : rejouer un échec

## Contexte

Trois constats dans l'issue :

1. TTL du cache égal à l'intervalle de sync : **corrigé** par #7 L4 (`EsiCostIndexService.php:28-37`, 3 intervalles : 6 h pour les indices, 72 h pour les prix ajustés ; tests `testCostIndexIsStillServed…`, `testAdjustedPriceIsStillServed…`).
2. Cache expiré → coût d'installation 0 : **corrigé** par #7 L1 à L5 (coût inconnu, `JobInstallCostEstimator`, plus de `calculateJobInstallCost`).
3. `SyncCostIndicesHandler` et `SyncAdjustedPricesHandler` attrapent l'exception de la sync, la journalisent et la signalent à `SyncTracker`, puis l'avalent : Messenger ne rejoue pas le message (`retry_strategy` du transport `async` : 3 tentatives) et il n'arrive jamais dans le transport `failed`. **Reste à corriger.**

La plupart des handlers de sync (`TriggerAnsiblexSyncHandler:75-77`, jobs, wallet, PI, PVE, minage, assets, marché structure) relancent l'exception après `syncTracker->fail()`.

## Règles métier

- **R1.** Une sync des indices de coût ou des prix ajustés qui échoue est journalisée en erreur, marquée en échec dans `SyncTracker` (type `cost-indices` / `adjusted-prices`), puis l'exception est **relancée** pour que Messenger rejoue le message, puis le range dans `failed`.
- **R2.** Une sync réussie est inchangée (`complete` avec le compte).

## Exemples

| # | Cas | Attendu |
|---|---|---|
| E1 | `syncCostIndices()` lève `EsiApiException` | le handler relance la même exception ; `SyncTracker::fail('cost-indices', message)` appelé une fois ; journal d'erreur ; `complete` jamais appelé |
| E2 | `syncAdjustedPrices()` lève | idem, `adjusted-prices` |
| E3 | `syncCostIndices()` renvoie 1 234 | `complete('cost-indices', '1234 systems')`, pas d'exception |
| E4 | `syncAdjustedPrices()` renvoie 14 000 | `complete('adjusted-prices', '14000 types')` |

## Hors périmètre

Stratégie de retry du transport, sync des jobs, autres handlers (`TriggerJitaMarketSyncHandler`, `SyncPublicContractsHandler`, `CheckAlertPricesHandler` avalent aussi : à traiter ailleurs si voulu).

## Glossaire

Aucun ajout.
