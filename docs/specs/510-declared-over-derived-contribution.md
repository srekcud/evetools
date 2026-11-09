# Spec #510 : apport dérivé qui arrive sur un apport déclaré du même participant, même ligne, même type

Décisions de Sylvain : questions du 2026-11-09 (issue #510, point 2). Le point 1 (refuser un apport déclaré quand un dérivé existe) est livré par #512.

## Contexte

Un apport dérivé (`DerivedContributionService`, `isAutoDetected`) est créé approuvé d'office, à partir des achats liés (matériau, au nom du propriétaire) ou des jobs liés (installation de job, au nom de chaque installateur). Seuls ces deux types sont dérivés : un BPC, une location de ligne et un courrier ne le sont jamais pour une ligne. Si le même participant avait déjà déclaré à la main un apport du même type sur la même ligne, les deux comptent dans la répartition, et un matériau compte deux fois dans `fulfilledQuantity`. Le glossaire dit qu'un apport approuvé ne diminue plus : cette règle reçoit une exception (R3).

## Règles

- **R1 — Le dérivé gagne sur un déclaré en attente.** À la création d'un apport dérivé de participant P, ligne L, type T, tout apport déclaré (`isAutoDetected` faux) de P sur L de type T au statut `pending` passe `rejected` : `reviewedBy` = propriétaire du projet, `reviewedAt` = maintenant, `note` = `Replaced by the ESI/wallet contribution`, suivi de `. Declared note: <note saisie>` si le participant en avait une (E3). Aucune quantité n'est retirée de la ligne (un `pending` n'en a jamais servi).
- **R2 — Un déclaré approuvé reste, les deux comptent en attendant.** Si le déclaré de P sur L de type T est `approved` quand le dérivé arrive, rien n'est modifié : les deux restent approuvés et la notification de R4 prévient les relecteurs.
- **R3 — Un déclaré approuvé qui double un dérivé est rejetable.** Un relecteur (Owner ou Admin accepté, mêmes contrôles que la revue actuelle, dont « pas son propre apport » sauf propriétaire seul relecteur, et projet non terminé) peut rejeter un apport `approved` si et seulement si il n'est pas dérivé et qu'un apport dérivé de même participant, ligne et type existe. Il passe `rejected`, `reviewedBy` / `reviewedAt` mis à jour, et pour un matériau sa quantité est retirée de `fulfilledQuantity` de la ligne (jamais sous 0). Tout autre rejet d'un apport `approved` reste refusé (400) : un apport dérivé, un déclaré sans dérivé jumeau, un déclaré déjà `rejected`. L'approbation d'un apport non `pending` reste refusée (400). La réservation de ligne du participant n'est pas restituée.
- **R4 — Notification des relecteurs.** Quand un apport dérivé est créé et que R1 ou R2 s'applique, une notification in-app par doublon est envoyée à chaque participant `accepted` de rôle Owner ou Admin du projet (`NotificationDispatcher::dispatch`, catégorie `Notification::CATEGORY_INDUSTRY`, niveau `LEVEL_WARNING`, route `/industry/projects/{id}`, données `projectId`, `contributionId` du dérivé, `bomItemId`). Titre et message en anglais, nom du projet (« Untitled project » s'il n'en a pas), type, ligne et participant cités. Pas de push, pas de topic Mercure nouveau : l'action `contribution_synced` existante recharge le fil.
- **R5 — Une seule notification par dérivé.** La notification n'est émise qu'à la création du dérivé (`DerivedContributionService::create`), jamais à une mise à jour de sa quantité ou de sa valeur. Un dérivé supprimé puis recréé notifie de nouveau si un déclaré existe encore.
- **R6 — Signal pour le front.** `ProjectContributionResource` expose `duplicatesDerived` (bool) : vrai pour un apport déclaré `approved` qui double un dérivé (R3). Le front affiche le bouton Reject d'un `approved` seulement quand `duplicatesDerived` est vrai et que l'utilisateur est relecteur, et une pastille « Doublon avec un apport ESI » (clé i18n en/fr) sur cet apport. Sinon la table reste inchangée.
- **R7 — Projet terminé.** Inchangé : `DerivedContributionService::refreshLine` ne fait rien sur un projet `completed`, donc ni R1 ni R4.

## Exemples (vérifiables par test)

P : propriétaire U, participant `accepted` V, Admin A. Ligne L (Tritanium, 1 000 requis), ligne FA (job).

| # | État avant | Événement | Résultat attendu |
|---|---|---|---|
| E1 | U a déclaré 400 Tritanium sur L, `pending` | U lie un achat de 600 sur L | apport dérivé U/L matériau 600 `approved` ; le déclaré passe `rejected` (note R1, `reviewedBy` U) ; `fulfilledQuantity` de L = 600 ; une notification à U et une à A |
| E2 | U a déclaré 400 Tritanium sur L, `approved` (L servie de 400) | U lie un achat de 600 sur L | dérivé créé, déclaré `approved` inchangé, L servie de 1 000 ; notifications à U et A ; `duplicatesDerived` vrai sur le déclaré |
| E3 | Cas E1 avec la note de dépôt « stock du hangar » | idem | note du rejet : `Replaced by the ESI/wallet contribution. Declared note: stock du hangar` |
| E4 | État de E2 | A rejette le déclaré de U | 200, déclaré `rejected`, L servie de 600, `duplicatesDerived` faux |
| E5 | État de E2 | A rejette le dérivé | 400, rien ne change |
| E6 | U a déclaré 400 Tritanium sur L, `approved`, aucun dérivé | A rejette | 400 « Cannot reject a contribution with status "approved" » |
| E7 | V a déclaré une installation de job sur FA, `pending` | V a un job lié sur FA qui crée son dérivé | déclaré de V `rejected` ; notifications à U et A (pas à V) |
| E8 | V a déclaré une installation de job sur FA, `pending` | le dérivé de U (matériau) est créé sur FA | aucun changement : participant ou type différent |
| E9 | Dérivé de U/L existant, déclaré `approved` | la sync change la quantité du dérivé de 600 à 700 | aucune notification, déclaré inchangé |
| E10 | État de E2 | U (propriétaire) rejette son propre déclaré, A existe | 400 « You cannot review your own contribution » |
| E11 | Projet `completed`, déclaré `pending` | un achat est lié | aucun dérivé, aucun changement, aucune notification |

## Cas limites

- Plusieurs déclarés `pending` de P sur L de type T ne peuvent pas coexister (anti-doublon #512, R1 en couvre néanmoins plusieurs sans erreur) : tous passent `rejected`.
- Déclaré `rejected` : jamais touché, et sans notification.
- Participant retiré (`Removed`) : ses apports `pending` sont rejetés par R1 comme les autres, les relecteurs notifiés sont ceux acceptés au moment de la création.
- Aucun relecteur autre que le propriétaire : le propriétaire seul est notifié (il est lui-même Owner).
- `fulfilledQuantity` d'un matériau déclaré rejeté ne descend jamais sous 0 (garde, jamais un `?? 0` sur donnée ESI).

## Accès et fraîcheur

- Rejet R3 : même processeur que la revue (`ReviewContributionProcessor`), `assertAdminOrOwner`, projet de l'apport vérifié, aucun nouvel identifiant reçu. Défaut préexistant repris tel quel : néant.
- Notification R4 : destinataires lus à la création du dérivé depuis `members` du projet (statut et rôle du moment), aucun appel ESI de plus.

## Tests existants contredits ou modifiés

- `ProjectContributionServiceTest` (reject d'un apport non `pending` renvoie 400, ~l.356) et `ReviewContributionProcessorTest` (~l.326) : le cas « approved » sans dérivé jumeau reste 400, seuls les harnais gagnent un dérivé jumeau pour le nouveau cas.
- `DerivedContributionServiceTest` : le constructeur gagne le notifier ; les doublures existantes sont complétées, aucune assertion changée.
- `GroupContributionApiTest` (~l.172) : à relire, le 400 sur un approuvé reste vrai hors doublon.

## Hors périmètre

- Annuler une approbation en général, restituer la réservation de ligne, rejeter un apport dérivé.
- Un doublon créé par une saisie (déjà refusé par #512).
- Tout autre type de notification, le push et l'activité du projet (#321, même module, fichiers distincts : `publishGroupProjectEvent` n'est pas touché).

## Glossaire

- **Apport dérivé** : ajouter « il remplace un apport déclaré en attente du même participant, ligne et type (spec #510) ».
- **Contribution** : « Une fois approuvée, elle ne diminue plus » devient « … sauf un apport déclaré approuvé qui double un apport dérivé, que les relecteurs peuvent rejeter (spec #510) ».
- **Doublon d'apport** (Duplicate contribution) : apport déclaré dont un apport dérivé de même participant, ligne et type existe ; `ProjectContributionResource::$duplicatesDerived`.
