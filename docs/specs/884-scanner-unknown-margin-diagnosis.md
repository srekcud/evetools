# #884 — Profit scanner: say why a margin is unknown

Statut : validée en autonomie (mode nuit). Circuit `circuit:m`. Niveau de technicité 3 (code Industry) : PR, jamais de fusion par le thread.

## Cause racine (établie dans l'issue, commentaire du 2026-11-14)

Sur C-J6MT (lieu de vente Structure), ~620 produits ont un prix de vente, un volume journalier suffisant et aucune marge sous le minimum, mais le scanner n'en renvoie aucun : leur marge est inconnue (`marginPercent === null`), et une marge inconnue ne satisfait jamais une marge minimale (comportement voulu, inconnu et non zéro).

- `missing_facility_tax` (120 lignes sur 126, catégorie drones) : `FacilityTaxResolver::structureFor` choisit la structure de production configurée du compte dans le système de fabrication, et cette `IndustryStructureConfig` n'a pas de `facilityTaxPercent` : `JobInstallCostEstimator` ne renvoie aucun coût.
- Le diagnostic de #908 (`diagnoseBatchScan`) ne compte pas les marges inconnues : aucune ligne.
- `BatchScanResultResource` garde `skip_null_values` : un champ nul est absent du JSON, `=== null` est faux, et les lignes sans prix de vente ne sont pas comptées.
- `SellerSalesFeesResolver::forUser` peut renvoyer null (standings non synchronisés, compétence Accounting ou Broker Relations absente) : `feeRate` nul, marge nulle, `unknownReason` nul aussi, donc invisible de bout en bout.

## Règles

1. `BatchScanResultResource` sérialise les champs nuls (`normalizationContext: ['skip_null_values' => false]`, comme `WalletResource`). Toute clé de la ressource est présente dans la réponse de `/industry/profit-scan`.
2. Nouvelle raison `missing_sales_fees` (constante `BatchProfitScannerService::UNKNOWN_REASON_MISSING_SALES_FEES`) : renvoyée comme `unknownReason` quand le prix de vente et le coût sont connus, que le lieu n'est pas `contracts` et que `SalesFees` est null. Elle passe avant `missing_daily_volume` et après les raisons de coût.
3. Nouveau champ `unknownReasonStructureId` (`?string`) sur la ligne et sur `BatchScanResultResource` : renseigné avec `ProductionStructure::$id` de la structure utilisée par la ligne (réaction : structure de réaction ; sinon structure de fabrication) quand `unknownReason === 'missing_facility_tax'`, null sinon. Aucun changement du domaine.
4. `BatchScanItem` (front) reçoit `unknownReasonStructureId: string | null`.
5. `diagnoseBatchScan` compte en plus, parmi les lignes dont `marginPercent` est nul, les lignes par `unknownReason` pour les raisons que les lignes existantes ne couvrent pas : `missing_cost_index`, `missing_adjusted_price`, `missing_facility_tax`, `missing_sales_fees`, `missing_invention_probability`, `missing_market_price`, `missing_volume`. Les raisons `missing_sale_price`, `missing_material_price` et `missing_daily_volume` restent portées par les lignes de #908 (aucun double comptage). Une raison inconnue du front est comptée sous « autre raison » (ligne générique), jamais ignorée.
6. Pour `missing_facility_tax`, le diagnostic regroupe par `unknownReasonStructureId` et expose un nombre par structure.
7. L'état vide du scanner affiche une ligne par raison comptée (> 0). Pour la taxe d'installation : une ligne par structure, avec son nom (lu dans le store des structures configurées, id comparé à `unknownReasonStructureId` ; nom absent du store : libellé générique sans nom) et un lien vers les réglages Industrie (`name: 'settings', params: { section: 'industry' }`). Textes i18n FR et EN, ton « HUD » factuel, aucune nouvelle couleur.
8. Les comparaisons de `diagnoseBatchScan` restent `=== null` : la règle 1 garantit la forme (le test front alimente la forme réelle de la réponse, clés nulles présentes).

## Exemples chiffrés

| Entrée (sans minimums) | Lignes du diagnostic |
|---|---|
| 126 produits, 120 `missing_facility_tax` sur la structure S1 (« Raitaru Home »), 3 `missing_sale_price`, 3 `missing_adjusted_price` | scanned 126 ; 3 sans prix de vente ; 3 `missing_adjusted_price` ; 120 taxe d'installation « Raitaru Home » + lien réglages |
| 10 produits, tous `missing_sales_fees` | scanned 10 ; 10 frais de vente inconnus (standings ou compétences à synchroniser) |
| 4 produits `missing_facility_tax`, 2 sur S1 et 2 sur S2 | une ligne par structure : 2 et 2 |
| 1 produit `unknownReason: 'missing_xyz'` | 1 « autre raison » |
| structure S9 absente du store | ligne taxe d'installation sans nom de structure, lien présent |

## Cas limites

- Lieu `contracts` : frais 0, pas de `missing_sales_fees`.
- Prix de vente absent : `missing_sale_price` reste prioritaire (ordre existant).
- Produit T2 à coût d'invention inconnu avec `missing_facility_tax` : `unknownReasonStructureId` = structure de fabrication.
- Structure null (station PNJ) : la taxe n'est jamais inconnue, le champ reste null.
- `diagnoseBatchScan` sans minimums : inchangé (`diagnoseEmptyScan` ne relit pas la réponse sans minimums).

## Tests existants touchés

- `tests/Unit/State/Provider/Industry/BatchScanProviderTest.php` : le mapping gagne le champ.
- `frontend/tests/utils/batchScanDiagnosis.spec.ts`, `frontend/tests/components/industry/batchScanTabEmptyDiagnosis.spec.ts` : lignes de test ajoutées ; les assertions existantes restent.

## Hors périmètre

- Saisir la taxe de la structure à la place du joueur (aucune valeur par défaut inventée).
- Retirer ou renommer un champ, un réglage, une route ou un écran existant.
- Les autres vues qui lisent `unknownReason` (projets, pivot).

## Décisions (mode nuit)

- Le nom de la structure est lu côté front par l'id : pas de changement du domaine ni de requête de plus côté scanner.
- Sérialiser les nulls plutôt que `== null` : la forme de la réponse devient prévisible pour tous les consommateurs.

## Actions prod

Aucune (pas de migration ni de commande console).
