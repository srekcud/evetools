# Spec #523 : bonus de temps de structure et rigs de science d'une copie (noyau)

Lot 1 de l'investigation #276 (`.claude/tasks/investigation-276-2026-11-10.md`), noyau seul, sans écran. Validée en autonomie (mode nuit), niveau 3 (code Industry).

## Contexte
Le noyau ne modélise ni le bonus de temps de rôle d'une structure ni les rigs de science. `RigCatalogue` propose « Standup M-Set / L-Set Laboratory Optimization I/II », absents du SDE. #526 (fusionné) a ajouté le bonus de rig de coût, mais attribue tout rig de laboratoire à toutes les activités de laboratoire (lu par attribut 2595) : un rig de coût de copie M réduirait aussi le coût d'une invention.

## Règles
1. **Rôle** : `SdeStructureBonusReader::timeMultiplierOf(typeId, Copying)` lit l'attribut 2602 comme pour la fabrication : Raitaru 0,85, Azbel 0,80, Sotiyo 0,70 ; attribut absent (Athanor, Tatara) = `null`. Invention, recherche ME/TE : lèvent toujours `InvalidArgumentException` (hors lot).
2. **Rig de science** : nouveau VO `Industry\Domain\ScienceRig(ActivityKind $activity, ?RigBonus $timeBonus, ?RigBonus $costBonus)`. Lève `InvalidArgumentException` si l'activité n'est pas de laboratoire (`isLaboratory()`) ou si les deux bonus sont `null`.
3. **Lecture par effet** : `SdeRigBonusReader::scienceRigsOf(int $rigTypeId): list<ScienceRig>` lit `sde_dgm_type_effects` (`DgmTypeEffectRepository::findByTypeId`) :

   | Activité | effet coût (attribut 2595) | effet temps (attribut 2593) |
   |---|---|---|
   | Copying | 6836 | 6837 |
   | Invention | 6830 | 6831 |
   | ResearchMaterial | 6832 | 6833 |
   | ResearchTime | 6834 | 6835 |

   Un `ScienceRig` par activité dont le rig porte au moins un des deux effets ; le bonus d'un effet absent est `null`, jamais 0. Effet présent mais attribut absent : `UnexpectedValueException` (donnée SDE incohérente, pas de défaut). Rig sans effet de science ou type inconnu : liste vide. Sécurité : mêmes modificateurs 2355/2356/2357 que `bonusOf`.
4. **Matériaux** : `materialBonusOf` renvoie `null` pour un rig qui porte un effet de science (2594 = 0 sur un rig de science n'est pas un bonus de matériaux). Un rig de fabrication à 2594 = 0 (TE) garde son comportement actuel.
5. **Modèle de structure** : `ProductionStructure` reçoit deux paramètres de constructeur en fin de liste, avec défaut : `Multiplier $timeRoleBonus = new Multiplier(1.0)` et `list<ScienceRig> $scienceRigs = []`.
   - `timeMultiplierFor(ActivityKind::Copying)` = `timeRoleBonus` × Π `timeBonus->multiplierIn(security)` des `ScienceRig` d'activité `Copying`. Toute autre activité lève `InvalidArgumentException` (non modélisée).
   - `installCostMultiplierFor(activity)` = `costRoleBonus` × Π `costBonus->multiplierIn(security)` des `ScienceRig` de **cette** activité ayant un coût. Fabrication et réaction : rôle seul (comportement de #526 conservé pour un rig XL, qui porte les 4 activités).
   - `StructureRig` perd `costBonus` et `costOnly()` (remplacés par `ScienceRig`, un seul chemin pour le coût).
6. **Isolation** : un rig de fabrication ou de réaction (`StructureRig` de matériaux) n'a aucun effet sur `timeMultiplierFor(Copying)` ; un `ScienceRig` n'a aucun effet sur `materialMultiplierFor` ni sur la fabrication. Un rig de recherche ME/TE ou d'invention n'a aucun effet sur la copie.
7. **Cumul** : deux `ScienceRig` d'une même activité se multiplient (une opération PostPercent par rig dans le SDE). Non relevé en jeu (investigation §7.1) : à vérifier.
8. **Compétences** : `SkillTimeModifier::forCopy(int $scienceLevel, int $advancedIndustryLevel): Multiplier` = `(1 − 0,05 × Science) × (1 − 0,03 × Advanced Industry)`, niveaux 0 à 5 (hors bornes : `InvalidArgumentException`, comme les autres).
9. **Durée** : `JobDuration::forCopyJob(int $baseTimePerRunSeconds, Runs $runs, Multiplier $timeModifier)` = `round(runs × base × modificateur)`, un seul arrondi, sans TE ; base négative lève `InvalidArgumentException`. Le modificateur est le produit compétences × `timeMultiplierFor(Copying)` ; le nombre de copies reste à l'appelant.
10. **Chargement** : `ConfiguredProductionStructures` renseigne `timeRoleBonus` (`?? Multiplier::one()`, comme le rôle de coût) et `scienceRigs` (via `scienceRigsOf`). Il ne produit plus de `StructureRig::costOnly`. Un nom de rig absent du SDE n'est plus une exception : le rig est ignoré (il n'apporte aucun bonus), pour qu'une structure déjà configurée avec un ancien nom se charge.
11. **Catalogue** : `RigCatalogue` retire les 4 noms M/L « Laboratory Optimization I/II » et ajoute, famille `manufacturing`, catégorie `Laboratory`, `bonus` 0, `targetCategories` []. Rigs de copie réels (SDE) : M « Standup M-Set Blueprint Copy Accelerator I/II » (43893/43892, `timeBonus` 20/24) ; M « Standup M-Set Blueprint Copy Cost Optimization I/II » (43891/43890, pas de `timeBonus`) ; L « Standup L-Set Blueprint Copy Optimization I/II » (43729/43730, `timeBonus` 20/24). Les XL « Laboratory Optimization I/II » (37183/37182) restent. Chaque nom du catalogue existe dans le SDE (vérifié sur la base de dev le 2026-11-10 : seuls ces 4 noms manquaient). `ProductionStructureRigsValidator` n'a pas de code à changer : les nouveaux noms passent (Raitaru = M, Azbel = L, Sotiyo = XL), les anciens sont refusés « not in the rig catalogue ».
12. **Glossaire** : ajouter « Bonus de temps de structure » et « Rig de science », compléter « Multiplicateur de compétence » (`forCopy`) et « Bonus de rig » (activité par effet).

## Exemples chiffrés (tests rouges)
Sécurité : high ×1,0, low ×1,9, null ×2,1. Rig de science II : temps 24, coût 12 ; I : 20 et 10.

| Cas | Entrée | Attendu |
|---|---|---|
| Doré Hornet | base 240 s, 600 runs, `forCopy(5,5)` (0,6375), Sotiyo (0,70) + XL Lab II (4 `ScienceRig`), nullsec | `timeMultiplierFor(Copying)` = 0,70 × 0,496 = 0,3472 ; durée **31 873 s** |
| Rôle seul | idem sans rig | durée **64 260 s** |
| Raven sans compétence | base 14 400 s, 1 run, modificateur = rôle | Raitaru **12 240**, Azbel **11 520**, Sotiyo **10 080** s |
| Sécurité, XL Lab II, Sotiyo | temps | high 0,70 × 0,76 = 0,532 ; low 0,70 × 0,544 = 0,3808 ; null 0,3472 |
| Sécurité, coût | coût d'installation, rôle de coût c | high c × 0,88 ; low c × 0,772 ; null c × 0,748 |
| M Accelerator II seul (Raitaru, null) | temps 24, coût `null` | temps 0,85 × 0,496 ; coût = c (rôle seul) |
| M Cost Optimization II seul | temps `null`, coût 12 | temps 0,85 (rôle seul) ; coût c × 0,748 |
| M les deux | Accelerator II + Cost Optimization II | temps 0,85 × 0,496 ; coût c × 0,748 |
| L Optimization II (Azbel, null) | temps 24, coût 12 | temps 0,80 × 0,496 ; coût c × 0,748 |
| Coût par activité | M Cost Optimization II (Copying seulement) | `installCostMultiplierFor(Invention)` = c ; `(Copying)` = c × 0,748 |
| Rig de fabrication | `StructureRig` XL Ship Manufacturing Efficiency II | copie : rôle seul ; fabrication : inchangé |
| Rig d'invention seul | `ScienceRig(Invention, 24, 12)` | copie : rôle seul |
| `forCopy` | (0,0) ; (5,0) ; (0,5) ; (5,5) ; (6,0) ; (0,-1) | 1 ; 0,75 ; 0,85 ; 0,6375 ; erreur ; erreur |
| `forCopyJob` | base 0 ; base -1 ; 144 000 × 0,6375 × 0,7 | 0 s ; erreur ; 64 260 s |
| Lecture rig (intégration) | 37182 ; 43892 ; 43890 ; 43729 | 4 `ScienceRig` temps 24 coût 12 ; Copying (24, `null`) ; Copying (`null`, 12) ; Copying (20, 10) |
| Lecture rig (intégration) | 43881 (M Invention Accelerator II, effet 6831) ; rig de fabrication ; id inconnu | Invention (24, `null`) ; `[]` ; `[]` |
| 2594 = 0 d'un rig de science | `materialBonusOf(37182, Manufacturing)` (effets de copie présents) | `null` |
| Rôle lu (intégration) | Raitaru, Azbel, Sotiyo, Tatara en Copying | 0,85 ; 0,80 ; 0,70 ; `null` |
| Chargement | structure avec rigs XL Lab II, « Standup M-Set Laboratory Optimization II » (inconnu) | se charge sans exception, 4 `ScienceRig`, rig inconnu ignoré |

## Cas limites
- Rig sans catégorie ciblée ni effet de science (ancien comportement : écarté) : toujours écarté pour les matériaux.
- Attribut de coût 0 avec effet de coût présent : `RigBonus(0)`, multiplicateur 1 ; sans effet : `null`.
- Structure sans rig ni rôle de temps lu : multiplicateur 1.
- Fabrication, réaction : aucun changement de montant ni de durée.

## Tests existants modifiés
- `ConfiguredProductionStructuresTest` : le test « rig absent du SDE lève » devient « rig ignoré » (règle 10) ; migration de `costOnly` vers `ScienceRig`, valeurs attendues inchangées.
- `Double/ProductionStructures.php`, `ProductionStructureInstallCostMultiplierTest` : même migration, mêmes valeurs.
- Tout test comptant les rigs du catalogue Laboratory (6 entrées) : nouvelle valeur exacte.

## Hors périmètre
- Affichage de la durée d'une copie et planning science (lot 2) ; aucun écran, aucun endpoint.
- Rigs d'invention, de recherche ME/TE absents du catalogue (hors copie) ; rôle de temps d'invention et de recherche ; bonus de temps de structure de fabrication et de réaction dans le noyau.
- Migration ou nettoyage des structures déjà configurées avec les noms supprimés (non vérifié en base de prod).

## Décisions ouvertes (Sylvain)
- Structures existantes avec « M-Set / L-Set Laboratory Optimization » : leur rig est ignoré au chargement, mais reste dans `getRigs()` ; une modification du rig de la structure sera refusée par le validateur tant qu'il y figure. Renommage ou nettoyage ?
- Cumul de deux rigs de copie : multiplicatif, à relever en jeu.
