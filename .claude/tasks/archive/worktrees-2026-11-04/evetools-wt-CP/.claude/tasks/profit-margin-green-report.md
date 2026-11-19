# Phase verte backend : Profit Margins sur le noyau industrie (#141, #143)

Date : 2026-10-22. Worktree `evetools-wt-CP`, base d3ea2d6. Rien de commité. `tests/` et `frontend/` non touchés.

## Résultat des gates

| Gate | Commande (via `docker run` sur `evetools-app-dev`, même montage que `wt-php.sh`) | Résultat |
|---|---|---|
| PHPUnit Unit | `vendor/bin/phpunit --no-coverage --testsuite Unit` | OK, 3114 tests, 9303 assertions. 1 incomplet (`InstallCostTest`, D11a, déjà présent). 1 notice (GMP absent, `WebPushServiceCharacterizationTest`, déjà présente) |
| PHPUnit Integration + Functional | même commande sur le réseau `evetools_evetools_net`, `DATABASE_URL` du conteneur `app` (migrations identiques à main) | OK, 198 tests, 640 assertions |
| Cibles du rouge | Golden 39, Provider 11, MissingDataPresenter 3 | 53/53 vertes |
| Container | `bin/console lint:container` | OK |
| PHPStan | `vendor/bin/phpstan analyse` (cache dev réchauffé) | 61 erreurs, identique à HEAD (61). Aucune erreur dans les fichiers touchés |
| Deptrac | `vendor/bin/deptrac analyse` | 35 violations contre 36 sur HEAD. Aucune nouvelle ; celle de `MissingDataPresenter → Cost` (squelette du rouge) disparaît |
| Infection | `--with-uncovered --filter=src/Industry/,ProfitMarginService,MissingDataPresenter,ProfitMarginProvider,StructureRigTargets --test-framework-options=--testsuite=Unit` | MSI 85 % (main 97e7271 : 84 %), covered MSI 96 % (main : 95 %). HEAD est rouge, donc la comparaison se fait avec main |

## Fichiers modifiés

- `deptrac.yaml` :
  - `Service` peut dépendre de `IndustryApplication` et `IndustryDomain` (plan §0.1).
  - `StateProvider` peut dépendre de `IndustryApplication` : non prévu par le plan, mais imposé par le test du provider, qui injecte le port `Decryptors` pour le 400.
  - `IndustryInfrastructure` peut dépendre de `Constant`, pour `StructureRigTargets`.
  - Le commentaire du ruleset est mis à jour. Jamais `IndustryInfrastructure` depuis les dossiers plats.
- `config/services.yaml` : alias `RigCategories`, `UserProductionStructures`, `Decryptors`, dans le bloc principal et dans `when@test`.
- `src/Constant/StructureRigTargets.php` (nouveau) : la table `RIG_CATEGORY_MAPPINGS` sort de `SeedRigDefinitionsCommand`, qui la lit désormais via `StructureRigTargets::categoriesOf()`.
- Application (§0.3) :
  - `ProductionCostCalculator` : 6e argument `RigCategories`. L'invention est calculée avant le plan ; pour une cible inventée, le ME/TE est celui du BPC inventé (`InventionOutcome`), #143.
  - `ProductionCostRequest` : `productCategories` supprimé.
  - `StructureMaterialModifiers` : port `RigCategories`, recherche faite selon l'activité du job.
  - `InventionCost` expose `recipe`, `chance` et `outcome`.
- Adaptateurs (nouveaux) :
  - `Infrastructure/Sde/SdeDecryptors` : groupe 1304, attributs 1112/1113/1114/1124 ; un attribut absent lève `UnexpectedValueException`.
  - `Infrastructure/Sde/SdeRigCategories` : carte des groupes lue une fois. En réaction, on lit le groupe de la formule.
  - `Infrastructure/Doctrine/ConfiguredProductionStructures` :
    - types : `station` exclu ; types hérités et sécurité inconnue refusés.
    - taxe : pourcentage converti en fraction ; D14 via la constante `UNSET_FACILITY_TAX_PERCENT`.
    - bonus de rôle : SDE, ou `Multiplier::one()` s'il est absent.
    - rigs : Thukker découpé en deux `StructureRig`, rig sans bonus matériau ignoré.
- `MissingDataPresenter::of()` implémenté.
- `ProfitMarginService` réécrit sur le constructeur cible (§1) :
  - mode coût marginal, compétences à 0 (`INVENTION_SKILL_LEVEL_WITHOUT_SKILLS`), `FavoriteSystems($solarSystemId, $solarSystemId)` ou `none()` ;
  - 9 `costOf()` pour un T2, l'option sélectionnée réutilisant le résultat principal ;
  - `missingData` global et par étape ; marges `null` si le coût total est inconnu.
  - Supprimés : `collectLeafMaterials`, `addToMaterialList`, `collectJobInstallCosts`, `collectCopyCosts`, `resolveFacilityTaxRate`, ainsi que les dépendances `IndustryTreeService`, `EsiCostIndexService`, `InventionService`, `IndustryStructureConfigRepository`, `IndustryActivityMaterialRepository`.
  - Les constantes `InventionService::NO_DECRYPTOR_NAME` et `UNKNOWN_REASON_*` sont réutilisées. `InventionService` est gardé : ses méthodes ont d'autres appelants ou sont utilisées en interne.
- `ProfitMarginResource` : `copyCost`, `missingPriceTypeIds` et `unknownInstallCostReasons` sont supprimés. `missingData` est ajouté. Les montants sont nullables avec `null` par défaut ; quantités et runs passent en float, les noms sont nullables.
- `ProfitMarginProvider` : 5e argument `Decryptors`. Un `decryptorTypeId` hors `Decryptors::all()` lève `BadRequestHttpException` avant `analyze()`.

## Choix d'implémentation à valider

1. `inventionCost` d'un produit non inventé vaut `0.0` (constante `NO_INVENTION_COST`) : pas d'invention, ce n'est pas une donnée manquante. Aucun test ne le fixe.
2. `invention.unknownReason` :
   - `missing_invention_probability` si la probabilité est absente ;
   - `missing_market_price` si le coût d'invention manque d'un prix de marché (decryptor ou datacore) ;
   - sinon `null`, y compris quand seul l'indice de coût manque (T2 sans système).
3. Point 3 de la note complémentaire du rouge, cas non spécifiés tranchés en exception (`UnexpectedValueException`) plutôt qu'en valeur par défaut :
   - un rig dont le nom est absent du SDE ;
   - un produit de réaction sans formule ;
   - un type absent de `sde_inv_types` dans `SdeRigCategories`.

   Risque : un nom de rig mal saisi bloque tout l'écran pour cet utilisateur. `IndustryBonusService` retombait sur le groupe du produit, ce que ce code ne fait pas.
4. Le service lève `InvalidArgumentException` sur un decryptor inconnu (le provider filtre avant), et `LogicException` sur un utilisateur sans id.
5. Mutants échappés et laissés en l'état :
   - `array_values` : l'ordre des listes JSON n'est pas vérifié par les tests ;
   - `throw` d'invariants inatteignables ;
   - garde `totalCost > 0`, qui correspond à la question Q4 du rebase ;
   - filtre des marges nulles dans `bestMarginOf`, mutant équivalent.

## Hors périmètre (plan §5 étapes 5-6), à faire

- Glossaire (§0.2 : `missingData`, motifs `market_price` et `invention_probability`) et `decisions.md` pour les décisions (a) à (e).
- Note complémentaire du rouge, constat 1 : les catégories de réaction ne sont pas mappées sur les groupes de formules 1888-1890 dans `industry_rig_categories`. Les rigs de réaction ne s'appliquent donc probablement jamais en dev (donnée, `SeedIndustryRigCategoriesCommand`).
- Avant le déploiement :
  - vérifier en prod `select structure_type, count(*) from industry_structure_configs group by 1`. Les valeurs `engineering_complex` / `refinery` lèvent désormais une exception ;
  - aucune commande à relancer : `app:seed-rig-definitions` garde la même table.

## Impact frontend (contrat)

Champs retirés : `copyCost`, `missingPriceTypeIds`, `unknownInstallCostReasons`, `jobInstallSteps[].unknownReason`. Ajoutés : `missingData` (global et par étape). Devenus nullables : `materialCost`, `inventionCost`, `totalCost`, `costPerUnit`, `materials[].typeName`, `jobInstallSteps[].productName`, `decryptorName`. Passés en float : `materials[].quantity`, `jobInstallSteps[].runs`. Lecteurs : `frontend/src/stores/industry/types.ts`, `frontend/src/components/industry/ProfitMarginTab.vue`, `frontend/src/components/industry/profit/InventionOptionsPanel.vue`. Specs : `profitMarginTabUnknownMaterialPrice`, `profitMarginTabUnknownJobInstallCost`, `profitMarginTabSelectedDecryptorFees`, `inventionMissingMarketPrice`. Le frontend est traité par l'autre implementer (fichiers déjà modifiés dans le worktree).

## Effets de bord locaux

- `var/cache/dev` réchauffé dans le worktree pour PHPStan.
- `.env` de substitution déjà présent, non suivi par git.
