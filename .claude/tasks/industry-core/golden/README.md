# Golden cases — noyau de calcul Industry

Entrées des futurs tests golden (étape 6 du plan). Les fichiers décrivent une entrée et la sortie attendue. Ils ne contiennent aucun nom de joueur, de corporation ni de structure.

| Fichier | Provenance | Ce que le cas verrouille |
|---|---|---|
| `nomad-1run-me10-no-structure.ravworks.json` | **Ravworks**, plan public https://ravworks.com/plan/CBevjGf, lu le 2026-10-07, puis recoupé par recalcul (63/63 feuilles, 65/65 jobs identiques) | BOM multi-niveaux complète : 65 jobs intermédiaires (réactions à 2 niveaux, hybrides, biochimiques, composants, fuel blocks), avec une demande agrégée avant l'arrondi |
| `hail-l-10runs-me2-raitaru-nullsec.json` | **recalculé** (SDE local + formule F1), **confirmé par EVE Ref** le 2026-10-07 (job racine 4/4, job R.A.M. 5/5 ; cf. `crosscheck-everef.md`) | Produit multi-unités (5000/run), B1 (#2), bonus de structure × rig × sécurité nullsec, intermédiaire multi-unités en surplus |
| `nitrogen-fuel-block-25runs-me10-npc.json` | **recalculé**, confirmé par EVE Ref (9/9) | Produit multi-unités (40/run), B1, règle `max(runs, …)` |
| `fernite-carbide-10runs-tatara-nullsec.json` | **recalculé**, **confirmé par EVE Ref** le 2026-10-07 (4 jobs, 18/18 matériaux) et par le modificateur de réaction du plan Ravworks 2MbF29y | Réactions : pas de ME, pas de bonus de rôle, rig × 1,1 en nullsec ; un intermédiaire consommé à deux profondeurs (#16) |
| `ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json` | **Ravworks**, plan https://ravworks.com/plan/2MbF29y partagé par Sylvain, lu le 2026-10-07. Configuration non affichée par la page : déduite puis vérifiée par recalcul (100/100 quantités, 57/57 runs) | Configuration réelle nullsec (rôle EC 0,99 × rig T1 × 2,1 ; réactions rig T2 × 1,1), 4 produits finaux en jobs séparés, **matériaux recalculés par job découpé** (D11b : 67/100 quantités changent sans découpage) |
| `install-cost-vectors.json` | **recalculé** (vecteurs synthétiques) ; structure de F3/F5 confirmée par EVE Ref, SCC copie (D11a) non tranché | Coût d'installation F3 : SCC, bonus de rôle, alpha, invention et copie sur la base de 2 %, donnée absente → inconnu (#7) |

## Format

- `input` : produit, runs, ME/TE, configuration explicite des modificateurs (`structureMaterialModifier`, `rigMaterialModifier`, déjà multipliés par la sécurité), achats forcés (`buy`), stock.
- `expected.root.inputs` : quantités consommées par le job racine.
- `expected.leaves` : BOM « feuilles » agrégée (matériaux achetés).
- `expected.intermediates[]` : un job par intermédiaire, avec `runs`, `outputPerRun`, `quantityProduced`, `quantityNeeded`, `surplus` et `inputs` (quantités par matériau pour ce job).
- `currentEvetools*` / `knownDeviation` : informatifs, ce ne sont pas des assertions.

Les tests doivent passer les modificateurs **explicitement** (pas via « la meilleure structure de l'utilisateur ») : le golden fige la formule, pas la sélection de structure.

## Règles de calcul supposées (cf. `../benchmark.md`)

1. Par job et par matériau : `max(runs, ceil(round(runs × base × (1 − ME/100) × mod_structure × mod_rig, 2)))`.
2. Réactions : ME = 0, `mod_structure = 1`, rig de réaction modulé par 1,0 (lowsec) ou 1,1 (nullsec/WH).
3. Intermédiaires : ME 10 / TE 20 (hypothèse Ravworks, identique au défaut evetools).
4. La demande d'un intermédiaire est **agrégée sur tout le plan** avant `runs = ceil(demande / unités par run)`.
5. Découpage de job : F1 s'applique à chaque job découpé. Sur le plan Nomad, les découpages (`Capital Jump Drive` 14 + 13, `Capital Cargo Bay` 16 + 16) ne changent pas les totaux ; sur le plan 2MbF29y, ils en changent 67 sur 100.

## Régénérer

```bash
# 1. Extraire le SDE de la base dev (TSV)
docker compose exec -T postgres psql -U eve_user -d eve_app -At -F $'\t' -c "select type_id,activity_id,material_type_id,quantity from sde_industry_activity_materials where activity_id in (1,5,8,11)" > sde/mats.tsv
docker compose exec -T postgres psql -U eve_user -d eve_app -At -F $'\t' -c "select type_id,activity_id,product_type_id,quantity,coalesce(probability,-1) from sde_industry_activity_products where activity_id in (1,8,11)" > sde/prods.tsv
docker compose exec -T postgres psql -U eve_user -d eve_app -At -F $'\t' -c "select t.type_id,t.type_name,t.group_id,g.group_name,g.category_id from sde_inv_types t join sde_inv_groups g on g.group_id=t.group_id" > sde/types.tsv
# 2. Calculer (config JSON : roots, rootMe, intermediateMe, modsByName, manufacturingMods, reactionMods, buy)
node tools/reference-bom.js sde config.json
```

Golden à jobs découpés (champ `jobs` = runs de chaque job) :

```bash
python3 -I tools/check-split-golden.py sde ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json
```

Recoupement externe (EVE Ref, plan Ravworks 2MbF29y) : `crosscheck-everef.md`.

Les scripts sont des outils de recoupement, pas du code de production. Il ne reprend aucun code AGPL.

## Licences et attribution

- Données EVE (SDE, noms d'items) : propriété de CCP Games.
- Plans Ravworks (CBevjGf, 2MbF29y) : données publiées par ravworks.com. Une seule lecture par page publique, sans scraping. Aucun nom de structure, de personnage ni de corporation repris.
- API EVE Ref (https://api.everef.net, code MIT-0) : 10 appels ponctuels le 2026-10-07. La documentation ne fixe pas de conditions d'usage.
- Formule de référence : EVE Ref (`autonomouslogic/eve-ref`, licence MIT-0). Recoupée avec Fuzzwork Blueprint Calculator (MIT) et le document « Formulas for EVE Industry » (Adam4EVE).
- `eve-evaluator` (AGPL) n'a pas été consulté.
