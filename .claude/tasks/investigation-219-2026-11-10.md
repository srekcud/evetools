# Investigation #219 : surtaxe SCC sur le coût d'installation d'une copie (2026-11-10)

## Conclusion
- **Question tranchée.** Le relevé en jeu de Sylvain (commentaire du 2026-11-05, https://github.com/srekcud/evetools/issues/219#issuecomment-6659471221) montre une ligne « SCC surcharge 4,00 % JCB » sur un job de copie. D11a est levée : la SCC de 4 % s'applique à la copie. Recalcul vérifié : JCB 2 % × 2 694 500 = 53 890 ; taxe 1 % = 539 ; SCC 4 % = 2 156 ; index 7,43 % = 4 005 ; × 0,748 × 0,95 = 2 846 ; total 2 846 + 539 + 2 156 = 5 541.
- **Source primaire CCP** : la baisse de 4 % à 2 % du 2025-07-18 ne vise que la recherche ME/TE, « temporary » (https://www.eveonline.com/news/view/exploration-and-industry-balance-rework). Elle ne mentionne ni copie, ni invention, ni fabrication : cohérent avec le relevé.
- **Non résolue sur origin/main** : `markTestIncomplete` toujours présent (`tests/Unit/Industry/Domain/InstallCostTest.php:124`), commentaire de spec et glossaire disent encore « en attente » (`docs/specs/industry-engine.md:145,218,305,309,342,389`, `docs/glossary.md:92,95`, `docs/adr/0009-noyau-calcul-industrie.md:44`, `InstallCost.php:15`, `tests/Fixtures/Golden/install-cost-vectors.json` clé `openQuestion`). Les valeurs du cas de référence sont 20 000 (système), 40 000 (SCC), 60 000 (total) ; le corps de l'issue dit « SCC 20 000 », c'est une erreur (le commentaire du 2026-11-05 a raison : 40 000).
- **Fait nouveau** : l'impact écrit dans l'issue (« copie surestimée de 4 % ») est inversé pour le chemin historique, qui n'applique **aucune** SCC (voir tableau). Le chemin du noyau est correct.

## Services de coût d'installation (lecture du code)
| Activité / service | Fichier:ligne | Formule réelle | SCC |
|---|---|---|---|
| Noyau, fabrication et réaction | `src/Industry/Domain/InstallCost.php:38-52,76+` | base = EIV ; système = base × index × modif. structure ; taxe = base × taux ; SCC = base × 0,04 ; total = somme (+ alpha 0,25 %) | **4 % oui** |
| Noyau, copie et invention | idem `:48-52` | base = 0,02 × EIV (JCB), même suite | **4 % oui** (`SCC_SURCHARGE_RATE:16`) |
| Noyau, recherche ME/TE | `InstallCost.php:58-73` | base = 0,02 × EIV × multiplicateur de niveau ; même suite | **2 % oui** (`RESEARCH_SCC_SURCHARGE_RATE:18`) ; `forResearch` n'a aucun appelant dans `src/` (spec #475 pas câblée) |
| `ProductionCostCalculator::installCost` (copie et invention du plan, EIV = matériaux du T1 pour la copie, runs = `COPY_RUNS_PER_ATTEMPT` = 1) | `src/Industry/Application/ProductionCostCalculator.php:186-199,243-248` | `forJob` avec `structure->costRoleBonus` seul | 4 % oui. Utilisé par `ProfitMarginService`, `BuyVsBuildService` |
| `StructureTotalCostComparator::installCost` (fabrication, réaction) | `src/Industry/Application/StructureTotalCostComparator.php:118` | `forJob` avec `costRoleBonus` | 4 % oui |
| `JobInstallCostEstimator::estimate` (fabrication, réaction, invention) | `src/Service/Industry/JobInstallCostEstimator.php:63` | `forJob` avec `Multiplier::one()` | 4 % oui. Appelé par `EsiCostIndexService::estimateJobInstallCost:197-207`, `PivotAdvisorService:176,443`, `BatchProfitScannerService:347`, `InventionService:281` |
| **`InventionService::estimateCopyJobCost`** (copie, chemin historique) | `src/Service/Industry/InventionService.php:62-81` | `0,02 × EIV × runs × index × (1 + taxe %)`, EIV = adjusted price du **produit** × quantité (`calculateBlueprintEiv:360-380`, pas des matériaux) | **non** (SCC absente, taxe multiplicative, EIV sur le mauvais objet) |

Le chemin historique de copie est vivant : `calculateInventionCost:278` ← `expectedCostPerRun:340` ← `PivotAdvisorService:504`, `BatchProfitScannerService:390`. Il sous-estime donc la copie (pas de SCC, taxe qui multiplie l'index au lieu de s'ajouter) et, par `costPerAttempt:292`, le coût d'invention de ces deux écrans. L'invention elle-même (`:281`) passe par le noyau et paie bien la SCC.

## Écart connu du relevé : bonus de rig sur le coût
- Le relevé donne un rig −25,2 % sur le terme index, multiplié par le rôle −5 %. evetools lit seulement les rigs de **matériaux** et de **temps** (`SdeRigBonusReader.php:17-22`, `StructureRig.php`, `ConfiguredProductionStructures.php:95-120`) et ne passe que `costRoleBonus` (`ProductionCostCalculator.php:247`) ou `Multiplier::one()` (`JobInstallCostEstimator.php:63`). Aucune lecture de rig de coût dans `src/`.
- Sur le relevé : 3 805 calculé au lieu de 2 846, total 6 500 au lieu de 5 541 (+17 %). Le commentaire de Sylvain propose une issue séparée : je ne l'ai pas trouvée dans les issues ouvertes (recherche gh sur « rig cost bonus install cost copy » : seule #219).
- Je n'ai pas lu l'attribut dogma du rig de coût (SDE non consulté ici) : **à vérifier** quel attribut porte −25,2 % avant de coder.

## À vérifier en jeu (ce que les sources ne tranchent pas)
1. **Multiplicateur de runs de la copie.** Relevé : « 1 run, 600 runs par copie ». Le noyau multiplie l'EIV par `COPY_RUNS_PER_ATTEMPT` = 1 ; on ne sait pas si CCP facture par run de copie, par nombre de copies ou par runs par copie. Protocole : même BPO, même structure, 3 jobs (1 copie × 1 run ; 1 copie × 10 runs ; 2 copies × 1 run) ; lire pour chacun la ligne « Estimated Item Value » et « Job cost base » dans la fenêtre du job (avant lancement) ; attendu si facturé par run × copies : JCB ×10 puis ×2 ; sinon JCB constante.
2. **Ligne du journal wallet** : lancer le job, lire dans le journal wallet la ligne « Industry job installation » (montant = total job cost 5 541 sur le relevé) pour confirmer que le total affiché est bien débité.
3. Source de l'EIV de copie : comparer l'EIV affiché avec Σ(adjusted price × quantité des matériaux de fabrication du BPO, ME 0) pour le même BPO ; si égal, la formule du noyau est bonne et le chemin historique (adjusted price du produit) est faux.

## Options de correctif
**A (recommandée, circuit s, un lot).** Lever D11a et aligner le chemin historique :
- retirer `markTestIncomplete` (`InstallCostTest.php:124`) et le saut `'copying' ===` du fournisseur de vecteurs (`:67`) ; retirer « en attente » de `InstallCost.php:15`, `industry-engine.md`, `glossaire.md`, ADR-0009, `install-cost-vectors.json` ; corriger « SCC 20 000 » (corps de l'issue : commentaire seulement) ;
- faire passer `InventionService::estimateCopyJobCost` par `InstallCost::forJob` (EIV des matériaux du T1, `Multiplier::one()`), comme `JobInstallCostEstimator`. Montants affichés de Pivot Advisor et Batch Scanner : hausse du coût de copie, donc **changement de chiffre affiché** à signaler à Sylvain avant fusion.
- Fichiers : `InventionService.php`, `InstallCostTest.php`, `InventionServiceTest.php`, golden JSON, 4 docs.
**B (circuit m, séparé).** Rig de coût : lire l'attribut dogma de rig de coût, l'ajouter à `StructureRig`/`ProductionStructure`, le multiplier au terme index dans `ProductionCostCalculator`, `StructureTotalCostComparator`, `JobInstallCostEstimator` ; décision produit (changement de montants, +17 % sur le relevé). Ne pas grouper avec A.

## Tests qui prouvent le correctif
- A : `testCopyingInstallCostWithSccSurcharge` actif (SCC 40 000, total 60 000) ; vecteur « relevé en jeu » : EIV 2 694 500, index 0,0743, modif. 0,95, taxe 1 %, JCB 53 890 → SCC 2 156, taxe 539 ; `InventionServiceTest` : coût de copie = `forJob` (SCC présente, taxe additive, EIV matériaux) pour un T1 connu.
- B : vecteur 4 005 × 0,748 × 0,95 = 2 846, total 5 541 avec rig −25,2 % et rôle −5 % ; test que la taxe et la SCC ne bougent pas avec le rig.

## Sources
- CCP, 2025-07-18 : https://www.eveonline.com/news/view/exploration-and-industry-balance-rework (recherche seulement, temporaire)
- Relevé en jeu : commentaire d'issue ci-dessus (source primaire, Sylvain)
- Non primaire, concordant : https://github.com/beulea/eve-industry-releases/wiki/How-the-Math-Works (invention et copie sur base 2 % ; SCC 4 %)
