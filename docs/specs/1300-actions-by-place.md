# #1300 — Every action has a place, one provider of actions per user across projects

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype. Back-end et couche de données front : la page À faire est #1303, le compagnon #1304, la colonne du projet #1307 ; ici on livre la source qu'ils liront.

## Contexte

`GET /industry/actions` (`IndustryActionsProvider`) renvoie une prochaine action par projet (cockpit), sans lieu. Une action par projet ne peut pas porter un lieu (les lignes d'un projet s'achètent ou se lancent à plusieurs endroits) et ne permet ni rangement par lieu ni multibuy. Le prototype (`actionsFor`, `grouped`) range les actions de tous les projets par lieu, lieu du personnage en tête, « n'importe où » en dernier.

## Règles

R1. **Lieu.** Value object `ActionPlace` : `kind` = `station` (`locationId`, `name`, `solarSystemId`, `solarSystemName`, nullables sauf `locationId`), `anywhere`, ou `unknown` (`unknownReason`). Un lieu qu'on ne sait pas résoudre est `unknown`, jamais `anywhere` ni un lieu par défaut.

R2. **Lieu d'un achat** (`source_material`) : marché de la ligne (`IndustryProjectBomLine::getSourcingMarket`). `jita` : Jita 4-4 (`EveConstants::JITA_STATION_ID`, nom et système lus dans `StaStationRepository`). `structure` : structure de marché du lecteur (`User::getEffectiveMarketStructureId`, nom `getEffectiveMarketStructureName`, système lu dans `CachedStructureRepository`, `null` si inconnu). Marché non choisi (`null`) : `unknown` / `market_not_chosen` (la recommandation n'est pas recalculée ici). Structure de marché non réglée : `unknown` / `market_structure_unset`.

R3. **Lieu d'un lancement** (`launch_job`) : station de l'étape = `IndustryStructureConfig` du propriétaire du projet désignée par `plannedStructureId` ; `locationId`, `name`, `solarSystemId` de la configuration, nom du système lu dans `MapSolarSystemRepository`. Sans `plannedStructureId`, configuration absente ou sans `locationId` : `unknown` / `station_unresolved`.

R4. **Lieu des autres actions** : `review_contributions` (vérifier un dépôt) et `review_join_requests` (accepter un membre) sont `anywhere`.

R5. **Actions émises par projet** (projet accepté du lecteur, statut autre que `completed`) :
- `source_material` : une action par ligne matière (non job) avec `remaining = required − fulfilled > 0` (fulfilled via `ProjectContainerFulfillment::of`), statut `published` ou `in_progress` ; `quantity = remaining` ;
- `launch_job` : une action par ligne de job, statut `in_progress`, dont `runs` est `null` ou supérieur aux runs comptées (`getCountedRuns`) et `deliveredRuns < runs` ; `quantity = runs − runs comptées` (`null` si `runs` est `null`) ;
- `review_contributions` : gestionnaire (propriétaire ou admin accepté), `pendingContributionCountReviewableBy > 0`, `quantity` = ce compte ;
- `review_join_requests` : gestionnaire, `pendingJoinRequestCount > 0`, `quantity` = ce compte.
Les actions take, carry, store et pay n'ont aucune source aujourd'hui (#1301, #1295, #1315, surplus) : non émises, jamais simulées.

R6. **Une source par type d'action.** Interface `ProjectActionSource` (`actionsOf(IndustryProject, User): list<PlacedAction>`), autoconfigurée par le tag `app.industry.project_action_source`. `UserActionReader` agrège les sources sur tous les projets acceptés du lecteur (`findAcceptedWithProjectsByUser`), ou sur un seul projet (`ofProject`). Une source qui lève une exception remonte : pas de catch silencieux. Les autres threads (#1295, #1301, #1304) ajoutent une source sans toucher au lecteur.

R7. **Position.** `CharacterPositions` lit chaque personnage du lecteur avec `CharacterLocationReader` (cache ESI court, #1297). Lieu « ici » = station ou structure d'au moins un personnage (`locationId === stationId ?? structureId`). Aucune position lisible : `here = null` sur toutes les actions (jamais `false` quand on ne sait pas). Position lue mais lieu différent : `false`. Une action `anywhere` ou `unknown` a `here = null`.

R8. **Tri** (`PlacedActionSorter`) : rang du lieu, puis rang du code, puis nom du projet, id du projet, nom du type, id de ligne. Rang du lieu : 0 ici (docké), 1 même système qu'un personnage, 2 autre station (par nom de station puis `locationId`), 3 `unknown`, 4 `anywhere`. Rang du code : `launch_job` 0, `source_material` 1, `review_contributions` 2, `review_join_requests` 3. Sans position lisible, plus de rang 0 ni 1 : les stations se rangent par nom.

R9. **Ressource.** `PlacedActionResource` (`shortName: PlacedAction`, `paginationEnabled: false`, `skip_null_values: false`) : `id` (`{projectId}:{code}:{bomLineId ou -}`, identifiant), `projectId`, `projectName`, `code`, `bomLineId`, `typeId`, `typeName`, `quantity`, `place` (R1, tableau), `here` (R7). Deux opérations, un seul provider : `GET /industry/place-actions` (tous les projets acceptés) et `GET /industry/projects/{projectId}/place-actions` (`uriVariables` + `Link`). 401 non authentifié ; projet inconnu ou uuid invalide : 404 ; non membre accepté : 403. `GET /industry/actions` et l'Atelier ne changent pas.

R10. **Lecture seule.** Aucune écriture, aucun montant, aucune action asynchrone donc pas de Mercure. Appels ESI : uniquement `/location/` et `/online/` via le cache court de #1297.

R11. **Front, couche de données.** Types et store `stores/group-industry/placeActions.ts` (types dans `stores/group-industry/types.ts`) : `fetchAll()`, `fetchOfProject(projectId)`, état de chargement et d'erreur par appel, `here: null` conservé, jamais converti en `false`. Aucun écran.

## Exemples (tests rouges)

Utilisateur U, projet P (in_progress, propriétaire U, structure A locationId 1000 « Azbel »). Lignes : matière Tritanium (marché `jita`, 500 requis, 100 couverts), matière Zydrine (marché `null`, 50 requis, 0 couverts), job « Composant X » (plannedStructureId = A, 8 runs, 3 comptées).
- Actions : `source_material` Tritanium (400, place Jita 4-4), `source_material` Zydrine (50, `unknown` / `market_not_chosen`), `launch_job` Composant X (5, place A « Azbel »).
- Un personnage de U docké en A : ordre `launch_job` (here true), `source_material` Tritanium (here false), `source_material` Zydrine (unknown, here null).
- Aucune position lisible : ordre Azbel avant Jita 4-4 (par nom), `here` null partout.
- Même système que A, autre station : rang 1, avant Jita.
- 2 demandes d'adhésion et 1 contribution à relire, U propriétaire : deux actions `anywhere` en fin de liste ; membre simple : aucune.
- Projet `completed`, projet `draft` sans matière à acheter : aucune action. Ligne de job `runs = null` : quantité `null`.
- Marché `structure`, structure réglée et système inconnu du cache : place `station` avec `solarSystemId` `null`.
- API : 401 ; 200 avec les actions des deux projets de U, triées ; `/projects/{id}/place-actions` ne renvoie que P ; 403 pour un étranger ; 404 pour un uuid inconnu ou invalide.

## Hors périmètre

take (#1301), carry (#1295), store, pay (#1315) ; page À faire, multibuy, compagnon, colonne projet ; alertes de prix et ordres sous-cotés ; transitions de statut du cockpit (supprimées par #1307) ; retrait de `GET /industry/actions`.

## Décisions

D1 : nouvelle ressource plutôt que modifier `GET /industry/actions` (l'Atelier reste intact, #1303 décidera de son retrait). D2 : une action par ligne (un lieu par ligne, multibuy possible). D3 : marché non choisi = `unknown`, pas de recommandation recalculée (coût prix à chaque lecture). D4 : `here = null` sans position. D5 : le rang « même système » reprend le prototype (`sysOf(b)===here`).
