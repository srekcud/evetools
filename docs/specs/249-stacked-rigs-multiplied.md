# Spec #249 : rigs empilés multipliés par l'historique, comme le noyau

Statut : validée en autonomie (mode nuit, 2026-11-04). Issue : « Quantité de matériaux : rigs empilés additionnés par l'historique, multipliés par le noyau » (circuit:m, group:core-wiring).

## Contexte

Quand une structure porte plusieurs rigs qui ciblent la même catégorie de produit, `IndustryBonusService::calculateStructureBonusForCategory` additionne les bonus de rig puis arrondit à 2 décimales (`rigBonus += …`, `round($rigBonus, 2)`). Le noyau (`RigBonus::multiplierIn`, `MaterialQuantity`, spec #141 R4) multiplie les multiplicateurs de rig. Le jeu les multiplie (goldens EVE Ref). Les écrans de l'historique (projets, liste d'achats, Scanner, Pivot) affichent donc des quantités plus petites ou égales à celles du noyau (Marges, Buy vs Build) pour la même structure.

Décision de l'issue (reprise ici) : aligner l'historique sur le noyau. Les appelants ne sont pas migrés dans ce lot.

## Règles

- **R1 : empilement multiplicatif.** Pour une structure et une catégorie, chaque rig applicable donne un bonus `b_i = bonus du rig dans la catégorie × multiplicateur de sécurité` (pourcentage, inchangé). Le multiplicateur des rigs vaut `Π (1 − b_i / 100)`. Le champ `rig` renvoyé par `calculateStructureBonusForCategory` est le pourcentage équivalent : `(1 − Π (1 − b_i / 100)) × 100`.
- **R2 : un seul rig, aucun rig.** Un seul rig applicable : `rig` vaut `b_1` (5,04 pour un rig T2 en nullsec, 4,56 en lowsec, etc.), comme aujourd'hui. Aucun rig applicable : `rig` vaut `0.0`.
- **R3 : précision.** `rig` est arrondi à 10 décimales (pour absorber le bruit flottant, pas plus). Il n'est plus arrondi à 2 décimales : un arrondi à 2 décimales ferait diverger la quantité du noyau (9,83 au lieu de 9,826 pour deux rigs à 5,04 %).
- **R4 : égalité avec le noyau.** Pour toute structure, toute catégorie et tout job, `MaterialQuantityFormula::forJob(base, runs, ME, base structure, rig)` alimenté par `calculateStructureBonusForCategory` donne la même quantité que `MaterialQuantity::forManufacturingJob` (ou `forReactionJob`) alimenté par le produit des multiplicateurs de rig (voir `MaterialQuantityEquivalenceTest`, groupe `issue-141`).
- **R5 : `total` et `base`.** `base` est inchangé. `total` reste `round((1 − (1 − base/100) × (1 − rig/100)) × 100, 2)`, calculé avec le nouveau `rig`.
- **R6 : sécurité et catégories.** Le multiplicateur de sécurité de chaque rig (standard, Thukker, réaction) et le filtrage par catégorie ne changent pas.

## Exemples chiffrés (base 100 par run, 10 runs)

| Rigs applicables (nullsec, ME 0, sans bonus de structure) | `rig` avant | `rig` après | Quantité avant | Quantité après |
|---|---|---|---|---|
| 1 × (2,4 × 2,1) | 5,04 | 5,04 | 950 | 950 |
| 2 × (2,4 × 2,1) | 10,08 | 9,825984 | 900 | 902 |
| 3 × (2,4 × 2,1) | 15,12 | 14,3707544064 | 849 | 857 |
| (2,0 × 2,1) + (2,4 × 2,1) | 9,24 | 9,02832 | 908 | 910 |

Calcul : `rig` après = `(1 − Π (1 − b_i / 100)) × 100`, arrondi à 10 décimales (R3) ; quantités pour une base de 100 par run sur 10 runs. Les quantités de référence du noyau sont dans `MaterialQuantityEquivalenceTest::multipleRigsManufacturingProvider` et `multipleRigsReactionProvider` (colonne « core »).

## Cas limites

- Rig sans bonus pour la catégorie demandée (absent de `rigBonusMap`) : ignoré, les autres rigs s'empilent.
- Station NPC ou structure sans rig : `rig = 0.0`, `total = base`.
- Réaction : même règle (multiplicateur de sécurité 1,0 / 1,0 / 1,1), pas de bonus de structure.
- Le minimum d'une unité par run reste appliqué par `MaterialQuantityFormula::forJob`.

## Hors périmètre

- Le temps de job : `calculateStructureTimeBonusForCategory` additionne encore les rigs de temps. Hors de l'issue (quantité de matériaux), à traiter à part si Sylvain le demande.
- `IndustryStructureConfig::getManufacturingMaterialBonus` et `getReactionMaterialBonus` (cartes de structure, prévisualisation) : somme de tous les rigs de la structure, toutes catégories confondues, affichage seulement. Non modifiés ici ; l'écart d'affichage avec les quantités n'apparaît qu'avec deux rigs ou plus.
- Migration des appelants vers le noyau (#141) et suppression de `MaterialQuantityFormula`.
- Aucun changement de schéma, de migration, de commande console ni d'API (les clés `rig`, `total`, `base` gardent leur forme).

## Décisions

- Aligner l'historique sur le noyau (R4 de #141, goldens EVE Ref) plutôt qu'attendre la migration des appelants : retenu par l'issue.
- Pas de nouvel arrondi à 2 décimales sur `rig` : la quantité doit rester égale à celle du noyau (R3).

## Tests attendus (proportionnés)

- Unité sur `IndustryBonusService::calculateStructureBonusForCategory` : deux rigs identiques, deux rigs différents, trois rigs, rig d'une autre catégorie ignoré, réaction avec deux rigs ; les valeurs de `rig` et `total` de R1 et R5.
- Un test d'égalité de quantité entre la sortie du service et le noyau sur les lignes des deux providers « multiple rigs » existants (R4), `#[Group('issue-249')]`.
- Les caractérisations `…CurrentlyAddedByLegacyButMultipliedByCoreIssue141` ne changent pas : elles utilisent un additionneur local au test, et restent la preuve de l'ancien écart.
