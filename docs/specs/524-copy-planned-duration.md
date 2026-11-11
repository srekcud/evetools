# Spec #524 : durée prévue de la copie sur le nœud copie de la chaîne

Lot 2 de l'investigation #276 (lot 1 = #523, fusionné). Niveau 3 (code Industry, persistance d'une BOM). Validée par Sylvain le 2026-11-11.

## Contexte
Aucun écran n'affiche la durée d'une copie : le nœud copie de la chaîne (`GET /industry/projects/{projectId}/chain`, `ProjectChainProvider` puis `ProjectChainBuilder::nodeOf`) n'expose que les jobs ESI liés (`jobOf`, `endDate`) et la colonne « Fin » de `ProductionChainJobTree.vue` n'affiche que la dernière date de fin ESI. Le noyau sait calculer la durée depuis #523 (`JobDuration::forCopyJob`, `SkillTimeModifier::forCopy`, `ProductionStructure::timeMultiplierFor(Copying)`).

Le plan du projet ne retient aucune structure pour la copie : `IndustryProjectService::copyLinesOf` et `replaceT2BlueprintCopiesByInventions` écrivent `plannedStructureId` et `plannedStructureStatus` à `null`, `ProductionPlanning::planWithStructuresOf` ne choisit une structure que pour les jobs du plan (fabrication, réaction), et `ProjectChainBuilder::nodeOf` ne calcule `plannedStructure` que pour les étapes `component` et `product`. Le seul choix de structure de copie existant est celui du coût (`ProductionCostCalculator::installCostOutsidePlan` → `StructureSelector::select(Copying, null)` → `lowestInstallRate`), hors projet.

Décisions de Sylvain (2026-11-11) : affichage sur le nœud copie seul, le planning reste sans copie (spec 338 R6 inchangée) ; porteur = structure retenue par le plan pour la copie et compétences Science et Advanced Industry du propriétaire ; job ESI lié : la date de fin ESI reste affichée ; on code sur le modèle SDE de #523 (relevés en jeu non faits).

## Règles
1. **Structure de copie du plan** : à la construction de la BOM (`buildBom`), le plan choisit une structure de copie avec la même sélection que le coût de copie : `StructureSelector::select(ActivityKind::Copying, null)` sur les structures et les systèmes favoris du propriétaire (taux d'installation minimal, puis système favori, puis la plus récente). Le choix ne dépend pas du produit : toutes les lignes `copy` d'un projet (`:copy` et `:copy:invention`) reçoivent le même `plannedStructureId` et `plannedStructureStatus` (`best_in_favorite_system`, `best_configured`, ou `not_configured` sans structure apte). Les lignes `invention` gardent `null` (hors lot). Pas de migration : les deux colonnes existent et sont nullables.
2. **Reconstruction** : `reconcileBomLines` → `updateLineInPlace` recopie déjà ces deux champs ; une ligne copie existante reçoit sa structure à la prochaine reconstruction de la BOM (PATCH du projet).
3. **Structure exposée** : le nœud d'une ligne `copy` expose `plannedStructure` (nom et statut) comme un nœud de fabrication ; `ProductionChainJobTree.vue` l'affiche déjà quand il n'est pas `null`. Les nœuds `invention` restent sans `plannedStructure`.
4. **Durée prévue** : chaque nœud `copy` porte une durée prévue `{seconds, missingData}` (même forme que `ResearchDuration`). Les autres nœuds ne la portent pas (`null`).
   `seconds = JobDuration::forCopyJob(temps de base, runs de la ligne, SkillTimeModifier::forCopy(Science, Advanced Industry) × multiplicateur de structure)`, un seul arrondi.
5. **Temps de base** : `time` de l'activité 5 (copie) du blueprint de fabrication du `typeId` de la ligne (`IndustryActivityProductRepository::findBlueprintForProduct(typeId, Manufacturing)`, puis `IndustryActivity::getTime()`). La ligne `:copy:invention` porte le produit T1 : on lit le blueprint T1.
6. **Runs** : les runs de la ligne. `:copy` = somme des runs de fabrication du produit (BPC sous plafond, lignes fusionnées par `copyLinesOf`) ; `:copy:invention` = nombre de tentatives (une copie d'un run par tentative, `COPY_RUNS_PER_ATTEMPT` = 1, sommées sur les produits T2 qui partagent le T1). Dans les deux cas la durée vaut `temps de base × runs × multiplicateurs`, cohérent avec `forCopyJob` (le temps de copie se compte par run copié).
7. **Multiplicateur de structure** : statut `not_configured` → durée inconnue, cause « aucune structure ne peut copier » (D2 : pas de station NPC par défaut) ; structure prévue présente dans `ConfiguredProductionStructures::configuredBy(propriétaire)` → `timeMultiplierFor(Copying)` (rôle 2602 × rigs de copie, sécurité de la structure) ; structure prévue absente (supprimée) → durée inconnue, cause « structure prévue supprimée ».
8. **Compétences** : niveaux Science (3402) et Advanced Industry (3388) d'un personnage du propriétaire, lus dans `CachedCharacterSkill` (la sync écrit ces deux compétences à 0 quand elles ne sont pas apprises : une ligne absente = jamais synchronisé = inconnu). Personnage retenu : celui du propriétaire dont les deux niveaux sont connus et dont `forCopy` est le plus petit (durée la plus courte), égalité départagée par nom (voir décision D1). Aucun personnage aux deux niveaux connus → durée inconnue, cause « compétences de copie inconnues ». Jamais de niveau par défaut.
9. **Nom du personnage** : la durée prévue porte le nom du personnage retenu, montré au propriétaire ; un autre membre voit le nom du pilote du propriétaire (`PilotName`, même règle que `JobInstallerName::seenBy`).
10. **Ligne non planifiée** : une ligne `copy` sans `plannedStructureStatus` (BOM construite avant ce lot) → durée inconnue, cause « structure de copie non planifiée » (jamais la station NPC par défaut).
11. **Affichage** : colonne « Fin » de l'arbre des jobs et nœud copie du flux. Nœud sans job lié (après filtre `STATUSES_WITHOUT_OUTPUT`) : durée prévue (« prévu : 8 h 51 min », personnage en infobulle) ou « inconnu » avec sa cause. Nœud avec au moins un job lié : la date de fin ESI, comme aujourd'hui ; la durée prévue reste dans la réponse API mais n'est pas affichée.
12. **Glossaire** : ajouter « Durée prévue de copie » ; modifier « Structure conseillée » (posée aussi sur les lignes de copie, plus « absente pour la copie »).

## Exemples chiffrés (tests rouges)
Sécurité null ×2,1 ; XL Laboratory Optimization II : temps −24 % ; Sotiyo 0,70, Raitaru 0,85. Multiplicateur de compétence `forCopy(S, AI) = (1 − 0,05 S)(1 − 0,03 AI)`.

| Cas | Entrée | Attendu |
|---|---|---|
| Doré Hornet (relevé en jeu) | 240 s × 600 runs, Sotiyo + XL Lab II nullsec, S5 / AI5 | 0,6375 × 0,70 × 0,496 → **31 873 s** |
| Raven sans compétence | 14 400 s × 1 run, Raitaru sans rig, S0 / AI0 (connus) | 14 400 × 0,85 → **12 240 s** |
| Compétences partielles | Raven, Raitaru sans rig, S4 / AI3 | 0,80 × 0,91 = 0,728 ; × 0,85 → **8 911 s** (8 910,72) |
| Meilleur personnage | Raven Raitaru ; A : S5 / AI3 (0,6825), B : S4 / AI5 (0,68) | B retenu → **8 323 s** (A aurait 8 354 s) |
| Personnage inconnu écarté | A : Science connue, AI absente ; B : S0 / AI0 | B retenu → 12 240 s (Raven Raitaru) |
| Aucune structure apte | Raven, statut `not_configured`, S5 / AI5 | `seconds` null, cause « aucune structure ne peut copier » |
| Copie pour invention | ligne `:copy:invention` Hornet, 12 tentatives, Sotiyo + XL Lab II null, S5 / AI5 | 2 880 × 0,6375 × 0,3472 → **637 s** |
| Nœud fusionné | ligne `:copy` Hornet 1 200 runs (deux jobs de 600), mêmes réglages que le doré | **63 746 s** |
| Rig de fabrication seul | Sotiyo + XL Ship Manufacturing Efficiency II, null, Raven S0 / AI0 | rôle seul : 14 400 × 0,70 → **10 080 s** |
| Structure supprimée | `plannedStructureId` absent des structures du propriétaire | `seconds` null, cause « structure prévue supprimée » |
| Aucun personnage connu | aucune ligne Science ni AI en cache | `seconds` null, cause « compétences de copie inconnues » |
| BOM antérieure | ligne `copy`, statut `null` | `seconds` null, cause « structure de copie non planifiée » |
| Sélection | Raitaru (taux 5 %) et Sotiyo + XL Lab II (taux 6 %), même système | Raitaru retenue (taux minimal, comme le coût), durée Raitaru |
| BOM | projet avec une copie T1 et une copie pour invention, Raitaru seule apte | les deux lignes `copy` : Raitaru, `best_configured` ; ligne `invention` : `null` |
| Job lié | nœud copie avec un job ESI actif | colonne « Fin » = `endDate` ESI ; `plannedCopyDuration` présent dans la réponse |

## Cas limites
- Structure sans rig : rôle seul. Rig de fabrication, d'invention ou de recherche : aucun effet sur la copie (#523 R6).
- Raffinerie (Athanor, Tatara) seule : ne peut pas copier (`StructureType::canRun`) → `not_configured` → durée inconnue (R7).
- Temps de base absent du SDE (blueprint ou activité 5 disparus après réimport) : durée inconnue, cause « temps de base de copie absent », jamais 0.
- Runs au-delà du plafond d'un BPC : une seule durée séquentielle (un job qui produit plusieurs copies), pas de découpe en jobs parallèles.
- Projet `completed` : la BOM n'est plus reconstruite ; ses lignes copie gardent leur statut (souvent `null`, donc inconnu).
- Plusieurs systèmes favoris : la copie suit le favori de fabrication (`FavoriteSystems::forActivity`), comme le coût.

## Accès et fraîcheur
- Même accès que la chaîne : `assertAcceptedMember`. Aucun identifiant reçu d'un autre périmètre : structure, personnages et compétences sont ceux du propriétaire, lus côté serveur.
- Un membre voit la durée calculée sur les compétences du propriétaire, sans le nom de son alt (R9).
- Fraîcheur : compétences = dernière sync des compétences (à la demande, `SyncCharacterSkillsProcessor`) ; structure = choix figé à la dernière construction de la BOM (cost indices du moment) ; rigs et sécurité = configuration actuelle de la structure, relue à chaque lecture.

## Tests existants contredits
- `IndustryProjectServiceTest::testJobLinesOfTheProductAndOfItsComponentsKeepThePlannedStructureAndHowItWasChosenWhileTheCopyAndMaterialsHaveNone` (l. 757) et `::testInventionAndCopyOfAnInventedProductHaveNoPlannedStructureNextToItsPlannedManufacturingJob` (l. 840) : la ligne `copy` reçoit désormais une structure (R1) ; renommage et valeurs attendues.
- `ProjectChainBuilderTest::testLineWithoutPlannedStructureAndCopyLineHaveNoPlannedStructure` (l. 343) : le nœud copie expose sa structure prévue (R3).
- Glossaire « Structure conseillée » : « absente (null) pour la copie » devient faux.

## Hors périmètre
- Planning (slots de science, spec 338 R6), durée d'invention, structure prévue d'une ligne `invention`.
- Durée de fabrication ou de réaction sur la chaîne ; découpe d'une copie en jobs parallèles.
- Reconstruction automatique des BOM existantes ; relevés en jeu de #276 §7 (corrigeront le noyau si besoin).
- Maquette : changement limité à une valeur dans la colonne « Fin » existante (à confirmer, voir D4).

## Termes du glossaire
- **Durée prévue de copie** (Planned copy duration) : durée d'une ligne de copie d'un projet, `runs × temps de base de copie × multiplicateur de compétence (copie) × bonus de temps de structure × rigs de copie`, calculée à la lecture sur la structure de copie du plan et le personnage du propriétaire aux compétences de copie connues les plus rapides ; inconnue avec sa cause, jamais par défaut. Affichée sur le nœud copie sans job lié.
- **Structure conseillée** : posée aussi sur les lignes de copie (sélection au taux d'installation minimal) ; toujours absente pour l'invention et les matériaux.

## Décisions de Sylvain (2026-11-11)
- **D1 Personnage** : le personnage du propriétaire le plus rapide parmi ceux aux deux compétences connues, nommé dans la réponse.
- **D2 Sans structure apte** : durée inconnue « aucune structure ne peut copier », pas de station NPC.
- **D3 BOM antérieures** : durée inconnue jusqu'à la prochaine reconstruction (R10), pas de recalcul à la lecture.
- **D4 Maquette** : aucune, la valeur prend place dans la colonne « Fin » existante.
