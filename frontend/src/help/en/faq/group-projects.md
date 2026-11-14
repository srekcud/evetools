# FAQ: group projects

## Who can join a project, who approves, and what does "approved" mean?

**Joining.** You join a project with its invitation link, as long as the project is not private and is published or in progress. A private project answers as if the link did not exist.

- If your corporation is the owner's, you are accepted right away.
- Otherwise, your request stays **pending** until the owner or an admin accepts it.
- Only the owner can appoint an admin.
- A member who was removed, or who left on their own, comes back only through a new pending request.

**Approving.** Every contribution (materials, job installation, BPC, line rental) is submitted for approval: pending, approved or rejected. The owner and the admins review, but nobody reviews their own contribution, except the owner when they are the only possible reviewer. In a solo project, your contributions are therefore approved as soon as you submit them.

**Approved = locked, except corrections before the sale.** An approved contribution never decreases. The exceptions are a contribution you declared as a duplicate of an automatically detected one, which the reviewers can reject, and a material contribution on a material line, which an owner or admin can reopen (it goes back to pending) until the project reaches the selling phase. When the project moves to **Completed**, all the accounts are frozen: no more contributions, reviews, sales or fee changes.

## How is a contribution valued?

The value of a contribution is:

- the **price paid**, for a purchase linked to the project;
- the **ESI cost**, for a job linked to the project (one contribution per participant who started the job);
- the **Jita price of the contribution day** (5th percentile), for a material declared without a linked purchase.

When the price is unknown, the value of the contribution is unknown, never 0. See also [Numbers that look wrong](/help?page=faq/numbers-that-look-wrong).

## How is the revenue shared, and when are payouts exact?

The owner or an admin records the sales of the project. The calculation goes like this:

1. **Net revenue** = sales revenue − broker fee − sales tax − contract fees. A sale made by contract pays no broker fee and no sales tax; only the real contract fees count.
2. **Margin** = (net revenue − total cost of the approved contributions) ÷ total cost.
3. **A member's profit share** = their contributed costs × the margin.
4. **Payout** = their contributed costs + their profit share, that is `contributed costs × (1 + margin)`.

While not everything is sold, the margin shown is provisional: unsold items are valued at the price of the most recent sale.

Payouts are **unknown** in three cases: no sale recorded, a total contribution cost of 0, or unknown fees or costs. They are exact when all the products are sold and the fees and contribution values are known.

The in-game transfers are computed at that point: each seller keeps the net revenue of their sales, and the smallest number of transfers settles the differences. Put the project's payment reference (`IP-` followed by 6 characters) in the reason of each transfer. The recipient ticks "received" on their side.
