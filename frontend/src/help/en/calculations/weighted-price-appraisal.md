# Weighted price and Appraisal

This page explains how EVE Tools reads a price from the Jita order book, and how the Appraisal values a pasted list. The **weighted price** is the average price you would actually pay (or receive) for a whole quantity, walking the order book one order at a time. It is also used for materials and sales in the industry analysis (see [profit and margin](/help?page=calculations/margin)).

Do not confuse it with the **adjusted price**: that is a reference price published by CCP, used only for job installation cost (see [job cost](/help?page=calculations/job-cost)). It never plays a part in the Appraisal.

## Where the figure appears

"Commerce" menu, "Appraisal" tab. Paste your list into "Paste your item list (from the game or in name + quantity format)", then "Analyze".

| Block | What it shows |
|---|---|
| "Sell Value" card ("Cost to buy these items") | Weighted total on the sell-order side; below it, "Best price:" at the cheapest order's price |
| "Buy Value" card ("What you receive selling") | Weighted total on the buy-order side; below it, "Best price:" at the highest buy order's price |
| "Total Volume" card | Sum of SDE volumes |
| "Unit Price" column (badges "Jita · lowest", "Jita buy · lowest") | Per line: weighted "Sell" and "Buy" in large type, best order price in small type, "~N/day" (average daily volume) |
| "Weighted Total" column (badges "Jita · weighted", "Jita buy · weighted") | Weighted price × quantity; in small type, best price × quantity; "…% covered" when the book is too short |
| "Structure Comparison" panel | "Jita + Transport", "(your structure) (weighted)", "Best Price (mixed)", "Structure Coverage"; columns "+ Import", "Weighted Sell", "Buy at" |

## The formula

### Weighted price

Orders are consumed from best to worst until the quantity is covered: sell orders from cheapest to most expensive for "Sell", buy orders from highest to lowest for "Buy".

weighted price = Σ (units taken from each order × order price) ÷ units covered
coverage = units covered ÷ requested quantity (at most 1)
weighted total = weighted price × requested quantity

- The quantity is the one on the pasted line. Two lines with the same name (case ignored) are added up before the calculation.
- The order book only keeps **the 20 best orders** on each side. If their volume does not cover the quantity, the weighted price is the price of the covered part, applied to the whole quantity: this is a **partial price**, marked "…% covered" (percentage rounded to one decimal, shown between 0.1 and 99.9).
- A line's unit price and total are rounded to 2 decimals; the total is computed before the unit price is rounded.

### Best price

best price = price of the first order in the book (cheapest sell or highest buy)

This is the small figure under each weighted price and the "Best price:" on the cards.

### Split

split = (Sell + Buy) ÷ 2, for weighted and best prices alike. It only appears on a shared appraisal ("Split Value" card).

### Comparison with a structure

The panel compares two ways of buying each line:

Jita + Transport = best Jita Sell price × quantity + line volume × "Transport cost / m3"
structure = cheapest sell order in the structure × quantity
Buy at = the cheaper of the two (Jita on a tie)
Best Price (mixed) = sum, line by line, of the cheaper of the two

- Transport cost defaults to 1,200 ISK/m3. It is not saved.
- The structure is the one picked in "Search a structure (min 3 chars)...", otherwise your preferred market structure, otherwise the first of your market structures, otherwise the application's default structure.
- "Structure Coverage" counts lines that have a price in the structure, not quantities.

### Percentile price (projects)

Projects do not use the weighted price for the BOM "Est. Cost" or for the value of a material contribution: they take the average of the cheapest 5% of the volume for sale in Jita (volume of the 20 best orders), the last order being partly taken. Below one unit of target volume, it is the cheapest order's price. See [T2 project](/help?page=playbooks/t2-project) and [group contributions](/help?page=calculations/group-contributions).

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Jita order book, common types (industry materials, ores and compressed ores, reprocessing outputs, PI products, manufacturable products) | ESI `/markets/10000002/orders/`, orders at the Jita 4-4 station | Synced every hour, kept 2 h at most |
| Jita order book, other types (ships, modules…) | Same endpoint, read on demand | Kept 5 min |
| Structure prices | Structure market (ESI), read with a character that has access | Every hour for the default structure and every market structure chosen by users; "Sync prices" reads it right away; kept 2 h |
| Unit volume | SDE | At each SDE import |
| Average daily volume ("~N/day") | ESI `/markets/10000002/history/`, average of the last 30 days | Kept 24 h |
| Transport cost | Your input | For this analysis |
| Shared appraisal | Copy of the result at the time of sharing | Frozen, expires after one week |

## Example

Example values, round, not taken from the game. You paste `400 Example`. Unit volume 0.1 m3.

Jita sell orders: 100 at 1,000 ISK, 200 at 1,100 ISK, 300 at 1,200 ISK.
Jita buy orders: 150 at 900 ISK, 100 at 850 ISK.

| Figure | Calculation | Result |
|---|---|---|
| Weighted Sell | (100 × 1,000 + 200 × 1,100 + 100 × 1,200) ÷ 400 | 1,100 ISK |
| "Sell Value" | 1,100 × 400 | 440,000 ISK |
| "Best price:" (Sell) | 1,000 × 400 | 400,000 ISK |
| Weighted Buy | (150 × 900 + 100 × 850) ÷ 250 | 880 ISK, "62.5% covered" |
| "Buy Value" | 880 × 400 | 352,000 ISK |
| "Best price:" (Buy) | 900 × 400 | 360,000 ISK |
| "Jita + Transport" | 1,000 × 400 + 40 m3 × 1,200 | 448,000 ISK |
| Structure (best order at 1,150 ISK) | 1,150 × 400 | 460,000 ISK |
| "Buy at" | 448,000 < 460,000 | Jita |

The buy book only covers 250 of the 400 units: the warning icon reads "Only 250 of 400 available (top 20 orders)". The 352,000 ISK assume the 150 missing units would also sell at 880 ISK, which is optimistic.

Note that "Jita + Transport" starts from the best price (400,000), not from the weighted total (440,000).

## When the figure is unknown

| Missing data | Effect | On screen |
|---|---|---|
| Name not recognised, or item not published | Line dropped | "N item(s) not found" and the list of names |
| No order in Jita on one side, or on-demand ESI read failed | No price on that side, line left out of that side's total | "-" in the cell |
| Price lookup failed | No prices at all | Message "Unable to fetch market prices. Please try again later.", cards at 0 |
| Volume missing from the SDE | Line volume, "Total Volume", "+ Import" and "Jita + Transport" unknown | "Unknown" and "Unknown volume in the SDE for: …" |
| Structure price (no order, structure never synced, no character with an ESI token) | No structure price for the line | "--"; the line goes to Jita if "Jita + Transport" is known, otherwise "-" |
| Sell or Buy missing | Split unknown (shared appraisal) | "-" |
| Daily volume not read | No hint | "~N/day" absent |

## Pitfalls

- **Totals ignore lines without a price.** "Sell Value", "Buy Value", the structure column and "Best Price (mixed)" add up only the lines that have a price, without saying so. Check that no line shows "-" before trusting a total.
- **A line without a Sell price also hides its Buy price.** If the sell side is missing, the whole cell shows "-", even when a buy price exists.
- **The structure column is not weighted.** "(weighted)", "Weighted Sell" and the tooltip "Weighted sell price based on available sell orders" say so, but the calculation takes the cheapest sell order in the structure × quantity, regardless of the volume available.
- **"Jita + Transport" and "Buy at" start from the best Jita price**, not from the weighted price in the next column. For a large quantity, the comparison favours Jita.
- **"item(s) with low coverage"**, under the structure column, counts lines whose **Jita sell** book covers less than half the quantity.
- **The "…% covered" tooltip on the Buy side is the sell-side one**: it talks about a cost that is likely higher. On the buy side, it is the revenue that is likely lower.
- **A limited order book.** Only the 20 best orders on each side are kept, read from the first page of ESI results. 100% coverage means "these orders are enough", not "the market is deep enough".
- **Station or region.** Orders at the Jita 4-4 station come first. If there are none on one side, orders from the whole The Forge region are used, with no mention on screen.
- **The format is not read.** The "Format:" choice is not sent to the analysis, and "Multibuy detected" is shown whatever format you pasted in "Auto-detect" mode.
- **A shared appraisal is a snapshot.** The link ("Share", copied automatically) opens a page anyone can read without an account, with the prices at the time of sharing, never refreshed. It shows the Split column but not the structure comparison. After one week, the page shows "This shared appraisal does not exist or has expired."; expired appraisals are deleted once a day.

## Technical note

Appraisal: `AppraiseProcessor::process()` (`POST /api/shopping-list/appraise`). Prices: `JitaMarketService::getPricesWithFallback()`, `getBuyPricesWithFallback()`, `getWeightedSellPricesWithFallback()`, `getWeightedBuyPricesWithFallback()`, computed by `calculateWeightedPrice()`; order book by `collectOrderBooks()` (constant `MAX_ORDERS_PER_TYPE` = 20); cache `CACHE_TTL` = 7200 s, `ON_DEMAND_CACHE_TTL` = 300 s, `VOLUME_CACHE_TTL` = 86400 s, `VOLUME_HISTORY_DAYS` = 30. Percentile: `getCheapestPercentilePrices()` (`CHEAPEST_PERCENTILE` = 0.05). Displayed coverage: `PartialPricePresenter::coveragePercentOfCoverage()`. Structure comparison: `ParseProcessor::process()` (`POST /api/shopping-list/parse`), `MarketService::comparePrices()`, `StructureMarketService::getLowestSellPrices()`. Sharing: `ShareListProcessor`, `SharedShoppingList` (expiry `+1 week`), purge `PurgeExpiredSharedListsHandler` scheduled daily in `SyncScheduler`.
