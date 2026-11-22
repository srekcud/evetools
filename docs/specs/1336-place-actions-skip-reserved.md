# Spec #1336 : place actions and multibuy skip what other members reserved

Circuit m. Branche d'intégration `feat/industry-flow`. Suite de #1300 (actions par lieu), #1301 (réservation de 48 h) et #1303 (page À faire, achat groupé). Glossaire : **Action par lieu**, **Réservation de ligne**.

## Problème

`SourceMaterialActions` propose d'acheter `remainingOf($line)` et `LaunchJobActions` propose de lancer toute ligne de job restante, sans lire les réservations. Deux membres peuvent acheter ou lancer la même chose, et l'achat groupé copie des quantités déjà prises par un autre membre. Le compagnon respecte déjà la règle (`freeQuantity`).

## Règles

1. **Réservation active** : une `IndustryProjectLineReservation` non échue (`IndustryProjectLineReservationRepository::findByProject`, qui filtre déjà `expiresAt > now`). Une réservation échue n'existe pas pour ces règles.
2. **Réservation d'un autre membre** : réservation active de la ligne dont le participant n'est pas le lecteur (comparaison d'objet `User`, comme `ProjectBomProvider` pour `isMine`). Seules les lignes non soldées comptent : le dépôt supprime déjà les réservations d'une ligne complète.
3. **Action d'achat** (`SourceMaterialActions`) : quantité = `remainingOf($line)` moins la somme des réservations des autres membres sur la ligne, jamais en dessous de 0. Quantité nulle ou négative : pas d'action. La réservation du lecteur ne se soustrait pas : elle reste une action pour lui, pour le restant moins les autres.
4. **Action de lancement** (`LaunchJobActions`) : pas d'action pour le lecteur quand au moins un autre membre a une réservation active sur la ligne et que le lecteur n'en a aucune. Le lecteur qui a réservé la ligne la garde, même si un autre membre en a aussi une. La quantité de runs de l'action ne change pas (`runs - countedRuns`).
5. **Dire qui et combien** : `PlacedAction` porte `reservedByOthers`, liste ordonnée (date de réservation croissante) de `{characterName: ?string, quantity: int}`, une entrée par réservation d'un autre membre sur la ligne (nom = `PilotName::of` du membre, `null` si le membre n'a plus d'utilisateur). Vide quand personne d'autre n'a réservé. Renseignée pour l'achat (quand l'action reste offerte) et pour le lancement (quand le lecteur garde l'action). Les actions de revue ne changent pas (liste vide).
6. **API** : `PlacedActionResource` expose `reservedByOthers` (`list<array{characterName: ?string, quantity: int}>`, `[]` par défaut), même source pour la page À faire (`GET /industry/place-actions`) et la colonne du projet (`GET /industry/projects/{projectId}/place-actions`). `UserActionReader::countsOfProject` compte déjà les actions via les sources : le compte d'un membre baisse donc des lignes cachées.
7. **Achat groupé** : le front lit `action.quantity` (`placeMultibuy`), donc il copie la quantité libre. Aucun changement de calcul côté front ; une ligne dont la quantité libre est 0 n'arrive plus.
8. **Front, page À faire** (`TodoPlaceGroup.vue`) : sous le détail de l'action, quand `reservedByOthers` n'est pas vide, une ligne `data-testid="todo-reserved-by-others"` : « Déjà pris : Mira 40, Kaelen 20 » (`industryTodo.reservedByOthers`, FR + EN, `Unknown` du nom nul traduit par la clé inconnue existante).
9. **Front, détail du projet** : la colonne À faire du projet (`ProjectFlowActions.vue`) affiche la même ligne (`data-testid="flow-action-reserved-by-others"`, clé `groupIndustry.flow.actions.reservedByOthers`). Le détail de carte d'approvisionnement (`FlowDetailSupplyBlock.vue`, bloc `useMyStock`) liste sous « Réservé » les réservations des autres membres de la ligne (`line.reservations` où `isMine` est faux : nom et quantité, `data-testid="flow-detail-reserved-by-others"`).
10. Pas de changement de schéma, pas de migration, pas de nouveau topic Mercure : les actions sont lues à la demande et `line_reserved` (déjà publié) fait relire la page.

## Exemples chiffrés (achat)

| Restant (conteneurs) | Réservations des autres | Réservation du lecteur | Action du lecteur |
|---|---|---|---|
| 400 | aucune | aucune | acheter 400, `reservedByOthers` vide |
| 400 | Mira 150 | aucune | acheter 250, `reservedByOthers` = [Mira 150] |
| 400 | Mira 150, Kaelen 100 | aucune | acheter 150, deux entrées (ordre de réservation) |
| 400 | Mira 400 | aucune | aucune action |
| 400 | Mira 500 | aucune | aucune action (jamais négatif) |
| 400 | aucune | lecteur 300 | acheter 400 |
| 400 | Mira 150 | lecteur 100 | acheter 250 |
| 400 | Mira 150 (échue) | aucune | acheter 400 |

## Exemples (lancement)

| Réservations de la ligne | Lecteur | Action de lancement |
|---|---|---|
| aucune | n'importe qui | offerte |
| Mira | Kaelen (rien) | aucune |
| Mira | Mira | offerte |
| Mira, Kaelen | Kaelen | offerte, `reservedByOthers` = [Mira q] |
| Mira (échue) | Kaelen | offerte |

## Cas limites

- Ligne de job sans runs (`runs === null`) : mêmes règles de masquage ; quantité de l'action `null` comme avant.
- Lecteur sans réservation, ligne de matière réservée en totalité par un autre : la ligne disparaît du multibuy et du compte d'actions du projet.
- Propriétaire seul (projet solo) : aucune réservation d'un autre membre, comportement inchangé.
- Statut du projet hors `Published`/`InProgress` (achat) ou hors `InProgress` (lancement) : aucune action, comme avant ; le dépôt n'est pas lu.
- Un seul `findByProject` par appel `actionsOf`, lu seulement quand l'action serait offerte.

## Hors périmètre

- Boutons « Je m'en charge » depuis la page À faire, compagnon (déjà conforme), réservation automatique.
- Retirer ou changer la règle de réservation (une par participant et par ligne, 48 h).
- Les actions de revue et le cockpit « prochaine action ».

## Décisions

- Lancement : la règle est littérale (toute réservation d'un autre membre masque la ligne à un lecteur qui n'a pas réservé), même quand la quantité réservée ne couvre qu'une partie de la ligne, comme l'écrit l'issue et le prototype (« une ligne réservée par un membre n'apparaît pas pour les autres »).
- « Détail de la carte » : pas d'écran de détail d'action n'existe ; la colonne À faire du projet et le bloc d'approvisionnement du détail de carte portent l'information (écart noté « À relire à la fin »).
