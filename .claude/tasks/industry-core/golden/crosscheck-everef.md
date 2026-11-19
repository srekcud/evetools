# Recoupement externe des goldens (D12) : EVE Ref + plan Ravworks 2MbF29y

Date : 2026-10-07. Deux sources externes indépendantes du script `tools/reference-bom.js` :

1. **API EVE Ref** `https://api.everef.net/v1/industry/cost` (doc : https://docs.everef.net/api/industry-cost.html, spec OpenAPI : https://github.com/autonomouslogic/eve-ref/blob/main/spec/eve-ref-api.yaml). 10 appels GET ponctuels, sans scraping.
2. **Plan Ravworks** https://ravworks.com/plan/2MbF29y, partagé par Sylvain avec sa configuration de structures. Une seule lecture de la page.

## Verdict

| Cas | Verdict | Résumé |
|---|---|---|
| `hail-l-10runs-me2-raitaru-nullsec` | **confirmé** | Job racine 4/4 matériaux et job R.A.M. 5/5 identiques à EVE Ref (runs, unités/run, quantités) |
| `fernite-carbide-10runs-tatara-nullsec` | **confirmé** | Racine 3/3, Ceramic Powder 3/3, Fernite Alloy 3/3, Hydrogen Fuel Block 9/9 identiques à EVE Ref. Le modificateur de réaction 0,9736 est aussi celui du plan Ravworks (48/48 matériaux de réaction) |
| `install-cost-vectors` (formules F3/F5) | **confirmé** (structure de la formule) | Rôle sur le seul terme SCI, taxe et SCC 4 % sur l'EIV (ou la JCB), Tatara sans bonus de rôle, JCB = 2 % EIV pour invention et copie |
| Contrôle : `nitrogen-fuel-block-25runs-me10-npc` | confirmé | 9/9 |
| Contrôle : `nomad-1run-me10-no-structure` (racine) | confirmé | 12/12 |
| Nouveau : `ravworks-2MbF29y-…-nullsec` | **nouveau golden, confirmé par recalcul** | 100/100 quantités consommées et 57/57 runs, à condition d'appliquer F1 **par job découpé** |

**Écarts inexpliqués : aucun.**

Limite : l'API EVE Ref calcule **un job à la fois** (pas de BOM multi-niveaux). Elle confirme la formule F1 par job, pas l'agrégation F2. Pour les intermédiaires, les runs du golden (5, 5, 3, 1) ont été passés tels quels. L'agrégation F2 est confirmée séparément par les deux plans Ravworks (Nomad, 2MbF29y).

## 1. Requêtes EVE Ref

Base : `https://api.everef.net/v1/industry/cost?`. Sans `system_id` ni indice, l'API met les indices de coût à 0 et prend les skills à 5 par défaut. Les skills n'influent pas sur les quantités. Les prix utilisés sont `ESI_AVG` (le champ `input.material_prices` de la réponse).

| # | Requête (paramètres) | Pour |
|---|---|---|
| 1 | `product_id=12779&runs=10&me=2&te=4&structure_type_id=35825&rig_id=37159&security=NULL_SEC` | Hail L racine (Raitaru + M-Set Ammunition ME II) |
| 2 | idem + `manufacturing_cost=0.05&facility_tax=0.10&invention_cost=0.08&copying_cost=0.02` | coûts F3/F5 |
| 3 | `product_id=11476&runs=1&me=10&te=20&structure_type_id=35825&security=NULL_SEC` | R.A.M.- Ammunition Tech (Raitaru sans rig) |
| 4 | `product_id=16673&runs=10&structure_type_id=35836&rig_id=46497&security=NULL_SEC&reaction_cost=0.03&facility_tax=0.05` | Fernite Carbide racine (Tatara + L-Set Reactor Efficiency II) + coût de réaction |
| 5 | `product_id=16660&runs=5&structure_type_id=35836&rig_id=46497&security=NULL_SEC` | Ceramic Powder |
| 6 | `product_id=16656&runs=5&structure_type_id=35836&rig_id=46497&security=NULL_SEC` | Fernite Alloy |
| 7 | `product_id=4246&runs=3&me=10&te=20&structure_type_id=35825&security=NULL_SEC` | Hydrogen Fuel Block (Raitaru sans rig) |
| 8 | `product_id=4051&runs=25&me=10&te=20&security=HIGH_SEC` | contrôle fuel block (sans structure) |
| 9 | `product_id=28846&runs=1&me=10` | contrôle Nomad (sans structure) |
| 10 | `product_id=11476&runs=10&me=10&te=20&copying_cost=0.02&manufacturing_cost=0.05&facility_tax=0.10` | copie directe (SCC sur copie) |

Type IDs vérifiés dans le SDE de la base dev : Raitaru 35825, Tatara 35836, rig ammo ME II 37159, Reactor Efficiency II 46497.

## 2. Matériaux, job par job

Toutes les colonnes « EVE Ref » sont les `materials[*].quantity` de la réponse. Les runs et les unités/run (`runs`, `units_per_run`) sont identiques au golden dans chaque cas.

### Hail L (golden `hail-l-10runs-me2-raitaru-nullsec`)

| Job | Matériau | Golden | EVE Ref | Écart |
|---|---|---|---|---|
| Hail L ×10 (5000/run, 50 000 u.) | Fernite Carbide | 27 640 | 27 640 | 0 |
| | Fullerides | 11 056 | 11 056 | 0 |
| | Morphite | 139 | 139 | 0 |
| | R.A.M.- Ammunition Tech | 10 | 10 | 0 |
| R.A.M. ×1 (100/run) | Tritanium | 496 | 496 | 0 |
| | Pyerite | 396 | 396 | 0 |
| | Mexallon | 198 | 198 | 0 |
| | Isogen | 74 | 74 | 0 |
| | Nocxium | 33 | 33 | 0 |

Les feuilles du golden sont exactement la réunion de ces deux jobs (Fernite Carbide et Fullerides achetés).

### Fernite Carbide (golden `fernite-carbide-10runs-tatara-nullsec`)

| Job | Matériau | Golden | EVE Ref | Écart |
|---|---|---|---|---|
| Fernite Carbide ×10 (10 000/run) | Ceramic Powder | 974 | 974 | 0 |
| | Fernite Alloy | 974 | 974 | 0 |
| | Hydrogen Fuel Block | 49 | 49 | 0 |
| Ceramic Powder ×5 (200/run) | Evaporite Deposits / Silicates / Hydrogen Fuel Block | 487 / 487 / 25 | 487 / 487 / 25 | 0 |
| Fernite Alloy ×5 (200/run) | Scandium / Vanadium / Hydrogen Fuel Block | 487 / 487 / 25 | 487 / 487 / 25 | 0 |
| Hydrogen Fuel Block ×3 (40/run) | Coolant 25, Enriched Uranium 11, Heavy Water 455, Hydrogen Isotopes 1203, Liquid Ozone 936, Mechanical Parts 11, Oxygen 59, Robotics 3, Strontium Clathrates 54 | identique | identique | 0 sur 9 |

EVE Ref confirme les trois règles verrouillées par ce cas : ME 0 sur les réactions, pas de bonus de rôle Tatara sur les matériaux, rig de réaction × 1,1 en nullsec (0,9736 et non 0,9496).

### Contrôles

- Nitrogen Fuel Block ×25, ME 10, sans structure : 9/9 identiques, Robotics 25 compris (règle `max(runs, …)`).
- Nomad ×1, ME 10, sans structure : 12/12 entrées du job racine identiques au golden Ravworks.

## 3. Coût d'installation (comparaison avec `install-cost-vectors.json`)

L'API calcule l'EIV à partir des adjusted prices du jour, pas des EIV synthétiques des vecteurs. On compare donc la **structure** de la formule : chaque composante divisée par sa base doit retomber sur les taux du vecteur.

| Composante (réponse EVE Ref) | Valeur | Rapport | Vecteur correspondant |
|---|---|---|---|
| Hail L, Raitaru : EIV | 13 645 553 | — | `manufacturing-raitaru-role-bonus` |
| `system_cost_index` | 682 278 | 5 % EIV | systemCost avant rôle |
| `system_cost_bonuses` | −20 468 | −3 % du terme SCI (rôle 0,97) | 48 500 = 50 000 × 0,97 |
| `facility_tax` | 1 364 555 | 10 % EIV (additif) | 100 000 |
| `scc_surcharge` | 545 822 | 4 % EIV | 40 000 |
| `total_job_cost` | 2 572 187 | **0,18850 × EIV** | 188 500 / 1 M = 0,1885 ✔ |
| Fernite Carbide, Tatara : EIV | 3 516 181 | — | `reaction-tatara-no-role-cost-bonus` |
| SCI / bonus / taxe / SCC | 105 485 / 0 / 175 809 / 140 647 | 3 % / 0 / 5 % / 4 % | **0,12000 × EIV** = 240 000 / 2 M ✔ |
| Invention (Hail L, Raitaru) : EIV | 2 752 044,32 | = EIV fabrication par run × 2,0168 runs d'invention | `invention-one-attempt` |
| `job_cost_base` | 55 040,89 | 2 % EIV | jcb |
| SCI / taxe / SCC | 4 403,27 / 5 504,09 / 2 201,63 | 8 % / 10 % / 4 % de la JCB | ✔ |
| `system_cost_bonuses` | −132,10 | −3 % du terme SCI | le vecteur met `structureCostModifier` 1,0 : pas d'impact, mais le rôle Raitaru s'applique aussi à l'invention chez EVE Ref |
| Copie directe (requête 10) : EIV | 508 120 | EIV fabrication × 10 runs | `copying-ten-runs` |
| `job_cost_base` / SCI / taxe / SCC | 10 162 / 203 / 1 016 / 406 | 2 % EIV / 2 % / 10 % / **4 %** de la JCB | ✔ (SCC 4 % sur la copie) |

Écart expliqué, sans effet sur les vecteurs : dans la réponse 2, la ligne `copying` (copie du BPO T1 pour l'invention) n'a pas des rapports exacts (SCI 7,39 pour une JCB de 353,61, soit 2,09 %). EVE Ref calcule la copie sur `ceil(2,0168) = 3` runs, arrondit chaque composante **à l'ISK** (JCB 526, SCI 11, taxe 53, SCC 21), puis multiplie par 2,0168 / 3 (`IndustryCalculator.calculateCopying`) : 11 × 0,6723 = 7,39, 53 × 0,6723 = 35,63, 21 × 0,6723 = 14,12. C'est un artefact d'affichage d'une copie fractionnaire, pas une autre formule.

Non vérifié par l'API : la taxe alpha (0,25 %) et l'index absent (`missing-cost-index`, `missing-adjusted-price`). Sur ce dernier point, EVE Ref ignore le matériau sans prix (avec un warning Sentry, `IndustryMath.eiv`) : c'est le comportement que le vecteur interdit pour evetools. EVE Ref ne fait donc pas référence ici.

### D11a — SCC 4 % sur la copie

EVE Ref applique 4 % sur la copie (requête 10). Sa constante `SCC_SURCHARGE_RATE = 0.04` est commune à toutes les activités. Elle est **validée en jeu** par deux captures d'écran de copie dans les fixtures du dépôt (`src/test/resources/com/autonomouslogic/everef/api/IndustryCostHandlerTest/armor-energizing-charge-blueprint/copy.jpg` et `copy-100-runs.jpg`). On y lit « SCC surcharge +4.00% JCB » sur un job de copie (JCB 33 703, SCC 1 348).

Ces captures datent du 2025-05-18 et du 2025-06-05 (commits « Industry cost API », « Blueprint copying on industry cost API (#884) »). Elles sont **antérieures** à la refonte du 2025-07-17 (S6), et `IndustryConstants.java` n'a pas été modifié depuis. **EVE Ref ne tranche donc pas D11a** : il prouve que le SCC de la copie était de 4 % avant juillet 2025, et S6 ne cite que ME/TE. Il faut toujours une vérification en jeu après juillet 2025 (détail du coût d'un job de copie). Le vecteur `copying-ten-runs` reste marqué `openQuestion`.

## 4. Plan Ravworks 2MbF29y

### Ce que la page affiche

- Produits finaux, en jobs séparés : Widow 6 × 3 runs ME 3 ; Marshal 1 × 1 ME 0 ; Nestor 10 × 1 ME 0 ; Machariel 6 × 1 ME 0.
- Mode de découpage par défaut : « Material jobs longer than 2.0 days are split ».
- 354 jobs, 57 pièces produites, 83 lignes de stock (achats et surplus).
- **La configuration de structures n'est pas affichée** : la page indique « Plan Uses Different Industry Config » sans détail. Skills et sécurité ne sont pas visibles non plus.
- Aucun nom de structure, de personnage ni de corporation dans les tableaux lus. Rien n'a été recopié hors des noms d'items.

### Configuration déduite, puis vérifiée

Méthode : pour chaque section Ravworks (produits finaux, composants avancés, composants capitaux, autres, réactions), on a testé les modificateurs plausibles : rôle 1 ou 0,99, rig T1 2,0 % ou T2 2,4 %, sécurité 1,0 / 1,9 / 2,1, rig de réaction × 1,0 / 1,1, ME 0 à 10. Chaque candidat est comparé aux matériaux consommés par cette seule section. Pour Ravworks, la demande d'un matériau vaut `To Buy + produit − End Amount`.

| Section | ME | Modificateur unique trouvé | Interprétation |
|---|---|---|---|
| Réactions (1er et 2e niveau) | 0 | **0,9736** (48/48 matériaux exclusifs) | rig T2 2,4 % × 1,1 nullsec, pas de rôle (Athanor/Tatara, non distinguables) |
| Composants avancés | 10 | **0,94842** (21/21) | 0,99 (rôle EC) × (1 − 2,0 % × 2,1) : rig T1 nullsec |
| Produits finaux | ME du job | **0,94842** (19/19) | idem |
| Composants capitaux, autres (Scorpion) | 10 | **0,94842** (seul candidat à 0 écart sur le plan complet) | idem |

Avec cette configuration, le recalcul F1 appliqué **à chaque job découpé** donne 100/100 quantités consommées (43 feuilles, 57 intermédiaires) et 57/57 runs d'intermédiaires (`runs = ceil(demande / unités par run)`), soit zéro écart.

### Comparaison aux goldens Hail L et Fernite Carbide

- Les produits diffèrent : ni Hail L ni une chaîne Fernite Carbide de 10 runs. Le plan devient donc un **nouveau golden** : `ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json`.
- Fernite Carbide y figure en intermédiaire (1 run), avec le **même modificateur de réaction** que le golden Fernite (0,9736). Ravworks consomme 98 Fernite Alloy (End Amount 102 sur 200), soit `ceil(1 × 100 × 0,9736)`. C'est la même formule que les 974 = `ceil(10 × 100 × 0,9736)` du golden.
- Le rôle EC × rig de fabrication modulé par 2,1 en nullsec, multiplicatifs, est confirmé sur 4 sections. Le golden Hail L utilise la même règle avec un rig T2 (0,99 × 0,9496).

### Ce que le plan apporte en plus : D11b confirmé

Si l'on fusionne les jobs découpés (un job par blueprint avec la somme des runs), **67 quantités sur 100 changent**. Exemples : Capital Jump Drive 53 au lieu de 57 (Widow 6 × 3 runs au lieu de 18), Oxygen Fuel Block 21 119 au lieu de 21 191, Titanium Carbide 14 965 731 au lieu de 14 965 748. Ravworks recalcule donc les matériaux job par job après découpage. C'est la règle D11b (#73), et le golden la verrouille.

Le contrôle Nomad (`README.md`, règle 5) n'avait pas d'écart sur ses deux découpages. Ce plan-ci en a.

Non repris : les coûts de job Ravworks (système et indices inconnus) et les prix.

## 5. Impact sur la spec

- F1 et les modificateurs (rôle, rig × sécurité, réactions × 1,1, ME 0 en réaction) : confirmés par deux sources indépendantes. Aucune modification.
- F2 (agrégation) : confirmée par Ravworks sur un deuxième plan, plus gros. Aucune modification.
- D11b : confirmé par l'observation (67/100 quantités dépendent du découpage). Le découpage est une entrée du calcul des matériaux, pas un post-traitement.
- F3/F5 : structure confirmée. Point à noter pour la spec : chez EVE Ref, le bonus de rôle de la structure (−3 % Raitaru) s'applique aussi au terme SCI de l'**invention** (et, via le même code, de la copie). Les vecteurs actuels n'en dépendent pas.
- D11a : non tranché (voir §3).

## Licences

- EVE Ref : code sous licence **MIT No Attribution** (fichier `LICENSE` du dépôt https://github.com/autonomouslogic/eve-ref, « Copyright 2017-2023 Autonomous Logic »). La documentation de l'API (https://docs.everef.net/api/) ne précise ni conditions d'usage, ni limite de débit, ni attribution exigée. Appels ponctuels uniquement. Les fixtures citées (captures en jeu) sont dans le même dépôt MIT-0.
- Ravworks : données de plan publiées par ravworks.com. Une seule page publique lue, sans scraping.
- Données EVE (noms, quantités SDE, captures d'écran du jeu) : propriété de CCP Games.
