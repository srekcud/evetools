# Spec #87 — Départ d'un membre en retrait doux, membre retiré masqué en découverte

## Contexte

Depuis le retrait doux (6ef3ad9, #10), un kick ne supprime plus la ligne de membership : `KickGroupMemberProcessor` passe le membre en `GroupMemberStatus::Removed`, ses contributions et son payout sont conservés, et il ne peut revenir qu'en `pending` (`GroupIndustryProjectMember::requestRejoin()`, via le lien court).

Le départ volontaire n'a pas suivi : `LeaveGroupProjectProcessor` refuse le départ d'un membre qui a des contributions (400 « Cannot leave: you have contributions in this project ») et, sinon, supprime la ligne (`EntityManager::remove()`). Le joueur qui veut partir après avoir contribué reste bloqué, et celui qui part sans contribution peut revenir sans validation.

`AvailableGroupProjectsProvider` masque un projet dès qu'une ligne de membership existe pour l'utilisateur, quel que soit son statut. Ce comportement est conservé : un membre retiré ne revoit pas le projet en découverte. Aucun test ne le fige aujourd'hui (la fixture de découverte n'a pas de membre `removed`).

## Règles métier

- **R1 — Le départ est un retrait doux.** Un membre `accepted` (rôle `member` ou `admin`) qui quitte un projet passe en `removed`. La ligne de membership est conservée, jamais supprimée.
- **R2 — Le départ est toujours permis.** Les contributions, quel que soit leur statut, n'empêchent plus le départ.
- **R3 — Les contributions restent.** Les contributions approuvées comptent toujours dans le payout du membre parti. Les contributions `pending` restent `pending` : le propriétaire ou un admin peut encore les approuver ou les rejeter, comme après un kick.
- **R4 — Le membre parti perd son rôle.** Même effet qu'un kick, déjà en place pour tout statut autre que `accepted` : absent de la liste des membres, ne peut plus contribuer ni administrer. Hors de la corporation du propriétaire, l'accès au projet est refusé ; dans cette corporation, il garde la lecture du projet sans rôle, comme tout membre de la corporation (`GroupProjectProviderTest::testRemovedMemberFromOwnerCorporationSeesProjectWithoutRole`).
- **R5 — Retour en pending.** Un membre parti qui rejoint de nouveau (lien court) passe en `pending`, rôle `member`, même s'il est de la corporation du propriétaire. Déjà en place via `requestRejoin()`.
- **R6 — Membre retiré masqué en découverte.** Un projet n'est pas proposé en découverte à un utilisateur qui y a une membership `removed`, qu'elle vienne d'un kick ou d'un départ. Inchangé, figé par un test.
- **R7 — Inchangé.** Le propriétaire ne peut pas partir (400). Un utilisateur sans membership `accepted` reçoit 404. L'événement Mercure `member_left` est publié avec `memberId` et `characterName`.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Membre `accepted`, rôle `member`, 0 contribution, quitte | ligne supprimée (`remove`) | statut `removed`, ligne conservée, aucun `remove` ; `member_left` publié |
| E2 | Membre `accepted`, rôle `member`, 3 contributions (2 `approved`, 1 `pending`), quitte | 400 « Cannot leave: you have contributions in this project » | statut `removed` ; les 3 contributions inchangées (2 `approved`, 1 `pending`) |
| E3 | Admin `accepted`, 0 contribution, quitte | ligne supprimée | statut `removed` ; au retour, rôle `member` (R5) |
| E4 | Après E2, le propriétaire approuve la contribution `pending` du membre parti | — (cas inatteignable) | 200, contribution `approved` (garde-fou : `approve()` ne regarde pas le statut du membre) |
| E5 | Après E2, payout du projet (marge 10 %, contributions approuvées du membre parti : 1 000 000 ISK) | — | le membre parti figure avec un payout de 1 100 000 ISK (garde-fou, déjà couvert pour un kick par `testRemovedMemberApprovedContributionStillProducesPayout`) |
| E6 | Après E1, le membre parti rejoint par le lien court, même corporation que le propriétaire | — (ligne supprimée : nouvelle membership `accepted`) | même ligne, statut `pending`, rôle `member` |
| E7 | Découverte : utilisateur avec une membership `removed` sur un projet `published` de sa corporation | masqué | masqué (garde-fou, R6) |
| E8 | Propriétaire qui quitte | 400 « Owner cannot leave the project » | inchangé |
| E9 | Membre `pending` ou `removed` qui quitte | 404 « You are not a member of this project » | inchangé |

## Cas limites

- Départ, puis kick impossible : le membre n'est plus `accepted`, le kick d'un membre `removed` reste hors périmètre.
- Membre parti puis revenu en `pending` puis accepté : la même ligne repasse en `accepted`, ses anciennes contributions lui sont rattachées.

## Hors périmètre

- Distinguer un départ d'un kick (nouveau statut, horodatage de départ).
- Annulation d'une demande `pending` par le demandeur.
- Rejet automatique des contributions `pending` au départ.
- Affichage frontend : le bouton « Quitter » et la redirection ne changent pas ; le message d'erreur « Cannot leave » disparaît de fait.
- Migration : aucune, les départs passés ont supprimé leurs lignes.

## Tests existants appelés à changer

- `LeaveGroupProjectProcessorTest::testMemberWithContributionsCannotLeave` : remplacé par E2.
- `LeaveGroupProjectProcessorTest::testSuccessfulLeave` et `testAdminWithNoContributionsCanLeave` : attendent `remove()`, réécrits sur E1 et E3.
- `LeaveGroupProjectProcessor` n'a plus besoin de `GroupIndustryContributionRepository` : les tests qui l'injectent changent de constructeur.

## Glossaire

Terme proposé pour [`docs/glossary.md`](../glossary.md), section « Industrie collaborative » :

- **Membre retiré** (Removed member) : membre d'un projet collaboratif exclu par un kick ou parti de lui-même. Sa ligne de membership est conservée avec ses contributions et son payout ; il perd l'accès au projet, ne le voit plus en découverte et ne peut revenir qu'en demande `pending`. Code : `GroupMemberStatus::Removed`, `KickGroupMemberProcessor`, `LeaveGroupProjectProcessor`, `GroupIndustryProjectMember::requestRejoin()`.
