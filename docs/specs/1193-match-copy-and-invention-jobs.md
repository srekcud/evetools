# Spec #1193 : rapprocher les jobs ESI de copie et d'invention aux étapes du projet

Mode nuit : spec validée en autonomie, décisions tranchées sur l'option recommandée (voir « Décisions »). Circuit l.

## Contexte

Une ligne de BOM `isJob` porte `activityType` parmi `manufacturing`, `reaction`, `copy`, `invention` (`IndustryProjectService::copyLinesOf`, `replaceT2BlueprintCopiesByInventions`). Seuls les deux premiers sont rapprochés d'un job ESI (`IndustryJobMatcher`, `AvailableJobsProvider`, `ManualJobLinker`), donc une étape de copie ou d'invention reste « À lancer » même quand le job est fait en jeu.

Lignes concernées (toutes de `jobGroup = blueprint`) :
- **copie** : `typeId` = produit du blueprint copié (T1 ou T2 fabriqué), `runs` = runs à copier. Blueprint ESI du job = **producteur de fabrication** du `typeId` (`IndustryActivityProductRepository::producerOf`).
- **invention** : `typeId` = produit T2 fabriqué, `runs` = tentatives. Blueprint ESI du job = **blueprint T1** de l'invention (`InventionService::getInventionData($typeId)['t1BlueprintTypeId']`).

Activités ESI : copie = 5 (`IndustryActivityType::Copying`), invention = 8 (`IndustryActivityType::Invention`).

## Règles métier

1. **Mêmes règles que la fabrication** pour copie et invention : blueprint de la ligne, démarré depuis la création du projet, personnages de l'owner et des participants acceptés, hors job sans production, hors job déjà lié ailleurs (projet ou ligne), runs du job dans le reste de la ligne (jamais un job plus gros), passe unique multi-projets (`JobMatchPlanner`) avec ses signaux, ses jobs à attribuer, la part partagée #1250 et la stabilité #1245. Aucun changement du planificateur de domaine.
2. **Activité du job selon l'activité de la ligne** : `manufacturing` → {1}, `reaction` → {9, 11}, `copy` → {5}, `invention` → {8} (table unique, partagée par la requête de dépôt, `isJobOfBomLineActivity`, le provider des jobs disponibles). Un job d'une autre activité (recherche ME/TE 3 et 4, rétro-ingénierie 7) n'est jamais rapproché.
3. **Blueprint attendu** : une ligne `copy` attend le producteur de `typeId` ; une ligne `invention` attend le blueprint T1 d'invention de `typeId`. Une ligne d'invention dont la donnée d'invention est absente est un échec de ligne explicite (`LineMatchFailure`), jamais ignorée en silence.
4. **Copie et invention qui partagent un blueprint** (copie T1 pour invention et invention du T2 utilisent le même BPO T1) : l'activité du job sépare les deux (5 contre 8).
5. **Rapprochement manuel** : `AvailableJobsProvider` (liste des jobs et `linkableBomLineIds`), `ManualJobLinker`, `LinkJobProcessor` et `AssignUnassignedJobProcessor` acceptent les jobs de copie et d'invention sur la ligne correspondante, avec les mêmes refus que la fabrication (activité, blueprint, sans production, déjà lié, runs). `UnassignedJobCandidates` ne filtre plus ces lignes.
6. **Prédicat de ligne** : un seul prédicat d'entité « ligne de job rapprochable à l'ESI » (quatre activités) remplace `isManufacturingOrReactionJob()` aux endroits qui rapprochent, rafraîchissent les contributions dérivées ou listent les candidats. `isManufacturingOrReactionJob()` reste tel quel pour tout ce qui calcule une quantité produite ou une consommation de matières (`getQuantityOfRuns`, stock couvrant, `StartedJobConsumption`, nom de blueprint de BOM).
7. **Effet sur la chaîne et la prochaine action** : aucune logique nouvelle. Un `IndustryProjectJobMatch` sur une ligne de copie ou d'invention fait avancer le nœud (à lancer, en cours, livré) et la prochaine action (`launch_job` puis `await_jobs`) comme pour la fabrication, parce que `ProjectChainBuilder` et `ProjectCockpitInputFactory` lisent déjà les `jobMatches` de toute ligne. Les tests doivent le prouver sur copie et invention.
8. **Pas de contribution dérivée** pour copie et invention : `refreshLine` n'est appelée que pour les lignes de fabrication et de réaction (matcher automatique, `MatchJobsProcessor`, `OpenProjectsJobMatcher`, lien manuel `ManualJobLinker`). Un job lié sur copie ou invention fait avancer la chaîne et la prochaine action, sans créer de `JobInstall` ni toucher aux montants ni à `fulfilledQuantity`.
9. **Front** : `jobLinking.ts::LINKABLE_ACTIVITIES` ajoute `copy` et `invention` (le bouton « Lier un job » apparaît pour ces étapes). Le texte « Un job lancé en jeu apparaît après la prochaine synchro des jobs » de `ProductionChainJobTree.vue` devient vrai pour ces étapes et n'est pas modifié. Pas de nouvel écran.
10. **Glossaire** : « Job lié » couvre copie et invention (blueprint attendu, activités) ; ajouter l'activité ESI par activité de ligne.

## Exemples chiffrés (tests rouges)

| # | Ligne | Job ESI | Résultat |
|---|---|---|---|
| 1 | copie, `typeId` X, 10 runs, producteur BPO_X | activité 5, blueprint BPO_X, 10 runs, depuis la création | job lié (10 runs), nœud « livré » une fois `delivered` |
| 2 | copie 10 runs | activité 5, BPO_X, 6 runs | lié 6 runs, reste 4 ; un second job de 4 runs complète |
| 3 | copie 10 runs | activité 5, 12 runs | non lié (trop gros) |
| 4 | copie 10 runs | activité 1 (fabrication) sur BPO_X | non lié (activité) |
| 5 | invention T2 Y, 3 tentatives, T1 = BPO_T1 | activité 8, blueprint BPO_T1, 3 runs | lié ; la copie T1 de 3 runs ne prend pas ce job |
| 6 | invention | activité 8, blueprint ≠ BPO_T1 | non lié |
| 7 | copie T1 (3 runs) et invention (3 runs) du même BPO_T1 | un job 5 de 3 runs, un job 8 de 3 runs | chacun sur sa ligne |
| 8 | copie, job déjà lié à un autre projet (toutes les runs) | activité 5 | non lié, ni listé comme assignable |
| 9 | copie avec job `delivered` lié | : | statut du nœud `done`, la prochaine action ne propose plus « lancer la copie » |
| 10 | manuel : copie, job 5 libre | `ManualJobLinker::link` | `IndustryProjectJobMatch` créé, aucune contribution `JobInstall` dérivée (règle 8) |
| 11 | manuel : copie, job 8 | `ManualJobLinker::link` | `BadRequestHttpException` « Job activity does not match BOM line activity » |
| 13 | `LineRentalSuggester` sur ligne de copie liée | : | aucune suggestion |
| 12 | `AvailableJobsProvider` | job 5 sur BPO_X | `linkableBomLineIds` contient la ligne de copie, pas la ligne de fabrication de X |

## Cas limites

- Copie sans `runs` : échec de ligne `noRuns`, comme la fabrication. Ligne invention sans donnée d'invention : nouvel échec de ligne (règle 3), non bloquant pour les autres lignes.
- Copie `isCoveredByStock` (`runs = 0`) : aucun candidat (reste de ligne ≤ 0).
- Job de copie supprimé ou annulé : la correspondance est libérée comme pour la fabrication (`releaseMatchesWithoutOutput`).
- Ligne avec lien manuel : l'automatique ne la recalcule pas (`hasManualJobMatch`), inchangé.
- Plusieurs lignes de copie du même produit (copie fabrication et copie d'invention) : elles se disputent les mêmes jobs 5 via le planificateur ; limite connue déjà notée par #1204.

## Accès et fraîcheur

Aucun nouvel identifiant ni endpoint. Les contrôles existants s'appliquent tels quels : `usersWhoseJobsCanBeLinkedBy` (provider et processor), personnages de l'owner et des participants acceptés (matcher), `getUser() === job owner`. Un défaut d'accès préexistant n'est pas repris.

## Tests existants contredits ou à mettre à jour

- `IndustryJobMatcherTest`, `IndustryJobMatcherFailedLinesTest` : la requête de dépôt change de nom ou de signature (activités par ligne) ; les stubs de `findManufacturingJobsByBlueprint` sont adaptés sans changer d'assertion.
- `AvailableJobsProviderTest` (stub `isJobOfBomLineActivity`), tests de `ManualJobLinker` et `jobLinking` côté front (`LINKABLE_ACTIVITIES` : un test fige aujourd'hui que copie et invention ne sont pas liables, #1199) : renversés avec raison au journal.
- Tests des appelants de `isManufacturingOrReactionJob` migrés vers le nouveau prédicat : à lister par le test-writer (grep `isManufacturingOrReactionJob`).

## Hors périmètre

- `successful_runs` et le nombre réel de BPC obtenus (#1316), décrypteur (#1205 déjà fait), planification (#1204 déjà fait).
- Recherche ME/TE et rétro-ingénierie ; réaffectation de la sémantique des runs d'une copie (voir « Décisions », risque 1).
- Migration de données : aucune (pas de champ, pas de colonne).

## Décisions (nuit)

1. **Runs d'un job de copie** : le champ ESI `runs` stocké dans `CachedIndustryJob` est comparé aux `runs` de la ligne tel quel, comme la fabrication (l'issue le demande : « runs within the line remainder »). L'ESI renvoie aussi `licensed_runs` (runs par copie), non stocké ; le rapport tient donc pour le nombre de runs du job, pas pour le nombre de copies. Risque noté « À relire à la fin ».
2. **Pas de contribution `JobInstall` dérivée** sur copie et invention (règle 8). Raison : le front n'offre que le type `bpc` sur ces lignes et la dédup de `DerivedContributionService` compare des contributions du même type ; une `JobInstall` dérivée doublerait un BPC déclaré (coût de copie inclus) dans `ProjectDistributionService`, et le rapprochement rétroactif des projets ouverts au déploiement l'aurait créée tout de suite. Autre voie écartée (à décider par Sylvain) : étendre la dédup au couple `Bpc`↔`JobInstall` du même installateur et dériver le coût d'installation. Aucun montant ne change.
3. **Ligne d'invention sans donnée d'invention** : ignorée par la liste manuelle (`AvailableJobsProvider`), 400 explicite au lien manuel, échec de ligne `inventionDataMissing` côté matcher automatique.
4. **`LineRentalSuggester`** : aucune suggestion de location sur une ligne qui n'est pas de fabrication ni de réaction (barème de fabrication inadapté à des jours de slot science).
5. **Prédicat distinct** de `isManufacturingOrReactionJob()` plutôt que d'élargir celui-ci, pour ne pas changer les quantités produites et les consommations de matières.
