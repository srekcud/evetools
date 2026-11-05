# Spec #362, lot 2 : un seul plan et une seule API pour le projet industriel (backend)

## Contexte

Le lot 1 a fusionné les entités dans `IndustryProject`, mais deux familles de routes restent : `/industry/projects/...` sert les projets planifiés par étapes (arbre ME/TE, achats d'étape, jobs liés), `/group-industry/...` les projets à produits (items et BOM plate), avec `isPlannedBySteps()` comme garde. Décisions de Sylvain (2026-11-05) : tout projet a 1 à n produits et une BOM plate ; les étapes disparaissent ; solo et groupe ne diffèrent que par l'accès ; `draft` pour tous ; apport sans revue en solo ; `isDeletable()` pour tous ; une seule release, sans compatibilité de routes ni migration de données ; un projet peut être privé ; le suivi des jobs ESI et des achats wallet passe des étapes aux lignes de BOM ; les autres calculs par étapes sont supprimés. Backend seul, front cassé jusqu'au lot 3. D1 et D4 du lot 1 sont caduques.

## Règles

- **R1 — Plan unique.** Un projet a 1 à 10 produits (validation actuelle de `CreateGroupProjectInput`) et une BOM plate construite à la création par `GroupIndustryProjectService::buildBom` (golden inchangé). Étapes, découpe, arbre, liste d'achats, estimation de coût, coûts de copie, kit BPC, prix de vente estimé, profit solo, durée par run et commandes ponctuelles sont supprimés (inventaire : note de câblage).
- **R2 — Création unique.** `POST /industry/projects` prend le corps de création du groupe et un booléen `private` (défaut `true`, D8). Le projet naît en `draft`, avec le propriétaire comme seul participant `owner`/`accepted`, un code d'invitation et sa BOM.
- **R3 — Champs du solo retirés.** Sortent de l'entité et de la table : produit, runs, ME, TE et durée maximale d'un job du projet, usage personnel, coûts saisis (BPO, matériaux, transport, taxe, prix de vente), composants exclus, matériaux d'invention, estimations (job, matériaux, prix de vente, source, taxe), date de début des jobs. `takeProductFromFirstItem()` disparaît. Restent : nom, notes (D6), statut, `private`, dates, et les champs du groupe.
- **R4 — Une seule famille de routes.** Tout passe par `/industry/projects` et `/industry/projects/{id}/...` (table : note de câblage) ; le barème de location de lignes passe à `/industry/line-rental-rates`. `/group-industry/...` et les routes d'étapes n'existent plus : 404 du routeur.
- **R5 — Plus de prédicat de route.** `isPlannedBySteps()`, `isPlannedByStepsAndOwnedBy()` et les filtres `items IS [NOT] EMPTY` disparaissent.
- **R6 — Mes projets.** `GET /industry/projects` liste les projets où l'utilisateur est participant `accepted`, propriétaire compris, avec `myRole` et `myStatus`.
- **R7 — Accès.** `GroupProjectAccessChecker` sur toutes les routes, tel qu'appliqué aujourd'hui au groupe. Participation (lire projet, BOM avec jobs et achats liés, distribution, ventes, participants, apports ; déposer un apport) : propriétaire ou participant `accepted`. Administration (modifier, revoir, vendre, container, gérer les participants) : propriétaire ou admin `accepted`. Suivi des jobs et des achats (R12, R13) et suppression : propriétaire seul.
- **R8 — Cycle de vie.** Transitions : `draft → published`, `draft → in_progress`, `published → in_progress → selling → completed`. Les autres sont refusées (400, message actuel). `active` et la réouverture d'un `completed` disparaissent ; `completedAt` est posé au passage à `completed`.
- **R9 — Modification.** `PATCH /industry/projects/{id}` (`application/merge-patch+json`) : nom, notes, container, frais, statut, `private`. Les règles de la spec #152 restent ; `private` se modifie dans tout statut.
- **R10 — Projet privé.** Un projet `private` n'est jamais listé en découverte ; l'adhésion par son code répond 404 « Project not found », comme un code inconnu (D9). Passer `private` à `false` ne change que la découverte et l'adhésion ; le repasser à `true` garde les participants en place (D10). « Solo » reste un état : un seul participant `accepted`, quel que soit `private`.
- **R11 — Découverte et adhésion.** La découverte liste les projets `published` et non privés de la corporation du propriétaire ; l'adhésion par code est ouverte en `published` et `in_progress` hors projet privé (acceptation d'office dans la même corporation, sinon `pending`, inchangé).
- **R12 — Jobs ESI sur les lignes de BOM.** Rebranchement du suivi des étapes : un job lié l'est à une ligne de job de la BOM (lien `IndustryProjectJobMatch`). Le matching automatique parcourt les lignes de fabrication et de réaction, cherche les jobs des personnages du propriétaire sur le blueprint producteur du produit de la ligne, démarrés depuis la création du projet, ni annulés ni liés à un autre projet ; il prend les jobs dont les runs tiennent dans le reste de la ligne, puis au plus un job plus gros. Une ligne qui a un lien manuel garde ses jobs et n'est pas recalculée. Le lien manuel exige un job d'un personnage du demandeur, de même blueprint et de même activité que la ligne. La ligne expose ses jobs liés et `matchedRuns` (somme de leurs runs) ; `fulfilledQuantity` ne bouge pas.
- **R13 — Achats wallet sur les lignes de BOM.** Les suggestions listent les achats wallet des personnages du demandeur sur les produits des lignes de composant, ou, s'il n'y en a pas, sur ceux des lignes de matériau (règle des étapes de profondeur non nulle) ; un achat lié à un autre projet est masqué, un achat lié à ce projet est marqué `alreadyLinked`. Un achat (transaction ou saisie manuelle) se lie à une ligne de BOM (`IndustryProjectPurchase`) ; la ligne expose ses achats et `purchasedQuantity`, sans effet sur `fulfilledQuantity` ni sur les apports.
- **R14 — Apport sans revue en solo.** Un apport du propriétaire d'un projet sans autre participant `accepted` est `approved` au dépôt (`reviewedBy` = propriétaire, ligne servie de la quantité), valorisé selon la spec #154 jusqu'à #363. Sinon `pending`, inchangé.
- **R15 — Suppression.** `isDeletable()` pour tout projet (400 si `completed`, apport `approved` ou vente) ; 403 hors propriétaire. Jobs et achats liés partent avec le projet.
- **R16 — Mercure.** Topics inchangés ; le jeton porte `/group-project/{id}/events` pour chaque projet où l'utilisateur est `accepted`. Un job lié qui se termine publie `industry-project` au propriétaire, avec `projectId` et le nom du produit de la ligne.
- **R17 — Renommage.** `GroupIndustry*` et `Group*` prennent les noms du projet industriel ; messages, topics et événements Mercure inchangés.

## Exemples (vérifiables par test)

U et V (corporation C1), W (C2). Produits A (5 runs, ME 10, TE 20) et B (3 runs). Ligne de matériau L (prix BOM 1 000 ISK). Ligne finale FA de A (5 runs).

| # | Action | Résultat attendu |
|---|---|---|
| E1 | U crée P1 avec A, sans `private` | 201 ; `draft`, `private` vrai ; 1 participant `owner`/`accepted` ; BOM du golden ; code de 10 caractères |
| E2 | U crée P2 avec A et B, `private` faux ; puis un projet sans produit ou à 11 produits | P2 : 2 produits, BOM agrégée du golden ; les deux autres : 400, rien persisté |
| E3 | U liste ses projets | P2 puis P1, `owner`/`accepted` |
| E4 | W lit P1 ou sa BOM ; UUID inconnu | 403 « You do not have access to this project » ; 404 « Project not found » |
| E5 | `GET /group-industry/projects`, `POST /industry/projects/{P1}/steps`, `GET .../shopping-list`, `.../bpc-kit` | 404 |
| E6 | P1 `draft` vers `published` ; P2 `draft` vers `in_progress` ; un `draft` vers `selling` | 200 ; 200 ; 400 « Cannot transition from "draft" to "selling" » |
| E7 | V liste la découverte : P1 `published` privé, P2 `in_progress`, P3 `published` non privé | P3 seul |
| E8 | V rejoint P1 (privé, `published`) par son code | 404 « Project not found » |
| E9 | U passe P1 à `private` faux ; V le rejoint ; W le rejoint | V `accepted` ; W `pending` |
| E10 | U repasse P1 à `private` vrai | 200 ; V garde son accès ; P1 sort de la découverte ; un nouveau code : 404 |
| E11 | V rejoint P1 en `draft` non privé | 400 « This project is no longer accepting new members » |
| E12 | U dépose 10 unités sur L de P2 | 201, `approved`, 10 000 ISK, L servie de 10 |
| E13 | V `accepted` dans P1 ; U y dépose 10 unités | `pending`, ligne inchangée |
| E14 | U supprime P2 après E12 ; un `draft` sans apport ni vente | 400, conservé ; 204 |
| E15 | Personnages de U : jobs de A de 3 et 2 runs démarrés après la création de P1, un de 4 runs démarré avant ; match | FA liée aux deux premiers, `matchedRuns` 5, `fulfilledQuantity` 0 |
| E16 | Jobs de A de 3 puis 7 runs, rien d'autre ; match | 3 runs lié, puis 7 runs en job plus gros ; `matchedRuns` 10 |
| E17 | Un job déjà lié à P2 ; match sur P1 | non lié à P1 |
| E18 | U lie à FA un job d'un autre blueprint ; un job de V ; un job déjà lié | 400 pour chacun (messages de `LinkJobProcessor`, « step » remplacé par « BOM line ») |
| E19 | Lien manuel sur FA, puis match | FA garde son seul job manuel |
| E20 | Le job lié de 3 runs passe `delivered` à la sync | statut copié sur le lien ; `syncCompleted` `industry-project` à U avec `projectId` de P1 et le nom de A |
| E21 | Achats de U : 50 du composant C (ligne de composant de P1) et 1 000 Tritanium (ligne de matériau) | suggestion : C seul |
| E22 | U lie l'achat de C à sa ligne ; relit les suggestions ; V les lit sur P2 où l'achat n'est pas lié | `purchasedQuantity` 50, `fulfilledQuantity` inchangé ; `alreadyLinked` vrai ; masqué |
| E23 | V (admin `accepted`) lance le match, lie un job ou un achat sur P1 | 403 |
| E24 | Matrice A1 à A13 de la spec #162 sur P2 et sur P1 (U seul) | codes et messages en vigueur (A5 à A7 : 403, la lecture par la corporation a été retirée) ; P1 : U 200 partout, V et W 403 |
| E25 | Jeton Mercure de U | `/group-project/{P1}/events` et `/group-project/{P2}/events` |
| E26 | Migrations sur une base avec P2 et une ligne dans chaque table d'étapes | tables d'étapes supprimées ; tables de liens de jobs et d'achats créées ; P2 gardé ; colonnes de R3 absentes, `private` présente |

## Cas limites

- **Ligne reconstruite** (#253) : un lien suit sa ligne, supprimé avec elle (cascade).
- **Projet de groupe** : seuls les jobs et achats du propriétaire sont suivis ; ceux des autres participants restent des apports déclarés (#363).
- **Lien de structure** : la correction automatique de la structure d'une étape par le lieu du job disparaît (aucune structure sur une ligne de BOM).
- **Apport en solo** : le premier apport rend le projet non supprimable (R14 + R15).
- **Propriétaire sans corporation connue** : accès par identité (spec #162) ; ses projets ne sont découverts par personne.
- **Compte supprimé** : `AccountDeletionService` inchangé.

## Accès et fraîcheur

- `{id}`, `{projectId}` : UUID, sinon 404 ; projet inconnu : 404 avant tout contrôle (spec #162, R4). Ligne de BOM, lien de job, achat, participant, apport, vente : rattachés au projet de l'URI, sinon 404.
- Job ESI : d'un personnage du demandeur, sinon 400 (inchangé) ; transaction wallet : d'un personnage du demandeur, sinon 404.
- Corporation : `User::getCorporationId()` (personnage principal), rafraîchie à la connexion seulement (`AuthenticationService`). Elle ne sert qu'à la découverte et à l'acceptation d'office ; défaut préexistant gardé tel quel.

## Tests existants contredits ou modifiés

Par fichier dans `.claude/tasks/362-lot2-wiring.md`. Environ 85 fichiers de tests des étapes sont supprimés avec leur cible (goldens de la factory, de la liste d'achats et de l'arbre, commandes ponctuelles, estimateur, kit BPC). `IndustryJobMatcherTest`, `LinkJobProcessorTest`, `PurchaseSuggestionProviderTest`, `IndustryJobSyncServiceTest` et le test fonctionnel des jobs et achats sont réécrits sur les lignes de BOM. Les tests de garde du lot 1, `TokenProviderTest`, les tests de statut de création et de découverte, et les URI fonctionnelles du groupe changent.

## Hors périmètre

Front (lot 3) ; navigation (#378) ; valorisation au prix réel et apports rattachés d'office (#363) ; chaîne et arbre (#337) ; kit BPC (#384) ; cascade revenu et profit (#346) ; planning réel contre prévu (#338) ; modification des produits ; jobs et achats des participants autres que le propriétaire ; renommage des topics Mercure.

## Glossaire (proposé, `docs/glossary.md` non modifié)

- **Projet industriel** : « … 1 à n produits et une BOM plate calculée à la création ; solo et groupe ne diffèrent que par l'accès. »
- **Projet solo** (Solo project) : état d'un projet dont le propriétaire est le seul participant `accepted` ; ses apports se passent de revue. Ni type ni colonne.
- **Projet privé** (Private project) : projet absent de la découverte et fermé à l'adhésion par code. `IndustryProject::$private`.
- **Job lié** (Matched job) : job ESI d'un personnage du propriétaire rattaché à une ligne de job de la BOM. `IndustryProjectJobMatch`.
- **Achat lié** (Linked purchase) : achat wallet ou saisi rattaché à une ligne de BOM. `IndustryProjectPurchase`.
- **Étape** et ses dérivés : à retirer ou marquer « supprimé au lot 2 de #362 ». **Contribution** : « soumise à revue, sauf en projet solo ». **Cycle de vie** : « démarre en `draft`, qui mène à `published` ou `in_progress` ».

## Décisions à confirmer

- **D5** — Étranger sur un projet solo : 403 comme en groupe (spec #162, Q3), plus 404.
- **D6** — Notes gardées, exposées et modifiables.
- **D7** — Migrations irréversibles (`down()` lève), sans purge de lignes de projet.
- **D8** — `private` vaut `true` par défaut à la création (colonne `NOT NULL DEFAULT true`).
- **D9** — Adhésion par code à un projet privé : 404, comme un code inconnu, pour ne pas révéler le projet.
- **D10** — Repasser un projet en privé garde ses participants et leurs apports.
- **D11** — Suivi des jobs et des achats réservé au propriétaire (403 pour un admin), comme sur les étapes.
- **D12** — Ligne d'invention : pas de lien de job (400, activité différente), le producteur d'un produit T2 n'est pas son blueprint inventé.
- **D13** — Chevauchement avec #363 : jobs et achats liés n'alimentent ni `fulfilledQuantity` ni les apports ; #363 décidera de les convertir en apports au prix réel.
