# Spec #15 — ME du consommateur d'une réaction

## Contexte

À la génération des étapes, `IndustryProjectFactory::recalculateReactionQuantities()` recalcule la quantité d'un produit de réaction à partir de ses consommateurs. Pour un consommateur fabriqué, le ME est fixé à 10, même pour la racine dont le ME vient de l'utilisateur. Le recalcul des étapes (`IndustryStepCalculator`) utilise déjà le ME de l'étape.

## Règles métier

- **R1 — ME du consommateur.** Un consommateur fabriqué applique le ME de son étape : celui du projet à la racine, 10 pour un intermédiaire (défaut voulu, cf. spec du noyau et #116). Un consommateur réaction n'a pas de ME (inchangé).

## Exemples chiffrés

Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]`.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Quantum Microprocessor, 32 runs, ME 0 : Titanium Carbide (17 par run) | `[1, 490, 1, 0, 0, 0, null]` (17 × 32 × 0,9) | `[1, 544, 1, 0, 0, 0, null]` (17 × 32) |
| E2 | Même projet à ME 10 | 490 | inchangé |
| E3 | Quantum Microprocessor, 237 runs, ME 10, Raitaru : Phenolic Composites | 1 267 | inchangé |

## Hors périmètre

- ME 10 des intermédiaires fabriqués dans l'arbre de production (`ProjectProviderGoldenTreeCharacterizationTest::testTreeOfFerniteCarbideAtMeZeroCurrentlyBuildsTheFuelBlockIntermediateAtHardCodedMeTenIssue15`) : défaut voulu, cf. #116 fermée ; le test garde son nom.
- Migration sur le noyau (R2 de la spec du noyau).

## Tests existants appelés à changer

- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testReactionOfAnMeZeroRootIsCurrentlyPlannedAtMeTenIssue15` → E1.

## Décision prise en autonomie

Spec validée en mode nuit. #15 ne vise que la racine : les intermédiaires gardent le ME 10 par défaut, décidé pour #116.

## Glossaire

Aucun terme ajouté.
