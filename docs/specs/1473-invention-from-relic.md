# #1473 — Invention à partir d'une relique

## Contexte

Les destroyers tactiques, croiseurs stratégiques, sous-systèmes (T3) et une rig sont des produits inventés à partir d'une **relique** (Intact, Malfunctioning ou Wrecked Hull Section, etc.). Dans le SDE, la relique porte l'activité d'invention (activité 8, produit : le BPO T3) mais ne fabrique rien : aucune ligne de fabrication (activité 1) pour elle dans `sde_industry_activity_products`. Aujourd'hui `SdeInventionCatalog::inventionOf` lève `UnexpectedValueException` (500 sur `GET /api/industry/profit-margin/{typeId}`) et `IndustryProjectService::t1ProductTypeIdOf` lève `InventionT1ProductMissingException` (création de projet).

## Règles

1. Une **source d'invention qui ne fabrique rien** est une relique. Elle n'a pas de produit T1 : `InventionRecipe::$t1ProductTypeId` vaut `null`.
2. Une invention depuis une relique n'a **pas de job de copie** : ni coût d'installation de copie (marge), ni ligne `copy` de BOM (projet).
3. La relique est **consommée par chaque tentative**, une unité, réussite ou échec, comme un datacore. Elle est donc ajoutée aux consommables de la tentative et achetée au prix marché ; un prix inconnu reste inconnu (jamais 0), comme pour un datacore.
4. Le choix de la relique reste la règle existante de `findBlueprintForProduct` (la relique de plus gros rendement, soit Intact). Les trois reliques ne sont pas encore proposées : question ouverte, voir « Décisions ».
5. La marge et le projet suivent la même règle : un produit T3 ne lève plus d'erreur serveur, ni dans `GET /api/industry/profit-margin/{typeId}` ni dans la création d'un projet.
6. Une invention depuis un blueprint T1 qui fabrique un produit est inchangée (copie, coût de copie, ligne de copie).

## Exemples chiffrés

| Cas | Entrée | Attendu |
|---|---|---|
| Catalogue, relique | produit T2 `SYNTHETIC_T2_PRODUCT`, source `SYNTHETIC_RELIC` sans fabrication, datacore ×2, probabilité 0,3, 20 runs | `inventionOf` rend une recette, `t1ProductTypeId` nul, `t1BlueprintTypeId` = relique, `datacores` = datacore ×2 + relique ×1 |
| Catalogue, T1 normal | Hobgoblin II | inchangé : `t1ProductTypeId` = Hobgoblin I, datacores sans relique |
| Marge, relique | produit inventé d'une relique, 1 run | pas d'erreur ; `copyInstallCost` absent ; coût d'une tentative = datacores + relique + decryptor + installation d'invention ; le prix de la relique est dans `datacoresCost` |
| Marge, T1 normal | produit inventé d'un T1 | inchangé : le coût d'installation de copie est dans le coût d'une tentative |
| Projet, relique | 1 produit T3, 20 runs, probabilité 0,26, 20 runs par BPC | une ligne `invention` ; aucune ligne `copy` ; la relique figure dans les matériaux avec `quantité = tentatives` |
| Projet, T1 normal | cas existant | inchangé (lignes `copy` et matériaux) |

## Cas limites

- Prix de la relique inconnu : le coût de l'invention est inconnu (donnée manquante de prix marché), pas 0.
- Probabilité absente : comportement actuel (`unknownReason`, `InventionProbabilityMissingException` côté projet).
- Plusieurs produits T3 d'un même projet avec reliques différentes : un matériau par relique, quantités par produit.

## Tests existants contredits

- `tests/Integration/Industry/Infrastructure/Sde/SdeInventionCatalogTest::testT1BlueprintWhichManufacturesNothingIsAnExplicitError` : remplacé par `testInventionFromARelicHasNoT1ProductAndNoCopyJob`.
- `tests/Unit/Service/Industry/Project/GroupIndustryBomGoldenCharacterizationTest::testT1BlueprintWithoutManufacturingProductThrowsNamingTheT1BlueprintAndPersistsNoProject` : remplacé par un test de projet depuis une relique.
- `InventionT1ProductMissingException` n'est plus levée : classe supprimée si plus aucune référence.

## Hors périmètre

- Choisir la relique côté utilisateur, ou en proposer trois (aucun champ, endpoint ni écran ajouté).
- `InventionService::calculateInventionCost` (onglet historique d'invention) : non touché.
- Les T2 inventés d'un blueprint T1 (aucun changement).

## Décisions

- Relique par défaut = la règle existante (rendement le plus haut). Question ouverte pour Sylvain : proposer les trois reliques (Intact 0,26 / 20 runs, Malfunctioning 0,21 / 10, Wrecked 0,14 / 3), ce qui demande un paramètre d'API et un sélecteur, ou garder Intact.
- Le prix de la relique est compté dans le coût d'invention : sans lui la marge d'un T3 serait fausse.

## Glossaire

- **Relique** (Relic) : source d'invention d'un produit T3, consommée par chaque tentative, sans produit T1 ni copie.
