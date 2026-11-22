# #1307 — Project page as a grid by station and flow state

Circuit `l`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`industry-prototype-ishtar-2026-11-21.html`, vue projet : `sgrid`, `scell`, `fold`). Front seulement : les données existent (`GET station-quantities` #1294, `GET place-actions` #1300, BOM, ventes). Câblage écrit ici, pas de note architect (aucun backend, aucune migration).

## Contexte

La page projet (`GroupIndustryDetail.vue`) est un en-tête, un rail de phases, une carte « prochaine action » et neuf onglets. Le flux la remplace par une grille : une ligne par station du plan, cinq colonnes (à apporter, sur place, en fabrication, sorti, vendu ou remis), une colonne latérale « À faire du projet ». Cartes détaillées (couleur d'activité, barre, source) = #1308 ; attendus en pointillé = #1309 ; détail de carte = #1310 ; bande ISK = #1311 ; Planning = #1312.

## Règles

R1. **Module pur `components/group-industry/projectFlow.ts`.** `buildFlowGrid({ quantities, actions, sales, project, bomItems })` renvoie `{ unavailableReason: string | null, rows: FlowRow[] }`. `FlowRow` : `key`, `kind` (`market`, `station`, `unresolved`), `locationId`, `name`, `here` (`true` si une action de ce lieu a `here === true`, sinon `null`), `cells: Record<FlowColumn, FlowCard[]>`. `FlowColumn` : `toBring`, `onSite`, `inProduction`, `out`, `done`. `FlowCard` : `key`, `typeId`, `typeName`, `quantity` (`number | null`), `state`, `unknownReason`.

R2. **Cartes issues de `station-quantities`** (une entrée par type et par station, ordre du serveur conservé) : `toBring > 0` → carte `toBring` en colonne `toBring` ; `inTransit > 0` → carte `inTransit` en colonne `toBring` ; `onSite > 0` → `onSite` en `onSite` ; `consumed > 0` → `consumed` en `onSite` ; `inProduction > 0` → `inProduction` en `inProduction` ; `out > 0` → `out` en `out`. Une quantité à 0 ne fait pas de carte.

R3. **Jamais zéro quand on ne sait pas.** Station dont `onSite`, `out` et `toBring` valent `null` : une seule carte `unknown`, `quantity` `null`, `unknownReason` repris, colonne `onSite`. `inTransit` `null` : aucune carte, aucun zéro. Type dont `stations` vaut `null` : aucune ligne, `unavailableReason` prend la raison du type (la grille affiche le bandeau d'indisponibilité, jamais une grille vide silencieuse).

R4. **Lignes.** Une ligne par `locationId` connu (nom = `stationName` ; `null` = « station sans nom ») ; les entrées `locationId = null` vont dans la ligne `unresolved` (« Lieu à choisir »), en dernier. Une action `source_material` de lieu `station` crée (ou rejoint) la ligne `market` de ce `locationId` et y pose une carte `toBuy` en colonne `onSite` (quantité de l'action) ; une action de lieu `unknown` pose la carte `toBuy` dans la ligne `unresolved` avec `unknownReason` de la place. Une action `launch_job` de lieu `station` pose une carte `toLaunch` en colonne `inProduction` de la ligne de ce lieu (créée si absente) ; lieu `unknown` : ligne `unresolved`. `review_contributions` et `review_join_requests` n'ont pas de carte (colonne latérale seulement). Ordre : lignes `market` (nom), lignes station (ordre serveur puis lieux issus des actions), `unresolved`. Une ligne vide n'existe pas.

R5. **Colonne vendu ou remis.** Pour chaque produit du projet (`buildProductSales`), si `sold > 0` : carte `sold` (quantité vendue) en colonne `done`, dans la ligne dont le `locationId` est `project.sellStructureId` (créée comme `market` avec `sellStructureName` si absente) ; sans structure de vente : ligne `unresolved`. Projet d'usage personnel : en-tête de colonne « Remis » au lieu de « Vendu », aucune carte `sold`.

R6. **Replis.** `foldCell(cards, open)` : si la cellule a plus de 3 cartes et au moins 2 cartes calmes (`onSite` ou `consumed`), les cartes calmes sont repliées derrière un bouton « + N sur place : a, b, c… » (ou « + N consommés : … » si toutes `consumed`) ; ouvert, toutes les cartes sont visibles et le bouton devient « − replier ». Jusqu'à 3 noms, puis « … ». Cellule de 3 cartes ou moins, ou moins de 2 calmes : rien de replié.

R7. **Composants.** `ProjectFlowGrid.vue` (en-têtes de colonnes, lignes étiquetées, cellules, état de repli par cellule), `ProjectFlowCard.vue` (icône du type, nom, quantité formatée, libellé d'état, bordure gauche et motif selon l'état : `inTransit` rayé, `toBuy` et `toLaunch` ambre, `unknown` rayé gris en italique avec la raison traduite), `ProjectFlowActions.vue` (colonne latérale). Palette du prototype (`--cy`, `--warn`, `--ok`, `--sky`). Libellés `groupIndustry.flow.*` en fr et en. Cartes non cliquables (détail = #1310). Pas d'onglets, pas de tableau.

R8. **Colonne latérale « À faire du projet ».** Actions de `GET /industry/projects/{id}/place-actions` (store `placeActions`, `fetchOfProject`) rangées avec `groupActionsByPlace` (`utils/todoPlaces.ts`), ordre du serveur : titre du lieu, badge « ici » si `here === true`, une ligne par action (verbe selon le code : lancer, acheter, relire les dépôts, accepter les demandes ; icône, nom du type, quantité). Aucune action : message vide. Erreur de lecture : message d'erreur, jamais la liste vide. Pas de bouton d'action : l'action se fait en jeu, la colonne dit où et quoi.

R9. **Page.** `GroupIndustryDetail.vue` : en-tête, bandeau d'alerte de prix, puis grille et colonne latérale (`xl:grid-cols-[1fr_320px]`, une colonne en dessous). Retirés : `ProjectPhaseRail`, `ProjectNextActionCard`, `ProjectTabBar`, le calcul de l'onglet d'ouverture par phases et l'action « prochaine action » (`actOnNextAction`). Les boutons d'avancement de statut de l'en-tête restent (rien dans le flux ne les remplace avant #1315), sans l'exclusion de la cible de la carte supprimée (`headerStatuses(..., null, ...)`).

R10. **Anciens onglets en sections repliées.** Aucun écran existant n'est supprimé : chaque ancien onglet (matières, contributions, bilan, ventes, chaîne, planning, invention, journal, équipe) devient une section `<details>` sous la grille, fermée par défaut, avec le même contenu et les mêmes règles de masquage (équipe seule, invention sans produit, ventes d'usage personnel). `?tab=<clé>` ouvre la section demandée et la fait défiler dans la vue (liens du compagnon, des contrôles de clôture, des alertes) ; ouvrir ou fermer une section met la clé dans l'URL ou la retire ; le contenu n'est rendu et chargé que section ouverte (chargements paresseux conservés). Sans clé : tout fermé. `refreshesVisibleOn(actions, tab)` accepte `null` (aucune section ouverte).

R11. **Chargement et fraîcheur.** À l'ouverture : `fetchStationQuantities`, `fetchOfProject`, ventes (`distributionStore.fetchSales`) en plus des chargements actuels. Rechargés (sans toucher aux sections) à chaque lot d'événements Mercure du projet, à la progression de sync `industry-project` et à la reconnexion. Lecture seule : aucun nouveau topic, aucune action asynchrone.

R12. **Glossaire.** Ajout : **Grille de flux** (Flow grid), **Carte de flux** (Flow card), **Colonne de flux** (Flow column : à apporter, sur place, en fabrication, sorti, vendu ou remis).

## Exemples (tests rouges)

| Entrée | Sortie |
|---|---|
| Zydrine à A : toBring 40, onSite 60, consumed 50, out 0 | ligne A : toBring [toBring 40], onSite [onSite 60, consumed 50] |
| Composant X à A : inProduction 40, onSite 0 | inProduction [inProduction 40], rien en onSite |
| Tritanium à A : onSite/out/toBring `null`, raison `corporation_assets_not_synced` | onSite [unknown, quantity null, raison reprise] |
| Tritanium à B : inTransit 25, toBring 0 | toBring [inTransit 25] |
| `source_material` Tritanium 400 à Jita 4-4 (60003760) | ligne market Jita : onSite [toBuy 400] |
| `source_material` Zydrine, lieu `unknown` / `market_not_chosen` | ligne unresolved : [toBuy, raison `market_not_chosen`] |
| `launch_job` Composant X (5) à A | ligne A : inProduction [toLaunch 5] |
| type avec `stations = null`, `plan_unavailable` | `unavailableReason` = `plan_unavailable`, 0 ligne pour ce type |
| 5 cartes onSite en une cellule (dont 4 calmes) | 1 carte visible + bouton « + 4 sur place : a, b, c… » ; ouvert : 5 cartes |
| 3 cartes dont 3 calmes | rien de replié |
| vente 20 Ishtar, `sellStructureId` = Jita | ligne Jita : done [sold 20] ; usage personnel : aucune carte, en-tête « Remis » |

## Cas limites

- Une station absente de `station-quantities` mais citée par une action : ligne créée par l'action.
- Une action sans `typeName` : nom de repli traduit, jamais vide.
- Deux actions du même type au même lieu : deux cartes (une par ligne de BOM).
- Projet sans quantité ni action : message « Rien à afficher pour l'instant », pas de grille vide.

## Décisions

D1. Aucun écran supprimé : les onglets deviennent des sections repliées jusqu'à #1310 (détail de carte), #1312 (Planning), #1315 (bilan), #1317 (invention) ; seuls rail, carte prochaine action et barre d'onglets partent (désignés par l'issue). Ligne « À relire à la fin ».
D2. Les boutons de statut de l'en-tête restent (démarrer, vendre, clôturer) : « no status transitions » est lu comme « la grille n'en porte pas » ; sans eux un projet ne pourrait ni démarrer ni se clôturer. Question à Sylvain dans le journal.
D3. Lignes de marché (Jita, capitale) créées à la demande par les actions d'achat, pas fixes : « une ligne vide n'existe pas ».
D4. Pas de filtre « ce qui me concerne », pas de légende d'activité (#1308), pas de bouton « Relire l'ESI » (absent de l'issue).
D5. Cartes `toBuy` et `toLaunch` viennent des actions de lieu (le serveur ne publie pas de quantité par ligne de BOM non lancée) ; une étape sans action (déjà couverte) n'a pas de carte.

## Hors périmètre

Couleur d'activité, barre de progression, source (#1308) ; attendus en pointillé (#1309) ; détail de carte et clic (#1310) ; bande ISK (#1311) ; Planning (#1312) ; vue structure (#1313) ; migration des projets (#1320). Backend inchangé.
