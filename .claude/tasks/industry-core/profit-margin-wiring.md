# Câblage de ProfitMarginService sur l'Application Industry (spec §7, rang 1)

- Date : 2026-10-09
- Phase rouge : worktree `evetools-wt-CP`, `tests/Unit/Service/Industry/ProfitMarginGoldenCharacterizationTest.php`
- Rang 2 (BuyVsBuild) : voir `buy-vs-build-wiring.md`, qui reprend les sections 0 et 2 de ce document
- Tout ce qui suit a été vérifié dans le code du worktree et, pour les valeurs SDE, dans la base locale (`sde_dgm_type_attributes`, `sde_inv_types`)

## 0. Préalables communs aux rangs 1 et 2

### 0.1 Deptrac

`deptrac.yaml` interdit aujourd'hui aux dossiers plats d'importer `Industry*` (commentaire « The flat folders never import the Industry layers », règle `Service: [...]` sans couche Industry). ProfitMarginService et BuyVsBuildService restent dans `src/Service/Industry/` : ils dépendent de `JitaMarketService`, `StructureMarketService`, `PublicContractPriceService` (couche Service), donc ils ne peuvent pas descendre en Application (Application → Service interdit, et c'est voulu).

Décision proposée : ajouter `IndustryApplication` et `IndustryDomain` à la règle `Service` (consommateurs du strangler, ADR-0009 « chaque appelant passe à l'Application »), jamais `IndustryInfrastructure` (câblée par le conteneur, `config/services.yaml:70-75`). Mettre à jour le commentaire du ruleset. Le Domain reste nécessaire côté Service pour les value objects d'entrée (`Runs`, `BlueprintEfficiency`, `MaterialEfficiency`, `TimeEfficiency`, `Decryptor`) et la lecture des résultats (`Cost`, `MissingData`).

### 0.2 Forme commune de l'inconnu (R10), rangs 1 et 2

Tranché : un montant inconnu est `null`, et chaque objet qui porte au moins un montant expose `missingData: list<array{reason: string, typeId: ?int}>`, copie de `App\Industry\Domain\MissingData` (dédoublonnée par `Cost`, ordre d'apparition).

- `reason` ∈ `market_price`, `adjusted_price`, `cost_index`, `invention_probability` (les quatre fabriques de `MissingData`). `typeId` est `null` pour `cost_index`, le blueprint T1 pour `invention_probability`.
- Une seule conversion `list<MissingData>` → tableau, partagée par les deux services : `App\Service\Industry\MissingDataPresenter::of(Cost $cost): list<array{reason: string, typeId: ?int}>` (deux appelants, donc pas une abstraction spéculative ; elle n'a pas sa place dans le Domain, qui ne sérialise rien).
- `unknownReason` ne disparaît pas tout de suite : il reste dans le bloc `invention` de Profit Margins (`'missing_invention_probability'`, contrat frontend `InventionUnknownReason`) et dans le scanner tant qu'il n'a pas migré. Pas de nouveau `unknownReason`.
- Glossaire à aligner : ligne **Coût inconnu** (« les services migrés exposent `missingData` ; `unknownReason` subsiste pour le scanner et le bloc invention de Profit Margins jusqu'à leur migration ») et ligne **Donnée manquante** (motifs : ajouter `market_price` et `invention_probability`, aujourd'hui seuls `adjusted_price` et `cost_index` y figurent).

### 0.3 Changements de l'Application partagés

| Changement | Pourquoi | Fichier |
|---|---|---|
| Port `RigCategories` (6e argument de `ProductionCostCalculator`), qui remplace `ProductionCostRequest::$productCategories` | La requête exige la catégorie de **tous** les produits de job, intermédiaires compris, que l'appelant ne connaît pas avant le plan (`StructureMaterialModifiers::forJob()` lève une exception sinon). La recherche doit être paresseuse | `src/Industry/Application/RigCategories.php`, `StructureMaterialModifiers.php`, `ProductionCostCalculator.php`, `ProductionCostRequest.php` |
| `InventionCost` expose `InventionRecipe $recipe`, `?InventionChance $chance`, `InventionOutcome $outcome` | Profit Margins affiche la probabilité de base, les datacores, la probabilité, le ME, le TE et les runs de chaque option ; sans ça le service recalculerait R8 | `src/Industry/Application/InventionCost.php` |
| Pour un produit inventé, le ME/TE de la cible est celui du BPC inventé (`InventionOutcome`), pas `$request->efficiency` | Décision (c) ci-dessous | `ProductionCostCalculator::costOf()` |
| `ProductionCost` expose `?Plan $plan` (null en mode coût marginal) | Rang 2 seulement : demande et jobs de chaque intermédiaire | `src/Industry/Application/ProductionCost.php` |

## 1. Signature cible de ProfitMarginService

```php
public function __construct(
    private ProductionCostCalculator $productionCosts,        // App\Industry\Application
    private UserProductionStructures $productionStructures,   // App\Industry\Application, nouveau port
    private Decryptors $decryptors,                           // App\Industry\Application, nouveau port
    private JitaMarketService $jitaMarketService,             // prix de vente Jita du produit, volume journalier
    private StructureMarketService $structureMarketService,
    private PublicContractPriceService $publicContractPriceService,
    private TypeNameResolver $typeNameResolver,               // noms des matériaux, des étapes, des datacores, des decryptors
    private CachedStructureRepository $cachedStructureRepository,
    private IndustryBlacklistService $blacklistService,
)
```

`analyze()` garde sa signature (le harnais l'appelle en positionnel).

Disparaissent du constructeur : `IndustryTreeService`, `EsiCostIndexService`, `InventionService`, `IndustryStructureConfigRepository` (passe dans l'adaptateur de structures), `IndustryActivityMaterialRepository`. Disparaissent du service : `collectLeafMaterials`, `addToMaterialList`, `collectJobInstallCosts`, `collectCopyCosts`, `resolveFacilityTaxRate`, et `findBestMarginForCost` renvoie `?float` (plus de `-999.0` / `0.0`). `InventionService` n'est pas supprimé : `BuyVsBuildService`, `BatchProfitScannerService`, `PivotAdvisorService`, `IndustryResourceMapper`, `CopyCostsProvider`, `BpcKitProvider`, `CreateProjectProcessor` l'utilisent encore.

Déroulé de `analyze()` :

1. `structures` = `[]` sans utilisateur, sinon `UserProductionStructures::configuredBy(userId)` ; `blacklist` = `IndustryBlacklistService::resolveBlacklistedTypeIds()` ; `favoriteSystems` = `new FavoriteSystems($solarSystemId, $solarSystemId)`, ou `FavoriteSystems::none()` si `$solarSystemId` est null (décision d).
2. Requête `CostMode::MarginalCost`, `InventionSettings(0, 0, 0, decryptor sélectionné)` (décision b). `ProductionCost->invention !== null` donne `isT2` : plus besoin de `InventionService::isT2()`.
3. Produit inventé : un `costOf()` par option, sans decryptor puis un par decryptor de `Decryptors::all()` (9 appels, l'option sélectionnée réutilisée comme résultat principal). Chaque option donne `me`, `te`, `runs` (outcome), `probability` (chance, null si inconnue), `inventionCost`, `totalProductionCost`, `bestMargin`.
4. Sortie : `materials[].quantity` float, `unitPrice` / `totalPrice` `?float` ; `jobInstallSteps[]` avec `runs` float et `installCost` `?float` ; `materialCost`, `jobInstallCost`, `inventionCost`, `totalCost`, `costPerUnit` `?float` ; `missingData` = `MissingDataPresenter::of($totalCost)` ; marges `null` dès que `totalCost` est inconnu.
5. `copyCost` disparaît de la sortie (voir §4, test D).

`ProfitMarginResource` et `frontend/src/stores/industry/types.ts` (`ProfitMarginResult`) suivent : types nullables, `missingData`, suppression de `copyCost`, `ProfitMarginTab.vue:258` (`inventionCost + copyCost` devient `inventionCost`).

## 2. Dépendances manquantes : ports et adaptateurs

| Besoin | Existe dans `src/Industry` ? | Proposition |
|---|---|---|
| Catégorie de rig d'un produit | Non. `IndustryBonusService::getCategoryForProduct()` (`:83`) a la règle, hors noyau | Port `Application\RigCategories::categoryOf(int $productTypeId, ActivityKind $activity): ?string`. Adaptateur `Infrastructure\Sde\SdeRigCategories` : `IndustryRigCategoryRepository::buildGroupCategoryMap()` (une fois), groupe du **produit** en fabrication, groupe de la **formule** en réaction (`IndustryActivityProductRepository::findBlueprintForProduct($typeId, 11)` puis `InvTypeRepository::find()`), comme `IndustryBonusService.php:85-109`. `null` = le groupe n'est ciblé par aucun rig : bonus de rôle seul, ce qui est la règle du jeu, pas une valeur par défaut |
| `IndustryStructureConfig` → `ProductionStructure` | Non. Les lecteurs dogma existent : `SdeStructureBonusReader`, `SdeRigBonusReader` (non câblés) | Port `Application\UserProductionStructures::configuredBy(string $userId): list<ProductionStructure>` (le nom `ProductionStructures` est déjà pris par le double `tests/Unit/Industry/Application/Double/ProductionStructures.php`). Adaptateur `Infrastructure\Doctrine\ConfiguredProductionStructures`, détail ci-dessous |
| Modificateurs des decryptors | Non. Seule la table codée en dur `InventionService::DECRYPTORS` (`:35-44`) | Port `Application\Decryptors::all(): list<Decryptor>` (triés par typeId). Adaptateur `Infrastructure\Sde\SdeDecryptors` : types du groupe 1304 (`InvTypeRepository::findBy(['group' => 1304])`), attributs via `DogmaAttributeValues` : 1112 `inventionPropabilityMultiplier`, 1113 `inventionMEModifier`, 1114 `inventionTEModifier`, 1124 `inventionMaxRunModifier`. Un attribut absent lève `UnexpectedValueException` (SDE incomplet), jamais un 0. Vérifié en base : 8 decryptors (34201-34208), valeurs identiques à la table de #70. Un `decryptorTypeId` de la requête absent de `all()` : `BadRequestHttpException` dans le provider, au lieu de l'ignorer comme `InventionService.php:265` |
| Compétences d'invention | Non (`InventionSettings` les attend, rien ne les fournit) | Pas de port au rang 1 (décision b) |
| Prix marché, adjusted prices, cost indices, recettes, invention | Oui : `JitaMarketPrices`, `EsiAdjustedPrices`, `EsiSystemCostIndices`, `SdeBlueprintCatalog`, `SdeInventionCatalog` | Inchangés |

### Adaptateur `ConfiguredProductionStructures`

Dépendances : `IndustryStructureConfigRepository` (`findBy(['user' => $userId, 'isDeleted' => false])`, pas `findByUser()` qui exige l'entité), `InvTypeRepository::findByNames()` (les rigs sont stockés par nom, `IndustryStructureConfig::$rigs`), `SdeStructureBonusReader`, `SdeRigBonusReader`, la table de ciblage des rigs.

| Champ de `ProductionStructure` | Source |
|---|---|
| `id` | `getId()->toRfc4122()` |
| `type` | `structureType` : `raitaru` 35825, `azbel` 35826, `sotiyo` 35827 → `EngineeringComplex` ; `athanor` 35835, `tatara` 35836 → `Refinery` (typeIds de `StructureSearchProvider.php:159-163`). `station` : pas de `ProductionStructure`, le job tombe en station NPC (0,25 %, R6). `engineering_complex` / `refinery` (valeurs héritées, encore acceptées par `CreateStructureInput`) : `UnexpectedValueException`, pas de bonus deviné. Base locale : aucune ligne héritée ; vérifier la prod avant déploiement (`select structure_type, count(*) from industry_structure_configs group by 1`) et migrer si besoin |
| `security` | `securityType` `highsec` / `lowsec` / `nullsec` → `SecurityClass` ; autre valeur : exception |
| `solarSystemId`, `configuredAt` | `getSolarSystemId()`, `getCreatedAt()` |
| `manufacturingMaterialRoleBonus`, `costRoleBonus` | `SdeStructureBonusReader::materialMultiplierOf()` / `costMultiplierOf()` ; `null` (raffineries) = pas de bonus de rôle selon le SDE → `Multiplier::one()`. Vérifié : Raitaru 2600 = 0,99, 2601 = 0,97 |
| `rigs` | Par rig : `SdeRigBonusReader::materialBonusOf(rigTypeId, Manufacturing \| Reaction selon le type)` ; `null` (rig de temps seul) : rig ignoré. Ciblage : table nom → catégories. Rig Thukker (attribut 2653 présent, vérifié : XL Thukker 2594 = −2, 2653 = −3,7, modificateurs 0,1 / 1,9 / 0,1) : deux `StructureRig`, l'un `thukkerMaterialBonusOf()` sur `basic_capital_component`, l'autre le bonus 2594 sur les autres catégories ciblées. `StructureRig` ne change pas |
| `facilityTaxRate` | `getFacilityTaxRate()` est en **pourcentage** (le harnais passe 10.0) : diviser par 100. `null` : décision (e) |

Table de ciblage : le projet en a déjà trois copies (`IndustryBonusService::getRigOptions()`, `RigOptionsProvider::getRigOptionsArray()`, `SeedRigDefinitionsCommand::RIG_CATEGORY_MAPPINGS`). Proposition : déplacer `RIG_CATEGORY_MAPPINGS` (motif de nom → catégories, 23 lignes, plus spécifique d'abord) dans `App\Constant\StructureRigTargets`, lu par `SeedRigDefinitionsCommand` et par l'adaptateur. Les deux autres copies partent avec `IndustryBonusService` en fin de migration. Le spec D10 (« ciblage par `IndustryRigCategory` ») reste vrai : la table donne rig → catégorie, `IndustryRigCategory` donne catégorie → groupes.

Services : `config/services.yaml`, ajouter les alias `RigCategories`, `UserProductionStructures`, `Decryptors` (bloc `:70-75` et bloc `when@test`).

## 3. Décisions ouvertes et recommandations

**(a) Goldens en mode plan, Profit Margins en mode coût marginal (Robotics 22,5 au lieu de 25).** Garder le mode coût marginal, comme le harnais l'écrit. R12 et D4b le disent explicitement (« sur les cibles aussi », `ProductionPlanner::marginalCost()`). Le golden §4.1 (25) reste asserté en mode plan par `GoldenPlanTest`. À corriger dans la spec : le critère d'acceptation §6.8 (« arbre, étapes, liste d'achats et Profit Margins donnent les mêmes quantités ») contredit D4 ; proposer « les mêmes quantités **dans le même mode** ». Compromis connu : sur un produit à petite quantité par run, Profit Margins sous-estime le coût du `max(runs, …)` (2,5 Robotics ici, < 0,1 % du coût).

**(b) Compétences d'invention.** Niveau 0 pour les trois compétences au rang 1, dans une constante nommée du service (`INVENTION_SKILL_LEVEL_WITHOUT_SKILLS = 0`). C'est le comportement actuel (`InventionService.php:315` n'applique aucune compétence), donc pas de régression, et c'est une borne basse assumée, pas une donnée ESI manquante. Le libellé de l'écran doit le dire (« probabilité sans compétences »). Suite : issue dédiée, port `InventionSkills` sur `CachedCharacterSkill` (personnage à choisir) et `sde_industry_activity_skills` (activité 8 du blueprint T1 : deux sciences + une encryption, à distinguer).

**(c) ME d'un T2.** ME/TE du BPC inventé : `2 + ΔME`, `4 + ΔTE` du decryptor de l'option (`InventionOutcome`), le `me` / `te` de la requête ignorés pour un produit inventé. C'est ce que le harnais attend (Process : ME 5, Hail L sans decryptor : ME 2 = requête par hasard), et c'est la seule lecture où `totalProductionCost` de chaque option est cohérent. Le provider envoie `me = 10` par défaut, faux pour un T2 jamais recherché. Le calcul appartient à l'Application (`costOf()`), pas au service. Les BPO T2 (anciens, non obtenables) sont hors cas.

**(d) `solarSystemId` comme système favori de toutes les activités.** Oui au rang 1 : `FavoriteSystems($solarSystemId, $solarSystemId)`. L'écran n'a qu'un sélecteur de système, l'ancien code utilise ce système pour tous les jobs, et le harnais met l'indice de réaction dans `MANUFACTURING_SYSTEM_ID`. Suite (issue) : le provider lit `favoriteReactionSystemId` de `IndustryUserSettings` quand la requête ne fixe pas de système. Le repli provider `?? JITA_SOLAR_SYSTEM_ID` (`ProfitMarginProvider.php:71`) reste une valeur d'écran, hors noyau.

**(e) Nouveau : taxe d'installation `null` d'une structure.** Les 6 structures de la base locale ont `facility_tax_rate` à null, et le frontend n'expose pas ce champ (aucune occurrence dans `frontend/src`). Rendre le coût inconnu rendrait inconnus tous les coûts avec structure. Recommandation : `null` lu comme 0 % dans l'adaptateur, via une constante nommée, décision consignée dans `decisions.md`, et issue « exposer la taxe dans le formulaire de structure puis la rendre obligatoire (NOT NULL DEFAULT 0) ». Alternative stricte : `MissingData::facilityTax()`, qui bloque l'écran tant que l'issue n'est pas faite. À trancher par Sylvain.

## 4. Les quatre tests hors « Currently »

Préalable au harnais : `setUp()` met `structureConfigs = []` (les seuls tests qui gardaient la structure par défaut sont ceux-ci, et l'ancien sens « structure taxée à 10 % » disparaît), et `structure()` fixe `structureType` et `securityType` (sinon `getStructureType()` lit une propriété non initialisée). Les montants de référence sans structure sont déjà dans le fichier : `HAIL_L_ONE_RUN_ENGINE_TOTAL_COST = 694548.564245`, `HAIL_L_ONE_RUN_PROCESS_TOTAL_COST = 673574.595154`. Les constantes `HAIL_L_ONE_RUN_TOTAL_COST`, `HAIL_L_ONE_RUN_MATERIAL_COST`, `HAIL_L_ONE_RUN_JOB_INSTALL_COST`, `HAIL_L_INVENTION_COST`, `HAIL_L_COPY_COST` et la valeur littérale `652513.732561` deviennent mortes.

**A. `testFacilityTaxFallsBackToDefaultStructure` : réécrire.** `isDefault` ne sert plus (R4, D3) ; D3b est déjà couvert par `StructureSelectorTest`. Le test devient un test de câblage du mapping : `testStructureOutsideTheFavoriteSystemRunsTheJobWithItsTaxAndItsCostIndex`. Entrée : une seule Raitaru highsec, sans rig, dans `OTHER_SYSTEM_ID`, taxe 20 %, Nitrogen Fuel Block 1 run ME 10, système `MANUFACTURING_SYSTEM_ID`, harnais avec un indice de fabrication 0,05 aussi pour `OTHER_SYSTEM_ID` et les attributs Raitaru (2600 = 0,99, 2601 = 0,97). Attendu : `jobInstallCost = 17031.184 × (0.05 × 0.97 + 0.20 + 0.04) = 4913.496584`, `materialCost = 153401.29 × 0.99 = 151867.2771` (bonus de rôle, catégorie null, mode marginal linéaire). Une seule structure : pas d'égalité départagée par `createdAt`, que le harnais ne peut pas fixer.

**B. `testDecryptorOptionCarriesTotalCostAndBestVenueMargin` : réécrire, mêmes prix de vente.** Sans structure. Meilleur lieu : contrat 690 000 (Jita 649 600). Attendu : option sans decryptor `totalProductionCost = 694548.564245`, `bestMargin = (690000 − 694548.564245) / 694548.564245 × 100 = −0.654895`, égal à `margins.contractSell.margin` ; Process `totalProductionCost = 673574.595154`, `bestMargin = 2.438543`.

**C. `testDecryptorOptionBestMarginIsTheBestMarketVenueWhenContractsSellLower` : réécrire, mêmes prix.** Jita 658 880 gagne. Attendu : sans decryptor `−5.135503` (= `margins.jitaSell.margin`), Process `−2.181584`. Les marges négatives ne changent pas ce que le test prouve.

**D. `testUnknownInventionProbabilityLeavesTotalCostAndMarginsUnknown` : réécrire.** Sans structure, probabilité null. Attendu : `materialCost = 688011.024112` ; `inventionCost`, `totalCost`, `costPerUnit` null ; marges toutes null ; `invention.baseProbability` null, `unknownReason = 'missing_invention_probability'` ; 9 options avec `probability`, `inventionCost`, `totalProductionCost`, `bestMargin` null ; `missingData = [['invention_probability', HAIL_L_T1_BLUEPRINT]]`. Supprimer l'assertion `copyCost` : dans le noyau, la copie est une partie de chaque tentative (R6, `InventionCost::$copyInstallCost`), son coût par succès est aussi inconnu, et l'ancien `copyCost` (copies des intermédiaires de l'arbre, `collectCopyCosts`) n'existe plus, les intermédiaires étant des BPO ME 10 / TE 20 (R3).

## 5. Découpage

### Le test-writer, avant verrouillage de `tests/`

1. Harnais du test golden : appel du constructeur cible (§1) ; `ProductionCostCalculator` avec un 6e argument `RigCategories` (vrai `SdeRigCategories` sur `IndustryRigCategoryRepository` et `InvTypeRepository` stubés, `null` partout suffit aujourd'hui) ; vrai `ConfiguredProductionStructures` sur un stub `IndustryStructureConfigRepository::findBy()` qui renvoie `$this->structureConfigs`, un stub `DgmTypeAttributeRepository::findBy()` (attributs Raitaru et des 8 decryptors) et `InvTypeRepository::findByNames()` ; vrai `SdeDecryptors` sur le même stub dogma et `InvTypeRepository::findBy()` ; noms des decryptors dans `typeNames`.
2. `userWithTaxedStructureInManufacturingSystem()` renvoie un `User` avec un id (réflexion sur `User::$id`, pas de setter) ; le renommer `user()`.
3. `setUp()` et `structure()` comme en §4 ; réécrire A à D ; ajouter `assertNull($result['invention'])` sur un test Nitrogen (seule couverture de `ProfitMarginServiceTest::testDecryptorOptionsForT1IsNull` absente des goldens).
4. Supprimer `tests/Unit/Service/Industry/ProfitMarginServiceTest.php` (il mocke `IndustryTreeService`, `EsiCostIndexService`, `InventionService` ; ses cas sont couverts par le golden). Vérifier `tests/Unit/State/Provider/Industry/ProfitMarginProviderTest.php` contre les types nullables du resource et `missingData`.
5. Tests rouges de l'Application : `ProductionCostCalculatorTest` et `StructureMaterialModifiersTest` passent au port `RigCategories` (fin de `productCategories`) ; ME/TE du BPC inventé pour une cible inventée ; `InventionCost` expose `recipe`, `chance`, `outcome` ; `ProductionCost::$plan` en mode plan (rang 2). Double `InMemoryRigCategories` dans `tests/Unit/Industry/Application/Double/`.
6. Tests rouges des adaptateurs (`tests/Unit/Industry/Infrastructure/`) : `SdeDecryptors` (8 decryptors, attribut absent → exception), `SdeRigCategories` (groupe produit / groupe formule, groupe non mappé → null), `ConfiguredProductionStructures` (pourcentage → fraction, `station` exclue, type hérité refusé, `createdAt`, rig de temps ignoré, rig Thukker en deux `StructureRig`, raffinerie sans bonus de rôle).
7. `MissingDataPresenterTest` (ordre, dédoublonnage hérité de `Cost`, `typeId` null).

### L'implementer, tests verrouillés

1. `deptrac.yaml` (§0.1).
2. Ports `RigCategories`, `UserProductionStructures`, `Decryptors` ; adaptateurs `SdeRigCategories`, `ConfiguredProductionStructures`, `SdeDecryptors` ; `App\Constant\StructureRigTargets` ; alias `services.yaml`.
3. Changements Application du §0.3.
4. `MissingDataPresenter`, puis `ProfitMarginService` (§1), `ProfitMarginResource`, `ProfitMarginProvider` (decryptor inconnu → 400).
5. Frontend : `ProfitMarginResult` (nullables, `missingData`, sans `copyCost`), `ProfitMarginTab.vue` (affichage « inconnu » des montants null, `effectiveInventionPlusCopy`), libellé « sans compétences ».
6. Glossaire (§0.2) ; `decisions.md` pour (a) à (e) une fois tranchées ; spec §6.8 et tableau R12.
7. `make test`, `make deptrac`, PHPStan, puis `make infection FILTER=src/Industry`.

## 6. Risques et compromis

- **Coût** : un T2 fait 9 `costOf()` (plan, prix pondérés Jita, recettes SDE à chaque fois). À mesurer sur un capital T2 ; si besoin, mémoriser `SdeBlueprintCatalog::recipeFor()` par requête, pas de cache Redis au rang 1.
- **Chiffres visibles** : installation plus élevée (SCC, taxe additive), plus de copies d'intermédiaires, invention en espérance fractionnaire, ME du BPC sur les T2, quantités fractionnaires affichées. ADR-0009 l'annonce ; prévoir une note de version.
- **Rig Thukker** modélisé par deux `StructureRig` : correct tant qu'un seul attribut spécial existe (2653) ; un futur rig à bonus par catégorie demanderait un `StructureRig` par catégorie.
- **Taxe null à 0 %** (décision e) : écart assumé à la règle « pas de `?? 0` », limité à une saisie utilisateur que l'UI ne permet pas encore.
- **Anomalie hors périmètre relevée** : `SearchStructuresCommand.php:24` nomme `AZBEL_TYPE_ID` le typeId 35825 (Raitaru).
