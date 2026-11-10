# Spec #338 : planning réel contre prévu et répartition des jobs par personnage

Maquette retenue : `industry-project-v3.html` pages 9 (Planning) et 10 (arbre des jobs, « Qui lance quoi »). Tranche livrée : la planification au plus tôt, son API et l'onglet Planning. Reste hors périmètre (voir fin) : bonus de structure, plan figé persisté, personnages des autres participants. Mode nuit : spec validée en autonomie.

## Contexte vérifié sur origin/main

- Lignes de job de la BOM (`IndustryProjectBomLine::isManufacturingOrReactionJob`) : `typeId`, `runs`, `teLevel`, `activityType` (`manufacturing`, `reaction`), `jobGroup`, `parentTypeId` (job consommateur). Les lignes de matériau n'ont pas de lien vers le job qui les consomme (feuilles agrégées) : l'attente de matériaux est une cause du projet, pas d'un job.
- Durée de base : `Recipe::$baseTimeSeconds` (`BlueprintCatalog::recipeFor`). Formule : `Domain\JobDuration::forManufacturingJob` / `forReactionJob`, compétences : `Domain\SkillTimeModifier::forManufacturing(industry, advancedIndustry, niveaux des compétences science du blueprint)` / `forReaction`. Aucun appelant à ce jour : cette tranche les branche.
- Compétences en cache : `CachedCharacterSkill` (`SKILL_INDUSTRY`, `SKILL_ADVANCED_INDUSTRY`, `SKILL_REACTIONS`), `CachedCharacterSkillRepository::findAllSkillsForCharacter` / `hasCachedSkills` ; compétences science requises par blueprint : `IndustryActivitySkillRepository::findSkillsForBlueprints`. Slots maximum : `SlotTrackerService::calculateMaxSlots` (clés `manufacturing`, `reaction`).
- Réel : `IndustryProjectJobMatch` (`esiJobId`, `status`, `endDate`, `characterName`) ; la date de début vient de `CachedIndustryJob` (`startDate`, `endDate`, par `jobId`).
- Absent : bonus de temps de structure et de rig (`ProductionStructure` ne porte que les matériaux ; `SdeStructureBonusReader::timeMultiplierOf` et `SdeRigBonusReader::timeBonusOf` ne sont pas branchés), toute persistance d'un plan.

## Règles

- **R1 — Planificateur pur (Domain).** `ProductionSchedule::earliest(jobs, workers, start, fixedEnds)` : jobs `ScheduleJob` (id, `ActivityKind` fabrication ou réaction, durée en secondes **par travailleur**, ids dont il dépend), travailleurs `ScheduleWorker` (id, nombre de slots par activité), `start`, `fixedEnds` (fin imposée par job id). Retourne un `Schedule` : affectations (job, travailleur, début, fin), jobs non planifiés avec leur raison, fin du plan. Aucun accès à Doctrine, au marché ni à l'horloge.
- **R2 — Ordre et choix.** Jobs pris dans l'ordre topologique, à égalité dans l'ordre d'entrée. Un job démarre à `max(start, fin du dernier job dont il dépend, libération du slot)`. Pour chaque travailleur qui a une durée pour ce job et un slot de l'activité, on calcule la fin ; on garde la fin la plus tôt, à égalité le premier travailleur de la liste ; le slot pris est le premier libre de ce travailleur. Un job de `fixedEnds` n'est pas planifié : sa fin sert seulement de dépendance. Un travailleur sans durée pour un job ne le reçoit pas.
- **R3 — Non planifié, jamais inventé.** Aucun travailleur éligible : raison `no_eligible_worker`. Un job dont une dépendance est non planifiée : `dependency_unscheduled`. Un cycle de dépendances : `\DomainException`. La fin du plan vaut `null` s'il reste un job non planifié (pas de fin partielle).
- **R4 — Durée par personnage (Application).** `JobDuration` sur la durée de base de la recette, les runs et le `teLevel` de la ligne, avec `SkillTimeModifier` du personnage : fabrication = Industry × Advanced Industry × compétences science du blueprint producteur (les niveaux du personnage, 0 s'il ne l'a pas entraînée) ; réaction = Reactions. Ligne sans `teLevel`, sans recette ou dont la recette n'est pas de l'activité de la ligne : durée inconnue pour tous, job `duration_unknown`. **Sans bonus de structure ni de rig** dans la tranche 1 ; la tranche 2 (R13) les ajoute.
- **R5 — Travailleurs.** Les personnages du propriétaire du projet. Un personnage sans aucune compétence en cache : listé avec `skillsUnknown: true`, exclu du planning (jamais niveau 0 par défaut). Slots du plan = slots maximum par `calculateMaxSlots` (le plan suppose tous les slots libres, les autres projets sont ignorés).
- **R6 — Prévu.** Planifie toutes les lignes de job de fabrication et de réaction (copie et invention exclues : slots science), départ à `IndustryProject::getCreatedAt`, matériaux supposés prêts, sans `fixedEnds`. Dépendances : une ligne dépend de toutes les lignes dont le `parentTypeId` est son `typeId`. Le prévu se recalcule à la lecture (aucune migration) : il ne change que si les compétences, les slots ou la BOM changent. `plannedEnd` = fin du plan.
- **R7 — Réel.** Par ligne, les jobs liés non `cancelled` ni `reverted` : début = plus petit `startDate`, fin = plus grande `endDate` (l'`end_date` de l'ESI fait foi), statut `done` si les runs livrés couvrent la ligne, `active` sinon (même règle que `ProjectChainBuilder`) ; un statut hors `active`, `paused`, `ready`, `delivered` : erreur, pas de valeur par défaut.
- **R8 — Fin attendue et écart.** Second calcul R2 au départ « maintenant » : lignes liées `done` ou `active` en `fixedEnds` (leur `endDate`), les autres replanifiées. `expectedEnd` = la plus tardive des fins réelles et replanifiées, `null` si un job reste non planifié ou si le prévu est `null`. `delaySeconds` = `expectedEnd` − `plannedEnd` (signé, `null` si l'un est `null`).
- **R9 — Attente expliquée.** Un job est en retard si son début réel (ou, sans lien, son début replanifié) dépasse son début prévu (`lateSeconds`). Cause : `waiting_job` si une ligne dont il dépend finit (fin réelle, sinon replanifiée) après son début prévu (noms de ces lignes), sinon `waiting_materials` si une ligne de matériau a `fulfilledQuantity` < `requiredQuantity` (noms des matériaux manquants du projet), sinon `unknown` sans nom. `delayCause` du projet = l'attente du job dont le retard est le plus grand, `null` si aucun job n'attend. Une ligne de durée inconnue est passée au planificateur sans durée (`no_eligible_worker`) et listée `duration_unknown` dans `unscheduled`.
- **R10 — Ressource.** `GET /api/industry/projects/{projectId}/planning` (`ProjectPlanningResource`, `skip_null_values: false`, `uriVariables` avec `Link`), accès `assertAcceptedMember`, projet inconnu 404 « Project not found » avant tout. Champs : `anchor`, `structureTimeBonusIncluded`, `plannedEnd`, `expectedEnd`, `delaySeconds`, `delayCause` (`{kind, names, lateSeconds}`), `characters` (`characterName`, `skillsUnknown`, `slots` {manufacturing, reaction} ou null, `skills` {industry, advancedIndustry, reactions} ou null, `endsAt`, `jobs`), `unscheduled` (`lineId`, `typeName`, `reason`). Un job : `lineId`, `typeId`, `typeName`, `activity`, `runs`, `planned` {start, end} ou null, `real` {start, end, status} ou null, `wait` {kind, names, lateSeconds} ou null. Un job est dans la ligne du personnage qui le fait réellement (job lié), sinon de son personnage prévu ; ligne d'un nom de personnage inconnu du propriétaire créée au besoin.
- **R11 — Écran.** Onglet « Planning » du cockpit (remplace l'onglet « bientôt » de `projectDetailTabs.ts`, invention et journal restent) : `ProjectPlanning.vue`, store `group-industry/planning.ts`. Trois chiffres (fin prévue, fin attendue, écart avec sa cause), Gantt une ligne par personnage (barre pointillée prévu, pleine réel : accent en cours, succès fait, hachurée ambre attente, repère « maintenant »), tableau « Qui lance quoi » (personnage, slots fabrication et réaction, compétences de temps, jobs proposés, fin), avis « durées sans bonus de structure ». Inconnu : « inconnu » violet pointillé avec sa cause (compétences non synchronisées, durée inconnue), jamais 0 ni date inventée. Rafraîchi sur les mêmes événements Mercure que la chaîne (`getGroupProjectEvent`, `getSyncProgress('industry-project')`) ; aucune action asynchrone nouvelle, donc aucun topic.
- **R12 — Libellés** FR et EN, noms de personnage et de type très longs tronqués avec titre complet. Direction HUD EVE.

## Exemples chiffrés

| # | Cas | Résultat |
|---|---|---|
| E1 | 1 travailleur, 1 slot de fabrication ; jobs A 3 600 s et B 7 200 s indépendants, départ 00:00 | A 00:00 à 01:00, B 01:00 à 03:00, fin du plan 03:00 |
| E2 | Idem avec 2 slots | A et B démarrent à 00:00, fin 02:00 |
| E3 | C dépend de A et B (E1) | C démarre à 03:00 |
| E4 | Job X : W1 3 600 s, W2 3 000 s, slots libres | X chez W2, fin 00:50 |
| E5 | Job Y : aucune durée pour aucun travailleur | non planifié `no_eligible_worker`, fin du plan `null` |
| E6 | Job Z dépend de Y (E5) | `dependency_unscheduled` |
| E7 | Fabrication, base 6 000 s, 10 runs, TE 20, Industry 5, Advanced Industry 5, aucune compétence science | 10 × 6 000 × 0,8 (TE) × 0,8 (Industry) × 0,85 (Advanced Industry) = 32 640 s |
| E8 | Réaction, base 3 600 s, 5 runs, Reactions 4 | 5 × 3 600 × (1 − 0,16) = 15 120 s |
| E9 | Composant prévu fini à 08:00 mais livré à 12:00 ; produit prévu 08:00 à 10:00, lancé à 12:00 pour 2 h ; PAU manquant | `plannedEnd` 10:00, `expectedEnd` 14:00, écart +4 h ; attente du produit `waiting_job` [composant] (composant pas `done` à 08:00), pas `waiting_materials` |

## Cas limites

- Projet sans ligne de job de fabrication ni de réaction : `characters` listés sans job, `plannedEnd`, `expectedEnd`, `delaySeconds` `null`, aucune erreur.
- Aucun personnage avec compétences : tous les jobs `no_eligible_worker`, écran « compétences non synchronisées ».
- Job lié `delivered` plus tôt que prévu : écart négatif affiché comme avance, jamais clampé à 0.
- Projet `completed` : lecture seule identique, `expectedEnd` = fin du dernier job réel.
- Un job lié par un personnage absent du propriétaire : ligne portant le nom du lien, `slots` et `skills` `null`.

## Hors périmètre (décisions ouvertes au journal)

1. ~~Bonus de temps de structure et de rig~~ : livré par la tranche 2 (R13).
2. Plan figé persisté à la création (« plan figé le 02/11 18:40 » de la maquette) : demande une migration.
3. Personnages des autres participants et prise ou rendu d'une proposition depuis le compagnon : exposition de leurs compétences à décider.
4. Occupation des slots par d'autres projets (ligne « autre projet » de la maquette), copie et invention (slots science).

## Tranche 2 : bonus de temps de structure et de rig (reliquat, 2026-11-11)

- **R13 — Temps de la structure prévue.** `ProductionStructure::jobTimeMultiplierFor(activité, catégorie du produit)` = bonus de rôle de temps (fabrication : `timeRoleBonus`, réaction : `reactionTimeRoleBonus`, attribut `strReactionTimeMultiplier`) × `1 − bonus % × modificateur de sécurité / 100` de chaque `TimeRig` dont les catégories visent le produit ; copie et autres activités : `InvalidArgumentException` ; sans catégorie, bonus de rôle seul. `ConfiguredProductionStructures` crée un `TimeRig` par rig dont le nom vise des catégories et dont le bonus de temps SDE (`SdeRigBonusReader::timeBonusOf`, 2593 fabrication, 2713 réaction) est positif ; les rigs de laboratoire n'en créent pas.
- **R14 — Modificateur par ligne.** `PlannedJobTimeModifier::of(plannedStructureId, structures du propriétaire, type du produit, activité)` : pas de structure prévue (poste sans structure configurée) = neutre ; structure prévue retrouvée = `jobTimeMultiplierFor` avec `RigCategories::categoryOf` ; structure prévue absente de la configuration (supprimée) = `null`, inconnu, jamais neutre en silence. `PlanningLine::$structureTimeModifier` et `$structureTimeKnown` portent le résultat ; le builder multiplie le modificateur de compétences par celui de la structure.
- **R15 — Drapeau.** `structureTimeBonusIncluded` = toutes les lignes ont leur temps de structure connu (vrai sans ligne). Une ligne inconnue garde sa durée de base et l'écran affiche l'avis (structure prévue plus configurée).
- Sans migration : `IndustryProjectBomLine::plannedStructureId` (#337) et la configuration des structures existent déjà.
