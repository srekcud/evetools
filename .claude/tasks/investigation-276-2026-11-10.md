# Investigation #276 : bonus de temps de structure d'une copie (rôle, rigs de science)

Date 2026-11-10, origin/main `af3b30ad`, lecture seule. SDE lu dans `var/sde/*.jsonl` du checkout principal (typeDogma, dogmaAttributes, dogmaEffects, blueprints, types). Décision déjà prise (commentaire du 2026-11-03) : modéliser les rigs de science. Cette note donne les faits pour la spec.

## 1. Conclusion en 5 lignes
1. Le constat de l'issue est périmé : `IndustryCalculationService::structureBonusOf` et `timePerRunOf` ont été supprimés par `82e509a1` (#362). **Aucun code de `src/` ne calcule ni n'affiche plus la durée d'une copie en structure** ; la copie est exclue du planning (`ProjectPlanningInputLoader.php:49`, spec 338 R6).
2. Le besoin reste entier : le noyau n'a **aucun bonus de temps de structure** (`ProductionStructure.php:21-31` : matériaux et coût seulement) et ne connaît **aucun rig de science**.
3. Le SDE tranche : la copie prend le bonus de rôle (attribut 2602) et les rigs d'effet `rigCopyTimeBonus` / `rigCopyCostBonus`, jamais les rigs de fabrication (effets distincts, voir §3).
4. Le catalogue `RigCatalogue` contient des rigs qui **n'existent pas dans le SDE** (M-Set et L-Set « Laboratory Optimization », `RigCatalogue.php:125-128`) et ignore les vrais rigs de science de M et L : l'hypothèse « un rig Laboratory par taille » est fausse.
5. Le relevé en jeu (Hornet, 31 873 s) est reproduit à la seconde par les données SDE.

## 2. État du dépôt (preuves)
| Point | Fichier:ligne | Comportement réel | Écart avec la règle attendue |
|---|---|---|---|
| Bonus de temps de rôle lu | `SdeStructureBonusReader.php:36-40` | Fabrication (2602) et réaction (2721) seulement ; la copie lève `InvalidArgumentException` (l. 39) ; `timeMultiplierOf` n'a aucun appelant dans `src/` | La copie prend 2602 (Raitaru 0,85, Azbel 0,80, Sotiyo 0,70 lus dans typeDogma 35825/35826/35827 ; Sotiyo = −30 % relevé en jeu) |
| Modèle de structure | `ProductionStructure.php:21-31` | Pas de champ temps ; `costRoleBonus` seul pour le coût | Prévoir `timeRoleBonus` |
| Rig du noyau | `StructureRig.php:14-17` | `materialBonus` + catégories de produit ; pas de temps, pas de coût, pas d'activité | Un rig de science vise une activité, pas une catégorie de produit |
| Lecture des rigs | `ConfiguredProductionStructures.php:97-120` | Ne lit que 2594 (matériaux) ; `timeBonusOf` (`SdeRigBonusReader.php:39`) n'a aucun appelant ; un rig sans catégorie ciblée est écarté (l. 112-115) | Les rigs de science (2594 = 0, 2593 et 2595 non nuls) sont donc ignorés |
| Nom de rig absent du SDE | `ConfiguredProductionStructures.php:103` | `UnexpectedValueException « Rig … is missing from the SDE »` | Une structure avec « Standup M-Set Laboratory Optimization II » (proposée par `RigOptionsProvider`, acceptée par `ProductionStructureRigsValidator`) fait échouer `configuredBy` (par lecture, non rejoué : les types M/L n'existent pas dans `types.jsonl`) |
| Durée d'un job | `JobDuration.php`, `SkillTimeModifier.php:23-37` | `forManufacturingJob` et `forReactionJob` ; aucun `forCopy` | Le glossaire (ligne « Multiplicateur de compétence », spec #275) décrit une formule de copie `(1 − 0,05 × Science) × (1 − 0,03 × Advanced Industry)` sans symbole dans le code |
| Coût de copie | `ProductionCostCalculator.php:224-235`, `InventionService.php:62-82` | Rôle de coût (2601) seul côté noyau ; `estimateCopyJobCost` sans rôle ni rig | Même trou côté coût (−25,2 % relevé sur #219) ; hors #276, à coordonner |

## 3. Ce que le SDE expose pour la copie (preuves)
Effets de rig (`dogmaEffects`) : `6837 rigCopyTimeBonus` (attribut 2593 vers 2570 `BPCopyTimeMultiplier`, opération PostPercent, domaine structure), `6836 rigCopyCostBonus` (2595 vers 2569). Les rigs de fabrication portent d'autres effets (`6840 rigAllShipManufactureMaterialBonus`, `6841 rigAllShipManufactureTimeBonus`) : **un rig de fabrication ne porte pas l'effet de copie**. Les mêmes attributs 2593/2595 servent à toutes les activités, d'où la nécessité de lire l'effet et non l'attribut seul.

| Taille | Rig (type) | Temps (2593) | Coût (2595) | Effets |
|---|---|---|---|---|
| M | Blueprint Copy Accelerator I / II (43893 / 43892) | −20 / −24 | 0 | 6837 |
| M | Blueprint Copy Cost Optimization I / II (43891 / 43890) | 0 | −10 / −12 | 6836 |
| L | Blueprint Copy Optimization I / II (43729 / 43730) | −20 / −24 | −10 / −12 | 6836, 6837 |
| XL | Laboratory Optimization I / II (37183 / 37182) | −20 / −24 | −10 / −12 | 6830-6837 (invention, ME, TE, copie) |

Les rigs « ME Research », « TE Research » et « Invention » existent par taille avec leurs propres effets (6830-6835) : ils ne touchent pas la copie. Modificateurs de sécurité : 2355 = 1,0 (highsec), 2356 = 1,9 (lowsec), 2357 = 2,1 (nullsec et WH), lus sur chaque rig, déjà gérés par `RigBonus::multiplierIn`.

Temps de base : `blueprints.jsonl`, Raven (688) copie 14 400 s ; Hornet I (2465) copie 240 s par run, `maxProductionLimit` 600.

**Reproduction du relevé** (Sotiyo, XL Laboratory Optimization II, nullsec, Science 5, Advanced Industry 5) : 240 × 600 = 144 000 ; × 0,75 × 0,85 (= 0,6375) = 91 800 ; × (1 − 24 × 2,1 / 100 = 0,496) = 45 532,8 ; × 0,70 = 31 872,96, soit 31 873 s (08:51:13), valeur affichée en jeu. Rôle seul (A2) : 91 800 × 0,70 = 64 260 s. Raven en Raitaru sans rig : 14 400 × 0,85 = 12 240 s (sans compétence).

## 4. Causes
- C1 : le noyau ne modélise pas le temps de structure (rôle ni rig) ; la durée de copie n'existe plus qu'en doc.
- C2 : le catalogue et la lecture des rigs supposent « un rig = un bonus de matériaux sur des catégories de produit » ; les rigs de science sont activité-ciblés et portent temps et coût.
- C3 : `RigCatalogue` mélange noms inexistants (M/L « Laboratory Optimization ») et manque les 8 rigs M et 4 rigs L de copie.

## 5. Options de correctif
**Option 1 (recommandée), en deux lots, circuit m puis m** (noyau d'abord, écran ensuite)
- Lot 1, pur noyau, sans écran : `ProductionStructure::timeRoleBonus`, `SdeStructureBonusReader::timeMultiplierOf(Copying)` (2602), un `ScienceRig` (activité, `timeBonus`, `costBonus`, `RigBonus`) lu par `SdeRigBonusReader` via l'effet (6836/6837, donc par type et non par attribut), `SkillTimeModifier::forCopy`, `JobDuration::forCopyJob`, coût de copie avec rig de coût et rôle (à aligner sur #219), correction de `RigCatalogue` (retrait des noms M/L inexistants, ajout des rigs réels M, L, XL, `family` science) et de `rigsOf` pour qu'un rig de science ne soit plus écarté ni ne lève. Fichiers : `ProductionStructure`, `StructureRig` (ou nouveau `ScienceRig`), `SdeStructureBonusReader`, `SdeRigBonusReader`, `ConfiguredProductionStructures`, `SkillTimeModifier`, `JobDuration`, `RigCatalogue`, `ProductionStructureRigsValidator`, `StructureRigTargets`, glossaire.
- Lot 2 : afficher la durée prévue de la copie (nœud copie de la chaîne et planning science #338 « reste »). Où l'afficher est une décision produit (aucun écran n'en montre aujourd'hui).

**Option 2, circuit l** : tout en un lot, durée affichée comprise. Plus risqué (noyau Industry, front, doc), même résultat à la fin.

Recommandation : option 1. Elle livre le correctif de modèle (et corrige la levée d'exception du catalogue) sans décider d'un écran, et prépare #219 (coût).

## 6. Tests qui prouveraient le correctif
- Unitaire doré : Hornet = 31 873 s (cas du §3) ; rôle seul = 64 260 s ; Raven Raitaru 12 240 s, Azbel 11 520 s, Sotiyo 10 080 s (sans compétence).
- Un rig de fabrication (XL Ship Manufacturing Efficiency II) et un rig ME/TE Research/Invention n'ont aucun effet sur la copie ; le rig de copie n'a aucun effet sur la fabrication.
- Sécurité : XL Laboratory II en highsec (×1,0), lowsec (×1,9), nullsec (×2,1), temps et coût.
- M : Accelerator seul (temps seul), Cost Optimization seul (coût seul), les deux ensemble ; L : Optimization (temps et coût).
- `SdeRigBonusReader` (intégration, SDE réel) : 37182 donne −24 / −12, 2594 = 0 non pris pour un bonus de matériaux ; lecture par effet.
- `RigCatalogue` : chaque nom du catalogue existe dans le SDE (le test aurait rouge sur M/L « Laboratory Optimization »).
- `ConfiguredProductionStructures` : une structure avec un rig de science se charge sans exception.

## 7. À vérifier en jeu
1. **Cumul de deux rigs de copie** (ex. Raitaru avec Copy Accelerator II et Cost Optimization II, ou deux rigs de temps de la même activité) : le SDE donne une opération PostPercent par rig, donc multiplicatif ; non relevé. Protocole : fitter les deux rigs sur une Raitaru, lancer une copie du Hornet, lire « Job Duration Modifiers » (Installed Rig) et le coût, comparer à 1 − 24 × mod_sécurité / 100 et au produit des deux.
2. **Rig de fabrication sur une copie** : prouvé par le SDE (effets distincts) mais jamais relevé en jeu avec un produit de la catégorie du rig. Protocole : Sotiyo avec XL Ship Manufacturing Efficiency I seul, copie d'un blueprint de vaisseau, vérifier que « Installed Rig » vaut 0 %.
3. **M et L : coût et temps** relevés nulle part ; la formule se déduit du SDE (§3), un relevé sur Azbel avec L Copy Optimization II suffit (attendu en nullsec : temps −50,4 %, coût −25,2 %).

## 8. Questions pour Sylvain
- Valider l'option 1 (lot 1 noyau seul, écran au lot 2).
- Le lot 1 corrige aussi le coût de copie dans le noyau : à fusionner avec #219 ou à garder séparé ?
- Après correction du catalogue, que faire des structures déjà configurées avec « M-Set / L-Set Laboratory Optimization » (nom inexistant) : migration de renommage ou nettoyage manuel ? Non vérifié en base de prod.
