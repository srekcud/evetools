# Spec #162 — Un seul contrôle d'accès aux ressources d'un projet collaboratif

## Contexte

Le contrôle d'accès aux ressources d'un projet Group Industry existe en plusieurs copies (audit du moteur industrie, 2026-10-22). État vérifié dans le code au 2026-10-26 :

| Endroit | Règle appliquée | Comment |
|---|---|---|
| `GroupProjectProvider::provide()` | membership `accepted`, sinon même corporation que le propriétaire | copie en ligne ; pas de court-circuit sur le propriétaire : il passe par sa ligne `owner`/`accepted` créée par `GroupIndustryProjectService::createProject()` |
| `GroupBomProvider::assertUserCanAccessProject()` | propriétaire, membership `accepted`, ou même corporation | méthode privée |
| `GroupDistributionProvider::assertUserCanAccessProject()` | idem | méthode privée, copie de la précédente |
| `GroupSaleCollectionProvider::assertUserCanAccessProject()` | idem | méthode privée, copie de la précédente |
| `GroupMemberCollectionProvider::provide()` | propriétaire ou membership `accepted` | `GroupProjectAccessChecker::assertAcceptedMember()` |
| `GroupContributionCollectionProvider::provide()` | propriétaire ou membership `accepted` | `GroupProjectAccessChecker::assertAcceptedMember()` |
| `RecordSaleProcessor::assertUserIsAdminOrOwner()` | propriétaire ou admin `accepted` | méthode privée, copie de `GroupProjectAccessChecker::assertAdminOrOwner()` ; seul le message diffère (« Only project owner or admin can record sales ») |

Passent déjà par `GroupProjectAccessChecker::assertAdminOrOwner()` : `ContainerVerificationProvider`, `UpdateGroupProjectProcessor`, `UpdateGroupMemberProcessor`, `KickGroupMemberProcessor`, `ReviewContributionProcessor`.

Les quatre copies en ligne disent aujourd'hui la même chose (au court-circuit du propriétaire près). L'écart réel est ailleurs : le code applique deux niveaux d'accès sans jamais les nommer. Un utilisateur de la corporation du propriétaire qui n'est pas membre lit le projet, la BOM, la distribution et les ventes, mais reçoit 403 sur les membres et les contributions. À l'écran, `GroupIndustryDetail.vue` charge le projet et la BOM au montage, puis les onglets à la demande : cet utilisateur voit l'onglet Distribution et reçoit une erreur dans les onglets Members et Contributions.

La spec #87 (R4) fixe la règle pour le projet : un membre retiré hors de la corporation du propriétaire perd l'accès ; dans cette corporation, il garde la lecture du projet sans rôle, comme tout membre de la corporation (`GroupProjectProviderTest::testRemovedMemberFromOwnerCorporationSeesProjectWithoutRole`). Elle fixe aussi qu'il n'apparaît plus dans la liste des membres et ne peut plus contribuer ni administrer.

## Règles métier

- **R1 — Trois niveaux d'accès, nommés, au même endroit.** `GroupProjectAccessChecker` porte les trois :
  - **Lecture** (méthode à ajouter, nom proposé `assertCanRead()`) : le propriétaire, un membre `accepted` quel que soit son rôle, ou un utilisateur dont la corporation est connue et égale à celle du propriétaire. S'applique à la lecture du projet, de la BOM, de la distribution et des ventes.
  - **Participation** (`assertAcceptedMember()`, inchangé) : le propriétaire ou un membre `accepted`. S'applique à la liste des membres et à la liste des contributions.
  - **Administration** (`assertAdminOrOwner()`, inchangé) : le propriétaire ou un admin `accepted`. S'applique, en plus des appelants actuels, à l'enregistrement d'une vente.
- **R2 — Aucune copie.** Les six providers du tableau et `RecordSaleProcessor` délèguent au checker. Aucun d'eux ne lit plus le statut d'une membership ni la corporation pour décider d'un accès. `GroupProjectProvider` lit encore la membership `accepted` de l'utilisateur, mais seulement pour remplir `myRole` et `myStatus`.
- **R3 — Comportement inchangé.** Tous les codes de la matrice ci-dessous sont ceux d'aujourd'hui. Le seul changement visible : le 403 de l'enregistrement d'une vente porte le message générique du checker, « Only project owner or admin can perform this action ». Le front n'affiche ni ne compare ce texte (`stores/group-industry/distribution.ts::recordSale`).
- **R4 — Ordre des refus inchangé.** 401 sans utilisateur, puis 404 si le projet n'existe pas, puis 403 si l'accès est refusé, puis les refus métier (400 « Cannot record a sale on a completed project »). Un étranger reçoit donc 403 et non 400 sur un projet terminé.
- **R5 — Le statut compte, pas l'existence de la ligne.** Une membership `pending` ou `removed` n'ouvre aucun niveau d'accès. Seule l'appartenance à la corporation du propriétaire ouvre la lecture, quel que soit le statut de la membership (#87, R4 et R5).

## Exemples chiffrés (matrice des tests rouges)

Projet `Sabre batch`, statut `in_progress`, propriétaire dans la corporation C1 = 98000001. Corporation étrangère C2 = 98000002. Colonnes : `GET /group-industry/projects/{id}`, `GET …/{projectId}/bom`, `GET …/{projectId}/distribution`, `GET …/{projectId}/sales`, `GET …/{projectId}/members`, `GET …/{projectId}/contributions`, `POST …/{projectId}/sales`.

| # | Profil | Projet | BOM | Distribution | Ventes | Membres | Contributions | Vente (POST) |
|---|---|---|---|---|---|---|---|---|
| A1 | Propriétaire (C1) | 200, `myRole` `owner`, `myStatus` `accepted` | 200 | 200 | 200 | 200 | 200 | 201 |
| A2 | Admin `accepted` (C2) | 200, `admin` / `accepted` | 200 | 200 | 200 | 200 | 200 | 201 |
| A3 | Membre `accepted`, rôle `member` (C2) | 200, `member` / `accepted` | 200 | 200 | 200 | 200 | 200 | 403 |
| A4 | Membre `pending` (C2) | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| A5 | Même corporation (C1), aucune membership | 200, `null` / `null` | 200 | 200 | 200 | 403 | 403 | 403 |
| A6 | Même corporation (C1), membership `pending` (retour après retrait, #87 R5) | 200, `null` / `null` | 200 | 200 | 200 | 403 | 403 | 403 |
| A7 | Membre retiré (C1), ancien admin | 200, `null` / `null` | 200 | 200 | 200 | 403 | 403 | 403 |
| A8 | Membre retiré (C2) | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| A9 | Étranger (C2), aucune membership | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| A10 | Utilisateur sans corporation connue, aucune membership | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| A11 | N'importe quel profil, projet inexistant | 404 | 404 | 404 | 404 | 404 | 404 | 404 |
| A12 | Admin `accepted` (C2), projet `completed` | 200 | 200 | 200 | 200 | 200 | 200 | 400 « Cannot record a sale on a completed project » |
| A13 | Étranger (C2), projet `completed` | 403 | 403 | 403 | 403 | 403 | 403 | 403 (R4) |

Message des 403 : « You do not have access to this project » (lecture, participation), « Only project owner or admin can perform this action » (administration).

Couverture actuelle : `GroupProjectProviderTest` couvre A2, A3, A5, A7, A8 sur le projet ; `GroupBomProviderTest` couvre A1, A3, A5, A9 sur la BOM ; `GroupProjectAccessCheckerTest` couvre A1, A3, A4, A8 et l'admin retiré pour `assertAcceptedMember()` et `assertAdminOrOwner()`. `GroupDistributionProvider` et `GroupSaleCollectionProvider` n'ont aucun test unitaire.

Harnais : le vrai `GroupProjectAccessChecker` sur un `GroupIndustryProjectMemberRepository` en mémoire, dont `findOneBy()` compare chaque critère aux lignes stockées (motif de `GroupProjectProviderTest::setUp()`). Les tests de la matrice échouent d'abord parce que les providers reçoivent le checker au constructeur à la place du repository de memberships.

## Cas limites

- **Propriétaire.** Il passe par son identité (`$project->getOwner() === $user`) avant toute lecture de membership, pour les trois niveaux. `GroupProjectProvider` cesse de dépendre de la ligne `owner`/`accepted` pour l'accès.
- **Propriétaire sans corporation connue.** La règle de corporation ne s'applique jamais : elle exige deux corporations connues et égales. Le propriétaire garde l'accès par son identité, les membres `accepted` par leur membership.
- **Changement de corporation.** La corporation est lue sur `User` à chaque requête : un joueur qui quitte la corporation du propriétaire perd la lecture dès la requête suivante, sauf s'il est membre `accepted`.
- **Membre `pending` de la corporation du propriétaire** (A6). Il lit le projet, mais ni les membres ni les contributions.
- **Items sous un projet.** `GroupMemberItemProvider` et `GroupContributionItemProvider` ne contrôlent pas l'accès : seuls PATCH et DELETE les utilisent, et leurs processors appellent `assertAdminOrOwner()` avant tout effet. Un étranger qui vise un membre inexistant reçoit 404 avant le 403. Inchangé.

## Hors périmètre

- Ouvrir la liste des membres et les contributions à la corporation du propriétaire (question Q1).
- Répondre 404 plutôt que 403 à un étranger pour masquer l'existence du projet (question Q3).
- `SubmitContributionProcessor` : il lit la membership `accepted` parce qu'il en a besoin pour rattacher la contribution, avec son propre message (« You must be an accepted member to contribute »). `LeaveGroupProjectProcessor` répond 404 sans membership `accepted` (#87, R7). Les deux restent tels quels.
- La comparaison « même corporation que le propriétaire » de `JoinGroupProjectProcessor::createMembership()` (acceptation automatique) et le filtre SQL de `AvailableGroupProjectsProvider` : ce ne sont pas des contrôles d'accès. Les rapprocher de la règle de lecture irait dans un autre lot.
- Temps réel : le jeton Mercure ne couvre que les projets où l'utilisateur est membre `accepted` (`TokenProvider`, via `GroupIndustryProjectMemberRepository::findAcceptedProjectIds()`). Un lecteur de la corporation n'en reçoit pas les événements. Inchangé.
- Frontend : aucun changement. Les onglets Members et Contributions restent en erreur pour un lecteur de la corporation, sauf décision contraire en Q1.
- Aucune migration, aucune dépendance.

## Tests existants appelés à changer

- `GroupBomProviderTest` : son constructeur passe `GroupIndustryProjectMemberRepository` à `GroupBomProvider`, il passera le checker. Les assertions ne changent pas.
- `GroupProjectProviderTest` : le provider reçoit aussi le checker. Les cinq tests gardent leurs assertions.
- `RecordSaleProcessorTest` : le constructeur reçoit le checker à la place du repository de memberships. Ses deux tests (admin, projet `selling` ou `completed`) gardent leurs assertions.
- Nouveaux : `GroupDistributionProviderTest`, `GroupSaleCollectionProviderTest`, un test du refus de vente (A3, A9) dans `RecordSaleProcessorTest`, et la matrice de `assertCanRead()` dans `GroupProjectAccessCheckerTest`.

## Glossaire

Terme proposé pour [`docs/glossary.md`](../glossary.md), section « Industrie collaborative ». Il n'est pas encore ajouté, faute de validation :

- **Accès en lecture** (Read access) : droit de lire un projet collaboratif, sa BOM, sa distribution et ses ventes. Il est ouvert au propriétaire, aux membres `accepted` et à tout utilisateur de la corporation du propriétaire, quel que soit le statut de sa membership. La liste des membres et les contributions demandent d'être membre `accepted`, et l'administration d'être admin ou propriétaire. Code : `GroupProjectAccessChecker` (`assertCanRead()` proposé, `assertAcceptedMember()`, `assertAdminOrOwner()`).

## 🚦 Questions pour Sylvain

1. **Q1 — La liste des membres et les contributions restent-elles réservées aux membres `accepted` et au propriétaire** (403 pour un lecteur de la corporation, A5 à A7) ? Recommandation : **oui**. C'est le comportement actuel, et ce lot doit rester une déduplication. Ouvrir ces deux ressources à la corporation ferait disparaître les erreurs d'onglets, mais c'est une décision produit à spécifier à part.
2. **Q2 — La distribution (payout par pilote, avec son nom) et les ventes restent-elles lisibles par toute la corporation du propriétaire ?** Recommandation : **oui**, inchangé. On note l'incohérence : un lecteur de la corporation voit les payouts nominatifs mais pas la liste des membres. La restreindre retirerait une information aux joueurs qui consultent un projet avant de le rejoindre.
3. **Q3 — Un étranger reçoit-il toujours 403, sans 404 pour masquer l'existence du projet ?** Recommandation : **oui**. L'identifiant d'un projet est un UUID, et le lien court passe par une autre route.
