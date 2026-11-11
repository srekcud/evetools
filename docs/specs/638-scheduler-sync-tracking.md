# #638 — Suivre les six tâches planifiées absentes de la santé des syncs

Circuit `circuit:m`, mode nuit. Module infra (scheduler, page admin).

## Règles

1. Chaque message récurrent de `SyncScheduler` a une clé `SyncTracker` dans `EXPECTED_INTERVALS` (même intervalle que le planning) et dans `LABELS`.
2. Six clés nouvelles :

| Message | Clé | Libellé | Intervalle (s) |
|---|---|---|---|
| `TriggerMiningOperationRecurrences` | `mining-operation-recurrences` | Recurring Mining Operations | 1800 |
| `CheckMoonChunkAlerts` | `moon-chunk-alerts` | Moon Chunk Alerts | 300 |
| `CheckMiningSiteDepletionAlerts` | `mining-site-depletion-alerts` | Mining Site Depletion Alerts | 300 |
| `PurgeOldNotifications` | `notifications-purge` | Notifications Purge | 86400 |
| `PurgeOldMarketHistory` | `market-history-purge` | Market History Purge | 86400 |
| `PurgeExpiredSharedLists` | `shared-lists-purge` | Shared Lists Purge | 86400 |

3. Chaque handler appelle `SyncTracker::start` au début, `complete` (message court avec le compte quand il est connu) à la fin, `fail` en cas d'échec.
4. Comportement d'erreur inchangé : `TriggerMiningOperationRecurrences`, `CheckMoonChunkAlerts`, `CheckMiningSiteDepletionAlerts` et `PurgeExpiredSharedLists` relancent l'exception après `fail` ; `PurgeOldNotifications` et `PurgeOldMarketHistory` continuent de journaliser sans relancer (les trois purges de l'historique restent indépendantes), mais appellent `fail` avec le message de l'échec (plusieurs échecs : messages joints par `; `) au lieu de `complete`.
5. Aucun topic Mercure dédié : synchronisations globales, suivies par `admin-sync` (déjà géré par `SyncTracker`).
6. Le test `SyncSchedulerIntervalsTest` n'exempte plus aucun message : tout message planifié sans clé connue, ou d'intervalle différent, fait échouer le test.

## Cas limites

- Aucune récurrence active, aucune notification à purger : `complete` avec un compte de zéro, pas d'erreur.
- Hors périmètre : renommer ou supprimer une clé existante, changer les intervalles du planning, frontend (libellés et intervalles viennent de l'API `getAll`).

## Décisions

- Les clés suivent le style kebab-case existant ; libellés en anglais comme les entrées récentes.
- Le tableau Scheduler de `CLAUDE.md` décrit déjà ces tâches : pas de changement.
