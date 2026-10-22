# Spec #133 (lot a) — Système et taxe de l'invention et de la copie d'un projet

## Contexte

Bilan de #133 sur main 7e2f5fa (après #7 L3c et L5) :

| Écran | Système | Taxe | État |
|---|---|---|---|
| Coût de production d'un projet (`ProductionCostService:177-199`) | structure de l'étape → favori de l'activité → Jita | structure de l'étape → `FacilityTaxRateResolver` | une règle (L5) |
| Profit Margins | système demandé | `StructureSelector` du noyau | noyau |
| Pivot Advisor, scanner | système demandé | `FacilityTaxRateResolver` (L3c) | corrigé |
| Buy vs Build | noyau | noyau | hors lot (thread en cours) |
| **BPC Kit, coûts de copie d'un projet** (`InventionService:547-574`) | première étape de profondeur 0 ayant une structure, dans une collection sans ordre → Jita | **première** étape de profondeur 0, souvent la copie (sans structure) → null | **bug** |

Le dernier point est un bug sans choix produit : le système et la taxe peuvent venir de deux étapes différentes, la taxe vient en général de l'étape de copie (vide) et le résultat dépend de l'ordre de chargement. Ce lot le corrige. L'écart restant (bonus matériaux = meilleure structure, éventuellement hors système favori ; coût d'installation = système favori) est un choix produit noté dans « Blocages ».

## Règles métier

- **R1 — Étape racine.** L'étape de référence d'un projet est l'étape de profondeur 0 dont l'activité n'est pas `copy`, au plus petit `sortOrder` ; indépendante de l'ordre de la collection.
- **R2 — Système.** Celui de la structure de l'étape racine, sinon le système favori de fabrication de l'utilisateur, sinon Jita : même règle que `ProductionCostService::resolveSolarSystemForStep()`, partagée (une seule implémentation).
- **R3 — Taxe.** Celle de la structure de l'étape racine (pourcents), sinon `FacilityTaxRateResolver::percentFor(utilisateur, système, ActivityKind::Invention)` (décision Q2, comme L5).
- **R4 — Portée.** S'applique au BPC Kit (`getBpcKitBreakdown`) et aux coûts de copie (`getProjectCopyCosts`). La formule de la copie ne change pas (#219) ; seuls son système et sa taxe d'entrée suivent R1-R3.

## Exemples

Projet T2 : étape copie (profondeur 0, sans structure, `sortOrder` 0) et étape fabrication (profondeur 0, Raitaru à Amarr 30002187, taxe 5 %, `sortOrder` 1).

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | BPC Kit | estimation d'invention demandée pour Amarr, taxe 5.0 (Amarr, taxe null) |
| E2 | Même projet, collection dans l'ordre inverse | même résultat |
| E3 | Coûts de copie, index de copie Amarr 0,05 | coût calculé avec l'index d'Amarr et `(1 + 5/100)` (taxe 0) |
| E4 | Deux étapes de fabrication racines (split), Raitaru Amarr 5 % `sortOrder` 2 et Azbel Jita 1 % `sortOrder` 1 | Jita, 1.0 |
| E5 | Étape racine sans structure, système favori de fabrication 30000144 | système 30000144 ; taxe du résolveur (utilisateur sans structure : 0.25) |
| E6 | Étape racine sans structure, pas de favori | Jita, taxe du résolveur |
| E7 | `ProductionCostService` | comportement inchangé (tests existants) |

## Hors périmètre

Choix de la structure qui fait foi pour une étape sans structure assignée (bonus matériaux vs coût d'installation) : « Blocages ». Formule de la copie (#219). Buy vs Build.

## Glossaire

Pas de terme « étape racine » dans le glossaire : la spec l'utilise au sens de R1 ; proposer l'ajout si le terme sert ailleurs. Aucun ajout dans ce lot.
