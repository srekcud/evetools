# Profit and margin: how EVE Tools calculates them

This page explains what "profit" and "margin" mean in the Industry modules: the formula, where each term comes from, how fresh it is, and what becomes unknown when a piece of data is missing. There is **one single definition of margin**, shared by the **Margins** tab, by projects and by the reference shown when you create a project.

## The formula

```
net revenue = sales revenue − broker fee − sales tax − contract fees
profit      = net revenue − production cost
margin      = profit ÷ production cost × 100
```

The margin is divided by the **production cost**, never by the revenue. A 20% margin means 100 ISK of production brings 20 ISK of net profit, that is 120 ISK of net revenue. The formula in the code is the reference, whatever help text the screen shows.

| Term | What it is | Where the data comes from |
|---|---|---|
| **Sales revenue** | In a project: the total of the sales you recorded (quantity × unit price). In the Margins tab: the assumed sell price × the quantity produced. | Project: your sales, entered by hand or attached to a wallet transaction or a contract. Margins: see "The Margins tab". |
| **Broker fee and sales tax** | Percentages taken on a market sale. A contract pays neither. | Calculated for the **seller** (see below). |
| **Contract fees** | Fees to create a contract, read from the seller's wallet journal. | ESI, seller's wallet journal. |
| **Production cost** | In a project: the sum of the value of the **approved contributions**. In the Margins tab: materials, job installation, and invention for a T2 product. | See "The production cost of a project" and the guide [Job cost, structure and rig bonuses, ME and TE](/help?page=calculations/job-cost-and-bonuses). |

### Seller fees

- **Sales tax** = 7.5% × (1 − 11% × Accounting level).
- **Broker fee** (NPC market) = 3% − 0.3% × Broker Relations level − 0.03% × standing towards the faction − 0.02% × standing towards the corporation.
- The standings are those of the Jita 4-4 station (Caldari State for the faction, Caldari Navy for the corporation). A standing missing from the synchronized list counts as 0 (neutral).
- They are calculated for the seller's **main character**, from the skills and standings in the cache. Standings are raw: no Connections or Diplomacy.
- A project copies these percentages when it is created (you can edit them): every view of the project then uses them.

## The production cost of a project

The cost of a project is the sum of the value of its approved **contributions**. Each contribution is counted at a **real price**, never a catalog price:

| Contribution | Value counted |
|---|---|
| Material bought and linked to the project | The **price paid**: the sum of the linked purchases (wallet). |
| Material deposited from your stock | The **Jita price of the deposit day**: 5th percentile of the Jita sell orders, read when the contribution is deposited, then frozen on it. |
| Linked job installation | The **real cost** read from the ESI job. A job started by another participant is in that participant's name. |
| BPC, line rental | The value you enter by hand (line rental offers a suggestion). |
| Courier | The reward of the delivered courier contract. |

A **declared** contribution waits for review (except in a solo project, or when the owner is the only possible reviewer: it is then approved when deposited). Only approved contributions count.

## The three margins of a project

### Final margin

When every product is sold, the margin is the one from the formula above, over the whole net revenue and the whole cost. Closing the project shows "Final margin".

### Provisional margin

With partial sales, the profit and the margin count only the revenue **already collected**: they are low, even negative, until the cost is covered. They are flagged **provisional**: in the Workshop, the Profit column shows "my provisional share". The list of **unsold** products (quantity to sell minus quantity sold, per product) is shown next to it.

### Margin projected at the last sale price

To estimate what the unsold products would bring, EVE Tools values them at the **unit price of the most recent sale** of the same product, with the same broker fee and sales tax, and recalculates the margin. It is unknown if an unsold product was never sold ("never sold"), because it has no last price.

### Before any sale

With no sale at all, the project is **awaiting sales**: net revenue, profit, margin and payouts are unknown, never −100% or 0. The Workshop shows a **Jita forecast (project)** instead:

```
forecast profit = Jita net revenue of the products − cost of what is left to produce − cost already committed
```

- The Jita net revenue is the **weighted price of the Jita sell orders** for the quantity to sell, minus the owner's broker fee and sales tax.
- The **cost of what is left to produce** is the sum, over the material lines not yet complete, of the remaining quantity × the line's estimated price (without the installation of jobs not yet started).
- The cost already committed is that of the approved contributions.

In the project page, the **sell forecast** gives a **planned profit** per column (sell structure or Jita): net revenue to sell minus the cost of what is left to produce, without the cost already committed.

## Break-even and cost per unit

- **Break-even price per unit** = cost ÷ (units × (1 − broker fee − sales tax)). Below this sell price, the sale loses money.
- **Cost per unit** (personal-use project) = cost ÷ units of the final product.
- Neither is calculated until all final jobs are delivered, nor with several final products, nor without an approved contribution.

## Several participants

The profit is split **in proportion to the costs contributed**: a participant's profit share is their costs × the margin, and their **payout** is their costs × (1 + margin). In a solo project, the share is the whole profit. In-game transfers then make sure each person holds their payout.

## The Margins tab (before starting a project)

The **Margins** tab applies the same formula to a product, before you create a project. The production cost comes from the production plan (materials at the weighted Jita price, installation, invention) and the margin is calculated for four **sell venues**:

| Venue | Price used | Fees counted |
|---|---|---|
| Jita (sell) | Weighted price of the Jita sell orders for the quantity produced | Broker fee + sales tax |
| Structure (sell) | The lowest sell order of the chosen structure | Broker fee + sales tax |
| Structure (buy) | The best buy order of the chosen structure | Sales tax only |
| Contract | The lowest unit price of public contracts | None |

## Worked example (fictional)

The figures below are **made up** to illustrate the calculation: they come neither from the game nor from a market.

A 10-unit project costs 100 M ISK of approved contributions (60 M contributed by A, 40 M by B). The broker fee is 2% and the sales tax 3%.

| Step | Calculation | Result |
|---|---|---|
| First sale: 6 units at 15 M | 90 M − 5% fees = 85.5 M net revenue | profit −14.5 M, provisional margin **−14.5%** |
| Unsold: 4 units at the last price (15 M) | 60 M × 0.95 = 57 M, so 142.5 M net revenue in total | projected margin **42.5%** |
| Everything sold at the same price | 150 M − 7.5 M fees = 142.5 M | profit 42.5 M, final margin **42.5%** |
| Break-even | 100 M ÷ (10 × 0.95) | about **10.53 M** per unit |
| A's share | 60 M × 42.5% = 25.5 M profit | payout 85.5 M |
| B's share | 40 M × 42.5% = 17 M profit | payout 57 M |

## When the result is unknown

A missing piece of data makes the result **unknown**, shown as such, never 0. A sum that contains an unknown amount is unknown.

| What is missing | What becomes unknown | How the app shows it |
|---|---|---|
| No sale recorded | Net revenue, profit, margin, payouts of the project | "Awaiting sales", then the Jita forecast if it can be calculated ("no sale yet" otherwise) |
| Sell fees (main character, Accounting or Broker Relations level, standings not synchronized) | Net revenue, profit, margin, break-even, forecast | "Fees unknown: the project owner has no synced skills and standings…" |
| Contract fees missing from the wallet journal (a contract older than 30 days, for example) | Net revenue, margin, payouts, transfers | The amounts stay unknown, with no default value |
| Value of an approved contribution unknown (an ESI job without a cost, for example) | Production cost, profit, margin, payouts | "An approved contribution has no known price…" |
| Production cost equal to 0 (no approved contribution) | Margin and member shares | "Undistributable distribution" |
| Unsold product never sold | Projected margin only | "never sold" |
| Market price, adjusted price, cost index or invention probability missing | Production cost of the Margins tab, so all its margins | No comparison row, and the reason in amber (for example "ESI adjusted price unknown") |
| Sell price missing or zero for a venue | The margin of that venue only | The venue has no row; with no sell price at all: "No market data available" |
| Sell fees unknown in the Margins tab | Fees, profit and margin of the market venues; the Contract venue is still calculated | "Unknown" in the cells concerned |

A **reference project** (the latest completed project for the same product, less than one month old, single-product, where you are an accepted participant) shows its unit cost and its margin when you create a project for the same product. Older or multi-product, it is ignored. An unknown figure stays "unknown" there.

## Data freshness

| Data | Synchronization | Note |
|---|---|---|
| Jita sell orders | Every hour | Kept 2 hours in the cache. A type missing from the cache is read on demand and kept 5 minutes. |
| Sell structure orders | Every hour | Read from the synchronization cache, never live. |
| Public contracts | Every 30 minutes | Price of the lowest contract per type. |
| Wallet journal (contract fees) | Wallet synchronization, 5-minute tick | 30-day window. |
| Seller's skills and standings | Character skills synchronization | Without a synchronization, the fees are unknown. |
| Deposit-day price | Read when the contribution is deposited | Frozen afterwards, it no longer follows the market. |
| ESI job cost | Industry jobs synchronization, every 30 minutes | A job without a cost makes the contribution unknown. |
| Sales and purchases of a project | Entered by hand or attached wallet transactions | No default value. |

The material and sell prices of a **project in progress** are not frozen: the forecast, the projected margin and the break-even move when the market moves or when you record a sale.
