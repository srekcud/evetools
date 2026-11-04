# Spec #250 : le ME ne s'applique jamais aux matériaux d'une réaction

Mode nuit : spec validée en autonomie (circuit:m, aucune décision produit ouverte).

## Contexte

`MaterialQuantityFormula::forJob` applique le ME qu'on lui passe. Une réaction n'a pas de ME (glossaire : « Une étape de réaction a toujours ME 0 »). Trois appelants forcent déjà 0 (`PivotAdvisorService` aux lignes 174, 209 et 427, `BatchProfitScannerService::312`, `IndustryTreeService::buildNode` par `$applyMe`). Trois chemins lisent (le quatrième, `IndustryProjectFactory::addNeedOfJobs`, reçoit toujours ME 0 pour une réaction : `collectStepsFromTree` le pose et `withSettingsOfExistingStep` ne recopie le ME stocké que pour une fabrication ; il reste un garde vert) le ME stocké de l'étape ou du job sans regarder l'activité :

| Chemin | Lieu |
|---|---|
| Quantité d'un matériau d'un job (liste d'achats, arbre affiché, runs effectifs, delta par produit) | `ProductionStepNeedsResolver::materialQuantityOf` |
| Cascade des besoins des étapes | `IndustryStepCalculator::needOfConsumer` |
| Besoin des enfants d'un groupe découpé | `SplitGroupMaterialNeeds` (par `SplitGroupMaterials::materialsOfJobs`) |
| Génération et régénération des étapes | `IndustryProjectFactory::addNeedOfJobs` (par `SplitGroupMaterials::materialsOfJobs`) |

Un ME non nul sur une étape de réaction peut exister : les étapes créées avant #190 (la commande `app:industry:reset-reaction-me-levels` les remet à 0 mais n'est pas lancée d'office), ou un job de réaction saisi avant le refus du 400 sur `UpdateStepProcessor`. Les matériaux de la réaction sont alors sous-estimés (ME 10 : 10 % de moins, avant bonus de structure et de rig).

## Règles

1. **R1** : la quantité d'un matériau consommé par un job dont l'activité est `reaction` se calcule avec un ME de 0, quel que soit le ME stocké sur l'étape ou le job. Pour `manufacturing`, le ME de l'étape s'applique comme avant.
2. **R2** : la règle est écrite une seule fois et partagée par les quatre chemins ci-dessus. Les trois appelants qui forcent 0 aujourd'hui peuvent la reprendre, sans y être obligés. `MaterialQuantityFormula::forJob` ne change pas (il reste la formule pure, son test d'équivalence #141 reste valable).
3. **R3** : le bonus de structure et de rig continue de s'appliquer à une réaction (inchangé, `MaterialQuantity::forReactionJob` du noyau).
4. **R4** : le ME stocké sur une étape de réaction n'est ni modifié ni normalisé par ce lot ; il reste affiché tel quel (`ProjectStepResource::$meLevel`). Seule la quantité de matériaux ignore ce ME.
5. **R5** : une réaction à ME 0 stocké donne les mêmes quantités qu'avant (aucun changement de montant pour les données saines).

## Exemples chiffrés

Matériau de base 100 par run, 10 runs, rig de matériaux 2,64 %, structure sans bonus de base.

| Activité de l'étape | ME stocké | Quantité attendue | Avant le correctif |
|---|---|---|---|
| reaction | 10 | 974 | 877 |
| reaction | 0 | 974 | 974 |
| reaction | 5 | 974 | 925 |
| manufacturing | 10 | 877 | 877 |
| manufacturing | 0 | 974 | 974 |

Sans bonus (base 100, 10 runs) : réaction ME 10 = 1000, fabrication ME 10 = 900.

Les tests rouges portent sur le comportement de chaque chemin, avec une étape de réaction à ME 10 :

| Chemin | Attendu |
|---|---|
| Liste d'achats / arbre affiché (`materialQuantityOf`) | matériaux de la réaction = quantité du ME 0 ; les matériaux d'une fabrication à ME 10 restent réduits |
| Cascade (`IndustryStepCalculator::recalculateStepQuantities`) | le besoin transmis à l'étage inférieur d'une réaction à ME 10 est celui du ME 0 (runs de l'étage inférieur plus élevés qu'avec ME 10) |
| Groupe découpé (`SplitGroupMaterialNeeds`) | le besoin des enfants d'une réaction découpée à ME 10 est celui du ME 0 |
| Génération et régénération (`IndustryProjectFactory`) | un projet dont une étape de réaction porte ME 10 régénère des étages inférieurs aux besoins du ME 0 |

## Cas limites

- Réaction à plusieurs unités par run ou à runs découpés : le ME 0 s'applique job par job, un arrondi par job (inchangé).
- Étape de copie ou d'invention : hors sujet (pas de matériaux concernés par le ME ici, comportement inchangé).
- Plancher d'une unité par run (`max($runs, …)`) : inchangé.
- Étape d'activité inconnue : comportement inchangé, le ME s'applique (seule `reaction` est exemptée).

## Accès et fraîcheur

Aucun nouvel accès, aucune ressource API nouvelle, aucune donnée de corporation. Défaut préexistant repris : les ME de réaction déjà stockés restent non normalisés (traité par `app:industry:reset-reaction-me-levels`, #190).

## Tests existants contredits ou modifiés

Aucun attendu : `MaterialQuantityEquivalenceTest::testLegacyFormulaCurrentlyAppliesTheMeToAReactionIssue141` fige la formule pure, qui ne change pas. Si un golden contient une étape de réaction à ME non nul, le `test-writer` le signale et le met à jour avec la valeur du ME 0.

## Hors périmètre

- Normaliser les ME stockés (commande #190 existante).
- Core du noyau `Domain\MaterialQuantity` (déjà correct).
- Group Industry (`GroupIndustryProjectService` applique déjà `REACTION_EFFICIENCY_LEVEL`).
- Front : aucun changement d'affichage.

## Glossaire

Aucun terme ajouté. Le terme **ME** (glossaire) est précisé : la quantité de matériaux d'une réaction ignore le ME stocké de l'étape.

## Prod

Aucune migration, aucune commande obligatoire. Rappel à Sylvain : lancer `app:industry:reset-reaction-me-levels --dry-run` puis sans l'option si ce n'est pas déjà fait, pour aligner le ME affiché sur la quantité calculée.
