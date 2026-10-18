# Spec #101 — Buy vs Build : libellé d'étape des composants

## Contexte

Buy vs Build ne liste que les composants de profondeur 1. `BuyVsBuildService::classifyStage()` libelle « Reaction » une réaction, « T1 Component » une fabrication de profondeur ≥ 2 (jamais atteinte) et « T2 Component » toute autre fabrication. Un fuel block, le R.A.M. d'une munition T2 ou un composant de capital reçoivent donc « T2 Component ».

## Règles métier

- **R1 — Réaction.** Un composant produit par réaction est « Reaction » (inchangé).
- **R2 — Composant T2.** Un composant fabriqué dont le blueprint consomme au moins un produit de réaction (composite, polymère…) est « T2 Component ».
- **R3 — Composant T1.** Tout autre composant fabriqué est « T1 Component ».
- Les valeurs du champ `stage` et les filtres du frontend ne changent pas (`T2 Component`, `T1 Component`, `Reaction`).

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Fernite Carbide : composant Hydrogen Fuel Block (matériaux : produits de glace et PI) | T2 Component | T1 Component |
| E2 | Hail L : composant R.A.M.- Ammunition Tech (minéraux) | T2 Component | T1 Component |
| E3 | Hail L : composant Fernite Carbide (réaction) | Reaction | Reaction |
| E4 | Composant fabriqué consommant un produit de réaction (par exemple un composant de construction T2 du Nomad, ou un arbre de test où le composant consomme Fernite Carbide) | T2 Component | T2 Component |

## Cas limites

- Le produit de réaction consommé est lu dans l'arbre de production construit sans blacklist (`buildProductionTree(…, [], …)`) : un matériau de réaction y porte toujours son blueprint de réaction.
- Un composant dont un matériau fabricable est lui-même fabriqué (pas une réaction) reste « T1 Component » si aucun matériau n'est un produit de réaction.

## Hors périmètre

- Collecte des composants au-delà de la profondeur 1.
- Migration de Buy vs Build sur le noyau (branche `feat/buy-vs-build-on-engine`).

## Tests existants appelés à changer

- `BuyVsBuildServiceCharacterizationTest::testFuelBlockConsumedByAReactionCurrentlyWrongIsLabelledT2Component` : remplacé par E1.
- `BuyVsBuildServiceCharacterizationTest` : `stage` du R.A.M. (Hail L) et du Hydrogen Fuel Block passe à « T1 Component ».

## Décision prise en autonomie

Spec validée en mode nuit. Le SDE importé ne porte ni groupe méta ni niveau technique (`InvType` sans `metaGroupId`) : le niveau du composant est déduit de l'arbre (R2), sans nouvel import SDE. Alternative écartée : listes de groupes SDE codées en dur (identifiants non vérifiables dans le code). Le fichier de caractérisation est aussi modifié, sans commit, par la branche `feat/buy-vs-build-on-engine` : le conflit se limite aux lignes de `stage`.

## Glossaire

Aucun terme ajouté.
