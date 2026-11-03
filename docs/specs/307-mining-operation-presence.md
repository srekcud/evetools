# Spec #307 : présence des participants pendant une opération de minage

Statut : validée en autonomie (mode nuit), à relire par Sylvain. Dépend de #262, #263. Maquette : `mockups/grouped-mining-v4.html` (`.pres`, `presenceOf()`, liste « Participants » du compagnon, étapes 7 et 10).

## Contexte

L'Owner veut savoir qui mine encore sans demander sur le canal vocal. L'ESI ne donne pas l'état en jeu : la présence est celle de l'écran, d'après la dernière activité dans evetools.

Signaux disponibles dans ce lot : la page détail de l'opération ouverte (battement) et « Je pars » (`departedAt`, #263 R7). Le compagnon et le collage de scan n'existent pas encore (#267, #271) et ne sont pas des signaux ici.

## Règles

- **R1 — Dernière activité.** `MiningOperationMember` gagne `lastSeenAt` (datetime immuable, nullable, migration). Il n'est jamais renseigné par un autre chemin que R2.
- **R2 — Battement.** `POST /api/mining-operations/{id}/presence` (`EmptyInput`, réponse 200, l'opération comme `…/depart`). Il agit sur la membership de l'utilisateur authentifié, aucun identifiant de membre en entrée. `lastSeenAt` = heure de l'horloge. Refus : sans membership `accepted` (absent, `pending`, `removed`) `404` « Membership not found » ; opération hors `in_progress` `400` « Presence is only tracked while an operation is in progress » ; participant déjà parti `400` « You already left this operation ». Le propriétaire est un membre `accepted`.
- **R3 — État.** `MiningPresenceResolver` (service pur, horloge injectée) rend un état et des minutes d'inactivité :
  1. participant parti (`departedAt` non nul) : `left`, minutes `null` ;
  2. `lastSeenAt` nul : `unknown`, minutes `null` (jamais traité comme en ligne ni comme parti) ;
  3. inactivité ≤ 120 s (`ONLINE_WINDOW_SECONDS`) : `online`, minutes `0` ;
  4. sinon `afk`, minutes = inactivité en minutes entières arrondies à l'inférieur.
  Aucune borne haute : un participant sans battement depuis 95 min est `afk` à 95 min. « Parti » ne vient que de « Je pars ».
- **R4 — Exposition.** `MiningOperationMemberResource` gagne `presence` (`online|afk|left|unknown|null`) et `presenceIdleMinutes` (`int|null`). Ils valent `null` pour un membre non `accepted` et pour toute opération hors `in_progress`. La liste des membres reste réservée aux participants (`assertCanParticipate`) ; `lastSeenAt` n'est pas exposé.
- **R5 — Mercure.** Le battement publie `presence_changed` (`{memberId}`) sur le topic existant `/user/{userId}/mining-operations`, vers les participants (`MiningOperationAudience::participantIdsOf`), **seulement** si l'état avant le battement n'était pas `online` (premier battement, retour d'un AFK). Un battement qui garde l'état `online` ne publie rien. Aucun nouveau topic.
- **R6 — Interface.** Sur la page détail, opération `in_progress` et utilisateur membre `accepted` non parti : un battement à l'ouverture puis toutes les 30 s (`PRESENCE_HEARTBEAT_SECONDS`), arrêté à la fermeture de la page et dès que l'opération n'est plus `in_progress` ou que l'utilisateur est parti. Que l'onglet soit visible ou non, la page ouverte compte. Les membres sont rechargés sur `presence_changed` (pas l'opération entière) et toutes les 60 s (pour le passage en AFK sans événement).
- **R7 — Carte des participants.** Chaque participant `accepted` d'une opération `in_progress` affiche une pastille (`online` vert, `afk` ambre, `left` ardoise, `unknown` ardoise creuse) et un texte : « En ligne », « AFK · {n} min », « Parti », « Pas encore vu ». Direction HUD de la maquette ; un nom long ne doit pas casser la ligne. Libellés fr et en dans les deux fichiers de locales.

## Exemples chiffrés (`now` = 20:00:00 EVE, opération `in_progress`, membre `accepted`)

| # | `lastSeenAt` | `departedAt` | État | Minutes |
|---|---|---|---|---|
| P1 | 19:59:30 | nul | `online` | 0 |
| P2 | 19:58:00 (120 s) | nul | `online` | 0 |
| P3 | 19:57:59 (121 s) | nul | `afk` | 2 |
| P4 | 19:48:00 | nul | `afk` | 12 |
| P5 | 18:25:00 | nul | `afk` | 95 |
| P6 | nul | nul | `unknown` | `null` |
| P7 | 19:59:50 | 19:30:00 | `left` | `null` |
| P8 | 19:59:50, opération `closing` | nul | `presence` `null` | `null` |
| P9 | membre `pending` | nul | `presence` `null` | `null` |

| # | Scénario du battement | Résultat |
|---|---|---|
| E1 | Brann `accepted`, jamais vu, `in_progress` | 200, `lastSeenAt` = now, un `presence_changed` publié |
| E2 | Brann revient 30 s plus tard | 200, `lastSeenAt` mis à jour, rien publié |
| E3 | Brann revient après 5 min de silence (AFK) | 200, un `presence_changed` publié |
| E4 | Brann `pending` | 404 « Membership not found », rien écrit |
| E5 | Opération `planned` | 400, rien écrit |
| E6 | Opération `closing` | 400, rien écrit |
| E7 | Brann parti | 400 « You already left this operation » |
| E8 | Utilisateur sans membership | 404 « Membership not found » |
| E9 | Aria (Owner) | 200 |

## Accès et fraîcheur

- Écriture : uniquement sur sa propre membership `accepted` (déduite de l'utilisateur authentifié) ; un ex-membre (`removed`) est refusé comme un inconnu.
- Lecture : les participants de l'opération via la liste des membres existante. Un lecteur par corporation (accès `read`) ne voit pas la présence (il n'a pas accès à cette liste).
- Fraîcheur : `lastSeenAt` vient du serveur, jamais d'un horodatage du client.

## Tests existants modifiés

`MiningOperationMemberCollectionProviderTest` et les fixtures front de membres (`frontend/tests/views/MiningOperation/*Fixtures.ts`) construisent des membres sans `presence` : champs ajoutés, à compléter sans changer d'assertion. Aucun test exhaustif sur le nombre de topics ou de routes ne change (même topic).

## Hors périmètre

Compagnon (fenêtre détachée), collage de scan comme signal d'activité, notification d'AFK, historique de présence, présence hors opération `in_progress`, heures minées (elles restent celles de la fenêtre des instantanés), état en jeu.

## Glossaire

Ajouté : **Présence**, **État de présence** (`MiningPresence`).

## Décisions prises en autonomie

1. « Parti » = « Je pars » seulement ; une longue absence reste AFK avec ses minutes (pas de seuil inventé).
2. Un participant jamais vu est `unknown`, pas `online`.
3. Aucune présence tant que l'opération n'est pas `in_progress` ; le battement est refusé hors `in_progress`.
4. Seuil en ligne 120 s, battement 30 s, rechargement 60 s : constantes nommées, à ajuster sans changer la règle.
