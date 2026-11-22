# #1312 — Planning view of the flow (Gantt by station)

Circuit `m` (relevé de `s` : le planning d'un projet de groupe ne porte aucun job, voir R1), mode nuit. Décisions prises sur le prototype (`industry-prototype-ishtar-2026-11-21.html`, `planning(P)`, bascule « Flux / Planning ») et sur ce qui existe. Câblage écrit ici, pas de note architect (une propriété de ressource, du front, pas de migration).

## Contexte

`GET /industry/projects/{id}/planning` calcule le plan au plus tôt (`ProjectPlanningBuilder`) mais ne le sert que par personnage (`characters[].jobs[]`), et seulement pour un projet sans autre participant accepté (`lanesShown`). Dans un projet de groupe, `characters` est vide : aucun job daté n'arrive au front. Le Gantt du flux est par station, sans personnage : il faut les jobs datés sans leur voie.

## Règles

R1. **Backend, propriété `jobs`.** `ProjectPlanningResource` gagne `jobs: list<array>` : tous les jobs du plan (ceux de toutes les voies), à plat, au même format que `characters[].jobs[]` (`lineId`, `typeId`, `typeName`, `blueprintName`, `activity`, `runs`, `planned`, `real`, `wait`), **sans nom de personnage ni voie**. Servie à tout participant accepté, projet solo ou de groupe (même contrôle d'accès qu'aujourd'hui, `assertAcceptedMember`). Tri : début prévu croissant (jobs sans `planned` en dernier), puis `lineId`. `characters`, `lanesShown`, `unscheduled` et les fins ne changent pas. Un projet de groupe garde `characters = []` et reçoit `jobs`.

R2. **Front, type.** `ProjectPlanning.jobs: PlanningJob[]` (`stores/group-industry/types.ts`). Le store `planning` ne change pas.

R3. **Module pur `components/group-industry/projectFlowPlanning.ts`.** `buildFlowPlanning({ planning, grid, bomItems, now })` renvoie `{ stations, undated, start, end }`.
- `stations: PlanningStation[]` : `key`, `kind` (`station` ou `unresolved`), `locationId`, `name`, `bars: PlanningBar[]`.
- `PlanningBar` : `lineId`, `typeName`, `activity`, `runs`, `start` et `end` (ms), `state` (`done`, `running`, `waiting`, `planned`).
- `undated: PlanningJob[]` : jobs sans `planned` ni `real` (jamais perdus en silence, jamais une date inventée).
- `start`, `end` : bornes de l'axe en ms. `start` = min(maintenant, débuts des barres) ; `end` = max(fins des barres, maintenant + 24 h). Sans barre : `null` et `null`.

R4. **Dates d'une barre.** `real` l'emporte sur `planned` (début et fin du job réel : le réel fait foi, comme le planning actuel). Sans `real`, `planned`. Une barre porte une seule paire de dates.

R5. **État d'une barre.** `real.status = done` → `done` ; `real.status = active` → `running` ; sans `real` et `wait !== null` → `waiting` ; sinon `planned`.

R6. **Station d'un job**, dans cet ordre :
1. la ligne de la BOM (`bomItems[].id === lineId`) a au moins un `jobMatches` avec `facilityId` non nul : station = `facilityId` et `facilityName` du lien le plus récent (`matchedAt`) ;
2. sinon, la première ligne de la grille de flux (`grid.rows`, ordre de la grille) de `kind` `station` dont la colonne `inProduction` ou `out` a une carte du même `typeId` (état `inProduction`, `toLaunch` ou `out`) ;
3. sinon, la ligne `unresolved` (« Lieu à choisir »), jamais une station devinée.
Clé de station = `station:<locationId>` (celle de la grille) ou `unresolved`. Nom : `facilityName`, sinon le nom de la ligne de grille, sinon `null` (« station sans nom »).

R7. **Ordre.** Stations : celles de la grille dans son ordre, puis les autres par nom, `unresolved` en dernier. Une station sans barre n'existe pas. Barres d'une station : début croissant, puis `lineId`.

R8. **Composant `ProjectFlowPlanning.vue`** (`projectId`, `grid`, `bomItems`). Charge `GET planning` à l'ouverture et le recharge aux lots d'événements Mercure du projet, à la progression de sync `industry-project` et à la reconnexion (comme `ProjectPlanning.vue`). Lecture seule : aucun topic, aucune action asynchrone. Rendu du prototype : bloc « Planning estimé », en-tête avec la fin attendue, titre de station en capitales cyan, une ligne par job (pastille de l'activité, nom du type tronqué avec le nom complet en `title`, piste avec barre, fin), trait vertical d'aujourd'hui, légende. Barre : `done` vert, `running` bleu ciel, `planned` ambre, `waiting` rayé gris. Fin affichée : « Fait » pour `done`, sinon `J+N` (N = jours entiers de maintenant à la fin de la barre, jamais négatif : barre `done` ou fin passée affiche la date).
- Fin attendue de l'en-tête = `planning.expectedEnd`, sinon `planning.plannedEnd`, sinon « inconnue » (`UnknownContributionValue`) ; jamais 0.
- Mentions honnêtes : « transferts entre stations non inclus » et, si `planning.unscheduled` n'est pas vide, la liste des lignes non planifiées avec leur raison (clés `groupIndustry.planning.unscheduledReason.*` existantes). L'avis `structureTimeBonusIncluded = false` est repris.
- États : chargement, erreur (`role="alert"`, jamais un Gantt vide), aucun job (« Rien à planifier »).
- Un nom de type très long ne déborde pas de sa colonne.

R9. **Page.** `GroupIndustryDetail.vue` : bascule `Flux | Planning` (style `seg` du prototype) au-dessus de la grille. `Flux` (défaut) = la grille actuelle ; `Planning` = `ProjectFlowPlanning` à sa place. La colonne latérale « À faire du projet » reste dans les deux vues. Le composant n'est monté, donc le planning n'est chargé, que dans la vue `Planning`. Les anciennes sections, dont celle du Planning par personnage, restent (aucun écran n'est retiré sans l'accord de Sylvain, voir « Décisions »).

R10. **Libellés** `groupIndustry.flowPlanning.*` en fr et en (aucun texte en dur), palette d'activité de #1171 (`ACTIVITY_STYLES`).

R11. **Glossaire.** Ajout **Planning du flux** (Flow planning). Mise à jour de **Planning du projet** : propriété `jobs` (jobs à plat sans voie, servis aussi aux projets de groupe).

## Exemples (tests rouges)

| Entrée | Sortie |
|---|---|
| projet de groupe, voie du propriétaire avec 2 jobs | `lanesShown = false`, `characters = []`, `jobs` de longueur 2, aucun nom de personnage dans le JSON |
| projet solo, 2 voies de 1 et 2 jobs | `characters` inchangé, `jobs` = 3 jobs triés par début prévu |
| membre non accepté | refusé comme aujourd'hui |
| job sans `planned`, 2 jobs datés | `jobs` : les 2 datés d'abord par début, le sans date en dernier |
| job avec `planned` 08:00-12:00 et `real` 09:00-11:00 `done` | barre 09:00-11:00, état `done` |
| job `planned` seul, `wait` non nul | état `waiting` ; `wait` nul : `planned` |
| job `real.status = active` | état `running` |
| job dont la ligne a un lien avec `facilityId` 1 puis un plus récent avec `facilityId` 2 | station 2 |
| ligne sans lien, carte `inProduction` du type en ligne A de la grille | station A |
| ligne sans lien, type absent de la grille | station `unresolved` |
| job sans `planned` ni `real` | dans `undated`, aucune barre |
| 2 stations, A avant B dans la grille, un job dans chaque | `stations` = [A, B] |
| maintenant = 10:00, barres 12:00-14:00 | axe de 10:00 à max(14:00, 10:00 + 24 h) |
| barre `running` finissant dans 2 j 3 h | fin « J+2 » |
| composant, `planning.jobs` vides, `unscheduled` vide | « Rien à planifier » |
| composant, réponse en erreur | alerte, aucune piste |
| composant, nom de type de 100 caractères | libellé tronqué, `title` complet |
| page, clic sur « Planning » | grille de flux masquée, Gantt affiché, colonne « À faire » toujours là |

## Cas limites

- Deux jobs du même type dans deux stations de la grille : le lien de job (R6.1) départage ; sans lien, la première station de la grille, jamais deux barres pour un job.
- Fin attendue absente (plan impossible) : « inconnue », barres déjà datées affichées quand même.
- Barre de durée nulle : largeur minimale visible (0,6 %).

## Hors périmètre

- Durées de trajet entre stations : aucune source en base ni en API (la route de courier ne porte que des coûts). Les estimations du backend ne les incluent donc pas ; l'écran le dit. Les inclure demande une durée de trajet par couple de stations (issue à ouvrir).
- Station prévue des jobs non lancés par le choix de stations du plan (#1285) : la carte `toLaunch` de la grille la porte déjà quand l'action de lancement a un lieu.
- Invention : toujours laissée de côté par le planning (#1204), listée dans les lignes non planifiées.
- Suppression de la section Planning par personnage : décision de Sylvain.

## Décisions ouvertes (tranchées en nuit, à relire)

- Section « Planning » par personnage conservée à côté de la vue (doublon partiel) plutôt que supprimée.
- `real` remplace `planned` pour la barre (pas de double barre) : le retard se lit par l'état `waiting` et la fin.
