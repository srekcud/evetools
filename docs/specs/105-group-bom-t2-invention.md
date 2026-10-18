# Spec #105 — Group Industry : un produit T2 s'obtient par invention, pas par copie de son blueprint T2

## Contexte

`GroupIndustryProjectService::flattenTree()` enregistre une ligne de copie du blueprint de tout nœud dont l'arbre a `hasCopy`, y compris un produit T2 (ex. Hail L) : la BOM demande une copie du blueprint T2, impossible à obtenir, et aucune invention. Un blueprint T2 s'obtient par invention à partir d'une copie d'un run du blueprint T1, avec des datacores consommés à chaque tentative (`InventionRecipe`, spec du noyau R8).

## Règles métier

- **R1 — Nœud inventé.** Un nœud de l'arbre (item du projet ou intermédiaire) dont le produit a une recette d'invention (`InventionCatalog::inventionOf()` non nul) n'a pas de ligne de copie de son propre blueprint.
- **R2 — Tentatives.** Pour un produit inventé, le nombre de tentatives d'invention vaut `ceil(runs / (runs de base × probabilité de base))`, où `runs` est la somme des runs de fabrication de ce produit dans la BOM (toutes lignes de production confondues), `runs de base` les runs du BPC inventé et la probabilité celle du SDE. Ni compétences ni décrypteur.
- **R3 — Ligne d'invention.** Une ligne de job par produit inventé : `typeId` = produit T2, activité `invention`, groupe `blueprint`, `runs` = tentatives, `requiredQuantity` = tentatives, ME 0 / TE 0, parent = parent de la première ligne de fabrication du produit (null pour un item du projet).
- **R4 — Copie du T1.** Une ligne de copie par produit inventé : `typeId` = produit T1 (`t1ProductTypeId`), activité `copy`, groupe `blueprint`, `runs` = tentatives (une copie d'un run par tentative), `requiredQuantity` = tentatives, ME 0 / TE 0, même parent que R3.
- **R5 — Datacores.** Chaque datacore de la recette est ajouté aux matériaux de la BOM, quantité = quantité par tentative × tentatives, agrégé avec les autres matériaux et pricé comme eux.
- **R6 — Probabilité inconnue.** Si la recette n'a pas de probabilité, la construction de la BOM échoue avec une erreur explicite qui nomme le produit T2 et le blueprint T1 ; aucune BOM partielle n'est enregistrée.
- **R7 — Inchangé.** Nœuds non inventés (T1, réactions) : copie comme aujourd'hui. Matériaux de fabrication, lignes de production.

## Exemples chiffrés

Recette d'invention de Hail L (SDE) : blueprint T1 898, produit T1 197 (Depleted Uranium L), runs de base 10, probabilité 0,34, datacores 20423 (Nuclear Physics) et 20424 (Mechanical Engineering), 1 de chaque par tentative. Le harnais de test met 1 × 20423 et 2 × 20424 pour distinguer les deux quantités.

| # | Projet | Attendu |
|---|---|---|
| E1 | Hail L, 1 run de fabrication | aucune copie de Hail L ; invention Hail L `ceil(1 / 3,4) = 1` tentative ; copie du produit T1 1 run ; datacores 1 × 20423, 2 × 20424 (harnais) |
| E2 | Hail L, 10 runs de fabrication (BOM actuelle : 1 run, #2) | tentatives calculées sur les runs de fabrication réellement présents dans la BOM |
| E3 | Hail L, 7 runs de fabrication dans la BOM (35 000 runs saisis, à cause de #2) | `ceil(7 / 3,4) = 3` tentatives ; copie T1 3 runs ; datacores 3 × 20423, 6 × 20424 (harnais) |
| E4 | Nitrogen Fuel Block (T1, garde-fou) | copie de son blueprint inchangée, aucune invention |
| E5 | Recette sans probabilité | exception explicite, aucune ligne enregistrée |
| E6 | Deux items Hail L | une seule ligne d'invention et une seule copie T1, tentatives sur la somme des runs |

## Cas limites

- Un datacore déjà présent comme matériau : quantités additionnées.
- Les intermédiaires d'un T2 (Ram Ammunition Tech, réactions…) gardent leurs règles actuelles.

## Hors périmètre

- Décrypteurs, compétences du pilote, choix de la probabilité effective.
- Le ME/TE du BPC inventé (ME 2 / TE 4 par défaut) sur la ligne de fabrication T2 (traité par #23 pour l'item).
- Les BOM déjà enregistrées : aucune migration.

## Décision prise en autonomie

- R2 : probabilité de base du SDE, sans compétences ni décrypteur (le projet Group Industry n'a pas de pilote désigné pour l'invention) ; c'est l'estimation qui demande le plus de tentatives, donc la plus prudente pour l'approvisionnement.
- R3/R4 : invention et copie du T1 rangées dans le groupe `blueprint` existant, pour ne pas créer de groupe que le front ne connaît pas.
- R6 : erreur explicite plutôt qu'un nombre de tentatives par défaut (règle du glossaire : jamais de valeur plausible à la place d'une donnée manquante), sur le modèle de `TypeMissingFromSdeException` (#109).
- Dépendance : le service passe par le port `App\Industry\Application\InventionCatalog` (implémentation `SdeInventionCatalog`), pas par `InventionService`.

## Glossaire

Ajout de « Tentative » et « Datacore » ; « Invention » existait.

## Tests impactés

- `GroupIndustryBomGoldenCharacterizationTest::testTenRunsOfHailLCurrentlyBecomeOneRunWithACopyOfTheT2BlueprintAndNoInventionIssue2` (lignes de copie Hail L, invention absente, matériaux sans datacores).
- Tout test qui construit `GroupIndustryProjectService` (nouvelle dépendance au constructeur).
