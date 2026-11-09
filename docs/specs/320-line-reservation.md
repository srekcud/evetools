# #320 : réserver une ligne de la BOM et contribuer depuis la ligne

Module unifié (#362). Maquette `industry-project-v3.html#p=6` (« Apports partiels »). Circuit m, mode nuit.

## Règles

1. **Réservation de ligne** (glossaire : « Je m'en charge ») : un participant accepté (propriétaire compris) réserve une quantité d'une ligne de BOM encore incomplète, matériau ou job. Une seule réservation par participant et par ligne : réserver de nouveau remplace la quantité.
2. **Quantité** : entière, `1` au plus égale au **libre** de la ligne = restant (`requiredQuantity - fulfilledQuantity`) moins les réservations des autres participants. Absente du corps : tout le libre. Libre à 0 ou hors bornes : 400.
3. **Libérer** : le participant retire sa propre réservation ; 404 s'il n'en a pas. On ne libère jamais celle d'un autre.
4. **Accès** : participant `accepted` seulement (403 sinon, même message que les autres routes), ligne d'un autre projet : 404, projet `completed` : 400 `Cannot reserve a line of a completed project`, ligne complète : 400 `The line is already complete`.
5. **Une réservation informe, elle ne bloque pas** : un autre participant contribue sur la ligne réservée comme avant.
6. **Plafond d'apport** : une contribution soumise ne dépasse pas le restant de la ligne (400 `The contribution exceeds the remaining quantity of the line`). Tous types de contribution.
7. **Retrait automatique** : (a) ligne complète par l'approbation d'une contribution : toutes les réservations de la ligne sont supprimées ; complétée par un apport dérivé (achat lié), la ligne n'expose plus de réservation à la lecture (la rangée tombe avec la ligne ou le participant) ; (b) une contribution approuvée réduit de sa quantité la réservation de son auteur sur la ligne (supprimée à 0) ; (c) un participant qui quitte ou est exclu perd toutes ses réservations ; (d) le projet passe `completed` : toutes les réservations du projet.
8. **Lecture** : chaque ligne de `GET /industry/projects/{projectId}/bom` porte `reservations` (`participantId`, `characterName` = main du joueur, jamais un alt, `quantity`, `isMine`), `reservedQuantity` et `freeQuantity` (restant moins réservé, au moins 0). Une ligne complète n'a aucune réservation.
9. **Temps réel** : action Mercure `line_reserved` sur `/group-project/{id}/events` à la réservation et à la libération (`data` : `bomItemId`, `bomItemName`, `characterName`, `quantity` (0 à la libération), `released`). Le front recharge la BOM sur cette action.
10. **Front** : colonne « Réservé par » (pastilles nom × quantité, la sienne mise en avant) et boutons sur chaque ligne incomplète des tables Matériaux et Jobs : « Je m'en charge » (champ quantité, défaut = libre), « Libérer » si la ligne est réservée par soi, « Contribuer » (ouvre `ContributeModal` avec la ligne ; quantité préremplie = sa réservation, sinon le restant). Aucun bouton si le projet est `completed` ou la ligne complète.

## Routes

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | `/industry/projects/{projectId}/bom/{lineId}/reservation` | `{quantity?: int}` | 201 `ProjectLineReservation` (`id`, `bomItemId`, `participantId`, `characterName`, `quantity`) |
| DELETE | même URI | aucun | 204 (provider = la réservation de l'appelant) |

Ressource API Platform, `uriVariables` avec `Link` sur le projet et la ligne, pas de contrôleur.

## Persistance

Table `industry_project_line_reservations` (migration `Version20261109210000`) : `id` uuid, `bom_line_id` (CASCADE), `participant_id` (CASCADE), `quantity` int, `reserved_at`, unique (`bom_line_id`, `participant_id`). Aucune donnée existante à migrer.

## Exemples (Tritanium 12 000, aucun apport)

| Action | Résultat |
|---|---|
| Brann réserve sans quantité | 12 000, libre 0 |
| Brann réserve 5 000, Aria réserve 8 000 | 400 (libre 7 000) ; Aria réserve 7 000, libre 0 |
| Brann réserve 5 000 puis 2 000 | une réservation de 2 000 |
| Brann apporte 2 000 (approuvé), réservation 5 000 | réservation 3 000 |
| Apport approuvé de 12 000 | plus aucune réservation |
| Contribution de 12 001 | 400 |

## Hors périmètre

Aucun retrait de champ ni de route. Pas de réservation par un participant non accepté. Pas de notification (la ligne dit qui s'en charge). Couleurs par participant de la couverture : seulement les pastilles.

## Décisions prises en autonomie

- Réservation partielle par quantité dès la première version (commentaire du 2026-11-03).
- Plafond d'apport appliqué à tous les types (l'approbation fait avancer `fulfilledQuantity` quel que soit le type).
- Pas de bouton « Contribuer » côté propriétaire distinct : il suit les mêmes règles que les autres participants.
