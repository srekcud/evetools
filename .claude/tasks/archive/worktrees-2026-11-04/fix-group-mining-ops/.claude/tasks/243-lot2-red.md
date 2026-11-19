# #243 lot 2 (B2) — phase rouge

- Rouges back : DeltaTest 20 et ImportLootSalesProcessorCorpProjectTest 22 (`findCorpProjectImports()` absente), UserPveSettingsDeclinedCorpProjectQuantitiesTest 5 (`addDeclinedCorpProjectQuantity()` ou propriété absente), ScanLootSalesResultResourceTest +2 (`characterId`, `accountedQuantity`, `rejectedContributions` absents).
- Rouges par le harnais partagé : toutes les classes `ScanLootSalesProcessor*` (91 erreurs sur 92), elles échouent sur `findCorpProjectImports()` tant que le vert ne l'a pas créée.
- Rouges front : usePveExpenseScanning.spec 5 (corps import/refus, `rejectedContributions`), lootSaleScanDialogCorpProjectErrors.spec 5 (textes des 3 clés du lot 2). Build front OK.
- À créer (signatures retenues par les tests, absentes de la spec) :
  - `CorpProjectContributionAccounting(incomeRepository: PveIncomeRepository, settingsRepository: UserPveSettingsRepository)` ;
  - paramètre nommé `contributionAccounting:` dans `CorpProjectContributionReader` et `ImportLootSalesProcessor` ;
  - `UserPveSettings::addDeclinedCorpProjectQuantity(string $projectId, int $characterId, int $quantity)`, `declinedCorpProjectQuantityOf(string, int): int` (0 si absent), propriété `$declinedCorpProjectQuantities` lue par réflexion ;
  - getters `PveIncome::getCorpProjectId()`, `getCorpProjectCharacterId()`, `getCorpProjectQuantity()`.
- Interprétations à valider : un refus de contribution rejeté compte dans `rejectedContributions` et le front appelle `onError` ; un élément `declined` de contribution porte `type: 'corp_project'`.
- Non couvert : « quantité d'import ≠ quantity du scan » (aucune règle de la spec ne permet au serveur de le détecter).
- Tests existants modifiés : CorpProjectsEsiSimulation (harnais : comptabilité, `corpProjectImports`, `settings`), ScanLootSalesProcessorTest (constructeur), ScanLootSalesProcessorCorpProjectsTest (14 identifiants avec le cumul), ScanLootSalesProcessorCorpProjectBoundariesTest (import P1 apparié au lieu de l'identifiant ancien importé, identifiant `_250`).
