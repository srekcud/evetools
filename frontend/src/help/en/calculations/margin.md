# Profit and margin

This page explains how EVE Tools computes the profit and margin of a production run, from the estimate in the analysis to the real figure of a sold project. There is a single definition of margin: **profit divided by cost**, not by revenue. Job install cost is covered in [job install cost](/help?page=calculations/job-cost), sale fees in [sale fees](/help?page=calculations/market-fees), weighted prices in [weighted price](/help?page=calculations/weighted-price-appraisal).

## Where the figure appears

| Screen | What is shown | Kind |
|---|---|---|
| "Industry" menu, "Analysis" tab, "Margins" sub-tab | Cards "Total Cost", "Best Sell Price", "Profit", "Margin"; the "Sell Price Comparison" table ("Revenue", "Fees", "Profit", "Margin" columns); the "Best Margin" column of a T2's invention panel | Estimate |
| "Batch Scan" sub-tab | "Margin %" and "Profit/Unit" columns, "Profitable" and "Best Margin" cards | Estimate, one run |
| "Buy vs Build" sub-tab | "Total Production Cost", "Sell Price" and "Margin" cards | Estimate |
| "Pivot" sub-tab | Margin of each suggested product | Estimate, same formula as "Batch Scan" |
| A project, "Finances" tab | "Financial Summary": "Profit" (or "Provisional profit") and "Margin:"; the "Provisional margin" box with "Margin projected at the last sale price" | Real, on recorded sales |
| "Balance" menu, "Industry" tab | "Profit over the period", "Profit", "Margin" and "Capital engaged" columns by month | Real, completed projects |
| "Balance" menu, "Overview" tab, and the "Home" page, "Balance of the last 30 days" card, "Industry" box | Industry profit of the period | Real, completed projects |

## The formula

For one sale:

revenue = unit price × units sold
fees = revenue × fee rate of the sell venue
profit = revenue − fees − cost
margin = profit ÷ cost × 100

- The margin is only computed when the cost is strictly positive. Otherwise it is unknown.
- A negative margin is a loss. It has no floor.
- Screens show the margin with one decimal ("+22.2%").

### Revenue

Revenue is the unit price times the **units produced** (runs × units per run), not times the number of runs. The price depends on the sell venue:

| "Sell Price Comparison" row | Unit price | Fee rate |
|---|---|---|
| "Jita", tag "sell" | Weighted price of the Jita sell orders over the produced quantity | broker fee + sales tax |
| Your market structure, tag "undercut" | Cheapest sell order in the structure | broker fee + sales tax |
| Your market structure, tag "instant sell" | Highest buy order in the structure | sales tax only |
| "The Forge", tag "contract" | Cheapest single-item public contract | none |

- The market structure is your preferred market structure, else the first of your market structures, else the Jita station.
- A row without a price (or with a zero price) is not shown.
- The row marked "Best" has the highest margin. The "Best Sell Price", "Profit" and "Margin" cards repeat that row; the venue is named under the "Margin" card.

### Production cost

In "Margins", the production cost ("Total Production Cost") is:

cost = bought materials + job install + invention (T2 only)

- **Materials**: Jita weighted price of the materials to buy, after ME and structure bonuses. Components you build are counted pro rata of the runs needed (marginal cost), not rounded up to whole runs.
- **Install**: sum of the install costs of the manufacturing and reaction jobs, see [job install cost](/help?page=calculations/job-cost).
- **Invention**: for a T2, cost of one attempt × expected attempts. One attempt = datacores + decryptor (if any) + invention install + install of the 1-run T1 copy. Expected attempts = wanted T2 runs ÷ (success probability × runs per invented BPC). The result is not rounded to whole attempts.
- The T1 BPO itself, transport and any BPC price are not counted in "Margins".

### In a project

Same calculation, on real figures:

- **revenue** = sum of the recorded sales (market and contracts);
- **fees** = the project's (broker fee % + sales tax %) applied to market revenue, plus contract fees read from the seller's wallet journal;
- **cost** = "Total Project Cost", the sum of the values of the approved contributions (see [group contributions](/help?page=calculations/group-contributions));
- **margin** = (net revenue − cost) ÷ cost × 100.

### Provisional margin and projected margin

While some final products are unsold, the profit is labelled "Provisional profit" and the "Provisional margin" box lists the "Unsold:" products with the unit price of their latest sale.

- The **displayed margin** ("Margin:") only counts the sales already recorded. It is low, often negative, early in the sales.
- The **margin projected at the last sale price** adds the unsold products: unsold quantity × unit price of the latest sale of the same product, less the project's broker fee and sales tax.

projected margin = (current net revenue + unsold × last price × (1 − (broker % + tax %) ÷ 100) − cost) ÷ cost × 100

### In the Balance

The "Profit" of a completed project is **your share**: your approved contributions × the project margin (the whole profit for a solo project). The "Margin" of a month = sum of the profits of the projects with a known profit ÷ sum of the "Capital engaged" in those same projects × 100.

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Prices of materials, datacores, decryptors | Jita sell order book (ESI), weighted over the quantity | Synced every hour; a missing type is read on demand |
| "Jita" sale price | Same book, weighted over the produced quantity | Every hour |
| Structure prices ("undercut", "instant sell") | Your structure's market (ESI) | Every hour |
| Contract price | The Forge public contracts (ESI) | Every 30 min |
| Install cost | See [job install cost](/help?page=calculations/job-cost) | Cost index 2 h, adjusted prices 24 h |
| Broker fee, sales tax | Skills and standings of the main character, see [sale fees](/help?page=calculations/market-fees) | On each "Sync all" |
| Invention probability | SDE and the skills of the chosen "Inventor" | SDE import, skills sync |
| Recipes, units per run | SDE | On each SDE import |
| Project sales and contributions | Your input in the project; contract fees read from the seller's wallet journal | On input; journal read with the wallet sync |

## Example

Example values, round, not taken from the game.

**Analysis.** 10 runs of a product that yields 1 unit per run: 10 units. Production cost 100,000,000 ISK (90 M materials, 10 M install). Broker fee 2%, sales tax 4%.

| Row | Unit price | Revenue | Fees | Profit | Margin |
|---|---|---|---|---|---|
| Jita, sell | 13,000,000 | 130,000,000 | 7,800,000 (6%) | 22,200,000 | +22.2% |
| Structure, instant sell | 12,000,000 | 120,000,000 | 4,800,000 (4%) | 15,200,000 | +15.2% |
| The Forge, contract | 12,500,000 | 125,000,000 | 0 | 25,000,000 | +25.0% |

The contract row is "Best". Measured against revenue, the Jita profit would give 17.1%: that is not what the app computes.

**Project.** Same 10 units, project cost 100,000,000 ISK. 6 units sold on the market at 13,000,000 ISK:

- revenue 78,000,000, fees 6% = 4,680,000, net revenue 73,320,000;
- "Provisional profit" = −26,680,000, "Margin:" −26.7%;
- projected margin: 4 unsold × 13,000,000 × 0.94 = 48,880,000, projected net revenue 122,200,000, that is **+22.2%**.

## When the figure is unknown

Missing data makes the result **unknown**, never 0.

| Missing data | Effect | On screen |
|---|---|---|
| Jita price of a material | Cost, profit and margins unknown | "Margins": cards show "Unknown", the table is replaced by "A material has no Jita price: the manufacturing cost and margins are unknown." and "Materials without a price: …" |
| Cost index, adjusted price or facility tax | Install cost, hence cost and margins, unknown | The cause, see [job install cost](/help?page=calculations/job-cost) |
| Invention probability, inventor skills, price of a datacore or decryptor | Invention, hence cost and margins, unknown | The cause (for example "Skills of the selected character are unknown: …") |
| Sale fees | Fees, profit and margin unknown, except the contract row | "Unknown fees"; "Fees" and "Profit" columns show "—", "Margin" shows "Unknown" |
| Sale price at one venue | That venue's row disappears | No row at all: "No market data available" |
| Sale price at the chosen venue ("Batch Scan") | Margin unknown | "Unknown", cause in the tooltip ("The product has no Jita sale price: …") |
| Project without a sale | Net revenue, profit, margin unknown | "Awaiting sales" |
| Value of an approved contribution | Cost, profit, margin unknown | "An approved contribution has no known price: …" |
| Project fees (market sale) or a contract's fees | Net revenue, profit, margin unknown | "Fees unknown: the project owner has no synced skills and standings, …" |
| Latest price of an unsold product | Projected margin unknown | "never sold" next to the product, projected margin "—" |
| Profit of a completed project (Balance) | Project left out of the sums, counted apart | "of which 1 with unknown profit, not counted" |

A **partial** Jita price (order book too short for the whole quantity) is not unknown: it is shown with "…% covered". The average price of the covered part is applied to all units: for a material, the real cost is likely higher.

## Pitfalls

- **The note under "Sell Price Comparison" is wrong on two points.** It says "Revenue = unit price x runs" and "Margin = profit / revenue". The calculation multiplies by units produced and divides by cost. It also says fees include the broker fee: the "instant sell" row only pays the sales tax.
- **"Best Sell Price" shows a revenue.** The large figure is the total revenue of the row with the best margin; the unit price is under "Per unit". It is not necessarily the highest price.
- **"undercut" does not undercut.** The price is the cheapest sell order in the structure, with nothing taken off.
- **"Margins", "Batch Scan" and "Buy vs Build" do not count the same cost.** "Batch Scan" counts one run, buys every direct material instead of building components, adds transport ("Import", and "Export" for a Jita sale) and your BPC price if set, and assumes no decryptor for a T2. "Buy vs Build" picks, for each component, the cheaper of buying and building, in whole runs, and sells at your structure's price if there is one, else at the Jita weighted price, always with broker fee and sales tax. "Margins" counts neither transport nor BPC price.
- **"Sell Price" in "Buy vs Build"** carries a system name in brackets that does not follow the chosen structure, nor change when the price comes from Jita.
- **Analysis and project do not compare the same thing.** The analysis estimates a market cost; the project adds up what was contributed and approved. A material never declared as a contribution is not in the project cost, and the margin looks better.
- **The projected margin always applies broker fee and sales tax.** Even when the latest sale of a product was a contract, with no market fees.
- **Home and Balance do not show projects with an unknown profit the same way.** Both leave completed projects with an unknown profit out of the sum. The Balance says so ("of which N with unknown profit, not counted") and shows "—" when no project has a known profit. The "Industry" box on the Home page does not say so, and shows 0 when every project of the period is unknown.

## Technical note

Analysis: `ProfitMarginService::marginForPrice()` (`src/Service/Industry/ProfitMarginService.php`), cost from `ProductionCostCalculator::costOf()` in `CostMode::MarginalCost`. "Batch Scan": `BatchProfitScannerService::scan()`. "Buy vs Build": `BuyVsBuildService::marginPercentOf()`. "Pivot": `PivotAdvisorService`. Project: `ProjectDistributionService::calculateDistribution()` and `projectedMarginPercent()`, unsold products `UnsoldProductsOfProject`. Balance: `CompletedProjectFiguresLoader`, `IndustryRevenueAggregator`, `LedgerDashboardProvider`.
