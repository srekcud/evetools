# Sale fees: broker fee and sales tax

This page explains how EVE Tools computes the fees of a market sale from the skills and standings of your main character, and why they can be unknown. The margin built on them is covered in [margin](/help?page=calculations/margin). To sync skills and standings: [getting started](/help?page=playbooks/getting-started), step 5.

## Where the figure appears

The sale fees are the sum of two rates: the **broker fee** (charged on a sell order) and the **sales tax** (charged on every sale).

- **"Industry" menu, "Analysis" tab, "Margins" sub-tab.** The header of the "Sell Price Comparison" table shows "Broker fee" followed by the rate, and the "Fees" column gives the amount per venue. The "Where to sell" card gives the "Fees", the "Net / unit" of each market and the "Net" of the "Immediate sale · Jita buy orders".
- **"Batch Scan", "Buy vs Build" and "Pivot" sub-tabs.** The margin of each row is net of the fees.
- **"Workshop" tab, "What to produce" card.** The weekly profit is net of the seller fees.
- **A project, "Finances" tab.** The "Financial Summary" shows "Broker Fee (x%)" and "Sales Tax (x%)". These two rates belong to the project, copied when it was created (see below).

## Who the seller is

The seller is always the **main character** of your account, the one marked with the star on the Characters page. Its skills and standings apply to all your screens, even when you sell with another character. Changing your main character changes the fees on the next display.

## The formula

sales tax = 7.5% × (1 − 0.11 × Accounting)

broker fee = 3% − 0.3% × Broker Relations − 0.03% × faction standing − 0.02% × corporation standing

- **Accounting** and **Broker Relations**: levels (0 to 5) of these two skills on the main character.
- **Faction standing** and **corporation standing**: the main character's standings towards the NPC faction and corporation of the Jita 4-4 station, from −10 to 10. The calculation always uses the Jita ones, whatever market you sell on.
- Standings are the raw values read from ESI, without the effect of the Connections or Diplomacy skills.
- A standing missing from the list read from ESI counts as neutral (0).
- No floor: a negative standing raises the broker fee. With Broker Relations 5 and standings at 10, the broker fee goes down to 1%.

The rate applied depends on how you sell:

| How you sell | Rate applied |
|---|---|
| Sell order (Jita or structure, tags "sell" and "undercut") | broker fee + sales tax |
| Sale to an existing buy order ("instant sell", "Immediate sale") | sales tax only |
| Contract ("contract") | no fee |

fees = revenue × rate applied, with revenue = unit price × quantity.

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Accounting and Broker Relations levels | ESI `GET /characters/{id}/skills/` (scope `esi-skills.read_skills.v1`) | Read only when you click "Sync all", never scheduled |
| Standings | ESI `GET /characters/{id}/standings/` (scope `esi-characters.read_standings.v1`) | Read by the same button, never scheduled |
| Main character | Your choice, Characters page | Immediate |
| Rates of a project | Copied from your fees at creation, then editable by hand | Frozen: never recomputed |

The analysis screens recompute the fees on every display, from the last synced values. Only projects keep their original rates.

## Example

Example values, rounded, not taken from the game.

A main character with Accounting 4, Broker Relations 4, a faction standing of 2 and a corporation standing of 5.

- Sales tax = 7.5% × (1 − 0.11 × 4) = 7.5% × 0.56 = **4.2%**.
- Broker fee = 3% − 1.2% − 0.06% − 0.1% = **1.64%**.

Sale of 100 units at 1,000,000 ISK, a revenue of 100,000,000 ISK:

| How you sell | Rate | Fees | Net |
|---|---|---|---|
| Sell order | 1.64% + 4.2% = 5.84% | 5,840,000 | 94,160,000 |
| Existing buy order | 4.2% | 4,200,000 | 95,800,000 |
| Contract | 0% | 0 | 100,000,000 |

Reference points: with neither skill trained and neutral standings, 7.5% tax and 3% broker fee; with both skills at level 5 and neutral standings, 3.375% and 1.5%.

## When the fees are unknown

The fees are **unknown**, never 0 nor a default rate, when:

- the account has no main character;
- the main character's standings have never been synced (for example when its token lacks the standings scope);
- the main character's Accounting or Broker Relations level has never been read (skills never synced, or a token without the skills scope).

An untrained skill does not make the fees unknown: after a successful sync it is stored at level 0 and counts as such.

On screen:

- **"Margins"**: the header shows "Unknown fees" and a banner "The broker fee and the sales tax are computed from your skills and standings. Sync them from the Skills page." The "Fees" and "Profit" columns show "—" (tooltip "Unknown value") and "Margin" shows "Unknown", except the contract row, which has no fees.
- **"Where to sell"**: "Unknown fees" in the "Fees" and "Net / unit" columns, and in the "Net" of the immediate sale.
- **"What to produce"**: "Seller fees unknown: sync the skills and standings of your main character".
- **Project**: "Fees unknown: the project owner has no synced skills and standings, so the net revenue, the profit and the margin cannot be computed." The break-even shows "The sales fees are unknown." and the transfers "The broker fee or the sales tax of the project is unknown: transfers cannot be computed." A project sold only through contracts has no market fees and is not affected.

## Pitfalls

- **The skills table does not show Accounting or Broker Relations.** The "Skills" section only shows Industry, Adv. Industry and Reactions. Before the first sync it shows levels at 0 with the source "Manual": that is a missing value, and the fees stay unknown. Accounting and Broker Relations cannot be set by hand.
- **The "Margins" banner points to "the Skills page".** The sync actually happens in "Configuration", "Skills" section, "Sync all" button. The "Skills" page (skill gains) has no sync button.
- **The "Fees" section of the configuration does not hold these fees.** It only sets the "Export cost (ISK/m³)". Sale fees cannot be typed in.
- **A project's rates are frozen at creation.** They are copied from the owner's fees, then never recomputed: syncing skills later does not fill a project created with unknown fees, and does not update an existing project. The owner or an admin corrects them with the "Edit" button, fields "Broker Fee %" and "Sales Tax %". The hint "Left empty, the fees stay unknown until the owner syncs skills and standings." suggests a sync is enough: you have to type both rates. Their sum cannot exceed 100%, and a completed project can no longer be edited.
- **In a project, every market sale pays broker fee and tax.** The distribution applies both rates to all the market channel revenue, including a sale to an existing buy order, which the analysis screens count with the tax only.
- **The note under "Sell Price Comparison"** says the fees include broker fee and sales tax, and that the margin is profit / revenue. The calculation applies only the tax to "instant sell", and the margin is profit / total cost (see [margin](/help?page=calculations/margin)).
- **Always the Jita standings.** A sale in a player structure uses the same broker fee, computed with the standings towards the NPC faction and corporation of Jita 4-4. EVE Tools does not read a structure's own fees.

## Technical note

Calculation: `SalesFeeCalculator::calculate()` (`src/Service/Industry/SalesFees/SalesFeeCalculator.php`), called by `SellerSalesFeesResolver::forUser()`, which reads the main character, its skills and its cached standings. Sync: `SyncCharacterSkillsProcessor` and `CharacterStandingsSynchronizer`. Per-venue application: `ProfitMarginService::marginsOf()`, `SellMarketComparison`, `BatchProfitScannerService`, `BuyVsBuildService`, `PivotAdvisorService`. Projects: `CreateProjectProcessor` (copy), `ProjectDistributionService`, `ProjectBreakEven`, `ProjectSettlementCalculator`.
