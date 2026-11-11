# #639 — Déclencher à la main chaque type de sync suivi

Circuit `circuit:m`, mode nuit. Module infra (page admin). Branche empilée sur #640 (clés `admin.sync.types.<type>`).

## Règles

1. `App\Service\Admin\TriggerableSyncs` (classe finale, méthodes statiques) est la seule définition des types déclenchables : type → liste de messages à dispatcher. Elle couvre tous les types de `SyncTracker::EXPECTED_INTERVALS` et de `SyncTracker::ON_DEMAND`.
2. Les messages sont ceux du `SyncScheduler` (24 types planifiés), `TriggerAssetsSync` pour `assets`. `market-alerts` est synchrone (aucun message) : le processeur exécute `MarketAlertService::checkAlerts()` comme `check_market_alerts`.
3. `market-jita` et `market-structure` ne dispatchent chacun que leur propre message (`TriggerJitaMarketSync`, `TriggerStructureMarketSync`).
4. `SyncTracker::getAll()` ajoute `triggerable` (bool) à chaque entrée, dérivé de `TriggerableSyncs`. `SchedulerHealthEntryDto` l'expose (`triggerable`), le front le type dans `SchedulerHealthEntry`.
5. Nouvelle opération `POST /admin/actions/sync` (`name: trigger_sync`, `TriggerSyncInput` : `type` non vide) traitée par `TriggerSyncProcessor` : admin seulement (mêmes gardes), appelle `SyncTracker::setTriggeredBy($type, $userId)` puis dispatche les messages, réponse `success: true`, `message: "Sync triggered: <type>"`. Type inconnu ou non déclenchable : `success: false`, `message: "Unknown sync type"`, rien dispatché.
6. Mercure : aucun nouveau topic, `SyncTracker` notifie déjà `admin-sync` au démarrage, à la fin et à l'erreur (setTriggeredBy).
7. Front : `SchedulerHealthTable` affiche le bouton quand `entry.triggerable` et émet `execute-action` avec le type ; il ne reçoit plus de `syncActionMap`. `Admin.vue` n'a plus de table type → action : un seul `adminStore.triggerSync(type)` (POST `/admin/actions/sync`, corps `{type}`). Le spinner suit le type de la ligne.

## Cas limites

- Types hors table (purges de notifications, historique) déclenchables par la même route ; les routes `/admin/actions/sync-*`, `check-market-alerts`, `purge-*` restent inchangées (aucune route retirée).
- Libellé du bouton : `admin.triggerSync` existant ; le nom du type vient de `admin.sync.types.<type>`.

## Hors périmètre

- Déclencheurs par utilisateur ou personnage (#647).
- Retrait des fonctions `syncAssets`, `syncPve`… du store admin et des routes historiques : gardés, question notée au journal.

## Décisions

- Route générique à corps JSON plutôt que `{type}` dans l'URL : évite les `uriVariables`/`Link` sans ressource identifiable.
- Un test garde la cohérence : tout type de `EXPECTED_INTERVALS` ou `ON_DEMAND` est déclenchable.
