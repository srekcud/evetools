# Take part in a group project as a member

This guide follows a project in the order a member acts: join, see what is missing, declare a contribution, follow its approval, read your share, collect your money. Each step names the screen and the button as they appear in the app.

Solo and group are the same project: a group project is a project that other players have been accepted into. The owner and the admins run the project, see [Running a group project as the owner](/help?page=playbooks/group-project-owner). For the production itself, see [Build a T2 item from A to Z: from the analysis to the real margin](/help?page=playbooks/t2-product-a-to-z).

> Some labels of the app are in English in both languages (**Add Contribution**, **Material**, **Job Install**, **BPC**, **Line Rental**, **Pending**, **Approved**, **Rejected**, **Approve**, **Reject**, and the column headers of the **Contributions** and **Finances** tables). They are quoted here as they appear.

## What a member can do

| A member can | A member cannot |
|---|---|
| Reserve a line (**I'll handle it**) and contribute | Review contributions (**Approve**, **Reject**) |
| Read the contributions, the distribution, the transfers and the **Journal** of the whole project | Record or correct a sale |
| Tick **Received** on a transfer addressed to them | Link a job or a purchase to a line, change the status, edit the project |
| Leave the project as long as they have no contribution | Accept or remove other members |

Other participants see your main character, never one of your alts.

## 1. Join the project

You need an EVE Tools account signed in with EVE SSO. There are two ways in.

### With the invitation link

The owner sends you a link of the form `/industry/join/<code>` (the `/join/<code>` form redirects to the same page). If you are not signed in, the app sends you to the sign-in page, then to the dashboard: **open the link again after signing in**, it is not replayed automatically.

When you open it, what you see depends on your case:

| Your case | Result |
|---|---|
| Your main character is in the owner's corporation | You are accepted at once and the project opens. |
| You are in another corporation | **Request Sent** screen: the request waits for approval from a project admin (next step). |
| The project is private, or the code is unknown | **Unable to Join** screen with the message "Project not found" (an API message). |
| The project is neither **Published** nor **In Progress** (**Draft**, **Selling**, **Completed**) | **Unable to Join** with "This project is no longer accepting new members". |
| You are already accepted | The project opens. |
| Your request is already pending | You land on the project list, with no message. |

A **Private** project is the owner's default setting: if you get "Project not found", ask the owner to untick **Private project** in **Edit Project**.

### From the Available tab

Side menu: **Production** › **Industry**, **Projects** tab, then the **Available** tab ("same corp" mention). It lists the **Published**, non-private projects of your corporation, except your own and those where you already have a request. An **In Progress** project is not listed there: it can only be joined with the link. **Join Project** accepts you without approval and opens the project.

### Waiting for approval

An admin or the owner sees your request in the **Team** tab (**Pending Requests** block) and clicks **Accept** or **Reject**. Until it is accepted you cannot open the project, and it does not appear in **My Projects** (which only lists the projects where you are accepted).

The **Request Sent** screen says "You will be notified once your request is accepted", but accepting sends no notification inside the app: come back and look. Open the link again: if you are accepted the project opens, if you are still pending you land on the project list. A rejected request disappears; the same link lets you make a new one.

## 2. See what is missing

Open the project from **My Projects** (the pills filter by status: **Draft**, **Published**, **In Progress**, **Selling**, **Completed**). Under the header there are two landmarks:

- the **Project phases** band: **Prepare**, **Supply**, **Produce**, **Sell**, **Close**, each one **Done**, **In progress**, **Blocked** or **Upcoming**. A blocked phase says why: "N materials to cover" or "N jobs to finish";
- the **Next action** card. For a member it offers:

| Card | What it says | Button |
|---|---|---|
| **Source a material** | "{quantity} {item} are still missing to cover the BOM": the line that lacks the most | **Contribute** (opens the add form on that line) |
| **Launch a job** | The runs of a product are left to launch, or "the remaining runs are unknown" | **See the jobs** |
| **Nothing to do for you** | The project is moving along without you: status changes, sales and closing belong to the owner and the admins | none |

**Source a material** only appears on a **Published** or **In Progress** project.

### The Sourcing & jobs tab

- **Materials to Source**: one row per material with **Required**, **Fulfilled**, **Remaining**, **Progress**, **Reserved by** and **Est. Cost**. When the project names containers, each row shows the container stock (**Verified**, **Partial**, **Missing from container**, **Cannot be checked**).
- **Jobs to Launch**: the components, blueprints and final products to produce, with their runs and status.
- **Fulfilled** only moves when a contribution is **approved** (or when a contribution detected by the ESI is counted). A pending contribution does not move it.

On each open line there are three buttons:

1. **I'll handle it** reserves a quantity (the **Quantity to handle** field, at most what is still free: "N free" shows the free part). Your reservation shows under your name with "(you)". **Release** removes it.
2. **Contribute** opens the add form on that line (step 3).
3. A reservation informs the others without blocking them: another member can contribute on the same line. It disappears when the line is complete, when you leave or are removed, or when the project is **Completed**. An approved contribution reduces it by its quantity.

The **Companion** button in the header opens a detached window with **To assign** (free lines, **I'll handle it**), **My lines to deliver** (**Deliver** opens the project on the add form of the line, **Release**) and **My jobs (ESI)**.

## 3. Declare a contribution

1. **Contributions** tab, **Add Contribution** button (it disappears once the project is **Completed**). You can also click **Contribute** on a line, or on the **Next action** card: the form then opens already set on the line.
2. In the **Add Contribution** window, choose the **Contribution Type**: **Material**, **Job Install**, **BPC** or **Line Rental**. Opened from a line, the window picks the type: **Material** for a material, **BPC** for an invention or copy line, **Job Install** for the other jobs.
3. Choose the **Item** (**Material** type) or the **BOM Step** (the other types) in the "Select an item..." list. It only offers lines that are not complete and shows "(N remaining)".
4. Set the **Quantity**. Opened from a line, it takes your reservation, otherwise the remaining quantity. It cannot exceed the remaining quantity of the line.
5. Fill in the value if the type requires it (table below), then an optional **Notes**.
6. Click **Submit Contribution**. The window reminds you: "Your contribution will be reviewed by a project admin."

| Type | Value of the contribution |
|---|---|
| **Material** | No entry. The contribution is valued at the **Jita price of the deposit day (5th percentile)**, read from the market when you submit and kept as is at review. With no Jita price for that item, the submission is refused ("No market price for type N: no Jita price for the deposit day.", N being the type id). |
| **Job Install**, **BPC** | **Estimated Value (ISK)**, entered by you, strictly above 0: otherwise the window shows "Enter a value above 0 ISK to submit this contribution." |
| **Line Rental** | Entered value. When it can be computed, **Suggested from your rate schedule** appears and pre-fills the value; otherwise **No suggestion** says why (no rate category, none of your jobs linked to the line, a job missing from the synced jobs). |

Submission rules, with the API message when it refuses:

- one **Pending** or **Approved** contribution per line and per type for you ("A contribution of this type is already pending or approved for this item"): a **Rejected** contribution lets you submit a new one;
- never more than the remaining quantity of the line ("The contribution exceeds the remaining quantity of the line");
- in a group project an intermediate component is not declared as **Material**: it goes through its job;
- the **Courier** type cannot be submitted: it comes from the courier contract linked by the owner.

### What you do not declare by hand

Some contributions are counted by the ESI and approved at once, with the **Auto-detected** badge (Method column: "real cost (ESI)" or "paid price (wallet)"):

- **Your manufacturing and reaction jobs.** After your jobs sync (every 30 minutes), a job of one of your characters, started since the project was created, that matches a line of the project is linked on its own and counted in your name as **Job Install**, at the install cost paid in game. A cost the ESI does not give stays **unknown**, never 0. Invention and copy lines are not linked by the ESI: declare them as **BPC**.
- **Wallet purchases.** Only the owner links them, and they count in the owner's name. A material you bought yourself is declared as **Material** and valued at the Jita price of the deposit day, not at the price you paid.

If you had already declared a pending **Job Install** on the same line, the detected contribution replaces it: yours becomes **Rejected** with the note "Replaced by the ESI/wallet contribution". If it was already **Approved**, both count and the **Duplicate of an ESI contribution** badge appears: a reviewer can reject the duplicate.

## 4. Follow the approval

Your contribution shows in the **Contributions** tab with the **Pending** status and the **Unverified** badge. Columns: **Character**, **Type**, **Item / Description**, **Qty**, **Valuation**, **Method**, **Status**, **Actions**.

- An admin or the owner clicks **Approve**: the status becomes **Approved**, the quantity counts in **Fulfilled** and the value in the project cost.
- Or **Reject**: the status becomes **Rejected**, the line does not change, you can submit again.
- Neither the approval nor the rejection sends you a notification. Follow the status in **Contributions**: the type and status filters, and the summary bar (**Total Engaged Costs**, **Approved Value**, **Pending Value**, with the **Approved**, **Pending**, **Rejected** counters) give the state of the whole project. The **Journal** tab keeps a **Contribution approved** or **Contribution rejected** line with the character, the item and the quantity.
- You cannot edit or withdraw a contribution yourself: the **Actions** column only holds buttons for reviewers. A pending contribution is sorted out with an admin (who rejects it); an approved contribution no longer decreases, except a duplicate of an ESI contribution.
- Only **approved** contributions enter the distribution. No contribution is accepted once the project is **Completed**.
- A contribution with no known price makes the totals that include it unknown, never a partial total ("A contribution has no known price").

## 5. Read your share

**Finances** tab. From top to bottom: **From revenue to profit**, the **Financial Summary**, the **Sell forecast**, the members table with its ring (the legend gives each member's **Share %**), **Transfers between members** and **Discord recap**. You see the distribution of every participant, not only yours.

In the members table, click your row to unfold the detail of your contributions by type (**Materials**, **Job Install**, **BPC**, **Line Rental**, **Courier Costs**). The columns:

| Column | Content |
|---|---|
| **Costs Engaged** | The sum of your approved contributions. |
| **Share %** | Your contributions divided by the **Total Project Cost**. |
| **Profit Part** | Your contributions multiplied by the project margin. |
| **Payout Total** | Your contributions plus your share of the profit. |

The margin is the profit divided by the production cost. The formulas are in [Profit and margin: how EVE Tools calculates them](/help?page=calculations/profit-and-margin) and the cost of a job in [Job cost, structure and rig bonuses, ME and TE](/help?page=calculations/job-cost-and-bonuses).

Fictional example, in round values: 100 M ISK of approved contributions out of a total cost of 400 M ISK, with a 10 % margin. **Share %** is 25 %, **Profit Part** 10 M ISK, **Payout Total** 110 M ISK. A -10 % margin would give a **Payout Total** of 90 M ISK: in case of a loss, the **Loss Scenario** block reminds you that everyone absorbs their share pro rata.

The "unknown" cases of this screen:

- **Awaiting sales**: no sale recorded, so no net revenue, no margin, no payouts. A **Provisional margin** shows when products remain unsold; a "never sold" product has no projection;
- **Undistributable distribution**: no approved contribution has a cost;
- an unknown broker fee or sales tax makes the net revenue, the margin and the payouts unknown;
- an approved contribution with no price: production cost, profit and payouts unknown.

These amounts change as long as sales are recorded or contributions are reviewed. They are final only once the project is **Completed**.

## 6. Collect your money

**Sales** tab: the list is read-only for you. The **My sales** box only keeps the sales of your characters ("No sales by your characters" if there are none). Each **Seller** keeps the net revenue of their sales: the transfers then rebalance the payouts.

In the **Finances** tab, the **Transfers between members** block lists "payer → recipient" and the amount. It is computed when **every** product is sold and the fees and costs are known. Otherwise it says why, for example "Some products are still unsold: transfers are computed once every product is sold."

1. If you owe money, click **Copy** next to the **Payment reference** and put it in the reason of the in-game transfer.
2. If the money is addressed to you, you tick **Received** once the transfer has arrived. The box also ticks itself, with the **Verified in the wallet journal** mention, when your wallet journal shows the transfer with the reference and the full amount.
3. Only the recipient ticks. For everyone else the box is greyed out ("Only the recipient can tick this transfer").
4. If nothing is to be transferred, the block says so: everyone already holds their payout. A rounding gap is absorbed by the largest payout.

## Leave the project

In the **Team** tab, the **Leave** button on your row removes your participation (the owner cannot leave). It only shows as long as you have submitted no contribution. Your reservations are released; your contributions stay in the project. To come back, open the invitation link again: the request goes back to waiting for approval, even if you are in the owner's corporation.

## Known pitfalls

- **The link says "Project not found"**: the project is private or the code is wrong. Ask the owner to untick **Private project**.
- **"Unable to Join" on a project that exists**: it is no longer **Published** nor **In Progress**.
- **No notification when accepted**: open the link again or look at **My Projects**.
- **Fulfilled does not increase after your contribution**: it is **Pending**. Only approval counts it.
- **Your material is valued differently from what you paid**: a declared **Material** takes the Jita price of the deposit day.
- **Your declared Job Install became Rejected**: the ESI detected the job and the detected contribution replaces it.
- **"Unknown" amounts in Finances**: look for a missing sales fee on the owner's side, an approved contribution with no price, or the absence of a sale.
- **The Leave button is gone**: it hides as soon as you have a contribution.
