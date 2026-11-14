# Weighted price and Appraisal

The **Appraisal** page values a list of items you paste (from the game or as name + quantity) at Jita prices. It shows two totals for the same list: what it would **cost to buy** (sell orders) and what you would **receive by selling** it right away (buy orders). Both rest on the **weighted price** of the Jita order book.

Find it under **Commerce** › **Appraisal**.

## The weighted price

A single "lowest price" is only valid for one unit. The weighted price is the average unit price you would really pay (or receive) for the whole quantity: the orders of the **order book** are consumed from the best to the worst until the quantity is covered.

```
for each order, best first:
    taken     = min(units still missing, volume of the order)
    cost     += taken × price of the order
    covered  += taken

weighted unit price = cost ÷ covered
coverage            = covered ÷ requested quantity   (at most 1)
line total          = weighted unit price × requested quantity
```

- **Sell side** (the "Sell" rows): the sell orders, cheapest first. It is the cost of buying the quantity.
- **Buy side** (the "Buy" rows): the buy orders, highest first. It is what you receive by selling the quantity immediately (**immediate sale**).
- The **best price** is the first order of the book: the cheapest sell order, the highest buy order. It is shown in grey under the weighted value.
- Prices and totals are rounded to 2 decimals.
- The **requested quantity** is the sum of the quantities pasted for the item: two lines of the same item are merged.

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Item name → item | SDE (`sde_inv_types`): exact name, case-insensitive, **published** items only | Changes with the SDE import |
| Orders and their volume | ESI `GET /markets/10000002/orders/` (The Forge), one call per item, no authentication | Hourly sync, then 5 minutes (see below) |
| Jita 4-4 orders | Orders located at the Jita 4-4 station; if the station has **no** order on a side, all the orders of The Forge on that side. The choice is made side by side | Same as above |
| Book depth | The 20 best orders of each side are kept | Same as above |
| Unit volume (m³) | SDE | Changes with the SDE import |
| Average daily volume (`~N/day`) | ESI market history of The Forge, average of the last 30 history entries | Cached 24 hours per item |

**Freshness of the orders:**

- A scheduled sync reads the Jita books **every hour**. It covers the items EVE Tools needs for Industry: the materials of the manufacturing, invention and reaction activities, ores and compressed ores, reprocessing outputs, planetary commodities and the products that can be manufactured. The result is kept for **2 hours**.
- An item **outside that list**, or any item when no sync result is available, is read from ESI at the moment of the appraisal and kept **5 minutes**.
- The Appraisal page itself shows the age of the Jita prices only in the **Structure Comparison** panel (**Jita prices** line), once a structure has synced prices.

## Worked example

Illustrative numbers, not real market prices. An item whose Jita sell orders are: 400 units at 100 ISK, 300 at 110 ISK, 200 at 120 ISK (900 units in total).

**Quantity 600: the book is enough.**

| Step | Calculation | Result |
|---|---|---|
| Units taken | 400 at 100 + 200 at 110 | 600 of 600 |
| Cost | 40,000 + 22,000 | 62,000 ISK |
| Weighted unit price | 62,000 ÷ 600 | about 103.33 ISK |
| Coverage | 600 ÷ 600 | 100 %, no marker |
| Best price (lowest order) | 100 × 600 | 60,000 ISK |

**Quantity 1,000: the book is too short.**

| Step | Calculation | Result |
|---|---|---|
| Units taken | 400 + 300 + 200 | 900 of 1,000 |
| Cost of the covered part | 40,000 + 33,000 + 24,000 | 97,000 ISK |
| Weighted unit price | 97,000 ÷ 900 | about 107.78 ISK |
| Coverage | 900 ÷ 1,000 | 90 %, marker "90 % covered" |
| Line total | 107.78 × **1,000** | about 107,778 ISK |

The 100 missing units are priced at the average of the covered units, not at a higher price: the real cost is probably higher. That is why the percentage is shown.

On the buy side, with buy orders of 300 units at 90 ISK then 200 at 80 ISK and a quantity of 400: 300 × 90 + 100 × 80 = 35,000 ISK, that is 87.50 ISK per unit.

## Reading the page

- **Sell Value** (card): the weighted total of the sell side, "cost to buy these items". **Buy Value**: the weighted total of the buy side, "what you receive selling". Under each, **Best price** is the same total at the best price only.
- **Unit Price** column: the weighted unit price of the **Sell** and **Buy** rows, with the best price in grey under it, and `~N/day` (amber when the quantity exceeds the average daily volume).
- **Weighted Total** column: the line total of each side, with the best-price total in grey.
- **Coverage**: when a book is too short, the line total carries "{n} % covered" (shown from 0.1 % to 99.9 %, never 0 % or 100 %). The sell unit price also carries a warning triangle: "Only {available} of {total} available (top 20 orders)".
- Above the table, the **Structure Comparison** panel compares Jita with a structure. It uses the **lowest sell price** of each market, not a weighted price: "Jita + Transport" is the lowest Jita sell price × quantity plus volume × **Transport cost / m3** (1,200 ISK by default). The structure column is the lowest sell price of the structure × quantity, read from the last structure sync. The column and its tooltip are labelled "Weighted Sell", which does not match what is calculated.

## When the result is unknown

| Case | What the page shows |
|---|---|
| No order on a side (or no volume in the book), or the ESI call of this item failed | The unit price and the total of that side show "-". The item is not counted in the total of that side. A failed call of a single item gives no error message |
| Item not recognised (unknown or unpublished name) | The yellow banner "{count} item(s) not found" lists the names. They are not appraised |
| Item without a volume in the SDE | "Unknown" in the **Volume** column and in the **Total Volume** card, and a banner naming the items. The volume and the Jita + Transport total are unknown |
| Prices cannot be read at all | The banner "Unable to fetch market prices. Please try again later." appears (in English in both languages). The items have no price |
| No synced market for the structure | The structure column shows "--" for every item. Use **Sync prices** in the panel |
| Average daily volume unavailable | The `~N/day` line is not shown. A failure is logged and the appraisal goes on |

Three points deserve attention:

- **The totals only add the items that have a price.** There is no counter of the items left out: look for the "-" in the table. When no item has a price, the card shows 0 ISK: read it as "no price", not as a value.
- A line the reader cannot parse is **ignored silently**: it is neither appraised nor listed as not found. For example a quantity before the name without `x` (`1000 Tritanium`) is skipped. The accepted forms are `10x Tritanium`, `Tritanium x10`, `Tritanium<tab>10`, a name followed by two spaces and a number, and a name alone (quantity 1). The **Format** buttons do not change the reading: the text sent is always the same.
- A coverage of 100 % only says that the **known** book is enough. EVE Tools reads the first page of the ESI orders of an item and keeps 20 orders per side: on a deep market, the orders beyond are unknown and the best price can be wrong.
