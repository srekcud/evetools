# Spec — Un job lié consomme la réservation de son installateur (#1202)

## Contexte

Le joueur prend une étape de job avec « Je m'en charge » (`IndustryProjectLineReservation`), lance le job, puis le livre. Le job est lié à la ligne (matcher ou à la main) et `DerivedContributionService::refreshLine` crée l'apport dérivé `JobInstall` de l'installateur. Seule l'approbation d'une contribution (`ProjectContributionService`) appelle `LineReservationService::settleApproval` : la réservation reste. Le compagnon garde l'étape dans « Mes lignes à livrer » (`companionTasks.ts`, calculé depuis `reservations`), l'arbre garde la pastille, et la modale propose une contribution manuelle (quantité par défaut = réservation moins les apports en attente) qui double l'apport dérivé.

Backend seulement : le front lit déjà `reservations` de l'API, `contribution_synced` recharge la BOM (`groupProjectRefreshes.ts`) et la modale suit la réservation restante.

## Règles

- **R1.** Quand `DerivedContributionService::synchronize` crée un apport `JobInstall`, ou augmente sa quantité (runs liés de l'installateur), la réservation de cet installateur sur la ligne est réduite de la quantité de ligne que couvrent les runs ajoutés (différence entre `getQuantityOfRuns(nouveau total)` et `getQuantityOfRuns(ancien total)`, pour que l'arrondi ne laisse rien sur une ligne entièrement installée) ; elle est supprimée si le restant est inférieur à 1. Même règle de réduction que `LineReservationService::settleApproval` (réutilisée).
- **R2.** Quantité couverte par `r` runs : `IndustryProjectBomLine::getQuantityOfRuns(r)`. Ligne de fabrication ou de réaction (`isManufacturingOrReactionJob`) : `intdiv(requiredQuantity × r, runs de la ligne)` (la quantité requise est runs × sortie par run). Autre ligne de job : `r`. Ligne de fabrication ou de réaction sans runs (`null` ou 0) : `\LogicException`, jamais une valeur par défaut ; `DerivedContributionService` ne consomme rien sur une ligne couverte par le stock (`isCoveredByStock`, runs à 0).
- **R3.** L'installateur est celui de l'apport dérivé (`participantInstalling` : participant du propriétaire du personnage, propriétaire du projet si inconnu). Les réservations des autres participants ne bougent pas.
- **R4.** Idempotence : un `refreshLine` sans changement de runs ne réduit rien (la réduction suit la différence de quantité de l'apport, pas le total).
- **R5.** Une baisse de runs (lien retiré, job sans production) ou la suppression de l'apport ne rétablit jamais la réservation.
- **R6.** Sans réservation de l'installateur sur la ligne : rien à faire, aucune erreur. Projet `completed` : inchangé (`refreshLine` sort avant).
- **R7.** La réduction est écrite avec le flush et l'événement `contribution_synced` déjà émis par `refreshLine` (pas de nouvel événement, pas de nouveau topic).
- **R8.** Aucun apport manuel n'est créé ni supprimé ; `fulfilledQuantity` d'une ligne de job reste inchangé par un job lié (glossaire « Job lié », spec #362 D13).
- **R9.** `DerivedContributionService` reçoit `LineReservationService` en dépendance du constructeur.

## Exemples

| # | Ligne | Réservation | Job lié (installateur) | Résultat |
|---|---|---|---|---|
| E1 | fabrication, 10 runs, sortie 1 (requis 10) | Sana 10 | 10 runs (Sana) | réservation supprimée |
| E2 | idem | Sana 10 | 4 runs (Sana) | Sana 6 ; un second `refreshLine` : Sana 6 ; puis un job de 6 runs : supprimée |
| E3 | fabrication, 10 runs, sortie 5 (requis 50) | Sana 50 | 4 runs (Sana) | Sana 30 (4 × 5 = 20 couverts) |
| E4 | fabrication, 10 runs | Sana 10, propriétaire 5 | 10 runs (Sana) | Sana supprimée, propriétaire 5 |
| E5 | fabrication, 10 runs | Sana 10 | job de Sana lié à la main par le propriétaire (10 runs) | Sana supprimée (installateur = Sana) |
| E6 | fabrication, 10 runs | Sana 10 | 10 runs, puis lien retiré | Sana reste supprimée, rien n'est rétabli |
| E7 | réaction, 5 runs, sortie 200 (requis 1000) | Sana 600 | 5 runs (Sana) | Sana supprimée (1000 couverts ≥ 600) |
| E8 | fabrication, 10 runs | aucune | 10 runs | rien, pas d'exception |

## Cas limites

- Réservation plus petite que la quantité couverte : supprimée (restant < 1), jamais négative.
- Plusieurs jobs d'un installateur liés dans la même passe : un seul apport `JobInstall`, la différence totale est consommée une fois.
- `getQuantityOfRuns` sur une ligne de matériau : pas appelée (aucun lien de job).
- Réservations antérieures au correctif : non traitées (aucune migration, la différence est nulle tant que les runs ne bougent pas) ; le joueur les libère ou les livre. Noté en « Décisions ».

## Hors périmètre

- Réservation d'une ligne dont le job est déjà lié (#1162 : lignes libres et règle du à-faire).
- Quantité par défaut de la modale pour une ligne libre (sans réservation).
- Libellés, écrans, nouvelle clé i18n ; endpoints ; Mercure (inchangé).
