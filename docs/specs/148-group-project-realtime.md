# Spec #148 — Temps réel du détail d'un projet collaboratif

## Contexte

Les processors du module publient sur `/group-project/{id}/events` (privé, `MercurePublisherService::publishGroupProjectEvent()`) un message `{action, data, timestamp}`. Le jeton Mercure de l'utilisateur couvre ces topics pour les projets où il est membre `accepted` (`TokenProvider.php:46-51`). Mais :

- le message ne dit pas de quel projet il vient (un message SSE ne porte pas son topic) ;
- `stores/sync.ts:134-139` range tout message sans `category/title/id` sous `syncStatus[data.syncType]`, soit `syncStatus["undefined"]` ici ;
- `GroupIndustryDetail.vue:91-113` attend `getSyncProgress('group-project-{id}')` avec `status === 'completed'` et `data.eventType`.

Aucun événement n'atteint donc la vue : il faut recharger la page.

## Règles métier

- **R1 — Le message nomme son projet.** Le message publié porte `projectId` (UUID RFC 4122) en plus de `action`, `data` et `timestamp`. Topic, caractère privé, `action` et `data` inchangés.
- **R2 — Le store range l'événement par projet.** Un message qui porte `action` et `projectId` (et pas `syncType`) est un événement de projet collaboratif : le store garde le dernier événement reçu par projet (`{action, data, timestamp}`), lisible par `projectId`. Il n'écrit rien dans `syncStatus`. Les messages de sync et les notifications sont traités comme aujourd'hui.
- **R3 — La vue rafraîchit selon l'action.** À chaque nouvel événement du projet affiché :
  - `contribution_submitted`, `contribution_approved`, `contribution_rejected` → BOM + contributions ;
  - `member_joined`, `member_left` → membres + détail du projet ;
  - `sale_recorded` → ventes + distribution ;
  - toute autre action (`status_changed`, …) → détail du projet + BOM.
  Un événement d'un autre projet ne déclenche rien.
- **R4 — Deux événements identiques successifs rafraîchissent deux fois** (deux approbations d'affilée) : chaque message reçu compte, même si `action` et `data` sont égaux au précédent.

## Exemples chiffrés

| # | Entrée | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | `publishGroupProjectEvent('0b6f…-a1', 'sale_recorded', {saleId: 's-7'})` | payload sans `projectId` | payload `{action: 'sale_recorded', projectId: '0b6f…-a1', data: {saleId: 's-7'}, timestamp}` ; topic `/group-project/0b6f…-a1/events`, privé |
| E2 | Store : message `{action: 'contribution_approved', projectId: 'P1', data: {contributionId: 'c-3'}, timestamp}` | `syncStatus.undefined` écrit | dernier événement de P1 = cet événement ; `syncStatus` inchangé |
| E3 | Store : message de sync `{syncType: 'industry-jobs', status: 'completed', …}` | rangé sous `industry-jobs` | inchangé (garde-fou) |
| E4 | Store : message de notification `{id, category, title}` | transmis au store des notifications | inchangé (garde-fou) |
| E5 | Détail du projet P1, événement `contribution_approved` pour P1 | rien | `fetchContributions('P1')` et `fetchBom('P1')` appelés une fois |
| E6 | Détail de P1, `member_left` pour P1 | rien | `fetchMembers('P1')` et `fetchProjectDetail('P1')` |
| E7 | Détail de P1, `sale_recorded` pour P1 | rien | `fetchSales('P1')` et `fetchDistribution('P1')` |
| E8 | Détail de P1, `status_changed` pour P1 | rien | `fetchProjectDetail('P1')` et `fetchBom('P1')` |
| E9 | Détail de P1, `sale_recorded` pour P2 | rien | rien |
| E10 | Détail de P1, deux `contribution_approved` identiques pour P1 | rien | `fetchContributions('P1')` appelé deux fois |

## Hors périmètre

- Jeton Mercure figé à la connexion : un projet rejoint ou créé après l'ouverture de la connexion SSE n'est couvert qu'au prochain jeton (reconnexion, rechargement). À traiter à part.
- Contenu de `data` par action, et action `container_verified` (documentée, jamais publiée).
- Notifications toast pour ces événements.

## Décisions prises en autonomie

- `projectId` ajouté au payload (ajout de champ, aucun consommateur existant à casser) plutôt que de déduire le projet côté client.
- État dédié par projet dans le store de sync plutôt que de simuler un `SyncProgress` : un événement de projet n'est pas une sync.
