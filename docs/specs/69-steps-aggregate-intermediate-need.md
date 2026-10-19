# Spec #69 — À la création, le besoin d'un intermédiaire est agrégé sur tout le plan

## Contexte

À la création et à la régénération, le besoin d'un intermédiaire de fabrication vient de l'arbre (`IndustryTreeService::buildNode()`, une branche à la fois, runs arrondis par branche) ; `IndustryProjectFactory::collectStepsFromTree()` additionne ensuite des quantités déjà arrondies. Un intermédiaire consommé par plusieurs composants est lancé avec trop de runs (`testStepsOfOneNomadCurrentlyOverplanTheFuelBlocksSummedAcrossBranchesIssue69` : Hydrogen Fuel Block 324 runs au lieu de 322 dans le plan Ravworks). Depuis #73 (option B), la factory corrige seulement l'écart dû au découpage. Le recalcul (#16) applique déjà l'agrégation.

## Règles métier

- **R1 — Besoin agrégé (D2).** À la création et à la régénération, le besoin de toute étape intermédiaire (fabrication et réaction) est la somme, sur toutes les étapes qui consomment son produit, des matériaux de chaque job calculés avec ses propres runs ; runs = `ceil(besoin / unités par run)`. C'est l'option A de `.claude/tasks/73-wiring.md`.
- **R2 — Ordre.** Un produit n'est planifié qu'une fois tous ses consommateurs planifiés et découpés (niveau de planification croissant).
- **R3 — Bonus de structure.** Chaque chemin garde sa règle d'aujourd'hui (réaction : catégorie du consommateur ; fabrication : règle de l'arbre), comme dans #73.
- **R4 — La racine** garde les runs du projet.
- **R5 — Création = recalcul.** Recalculer un projet juste créé ne modifie aucune étape.

## Exemples chiffrés

Nomad 1 run, ME 10 / TE 20, sans structure (valeurs de la note de câblage #73, option A, recoupées avec le plan Ravworks).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Hydrogen Fuel Block | `[[162, 6480, …, 0, 324], [162, 6480, …, 1, 324]]` | `[[161, 6440, 5, 10, 20, 0, 322], [161, 6415, 5, 10, 20, 1, 322]]` |
| E2 | Nitrogen Fuel Block | `[[107, 4260, …]]` | `[[106, 4225, 5, 10, 20, 0, null]]` |
| E3 | Oxygen / Helium Fuel Block | 46 runs, 1815 / 107 runs, 4265 | 44 runs, 1735 / 107 runs, 4245 |
| E4 | Fernite Alloy, CECU, Nomad, nombre d'étapes | 550, 951, 1, 373 | inchangés |
| E5 | Création puis recalcul (#16) | le recalcul change des étapes | aucune étape modifiée |
| E6 | Régénération | — | étapes identiques à la création |

## Cas limites

- Intermédiaire consommé par une seule branche : inchangé.
- Groupe découpé consommateur : chaque job compte (#73).
- Type blacklisté : pas d'étape, ignoré.

## Hors périmètre

- Arbre affiché, liste d'achats, coût de production : ils lisent encore l'arbre (#121).
- Plafond de runs d'un BPC pour un job agrégé (D2 : découper au-delà des runs du BPC) : non traité ici.
- Stock et achat à la régénération (#21).

## Décision prise en autonomie

- #69 n'est corrigée que pour les étapes ; l'issue reste ouverte pour l'arbre et le plafond de runs des BPC (commentaire sur l'issue).
- Option A de la note de câblage #73 : `recalculateReactionQuantities()` et la correction Δ de l'option B sont remplacées par une seule passe d'agrégation.

## Tests existants appelés à changer

- `testStepsOfOneNomadCurrentlyOverplanTheFuelBlocksSummedAcrossBranchesIssue69` (perd `Currently…Issue69`), snapshot `nomad-1run-me10-no-structure-per-product.json`, constante `FIVE_MANUFACTURING_STEPS_OF_ONE_NOMAD_IN_SORT_ORDER` (#110) : valeurs ci-dessus.

## Glossaire

Aucun terme ajouté (**Besoin**, **Demande** existent).
