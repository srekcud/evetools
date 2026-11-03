# Spec #262 — Opération de minage : créer, rejoindre par lien court ou par corporation

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-03, à valider par Sylvain avant `/tdd-red`.
- **État du code de référence** : `main` @ `f5416084`.
- **Maquette de référence** : `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`), étapes 4 « Création », 5 « Invitation », 6 « Rejoindre (choix des persos) » et 27 « Chacun pour soi · création » (modales `create`, `created`, `join`, vue `renderPlanned()`).
- **Dépend de** : #261 (glossaire Minage, PR #326). Termes utilisés tels quels, glossaire non modifié ici.

## 1. Contexte

Une session de minage réunit les mains et les alts de plusieurs joueurs. Aujourd'hui, rien dans evetools ne les rassemble : chacun voit son propre ledger de minage (`MiningEntry`, onglet Revenus › Minage), et l'organisateur tient un tableur des personnages. Ce lot pose le socle des issues suivantes (instantanés #263, parts #264, fleet storage #266, sites #267) : une **opération de minage** qu'un pilote crée, que d'autres rejoignent avec les personnages déjà rattachés à leur compte, sans rien saisir.

Le modèle de groupe suit Group Industry (rôles, statuts de membership, lien court, acceptation automatique dans la corporation), avec des entités dédiées (décision A de l'issue). Group Industry n'est pas modifié : aucun fichier `Industry*` ou `GroupIndustry*` n'est touché, aucun enum ni service n'est partagé.

Constat dans le code (lu le 2026-11-03) :

| Élément Group Industry | Ce qu'on reprend | Ce qui change pour le minage |
|---|---|---|
| `GroupIndustryProject::$shortLinkCode`, `generateShortLinkCode()` (10 caractères hex, `random_bytes(5)`) | même format de code, colonne unique | le code se **renouvelle** (`renewJoinCode()`), l'ancien cesse de fonctionner |
| `JoinGroupProjectProcessor` : rejoint au montage de la page, accepté si même corporation que le propriétaire, `requestRejoin()` pour un membre retiré | règle d'acceptation, retour en `pending` d'un membre retiré | un **aperçu** (GET) précède l'adhésion, qui porte la liste des personnages cochés |
| `AvailableGroupProjectsProvider` : projets de la corporation du propriétaire (lue en direct), hors projets dont l'utilisateur a une membership | même exclusion | seules les opérations de visibilité « ma corporation » ; la corporation est figée sur l'opération à la création |
| `GroupProjectAccessChecker` : lecture / participation / administration (spec #162) | les trois niveaux | une opération privée n'est jamais lisible par la corporation sans membership |
| Topic `/group-project/{id}/events` autorisé par `TokenProvider` | — | topic utilisateur `/user/{id}/mining-operations`, sans changement de `TokenProvider` |

## 2. Modèle

### Entités (une seule migration Doctrine)

| Entité / table | Champs | Contraintes |
|---|---|---|
| `MiningOperation` / `mining_operations` | `id` UUID ; `owner` → `users` ; `label` varchar(100) ; `plannedStartAt` datetime_immutable (UTC) ; `status` varchar(20) `MiningOperationStatus` ; `visibility` varchar(20) `MiningOperationVisibility` ; `splitMode` varchar(30) `MiningSplitMode` ; `corporationId` int nullable ; `joinCode` varchar(10) ; `createdAt` | `owner_id` `ON DELETE CASCADE` ; `UNIQUE(join_code)` ; index `owner_id` ; index `(visibility, corporation_id, status)` |
| `MiningOperationMember` / `mining_operation_members` | `id` UUID ; `operation` ; `user` → `users` (non nul) ; `role` varchar(10) `MiningOperationMemberRole` ; `status` varchar(10) `MiningOperationMemberStatus` ; `joinedAt` | `operation_id` et `user_id` `ON DELETE CASCADE` ; `UNIQUE(operation_id, user_id)` ; index `user_id` |
| `MiningOperationCharacter` / `mining_operation_characters` | `id` UUID ; `member` ; `character` → `characters` ; `enrolledAt` | `member_id` et `character_id` `ON DELETE CASCADE` ; `UNIQUE(member_id, character_id)` ; index `character_id` |

`MiningOperation` porte ses membres (`OneToMany`, `cascade: persist, remove`, `orphanRemoval`), un membre porte ses personnages de la même façon.

### Enums (`src/Enum/`)

| Enum | Cas (valeur) | Méthodes |
|---|---|---|
| `MiningOperationStatus` | `Planned` (`planned`), `InProgress` (`in_progress`), `Closing` (`closing`), `Completed` (`completed`) | `isOpen()` : vrai sauf `Completed` ; `acceptsParticipants()` : vrai pour `Planned` et `InProgress` |
| `MiningOperationVisibility` | `Private` (`private`), `Corporation` (`corporation`) | — |
| `MiningSplitMode` | `ValueProrata` (`value_prorata`), `EachForThemselves` (`each_for_themselves`) | — |
| `MiningOperationMemberRole` | `Owner` (`owner`), `Admin` (`admin`), `Member` (`member`) | — |
| `MiningOperationMemberStatus` | `Pending` (`pending`), `Accepted` (`accepted`), `Removed` (`removed`) | — |
| `MiningCharacterEligibility` | `Eligible` (`eligible`), `ReconnectRequired` (`reconnect_required`), `InAnotherOperation` (`in_another_operation`) | — |

Seul `Planned` est atteint par ce lot. `InProgress`, `Closing` et `Completed` existent pour les règles d'ouverture et les tests (posés directement sur l'entité dans les fixtures) ; leurs transitions viennent avec #263 et #265. Les étapes propres au prorata (« Parts », « Réglée ») s'ajouteront comme nouveaux cas de chaîne, sans migration de données.

## 3. Règles métier

### Création

- **R1 — Créer une opération.** `POST /api/mining-operations` avec `{label, plannedStartAt, visibility, splitMode, characterIds}` crée une opération au statut `planned`, dont l'utilisateur est le propriétaire. Il en devient membre `owner` / `accepted`, inscrit avec les personnages cochés. Réponse `201`, la ressource de l'opération.
- **R2 — Validation de l'entrée** (`422`, violation sur le champ) :
  - `label` : obligatoire, rogné des espaces de bord, 1 à 100 caractères après rognage ;
  - `plannedStartAt` : date ISO 8601 avec fuseau, obligatoire. Stockée et renvoyée en UTC (« EVE time ») ; aucune borne de date dans ce lot ;
  - `visibility` : `private` ou `corporation` ;
  - `splitMode` : `value_prorata` ou `each_for_themselves`. `weighted_time` (#270) est refusé ;
  - `characterIds` : au moins 1 identifiant, UUID, sans doublon.
- **R3 — Corporation de l'opération.** `corporationId` reçoit la corporation du personnage principal du propriétaire au moment de la création (`User::getCorporationId()`), quelle que soit la visibilité. Elle ne bouge plus ensuite. Visibilité `corporation` avec une corporation inconnue (pas de personnage principal) : `400` « Corporation visibility needs a known corporation ».
- **R4 — Mode de répartition stocké, pas calculé.** `splitMode` est enregistré et renvoyé. Aucun calcul de part, aucun champ de part du booster ni de part corporation dans ce lot.
- **R5 — Code d'invitation.** À la création, l'opération reçoit un `joinCode` de 10 caractères `[0-9a-f]`. Le lien d'invitation est `{origin}/mining-operations/join/{joinCode}`. Le code n'expire pas avec le temps.

### Personnages inscrits

- **R6 — Personnages cochés.** À la création comme à l'adhésion, chaque identifiant de `characterIds` est contrôlé dans l'ordre de la liste. Le premier refus arrête tout, rien n'est enregistré :
  1. le personnage appartient au compte de l'utilisateur (`Character::getUser() === $user`), sinon `400` « Character {id} is not one of your characters » ;
  2. il peut lire son ledger de minage : token présent, `Character::isAuthValid()`, scope `esi-industry.read_character_mining.v1` dans `EveToken::getScopes()`. Sinon `400` « {name} must be reconnected before joining » ;
  3. il n'est inscrit dans aucune autre **opération ouverte** (R7), sinon `409` « {name} is already in the open operation "{label}" ».
- **R7 — Une seule opération ouverte par personnage.** Un personnage est inscrit dans une opération quand une ligne `MiningOperationCharacter` le relie à un membre `pending` ou `accepted` d'une opération dont le statut est ouvert (`planned`, `in_progress`, `closing`). Un membre retiré n'a plus de ligne (R14). Une opération `completed` libère ses personnages.
- **R8 — Éligibilité affichée.** Pour l'aperçu (R10), chaque personnage du compte reçoit `eligible`, `reconnect_required` (contrôle 2 de R6) ou `in_another_operation` (contrôle 3, avec le libellé de l'opération qui le retient). `reconnect_required` l'emporte sur `in_another_operation`. Un personnage déjà inscrit dans l'opération consultée est `in_another_operation` avec le libellé de celle-ci.

### Rejoindre

- **R9 — Statut de la nouvelle membership.** Un utilisateur dont la corporation est connue et égale à `MiningOperation::$corporationId` est `accepted`. Sinon il est `pending`. Rôle `member`. La règle vaut pour les deux visibilités : un membre de la corporation qui reçoit le lien d'une opération privée est accepté directement.
- **R10 — Aperçu par le code.** `GET /api/mining-operations/join/{code}` renvoie l'opération (libellé, propriétaire, début, statut, visibilité, mode de répartition, nombre de participants), `myRole` / `myStatus` de l'utilisateur (`null` sans membership, `removed` pour un membre retiré), `autoAccepted` (R9, faux pour un membre retiré) et ses personnages avec leur éligibilité (R8), personnage principal en premier puis par nom. Tout utilisateur authentifié qui a le code lit l'aperçu, quelle que soit la visibilité ou le statut. Code inconnu ou remplacé : `404` « Mining operation not found ».
- **R11 — Rejoindre par le code.** `POST /api/mining-operations/join/{code}` avec `{characterIds}` crée la membership (R9) et inscrit les personnages (R6). Réponse `200`, l'aperçu à jour (`myStatus` `accepted` ou `pending`). Ordre des refus : `401` sans utilisateur, `422` entrée invalide (R2, `characterIds`), `404` code inconnu, `400` « This operation is no longer accepting participants » si le statut n'accepte plus de participants (`closing`, `completed`), `409` « You are already a member of this operation » pour une membership `pending` ou `accepted` (propriétaire compris), puis les refus de R6.
- **R12 — Retour d'un membre retiré.** Un membre `removed` qui rejoint repasse en `pending`, rôle `member`, `joinedAt` remis à l'heure courante, avec les personnages qu'il coche. Il ne revient jamais directement en `accepted`, même dans la corporation de l'opération.
- **R13 — Opérations disponibles.** `GET /api/mining-operations/available` liste les opérations de visibilité `corporation`, de `corporationId` égal à la corporation de l'utilisateur, dont le statut accepte des participants (R11), dont il n'est pas propriétaire et où il n'a aucune membership, quel qu'en soit le statut. Tri par `plannedStartAt` croissant. Utilisateur sans corporation connue : liste vide. Chaque élément porte son `joinCode` : la page d'adhésion est la même que pour le lien.

### Membres

- **R14 — Retirer un membre.** Le retrait (refus d'une demande, exclusion, départ) passe la membership en `removed`, rôle `member`, et supprime ses lignes `MiningOperationCharacter` : ses personnages sont aussitôt libres (R7). La ligne de membership reste, pour R12 et R13.
- **R15 — Gérer les membres.** Seuls le propriétaire et un admin `accepted` gèrent les membres :
  - `PATCH …/members/{id}` `{status: "accepted"}` accepte une demande `pending` ; sur un autre statut : `400` « Only pending members can be accepted » ;
  - `PATCH …/members/{id}` `{role: "admin" | "member"}` : seul le propriétaire nomme un admin (`403` « Only the operation owner can promote members to admin ») ;
  - `DELETE …/members/{id}` retire le membre (R14) ; membre déjà retiré : `400` « Member already removed » ;
  - le propriétaire ne se modifie pas et ne se retire pas : `400` « Cannot modify the operation owner » / « Cannot remove the operation owner » ;
  - un admin ne gère pas un autre admin : `403` « Only the operation owner can manage an admin » ;
  - membre d'une autre opération ou inconnu : `404` « Member not found ».
- **R16 — Quitter.** `POST /api/mining-operations/{id}/leave` retire la membership de l'utilisateur (R14), réponse `204`. Le propriétaire ne quitte pas (`400` « The owner cannot leave the operation »). Un membre `accepted` ne quitte qu'une opération `planned` (`400` « Only a planned operation can be left ») ; une demande `pending` se retire tant que l'opération est ouverte. Sans membership `pending` ou `accepted` : `404` « Membership not found ».

### Accès, code, suppression

- **R17 — Trois niveaux d'accès** (classe `MiningOperationAccess`, même découpage que la spec #162) :
  - **Lecture** (`GET /mining-operations/{id}`) : le propriétaire, un membre `accepted`, ou, pour une opération `corporation` seulement, un utilisateur dont la corporation est connue et égale à `corporationId`, quel que soit le statut de sa membership. Sinon `403` « You do not have access to this operation » ;
  - **Participation** (`GET …/members`) : le propriétaire ou un membre `accepted`, sinon `403` « You do not have access to this operation » ;
  - **Administration** (R15, R18) : le propriétaire ou un admin `accepted`, sinon `403` « Only the operation owner or an admin can perform this action ».
  Ordre : `401`, `404` (identifiant non UUID compris, via `UuidOrNotFound`), `403`, puis les refus métier.
- **R18 — Renouveler le code.** `POST /api/mining-operations/{id}/join-code` (corps vide) remplace `joinCode` par un nouveau code (R5), différent de l'ancien. Administration (R17). Statut qui n'accepte plus de participants : `400` « This operation is no longer accepting participants ». Réponse `200`, l'opération. L'ancien code répond `404` (R10).
- **R19 — Code visible.** `joinCode` est renseigné pour le propriétaire et les membres `accepted`, et dans la liste des opérations disponibles (R13). Il vaut `null` pour tout autre lecteur (corporation sans membership, membre `pending`).
- **R20 — Supprimer une opération.** `DELETE /api/mining-operations/{id}` : propriétaire seulement (`403` « Only the operation owner can delete it »), statut `planned` seulement (`400` « Only a planned operation can be deleted »). Membres et personnages inscrits disparaissent avec elle. Réponse `204`.
- **R21 — Mes opérations.** `GET /api/mining-operations` liste les opérations dont l'utilisateur est propriétaire ou membre `pending` / `accepted`, tous statuts, triées par `plannedStartAt` décroissant. Un membre retiré ne les y voit plus.

### Ressources renvoyées

- **R22 — `MiningOperation`** : `id`, `label`, `plannedStartAt` (`2026-11-05T20:00:00+00:00`), `status`, `visibility`, `splitMode`, `corporationId` (`?int`), `ownerCharacterName` (`?string`, `null` si le propriétaire n'a pas de personnage principal), `participantsCount` (membres `accepted`, propriétaire compris), `charactersCount` (personnages de ces membres), `joinCode` (`?string`, R19), `myRole`, `myStatus` (`null` sans membership), `createdAt`.
- **R23 — `MiningOperationMember`** : `id`, `characterName` (personnage principal, `?string`), `corporationId` (`?int`), `role`, `status`, `joinedAt`, `characters` (`[{id, eveCharacterId, name}]`, triés par nom). La liste (R17, participation) contient les membres `accepted` et `pending` ; propriétaire en premier, puis par `joinedAt`.
- **R24 — `MiningOperationInvitation`** (R10) : `code`, `operationId`, `label`, `ownerCharacterName`, `plannedStartAt`, `status`, `visibility`, `splitMode`, `participantsCount`, `autoAccepted`, `myRole`, `myStatus`, `characters` (`[{id, eveCharacterId, name, isMain, eligibility, busyOperationLabel}]`, `busyOperationLabel` renseigné seulement pour `in_another_operation`).

### Temps réel

- **R25 — Topic.** `MercurePublisherService::getTopicsForUser()` ajoute `/user/{userId}/mining-operations` (16 topics au lieu de 15). Pas de changement de `TokenProvider` ni de `getGroupTopics()`.
- **R26 — Événements publiés.** Après le `flush`, `MercurePublisherService::publishMiningOperationEvent(list<string> $userIds, string $operationId, string $action, array $data)` publie un message privé `{action, operationId, data, timestamp}` sur le topic de chaque utilisateur concerné : le propriétaire, les membres `pending` et `accepted` (état après l'action) et le membre visé par l'action, sans doublon. Actions : `member_joined` (`{memberId, status}`), `member_updated` (`{memberId, role, status}`), `member_removed` (`{memberId}`, retrait et départ), `join_code_renewed` (`{}`), `operation_deleted` (`{}`). Un échec de publication est journalisé en `warning` et ne fait pas échouer la requête (comme `publishGroupProjectEvent()`). La création ne publie rien. La liste des opérations disponibles ne se met pas à jour en direct.
- **R27 — Le store range l'événement par opération.** Dans `stores/sync.ts`, un message qui porte `action` et `operationId` (sans `syncType` ni `projectId`) est rangé comme dernier événement de cette opération, compté à chaque réception (même règle que #148 R4). La vue détail recharge l'opération et les membres sur `member_*` et `join_code_renewed` de l'opération affichée, et revient à la liste avec un message sur `operation_deleted`. La liste « Mes opérations » se recharge sur tout événement.

## 4. Endpoints API Platform

Toutes les opérations : `security: "is_granted('ROLE_USER')"`, `paginationEnabled: false`, préfixe `/api`. `{id}` porte l'exigence UUID de Group Industry (`{id<[0-9a-f]{8}-…-[0-9a-f]{12}>}`) pour ne pas capturer `available` ni `join`.

| Ressource | Opération | `uriTemplate` | `uriVariables` | Entrée | Provider / Processor | Réponse |
|---|---|---|---|---|---|---|
| `MiningOperationResource` | `GetCollection` | `/mining-operations` | — | — | `MyMiningOperationsProvider` | 200 (R21) |
| | `GetCollection` | `/mining-operations/available` | — | — | `AvailableMiningOperationsProvider` | 200 (R13) |
| | `Get` | `/mining-operations/{id}` | `id` → `Link(fromClass: self::class)` | — | `MiningOperationProvider` | 200 (R17) |
| | `Post` | `/mining-operations` | — | `CreateMiningOperationInput` | `CreateMiningOperationProcessor` | 201 (R1) |
| | `Delete` | `/mining-operations/{id}` | `id` → `Link(fromClass: self::class)` | — | `MiningOperationDeleteProvider` / `DeleteMiningOperationProcessor` | 204 (R20) |
| | `Post` | `/mining-operations/{id}/join-code` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `RenewMiningOperationJoinCodeProcessor` | 200 (R18) |
| | `Post` | `/mining-operations/{id}/leave` | `id` → `Link(fromClass: self::class)` | `EmptyInput` | `LeaveMiningOperationProcessor`, `output: false`, `status: 204` | 204 (R16) |
| `MiningOperationInvitationResource` | `Get` | `/mining-operations/join/{code}` | `code` → `Link(fromClass: self::class, identifiers: ['code'])` | — | `MiningOperationInvitationProvider` | 200 (R10) |
| | `Post` | `/mining-operations/join/{code}` | idem | `JoinMiningOperationInput` | `JoinMiningOperationProcessor` | 200 (R11) |
| `MiningOperationMemberResource` | `GetCollection` | `/mining-operations/{operationId}/members` | `operationId` → `Link(fromClass: MiningOperationResource::class)` | — | `MiningOperationMemberCollectionProvider` | 200 (R23) |
| | `Patch` | `/mining-operations/{operationId}/members/{id}` | `operationId` → `Link(fromClass: MiningOperationResource::class)`, `id` → `Link(fromClass: self::class)` | `UpdateMiningOperationMemberInput`, `application/merge-patch+json` | `MiningOperationMemberItemProvider` / `UpdateMiningOperationMemberProcessor` | 200 (R15) |
| | `Delete` | `/mining-operations/{operationId}/members/{id}` | idem | — | `MiningOperationMemberItemProvider` / `RemoveMiningOperationMemberProcessor` | 204 (R14, R15) |

Fichiers : `src/ApiResource/MiningOperation/`, `src/ApiResource/Input/MiningOperation/` (`CreateMiningOperationInput`, `JoinMiningOperationInput`, `UpdateMiningOperationMemberInput` avec `Assert\Choice` `admin|member` et `accepted`), `src/State/Provider/MiningOperation/`, `src/State/Processor/MiningOperation/`, `MiningOperationResourceMapper` (provider) pour les trois ressources. Les règles R6 à R9, R12, R14 et R17 vivent dans `src/Service/MiningOperation/` (`MiningOperationEnrolment`, `MiningOperationAccess`) et dans les méthodes des entités, pas dans les processors.

## 5. Écrans (front)

Direction visuelle : HUD EVE de la maquette v4 (cartes `slate-900`, Share Tech Mono pour les heures et le code, badges de statut). Tous les noms (opération, personnages, corporation) tiennent à 60 caractères : troncature avec infobulle, jamais de débordement.

| Route | Nom | Vue (`<MainLayout>`) | Contenu |
|---|---|---|---|
| `/mining-operations` | `mining-operations` | `views/MiningOperation/MiningOperationList.vue` | onglets « Mes opérations » / « Disponibles » (`?tab=available`). Carte par opération : libellé, badge de statut, badge de visibilité, début EVE en mono, `participantsCount` inscrits, bouton « Ouvrir » (détail) ou « Rejoindre » (page d'adhésion par `joinCode`). Bouton « Nouvelle opération ». États vides, et « corporation inconnue » pour Disponibles |
| — | — | modale de création (dans la liste) | maquette `create` réduite à : Libellé, Début (EVE time), Visibilité (radio « Privée (lien) » / « Ma corporation », désactivée sans corporation), Répartition (radio « Prorata de la valeur » / « Temps pondéré » désactivé « bientôt » / « Chacun pour soi », avec l'aide de la maquette), Vos personnages (même sélecteur que l'adhésion). Puis modale `created` : bandeau « planifiée », carte « Lien d'invitation » (copier), bouton « Ouvrir l'opération » |
| `/mining-operations/:id` | `mining-operation-detail` | `views/MiningOperation/MiningOperationDetail.vue` | maquette `renderPlanned()` réduite : en-tête (libellé, statut, visibilité), carte « Lien d'invitation » (copier ; « Renouveler » pour propriétaire et admin, avec confirmation), carte « Inscrits » (participants avec badge Owner/Admin, personnages, demande en attente avec « Accepter » / « Refuser » pour propriétaire et admin, « Retirer »), carte « Démarrage » (heure EVE), carte « Règles » (répartition seulement). Actions « Quitter » et « Supprimer » selon R16 et R20. Un lecteur sans participation voit l'en-tête et les règles, sans liste d'inscrits |
| `/mining-operations/join/:code` | `mining-operation-join` | `views/MiningOperation/MiningOperationJoin.vue` | maquette `join` : titre « Rejoindre « {label} » », sous-titre propriétaire et début, liste « Quels personnages minent ? » (case, initiales, nom, « main », badge `prêt` / `à reconnecter` + lien vers Personnages / `déjà dans « … »`, case désactivée hors `eligible`), aide « un seul personnage par opération ouverte », carte « Règles de l'opération » et ligne d'acceptation (`autoAccepted`), bouton « Rejoindre avec N personnage(s) » désactivé à 0. États : déjà membre (lien vers le détail), demande en attente, opération fermée, lien invalide. Après `accepted` : redirection vers le détail |

- Lien : fonction pure `miningOperationJoinUrl(origin, code)` → `{origin}/mining-operations/join/{code}` (motif `groupJoinUrl`, spec #150), seule construction du lien.
- Store Pinia `stores/miningOperation.ts` : `fetchMine`, `fetchAvailable`, `fetchDetail`, `fetchMembers`, `create`, `fetchInvitation`, `join`, `renewJoinCode`, `leave`, `deleteOperation`, `updateMember`, `removeMember`.
- Navigation (`constants/sidebarNavigation.ts`) : groupe repliable `mining` (`nav.mining`) placé après Production, avec l'entrée `mining-operations` (`nav.miningOperations`, route `/mining-operations`). `useNavigationEntries.ts` ajoute l'onglet `available` (`miningOperation.tabs.available`).

### Clés i18n (fr / en)

| Clé | fr | en |
|---|---|---|
| `nav.mining` | Minage | Mining |
| `nav.miningOperations` | Opérations de minage | Mining operations |
| `miningOperation.title` | Opérations de minage | Mining operations |
| `miningOperation.tabs.mine` / `.available` | Mes opérations / Disponibles | My operations / Available |
| `miningOperation.list.new` | Nouvelle opération | New operation |
| `miningOperation.list.emptyMine` | Aucune opération. Créez-en une ou rejoignez-en une par son lien. | No operation yet. Create one or join one from its link. |
| `miningOperation.list.emptyAvailable` | Aucune opération ouverte dans votre corporation. | No open operation in your corporation. |
| `miningOperation.list.noCorporation` | Corporation inconnue : seules les opérations reçues par lien sont accessibles. | Unknown corporation: only operations shared by link are reachable. |
| `miningOperation.list.participants` | {count} inscrits | {count} participants |
| `miningOperation.list.open` / `.join` | Ouvrir / Rejoindre | Open / Join |
| `miningOperation.status.planned` / `.in_progress` / `.closing` / `.completed` | Planifiée / En cours / Clôture / Terminée | Planned / In progress / Closing / Completed |
| `miningOperation.visibility.private` / `.corporation` | Privée (lien) / Ma corporation | Private (link) / My corporation |
| `miningOperation.splitMode.value_prorata` / `.weighted_time` / `.each_for_themselves` | Prorata de la valeur / Temps pondéré / Chacun pour soi | Value prorata / Weighted time / Each for themselves |
| `miningOperation.splitMode.soon` | bientôt | soon |
| `miningOperation.splitMode.hint.value_prorata` | Les parts seront calculées au prorata de la valeur minée par chacun. | Shares will follow the value each participant mined. |
| `miningOperation.splitMode.hint.each_for_themselves` | Chacun garde son minerai : pas de part, pas de paiement. | Everyone keeps their own ore: no share, no payment. |
| `miningOperation.create.title` | Nouvelle opération de minage | New mining operation |
| `miningOperation.create.label` / `.start` / `.visibility` / `.splitMode` / `.characters` | Libellé / Début (EVE time) / Visibilité / Répartition / Vos personnages | Label / Start (EVE time) / Visibility / Split / Your characters |
| `miningOperation.create.corporationUnavailable` | Corporation inconnue : visibilité privée seulement. | Unknown corporation: private visibility only. |
| `miningOperation.create.submit` | Créer l'opération | Create operation |
| `miningOperation.created.title` / `.planned` / `.open` | Opération créée / L'opération est planifiée. / Ouvrir l'opération | Operation created / The operation is planned. / Open operation |
| `miningOperation.invite.title` | Lien d'invitation | Invitation link |
| `miningOperation.invite.copied` | Copié ! | Copied! |
| `miningOperation.invite.hint` | Les membres de votre corporation rejoignent directement. Les autres attendent votre validation. | Members of your corporation join right away. Others wait for your approval. |
| `miningOperation.invite.renew` / `.renewConfirm` | Renouveler le lien / L'ancien lien ne fonctionnera plus. | Renew link / The previous link will stop working. |
| `miningOperation.join.title` | Rejoindre « {label} » | Join “{label}” |
| `miningOperation.join.subtitle` | Invitation de {owner} · {start} EVE | Invitation from {owner} · {start} EVE |
| `miningOperation.join.whichCharacters` | Quels personnages minent ? | Which characters are mining? |
| `miningOperation.join.eligibility.eligible` / `.reconnect_required` / `.in_another_operation` | prêt / à reconnecter / déjà dans « {label} » | ready / reconnect needed / already in “{label}” |
| `miningOperation.join.reconnect` | Reconnecter | Reconnect |
| `miningOperation.join.oneOpenOperation` | Un personnage ne peut être que dans une seule opération ouverte. Sans token valide, sa quantité serait inconnue : reconnectez-le avant de le cocher. | A character can only be in one open operation. Without a valid token its quantity would be unknown: reconnect it before ticking it. |
| `miningOperation.join.rules` | Règles de l'opération | Operation rules |
| `miningOperation.join.autoAccepted` / `.needsApproval` | Même corporation : demande acceptée automatiquement. / Le propriétaire validera votre demande. | Same corporation: request accepted automatically. / The owner will review your request. |
| `miningOperation.join.submit` | Rejoindre avec {count} personnage \| Rejoindre avec {count} personnages | Join with {count} character \| Join with {count} characters |
| `miningOperation.join.pendingTitle` / `.pendingDescription` | Demande envoyée / Le propriétaire ou un admin doit l'accepter. | Request sent / The owner or an admin must accept it. |
| `miningOperation.join.alreadyMember` | Vous participez déjà à cette opération. | You already take part in this operation. |
| `miningOperation.join.closed` | Cette opération n'accepte plus de participants. | This operation no longer accepts participants. |
| `miningOperation.join.invalidLink` | Lien invalide ou renouvelé. | Invalid or renewed link. |
| `miningOperation.detail.participants` / `.summary` | Inscrits / {participants} participants · {characters} personnages | Participants / {participants} participants · {characters} characters |
| `miningOperation.detail.start` / `.rules` / `.split` | Démarrage / Règles / Répartition | Start / Rules / Split |
| `miningOperation.detail.pending` / `.you` | demande en attente / vous | pending request / you |
| `miningOperation.detail.accept` / `.refuse` / `.remove` / `.promote` / `.demote` | Accepter / Refuser / Retirer / Nommer admin / Retirer admin | Accept / Refuse / Remove / Make admin / Remove admin |
| `miningOperation.detail.leave` / `.delete` / `.deleteConfirm` | Quitter l'opération / Supprimer l'opération / Les inscrits et leurs personnages seront retirés. | Leave operation / Delete operation / Participants and their characters will be removed. |
| `miningOperation.detail.deleted` | Cette opération a été supprimée. | This operation was deleted. |
| `miningOperation.role.owner` / `.admin` / `.member` | Owner / Admin / Membre | Owner / Admin / Member |
| `miningOperation.unknownPilot` | Pilote inconnu | Unknown pilot |

## 6. Exemples chiffrés (tests rouges)

Données communes : corporation C1 = `98_262_101`, C2 = `98_262_102`. Aria Solberg (C1, personnages `Aria Solberg` main et `Aria II`), Brann Holt (C1 : `Brann Holt` main, `Brann II`, `Brann III` token invalide, `Brann IV` inscrit dans l'opération ouverte « Ice belt — dimanche » de Cyra), Cyra Lune (C2 : `Cyra Lune` main), Dorn Achar (aucun personnage principal). Opération O = « Anomalie Spodumain — 20:00 », Aria propriétaire, `plannedStartAt` `2026-11-05T20:00:00+00:00`, `corporation`, `value_prorata`, code `7f3a9c2e1b`, statut `planned`, sauf mention contraire. Tout personnage cité est `eligible` sauf mention contraire.

### Domaine (entités et enums, sans base)

| # | Entrée | Sortie attendue |
|---|---|---|
| D1 | `new MiningOperation()` | statut `planned`, `joinCode` conforme à `^[0-9a-f]{10}$` |
| D2 | `renewJoinCode()` sur un code `7f3a9c2e1b` | nouveau code conforme à D1, différent de `7f3a9c2e1b` |
| D3 | `MiningOperationStatus::isOpen()` | `planned` vrai, `in_progress` vrai, `closing` vrai, `completed` faux |
| D4 | `MiningOperationStatus::acceptsParticipants()` | `planned` vrai, `in_progress` vrai, `closing` faux, `completed` faux |
| D5 | membre `accepted` / `admin` avec 2 personnages, retiré | `removed`, `member`, 0 personnage |
| D6 | membre `removed`, `requestRejoin([Brann Holt])` | `pending`, `member`, 1 personnage, `joinedAt` = maintenant |

### Création (`POST /api/mining-operations`)

| # | Entrée | Sortie attendue |
|---|---|---|
| E1 | Aria, `{label: "Anomalie Spodumain — 20:00", plannedStartAt: "2026-11-05T20:00:00+00:00", visibility: "corporation", splitMode: "value_prorata", characterIds: [Aria Solberg, Aria II]}` | `201` ; `status` `planned`, `corporationId` `98262101`, `joinCode` 10 hex, `myRole` `owner`, `myStatus` `accepted`, `participantsCount` 1, `charactersCount` 2, `ownerCharacterName` « Aria Solberg » |
| E2 | Aria, idem avec `visibility: "private"`, `splitMode: "each_for_themselves"`, `[Aria II]` | `201` ; `private`, `each_for_themselves`, `corporationId` `98262101`, `charactersCount` 1 |
| E3 | Aria, `plannedStartAt: "2026-11-05T21:00:00+01:00"` | `201` ; `plannedStartAt` `2026-11-05T20:00:00+00:00` |
| E4 | Aria, `label: "   "` | `422`, violation `label` ; aucune opération |
| E5 | Aria, `label` de 101 caractères | `422`, violation `label` |
| E6 | Aria, `splitMode: "weighted_time"` | `422`, violation `splitMode` |
| E7 | Aria, `characterIds: []` | `422`, violation `characterIds` |
| E8 | Aria, `characterIds: [Aria II, Aria II]` | `422`, violation `characterIds` |
| E9 | Dorn, `visibility: "corporation"` | `400` « Corporation visibility needs a known corporation » |
| E10 | Dorn, `visibility: "private"`, un de ses personnages | `201` ; `corporationId` `null` |
| E11 | Aria, `characterIds: [Brann II]` | `400` « Character {id de Brann II} is not one of your characters » ; aucune opération |
| E12 | Brann, `characterIds: [Brann Holt, Brann IV]` | `409` « Brann IV is already in the open operation "Ice belt — dimanche" » ; aucune opération |

### Aperçu (`GET /api/mining-operations/join/{code}`)

| # | Entrée | Sortie attendue |
|---|---|---|
| A1 | Brann, code d'O | `200` ; `label`, `ownerCharacterName` « Aria Solberg », `participantsCount` 1, `autoAccepted` vrai, `myStatus` `null` ; `characters` : `Brann Holt` (`isMain`, `eligible`), `Brann II` (`eligible`), `Brann III` (`reconnect_required`), `Brann IV` (`in_another_operation`, « Ice belt — dimanche ») |
| A2 | Cyra (C2), code d'O | `autoAccepted` faux, `Cyra Lune` `eligible` |
| A3 | Brann, « Ice belt — dimanche » passée à `completed` | `Brann IV` `eligible` |
| A4 | Brann, `Brann IV` à la fois token invalide et inscrit ailleurs | `Brann IV` `reconnect_required` |
| A5 | Brann, `Brann II` sans token, ou token sans `esi-industry.read_character_mining.v1` | `Brann II` `reconnect_required` |
| A6 | Brann, code `ffffffffff` | `404` « Mining operation not found » |
| A7 | Brann, O au statut `completed` | `200`, `status` `completed` |
| A8 | Brann membre `removed` d'O | `myStatus` `removed`, `autoAccepted` faux |

### Rejoindre (`POST /api/mining-operations/join/{code}`)

| # | Entrée | Sortie attendue |
|---|---|---|
| J1 | Brann (C1), `[Brann Holt, Brann II]` | `200` ; `myRole` `member`, `myStatus` `accepted`, `participantsCount` 2 ; O : `charactersCount` 4 |
| J2 | Cyra (C2), `[Cyra Lune]` | `200` ; `myStatus` `pending` ; O : `participantsCount` 1, `charactersCount` 2 |
| J3 | Brann, O privée (même corporation), `[Brann Holt]` | `200`, `accepted` |
| J4 | Dorn, O `corporation` | `200`, `pending` |
| J5 | Brann, `[Brann III]` | `400` « Brann III must be reconnected before joining » ; aucune membership |
| J6 | Brann, `[Brann Holt, Brann IV]` | `409` « Brann IV is already in the open operation "Ice belt — dimanche" » ; aucune membership, `Brann Holt` non inscrit |
| J7 | Brann, `[Aria II]` | `400` « Character {id d'Aria II} is not one of your characters » |
| J8 | Brann, `[]` | `422`, violation `characterIds` |
| J9 | Aria (propriétaire), `[Aria II]` | `409` « You are already a member of this operation » |
| J10 | Brann déjà `accepted` | `409` « You are already a member of this operation » |
| J11 | Cyra déjà `pending` | `409` « You are already a member of this operation » |
| J12 | Brann, code `ffffffffff`, `[Brann Holt]` | `404` « Mining operation not found » |
| J13 | Brann, ancien code après R18 | `404` ; avec le nouveau code : `200` |
| J14 | Brann, O `closing` | `400` « This operation is no longer accepting participants » |
| J15 | Brann, O `completed` | `400` idem |
| J16 | Brann, O `in_progress` | `200`, `accepted` |
| J17 | Brann membre `removed` d'O (C1), `[Brann Holt]` | `200`, `pending`, rôle `member` ; 1 personnage inscrit |
| J18 | Brann, `Brann II` inscrit dans P2 où Brann est `pending` | `409` « Brann II is already in the open operation "{libellé de P2}" » |
| J19 | Brann, `Brann II` auparavant dans P2 où Brann a été retiré | `200` |
| J20 | Brann, O `closing` et `[Brann III]` | `400` « This operation is no longer accepting participants » (statut avant personnages) |
| J21 | Cyra, J2, hub Mercure en panne | `200`, `warning` journalisé |

### Listes

| # | Entrée | Sortie attendue |
|---|---|---|
| L1 | Brann, `GET /available`, opérations de Aria : O1 `corporation` C1 `planned` le 05/11, O2 `private` C1, O3 `corporation` C1 `completed`, O4 `corporation` C1 `closing`, O5 `corporation` C1 `in_progress` le 04/11, O6 `corporation` C1 où Brann est `pending`, O7 `corporation` C1 où Brann est `removed` ; O8 `corporation` C1 de Brann ; O9 `corporation` C2 de Cyra | `[O5, O1]` ; chacune avec son `joinCode` |
| L2 | Dorn, `GET /available` | `[]` |
| L3 | Brann, `GET /mining-operations` : propriétaire d'O8 (06/11), `accepted` dans O1 (05/11), `pending` dans O6 (03/11), `removed` dans O7, `accepted` dans O3 `completed` (01/11) | `[O8, O1, O6, O3]` |

### Accès (`GET /mining-operations/{id}`, `GET …/members`)

| # | Profil, opération O sauf mention | Détail | Membres |
|---|---|---|---|
| X1 | Aria, propriétaire | 200, `owner` / `accepted`, `joinCode` renseigné | 200 |
| X2 | Brann `accepted` | 200, `joinCode` renseigné | 200 |
| X3 | Cyra `pending` (C2) | 403 | 403 |
| X4 | Membre de C1 sans membership | 200, `myRole` / `myStatus` `null`, `joinCode` `null` | 403 |
| X5 | Membre de C1 `pending` | 200, `myStatus` `pending`, `joinCode` `null` | 403 |
| X6 | Membre de C1 `removed` | 200, `myStatus` `null` | 403 |
| X7 | Membre de C1 sans membership, O privée | 403 | 403 |
| X8 | Cyra (C2) sans membership | 403 | 403 |
| X9 | Dorn sans membership | 403 | 403 |
| X10 | n'importe qui, identifiant inconnu ou `abc` | 404 « Mining operation not found » | 404 |

Liste des membres pour X1 (O avec Brann `accepted` `[Brann Holt, Brann II]`, Cyra `pending`) : `[Aria Solberg owner accepted [Aria II, Aria Solberg], Brann Holt member accepted [Brann Holt, Brann II], Cyra Lune member pending [Cyra Lune]]`.

### Gestion, départ, code, suppression

| # | Entrée | Sortie attendue |
|---|---|---|
| M1 | Aria, `PATCH` Cyra `{status: "accepted"}` | `200`, Cyra `accepted` ; O `participantsCount` 3 |
| M2 | Brann (`member`), même requête | `403` « Only the operation owner or an admin can perform this action » |
| M3 | Aria, `PATCH` Brann `{role: "admin"}` | `200`, `admin` |
| M4 | Brann `admin`, `PATCH` membre E `{role: "admin"}` | `403` « Only the operation owner can promote members to admin » |
| M5 | Aria, `PATCH` sa propre membership | `400` « Cannot modify the operation owner » |
| M6 | Aria, `PATCH` Brann `accepted` `{status: "accepted"}` | `400` « Only pending members can be accepted » |
| M7 | Aria, `DELETE` Cyra `pending` (refus) | `204` ; Cyra `removed`, `Cyra Lune` éligible ailleurs |
| M8 | Brann `admin`, `DELETE` admin E | `403` « Only the operation owner can manage an admin » |
| M9 | Brann `admin`, `DELETE` Aria | `400` « Cannot remove the operation owner » |
| M10 | Aria, `DELETE` membre déjà `removed` | `400` « Member already removed » |
| M11 | Aria, `PATCH` d'un membre d'une autre opération | `404` « Member not found » |
| Q1 | Brann `accepted`, O `planned`, `POST …/leave` | `204` ; Brann `removed`, ses personnages éligibles ailleurs |
| Q2 | Aria, `POST …/leave` | `400` « The owner cannot leave the operation » |
| Q3 | Brann `accepted`, O `in_progress` | `400` « Only a planned operation can be left » |
| Q4 | Cyra `pending`, O `in_progress` | `204` |
| Q5 | Dorn sans membership | `404` « Membership not found » |
| C1 | Aria, `POST …/join-code`, code `7f3a9c2e1b` | `200`, `joinCode` 10 hex ≠ `7f3a9c2e1b` |
| C2 | Brann `member` | `403` « Only the operation owner or an admin can perform this action » |
| C3 | Aria, O `completed` | `400` « This operation is no longer accepting participants » |
| S1 | Aria, `DELETE /mining-operations/{O}` (Brann inscrit) | `204` ; O, ses membres et lignes de personnages supprimés ; `Brann Holt` éligible ailleurs |
| S2 | Brann `admin` | `403` « Only the operation owner can delete it » |
| S3 | Aria, O `in_progress` | `400` « Only a planned operation can be deleted » |

### Temps réel

| # | Entrée | Sortie attendue |
|---|---|---|
| T1 | `getTopicsForUser('user-uuid-123')` | contient `/user/user-uuid-123/mining-operations` ; 16 topics |
| T2 | J2 (Cyra rejoint O, Brann `accepted`) | un message privé par topic, sur `/user/{Aria}/mining-operations`, `/user/{Brann}/…`, `/user/{Cyra}/…` : `{action: "member_joined", operationId: O, data: {memberId: Cyra, status: "pending"}}` |
| T3 | M7 (refus de Cyra) | `member_removed` vers Aria, Brann et Cyra |
| T4 | S1 | `operation_deleted` vers Aria et Brann |
| T5 | E1 | aucun message |
| T6 | Store : `{action: "member_joined", operationId: "O", data: {...}, timestamp}` | dernier événement de O ; `syncStatus` et événements de projet inchangés |
| T7 | Store : message de projet collaboratif `{action, projectId}` | rangé comme aujourd'hui (garde-fou #148) |
| T8 | Détail d'O ouvert, deux `member_joined` pour O | `fetchDetail(O)` et `fetchMembers(O)` appelés deux fois ; un événement de P2 ne déclenche rien |

### Front

| # | Entrée | Sortie attendue |
|---|---|---|
| F1 | `miningOperationJoinUrl('https://evetools.example', '7f3a9c2e1b')` | `https://evetools.example/mining-operations/join/7f3a9c2e1b` |
| F2 | routeur, `/mining-operations/join/7f3a9c2e1b` | route `mining-operation-join`, `params.code = '7f3a9c2e1b'` |
| F3 | routeur, `/mining-operations/available` sans route dédiée | n'existe pas : l'onglet est `/mining-operations?tab=available` |
| F4 | page d'adhésion, aperçu A1, aucune case cochée | bouton désactivé ; `Brann III` et `Brann IV` désactivés avec leur badge |
| F5 | page d'adhésion, `Brann Holt` et `Brann II` cochés | bouton « Rejoindre avec 2 personnages » ; clic → `join('7f3a9c2e1b', [id, id])` ; réponse `accepted` → route `mining-operation-detail` |
| F6 | réponse `pending` | état « Demande envoyée » |
| F7 | aperçu `myStatus` `accepted` | état « Vous participez déjà », lien vers le détail, pas de sélecteur |
| F8 | aperçu `status` `closing` | état « n'accepte plus de participants », pas de bouton |
| F9 | aperçu `404` | état « Lien invalide ou renouvelé » |
| F10 | création sans corporation (utilisateur sans `corporationId`) | radio « Ma corporation » désactivée avec `create.corporationUnavailable` |
| F11 | radio « Temps pondéré » | désactivée, libellé « bientôt » |
| F12 | textes de chaque vue en fr puis en en | uniquement les clés i18n de la locale (`expectOnlyLocaleTexts`) |
| F13 | navigation, utilisateur non admin | entrées `/mining-operations` (« Opérations de minage ») et `/mining-operations?tab=available` (« Opérations de minage › Disponibles ») après les entrées de Production |

## 7. Cas limites

- **Code renouvelé.** L'ancien code répond `404` en aperçu comme en adhésion, sans indiquer qu'il a existé. Les membres déjà inscrits ne sont pas touchés. Aucune expiration dans le temps.
- **Personnage déjà dans une autre opération ouverte.** Refus `409` (création comme adhésion), badge `in_another_operation` dans l'aperçu. Une demande `pending` retient aussi ses personnages : pour les libérer, son auteur la retire (R16) ou le propriétaire la refuse (R14).
- **Personnage d'un autre compte.** `400`, que l'identifiant existe ou non, sans révéler à qui il appartient.
- **Personnage à reconnecter.** Token absent, `authStatus` `invalid` ou scope de minage manquant : `400`. Le token n'est pas déchiffré (pas d'appel à `TokenManager`).
- **Rejoindre sa propre opération.** Le propriétaire a une membership `owner` / `accepted` : `409`.
- **Opération en cours.** On la rejoint encore (arrivée tardive). Un membre `accepted` ne la quitte pas dans ce lot.
- **Opération en clôture ou terminée.** Aperçu lisible, adhésion et renouvellement refusés (`400`). Une opération terminée libère ses personnages.
- **Corporation différente.** Demande `pending`, visible du propriétaire et des admins, acceptée par `PATCH`.
- **Changement de corporation.** La corporation de l'utilisateur est lue à chaque requête sur son personnage principal ; celle de l'opération reste celle de la création. Un joueur qui quitte C1 ne voit plus les opérations `corporation` de C1 sans membership `accepted`.
- **Membre retiré qui réessaie.** Retour en `pending` (R12) ; il ne voit pas l'opération dans Disponibles (R13) mais peut la lire en `corporation` comme tout membre de la corporation (X6).
- **Propriétaire sans personnage principal.** `ownerCharacterName` vaut `null`, affiché « Pilote inconnu » ; il ne peut créer qu'une opération privée.
- **Personnage retiré du compte.** Sa ligne d'inscription disparaît par cascade ; la membership reste, éventuellement sans personnage.
- **Suppression de compte.** Le propriétaire qui supprime son compte supprime ses opérations (cascade) ; un membre supprime sa membership et ses inscriptions. Aucune donnée de quantité ou de part n'existe encore dans ce lot.
- **Courses.** Deux adhésions simultanées du même compte à deux opérations avec le même personnage peuvent passer toutes les deux : seule une session du même compte peut le faire, la contrainte reste applicative (décision 7).

## 8. Hors périmètre

- Transitions de statut (démarrage, fin, clôture), instantanés du ledger (#263), parts, part du booster, part corporation, base de valorisation (#264), paiements et récapitulatif (#265), fleet storage (#266), sites et scans (#267), temps pondéré (#270, décision en attente), taxe lunaire (#273, #274).
- Champs de la maquette de création non retenus : système, récurrence, base de valorisation, part du booster et part corporation, vaisseau de boost et compresseurs, booster, destinataire du fleet storage. Le vaisseau affiché sous chaque personnage dans la maquette `join` n'est pas affiché.
- Modifier une opération (libellé, début, visibilité, mode de répartition) ; ajouter ou retirer un personnage après l'adhésion ; transférer la propriété.
- Départ d'un membre `accepted` d'une opération en cours (il dépend des instantanés de fin, #263).
- Visibilité alliance, invitation en jeu (`POST /fleets/{id}/members`, scope `esi-fleets.write_fleet` non demandé), notification in-app ou push d'une demande en attente.
- Retour sur le lien d'adhésion après la connexion EVE SSO (le routeur renvoie vers `/login` sans cible, comme pour Group Industry).
- Mise à jour en direct de la liste des opérations disponibles.
- Page Légal : mention des opérations de minage dans la suppression de compte.

## 9. Termes du glossaire

Utilisés tels quels (section Minage, PR #326) : **Opération de minage** (`MiningOperation`), **Participant** (membre `accepted` d'une opération, propriétaire compris, inscrit avec au moins un personnage ; un membre `pending` n'en est pas un, d'où `participantsCount`), **Ligne du ledger de minage** (scope `esi-industry.read_character_mining.v1` requis pour l'éligibilité), **Instantané du ledger**, **Part**, **Part du booster**, **Fleet storage** (cités pour le hors périmètre).

Termes proposés, à ajouter au glossaire après validation (non ajoutés ici) :

| Terme FR | Term EN | Définition proposée | Symbole(s) |
|---|---|---|---|
| **Mode de répartition** | Split mode | Règle choisie à la création d'une opération de minage pour répartir sa valeur : prorata de la valeur ou chacun pour soi (temps pondéré à venir, #270). Stocké dès #262, calculé à partir de #264. | `MiningSplitMode` |
| **Prorata de la valeur** | Value prorata | Mode de répartition où chaque participant reçoit une part proportionnelle à la valeur qu'il a minée, après la part du booster et la part corporation. | `MiningSplitMode::ValueProrata` |
| **Chacun pour soi** | Each for themselves | Mode de répartition où chacun garde son minerai : part du booster et part corporation à 0, aucun fleet storage, aucun paiement. Restent partagés les sites, scans, ETA et récapitulatifs. Étapes : Planifiée → En cours → Clôture → Terminée. | `MiningSplitMode::EachForThemselves` |
| **Code d'invitation** | Join code | Code court (10 caractères hexadécimaux) d'une opération de minage, dans le lien `/mining-operations/join/{code}`. Renouvelable : l'ancien code cesse de fonctionner. | `MiningOperation::$joinCode`, `renewJoinCode()` |
| **Opération ouverte** | Open operation | Opération de minage qui n'est pas terminée (planifiée, en cours ou en clôture). Un personnage n'est inscrit que dans une seule opération ouverte. | `MiningOperationStatus::isOpen()` |
| **Personnage inscrit** | Enrolled character | Personnage coché par un participant ou un demandeur pour une opération de minage ; ce sont ses lignes du ledger qui compteront. | `MiningOperationCharacter` |
| **Participant retiré** | Removed participant | Membre d'une opération de minage refusé, exclu ou parti. Sa membership reste, ses personnages sont libérés ; il ne revient qu'en demande `pending`. | `MiningOperationMemberStatus::Removed` |

## 10. Décisions prises en autonomie

| # | Décision | Alternative écartée | Raison |
|---|---|---|---|
| 1 | Enums dédiés (`MiningOperationMemberRole`, `MiningOperationMemberStatus`), mêmes valeurs que Group Industry | réutiliser `GroupMemberRole` / `GroupMemberStatus` | Group Industry est en refonte dans un autre fil ; un enum partagé couplerait les deux modules. On factorisera si la duplication se confirme (recommandation A). |
| 2 | Corporation figée sur l'opération à la création (`corporationId`) | corporation du propriétaire lue en direct (Group Industry) | L'opération est annoncée à une corporation ; la liste des disponibles devient un filtre indexé, sans jointure sur le personnage principal du propriétaire. |
| 3 | Code de 10 caractères hex, sans expiration, invalidé seulement par le renouvellement | durée de vie (TTL) | L'issue demande un lien « renouvelable pour invalider l'ancien » ; une expiration ajouterait une horloge sans besoin exprimé. |
| 4 | Aperçu GET puis adhésion POST avec les personnages cochés | adhésion au montage de la page (Group Industry) | La maquette fait choisir les personnages avant de rejoindre. |
| 5 | Éligibilité sans déchiffrer le token : token présent, `authStatus` valide, scope de minage | logique de `CharacterCollectionProvider` (déchiffrement via `TokenManager`, tous les scopes requis) | Seul le ledger de minage compte ici ; pas de dépendance cryptographique dans le service. Un token indéchiffrable sera vu au premier instantané (#263). |
| 6 | Une demande `pending` retient ses personnages | seuls les membres `accepted` retiennent leurs personnages | Sinon l'acceptation devrait revérifier chaque personnage et pourrait échouer ; le demandeur garde la main par R16. |
| 7 | « Une seule opération ouverte » contrôlée dans le service, contrainte unique `(member_id, character_id)` seulement | index unique partiel en base, ou colonne nullable unique dénormalisée | Doctrine compare mal les index partiels (`MigrationsReplayTest`) ; la course ne peut venir que du même compte. |
| 8 | Le retrait supprime les lignes de personnages | les garder avec une date de libération | Aucune donnée n'y est attachée dans ce lot ; #263 reverra le cas quand les instantanés existeront. |
| 9 | Le propriétaire coche ses personnages à la création, au moins un | le propriétaire rejoint après coup, ou crée sans personnage | Le glossaire définit le participant « avec un ou plusieurs de ses personnages », et rejoindre sa propre opération est refusé. |
| 10 | Quatre statuts déclarés dès maintenant, seul `planned` atteint | ne déclarer que `planned` | Les règles d'ouverture et les cas limites (opération terminée, en clôture) se testent dès #262 ; les cas futurs (`shares`, `settled`) s'ajoutent sans migration. |
| 11 | `weighted_time` absent de `MiningSplitMode` jusqu'à #270, affiché désactivé au front | cas présent mais refusé | Pas de valeur stockable tant que la décision #270 n'est pas prise. |
| 12 | Cascade sur `users` et `characters`, sans transfert de propriété ni changement de `AccountDeletionService` | transfert au plus ancien admin (spec #53) | Rien à préserver tant qu'il n'y a ni quantité ni part ; le transfert viendra avec #264. |
| 13 | `joinCode` visible des participants et dans Disponibles, renouvellement réservé à l'administration | code réservé au propriétaire et aux admins | Un participant peut inviter un équipier ; les joueurs hors corporation restent en `pending`. |
| 14 | Lecture par la corporation seulement pour une opération `corporation` ; liste des membres réservée à la participation | lecture par la corporation quelle que soit la visibilité (Group Industry) | « Une opération privée n'est accessible que par le lien » (issue). |
| 15 | Topic utilisateur `/user/{id}/mining-operations`, publication à chaque utilisateur concerné | topic par opération autorisé par `TokenProvider` | Pas de changement de `TokenProvider` (qui lit le repository de Group Industry) ni de jeton à rafraîchir quand on rejoint. |
| 16 | Règles dans les entités et `src/Service/MiningOperation/` ; tests Domain = entités et enums, Application = services, sans base | contexte borné `src/Mining/{Domain,Application}` | L'ADR-0002 réserve les couches aux domaines riches ; le calcul des parts (#264) justifiera une ADR et l'extraction. |
| 17 | Départ (`accepted`) et suppression limités à `planned` ; pas de modification d'opération | départ et modification à tout statut | Le départ en cours dépend des instantanés (#263) ; la modification n'est pas demandée. |
| 18 | `400` pour un personnage d'un autre compte ou à reconnecter, `409` pour un personnage pris ou une membership existante | `422` ou `403` | Même famille de codes que Group Industry (`BadRequest`, `Conflict`) ; `422` reste réservé à la forme de l'entrée. |
| 19 | Lien `/mining-operations/join/{code}` | `/minage/rejoindre/{code}` de la maquette | Chemin de l'issue, URLs en anglais comme le reste de l'app. |
| 20 | Groupe de navigation « Minage » avec une entrée | entrée isolée au premier niveau | Structure de la maquette ; la taxe lunaire et la valeur des minerais s'y ajouteront. |
| 21 | Pas de nouvelle maquette : la v4 fait foi, écrans réduits aux champs de ce lot | refaire une maquette #262 | La maquette v4 est la spécification retenue ; une seconde version risquerait de diverger. |
