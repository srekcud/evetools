# Spec #341 lot 2 : tableau « Structures » du projet et sa ressource API

Mode nuit. Maquette `industry-project-v3` page 7 (`renderStructures`). Le lot 1 (PR 382, fusionnée) a livré le comparateur au coût total, la politique du projet et le branchement du choix. Ce lot expose la comparaison à l'écran. Spec #341 : `docs/specs/341-structure-total-cost.md`.

## Règles

1. **Décomposition** : `StructureTotalCostComparator::breakdownOf` renvoie un `StructureCostBreakdown` (matériaux, installation, transport en `Cost`, plus `transportedCubicMeters` et `jumps`) ; `totalCostOf` en devient `breakdownOf(...)->total()`. Aucune règle du lot 1 ne change (tests existants verts sans modification). Sans système favori (`null`) : transport inconnu (`MissingData::jumpRoute`), matériaux et installation calculés. Structure sans système connu : installation inconnue (`MissingData::costIndex`) et transport inconnu, matériaux calculés.
2. **Groupe** : un `JobStructureComparison` par couple (produit, activité) des jobs de fabrication et de réaction du plan du propriétaire (`PlannedProduction`), dans l'ordre du plan. Copie et invention : exclues (pas de coût total, hors périmètre du lot 1). `jobs` = nombre de jobs, `runs` = somme des runs.
3. **Coût d'une structure pour un groupe** = somme des `breakdownOf` de chaque job du groupe (runs du job, ME 0 comme le comparateur du lot 1, catégorie de rig du produit par `RigCategories`). Somme de `Cost` : un coût inconnu rend l'ensemble inconnu ; m³ et sauts : inconnus si un job l'est.
4. **Candidates** : structures du propriétaire qui peuvent faire l'activité (`canRun`). Aucune candidate : groupe sans ligne, `chosenStructureId` null.
5. **Ordre des lignes** : structure du système favori de l'activité d'abord, puis total connu croissant, totaux inconnus en dernier, puis identifiant.
6. **Retenue** : `chosenStructureId` et `choiceStatus` viennent du plan (`PlannedProduction::structureChoiceFor`), jamais recalculés. Statuts exposés : `assigned`, `best_in_favorite_system`, `best_configured`, `best_outside_favorite_system`, `best_by_total_cost`, `not_configured`.
7. **Écart** d'une ligne = total de la ligne − total de la retenue, arrondi à 2 décimales ; `null` si l'un des deux est inconnu ; 0 pour la retenue.
8. **Moins chère** : `cheapestStructureId` = première ligne de total connu minimal ; `savingIfCheapest` = total de la retenue − total de la moins chère, seulement si la retenue est connue, que la moins chère n'est pas la retenue et que l'écart est > 0 ; sinon `null`.
9. **Ressource** `GET /industry/projects/{projectId}/structures` (`ProjectStructuresResource`, `skip_null_values` à false, `Link` sur le projet, provider `ProjectStructuresProvider`) : `id`, `acceptsOutsideFavoriteSystem`, `minimumOutsideSaving`, `transportIskPerCubicMeterJump` (constante du comparateur), `groups`. Même accès que la BOM (`assertAcceptedMember`), 404 projet inconnu, 422 si le plan échoue (comme `/buy-or-build`). Données : structures et système favori du **propriétaire**.
10. **Groupe sérialisé** : `typeId`, `typeName`, `activity` (`manufacturing`, `reaction`), `jobs`, `runs`, `chosenStructureId`, `choiceStatus`, `cheapestStructureId`, `savingIfCheapest`, `rows`. **Ligne** : `structureId`, `name`, `kind` (type de la configuration : raitaru, azbel, sotiyo, athanor, tatara), `solarSystemId`, `solarSystemName`, `inFavoriteSystem`, `jumps`, `materialBonusPercent` (`(1 − multiplicateur de matériaux pour la catégorie) × 100`, arrondi à 2 décimales), `facilityTaxPercent`, `materialsCost`, `installationCost`, `transportCost`, `transportedCubicMeters`, `totalCost`, `delta`, `missingData` (de la somme des trois coûts). Montants arrondis à 2 décimales, `null` si inconnu, jamais 0. Nom ou système introuvables : `null`.
11. **Front** : carte `ProjectStructures.vue` dans l'onglet Chaîne, sous la carte acheter ou fabriquer, store `stores/group-industry/structures.ts`, rechargée par les événements Mercure du projet comme `ProjectBuyOrBuild.vue` (topics existants, aucune nouvelle action async). Par groupe : titre (produit, activité, jobs, runs), tableau Structure (nom, type · système · « préférée » ou N sauts) · Bonus matériaux · Matériaux · Installation (taxe) · Transport (m³ × sauts) · Total · Écart, ★ sur la retenue, valeur inconnue = `UnknownContributionValue`.
12. **Bandeaux** (par groupe) : `savingIfCheapest` non null et projet n'acceptant pas hors système favori : avertissement « {structure} reviendrait {économie} moins cher, transport compris, non retenue : le projet n'accepte que le système préféré » ; projet acceptant et économie < `minimumOutsideSaving` : « économie sous le seuil du projet » ; projet acceptant et économie ≥ seuil : « non retenue » générique. Statut `best_by_total_cost` : bandeau d'information « retenue hors du système préféré : coût total le plus bas ». Pas de bouton « Autoriser pour ce projet » (option non modifiable, lot 1).
13. Textes fr et en, HUD EVE, noms de structure longs tronqués avec `title`.

## Exemples chiffrés (comparateur du lot 1, `Example`)

Job 10 runs ; A (système favori) 10 900 000, C (3 sauts) 10 750 000, D (8 sauts) 12 250 000. Projet n'acceptant que le système favori, retenue A :

| Ligne | Matériaux | Installation | Transport | Total | Écart |
|---|---|---|---|---|---|
| A (préférée, retenue) | 10 000 000 | 900 000 | 0 | 10 900 000 | 0 |
| C | 9 000 000 | 850 000 | 900 000 | 10 750 000 | −150 000 |
| D | 9 000 000 | 850 000 | 2 400 000 | 12 250 000 | +1 350 000 |

`cheapestStructureId` = C, `savingIfCheapest` = 150 000. Même projet acceptant avec seuil 100 000 : retenue C (`best_by_total_cost`), `savingIfCheapest` null, écart de A = +150 000.

## Cas limites

- Deux jobs du même produit (plafond de runs) : une seule ligne par structure, coûts sommés.
- Prix de marché absent : matériaux, total et écart `null`, `missingData` nomme le typeId ; les autres colonnes restent affichées.
- Aucun système favori pour l'activité : transport et total `null` pour toutes les lignes, aucune « moins chère » ; la retenue vient du plan.
- Projet sans fabrication ni réaction : `groups` vide, carte « rien à comparer ».
- Un participant non propriétaire lit les structures du propriétaire (mêmes données que le nom de structure déjà exposé par `/chain`).

## Hors périmètre

Modifier l'option d'un projet existant (bouton « Autoriser pour ce projet » : PATCH + recalcul de la BOM, décision de Sylvain) ; carte « Santé des structures » (carburant, services : scope et ESI absents) ; bonus de rôle et de rig affichés séparément (le bonus total suffit, `ProductionStructure` ne porte pas le libellé du rig) ; copie et invention au coût total ; Ansiblex dans les sauts ; ME du comparateur toujours 0 ; seuil appliqué par groupe.

## Glossaire

Ajout : **Comparaison des structures** (par groupe de jobs : coût total de chaque structure candidate, écart à la retenue, moins chère).
