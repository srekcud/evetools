# Goldens — noyau de calcul Industrie

Cas de référence de la [spec du noyau](../../../docs/specs/industry-engine.md) (§4). Chaque fichier décrit une entrée et la sortie attendue. Aucun nom de joueur, de personnage, de corporation ni de structure n'y figure (seulement des types de structure et de rig du SDE).

| Fichier | Provenance | Statut (D12) | Ce que le cas verrouille |
|---|---|---|---|
| `nitrogen-fuel-block-25runs-me10-npc.json` | Recalculé depuis le SDE local, confirmé par l'API EVE Ref (9/9) le 2026-10-07 | fait foi | Produit à 40 unités par run, règle `max(runs, …)` (R1), B1 (#2) |
| `hail-l-10runs-me2-raitaru-nullsec.json` | Recalculé depuis le SDE local, confirmé par l'API EVE Ref le 2026-10-07 (job racine 4/4, job R.A.M. 5/5) | provisoire | Bonus de structure × rig × sécurité nullsec multipliés (R1, R4), intermédiaire multi-unités en surplus |
| `fernite-carbide-10runs-tatara-nullsec.json` | Recalculé depuis le SDE local, confirmé par l'API EVE Ref le 2026-10-07 (4 jobs, 18/18 matériaux) | provisoire | Réactions sans ME ni bonus de rôle, rig de réaction × 1,1 (R2), intermédiaire consommé à deux profondeurs (R3) |
| `install-cost-vectors.json` | Vecteurs synthétiques recalculés à la main (formules F3/F5 du benchmark, identiques à R5/R6 de la spec) ; structure de la formule confirmée par l'API EVE Ref le 2026-10-07 (rapports SCI, taxe, SCC 4 % sur l'EIV ou la base de coût de job) ; recalculés avant la phase rouge de la tranche 2 | fait foi, sauf `copying-ten-runs` (D11a, SCC de la copie en attente de vérification en jeu) | Coût d'installation R6 : bonus de rôle sur le seul terme du cost index, taxe et SCC additifs, taxe alpha, base de 2 % pour copie et invention, cost index ou adjusted price absent → coût inconnu (#7) |
| `nomad-1run-me10-no-structure.ravworks.json` | **Ravworks**, plan public https://ravworks.com/plan/CBevjGf, lu le 2026-10-07 (une seule lecture), recoupé par recalcul depuis le SDE local : 63/63 feuilles, 65/65 jobs ; recopié de `.claude/tasks/industry-core/golden/` | fait foi | BOM multi-niveaux complète en mode plan (R3) : demande agrégée sur tout le plan avant l'arrondi en runs (#69). Surplus non assertés à l'unité (Nitrogen Fuel Block : Ravworks 14, recalcul 15) |
| `ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json` | **Ravworks**, plan https://ravworks.com/plan/2MbF29y partagé par Sylvain, lu le 2026-10-07 ; configuration de structures déduite puis vérifiée par recalcul (100/100 quantités, 57/57 runs) ; recopié de `.claude/tasks/industry-core/golden/` | fait foi (recalcul) | 4 produits finaux, nullsec, jobs découpés : matériaux recalculés job par job (R13, D11b, #73). Les découpages Ravworks (par durée) sont reproduits par un plafond de runs de BPC égal au plus gros job, runs répartis également |
| `sde-blueprint-recipes.json` | Extrait du SDE (base de dev au 2026-10-07, lecture seule) : recettes publiées de chaque item fabricable de l'arbre des produits finaux des goldens | — | Entrée du port `BlueprintCatalog` en test (unités par run, matériaux de base par run, `maxProductionLimit`, temps de base) |
| `sde-base-materials.json` | Extrait du SDE (base de dev au 2026-10-07) | — | Quantités de base par run des produits ci-dessus, entrées de R1 |

Fichiers d'origine et outils de recalcul : `.claude/tasks/industry-core/golden/` (non versionnés), recoupement dans `crosscheck-everef.md`.

## Format

- `input` : produit, runs, ME/TE, modificateurs explicites (`structureMaterialModifier`, `rigMaterialModifier`, déjà multiplié par la sécurité), achats forcés (`buy`), stock.
- `expected.root.inputs` : quantités consommées par le job racine.
- `expected.intermediates[]` : un job par intermédiaire (`runs`, `me`, `modifiers`, `inputs`…).
- `expected.leaves` : BOM « feuilles » agrégée.
- `currentEvetools*`, `knownDeviation`, `informative`, `ravworksEndStock` : informatifs, jamais une assertion.
- Goldens Ravworks : `expected.roots[]` / `expected.intermediates[]` portent `jobs` (runs de chaque job découpé) ; `input.endProductJobs` donne les cibles.

Les tests passent les modificateurs explicitement : le golden fige la formule, pas la sélection de structure.

## Licences et attribution

- Données EVE (SDE, noms d'items) : propriété de CCP Games.
- Plans Ravworks (CBevjGf, 2MbF29y) : données publiées par ravworks.com, une seule lecture par page publique, sans scraping. Aucun nom de structure, de personnage ni de corporation repris.
- Formule de référence : EVE Ref (`autonomouslogic/eve-ref`, licence MIT-0), recoupée avec Fuzzwork Blueprint Calculator (MIT) et « Formulas for EVE Industry » (Adam4EVE). Aucun code AGPL repris.
- API EVE Ref (https://api.everef.net, code MIT-0) : appels ponctuels de recoupement le 2026-10-07.
