# Spec #206 (fin) : formes de réponse et contrôle unique des DELETE Industry

**Statut : décidée en autonomie le 2026-11-03 sur instruction « fini #206 », en suivant la décision de #233 (toujours une liste) ; à relire par Sylvain.**

Circuit : `circuit:l` ramené à deux lots `s`/`m` après tri (`.claude/tasks/206-triage.md`). Lots 6 et 7 du tri.

## Règles

- **R1 : POST d'étape, une seule forme.** `POST /industry/projects/{id}/steps` renvoie toujours une liste de `ProjectStepResource` : la nouvelle étape en tête, puis, pour la branche `stepId`, l'étape de référence recalculée. Branches `typeId` et `splitGroupId` : liste d'une étape, la nouvelle. Fin de l'objet `{newStep, updatedStep}` et de l'objet seul. Statut 201 inchangé.
- **R2 : Front.** `addChildJob` et la création d'étape de `frontend/src/stores/industry/steps.ts` ne lisent qu'une liste et renvoient la nouvelle étape (premier élément). Le rechargement du projet après `addChildJob` est inchangé.
- **R3 : Contrôle de propriétaire une seule fois par DELETE.** Quand le provider d'un DELETE contrôle le propriétaire et renvoie la resource (`JobMatchDeleteProvider`, `StepPurchaseDeleteProvider`), le processor ne refait pas le même contrôle et agit sur la resource reçue. `ScannerFavoriteDeleteProvider` et son processor ne changent pas : un favori n'a pas d'identifiant propre (couple utilisateur et `typeId`), le processor le retrouve par l'utilisateur connecté, ce qui est sa recherche et non un contrôle en double. Les providers « stub » dont le processor contrôle déjà seul (`ProjectReferenceProvider`, `ProjectStepReferenceProvider`, `StockpileTargetDeleteProvider`, `StructureDeleteProvider`, `BpcPriceDeleteProvider`) ne changent pas.
- **R5 : Lecture d'un projet de groupe réservée à ses membres.** Décision de Sylvain, 2026-11-03 : quelqu'un qui ne fait pas partie du projet ne peut pas le voir. Le propriétaire et les membres acceptés lisent le détail du projet, la BOM, les ventes et la distribution ; tout autre utilisateur, y compris un collègue de la corporation du propriétaire, reçoit 403 `You do not have access to this project`. `GroupProjectAccessChecker::assertCanRead` et son accès par corporation disparaissent au profit de `assertAcceptedMember`. Inchangés : la liste des projets disponibles à rejoindre, l'entrée par lien de partage (`/projects/join/{code}`), les refus 403 déjà en place.
- **R4 : Comportement inchangé.** Statuts, 404, corps de réponse des DELETE, sécurité.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| S1 | POST avec `typeId` | 201, liste d'une étape |
| S2 | POST avec `splitGroupId` | 201, liste d'une étape (le nouveau job) |
| S3 | POST avec `stepId` | 201, liste de deux étapes : le nouveau job, puis l'étape de référence |
| S4 | `addChildJob` côté store, réponse S3 | retourne le nouveau job, recharge le projet |
| D1 | DELETE d'un job match d'un autre utilisateur | 404, rien supprimé |
| D2 | DELETE d'un favori de scanner d'un autre utilisateur | 404, rien supprimé (déjà le cas, figé) |
| V1 | GET détail, bom, sales, distribution d'un projet par un collègue de corporation non membre | 403 `You do not have access to this project` |
| V2 | mêmes routes par un membre en attente | 403 (déjà le cas, figé) |
| V3 | mêmes routes par le propriétaire et un membre accepté | 200 |
| D3 | DELETE de son propre job match | 204, supprimé par l'id de la resource, le processor ne lit plus le propriétaire |

## Hors périmètre

Les stubs de R3, `JobMatchDeleteProvider` qui renvoie un `ProjectStepResource` à l'id du lien (à traiter à part si besoin), les comportements de #331.
