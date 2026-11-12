# Spec #646 : historique des runs par type de sync

Décisions de Sylvain (2026-11-11) : table Postgres, une ligne par run (type, début, fin, statut, message court), rétention 30 jours, purge par la tâche quotidienne existante (`PurgeOldNotificationsHandler`). Niveau 3 (migration, purge de données).

## Règles

1. **Run de synchronisation** : `SyncTracker::start()` crée une ligne `sync_runs` (`type`, `started_at`, `status = running`). `complete()` et `fail()` terminent le run `running` le plus récent du même type (`completed_at`, `status = ok|error`, `message`). Sans run `running` à terminer, `complete()` et `fail()` créent une ligne déjà terminée (début = fin = maintenant), comme l'état du cache le fait déjà.
2. Les types suivis sont ceux de `EXPECTED_INTERVALS` et `ON_DEMAND`. Le cache reste la source du dernier état, de la santé, de `last_success_at` et des notifications Mercure : rien de ce qu'il fait aujourd'hui ne change.
3. Une panne de la base d'historique ne doit jamais interrompre une sync ni masquer son erreur : l'écriture échouée est journalisée (`error`) et la sync continue. Le tracker n'écrit pas par l'`EntityManager` (fermé après une erreur Doctrine) mais par DBAL.
4. Le message d'un run est tronqué à 500 caractères (colonne `varchar(500)`).
5. **Lecture** : chaque entrée de `schedulerHealth` porte `runs` (les 20 derniers runs du type, du plus récent au plus ancien) et `successRate24h`.
   - un run `running` plus vieux que le seuil de blocage du type (2 × intervalle ; 3600 s pour un type à la demande, mêmes constantes que `reportStuckRunning`) est présenté `error` avec le message de `reportStuckRunning` et `stuck = true` ;
   - `successRate24h` = part de runs `ok` (entier, arrondi à l'entier le plus proche) parmi les runs terminés (`ok` ou `error`) démarrés dans les 24 dernières heures ; `null` s'il n'y en a aucun (jamais 0 ou 100 supposé). Un run `running` n'y compte pas.
   - un type sans run : `runs = []`, `successRate24h = null`.
6. **Purge** : `PurgeOldNotificationsHandler` supprime en plus les runs démarrés il y a plus de 30 jours ; le message de son propre run devient `N notification(s) deleted, M sync run(s) deleted`. Si la purge des runs échoue, le handler échoue comme aujourd'hui (`SyncTracker::fail`).
7. **Front** : dans `SchedulerHealthTable`, une colonne « Historique » affiche par type une mini frise des runs (du plus ancien à gauche au plus récent à droite) : un carré par run, vert `ok`, rouge `error`, bleu pulsant `running` ; `title` = statut, date de début, durée (`--` pour un run en cours) et message. À côté, le taux de succès 24 h (`92 %`, ou `--` si `null`). Texte via i18n fr/en, clés sous `admin.sync.history*`.

## Exemples

| Runs 24 h (terminés) | `successRate24h` |
|---|---|
| 3 ok, 1 error | 75 |
| 2 ok, 1 error | 67 |
| 0 ok, 2 error | 0 |
| 1 running seulement | null |
| aucun | null |

## Hors périmètre

- Historique par utilisateur (#647) ; endpoint dédié (les runs voyagent dans `GET /admin/stats`) ; page de détail d'un type ; alerte sur le taux de succès.
- Aucune purge à la demande côté admin.

## Actions prod

- Migration `sync_runs` via `bin/deploy`. Aucune commande console.

## Décisions de nuit

- Seuil « bloqué » et taille de la frise (20) en constantes ; frise de 20 carrés fixe, pas de réglage.
- Pas de rattrapage : les runs d'avant le déploiement n'existent pas, le cache n'en garde qu'un.
