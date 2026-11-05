# #348 phase rouge : notes pour l'implémentation

## Squelettes créés (src/, sans logique)
- `src/Service/Industry/SellMarkets/{SellMarketComparison,SellMarketComparisonResult,SellMarketRow,ImmediateSale}.php` : `compare()` renvoie `markets` vide et `ImmediateSale(null, null, null)`. Constantes `SELL_SHARE_OF_DAILY_VOLUME` (0.1) et `JITA_MARKET_NAME` déjà posées.
- `src/ApiResource/Industry/SellMarketResource.php` (route et sécurité complètes, champs aux valeurs neutres) ; `src/State/Provider/Industry/SellMarketProvider.php` (constructeur `Security, RequestStack, SellMarketComparison, TypeNameResolver, ClockInterface`, `provide()` renvoie une ressource vide, `MAXIMUM_QUANTITY` posée).
- `frontend/src/stores/industry/sellMarkets.ts` (`useSellMarketsStore`, `fetchSellMarkets` vide), `frontend/src/components/industry/SellMarketsCard.vue` (props seules, template vide), types `SellMarketResponse`, `SellMarketLine`, `SellMarketImmediateSale` dans `types.ts`.

## Choix qui contraignent l'implémentation
- Le provider passe `$clock->now()` (DateTimeImmutable) comme `today` ; le test ne vérifie que la date `Y-m-d`.
- Provider : `quantity` lu dans la query ; entier strict (`'2.5'` et `'fifty'` refusés), 1 à 100 000 000 ; 401 (`UnauthorizedHttpException`) sans User ; nom du type via `TypeNameResolver::findName`.
- `typeName` null et `immediateSale` : API Platform omet les clés `null` de premier niveau (`skip_null_values`) ; les tableaux imbriqués (`markets[]`, `immediateSale`) gardent leurs `null`. Le test fonctionnel s'appuie sur ça pour `immediateSale`.
- Fonctionnel : utilisateur sans standings synchronisés, donc résolveur de frais à `null` (feeRate et netUnitPrice `null`), volume Jita `null` (ESI mocké en 503), carnet d'achat vide par `seedNoJitaSellOrderFor`. Pas de test 401 anonyme (aucun précédent dans `tests/Functional`).
- Aucune vérification de câblage R11 (montage de la carte dans `ProfitMarginTab`) : hors des 10 tests demandés, à ajouter dans `ProfitMarginTab` si on veut le figer.

## Contrat front des tests (sellMarketsCard.spec.ts)
Les clés i18n ne sont pas créées dans `fr.json`/`en.json`. Le spec fusionne ses propres messages `industry.sellMarkets.*` dans `en.json` : l'implémentation doit utiliser exactement ces clés (et les ajouter aux deux fichiers de langue ; si les textes diffèrent, ceux du spec l'emportent dans les tests) :
`title, quantity, price, volume, fees, net, daysToSellAll, days ('{days} d'), capitalEstimate, immediateSale, averagePrice, unknown.noSellOrder, unknown.noBuyOrder, unknown.noVolume`. Seules `days`, `capitalEstimate` et les trois `unknown.*` sont assertées.

Sélecteurs :
- ligne : `[data-testid="sell-market-row-jita|capital"]`, cellules `[data-testid="sell-market-price|volume|net|days"]` dans la ligne.
- encart : `[data-testid="immediate-sale"]` avec `immediate-sale-price` et `immediate-sale-net`.
- saisie : `[data-testid="sell-markets-quantity"]`, rechargement sur l'événement `change` (pas de debounce) et sur changement de la prop `quantity`.
- appel : `apiRequest('/industry/sell-markets/{typeId}?quantity={n}')` (un seul argument).

Données inconnues (violet pointillé) : élément `[data-testid="unknown-value"]` aux classes `border-dashed border-violet-400 text-violet-300`, de texte la cause (`industry.sellMarkets.unknown.*`), jamais de chiffre dans la cellule. Prix inconnu : `noSellOrder` ; volume et jours de la capitale : `noVolume` (dans les deux cellules) ; vente immédiate sans carnet d'achat : `noBuyOrder` dans prix et net.
Montants : `toLocaleString('en-US', {maximumFractionDigits: 0}) + ' ISK'` (soit `formatIskFull`). Jours : `{days} d` avec une décimale max (12.5, 30). Ambre : classe `text-amber-400` sur la cellule jours quand `daysToSell > 14` (14 exactement : pas d'ambre). Prix partiel : `PartialPriceMarker` (`industry.partialPrice.marker`) dans la cellule du prix Jita et dans `immediate-sale-price`. Erreur API : le message de l'erreur affiché tel quel, sans lignes ni encart.
Volume capitale : texte `capitalEstimate` dans la cellule volume de la capitale uniquement.

## Tests existants touchés
Aucun (recherches `grep` sur `sell-markets`, `SellMarket`, `Currently.*Issue348` : rien d'existant).
