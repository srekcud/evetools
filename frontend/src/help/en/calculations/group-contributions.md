# Group contributions: contribution value and revenue distribution

This page explains two calculations of an industry project: the value of each contribution, and how the net revenue from sales is split between participants (payouts, then transfers). The step-by-step flow is in the [owner](/help?page=playbooks/group-project-owner) and [participant](/help?page=playbooks/group-project-member) playbooks. The project margin is defined in [profit and margin](/help?page=calculations/margin), sale fees in [sale fees](/help?page=calculations/market-fees).

Labels in quotes " " are the ones on screen.

## Where the figures appear

| Screen | What is shown |
|---|---|
| "Contributions" tab | "Valuation" (value of the contribution) and "Method" (where it comes from) columns; table footer "Total Engaged Costs", "Approved Value", "Pending Value" |
| "Finances" tab, "Profit Distribution" table | Per participant: "Costs Engaged", "Share %", "Profit Part", "Payout Total"; "Total Payout" in the middle of the ring |
| "Finances" tab, "Transfers between members" section | The transfers to make, the "Payment reference", and sometimes "Rounding gap absorbed by the largest payout: …" |

The "Contributions" tab is hidden while you are alone on the project, with no request and no contribution to review.

## The value of a contribution

| Contribution | Credited to | Value | "Method" |
|---|---|---|---|
| Declared "Material" ("Contribute", "I supplied it", "Add Contribution") | Whoever declares it | Jita price of the deposit day × quantity | "Jita price of the deposit day" |
| "Material" from a linked purchase ("Purchases") | The owner | Sum of the totals of the purchases linked to the line, for the line's type | "paid price (wallet)" |
| "Job Install" from a linked job | The participant who installed the job | Sum of the ESI costs of their jobs linked to the line | "real cost (ESI)" |
| Declared "Job Install" or "BPC" | Whoever declares it | Entered "Estimated Value", strictly positive | "entered value" |
| "Line Rental" | Whoever declares it | Entered "Estimated Value", required; 0 is accepted | "entered value" |
| "Courier" | The owner | Reward of the linked courier contract, once delivered | "entered value" (see Traps) |

- **Jita price of the deposit day**: weighted average of the cheapest 5% of the volume of Jita sell orders. It is read once, at submission, and kept as is: the review does not read it again. With no Jita price that day, the contribution is refused ("No market price for type N: no Jita price for the deposit day."), never valued at 0.
- **Paid price**: for a purchase linked from the wallet, unit price of the transaction × linked quantity; for a "Manual purchase", entered unit price × quantity. Only the owner links purchases: another participant's purchase only counts if they declare it as "Material".
- **ESI cost**: the cost the ESI gives for the job. A job installed by a character that EVE Tools does not match to any participant is credited to the owner.

### When a value stops moving

- **Declared contribution**: its value is set at submission. Once "Approved", it can no longer be rejected, unless it doubles a detected contribution ("Duplicate of an ESI contribution").
- **Detected contribution** (linked purchase, linked job, courier): it arrives "Approved" and follows its links. Linking or deleting a purchase, linking or unlinking a job recomputes it; the jobs sync (every 30 minutes) copies the ESI cost again. The courier is read again when the project's courier is displayed: delivered, the reward counts; outstanding, in transit, failed or unlinked, it is removed; unreadable status, the last value stays.
- **"Completed" project**: no more recomputation. Values are frozen.
- When a detected contribution appears, a declared contribution still "Pending" from the same participant, on the same line and of the same type, is rejected automatically (note "Replaced by the ESI/wallet contribution").

## Revenue distribution

### Net revenue and margin

market revenue = sum of sales on the "Market order" channel (quantity × unit price)
contract revenue = sum of sales on the "Contract" channel
broker fee = market revenue × "Broker Fee %" ÷ 100
sales tax = market revenue × "Sales Tax %" ÷ 100
net revenue = market revenue + contract revenue − broker fee − sales tax − fees of attached contracts
total cost = sum of the values of "Approved" contributions ("Total Project Cost")
margin = (net revenue − total cost) ÷ total cost

The fees of an attached contract ("Link a contract") are read from the seller's wallet journal. A sale entered by hand on the "Contract" channel carries none.

### Each participant's payout

Only participants with at least one "Approved" contribution are in "Profit Distribution":

Costs Engaged = sum of their "Approved" contributions
Share % = Costs Engaged ÷ total cost × 100
Profit Part = Costs Engaged × margin
Payout Total = Costs Engaged + Profit Part = Costs Engaged × net revenue ÷ total cost

So the payout pays back your contributions and adds your share of the profit, or takes away your share of the loss. "Pending" and "Rejected" contributions are in none of these figures. Nothing is rounded at this step.

### Held revenue and transfers

EVE Tools moves no ISK. It assumes each sale stays in the wallet of whoever holds it, and computes the transfers that give everyone their payout.

1. **Holder of a sale**: the player of the "Seller", otherwise the person who recorded it. The "Seller" can only be one of the recorder's characters, so the holder is always whoever recorded the sale.
2. **Held revenue** of each participant = their market sales × (1 − ("Broker Fee %" + "Sales Tax %") ÷ 100) + their contract sales − those contracts' fees, rounded to the cent.
3. **Due** = "Payout Total", rounded to the cent.
4. **Balance** = held revenue − due. Positive: the participant must send ISK; negative: they must receive it.
5. **Rounding gap**: the balances should add up to 0; rounding to the cent can leave a few cents. That gap is taken off the balance of the participant with the largest payout, and the screen shows "Rounding gap absorbed by the largest payout: …".
6. **Transfer plan.** The largest debtor pays the largest creditor the smaller of the two amounts, then again until everything is settled. Up to 12 participants with a non-zero balance, EVE Tools first splits them into groups whose balances cancel out, as many groups as possible, and settles each group on its own: the plan has the fewest transfers possible. Above 12, everyone is settled in one block, and the plan may have more transfers than the minimum.

Each transfer carries the project's **payment reference** in its reason: `IP-` followed by the first 6 hexadecimal characters of the project identifier, in upper case. The plan is recomputed on every display.

### When payouts are exact

- **Payouts**: computed from the first sale, on the sales recorded so far. While some products are unsold ("Provisional margin"), they are provisional.
- **Transfers**: computed only once every planned unit of the final products is sold, the fees are known, every cost is known and every holder of a sale is an accepted participant.
- **Completing the project is not required**, and guarantees nothing more: it stops recomputation, sales and reviews. Payouts are final once everything is sold, every sale is recorded and no contribution changes anymore.

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Jita price of the deposit day | Jita sell orders (ESI); a type missing from the cache is read on demand | Cache synced every hour; read at submission, then frozen |
| Paid price | Wallet transaction of one of the owner's characters (ESI), or "Manual purchase" | When linked; wallet read again on a 5-minute tick |
| Cost of a linked job | Character industry jobs (ESI) | Jobs sync every 30 minutes, until the project is completed |
| "Estimated Value" | Your entry | At submission |
| Courier reward | Character contracts (ESI) | When the project's courier is displayed |
| Sales | Entered by the owner or an admin ("Record Sale", attached transaction or contract) | When entered |
| "Broker Fee %", "Sales Tax %" | Project setting, copied from the owner's fees at creation | Until the project is completed |
| Contract fees | Seller's wallet journal | With the wallet sync |

## Example

Example values, rounded, not taken from the game. Three participants: Pilot A (owner), Pilot B (admin), Pilot C. "Broker Fee %" 2, "Sales Tax %" 4.

Approved contributions:

| Participant | Contributions | Costs Engaged |
|---|---|---|
| Pilot A | linked purchase 280,000,000 ("paid price (wallet)") + linked job 20,000,000 ("real cost (ESI)") | 300,000,000 |
| Pilot B | "Material", 1,000 units × 100,000 ("Jita price of the deposit day") | 100,000,000 |
| Pilot C | "BPC", entered value | 100,000,000 |
| Total | | 500,000,000 |

Everything is sold on the market: Pilot A records 400,000,000 (seller: their character), Pilot B records 200,000,000.

- revenue 600,000,000; broker fee 12,000,000; sales tax 24,000,000; net revenue 564,000,000
- margin = (564,000,000 − 500,000,000) ÷ 500,000,000 = 12.8%

| Participant | Share % | Profit Part | Payout Total | Held revenue | Balance |
|---|---|---|---|---|---|
| Pilot A | 60% | 38,400,000 | 338,400,000 | 400,000,000 × 0.94 = 376,000,000 | +37,600,000 |
| Pilot B | 20% | 12,800,000 | 112,800,000 | 200,000,000 × 0.94 = 188,000,000 | +75,200,000 |
| Pilot C | 20% | 12,800,000 | 112,800,000 | 0 | −112,800,000 |

Rounding gap: 0. Plan: Pilot B sends 75,200,000 to Pilot C (largest debtor first), then Pilot A sends 37,600,000 to Pilot C.

Had Pilot A recorded both sales, they would hold 564,000,000 (balance +225,600,000) and the plan would be: Pilot A sends 112,800,000 to Pilot B and 112,800,000 to Pilot C.

## When the figure is unknown

Missing data makes the result **unknown**, never 0. An unknown amount shows as "—" in a dashed box (tooltip "Unknown value").

| Missing data | Effect | On screen |
|---|---|---|
| Jita price of a material at submission | The contribution is refused | "No market price for type N: no Jita price for the deposit day." |
| ESI cost of a linked job | "Job Install" value unknown, so total cost, margin and **every** payout unknown, not only the installer's | "An approved contribution has no known price: …"; transfers: "A cost is unknown or the total cost is zero: …" |
| No sale | Net revenue, margin, payouts unknown | "Awaiting sales" |
| Project fees, with a market sale | Net revenue, margin, payouts unknown | "Undistributable distribution: …" (see Traps); transfers: "The broker fee or the sales tax of the project is unknown: …" |
| Fees of an attached contract, missing from the wallet journal | Net revenue, margin, payouts unknown | Same as the row above |
| Total cost at 0 | Margin and payouts unknown | "Undistributable distribution: …" |
| Final product still unsold | Provisional payouts, transfers not computed | "Provisional margin"; "Some products are still unsold: …" |
| Holder of a sale no longer an accepted participant (left, removed, deleted account) | Transfers not computed | "A sale was recorded by someone who is no longer an accepted participant: …" |

## Traps

- **A sale made by another participant is counted for whoever records it.** Only the owner and admins record sales, and only for their own characters. If a member sold, the recorder is counted as holding that money, and the plan asks them to send ISK they do not have.
- **Alone on a project: "I supplied it" then a linked purchase count twice.** On the same line, the declared contribution (at the Jita price) and the purchase's (at the paid price) are both approved and both in the cost. In a group, a reviewer rejects the declared one ("Duplicate of an ESI contribution", "Reject"). Alone on the project, the "Contributions" tab is hidden and that button cannot be reached: the only way to remove the duplicate is to delete the purchase in "Purchases".
- **The project's broker fee and sales tax apply to all market revenue**, at the rates copied from the owner, whoever the seller is, whatever their skills or the kind of sale (a sale to a buy order pays no broker fee in game).
- **"Undistributable distribution" also shows when the fees are unknown.** The message says "no approved contribution has a cost", but the cause can be an empty "Broker Fee %" or "Sales Tax %".
- **The transfers message "The broker fee or the sales tax of the project is unknown"** also shows when it is the fees of an attached contract that are missing.
- **"Total Engaged Costs" is not the project cost.** This footer of the "Contributions" tab adds up every contribution, pending and rejected ones included. The project cost is "Approved Value".
- **"Method" is wrong in two cases.** A "Courier" contribution shows "entered value", whereas its value is the contract reward. A manual purchase shows "paid price (wallet)". Under any "Material" value, even a paid price, the price badge says Jita P5.
- **A participant with no approved contribution** does not appear in "Profit Distribution", but may have to send ISK if they recorded sales.
- **An admin who recorded sales, then leaves the project or is removed from it, blocks the transfers**, even once the project is completed.

## Technical note

Declared value: `ProjectContributionService::submit()` (`resolveMaterialValue()`, constant `DEPOSIT_DAY_PERCENTILE` = 0.05), price from `JitaMarketService::getCheapestPercentilePrices()`. Detected contributions: `DerivedContributionService::refreshLine()` (`paidPriceOf()`, `installCostOf()`) and `syncCourier()`. Distribution: `ProjectDistributionService::calculateDistribution()` and `buildMemberDistributions()`. Transfers: `ProjectSettlementCalculator::calculate()` (`heldCentsByParticipant()`, `dueCentsByParticipant()`, `unavailableReason()`), `TransferPlanner::plan()` (constant `MAX_EXACT_PARTICIPANTS` = 12). Reference: `ProjectPaymentReference::of()`.
