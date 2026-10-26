# Spec #234 — Un job agrégé ne dépasse pas les runs maximum de son BPC

## Contexte

Depuis #69, le besoin d'un intermédiaire est agrégé sur tout le plan : une étape peut recevoir plus de runs que le BPC qui la fabrique n'en permet. `IndustryProjectFactory::jobsOf()` ne découpe une étape que par la durée maximale de job ; rien ne borne les runs d'un job aux runs maximum d'une copie. Cas réel sur le SDE golden : un Nomad de 2 runs, à la durée maximale par défaut (2 jours), planifie Hydrogen Fuel Block en 3 jobs de 214 runs et Nitrogen Fuel Block en un job de 211 runs, alors qu'une copie de ces blueprints porte au plus 200 runs.

Le noyau porte déjà la règle (spec du noyau, R3, « Plafond de runs d'un BPC (D2) » ; `ProductionPlanner::splitIntoJobs()`) : `ceil(runs / plafond)` jobs, runs répartis également, les premiers jobs prenant le reste. Le plafond y est une entrée (`PlanRequest::$blueprintCopyMaxRuns`) que la factory ne fournit pas encore ; son branchement est prévu au rang 7 de la migration. Décision de Sylvain : correctif ciblé dans la factory historique, sans attendre le rang 7. Plafond = `maxProductionLimit` du SDE (`IndustryBlueprint::$maxProductionLimit`), pour le flux supposé « BPO, puis copie, puis fabrication ».

Group Industry plafonne déjà ses lignes de job (`GroupIndustryProjectService::splitJobsByMaxProductionLimit()`), avec une autre répartition (jobs pleins puis reste : 10 + 10 + 10 + 5) et des valeurs par défaut quand le blueprint manque (500, 10 000).

## Règles métier

- **R1 — Étapes plafonnées.** Le plafond s'applique à une étape de fabrication dont le blueprint a une étape de copie dans le plan (activité de copie présente dans le SDE). Plafond = `maxProductionLimit` du blueprint. Aucune réaction n'est plafonnée : aucune des 120 formules de réaction du SDE local n'a d'activité de copie. Les étapes de copie ne sont pas plafonnées.
- **R2 — Nombre de jobs.** À la création et à la régénération, une étape plafonnée de N runs est découpée en `max(jobs par la durée, ceil(N / plafond))` jobs, soit `ceil(N / min(runs max par la durée, plafond))`. Une étape sans durée par run n'a que la contrainte du plafond. Moins de 2 jobs : l'étape reste intacte (#111).
- **R3 — Répartition.** Celle du découpage par durée et du noyau : runs répartis également (`SplitGroupQuantities::runsBySplitIndex()`), les premiers jobs prenant le reste ; quantités dans l'ordre de `splitIndex` jusqu'à épuisement du besoin (#112) ; `totalGroupRuns` = N. Aucun job ne dépasse le plafond.
- **R4 — Besoin des matériaux.** Un groupe découpé par le plafond est un groupe découpé comme un autre : le besoin de ses matériaux se calcule job par job (#73, R1), avant que ses matériaux soient planifiés (#69, R2). Un découpage par le plafond peut donc augmenter le besoin, et les runs, d'un intermédiaire en aval.
- **R5 — Création = régénération = recalcul.** Régénérer un projet juste créé donne les mêmes étapes ; le recalcul (#16) d'un projet juste créé ne modifie aucune étape.
- **R6 — Écrans dérivés.** Liste d'achats, coût de production et arbre affiché sont dérivés des étapes (#121) : ils suivent les jobs plafonnés sans changement de code. Le coût d'installation se calcule déjà par étape.
- **R7 — Plafond absent.** Une étape à plafonner dont le blueprint n'a pas de ligne dans `sde_industry_blueprints`, ou un `maxProductionLimit` inférieur ou égal à 0, fait échouer la génération avec une erreur explicite qui nomme le blueprint. Pas de plafond par défaut. Le SDE local n'a aucun cas de ce type, mais l'import écrit 0 quand la donnée manque (`SdeBlueprintImporter`, `?? 0`).

## Exemples chiffrés

SDE golden, sans structure, ME 10 / TE 20, plafonds de `sde-blueprint-recipes.json` (fuel blocks 200, Capital Electrolytic Capacitor Unit 800, Capital Fernite Carbide Composite Armor Plate 800, Capital Nuclear Reactor Unit 400, Auto-Integrity Preservation Seal 200, Life Support Backup Unit 200, Core Temperature Regulator 40, Scorpion 10, Widow 1, Nomad 1, Fenrir 1, réactions 1000). Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]`. Le golden doit déclarer une activité de copie pour chaque blueprint de fabrication, sauf le Marshal, comme le SDE local. Valeurs calculées avec un prototype jetable de la factory (plafond ajouté dans `jobsOf()`), relancé sans plafond pour la colonne « Aujourd'hui ». Les nombres d'étapes excluent les étapes de copie.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Nomad 2 runs, durée max 2 j : Hydrogen Fuel Block | `[[214, 8560, 5, 10, 20, i, 642]]` pour i = 0, 1, 2 | `[[161, 6440, 5, 10, 20, 0, 642], [161, 6440, 5, 10, 20, 1, 642], [160, 6400, 5, 10, 20, 2, 642], [160, 6400, 5, 10, 20, 3, 642]]` |
| E2 | E1 : Nitrogen et Helium Fuel Block | `[[211, 8420, 5, 10, 20, 0, null]]` ; `[[212, 8455, 5, 10, 20, 0, null]]` | `[[106, 4240, 5, 10, 20, 0, 211], [105, 4180, 5, 10, 20, 1, 211]]` ; `[[106, 4240, 5, 10, 20, 0, 212], [106, 4215, 5, 10, 20, 1, 212]]` |
| E3 | E1 : Oxygen Fuel Block, autres produits, nombre d'étapes | `[[86, 3435, 5, 10, 20, 0, null]]`, 701 étapes | Oxygen et les autres produits inchangés, 704 étapes |
| E4 | Nomad 1 run, durée max 2 j (snapshot `nomad-1run-me10-no-structure-per-product.json`) | Hydrogen 161 + 161, 373 étapes | inchangé : la durée découpe déjà sous le plafond |
| E5 | Nomad 1 run, durée max 5 j : Hydrogen Fuel Block (2,68 j, non découpé par la durée) | `[[322, 12855, 5, 10, 20, 0, null]]`, 172 étapes | `[[161, 6440, 5, 10, 20, 0, 322], [161, 6415, 5, 10, 20, 1, 322]]`, 173 étapes |
| E6 | E5 : Capital Electrolytic Capacitor Unit (2 jobs par la durée, plafond 800) | `[[476, 476, 1, 10, 20, 0, 951], [475, 475, 1, 10, 20, 1, 951]]` | inchangé (R2 : le maximum des deux contraintes) |
| E7 | Nomad 1 run, durée max 10 000 j : composants capitaux | Capital Electrolytic Capacitor Unit `[[951, 951, 1, 10, 20, 0, null]]` ; Capital Fernite Carbide Composite Armor Plate `[[1368, …]]` ; Capital Nuclear Reactor Unit `[[700, …]]` | `[[476, 476, 1, 10, 20, 0, 951], [475, 475, 1, 10, 20, 1, 951]]` ; 684 + 684 ; 350 + 350 |
| E8 | E7 : Hydrogen Fuel Block, Fernite Carbide, nombre d'étapes | `[[322, 12855, 5, 10, 20, 0, null]]` ; `[[972, 9719982, 2, 0, 0, 0, null]]` ; 66 étapes | `[[161, 6440, 5, 10, 20, 0, 322], [161, 6415, 5, 10, 20, 1, 322]]` ; `[[972, 9719983, 2, 0, 0, 0, null]]` (R4, réaction non plafonnée) ; 71 étapes |
| E9 | Réaction au-delà de son `maxProductionLimit` : Nomad 2 runs, durée max 10 000 j, Fernite Carbide | `[[1944, 19433661, 2, 0, 0, 0, null]]` | `[[1944, 19439969, 2, 0, 0, 0, null]]` : un seul job (R1), besoin relevé par les consommateurs plafonnés (R4) |
| E10 | Racine (Q1) : Scorpion 25 runs, durée max 10 000 j | Scorpion `[[25, 25, 0, 10, 20, 0, null]]` ; Auto-Integrity Preservation Seal `[[840, 2520, 1, 10, 20, 0, null]]` ; Life Support Backup Unit `[[420, 1260, 1, 10, 20, 0, null]]` | Scorpion `[[9, 9, 0, 10, 20, 0, 25], [8, 8, 0, 10, 20, 1, 25], [8, 8, 0, 10, 20, 2, 25]]` ; Seal 169 + 168 + 168 + 168 + 168 runs, 507 + 504 + 504 + 504 + 503 u., total 841 ; Life Support `[[141, 423, 1, 10, 20, 0, 421], [140, 420, 1, 10, 20, 1, 421], [140, 419, 1, 10, 20, 2, 421]]` ; Core Temperature Regulator `[[25, 25, 1, 10, 20, 0, null]]` inchangé |
| E11 | Racine (Q1) : Widow 3 runs, durée max 10 000 j | Widow `[[3, 3, 0, 10, 20, 0, null]]` ; Sustained Shield Emitter `[[4412, 4412, 1, 10, 20, 0, null]]` ; Magpulse Thruster `[[608, 608, 1, 10, 20, 0, null]]` | Widow `[1, 1, 0, 10, 20, i, 3]` pour i = 0, 1, 2 ; `[[2207, 2207, 1, 10, 20, 0, 4413], [2206, 2206, 1, 10, 20, 1, 4413]]` ; `[[609, 609, 1, 10, 20, 0, null]]` |
| E12 | Régénération du projet de E1 | — | étapes identiques à E1 (R5) |
| E13 | Recalcul (#16) du projet de E1 juste créé | — | aucune étape modifiée (R5) |
| E14 | Runs fixés (Q2) : Nomad 1 run, durée max 10 000 j, Hydrogen Fuel Block fixé à 322 runs, régénération | — | `[[322, 12880, 5, 10, 20, 0, null]]` : jobs fixés gardés (#185, G7) |
| E15 | Plafond absent (R7) : blueprint à copier sans ligne `sde_industry_blueprints` | — | création refusée, erreur nommant le blueprint, aucun projet persisté |

## Cas limites

- Plafond de 1 (Nomad, Fenrir, Widow) : un job par run. La limite de 20 jobs ne vaut que pour le découpage manuel (#111).
- N égal au plafond (200 runs pour un fuel block) : un seul job.
- Blueprint de fabrication sans activité de copie (Marshal ; 580 blueprints de fabrication dans le SDE local : T3, faction, mutaplasmides…) : pas d'étape de copie, pas de plafond (Q4).
- Type blacklisté, acheté ou en stock : inchangé, le plafond porte sur les runs planifiés à la génération.
- Étape ajoutée à la main : hors génération, non plafonnée.

## Hors périmètre

- Branchement du `ProductionPlanner` (rang 7) : il fournira `blueprintCopyMaxRuns` (BPC possédé, BPC inventé). R2 et R3 donnent la même répartition que `ProductionPlanner::splitIntoJobs()` : la migration ne changera pas les jobs.
- Group Industry (`splitJobsByMaxProductionLimit()`) : répartition « jobs pleins » et valeurs par défaut 500 / 10 000 inchangées. Écart à noter dans `.claude/tasks/todo.md`.
- Étape de copie : ses runs restent Σ des runs des branches de l'arbre (`collectStepsFromTree()`), sans nombre de copies (Q3). Kit de BPC et coût de copie inchangés.
- Découpage et fusion manuels (`SplitStepProcessor`, `MergeStepsProcessor`) : aucun contrôle du plafond (Q2).
- `IndustryTreeService` (Stockpile, Group Industry, catalogue SDE) et projets déjà en base : ces derniers sont corrigés à la prochaine régénération, sans migration.

## Décisions proposées (à valider avec les questions)

- **Durée et plafond (R2).** On prend le maximum des deux nombres de jobs, en un seul découpage. Écartée : découper d'abord par le plafond, puis chaque job par la durée. Pour 322 runs avec un plafond de 200 et 150 runs max par la durée, on obtiendrait 4 jobs (81 + 80 + 81 + 80) au lieu de 3 (108 + 107 + 107), avec deux règles de répartition différentes.
- **Plafond absent (R7).** Erreur plutôt que « pas de plafond » ou valeur par défaut, comme le type inconnu à la création.

## Tests existants appelés à changer

- Le harnais golden de la factory (`BuildsProjectOnGoldenSde`) ne déclare aucune activité de copie (`fetchOne` renvoie 0) : sans option nouvelle, aucun test golden existant ne change. Les tests rouges ont besoin d'une option « activité de copie » (comme `GroupIndustryBomGoldenCharacterizationTest::hasCopyActivity()`) et des plafonds du golden.
- Nouvelle dépendance de la factory pour lire `maxProductionLimit` : constructions à mettre à jour dans `BuildsProjectOnGoldenSde`, `IndustryProjectFactoryTest`, `IndustryProjectFactoryRegenerationTest`, `UpdateStepFixedRunsTest`.
- Tests d'intégration dont le SDE de fixture déclare une copie et un plafond inférieur aux runs générés : à lister dans la note de câblage.

## Glossaire

- Ajouter **Plafond de runs d'un BPC** (BPC max runs) : nombre maximal de runs d'un job lancé sur une copie de blueprint, `maxProductionLimit` du SDE ; au-delà, l'étape est découpée en groupe découpé (spec #234). Symboles : `IndustryBlueprint::$maxProductionLimit`, `Recipe::$maxProductionLimit`, `PlanRequest::$blueprintCopyMaxRuns`.
- **Étape** et **Groupe découpé** : une étape est aussi découpée quand ses runs dépassent le plafond de runs de son BPC.

## Décisions validées (2026-10-23)

1. **Racine du projet (E10, E11).** Le plafond s'applique à l'étape racine ; ses runs totaux restent ceux du projet.
2. **Recalcul, découpage manuel, runs fixés (E13, E14).** Plafond appliqué à la création et à la régénération seulement. Les jobs fixés gardent leurs jobs, même au-delà du plafond (#185, G7) ; la cascade (#16) ne crée pas de job ; la fusion manuelle reste libre.
3. **Étape de copie.** Hors périmètre : issue séparée (nombre de copies, runs par copie, coût de copie, kit de BPC).
4. **Blueprints sans copie et T2 inventés.** Pas de plafond dans ce lot ; le rang 7 fournira les runs du BPC inventé ou possédé.
