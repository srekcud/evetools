# #1285 — One station per type of work of the BOM

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`#stations`). Back-end seulement : l'écran des stations est l'étape 2 du wizard #1289 (données et libellés du prototype fournis par l'API).

## Contexte

Aujourd'hui la comparaison `GET /industry/projects/{id}/structures` classe les structures produit par produit, avec un transport « m³ × sauts × 150 », et le plan choisit seul la structure de chaque job (`StructureSelector`). Le flux demande une station par type de travail, comparée avec les trajets entre stations et la règle du hub de courier (#1286), et un choix qui oriente le plan.

## Règles

R1. Type de travail (`WorkType`, Application) : `reaction`, `components`, `hulls`, `final_products`, `blueprints` (copie et invention). Classement d'un job du plan par `WorkTypeClassifier` : activité réaction → `reaction` ; sinon produit cible du projet → `final_products` ; sinon produit de la catégorie SDE Ship (6) → `hulls` ; sinon `components`. `blueprints` n'existe pour un projet que si sa BOM porte une ligne `jobGroup = blueprint`. Un type sans job n'apparaît pas. Port `ShipProducts::isShip(int $typeId): bool` (adaptateur SDE `SdeShipProducts`, lié dans `config/services.yaml`).

R2. Stockage : colonne JSON nullable `industry_projects.work_stations` (migration réversible, lignes existantes `null`) : `{"<workType>": {"structureId": "<uuid IndustryStructureConfig>", "recommendedStructureId": "<uuid>|null"}}`. `null` ou clé absente = choix automatique, comportement actuel inchangé.

R3. Le plan applique le choix. `ProductionPlanRequest` reçoit `workStructures: array<string, ProductionStructure>` (clé = valeur de `WorkType`, défaut `[]`). `StructureMaterialModifiers` passe la structure du type du job comme structure assignée au `StructureSelector` (statut `Assigned`, donc `plannedStructureStatus = 'assigned'` sur les lignes de BOM) ; la copie prend `workStructures['blueprints']` comme structure assignée de `select(Copying, null, …)`. `ProductionPlanning` reçoit un `?WorkTypeClassifier` optionnel (comme `$totalCostComparator`) : des `workStructures` non vides sans classifier lèvent `LogicException`. `IndustryProjectService::plannedProductionOf` remplit `workStructures` depuis le projet. Une structure stockée absente des structures de l'utilisateur, ou qui ne sait plus faire l'activité, est ignorée par le plan (sélection automatique, `ignoredAssignedStructure` si elle existe) et signalée par `choiceStale` (R5).

R4. Comparaison : `WorkStationAdvisor` (Application) calcule, par type de travail du plan, une ligne par structure candidate (`canRun(activité)`) :
- matériaux et installation = ceux de `StructureTotalCostComparator::breakdownOf` (mêmes bonus, mêmes index que la comparaison « Structures ») sommés sur les jobs du type ; son transport « sauts × 150 » n'est pas repris ;
- transport = somme de trajets (R6), puis `total = matériaux + installation + transport` ; une donnée manquante rend la ligne inconnue (jamais 0), jamais recommandée, classée après les lignes connues ;
- recommandée = total connu le plus bas ; égalité : système favori, puis structure configurée le plus récemment, puis id (ordre de `PlanStructureComparator`) ; `delta` = total − total de la recommandée ;
- les stations des autres types servent aux trajets : choix stocké valide, sinon recommandée, calculée en trois passes depuis « matériaux + installation les plus bas » (comme `recommend()` du prototype) ;
- `blueprints` : candidats `canRun(Copying)`, recommandée = choix de `StructureSelector::select(Copying, null)` (taux d'installation le plus bas, règle existante), lignes sans montants (`totalCost`, matériaux, installation, transport `null`, `costsCompared = false`) : le coût d'une copie et d'une invention par structure n'est pas modélisé ici.

R6. Trajets (volumes = quantités des jobs du plan × `ItemVolumes`, ni ME ni structure candidate recalculés) ; chaque trajet : `kind`, `fromStructureId`, `toStructureId`, `cubicMeters`, `cost` :
- `courier` : matières achetées (feuilles du plan consommées par le type) livrées à la station : `CourierHub::transportCostOf(m³, solarSystemId de la station)` (Jita → hub → station, règle de #1286) ;
- `jump_bridge` : intermédiaires produits par un autre type, de la station de ce type à la station candidate : 0 si même structure ou même système, sinon `m³ × jumpBridgeIskPerCubicMeter` ;
- `to_hub` : produits finaux, station candidate → hub : 0 si même système que le hub, sinon `m³ × jumpBridgeIskPerCubicMeter`. La vente hub → Jita est la même pour toute station : non comptée.
Système de la station inconnu : trajet `cost = null` et ligne inconnue. Les achats, le hub et la vente ne sont pas des choix (ils ne portent pas de station).

R5. Lecture : `GET /industry/projects/{projectId}/work-stations` (`ProjectWorkStationsResource`, `skip_null_values` false, `assertAcceptedMember`, plan du propriétaire comme `/structures`, 422 si le plan échoue). Réponse : `id`, `courierHubSolarSystemId`, `courierIskPerCubicMeter`, `jumpBridgeIskPerCubicMeter`, `workTypes[]` : `workType`, `activity`, `jobs`, `runs`, `recommendedStructureId`, `chosenStructureId` (stocké valide, sinon `null`), `storedRecommendedStructureId`, `choiceStale`, `savingIfRecommended` (total du choix − total de la recommandée, `null` si pas d'écart ou inconnu), `locked`, `lockReason`, `costsCompared`, `rows[]` : `structureId`, `name`, `kind`, `solarSystemId`, `solarSystemName`, `inFavoriteSystem`, `materialBonusPercent`, `facilityTaxPercent`, `materialsCost`, `installationCost`, `transportCost`, `transportLegs[]`, `totalCost`, `delta`, `missingData`.

R7. Verrou : un type est verrouillé (`locked`, `lockReason`) si une ligne de BOM du type porte un job lié (`job_launched`) ou si un achat existe sur une ligne matière consommée par un job du type (`purchase_made`, consommation lue dans les matières des jobs du plan). `lockReason` vaut `null` sinon.

R8. Écriture : `PATCH /industry/projects/{id}` accepte `workStations: {"<workType>": "<structureId>"|null}` (`UpdateProjectInput`, `assertAdminOrOwner`). Par clé : type inconnu ou absent du plan → 422 ; structure absente des structures du propriétaire ou incapable de faire l'activité du type → 422 ; type verrouillé et valeur différente de l'actuelle → 422 (même valeur : sans effet) ; `null` retire le choix et sa recommandée. Valeur acceptée : stocke `structureId` et la recommandée du moment. Après changement : `buildBom(refuseToDropWorkedLines: statut ≠ draft)` ; en cas d'échec, l'ancienne valeur est restaurée comme dans `changeTheInventionExclusion`. `IndustryProjectResource` expose `workStations` (même forme que R2, `{}` si aucun).

R9. Pas d'action asynchrone : pas de nouveau topic Mercure. Accès, scoping et montants : voir « À relire à la fin » du journal.

## Exemples chiffrés (tests rouges)

Réglages de test : courier 800 ISK/m³, pont 50, hub C-J6MT (30000772), Jita 30000142.

| Cas | Volume (m³) | Station | Trajet attendu |
|---|---|---|---|
| Achats vers une station hors hub | 100 | système A | `courier` 100 × (800 + 50) = 85 000 |
| Achats vers une station du système du hub | 100 | système du hub | `courier` 80 000 |
| Composants Azbel (A) → navires Sotiyo (hub) | 200 | A → hub | `jump_bridge` 200 × 50 = 10 000 |
| Même système, structures différentes | 200 | A → A | `jump_bridge` 0 |
| Navires à Sotiyo (hub) | 1 000 | hub | `to_hub` 0 |
| Navires à Azbel (A) | 1 000 | A | `to_hub` 50 000 |
| Système de la station inconnu | 100 | `null` | `courier` coût `null`, ligne inconnue |

| Cas | Attendu |
|---|---|
| Deux candidats, totaux 1 000 et 1 200 | recommandée = 1 000, `delta` 0 et 200 |
| Totaux égaux, l'un en système favori | recommandée = le favori |
| Un candidat inconnu | classé après, `delta` `null`, jamais recommandé |
| Réaction + composants + navires | une entrée par type, `components` compté avec les trajets depuis la station des réactions |
| Choix stocké ≠ recommandée | `chosenStructureId` = choix, `savingIfRecommended` = total(choix) − total(recommandée) |
| Structure stockée supprimée | `chosenStructureId` `null`, `choiceStale` vrai, plan automatique |

## Cas limites

- Projet sans job de réaction : pas d'entrée `reaction`. Plan non calculable : 422 comme `/structures`.
- Aucun candidat pour un type : `rows = []`, `recommendedStructureId = null`.
- Un seul candidat : recommandée = ce candidat, `delta` 0.
- `workStations` ne touche ni la politique de sélection ni les systèmes favoris : la comparaison « Structures » existante reste inchangée.

## Hors périmètre

Écran des stations (#1289), marché par ligne (#1287), acheter ou fabriquer de bas en haut (#1288), lieu des containers et stock par station (#1292, #1294), coût d'une copie et d'une invention par structure, recommandation d'accès aux structures (#1299).
