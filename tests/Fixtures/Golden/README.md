# Goldens — noyau de calcul Industrie

Cas de référence de la [spec du noyau](../../../docs/specs/industry-engine.md) (§4). Chaque fichier décrit une entrée et la sortie attendue. Aucun nom de joueur, de personnage, de corporation ni de structure n'y figure (seulement des types de structure et de rig du SDE).

| Fichier | Provenance | Statut (D12) | Ce que le cas verrouille |
|---|---|---|---|
| `nitrogen-fuel-block-25runs-me10-npc.json` | Recalculé depuis le SDE local, confirmé par l'API EVE Ref (9/9) le 2026-10-07 | fait foi | Produit à 40 unités par run, règle `max(runs, …)` (R1), B1 (#2) |
| `hail-l-10runs-me2-raitaru-nullsec.json` | Recalculé depuis le SDE local, confirmé par l'API EVE Ref le 2026-10-07 (job racine 4/4, job R.A.M. 5/5) | provisoire | Bonus de structure × rig × sécurité nullsec multipliés (R1, R4), intermédiaire multi-unités en surplus |
| `fernite-carbide-10runs-tatara-nullsec.json` | Recalculé depuis le SDE local, confirmé par l'API EVE Ref le 2026-10-07 (4 jobs, 18/18 matériaux) | provisoire | Réactions sans ME ni bonus de rôle, rig de réaction × 1,1 (R2), intermédiaire consommé à deux profondeurs (R3) |
| `sde-base-materials.json` | Extrait du SDE (base de dev au 2026-10-07) | — | Quantités de base par run des produits ci-dessus, entrées de R1 |

Fichiers d'origine et outils de recalcul : `.claude/tasks/industry-core/golden/` (non versionnés), recoupement dans `crosscheck-everef.md`.

## Format

- `input` : produit, runs, ME/TE, modificateurs explicites (`structureMaterialModifier`, `rigMaterialModifier`, déjà multiplié par la sécurité), achats forcés (`buy`), stock.
- `expected.root.inputs` : quantités consommées par le job racine.
- `expected.intermediates[]` : un job par intermédiaire (`runs`, `me`, `modifiers`, `inputs`…).
- `expected.leaves` : BOM « feuilles » agrégée.
- `currentEvetools*` : informatif, jamais une assertion.

Les tests passent les modificateurs explicitement : le golden fige la formule, pas la sélection de structure.

## Licences et attribution

- Données EVE (SDE, noms d'items) : propriété de CCP Games.
- Formule de référence : EVE Ref (`autonomouslogic/eve-ref`, licence MIT-0), recoupée avec Fuzzwork Blueprint Calculator (MIT) et « Formulas for EVE Industry » (Adam4EVE). Aucun code AGPL repris.
- API EVE Ref (https://api.everef.net, code MIT-0) : appels ponctuels de recoupement le 2026-10-07.
