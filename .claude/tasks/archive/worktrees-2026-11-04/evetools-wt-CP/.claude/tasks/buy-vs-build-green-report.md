# Buy vs Build sur le noyau industrie : phase verte (rang 2)

- Date : 2026-10-22. Worktree `evetools-wt-CP`, branche `feat/buy-vs-build-on-core` (phase rouge 38571ef). Rien de commité.
- `tests/` et `frontend/tests/` non modifiés. Aucun changement dans `src/Industry/Application` (BB-f respecté).

## Fichiers modifiés

| Fichier | Changement |
|---|---|
| `src/Service/Industry/BuyVsBuildService.php` | Réécrit sur `ProductionCostCalculator` (constructeur du plan §2). N + 1 `costOf()` en `CostMode::Plan` : racine avec blacklist = composants, chaque composant avec blacklist = ses matériaux. Achat = prix structure × quantité, sinon `leaves[c]->cost` de la racine (BB-j). Verdict avec `Isk::equals()` (BB-e). `missingData` composant = build puis achat retenu ; résultat = composants (ordre de la recette) puis feuilles racine hors composants, installation racine, invention. Supprimés : `collectBuildableComponents`, `estimateJobInstallCost`, `collectLeafMaterialsFromTree`, `sumOfKnownCosts`, `missingPriceTypeIds`, `unknownInstallCostReasons`, les dépendances `IndustryTreeService`, `EsiCostIndexService`, `InventionService`, `IndustryActivityMaterialRepository` |
| `src/Industry/Domain/ProductionPlanner.php` | `MANUFACTURED_INTERMEDIATE_ME`, `MANUFACTURED_INTERMEDIATE_TE`, `REACTION_EFFICIENCY_LEVEL` passent de `private` à `public` (plan §2 étape 4, pas de duplication) |
| `src/ApiResource/Industry/BuyVsBuildResource.php` | `missingData` remplace `missingPriceTypeIds` et `unknownInstallCostReasons` ; forme d'un composant complétée |
| `src/State/Provider/Industry/BuyVsBuildProvider.php` | `mapToResource()` suit |
| `frontend/src/stores/industry/types.ts` | `BuyVsBuildComponent.missingData`, `BuyVsBuildResult.missingData` ; anciens champs retirés |
| `frontend/src/composables/useUnknownInstallCostReasons.ts` (nouveau) | dérivation `cost_index` / `adjusted_price` → motifs d'installation, extraite de `ProfitMarginTab.vue` |
| `frontend/src/components/industry/ProfitMarginTab.vue` | utilise le composable |
| `frontend/src/components/industry/BuyVsBuildTab.vue` | motifs dérivés de `missingData` (installation + `missing_invention_probability`) ; mention D13 sous le sous-titre, toujours affichée |
| `frontend/src/i18n/locales/{en,fr}.json` | clé `industry.scanner.buyVsBuild.blacklistNotApplied`, textes BB-h exacts |
| `docs/specs/industry-engine.md` | R12 : Buy vs Build dans les consommateurs du mode Plan |
| `docs/glossary.md` | **Coût inconnu** : Buy vs Build migré ; **Blacklist** : ignorée par Buy vs Build (D13) ; référence obsolète `BuyVsBuildService.php:280-284` retirée |

Aucune classe n'est devenue morte : `IndustryTreeService`, `InventionService::isT2()`, `EsiCostIndexService::estimateJobInstallCost()` et `findMaterialsForBlueprints()` gardent des appelants (projets, Pivot Advisor, scanner, ProductionCostService).

## Gates

Commandes PHP : `docker run` image `evetools-app-dev`, worktree sur `/app`, `vendor` du dépôt principal en lecture seule, env de `wt-php.sh` (unit : `DATABASE_URL` 127.0.0.1:1 ; integration : réseau `evetools_evetools_net` et `DATABASE_URL` du conteneur `app`). Référence `main` = export `git archive ac0a555` dans `/tmp/bvb-green/main`.

| Gate | Worktree | main (ac0a555) |
|---|---|---|
| Tests Buy vs Build (5 fichiers) | 66/66 verts, 344 assertions | (rouges par construction) |
| PHPUnit Unit | OK, 3099 tests, 9267 assertions, 1 incomplet préexistant (D11a), 220 PHPUnit notices | OK, 3098 tests, 230 notices |
| PHPUnit Integration | OK, 202 tests, 644 assertions | — |
| PHPStan | 59 erreurs, liste identique à main (diff vide, aucune sur les fichiers touchés) | 59 |
| Deptrac | 35 violations, 0 sur les classes touchées, 7275 autorisées | 35 (7225 autorisées) |
| Infection `BuyVsBuildService.php` | 147 mutants, 139 tués, 8 échappés, couverture 100 %, Covered MSI **94 %** | 264 mutants, 28 échappés, Covered MSI 89 % |
| eslint | 0 erreur, 82 warnings (aucun nouveau sur les lignes touchées) | |
| vitest | 28 fichiers, 197 tests verts (dont les 20 specs Buy vs Build) | |
| vue-tsc -b | 0 erreur | |
| vite build | OK | |

`frontend/tsconfig.tsbuildinfo` restauré (`git checkout`).

Mutants échappés (aucun ne justifie un test hors périmètre ; tests verrouillés) :
- tri : `&&` → `||` et `±1` du `default` de l'usort (aucun cas mélangé à valeurs égales) ;
- `<` → `<=` dans le verdict : équivalent (l'égalité est déjà couverte par `Isk::equals()`) ;
- `> 0.0` → `>= 0.0` (savingsPercent) et `<= 0.0` → `< 0.0` (marge) : seuls deux coûts nuls ou un total nul les distinguent, non testés ;
- `throw` de l'utilisateur sans id : non testé (même garde que Profit Margins).

## Points à confirmer

1. Visibilité publique des trois constantes d'efficacité par défaut de `ProductionPlanner` (Domain), plutôt qu'une duplication dans le service.
2. Clé i18n `industry.scanner.buyVsBuild.blacklistNotApplied`, affichée sous le sous-titre de l'onglet (visible avant même une analyse).
3. Produit sans recette : `DomainException` (même message que le noyau), comme Profit Margins ; avant, l'arbre ne levait rien d'explicite.
4. Le TE du job racine est une constante 0 (`ROOT_JOB_TIME_EFFICIENCY`), sans effet sur un montant.
5. `decisions.md` (BB-a à BB-k) est dans le dépôt principal, hors worktree : non modifié ici.

## Actions prod

Aucune migration, seed ni commande. Effet visible au déploiement (note de version, ADR-0009) : installations plus hautes (taxe NPC + SCC), invention incluse dans les T2, runs réels (#2), prix d'achat Jita pondérés, structures de l'utilisateur prises en compte, mention blacklist.

## Message de commit proposé

```
feat(industry): compute buy vs build with the industry core

Each component and the root job are costed by ProductionCostCalculator in plan mode; missingData replaces missingPriceTypeIds and unknownInstallCostReasons.
The tab derives its reasons from missingData and states that the industry blacklist does not apply.
```
