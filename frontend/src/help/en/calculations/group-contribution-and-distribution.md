# Contribution value and revenue distribution of a group project

In a group project, each participant brings materials, jobs, BPCs, a line rental or a delivery. This page explains how each contribution is valued, why an approved contribution no longer moves, and how the net revenue is shared between participants.

Principle: **a contribution is worth a real price, never a BOM estimate**. When that price does not exist, the value is **unknown** (shown as "—", tooltip "Unknown value"), never 0.

## 1. Value of a contribution, by type

| Contribution type | Value | Source | Freshness |
|---|---|---|---|
| **Materials bought by the owner** (derived contribution) | **Paid price**: sum of the total price of the purchases linked to the line | ESI wallet transaction linked to the line, or a purchase entered by hand (quantity and unit price) | Purchases are linked or entered; the wallet is re-read every 5 minutes for characters whose ESI cache has expired, or on demand with "Refresh wallet" (same condition). In a group project the purchase is first a contribution proposal (see below) |
| **Materials declared by a participant** | **Jita deposit-day price** | 5th percentile of the Jita station sell orders (see below) | Read at submission, then frozen |
| **Job installation** (derived contribution) | **Real ESI cost** of the linked job, one contribution per participant who started the job | The `cost` field of the job returned by ESI | Jobs synced every 30 minutes |
| **Declared job installation** | **Entered value** (greater than 0) | The participant | Frozen at entry |
| **BPC** | **Entered value** (greater than 0) | The participant | Frozen at entry |
| **Line rental** | **Entered value** (required); a suggestion from the participant's rate card is offered | The participant and their rate card | Frozen at entry |
| **Courier** (derived contribution) | **Reward** of the linked courier contract, counted only once the contract is delivered | Courier contract linked to the project | Re-read at every sync; if the contract is no longer delivered, the contribution disappears |

A declared material **takes no entered value**: the deposit-day price always applies. If Jita has no price for that type at deposit time, the deposit is refused ("No market price for type…"): there is no contribution without a price, hence no value set to 0.

### The Jita deposit-day price

It is the **weighted average price of the cheapest 5 % of the volume** of Jita sell orders:

1. the known sell orders are sorted from cheapest to most expensive (the order book keeps up to 20 per type);
2. the cheapest units are taken until 5 % of the total volume of these orders is reached;
3. the price is the average of those units, weighted by their quantity; if 5 % of the volume is less than one unit, it is the price of the best order;
4. contribution value = price × contributed quantity.

Freshness: the Jita market is re-synced every hour and the order book stays cached for 2 hours. A type missing from the book is read on demand (5-minute cache). The price is **frozen on the contribution at deposit**: the review does not recompute it, and a later move of the Jita price changes nothing.

### Derived contributions: computed, not declared

Linked jobs produce contributions that are **approved automatically**, and so do the owner's linked purchases when they are the only reviewer of the project. In a group project, a linked purchase gives a **contribution proposal** (see below). All of them are recomputed after every link change. If a participant had declared the same contribution (same line, same type), the declaration still pending is rejected and replaced. A declaration that was already approved stays, and both count: it is a **duplicate contribution**, which reviewers can reject.

A job started by another participant is counted in their name, at the cost paid in game. If ESI gives no cost for that job, the value of the contribution is **unknown**.

### Contribution proposal and delivery confirmation

In a project where another owner or administrator can review, a purchase linked to a material line does not count right away: it creates a **contribution proposal**, pending, unverified, at the paid price. It enters the served quantity of the line and the distribution only at its **delivery confirmation**, in one of two ways:

- **By the project container.** The proposal is confirmed when the stock of the type in the container named on the project covers it. The proposals of a line are taken from the oldest to the most recent, with a running total that starts at the quantity already served, in strict order: the first one that is not covered stops the line. *Example: two proposals, 60 (the oldest) then 50, and 100 units in the container: the first is confirmed, the second waits (60 + 50 exceeds 100).* The contribution is then marked **verified**, with no reviewer. A contribution declared by hand is never confirmed this way, nor is a job line.
- **By hand.** Another owner or administrator clicks "Confirm delivery" in the "Contributions" tab. The contribution counts but stays **unverified** and carries the "Delivery confirmed by hand" badge. You cannot confirm your own proposal. Use it, for example, when the goods were already consumed by a started job and so left the container.

The container confirmation is attempted after the assets sync of the owner's corporation (that of their main character) and after an automatic link of a wallet purchase. A purchase entered by hand does not trigger it: its proposal waits for the next sync.

A participant who is not the owner links the wallet purchases of their own characters themselves, from the "Purchases" button of a line. Their purchase creates their own contribution proposal, always pending (even if the owner is the only reviewer), at the price they paid, and follows the same delivery confirmations. Entering a purchase by hand stays reserved to the owner. A participant deletes their own purchases, the owner deletes any purchase of the project.

When the container cannot be read, **nothing is confirmed** and the proposal stays pending: never a fallback to "zero" or to "delivered". The cases: no container name on the project, owner without a corporation, no director of the corporation connected with the assets scope, corporation assets never synced, no division visible to the owner (hidden division), container not found.

The "Refresh wallet" button, in the owner's "Purchases" window, starts reading the wallet of your characters (wallet scope, valid authorization) without waiting for the next 5-minute pass, but **only for those whose ESI cache has expired**. If none has, it makes no ESI call and tells you the time of the next read.

## 2. Why an approved contribution no longer moves

- A declared contribution is **pending** until an owner or an administrator of the project reviews it. They cannot review their own, unless the owner is the only possible reviewer (solo project): the contribution is then approved at deposit.
- Once **approved**, a contribution can be neither approved nor rejected again: only an approved contribution that duplicates a derived one can still be rejected. An approved declared contribution cannot be deleted; only derived contributions follow their linked purchases and jobs.
- A completed project is no longer reviewed: no review, and derived contributions are no longer recomputed.

Reason: the coverage of each BOM line and everyone's share rest on the approved contributions. Changing them afterwards would shift the payout of every participant without them seeing it.

On approval, the contributed quantity counts in the served quantity of the line. A contribution that would exceed the remaining quantity of the line is refused, and a participant cannot have two pending or approved contributions of the same type on the same line.

## 3. Revenue distribution

Only **approved** contributions count. The terms are those of the distribution screen.

1. **Total revenue** = sum of the total price of the project's sales (market and contract).
2. **Fees**: the **broker fee** and the **sales tax** apply to **market** sales revenue only, at the rates stored on the project. A **contract** sale pays neither; its real fees are read from the seller's wallet journal.
3. **Net revenue** = total revenue − broker fee − sales tax − contract fees.
4. **Production cost** = sum of the value of all approved contributions.
5. **Margin** = (net revenue − production cost) ÷ production cost. It is the profit relative to the cost, as everywhere in the application.
6. For each participant, with C = the sum of the value of their approved contributions:
   - **share** = C ÷ production cost;
   - **profit** = C × margin;
   - **payout** = C + profit = C × (1 + margin).

The payouts add up to the net revenue: everyone gets back what they committed, plus the same margin as the others. A participant removed from the project keeps their contributions and their payout.

### Example (round numbers, purely illustrative)

Two participants, A (owner) and B. Example fees: broker fee 3 %, sales tax 4 %.

| Approved contribution | Value |
|---|---|
| A: materials bought (paid price) | 60,000,000 ISK |
| A: job installation (ESI cost) | 10,000,000 ISK |
| B: declared materials (Jita deposit-day price) | 30,000,000 ISK |

- Production cost = 60 + 10 + 30 = **100,000,000 ISK**.
- One market sale of **150,000,000 ISK**: broker fee 4,500,000 + tax 6,000,000 = 10,500,000 of fees, so net revenue = **139,500,000 ISK**.
- Margin = (139.5 − 100) ÷ 100 = **39.5 %**.

| Participant | Contributed (C) | Share | Profit (C × 39.5 %) | Payout |
|---|---|---|---|---|
| A | 70,000,000 | 70 % | 27,650,000 | 97,650,000 |
| B | 30,000,000 | 30 % | 11,850,000 | 41,850,000 |
| Total | 100,000,000 | 100 % | 39,500,000 | 139,500,000 |

If A sold everything alone, the 139,500,000 ISK sit with A: one **transfer** of 41,850,000 ISK to B settles the distribution.

## 4. The "unknown" cases

The application shows "—" or a banner rather than a plausible number in each of these cases.

| Missing input | What becomes unknown | What the screen says |
|---|---|---|
| No sale recorded | Net revenue, margin, profit, payouts | "Awaiting sales"; committed costs and shares are still computed |
| Project broker fee or sales tax rate missing, with a market sale | Net revenue, margin, payouts | Unknown fees |
| Fees of a sale contract missing from the seller's wallet journal | Net revenue, margin, payouts | Unknown fees |
| An approved contribution without a value (ESI cost of a job missing, courier reward unknown) | Production cost, shares, profit and payouts of everyone; a participant's contributed cost stays known if all their contributions have a price | "An approved contribution has no known price…" |
| Production cost equal to 0 (no approved contribution, or contributions at 0 ISK) | Margin, shares, payouts | "Undistributable distribution: no approved contribution has a cost" |

From the first sale, the margin is computed on what is already sold: the profit is shown as **provisional**, and often negative while products remain unsold. The projected margin values the unsold products at the price of the most recent sale, net of the same fees; a product that was never sold makes it unknown.

### Transfers

Each seller keeps the net revenue of their sales; the application computes the smallest number of transfers so that everyone holds their payout. Transfers are **unavailable**, with their reason, as long as: no sale exists, a product remains unsold, fees are unknown, a cost is unknown, or a seller is not an accepted participant of the project. The recipient ticks the transfer as "received"; receipt is also recognised when the wallet journal shows a donation of the right amount.

## Glossary terms

Contribution, derived contribution, contribution proposal, delivery confirmation, deposit-day price, duplicate contribution, payout, undistributable distribution, awaiting sales, transfer, margin.
