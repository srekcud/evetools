# Ore value and moon tax

This page explains how EVE Tools prices one unit of ore, ice or gas, and how it computes the moon tax of a refinery. The step-by-step flow (operations, refinery settings, donations) is in the playbook [mining operation and moon tax](/help?page=playbooks/mining-moon-tax).

Two pricing rules live side by side:

- **ore value** follows your **mining valuation profile** (market, side, basis, structure, export tax), set on the "Ore value" page;
- **moon tax** ignores that profile: it always takes the Jita buy price of the compressed ore, with no export tax.

## Where the figures appear

| Screen | Figure |
|---|---|
| "Mining" menu, "Ore value" tab, "Value per mined m³" table | Per ore: "Raw", "Compressed", "Reprocessed", "Best" and "Gap to best", in ISK/m³ |
| Same page, "Reprocessing yield" panel | The yield used for the "Reprocessed" column |
| "My mining" tab | "Total value" of the ore mined over the period, with its valuation source |
| "Moons" tab, "Chunks" sub-tab, "Value" column | "Estimated value" of a chunk and its share "At 4", "At 6", "At 8 participants" |
| "Moons" tab, "Moon tax" sub-tab | "Due", "Paid", "Remaining" and "Status" of each statement |

The settings at the top of the "Ore value" page ("Export tax", "Structure", "Market", "Valuation basis") are saved on your account: they also value "My mining" and the chunks ("The structure and the export tax also value the lines of My mining.").

## The formula

### Unit price of an ore

For each ore, EVE Tools reads the **best order** of the book (highest buy for "Jita buy" and "Structure buy", lowest sell for "Jita sell" and "Structure sell"). It is not a weighted price.

Raw = max(0, raw ore price − export tax × volume of one raw unit)
Compressed = max(0, compressed ore price − export tax × volume of one compressed unit) × compressed portion size ÷ raw portion size
Reprocessed = Σ (mineral quantity per batch × yield × mineral price) ÷ raw portion size

- All three are prices **per mined raw unit**. Compressed is brought back to the raw unit through the SDE portion sizes: this is the **compressed equivalent price**.
- The compressed variant is the type named "Compressed" followed by the raw ore name. Without that type, "Compressed" is unknown.
- The **export tax** ("Export tax", ISK/m³) only applies to Jita raw and compressed prices. It touches neither the reprocessed value nor structure prices. A negative net price is clamped to 0.
- Reprocessed uses the SDE mineral quantities for one batch of raw ore. If a single mineral has no price, or if the sum is 0, reprocessed is unknown.

### Value per m³ ("Value per mined m³" table)

ISK/m³ = unit price ÷ volume of one raw unit
Best = the highest of the three known values (on a tie: compressed, then reprocessed, then raw)
Gap to best = (best value of the table − "Best" of the row) ÷ best value of the table × 100

The volume is the raw ore volume, even for compressed: the table compares what one m³ **extracted by the laser** brings. The top row has a 0 % gap. The gap is shown with one decimal.

The table lists the published types of the asteroid category and of the gas cloud group that are on the market, without their compressed variants. "Ice" family: SDE group 465; "Gas": group 711; everything else is "Ore".

### Reprocessing yield

"Reprocessing yield" panel: you pick "Character", "Ore processing skill", "Location", "Reprocessing rig", "Security" and "Implant". EVE Tools reads the character's skill levels through ESI and computes:

yield = base × (1 + 0.03 × Reprocessing) × (1 + 0.02 × Reprocessing Efficiency) × (1 + 0.02 × ore skill) × (1 + implant ÷ 100)

| Location | Base |
|---|---|
| "NPC station" | 0.50 |
| "Athanor" or "Tatara" | (50 + rig bonus) ÷ 100 × (1 + security bonus) × (1 + structure bonus) |

| Setting | Values |
|---|---|
| "Reprocessing rig" | "No rig" 0, "T1 rig" 1, "T2 rig" 3 (points added to the 50) |
| "Security" | "High security" 0, "Low security" 0.06, "Null security and wormholes" 0.12 |
| Structure | "Athanor" 0.02, "Tatara" 0.055 |
| "Implant" | 0, 1, 2 or 4 % |

Rig and security only count in a structure. EVE Tools does not cap the result. The yield is recomputed when you save the panel or click "Recompute".

### Value of mined ore ("My mining")

Total value = Σ (mined quantity of the type × unit price of the type on your profile)

The quantity comes from your mining ledger, summed over the period. The profile's valuation basis ("Raw", "Compressed" or "Reprocessed") picks which of the three unit prices is used; "Reprocessed" uses the yield of your panel. A mining operation has its own basis, which replaces your profile (playbook, step 3).

### Chunk value

chunk volume = extraction time in hours × 25,000 m³/h
units of an ore = ore share × chunk volume ÷ volume of one raw unit
estimated value = Σ (units × unit price on your profile)
share per participant = estimated value ÷ 4, 6 or 8

The extraction time runs from the extraction start to the chunk arrival. Shares come from the moon scan pasted in "Moon scan". If the shares add up to less than 100 %, the rest is worth nothing: nothing is rescaled. The table row shows the share "at 6"; "Show the value detail" gives the others and the volume calculation. It is a value before booster and tax.

### Moon tax

For each character, refinery and period, EVE Tools sums the refinery ledger per ore type, then:

tax of a type = rounded to 2 decimals (quantity × Jita buy compressed equivalent price × rarity rate ÷ 100)
statement tax = rounded to 2 decimals (Σ type taxes)

- Price: best Jita buy order of the compressed ore, brought back to the raw unit, with no export tax and no structure, whatever your profile.
- **Rarity**: taken from the SDE group of the ore: 1884 = R4, 1920 = R8, 1921 = R16, 1922 = R32, 1923 = R64. The rate is the one entered for the refinery ("R4 rate (%)" to "R64 rate (%)").
- Period: a ledger line belongs to the period that contains its date (week from Monday 00:00 UTC, or month from the 1st 00:00 UTC).
- The **statement** is created, at the end of a successful ledger read, for a period that ended at least one hour earlier. Its lines, prices and rates are frozen then. The current period stays "Provisional" and is recomputed at each display with current prices.

### Settlement through donations

Paid = sum of the donations applied to the statement
Remaining = Due − Paid, rounded to 2 decimals

An ISK donation received by the corporation pays a statement when all three conditions hold:

1. same corporation;
2. same payer: the character a manager attributed the donation to, otherwise the donor;
3. donation date on or after the **start** of the statement period.

Donations are taken from oldest to newest. Each one first pays the statement that ends first (across all refineries of the corporation), and its surplus moves on to the next one that meets the conditions. **The donation reason is never read**: the `TAX-…` reference is only there for humans.

Status of a statement that is neither cancelled nor marked paid: "Paid" when nothing remains; otherwise "Late" more than 7 days after the end of the period; otherwise "Partial" if part is paid, "Due" if not.

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Jita prices of ores, compressed ores and minerals | ESI `/markets/10000002/orders/`, best order at the Jita 4-4 station | Synced every hour, kept 2 h |
| Structure prices | Structure market (ESI), read with a character that has access | Every hour if it is the default structure or a market structure picked by a user; otherwise only through "Sync structure prices". Kept 2 h |
| Volume, portion size, reprocessing minerals, group (rarity, family) | SDE | At each SDE import |
| Skill levels | ESI, skills of the panel's character | On save or "Recompute" |
| Location, rig, security, implant, export tax, market, basis, structure | Your settings | Saved on your account |
| "My mining" quantities | ESI, mining ledger of your characters | See [data freshness and privacy](/help?page=data/freshness-privacy) |
| Extraction start and chunk arrival | ESI, corporation extractions | Every 30 minutes |
| Ore shares of a moon | Scan pasted by a player | Until the next paste |
| Refinery ledger | ESI `/corporation/{id}/mining/observers/{observer_id}/`, one line per character, type and day, replaced at each read | Every hour, or "Sync" |
| Donations | ESI `/corporations/{id}/wallets/{division}/journal/`, positive `player_donation` lines received by the corporation | Every hour, or "Sync" |
| R4 to R64 rates, period | Refinery setting | Frozen in the statement when it is created |

## Example

Example values, round, not taken from the game.

**Yield.** NPC station, Reprocessing 5, Reprocessing Efficiency 5, ore skill 5, implant 0 %:
0.50 × 1.15 × 1.10 × 1.10 = 0.69575, i.e. 69.575 %.

**Value of an ore.** Ore "Example": 10 m³ per raw unit, 0.1 m³ per compressed unit, same portion size (100) raw and compressed. A batch of 100 raw units reprocesses into 1,000 mineral A and 100 mineral B. Profile: "Jita buy", "Export tax" 10 ISK/m³, yield 70 %. Best Jita buy orders: raw 1,000 ISK, compressed 1,100 ISK, mineral A 50 ISK, mineral B 400 ISK.

| Figure | Calculation | Result |
|---|---|---|
| Raw, per unit | 1,000 − 10 × 10 | 900 ISK |
| Compressed, per raw unit | (1,100 − 10 × 0.1) × 100 ÷ 100 | 1,099 ISK |
| Reprocessed, per raw unit | (1,000 × 0.7 × 50 + 100 × 0.7 × 400) ÷ 100 | 630 ISK |
| "Raw" in ISK/m³ | 900 ÷ 10 | 90 |
| "Compressed" in ISK/m³ | 1,099 ÷ 10 | 109.9 |
| "Reprocessed" in ISK/m³ | 630 ÷ 10 | 63 |
| "Best" | the highest | 109.9 (compressed) |

If this is the best row of the table, its gap is 0.0 %; another row at 100 ISK/m³ shows (109.9 − 100) ÷ 109.9 × 100 = 9.0 %. With the "Compressed" basis, 1,000 mined units are worth 1,000 × 1,099 = 1,099,000 ISK in "My mining".

**Chunk value.** A 100 h extraction: 100 × 25,000 = 2,500,000 m³. Scan: ore A 40 %, ore B 60 %, both 10 m³ per unit, profile prices 500 ISK and 100 ISK.

| Ore | Units | Value |
|---|---|---|
| A | 0.4 × 2,500,000 ÷ 10 = 100,000 | 50,000,000 ISK |
| B | 0.6 × 2,500,000 ÷ 10 = 150,000 | 15,000,000 ISK |
| Total | | 65,000,000 ISK |

The row shows "≈ 65,000,000 ISK · 10,833,333 ISK / person at 6"; the detail also gives 16,250,000 ISK at 4 and 8,125,000 ISK at 8.

**Moon tax.** Weekly refinery, "R4 rate (%)" 5, "R16 rate (%)" 10. In week 1, a character mines 10,000 units of an R16 ore (Jita buy compressed equivalent price 500 ISK) and 20,000 units of an R4 ore (100 ISK).

| Line | Calculation | Tax |
|---|---|---|
| R16 | 10,000 × 500 × 10 ÷ 100 | 500,000 ISK |
| R4 | 20,000 × 100 × 5 ÷ 100 | 100,000 ISK |
| Week 1 statement | | 600,000 ISK |

Their week 2 statement is 300,000 ISK. During week 2, they donate 400,000 ISK on Tuesday and 250,000 ISK on Wednesday, from the character who mined.

| Donation | Effect |
|---|---|
| 400,000 (Tuesday) | Week 1: paid 400,000, remaining 200,000 |
| 250,000 (Wednesday) | Week 1: paid 600,000, "Paid". Surplus of 50,000: week 2 starts on Monday, before the donation, so it goes there |

While week 2 is "Provisional", it shows "Paid" 0 and "Remaining" 300,000. Once its statement exists, it shows "Paid" 50,000 and "Remaining" 250,000.

## When the figure is unknown

Unknown shows as "—" (tooltip "Unknown value") or "unknown", never 0.

| Missing input | Effect | On screen |
|---|---|---|
| No order on the chosen market for the raw or compressed ore | Price of that column unknown | "—" in the column |
| No "Compressed …" variant, or portion size missing or zero | "Compressed" unknown | "—" |
| No yield computed (panel not filled, no ESI token, skills scope missing, ESI error) | "Reprocessed" unknown everywhere | "No reprocessing yield computed: reprocessed values are unknown. …" and the panel's failure message |
| One mineral without a price | "Reprocessed" unknown for that ore | "—" |
| Ore without reprocessing minerals in the SDE | No reprocessed value | "—" with "Cannot be reprocessed" for screen readers |
| Ore volume missing or zero | All its per-m³ values unknown | "—" |
| None of the three values known | "Best" and "Gap to best" unknown | "—", row at the bottom of the table |
| A mined type without a price on your profile | "Total value" unknown, not a partial sum | "Ores without a price: …" |
| No mining over the period | Total value 0: there is nothing to value | 0 |
| No moon scan | Chunk not valued | "value unknown · paste the moon scan" |
| An ore of the scan without a price | Chunk not valued | "value unknown · price missing" |
| An ore of the scan without a volume | Chunk not valued | "value unknown · ore volume missing" |
| A ledger type without a Jita buy compressed price, or outside the R4 to R64 groups | Statement tax unknown, status "Unknown", no donation applied | "unknown" and "no price:" followed by the type numbers; totals "unknown" with "of which … known" |

## Pitfalls

- **Two different prices for the same moon ore.** Chunk value and "My mining" follow your profile; moon tax always takes Jita buy compressed. With a "Jita sell" or "Reprocessed" profile, the tax cannot be derived from the value shown.
- **The message of a chunk without a price is misleading.** The detail says "At least one ore of the scan has no compressed Jita buy price." while the missing price is the one of your profile.
- **The export tax is partial.** It is only taken off Jita raw and compressed prices. Reprocessed values and structure prices carry no export tax, which favours the "Reprocessed" column over compressed once you enter a tax.
- **A price clamped to 0 is not unknown.** If the export tax exceeds the price, raw or compressed shows 0 instead of "—".
- **Best order, not the book.** The price is the first order, whatever the quantity. To sell a lot, use the weighted price of the [Appraisal](/help?page=calculations/weighted-price-appraisal).
- **Stale structure prices.** Your profile's structure is only re-read every hour if it is also the default structure or a market structure picked by a user. Otherwise its prices vanish 2 h after the last "Sync structure prices" and the values turn unknown.
- **The empty "Structure" field shows a structure marked "(defaut)".** The mining profile has no default structure though: until you pick one, "Structure buy" and "Structure sell" stay greyed out.
- **A statement freezes the price of the moment it is created,** about one hour after the end of the period, not the price of the mining day.
- **Donations do not reduce a "Provisional" line.** A donation made during the current period only shows once the statement exists.
- **A donation dated before the start of a period never pays it.** It only pays statements of periods already started at its date: you cannot prepay a future period.
- **The gap to best ignores the family filter.** With "Ice" or "Gas", the gap is still measured against the best row of the whole table, ores included.
- **Changing a refinery's "Period" can create a duplicate.** A statement is identified by refinery, period start and character. Switching from "Weekly" to "Monthly" changes the period starts: days already billed by weekly statements can produce an extra monthly statement. Pick the period before the first sync.
- **A late ledger line is lost for the tax.** Once the statement exists, a new line for the same period and character is no longer counted.

## Technical note

Ore value: `OreValueService::getBatchOrePrices()` (raw price, `compressedEquivalentPrice`, `reprocessValue`), table `OreValueTableService::build()` (`GET /api/mining/ore-values`, `ICE_GROUP_ID` = 465, `HARVESTABLE_CLOUD_GROUP_ID` = 711), profile `MiningProfileUnitPrices::read()` and `MiningValuationSettings::defaults()` (Jita, buy, compressed, export tax 0). Best order: `JitaMarketService::getPrices()`, `getBuyPrices()` (`CACHE_TTL` = 7200 s); `StructureMarketService::getLowestSellPrices()`, `getHighestBuyPrices()` (`CACHE_TTL` = 7200 s). Yield: `ReprocessingYieldCalculator::calculate()` (`NPC_STATION_BASE_YIELD` = 0.50, `STRUCTURE_BASE_PERCENT` = 50, per-level bonuses 0.03, 0.02, 0.02), `ReprocessingRigTier`, `ReprocessingSecurity`, `ReprocessingLocation`. Mined value: `MiningBestValueCalculator::getTotalBestValue()`. Chunk: `MoonChunkValueReader::read()`, `MoonChunkValuer::value()` (`PARTICIPANT_COUNTS` = 4, 6, 8), `MoonChunkVolumeEstimator` (`EXTRACTION_RATE_M3_PER_HOUR` = 25,000). Tax: `MoonTaxUnitPrices::read()`, `MoonOreRarity::fromGroupId()`, `MoonTaxCalculator::calculate()` (`MONEY_DECIMALS` = 2), `MoonTaxPeriod::containing()`, `MoonTaxStatementCloser::close()` (`LEDGER_CACHE_DELAY` = 1 h), `MoonTaxStatementReader` (provisional lines), `MoonTaxSettlement::settle()` (`PAYMENT_GRACE_DAYS` = 7).
