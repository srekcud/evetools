# #336 — Cockpit de projet industriel : phases, prochaine action, onglets

Issue #336 (circuit:l, group:unified-industry). Maquette : `industry-project-v3.html#p=3`, `#p=7` (notes 3 et 4). Niveau de technicité attendu : 3 (code Industry, nouvelle ressource API sur un projet, assertions de specs front modifiées).

## Objectif
Une page `/industry/projects/{id}` qui dit où en est le projet : rail de cinq phases avec compteur, phase bloquée en ambre avec sa raison, **une** prochaine action pour la personne connectée, onglets. Les écrans existants du détail de projet sont réutilisés ; aucun écran, réglage ni route n'est retiré.

## Vocabulaire (à ajouter au glossaire)
**Phase** (Préparer, Approvisionner, Produire, Vendre, Clôturer) : étape du rail du cockpit, calculée côté serveur, distincte du **cycle de vie** (statut). **Prochaine action** : l'unique chose à faire maintenant pour la personne connectée.

## API (R1 à R4)
- **R1** `GET /industry/projects/{projectId}/cockpit`, ressource `ProjectCockpitResource`, `uriVariables` avec `Link` vers `IndustryProjectResource`. Accès : mêmes règles et mêmes refus que `ProjectBomProvider` (`ProjectAccessChecker::assertAcceptedMember`, 401, 404 projet inconnu, 403 sinon ; projet privé respecté, pas de fuite).
- **R2** Réponse : `projectId`, `status`, `phases` (liste de 5 : `key` ∈ `prepare|supply|produce|sell|close`, `state` ∈ `done|current|blocked|upcoming`, `counter {done, total}`, `blockedReason` nullable `{code, count}`), `nextAction`.
- **R3** Calcul dans une classe pure, sans accès base, testable seule (ex. `ProjectCockpitCalculator` dans `src/Service/Industry/Project/`), alimentée par un provider qui lit le projet, ses lignes de BOM (quantité requise, livrée, `isJob`, `jobGroup`, runs, runs liés), les ventes (quantité par type) et le nombre de contributions `pending`. Aucune valeur par défaut plausible : une donnée absente (runs `null`) devient `null` dans la réponse.
- **R4** La personne connectée est « gestionnaire » si son rôle est `owner` ou `admin` (même règle que le front, `isOwnerOrAdmin`).

## Phases (R5 à R8)
Index de phase courante selon le cycle de vie : `draft` → `prepare`, `published` → `supply`, `in_progress` → `produce`, `selling` → `sell`, `completed` → tout `done`.
- **R5** État : avant la courante → `done` ; la courante → `current` ; après → `upcoming`. Exception `blocked` (R7).
- **R6 Compteurs** (`done/total`) : `prepare` = produits du projet (done = total si statut ≠ `draft`, sinon 0) ; `supply` = lignes de matériau (non job) couvertes (`fulfilledQuantity ≥ requiredQuantity`) / lignes de matériau ; `produce` = lignes de job livrées (même règle) / lignes de job (copie, invention, composant, final) ; `sell` = unités vendues des produits finaux (ventes des types des lignes `final`) / unités à vendre (somme des `requiredQuantity` des lignes `final`) ; `close` = 1 si `completed` sinon 0, sur 1. Une somme à 0 donne `0/0`.
- **R7 Bloquée** (statut < `completed` seulement) : `supply` est `blocked` avec `{code: materials_missing, count: lignes non couvertes}` quand le statut est `in_progress` ou `selling` et qu'une ligne de matériau n'est pas couverte ; `produce` est `blocked` avec `{code: jobs_unfinished, count: lignes de job non livrées}` quand le statut est `selling` et qu'une ligne de job n'est pas livrée. Ailleurs `blockedReason` vaut `null`.
- **R8** Un projet `completed` : cinq phases `done`, aucun blocage, même si des lignes restent non couvertes.

## Prochaine action (R9 à R11)
Première règle qui s'applique, dans cet ordre ; chaque action a un `code`, un drapeau `blocked`, un `tab` cible (`materials|contributions|sales|finances|members`) et selon le cas `targetStatus`, `bomLineId`, `typeId`, `typeName`, `quantity`.
1. `completed` → `project_completed`.
2. gestionnaire et contributions `pending` > 0 → `review_contributions` (`quantity` = nombre, tab `contributions`).
3. `draft` : gestionnaire → `start_project` (`targetStatus: in_progress`) ; sinon `nothing_to_do`.
4. `published` ou `in_progress` avec une ligne de matériau non couverte → `source_material` pour la ligne au plus grand reste à couvrir (égalité : nom de type croissant), `quantity` = ce reste, tab `materials`, `blocked: true` si `in_progress`, sinon `false` (tous les participants).
5. `published` (matériaux couverts) : gestionnaire → `start_project` ; sinon `nothing_to_do`.
6. `in_progress` avec une ligne de job non livrée → `launch_job` pour la première dans l'ordre `jobGroup` blueprint, component, final puis nom de type ; `quantity` = runs restants (`runs − runs liés`, `null` si `runs` est `null`), tab `materials` ; tous les participants.
7. `in_progress`, jobs tous livrés : gestionnaire → `start_selling` (`targetStatus: selling`) ; sinon `nothing_to_do`.
8. `selling` : unités à vendre restantes > 0 → gestionnaire `record_sale` (`quantity` = restant, tab `sales`), sinon `nothing_to_do` ; restant = 0 → gestionnaire `complete_project` (`targetStatus: completed`), sinon `nothing_to_do`.
- **R10** `nothing_to_do` : `blocked: false`, aucun tab. Jamais deux actions.
- **R11** Écart avec la maquette : la chaîne de production (#337) n'existe pas, la règle se fonde sur la BOM, les jobs liés et le cycle de vie. Pas de minuteur, pas de « lancer depuis tel hangar ».

## Front (R12 à R19)
- **R12** Route `/industry/projects/:id`, nom `industry-project`, vue `GroupIndustryDetail.vue` (nom de fichier et dossiers gardés, décision #362). `/group-industry/:id` reste joignable : redirection vers la nouvelle route (params et query conservés). `GroupIndustryList` et `GroupIndustryJoin` poussent la nouvelle route. `/group-industry`, `/join/:code` inchangées. La navigation (Industrie active, titre) fonctionne sur `/industry/projects/:id`.
- **R13** Rail : cinq cellules en bandeau HUD (libellé, compteur `done / total`, coche si `done`, ambre si `blocked` avec la raison en clair, accent si `current`) ; libellés longs tronqués sans casser la grille, mobile en défilement horizontal. Raisons traduites FR/EN : `materials_missing` (« 3 matériaux à couvrir »), `jobs_unfinished`.
- **R14** Carte « Prochaine action » sous le rail : titre et phrase par `code` (FR/EN, nom du type et quantité insérés), bouton primaire : `targetStatus` → même flux que les boutons de statut actuels (confirmation pour `completed`) ; sinon change d'onglet (`tab`) ; `source_material` ouvre la modale de contribution préremplie sur la ligne si elle est dans la BOM chargée. Ambre si `blocked`. `nothing_to_do` : phrase neutre, sans bouton.
- **R15 Onglets** (barre unique) : `Matériaux` (BOM + jobs, existant), `Apports` (existant), `Finances` (`FinancialSummary` + `DistributionTable`), `Ventes` (`SalesTable` + `SaleSuggestionsPanel`), `Équipe` (existant, **toujours visible** : c'est là que vit le lien d'invitation, on ne retire rien à un projet solo). Query `?tab=` : `bom`, `contributions`, `distribution` (= Finances), `sales`, `members`. Défaut `bom`.
- **R16 À venir** : `Chaîne`, `Invention`, `Planning`, `Journal` apparaissent en onglets désactivés avec la mention « À venir » (sources : #337, #338, #321), sans panneau ni donnée.
- **R17** Mercure : tout événement de projet (`getGroupProjectEvent`, hors `member_*`) et la progression `industry-project` rechargent aussi le cockpit (`fetchCockpit`), avec le flash existant.
- **R18** Chargement : le cockpit se charge avec le détail et la BOM ; en erreur de cockpit, la page reste utilisable (rail et carte absents, message discret), jamais de valeur inventée. Le store garde `cockpit` et `fetchCockpit(projectId)` (store `group-industry/project.ts`, type `ProjectCockpit`).
- **R19** Design HUD EVE (chamfer, accent cyan, ambre d'avertissement), noms de projet et de type longs testés FR et EN, pas de commentaire.

## Hors périmètre
Chaîne, Invention, Planning, Journal (onglets vides), file d'actions de l'Atelier, compagnon, alerte prix, renommage des dossiers `group-industry`, retrait de tout écran ou réglage, nouvelle migration (aucune), nouvelle commande console (aucune), nouvelle dépendance (aucune).

## Exemples chiffrés
| Cas | Résultat |
|---|---|
| `in_progress`, 5 matériaux dont 2 non couverts (PAU reste 50, Robotics reste 10) | `supply` bloquée `materials_missing` ×2, compteur 3/5 ; action `source_material` PAU, 50, `blocked` |
| `in_progress`, matériaux couverts, 6 jobs dont 1 livré | `produce` courante 1/6 ; action `launch_job` (jobGroup blueprint d'abord) |
| `selling`, 100 unités finales, 60 vendues | `sell` 60/100 ; gestionnaire `record_sale` 40 ; membre `nothing_to_do` |
| `selling`, 100/100 vendues | gestionnaire `complete_project` |
| `selling`, 1 job non livré | `produce` bloquée `jobs_unfinished` ×1 |
| `draft`, membre simple | `prepare` courante 0/2, `nothing_to_do` |
| `completed`, 1 matériau non couvert | 5 phases `done`, `project_completed` |
| 2 contributions `pending`, gestionnaire, `published` | `review_contributions` ×2 avant `source_material` |

## Cas limites
Zéro ligne de matériau (`supply` 0/0, jamais bloquée) ; `runs` `null` sur un job (runs restants `null`) ; vente d'un type qui n'est pas un produit final (ignorée du compteur) ; ventes au-delà du requis (restant plafonné à 0).

## Décisions prises en autonomie (à reporter au journal)
D1 phase courante = statut, les données ne font que compter et bloquer ; D2 Équipe toujours visible ; D3 onglets absents = désactivés « À venir » ; D4 Finances et Ventes séparées (l'onglet `distribution` regroupait les deux) ; D5 pas de nouvelle migration, calcul à la lecture.
