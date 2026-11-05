# Spec #362, lot 1 : un seul projet industriel (modèle et domaine)

## Contexte

`IndustryProject` (solo : un produit, arbre d'étapes, `ProjectStatus` active/completed/archived) et `GroupIndustryProject` (n items, BOM plate, membres, contributions, ventes, `GroupProjectStatus`) sont deux entités sans lien. Décisions de Sylvain (2026-11-05) : un seul projet industriel à 1..n participants, le propriétaire est le premier participant ; cycle de vie du groupe pour tous ; les étapes sont la source du plan, la BOM en dérive ; n racines d'étapes par projet ; aucune migration de données (module en beta, tout est effacé) ; routes changées d'un coup au lot 2, front au lot 3.

Lot 1 : une seule entité, `IndustryProject`. `GroupIndustryProject` disparaît. Les URI d'aujourd'hui (`/industry/projects/...`, `/group-industry/projects/...`) restent, servies par l'entité unifiée, avec les mêmes réponses JSON : le front ne change pas.

## Règles

- **R1 — Une entité.** `IndustryProject` (table `industry_projects`) porte les champs des deux projets : ceux du solo, plus statut de cycle de vie, code d'invitation, container, location de lignes (override), listes noires (groupes, types), frais de courtage et taxe de vente, `updatedAt`. Items, participants, lignes de BOM, contributions et ventes pointent vers `industry_projects`.
- **R2 — Propriétaire.** Le champ `user` du solo devient `owner` (`getOwner()`, colonne `owner_id`). `transferOwnershipTo()` passe sur `IndustryProject`.
- **R3 — Premier participant.** Toute création, par l'une ou l'autre route, ajoute une ligne de participant `owner` / `accepted` pour le propriétaire (comme `GroupIndustryProjectService::createProject()` aujourd'hui).
- **R4 — Cycle de vie pour tous.** `ProjectStatus` est supprimé. Le statut est un `GroupProjectStatus`. La route solo crée en `draft` ; la route groupe crée en `published` (inchangé).
- **R5 — Statut vu par la route solo.** La ressource solo expose `completed` pour `completed` et `active` pour tout autre statut. PATCH solo `completed` : statut `completed`, `completedAt` posé s'il est vide (inchangé). PATCH solo `active` sur un projet `completed` : retour en `draft`, `completedAt` gardé (inchangé). `archived` est retiré (D2).
- **R6 — Code d'invitation.** Tout projet reçoit un code de 10 caractères hexadécimaux, unique. La route solo ne l'expose pas au lot 1.
- **R7 — Source du plan au lot 1.** Deux sources coexistent jusqu'au lot qui dérive la BOM des étapes (D1) : un projet créé par la route solo a des étapes et aucun item ; un projet créé par la route groupe a des items, une BOM et aucune étape. Les prédicats sont portés par l'entité : `isPlannedBySteps()` (aucun item, côté route solo) et `hasSteps()` (au moins une étape, côté route groupe).
- **R8 — Produit d'un projet créé par la route groupe.** `productTypeId`, `runs`, `meLevel`, `teLevel` (non nuls) reprennent ceux du premier item (`sortOrder` 0). Aucun code de groupe ne les lit.
- **R9 — Chaque route ne sert que ses projets.** Routes solo : projets planifiés par étapes ; un projet à items répond 404 « Project not found », comme un projet d'un autre utilisateur. Routes groupe : projets à items ; un projet planifié par étapes (aucun item) répond comme un projet inexistant (404), y compris pour la résolution d'une vente. Listes, découverte, lien court et jeton Mercure filtrent sur les items (`IndustryProjectRepository`, `IndustryProjectParticipantRepository`).
- **R10 — Accès inchangé (spec #162).** `GroupProjectAccessChecker` reçoit un `IndustryProject` ; la matrice A1 à A13 donne les mêmes codes et messages. Routes solo : propriétaire seul, 404 sinon (inchangé).
- **R11 — Suppression inchangée par route.** Route solo : le propriétaire supprime toujours (étapes, achats et jobs matchés en cascade). Route groupe : `isDeletable()` (spec #152) inchangé. Unification au lot 2 (D2).
- **R12 — Purge.** La migration efface tous les projets solo et groupe et leurs lignes dépendantes, puis transforme le schéma. Utilisateurs, personnages, transactions wallet, Stockpile, structures et réglages industrie restent.

## Exemples (vérifiables par test)

Utilisateur U (corporation C1), produit P (10 runs, ME 10, TE 20), items A (5 runs, ME 10, TE 20) et B (3 runs).

| # | Action | Résultat attendu |
|---|---|---|
| E1 | U crée P par la route solo | 1 ligne `industry_projects`, statut `draft`, 1 participant `owner`/`accepted`, 0 item, 0 ligne de BOM, code de 10 caractères ; `GET /industry/projects/{id}` : `status` `active` |
| E2 | U crée A + B par la route groupe | 1 ligne `industry_projects`, statut `published`, `productTypeId` = A, `runs` 5, ME 10, TE 20 ; 2 items ; 1 participant `owner`/`accepted` ; BOM identique à aujourd'hui (golden inchangé) ; 0 étape |
| E3 | U liste `GET /industry/projects` | E1 seul |
| E4 | U liste ses projets de groupe | E2 seul, `myRole` `owner` |
| E5 | Membre de C1 liste les projets disponibles | E2 seul (E1 est `draft` et sans item) |
| E6 | U lit `GET /industry/projects/{E2}` | 404 « Project not found » |
| E7 | U lit `GET /group-industry/projects/{E1}` et le lien court de E1 | 404 |
| E8 | PATCH solo `{status: completed}` sur E1, puis `{status: active}` | `completed` puis `draft` ; API `completed` puis `active` ; `completedAt` posé puis gardé |
| E9 | Jeton Mercure de U | topic `/group-project/{E2}/events`, pas celui de E1 |
| E10 | Suppression du compte de U, E2 a un admin `accepted` | E1 supprimé avec ses étapes ; E2 transféré à l'admin (inchangé) |
| E11 | Migration sur une base avec 3 projets solo (4 étapes) et 2 projets de groupe (4 membres, 1 vente) | `group_industry_projects` n'existe plus ; 0 ligne dans les 9 autres tables de projets (liste dans la note de câblage) ; utilisateurs, personnages et transactions wallet gardés |
| E12 | Matrice #162 A1 à A13 sur E2 | codes et messages de la spec #162 |

## Cas limites

- **Projet solo sans participant** : impossible après R3 ; aucun projet ancien ne survit à la purge.
- **Item supprimé** : aucune route ne retire un item ; un projet à items ne devient jamais « planifié par étapes ».
- **Identifiant** : l'entité unifiée génère un UUID v7 au constructeur (comme le groupe) ; `getId()` n'est plus nullable.
- **Corrections ponctuelles** (`ProjectByProjectCorrection` et ses trois commandes) : sélection « actif » devient « statut différent de `completed` », sur les projets planifiés par étapes.
- **Job ESI terminé** : la notification Mercure `industry-project` garde `projectId` et le nom du projet ; aucun lien en base vers un projet.
- **Stockpile** : aucune clé vers un projet (`IndustryStockpileTarget`, `IndustryStockpileImport`) ; hors purge.

## Tests existants contredits ou modifiés

- Purge et anciens tests de migration (`PurgeIndustryProjectsMigrationTest`, `GroupIndustryJobQuantitiesInUnitsMigrationTest`, `GroupIndustrySaleSellerMigrationTest`, `GroupIndustrySaleWalletTransactionMigrationTest`) : ils écrivent dans `group_industry_projects` via les entités ; contredits par la nouvelle table. Recommandation : les supprimer, le nouveau test de purge les remplace.
- `ProjectStatus` : `ReactionProjectSellPriceRecalculationTest`, `ReactionStepMeLevelResetTest`, `MultiUnitProjectStepsRegenerationTest`, `UpdateProjectInputValidationTest` (voir D2).
- `AccountDeletionServiceTest` : un projet solo a maintenant une ligne de participant ; les comptes de lignes changent, pas le résultat.
- Autres (environ 115 fichiers) : changement de type `GroupIndustryProject` vers `IndustryProject`, `setUser` vers `setOwner`, mocks de `GroupIndustryProjectRepository` ; assertions inchangées. Liste dans `.claude/tasks/362-wiring.md`.

## Hors périmètre

Routes unifiées et voters (lot 2) ; front (lot 3) ; dérivation de la BOM depuis les étapes et racines multiples d'un projet de groupe (D1, #337) ; fusion des listes noires (`excludedTypeIds` solo, `blacklistGroupIds`/`blacklistTypeIds` groupe) ; frais du projet dans le prix de vente estimé solo ; renommage des enums `GroupMemberRole`, `GroupMemberStatus`, `GroupProjectStatus` ; ressources API et Mercure `/group-project/...`.

## Glossaire (à ajouter, section « Industrie collaborative » renommée « Projet industriel »)

- **Projet industriel** (Industry project) : projet de fabrication à 1..n participants, propriétaire compris ; une seule entité. `IndustryProject`.
- **Participant** (Project participant) : utilisateur rattaché à un projet industriel avec un rôle et un statut ; le propriétaire est le premier. Remplace « membre » ; code `IndustryProjectParticipant` jusqu'au renommage (D3).
- **Code d'invitation** (Invitation code) : code court unique d'un projet, porté par le lien d'invitation. `IndustryProject::$shortLinkCode`.
- **Cycle de vie** (Project lifecycle) : `draft`, `published`, `in_progress`, `selling`, `completed`. `GroupProjectStatus`.
- **Produit du projet** (Project product) : produit visé, avec runs, ME et TE ; une racine d'étapes. `IndustryProjectProduct` au lot 1.

## Décisions (Sylvain, 2026-11-05)

- **D1 — BOM dérivée des étapes** : après le lot 2, alignée sur #337 ; au lot 1 la BOM reste calculée depuis les items, golden inchangé.
- **D2 — `archived`** : retiré de `UpdateProjectInput` (le PATCH solo n'accepte plus que `active` et `completed`, tout autre statut est refusé par la validation, 400). La réouverture d'un `completed` et la suppression libre restent propres à la route solo jusqu'au lot 2.
- **D3 — Renommage `GroupIndustry*`** : oui, dernier sous-lot mécanique (362-C), tables `industry_project_*`.
- **D4 — Prédicat de route** : dérivé, sans colonne : un seul prédicat, `IndustryProject::isPlannedBySteps()` (aucun item) : côté route groupe un projet sans item est un projet solo (404), côté route solo un projet à items est un projet de groupe (404), même si le propriétaire a supprimé toutes les étapes d'un solo. Un projet solo vit de ses étapes, un projet de groupe de ses items et de sa BOM plate. Listes et lien court filtrent sur les items (`IndustryProjectRepository`).
