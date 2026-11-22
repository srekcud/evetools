# #1320 — Migrer les projets existants vers le modèle par station

Circuit `l`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et des specs #1285, #1292, #1293, #1294. Back-end seulement (commande console) : aucun écran. Les projets existants ont `work_stations = null` (#1285) et des containers sans lieu (#1292). Aucun projet n'est effacé, aucune ligne de BOM ni quantité n'est recalculée.

## Contexte

Les quantités par station (#1294) se lisent à la volée : station d'une étape = `plannedStructureId` de sa ligne, stock = containers de projet localisés (#1292, #1293). Il reste à poser, pour les projets d'avant le flux : les stations du plan (`work_stations`, par type de travail) et le lieu de chaque container, à partir de ce que le projet porte déjà. Ce qui n'est pas déductible sans ambiguïté n'est pas deviné : il est rapporté.

## Commande

R1. `app:industry:migrate-to-station-model` (`src/Command/`, atterrissage `StationMigration`). Options : `--apply` (écrit ; sans lui, simulation), `--project=<uuid>` (un seul projet), `--log=<fichier>` (obligatoire avec `--apply`, refusé sans lui : exit `FAILURE`, rien n'est écrit), `--revert=<fichier>` (annule un journal ; simulation sans `--apply`).

R2. Simulation et écriture suivent le même chemin de code : tout le lot s'exécute dans **une seule transaction** (un `flush` puis un `clear` par projet). Simulation : `rollBack` en fin de lot. Écriture : le journal (R9) est écrit **avant** le `commit` ; échec d'écriture du journal ou exception = `rollBack`, rien n'est appliqué, l'exception remonte (exit `FAILURE`).

R3. Projets lus : tous les statuts, triés par date de création. Un projet traité deux fois ne change plus (idempotent : seules les valeurs absentes sont posées).

R4. Sortie : un bloc par projet (nom, statut), les changements puis les éléments non migrés avec leur raison, et un total (projets, changements, non migrés par raison). Exit `SUCCESS` même s'il reste des éléments non migrés (le rapport est le contrôle).

## Stations du plan (`work_stations`)

R5. Pour chaque ligne de BOM de job (`isJob()`), type de travail = `WorkTypeClassifier` (même règle que `ProjectWorkStations`, #1285 R1 : `blueprints` si `jobGroup = blueprint`, sinon l'activité et le produit). Les lignes sont groupées par type de travail.

R6. Un type déjà présent dans `work_stations` n'est ni modifié ni rapporté. Pour un type absent, selon les `plannedStructureId` de ses lignes :
- toutes identiques, non nulles, structure du propriétaire (`IndustryProjectService::structuresOf`) et qui sait faire l'activité du type (`canRun` ; `blueprints` : `Copying`, `reaction` : `Reaction`, autres : `Manufacturing`) → `work_stations[type] = {structureId, recommendedStructureId: null}`, changement `work_station` ;
- une ligne dont le type est absent du SDE (classement impossible) → non migré `type_missing_from_sde` (sujet `type_<id>`), les autres types continuent ;
- au moins une ligne sans `plannedStructureId` → non migré `lines_without_station` ;
- deux identifiants différents ou plus → `several_stations` (un type n'a qu'une station dans le modèle) ;
- identifiant absent des structures du propriétaire (supprimée, autre utilisateur, valeur qui n'est pas un uuid de configuration) → `structure_unknown` ;
- structure qui ne sait plus faire l'activité → `structure_cannot_run`.
Ni `buildBom`, ni plan, ni `plannedStructureId`, ni quantité ne sont touchés ; le verrou de #1285 n'est pas appliqué (on enregistre l'état existant).

## Lieu des containers

R7. Un container de projet qui a déjà un lieu n'est ni modifié ni rapporté. Un projet `completed` : ses containers sans lieu sont rapportés `project_completed` (les assets d'aujourd'hui ne disent rien du passé). Sinon, assets du propriétaire indisponibles (`ProjectContainerService::unavailabilityReasonOf` non nul, ou dernière synchro des assets de la corporation de plus de 48 h : détail `corporation_assets_stale`) : `assets_unavailable`, avec la raison en détail.

R8. Sinon, assets nommés comme le container (clé `IndustryProjectContainer::keyOf`) parmi `VisibleCorporationAssetsFinder::findVisibleAssets(corporation du propriétaire, null, propriétaire)`, station de chacun par `AssetStations` (même lecture que `ProjectContainerReadings`). Résultat :
- aucun asset nommé → `container_not_found` ;
- au moins un asset dont la station n'est pas résolue → `station_unresolved` (on ne choisit pas entre un lieu connu et un inconnu) ;
- plusieurs stations distinctes → `container_in_several_stations` (le modèle a un lieu par container, décision #801 : le container garde sa lecture par nom seul) ;
- une seule station → `ProjectContainerPlacer::place($project, station, nom du container)` ; refus `LocationNotInPlanException` → `station_not_in_plan`, `StationAlreadyHasContainerException` → `station_already_has_container`, `ContainerNameTakenException` → `named_like_personal_stock`. Succès : le container est localisé, changement `container_location`.
Les stations du plan incluent déjà les `work_stations` posés par R6 dans le même projet : R6 s'exécute avant R8.

## Journal et annulation

R9. Journal (JSON, une entrée par changement) : `{projectId, kind: work_station|container_location, subject: <type de travail | uuid du container>, value: <uuid de la structure | locationId>}` plus la date d'application. Aucun nom ni montant. Le fichier est créé en refusant d'écraser un journal existant (mode `0600`) : un second `--apply` avec le même `--log` échoue sans rien appliquer.

R10. `--revert` : pour chaque entrée, `work_station` : si le projet a toujours ce type de travail avec ce `structureId` et `recommendedStructureId` nul, le retire ; `container_location` : si le container appartient bien au projet de l'entrée et a toujours ce lieu, le remet à `null` (nouvelle méthode `IndustryProjectContainer::clearLocation()`). Une valeur qui a changé depuis, un projet ou un container disparu : ignoré et rapporté `changed_since`, jamais écrasé. Même règles de transaction que R2 (simulation par défaut). Fichier illisible ou sans la forme attendue : exit `FAILURE`, rien n'est écrit.

## Exemples (tests rouges)

| Projet | Situation | Résultat |
|---|---|---|
| lignes `components` toutes prévues en A, A sait fabriquer | `work_stations` vide | `work_stations.components = {A, null}` |
| lignes `components` en A et en B | | `several_stations`, rien posé |
| une ligne `reaction` sans structure prévue | | `lines_without_station` |
| lignes `hulls` prévues dans une config supprimée | | `structure_unknown` |
| lignes `reaction` en A, A est un Azbel sans réaction | | `structure_cannot_run` |
| `work_stations.components` = C, lignes en A | | inchangé, non rapporté |
| container « Ishtar » sans lieu, un seul asset nommé, station du plan 100 | | lieu 100, changement `container_location` |
| même cas, station hors du plan | | `station_not_in_plan` |
| deux assets « Ishtar » en stations 100 et 200 | | `container_in_several_stations` |
| un asset nommé dont la station n'est pas résolue | | `station_unresolved` |
| aucun asset nommé | | `container_not_found` |
| un autre container du projet est déjà à la station 100 | | `station_already_has_container` |
| container nommé comme un container de stock perso | | `named_like_personal_stock` |
| projet `completed` | | `project_completed` |
| propriétaire sans corporation ni director | | `assets_unavailable` |
| second passage `--apply` | | zéro changement |
| `--apply` sans `--log` | | `FAILURE`, base inchangée |
| simulation puis lecture | | base inchangée, rapport identique à l'écriture |
| `--apply` puis `--revert` `--apply` | | `work_stations` null et lieux null (état d'origine) |
| valeur modifiée à la main entre les deux | | `changed_since`, non écrasée |

## Hors périmètre

Recette sur copie de la production (#1321) ; écran ou notification Mercure (aucun client ne tient d'état à rafraîchir : le prochain chargement lit les nouvelles données, et la commande n'est pas une action de l'utilisateur) ; recalcul des quantités, du plan ou de la BOM ; création de containers par station (le POST de #1292) ; transferts entre stations (#1295, rien d'avant le flux) ; fusion de plusieurs stations d'un même type de travail.

## Glossaire

Ajout : **Migration vers le modèle par station** (Station model migration) : commande qui pose les stations du plan et les lieux des containers des projets d'avant le flux, avec journal et annulation.

## Décisions

D1. Commande console avec simulation, journal et annulation plutôt que migration Doctrine : la déduction lit les assets ESI et les configurations de structure, pas le schéma ; l'annulation exige un journal.
D2. Aucune donnée ambiguë n'est devinée : plusieurs stations, station inconnue, container en plusieurs lieux restent comme avant (lecture par nom, choix automatique du plan) et sont rapportés.
D3. `recommendedStructureId = null` : le projet ne garde pas de recommandée d'époque ; la page du plan la recalcule.
D4. Pas de `buildBom` : poser `work_stations` fige l'état existant sans changer les lignes ; le prochain recalcul du plan traitera ce choix comme assigné (statut `assigned`).
