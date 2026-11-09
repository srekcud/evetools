# #297 - phase verte

## Changements
- src/ApiResource/Input/Industry/StockpileImportInput.php : propriete $te retiree.
- frontend/src/stores/industry/stockpile.ts : parametre te retire de previewImport et importFromBlueprint, et des corps de requete.
- frontend/src/components/industry/StockpileImportPanel.vue : champ, etat et libelle TE retires ; grille a 2 colonnes.
- frontend/src/i18n/locales/{en,fr}.json : cle industry.stockpile.import.te retiree.
- docs/modules/industry.md : aucune mention de l'input te, inchange.

## Validation
- Unit complete : 14504 tests OK (notices preexistantes).
- phpunit --group issue-297 : 3 tests OK.
- phpstan sur StockpileImportInput.php : aucune erreur. deptrac : 0 violation.
- vitest run : 192 fichiers, 1410 tests OK. vue-tsc : OK. eslint : 0 erreur (1 warning non-null assertion preexistant, ligne 40 du panneau).
