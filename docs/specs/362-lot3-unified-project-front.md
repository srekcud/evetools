# Spec #362, lot 3 : le front sur l'API unifiée du projet industriel

## Contexte

Le lot 2 a supprimé `/api/group-industry/*` et toutes les routes d'étapes ; tout passe par `/api/industry/projects...` et `/api/industry/line-rental-rates` (spec lot 2). Le front appelle encore les anciennes routes (stores `group-industry/*`, `industry/projects|steps|purchases`, palette de commandes) et porte un module solo par étapes (onglet « Projets » de `/industry`, `ProjectTable`, `ProjectDetail`, `StepsTab`, `StepTree`, `ShoppingTab`, `CostEstimationTab`, `BpcKitTab`). Une release unique : rien à préserver. Le module de projet est celui de `/group-industry` (vues, composants, stores gardent leurs noms, renommage hors lot). Navigation (#378), prix réel (#363), chaîne et arbre (#337), kit BPC (#384), cascade (#346), planning (#338) : hors lot.

## Règles

- **R1 — Plus aucun appel retiré.** Aucun appel front à `/group-industry`, `/industry/projects/{id}/steps`, `shopping-list`, `cost-estimation`, `bpc-kit`, `copy-costs`, `link-job` d'étape ni `purchases` d'étape. Les stores `group-industry/project|contribution|distribution` ciblent `/industry/projects...` (liste, disponibles, détail, BOM, vérification du container, création, PATCH, DELETE, `join/{code}`, apports, ventes, suggestions de vente, membres, `leave`, distribution) et `/industry/line-rental-rates`.
- **R2 — Liste.** `GET /industry/projects` renvoie un tableau (comme l'ancienne liste de groupe), plus l'enveloppe `{projects}` du solo. Le type `GroupProject` gagne `private`, `notes`, `acceptsOutsideFavoriteSystem`, `minimumOutsideSaving` ; `BomItem` gagne `jobMatches`, `matchedRuns`, `purchases`, `purchasedQuantity`.
- **R3 — Création.** `NewProjectModal` envoie `private` (défaut `true`). La case « Projet privé » est cochée par défaut, avec une aide (« absent de la découverte de la corporation, sans adhésion par code »). Décochée, le corps envoie `private: false`.
- **R4 — Confidentialité visible et modifiable.** Un projet privé affiche un badge « Privé » (en-tête du détail, carte de liste). Le propriétaire et les admins basculent `private` dans la modale de modification (PATCH `{private}`), dans tout statut.
- **R5 — Cycle de vie.** Un projet naît en `draft`. Depuis `draft`, le détail propose deux actions (Publier → `published`, Démarrer → `in_progress`) ; ensuite `published → in_progress → selling → completed` (confirmation conservée pour `completed`). `nextProjectStatus` devient `nextProjectStatuses(status)` (liste) ; `draft` n'est plus un cul-de-sac. Le filtre de statut de la liste ajoute `draft`.
- **R6 — Projets du solo retirés.** L'onglet « Projets » de `/industry` et sa nav (`useNavigationEntries`, `MAIN_TABS`, `NavigationEntryTabsContract`), le formulaire de création solo, `ProjectTable`, `ProjectDetail`, `StepsTab`, `StepTree`, `StepHierarchyTree`, `ShoppingTab` + `shopping/*`, `CostEstimationTab`, `BpcKitTab`, les stores `industry/steps.ts` et `industry/purchases.ts`, et dans `industry/projects.ts` tout sauf recherche de produit, type fabricable et analyse de marge, disparaissent avec leurs types, composables orphelins et clés i18n. Les anciens tests de ces éléments sont supprimés avec eux.
- **R7 — Points d'entrée « créer un projet ».** `NavigationIntent` `projects` ouvre `/group-industry` : `{openCreateModal}` ouvre la modale vide ; `{prefill}` l'ouvre avec le produit (typeId, typeName, runs, ME, TE) comme item, et `excludedTypeIds` comme `blacklistTypeIds`. Le tableau de bord (Industry) lit ses projets récents et son compte « actifs » dans la liste unifiée (actif = statut ≠ `completed`), son bouton « projets » va à `/group-industry`, sa vue d'un projet à `/group-industry/{id}`.
- **R8 — Palette de commandes.** Une seule source de projets (`/industry/projects`), entrées vers `/group-industry/{id}`, visible si le module `group-industry` n'est pas masqué ; l'origine `industry` et son avis d'indisponibilité disparaissent.
- **R9 — Jobs liés sur les lignes de BOM (propriétaire seul).** Sur une ligne de job, le tableau des jobs affiche `matchedRuns` et les jobs liés (`jobMatches`: personnage, runs, statut) ; sur une ligne de composant ou de matériau, `purchasedQuantity`. Le propriétaire dispose d'un bouton « Associer les jobs » (POST `match-jobs`, puis rechargement de la BOM ; `failedCharacters` affichés). Pas d'UI de lien manuel, de suppression de lien ni d'achats : retiré, noté en décision.
- **R10 — Mercure.** Topics et `getGroupProjectEvent` inchangés ; un événement `industry-project` (job lié terminé) recharge la BOM et le détail.
- **R11 — Aucune valeur par défaut plausible.** Un champ `notes` ou `matchedRuns` absent de la réponse n'est pas rendu (pas de `?? 0`) ; une erreur d'API affiche son message.

## Exemples (vérifiables par test)

| # | Situation | Attendu |
|---|---|---|
| E1 | Création sans toucher à la case | POST `/industry/projects` avec `private: true` |
| E2 | Case décochée | corps avec `private: false` |
| E3 | Détail d'un projet `draft`, propriétaire | boutons « Publier » et « Démarrer » ; clic « Démarrer » : PATCH `{status: 'in_progress'}` |
| E4 | `nextProjectStatuses` : draft, published, selling, completed | `[published, in_progress]`, `[in_progress]`, `[completed]`, `[]` |
| E5 | Modale de modification : cocher puis enregistrer | PATCH `{..., private: false}` |
| E6 | Liste de 4 projets dont un `draft` | filtre « Brouillon » montre 1 ; badge « Privé » sur les projets privés |
| E7 | Store de projets : chargement de la liste, de la BOM, join par code | URL `/industry/projects`, `/industry/projects/{id}/bom`, `/industry/projects/join/{code}` |
| E8 | Store de distribution : barème de location | `/industry/line-rental-rates` |
| E9 | Palette ouverte, deux projets renvoyés | deux entrées, route `/group-industry/{id}`, un seul appel API |
| E10 | Intent `projects` + prefill (typeId 1, runs 5, ME 10, TE 20, excludedTypeIds [9]) | route `/group-industry`, modale ouverte avec 1 item (5 runs, 10, 20) et blacklist [9] |
| E11 | Tableau de bord, 3 projets dont 1 `completed` | 2 actifs ; « nouveau projet » : intent `openCreateModal` vers `/group-industry` |
| E12 | Ligne de job avec `matchedRuns` 5 et 2 jobs liés, propriétaire | 5 runs et 2 jobs affichés ; bouton « Associer les jobs » présent, absent pour un admin |
| E13 | « Associer les jobs » avec 1 personnage en échec | POST `match-jobs`, BOM rechargée, personnage et raison affichés |
| E14 | Navigation `/industry` | plus d'onglet « Projets », `?tab=projects` retombe sur le tableau de bord |
| E15 | `grep -rn "group-industry/\|/steps\|shopping-list" frontend/src` sur les appels API | aucun |

## Cas limites

- Ancien lien `/industry?tab=projects&project=...` : `useTabQuery` retombe sur `dashboard` (valeur inconnue), pas de redirection.
- Projet privé : le badge est visible de tous les participants ; seul un admin ou le propriétaire voit la bascule.
- Réponse de liste vide : état vide existant, pas d'erreur.
- `GroupProject.notes` est lu et typé ; pas d'éditeur au lot 3 (D3).
- `excludedTypeIds` absent du prefill : pas de blacklist.

## Hors périmètre

Renommage `GroupProject*` / dossiers `group-industry` ; fusion des deux entrées de barre latérale (#378) ; lien manuel de job, suppression de lien, achats et suggestions d'achat (D2) ; réglage `acceptsOutsideFavoriteSystem` / `minimumOutsideSaving` à la création (D4) ; `docs/` autres que cette spec et la carte du module.

## Décisions

- **D1** — Le module de `/group-industry` est le module unique ; l'onglet solo est supprimé plutôt que rebranché (aucune source d'étapes).
- **D2** — Le suivi des jobs est rebranché en lecture et en déclenchement du matching (R9) ; le lien manuel et les achats (route `/bom/{lineId}/purchases`, `purchase-suggestions`, `available-jobs`) n'ont pas d'écran au lot 3 : à décider avec #363.
- **D3** — `notes` : typé, pas d'éditeur.
- **D4** — `acceptsOutsideFavoriteSystem` et `minimumOutsideSaving` : le formulaire solo les posait ; le backend garde les défauts (faux, valeur par défaut) ; pas de champ au formulaire du lot 3.
- **D5** — Un module `group-industry` masqué par l'utilisateur cache aussi les projets de la palette (comportement actuel), à revoir par #378.
