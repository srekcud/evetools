# Spec — État d'affichage unique d'un job lié (#1201)

## Contexte

L'arbre de production, le compagnon et le Journal répondent chacun différemment à « mon job est-il terminé ? » : le compagnon calcule « prêt » depuis `endDate` (`companionTasks.ts::isReadyToDeliver`), l'arbre affiche le statut ESI brut (`ProductionChainJobTree.vue`, `esiJobStatusLabel`) sans compte à rebours, le Journal compte à rebours seul (`ProjectJournal.vue`, `jobCountdown.ts`). Un job `active` dont la date de fin est passée est « En cours » dans l'arbre et « à livrer » dans le compagnon, qui libelle de plus `ready` « Terminé ».

Front seulement. Le statut de ligne du backend (`ProjectChainBuilder`, `ready` compté « En cours » jusqu'à la livraison) et `ProjectRunningJobsReader` (jobs `active` seuls, test existant) ne changent pas : une ligne dont le job attend sa livraison reste en cours.

## Règles

- **R1.** Un module pur `jobDisplayState.ts` (`frontend/src/components/group-industry/`) expose `jobDisplayOf({ status, endDate }, now)` qui rend `{ state, remainingMs }`. États : `running`, `paused`, `ready`, `delivered`, `cancelled`, `reverted`, `unknown`.
- **R2.** `ready` : statut ESI `ready`, ou statut `active` avec une `endDate` atteinte (`endDate <= now`). `remainingMs` vaut 0.
- **R3.** `running` : statut `active` avec `endDate` future ou inconnue ; `remainingMs` = `endDate - now`, `null` quand `endDate` est `null`, jamais 0 par défaut.
- **R4.** `paused` : statut `paused`, `remainingMs` = `max(0, endDate - now)` ou `null` sans `endDate`.
- **R5.** `delivered`, `cancelled`, `reverted` : `remainingMs` `null`. Tout autre statut : `unknown`, `remainingMs` `null`, jamais un état plausible.
- **R6.** Libellés partagés, une seule clé par état, sous `groupIndustry.jobs.displayState.*` (FR et EN) : `ready` se lit « Ready to deliver » / « À livrer », `running` « Running » / « En cours », `delivered` « Delivered » / « Livré ».
- **R7.** Arbre : chaque job lié d'une ligne affiche le libellé de son état ; en `running` avec `endDate`, le compte à rebours `HH:MM:SS` (`formatCountdown`) s'y ajoute et se met à jour chaque seconde. `data-testid` : `job-match-state` (attribut `data-state`) et `job-match-countdown`. Un job `active` passé sa fin affiche « À livrer », sans compte à rebours.
- **R8.** Compagnon : `jobTimingOf` délègue au module partagé ; la ligne d'état d'un job (`companion-job-status`) lit le libellé partagé de l'état calculé, plus le statut ESI brut. Un job `active` passé sa fin n'affiche plus « En cours » à côté de l'alerte « à livrer » ; `ready` n'est plus libellé « Terminé ». Les clés `groupIndustry.companion.jobStatus.*` sont retirées (remplacées).
- **R9.** Journal : le compte à rebours d'un job de la liste « En cours » se lit par `jobDisplayOf` (statut `active`, `endDate` = `endsAt`) ; l'état `ready` affiche le libellé partagé « À livrer » à la place de `journal.finished` (clé retirée).
- **R10.** Le `now` qui bat chaque seconde est un composable `useNow` partagé par l'arbre et le Journal, nettoyé au démontage.

## Exemples

| # | statut ESI | `endDate` vs maintenant | État | `remainingMs` |
|---|---|---|---|---|
| E1 | `active` | dans 1 h 02 min 05 s | `running` | 3 725 000 |
| E2 | `active` | il y a 1 s | `ready` | 0 |
| E3 | `active` | exactement maintenant | `ready` | 0 |
| E4 | `active` | `null` | `running` | `null` |
| E5 | `ready` | n'importe laquelle | `ready` | 0 |
| E6 | `paused` | dans 10 min | `paused` | 600 000 |
| E7 | `paused` | il y a 5 min | `paused` | 0 |
| E8 | `delivered` | passée | `delivered` | `null` |
| E9 | `cancelled`, `reverted` | passée | `cancelled`, `reverted` | `null` |
| E10 | `foo` | — | `unknown` | `null` |

## Cas limites

- `endDate` illisible : traité comme `null` par R3 (running sans compte à rebours), jamais ready.
- Journal : `endsAt` atteint pendant que la page est ouverte bascule en « À livrer » à la seconde suivante, sans rechargement.

## Hors périmètre

- Statut de ligne du backend, pastille de statut du nœud de l'arbre, liste « jobs à lier » (`JobLinkModal`, statut ESI brut d'un job non lié).
- Jobs `ready` dans la liste du Journal (le lecteur ne renvoie que les `active`, test existant).

## Décisions

- Les clés `companion.jobStatus.*` et `journal.finished` sont retirées : l'issue les remplace par l'état partagé (le libellé « Terminé » est le défaut décrit).
