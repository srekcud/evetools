# Running a group project as the owner

This guide follows a project from start to finish, in the order the owner acts: create, publish, invite, accept, review contributions, read the distribution, record sales, transfer and close. Each step names the screen and the button as they appear.

Solo and group are the same project: a group project is a project that other players have been accepted into. To build a project alone, from the analysis to the sale, see [Build a T2 item from A to Z](/help?page=playbooks/t2-product-a-to-z).

> Some labels of the application stay in English in both languages (**Approve**, **Reject**, **Promote**, **Kick**, **Record Sale**, **Save**, **Add Contribution**). Some French labels appear without accents. This guide quotes each label as the screen shows it.

## What each role can do

| Role | Can |
|---|---|
| **Owner** | Everything an admin does, plus promoting an admin (**Promote**), linking the jobs and the purchases of the project, and deleting the project while it is not completed. The owner can neither leave the project nor be removed. |
| **Admin** | Edit the project, change its status, accept or reject a request, review contributions, record and correct sales. An admin does not manage another admin. |
| **Member** | Submit contributions and follow their share. |

## 1. Create the project

1. Side menu: **Industry**, tab **Projects**.
2. Click **New Project**: the **New Group Project** window opens.
3. Add the items to produce (search, or **Bulk Paste**), set ME, TE and runs, then confirm with **Create Project**.

The details of the options (blacklist, structures, invention, templates) are in the guide [Build a T2 item from A to Z](/help?page=playbooks/t2-product-a-to-z). Two settings matter for a group:

- **Private project** is ticked by default. A private project does not appear in the corporation discovery and **nobody can join it with the link** (see step 3).
- **Corp Containers**: if you name containers, admins can check material contributions against their content (**Verify All** button, **Sourcing & jobs** tab).

The project starts in the **Draft** status, and you are registered in it as **Owner**.

The sales fees of the project (**Broker Fee %** and **Sales Tax %**) are taken from your skills and standings when the project is created. If they could not be read, they stay **unknown**: the pencil icon (tooltip **Edit**) opens **Edit Project**, where you enter them. The net revenue and the payouts stay unknown while they are missing.

## 2. Publish

In the project header, the **Publish** button moves the project from **Draft** to **Published**. A **Published**, non-private project appears in the **Available** tab (labelled "same corp") of the members of your corporation: they join it without approval.

The life cycle only goes one way:

| Status shown | Button to get there |
|---|---|
| **Draft** | (creation) |
| **Published** | **Publish** |
| **In Progress** | **Start** (from **Draft**) or **Move to In Progress** (from **Published**) |
| **Selling** | **Move to Selling** |
| **Completed** | **Move to Completed**, then **Complete** in the confirmation window |

There is no way back. Publishing is not mandatory: **Start** jumps straight from **Draft** to **In Progress**.

## 3. Invite

The invite link looks like `/industry/join/<code>`. You can copy it in two ways: the **Share Link** button of the header (it is called **Invite** while you work alone), or **Copy Join Link** and the **Invite Link** card in the **Team** tab.

The link only works when **both** conditions are met:

- the project is **not private**: untick **Private project** in **Edit Project**, then **Save**. Otherwise the person lands on **Unable to Join** with the message "Project not found" (an API message). Setting the project back to private later keeps the participants already accepted;
- the status is **Published** or **In Progress**. A **Draft**, a **Selling** or a **Completed** project refuses new members.

When the link is opened:

- a player of **your corporation** is accepted at once (the card says so: "Same-corp members are auto-accepted.");
- any other player sees **Request Sent** and waits for your approval ("Other players will need approval."). Until the request is accepted, they cannot open the project.

The **Team** and **Contributions** tabs are hidden while you are alone, with no pending request and no contribution to review. They appear as soon as a request arrives.

## 4. Accept the members

1. **Team** tab, **Pending Requests** block: the table shows **Character**, **Corporation**, **Requested** and **Via** (**Short Link**).
2. **Accept** adds the person as a **Member**. **Reject** removes the request. The person can ask again with the link, and the new request goes back to pending, even if they are from your corporation.
3. In the member list, **Promote** (owner only) makes an admin, **Demote** brings them back to member, **Kick** removes a member from the project.

A removed member keeps their contributions in the project, but their line reservations are released. A member can leave the project themselves with **Leave** as long as they have submitted no contribution.

## 5. Start and collect contributions

Move the project to **In Progress** when sourcing begins (**Start** or **Move to In Progress**). Contributions stay possible in every status until **Completed**.

Under the header, the **Project phases** rail (**Prepare**, **Supply**, **Produce**, **Sell**, **Close**) and the **Next action** card tell you what to do next: review contributions, source a material, launch a job, move to selling, record the sales, close.

A member can first reserve a share of a line with **I'll handle it** (**Release** gives it back). They contribute from **Add Contribution** (**Contributions** tab) or from the **Contribute** button of a line in the **Sourcing & jobs** tab. The types are **Material**, **Job Install**, **BPC** and **Line Rental**. The value of a contribution is decided like this:

| Contribution | Value |
|---|---|
| **Material** submitted by hand | Jita price of the deposit day (5th percentile), read when it is submitted and kept as is at the review. With no Jita price, the submission is refused. |
| **Job Install**, **BPC** | Entered value, above 0 ISK. |
| **Line Rental** | Entered value. A suggestion from your rate schedule is shown when it can be computed. |
| Purchase or job linked by the owner | **paid price (wallet)** for a material, **real cost (ESI)** for a job. Approved automatically, **Auto-detected** badge. |

Members do not link their own jobs and purchases: only the owner does. A linked job launched by another participant is counted to them as a job install contribution.

A member can only submit one pending or approved contribution per line and per type, and never more than the remaining quantity of the line.

## 6. Review the contributions

1. **Contributions** tab, **Actions** column: **Approve** or **Reject** on each row with the **Pending** status.
2. **Approve** counts the contribution in the fulfilled quantity of the line and in the cost of the project. **Reject** does not change the line.
3. An already approved contribution carrying the **Duplicate of an ESI contribution** badge (the same contribution was later detected by the ESI) still offers **Reject**.

Rules to know:

- you do not review your own contribution, unless you are the only person able to review (an owner with no other admin);
- only **approved** contributions enter the distribution;
- a **Pending** contribution shows in the tab counter and in the checks before closing;
- no review is possible once the project is **Completed**.

A contribution with no known price makes the totals that include it unknown, never a partial total (message "A contribution has no known price").

## 7. Read the distribution

**Finances** tab. The blocks, from top to bottom: **From revenue to profit**, the financial summary, the sales forecast, the member table, **Transfers between members** and **Discord recap**.

The member table gives, per person:

| Column | Content |
|---|---|
| **Costs Engaged** | Sum of their approved contributions. |
| **Share %** | Their contributions divided by the **Total Project Cost**. |
| **Profit Part** | Their contributions multiplied by the margin of the project. |
| **Payout Total** | Their contributions plus their share of the profit. |

The margin of the project is the profit divided by the production cost: (**Net Revenue** minus the **Total Project Cost**) divided by the **Total Project Cost**. The **Net Revenue** is the total revenue minus the **Broker Fee**, the **Sales Tax** and the real fees of the linked contracts. The detailed formulas are in [Profit and margin: how EVE Tools calculates them](/help?page=calculations/profit-and-margin) and the cost of a job in [Job cost, structure and rig bonuses, ME and TE](/help?page=calculations/job-cost-and-bonuses).

The "unknown" cases of this screen:

- **Awaiting sales**: no sale recorded, so no net revenue, no margin, no payouts. A **Provisional margin** and a margin projected at the last sale price appear when products remain unsold and their last price is known; a product that was "never sold" has no projection;
- **Undistributable distribution**: no approved contribution has a cost;
- an unknown broker fee or sales tax makes the net revenue, the margin and the payouts unknown. Only the revenue of market sales requires them;
- an approved contribution with no price: production cost, profit and payouts unknown.

## 8. Move to selling and record the sales

When production is finished, **Move to Selling**. Sales can be recorded in every status until **Completed**, and only the owner and the admins do it.

**Sales** tab:

1. **Record Sale** opens the **Record a Sale** form: the item (the list shows "left to sell"), **Quantity**, **Unit Price (ISK)**, the channel (**Market order** or **Contract**), **Venue** and the **Seller**. Confirm with **Save**.
2. The **Transactions to link** block lists the wallet transactions of your characters that match a product of the project. **Record as sale** turns one into a sale; the **Possible duplicate** mention flags a sale already recorded with the same quantity and the same unit price.
3. The **Link a contract** block lists the finished item exchange contracts of your characters. **Link** freezes the quantity, the price and the date from the contract; the real fees of the contract are read from the wallet journal.
4. **Edit** on a row opens **Correct a Sale**, **Delete** removes the sale: the distribution is recalculated.

The **Seller** matters: each seller keeps the net revenue of their sales, and the transfers follow from it. A sale recorded by someone who is no longer an accepted participant blocks the computation of the transfers.

The transaction and contract suggestions only show what dates from after the creation of the project.

## 9. Transfers and closing

The transfers are computed when **every** product is sold and the fees and costs are known. While a product is unsold, or a fee or a cost is unknown, the **Transfers between members** block says why (for example "Some products are still unsold").

1. In **Transfers between members**, copy the **Payment reference** with **Copy** and put it in the reason of each in-game transfer.
2. The recipient ticks **Received**. The box also ticks itself when their wallet journal shows the transfer with the reference and the full amount: **Verified in the wallet journal** mention. You never tick on the recipient's behalf.
3. If there is nothing to transfer, the block says so: everyone already holds their payout. A rounding gap is absorbed by the largest payout.
4. **Copy for Discord** (**Discord recap** block) produces a message ready to paste. Nothing is posted for you.

To complete:

1. Click **Move to Completed**: the **Complete Project** window opens.
2. Read **Before completing**: contributions awaiting review, **Everything is sold** or **Unsold products**, **Final margin** (or "Final margin unknown: a cost or a fee is missing"), **Transfers ticked as received**. These checks inform, they **do not block** the closing.
3. **Complete** freezes the accounts. It cannot be undone: no more contributions, reviews, sales or fee changes, and the project can no longer be deleted. Line reservations are released.

Once the project is **Completed**, the **Sourcing & jobs** tab shows the surplus of materials.

## Known pitfalls

- **The link does not work**: the project stayed private (the default), or it is still a **Draft**. Check both.
- **A member from another corporation sees nothing**: their request is pending, they only open the project after your **Accept**.
- **The Team and Contributions tabs are missing**: they are hidden while you are alone, with no request or contribution to review.
- **Payouts "unknown"**: look for a missing fee in **Edit Project**, or for an approved contribution with no price.
- **Not everyone can leave**: the **Leave** button disappears as soon as a member has submitted a contribution; **Kick** stays at your disposal.
