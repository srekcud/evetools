# #1151 — Coalesce the group project Mercure events on the client

Statut : validée en autonomie (mode nuit). Front seulement, aucune section retirée.

## Contexte

Chaque message `/group-project/{id}/events` relance, chez chaque client ouvert, un refetch de `GroupIndustryDetail.vue` (jusqu'à 5 requêtes) et de chaque section montée (`ProductionChain` 2, `MaterialSourcing`, `ProjectBuyOrBuild`, `ProjectCourier`, `ProjectPlanning`, `ProjectPurchasesToDeliver`, `ProjectReactionSteps`, `ProjectResearch`, `ProjectStructures`) et de `GroupCompanionHost` (4 à 5). Une rafale d'événements multiplie ces requêtes et les réponses arrivent après l'événement suivant.

## Règles

1. Un composable `useCoalescedGroupProjectEvents(projectId, refetch, coalesceMs)` remplace chaque `watch(() => syncStore.getGroupProjectEvent(...))` des 11 consommateurs. Il observe les événements du projet courant seulement (`flush: 'sync'`, chaque message compte) et appelle `refetch(actions)` avec les actions reçues depuis le dernier appel.
2. **Fenêtre de coalescence** `GROUP_PROJECT_EVENT_COALESCE_MS = 3000`, une par consommateur (donc par endpoint) :
   - fenêtre fermée : le premier événement déclenche le refetch aussitôt (une action isolée garde son retour immédiat) et ouvre la fenêtre ;
   - fenêtre ouverte : les événements s'accumulent ; à la fermeture, s'il y en a, un seul refetch reçoit toutes leurs actions et rouvre la fenêtre. Une rafale de N événements coûte donc au plus 2 refetchs par endpoint, et le rythme ne dépasse jamais 1 refetch par fenêtre.
3. **Pas de refetch doublé** : tant qu'un refetch est en vol, aucun autre ne démarre ; les événements reçus entre-temps s'accumulent et un seul refetch part à la fin du vol (fenêtre fermée).
4. **Onglet de navigateur caché** (`document.visibilityState === 'hidden'`) : aucun refetch ; les actions s'accumulent et un seul refetch part au retour en `visible` (événement `visibilitychange`).
5. Désabonnement à la destruction du scope : minuteur effacé, écouteur retiré, plus aucun refetch.
6. **Sections cachées** : les sections d'un onglet fermé ne sont pas montées (`v-if`), donc n'observent rien et se chargent à l'ouverture (montage). `GroupIndustryDetail` ne rafraîchit que : `projectDetail`, `bom`, `cockpit`, `members` (toujours utiles : en-tête, rail, modale de contribution, bandeau « travaillé seul »), plus les rafraîchissements de l'onglet actif (`contributions` → onglet contributions ; `sales`, `saleSuggestions` → ventes ; `distribution`, `settlement` → distribution ; `activity` → journal). Les autres sont ignorés : l'ouverture de leur onglet les charge déjà (`watch(activeTab)` pour contributions, distribution, membres, journal ; montage de `SalesTable` et `SaleSuggestionsPanel` pour les ventes). Les rafraîchissements d'une rafale sont dédoublonnés (une seule requête par type).
7. `GroupCompanionHost` : un seul `companion.handleEvents(actions)` par flush ; les membres sont rechargés si au moins une action commence par `member_` (admin seulement, comme aujourd'hui). `handleEvent(action)` reste et délègue à `handleEvents([action])`.

## Exemples (tests rouges)

| Situation | Attendu |
|---|---|
| 1 événement, fenêtre fermée | 1 appel immédiat avec `['a']` |
| 5 événements dans la fenêtre | 1 appel immédiat, puis 1 appel à la fermeture avec les 4 actions suivantes |
| événement après une fenêtre calme | appel immédiat |
| refetch en vol, fenêtre écoulée, 2 événements | aucun appel jusqu'à la fin du vol, puis 1 appel avec les 2 actions |
| onglet caché, 3 événements | 0 appel ; retour `visible` : 1 appel avec les 3 actions |
| événement d'un autre projet / scope arrêté | aucun appel |
| détail sur l'onglet `bom`, `sale_recorded` | cockpit rechargé, `/sales` et `/distribution` non ; ouverture de l'onglet ventes : `/sales` chargé |
| détail, `contribution_submitted` + `sale_recorded` dans la fenêtre | cockpit rechargé 2 fois au plus (1 immédiat, 1 à la fermeture) |
| `ProjectResearch`, 5 événements rapprochés | 1 lecture au montage + 2 au plus |
| compagnon, `handleEvents(['sale_recorded','member_joined'])` admin | 1 chargement de projet, de BOM, de chaîne, d'alertes et de membres |

## Cas limites

- Événement reçu pendant la fenêtre puis scope détruit : rien ne part.
- `refetch` qui rejette : la fenêtre et le vol sont libérés (`finally`), l'erreur reste gérée par le store.
- Changement de projet dans la vue : le getter suit `projectId` ; les actions accumulées de l'ancien projet ne sont pas rejouées.

## Tests existants

Les specs de section qui publient un événement sont conçues avec un seul événement par fenêtre : elles ne changent pas. Celles qui en publient deux d'affilée sur le même consommateur passent en minuteurs simulés (à lister après le vert, raison : coalescence voulue).

## Hors périmètre

Debounce côté backend, un seul événement par lot de ventes (#1152), niveau de chaîne (#1153), cache des endpoints, retrait d'une section ou d'un réglage.

## Décisions

- Fenêtre « premier tout de suite, puis un seul rattrapage » plutôt qu'un debounce final : une action isolée reste instantanée et les specs existantes ne changent pas ; coût d'une rafale : 1 requête de plus par endpoint.
- `bom`, `cockpit`, `projectDetail`, `members` restent toujours rafraîchis : utilisés hors de leur onglet.
- Pas de nouveau terme du glossaire.
