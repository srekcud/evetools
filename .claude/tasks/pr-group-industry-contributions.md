## Contexte

Deux bugs du module Group Industry relevés par l'audit de la Phase 1, et la décision du mainteneur pour #10 : **un admin doit pouvoir retirer un membre sans supprimer ses contributions validées ou en cours.**

## #10 — Retrait doux d'un membre

La relation contribution → membre est en `ON DELETE CASCADE`. Exclure un membre supprimait donc la ligne de membership et, par cascade, ses contributions et son payout.

- **Le retrait est maintenant doux.** Le membre passe au nouveau statut `removed`, sans `remove()`. Le comportement est le même qu'il ait des contributions ou non. L'événement Mercure `member_left` est inchangé.
- **Les contributions** du membre retiré, approuvées ou en attente, sont conservées et comptent toujours dans la répartition et les payouts (test de garde dédié).
- **Accès.** L'accès à un projet exige désormais une adhésion `accepted`. Un membre retiré, **ou en attente**, n'y a plus accès. Ça ferme une fuite existante : un membre `pending` voyait le détail du projet.
- **Liste des membres.** Les membres retirés n'y apparaissent plus.
- **Retour au projet.** Un membre retiré qui rejoint réutilise sa ligne (contrainte unique projet + utilisateur). Il repasse **toujours en `pending`**, même s'il est de la corpo du propriétaire, sinon le retrait ne servirait à rien. Son rôle redevient `member` et sa date d'arrivée est remise à zéro.
- **Page de join.** Le projet expose `myStatus` (`accepted`, `pending` ou `null`). La page ne redirige vers le détail que si le statut est `accepted` ; sinon elle affiche l'écran « en attente ». Avant, elle redirigeait dès qu'un rôle existait, ce qui donnait un 403 avec les nouvelles règles d'accès.

## #9 — Marge de la répartition en pourcentage

`marginPercent` contient maintenant un pourcentage (58,33 et non 0,583), comme dans les autres services. Les payouts utilisent toujours le ratio en interne et ne changent pas. Le frontend attendait déjà un pourcentage.

## À relire / hors périmètre

- Le départ volontaire (`LeaveGroupProjectProcessor`) n'a pas changé : il refuse toujours si le membre a des contributions et supprime la ligne. Faut-il l'aligner sur le retrait doux ?
- `AvailableGroupProjectsProvider` cache un projet dès qu'une ligne de membership existe, y compris `removed`. Le membre retiré ne le voit plus dans la découverte corpo, mais peut le rejoindre par le lien court.
- Le `ON DELETE CASCADE` reste en base. Aucun chemin de l'API ne supprime plus de membership pour un kick.
- L'historique contient d'abord un commit qui refusait l'exclusion, remplacé ensuite par le retrait doux. On peut squasher au merge.

## Vérifications

- Suite complète, intégration comprise : 893 tests, 0 échec. Frontend : 83 tests verts, lint 0 erreur, build OK.
- PHPStan : 63 erreurs, comme sur `main`. deptrac : 35 violations, comme sur `main`.
- MSI : `KickGroupMemberProcessor` et le flux de membership 88 %, mapper 100 %, distribution 89 %.
- Relecture `security-reviewer` faite sur la première version (rien à signaler).

Fixes #9, fixes #10
