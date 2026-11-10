# Spec #322 : compagnon compact du projet collaboratif

Maquettes retenues : `group-industry-v1.html` étapes 17, 18, 30 (contenu des sections, 6 lignes puis « + N autres lignes libres · voir la BOM ») et `industry-project-v3.html` `#v=compagnon` (barre et colonne, alertes sans son). La v3 prime en cas d'écart. Mode nuit : spec validée en autonomie. Front seul : aucune route ni migration, tout vient de la BOM (#320, #319), du détail du projet, des membres et du topic `/group-project/{id}/events`.

## Contexte vérifié sur origin/main

- `BomItem` porte `reservations` (`isMine`), `reservedQuantity`, `freeQuantity`, `isFulfilled`, `jobMatches` (`status`, `endDate`, `runs`, `characterName`, `locations`) ; `reserveLine` / `releaseLine` du store `group-industry/project.ts` appellent `POST|DELETE /industry/projects/{id}/bom/{lineId}/reservation`.
- `GroupProject` porte `fulfillmentPercent`, `pendingContributionCount` (admins), `myRole`, `myStatus`, `containerName` ; les demandes d'adhésion sont les membres `status = pending` de `distribution.fetchMembers`.
- Mercure : `syncStore.getGroupProjectEvent(projectId)` ; table action → rafraîchissements dans `views/GroupIndustry/groupProjectRefreshes.ts`.
- Aucune fenêtre détachée n'existe dans le front ; #304 n'a pas poussé la sienne.

## Règles

- **R1 — Fenêtre détachée** (`composables/useDetachedWindow.ts`, fichier dédié, aucun import du minage). `open({ width, height })` : Document Picture-in-Picture (`window.documentPictureInPicture.requestWindow`) si l'API existe, sinon `window.open` en popup (`popup=yes`, largeur, hauteur) ; renvoie `{ window, kind: 'pip' | 'popup' }` ou `null` si le navigateur refuse. Les feuilles de style de la fenêtre principale (`<link rel=stylesheet>`, `<style>`) sont clonées dans la nouvelle fenêtre. `isOpen` repasse à `false` à la fermeture par l'utilisateur (`pagehide`). `resize(width, height)` redimensionne. Une seule fenêtre à la fois.
- **R2 — Contenu** (`companionTasks.ts`, fonction pure `buildCompanionTasks`). Entrées : lignes de BOM, projet, membres, noms des personnages de l'utilisateur, `now`. Sorties : `free`, `mine`, `toReview`, `joinRequests`, `jobs`, `bomPercent`, `finalJobsRunning`, `alerts`.
  - **À attribuer** : ligne non complète avec `freeQuantity > 0` ; une ligne de job qui a déjà un job lié n'est pas libre. Ordre de la BOM. L'affichage montre 6 lignes puis « + N autres lignes libres · voir la BOM » (N = reste, lien vers l'onglet BOM du projet dans la fenêtre principale). Libellé : matériau = quantité libre et valeur estimée (« inconnu » si `estimatedPrice` est `null`, jamais 0) ; job = activité et runs.
  - **Mes lignes à livrer** : lignes non complètes où l'utilisateur a une réservation (`isMine`), avec sa quantité réservée.
  - **À faire** : `toReview` = `pendingContributionCount` du projet (visible seulement si le champ n'est pas `null`, donc admins) ; `joinRequests` = nombre de membres `pending` (admins seulement).
  - **Mes jobs (ESI)** : jobs liés dont `characterName` est un personnage de l'utilisateur et dont le statut est `active`, `paused` ou `ready` ; statut, runs, et lieu de livraison (`locations.output.name`, sinon « inconnu » avec sa raison, `locations` absent = pas de ligne de lieu, jamais inventé).
  - **Projet** : `bomPercent` = `fulfillmentPercent` ; `finalJobsRunning` = jobs liés `active` des lignes `jobGroup = final`.
- **R3 — Actions.** « Je m'en charge » appelle `reserveLine(projectId, lineId, freeQuantity)` (toute la quantité libre) ; « Libérer » appelle `releaseLine`. « Livrer » ne contribue pas dans la fenêtre détachée : il ramène la fenêtre principale (`window.opener` ou onglet de l'application) sur `/industry/projects/{id}?tab=bom&contribute={lineId}` ; `GroupIndustryDetail` ouvre alors `ContributeModal` pré-rempli sur cette ligne (comme `openContributeModal(item)`) puis retire `contribute` de l'URL. Ligne inconnue : rien ne s'ouvre, pas d'erreur. Une action en échec affiche une ligne d'erreur, jamais un succès silencieux.
- **R4 — Alertes visuelles, sans son.** `job_ready` : un de mes jobs liés `ready`, ou `active` dont `endDate` est passée (nom, runs, personnage, « à livrer »). `bom_complete` : au moins une ligne de matériau et toutes complètes. `join_request` : au moins une demande (admins). Chaque alerte a une clé stable (`job_ready:{matchId}`, `bom_complete`, `join_request`) ; quand une clé apparaît, le bord de la fenêtre pulse 3 fois (animation CSS `animation-iteration-count: 3`, ambre pour job et demande, vert pour BOM complète), aucun son, aucun `Audio`. `prefers-reduced-motion` : pas de pulsation, l'alerte reste affichée. **Sortie hors container** : l'alerte « déplacer N produits » est #506, hors périmètre ; seul le lieu de livraison de « Mes jobs » est affiché.
- **R5 — Deux tailles.** Barre (≈ 440 × 72) : nom du projet, voyant « direct », BOM %, compteurs (à attribuer, à livrer, à revoir), pastille de l'alerte la plus prioritaire. Colonne (≈ 360 × 640) : toutes les sections. Un bouton bascule et redimensionne la fenêtre ; la taille choisie est gardée par utilisateur (`localStorage`, lecture et écriture protégées par `try/catch`, repli colonne).
- **R6 — Temps réel.** Le compagnon garde ses propres données (store `group-industry/companion.ts`, indépendant de `currentProject` et `bomItems` de la page détail) et se rafraîchit à chaque événement Mercure du projet : `bom` et `projectDetail` toujours, `members` pour `member_*` (admins). Il ne dépend pas de la page ouverte dans la fenêtre principale. Aucun nouveau topic : le topic projet existe, aucune action async nouvelle.
- **R7 — Ouverture et accès.** Bouton « Compagnon » dans l'en-tête de `GroupIndustryDetail`, pour un membre accepté d'un projet non terminé ; `GroupCompanionHost` (monté dans `App.vue`) ouvre la fenêtre et rend `GroupCompanion.vue` dedans (Teleport). Projet terminé ou membre non accepté : le bouton est absent, et le store ferme la fenêtre si le projet devient `completed`. Les données sont celles que l'API renvoie déjà au demandeur (aucun contrôle d'accès nouveau).
- **R8 — Libellés et direction.** FR et EN (`groupIndustry.companion.*`), direction HUD EVE, noms de projet, de type et de personnage très longs tronqués avec titre complet.

## Exemples chiffrés (tests rouges)

| # | Entrée | Sortie |
|---|---|---|
| E1 | Nocxium `freeQuantity` 80 non complète ; Tritanium complète ; Morphite `freeQuantity` 0 (tout réservé) | `free` = [Nocxium] |
| E2 | Ligne de job Hammerhead II avec un job lié | absente de `free` |
| E3 | 9 lignes libres | `free` 9 ; l'affichage montre 6 lignes et « + 3 autres lignes libres · voir la BOM » |
| E4 | Réservation `isMine` de 50 sur Nocxium, une autre de 20 d'un autre | `mine` = [Nocxium × 50] |
| E5 | Matériau `estimatedPrice` `null` | libellé « inconnu », jamais 0 |
| E6 | Job lié `ready` du personnage « Alpha » (personnage de l'utilisateur) et job `ready` de « Zeta » (autre joueur) | `jobs` = [Alpha], alerte `job_ready:{matchId}` pour Alpha seulement |
| E7 | Job `active`, `endDate` passée de 1 min | alerte `job_ready` ; `endDate` dans 1 h : pas d'alerte |
| E8 | 13 matériaux sur 13 complets | alerte `bom_complete` ; 12 sur 13 : pas d'alerte ; aucun matériau : pas d'alerte |
| E9 | `pendingContributionCount` 3, 2 membres `pending`, utilisateur admin | `toReview` 3, `joinRequests` 2, alerte `join_request` |
| E10 | Utilisateur simple membre (`pendingContributionCount` `null`) | « À faire » absent, aucune alerte `join_request` |
| E11 | Clic « Je m'en charge » sur Nocxium (`freeQuantity` 80) | `POST …/bom/{id}/reservation` `{ quantity: 80 }`, puis rafraîchissement |
| E12 | Clic « Livrer » sur Nocxium | fenêtre principale sur `?tab=bom&contribute={id}` ; `ContributeModal` ouvert sur Nocxium |
| E13 | Événement Mercure `line_reserved` | la BOM du compagnon est rechargée |
| E14 | `documentPictureInPicture` absent | `window.open` appelé avec `popup=yes`, `kind = 'popup'` |
| E15 | `window.open` renvoie `null` | `open` renvoie `null`, `isOpen` reste `false` |

## Cas limites

- Pop-up bloquée ou Document PiP refusé (pas de geste utilisateur) : message sous le bouton, rien d'ouvert.
- L'utilisateur ferme la fenêtre : `isOpen` faux, le store arrête de rafraîchir. Quitter la page de l'application ferme la fenêtre.
- Réservation refusée par l'API (quantité prise entre-temps) : erreur affichée, BOM rechargée.
- Aucune ligne libre : « Toutes les lignes ont un responsable. » ; rien à livrer : « Rien à livrer. »

## Tests existants contredits ou modifiés

Aucun attendu. À vérifier avant de figer le rouge (5a) : tests de `App.vue`, listes exhaustives de clés i18n ou de boutons de l'en-tête de `GroupIndustryDetail`.

## Hors périmètre

Alerte « déplacer N produits » et vérifications en jeu des lieux (#506) ; compagnon du minage (#304) et mise en commun des deux composables de fenêtre après fusion ; alerte prix de la v3 (Jita sous le seuil), ordres sous-cotés, slots libres, Multibuy et « C'est fait » de la v3 (autres issues) ; notification push ; son.

## Termes du glossaire

**Compagnon de projet** (Project companion) : fenêtre détachée toujours au-dessus du jeu (Document Picture-in-Picture, repli popup), en deux tailles, qui montre au participant connecté les lignes à attribuer, ses lignes à livrer, ce qui est à revoir, ses jobs ESI et l'avancement, et pulse sans son à chaque alerte (#322).

## Décisions ouvertes

1. Mise en commun de `useDetachedWindow` avec celui de #304 après fusion des deux PR (rien à faire dans ce lot).
2. « Je m'en charge » réserve toute la quantité libre ; choisir une quantité reste dans la BOM.
