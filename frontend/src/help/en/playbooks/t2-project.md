# Build a T2 product from A to Z

This playbook follows a T2 product from start to finish: choose what to produce in the analysis, create the project, buy the materials, run the jobs (invention included), record the sale and read the real margin. The steps are in the order you do them. A project you build alone is a project like any other: it only differs from a group project by its access (with several people, see [group project owner](/help?page=playbooks/group-project-owner)).

Labels between quotes "" are the ones on screen in the English interface. A few are hard-coded in English even in the French interface; they are the same here.

## Before you start

- Skills and standings synced ("Sync all"): without them, the project's sales fees are unknown and the invention probability in the analysis can be too. See [Getting started](/help?page=playbooks/getting-started), step 5.
- At least one production structure declared, with its facility tax: [Set up your industry structures](/help?page=playbooks/industry-structures).
- Do both before step 2. The project's fees are copied from your main character when the project is created, and they are never recomputed afterwards (see step 2).

## 1. Analyze

"Industry" menu, "Analysis" tab, "Margins" sub-tab. Under the sub-tabs, three labels give the age of the data: "Jita prices", "Cost indices", "Adjusted prices" (see [data freshness](/help?page=data/freshness-privacy)).

1. In "Analyze Profit Margin", search for the product. A T2 carries the "T2" label.
2. Set "Runs" and, if needed, "Solar System" ("Default" or one of your favorite systems). For a T2, "ME" and "TE" are greyed out, starting at 2 and 4: they follow the decryptor you pick lower on the page.
3. Click "Analyze".

What you read:

- Four cards: "Total Cost" (with "Per unit"), "Best Sell Price", "Profit" and "Margin". The last three are for the best sell venue, named under the "Margin" card.
- The "Sell Price Comparison" table: one row per venue that has a price (Jita, your market structure as "undercut" then "instant sell", The Forge as "contract"), with "Unit Price", "Revenue", "Fees", "Profit", "Margin" and "Daily Volume".
- "Where to sell" compares Jita and your structure: price, volume, fees, "Net / unit" and "To sell everything".
- For a T2, the invention panel ("Decryptor Impact on Profit"): pick the "Inventor" (your main character by default, or "Without skills"), then one row per decryptor ("None" included) with probability, "Attempts", "Jobs", "Cost / unit" and "Best Margin". Clicking a row picks that decryptor and reruns the analysis. That choice is only for comparing: it is not saved in the project.
- Below: "Material Cost Breakdown" (prices "Prices from Jita weighted average"), "Job Install Cost" per job, and "Cost Summary".

If a price, a cost index, a facility tax or the inventor's skills are missing, the total cost shows "Unknown" with its cause, and the comparison table is replaced by that cause. If the sales fees are unknown, the table header shows "Unknown fees", a banner gives the cause, and "Fees", "Profit" and "Margin" read "Unknown". None of these values is a zero.

Where the numbers come from: [job cost](/help?page=calculations/job-cost), [weighted price](/help?page=calculations/weighted-price-appraisal), [market fees](/help?page=calculations/market-fees), [margin](/help?page=calculations/margin).

## 2. Create the project

Above the results, click "Create project". You land on "Projects" with the "New Group Project" window already filled in (the title says "Group" even for a project you run alone). You can also open "Projects" and click "New Project".

1. In the items table, check the product, "ME", "TE" and "Runs". The ME comes from the analysis. The TE is 4 for a T2, whatever the decryptor: fix it if your T2 BPC is different.
2. "Name" is optional.
3. "Private project" is ticked by default: the project does not appear in the corporation's discovery list. Leave it that way if you work alone.
4. "Personal use (not sold)": tick it only if you will not sell. The project then shows a cost per unit instead of the profit, and this choice cannot be changed after creation.
5. "Exclude the invention": the T2 BPCs are to be provided, and the plan has no invention, no T1 copy and no datacores. This choice can only be changed while the project is a draft.
6. "Sell structure": your preferred market structure by default. "Blacklist": the ticked categories are bought and not built (your Industry settings pre-tick some).
7. Click "Create Project".

The project opens with the "Draft" status. Three points to remember:

- **Products, runs, ME and TE cannot be changed after creation.** To change them, delete the project (possible as long as it has no approved contribution and no sale) and create it again.
- **Create the project before buying and launching jobs.** Wallet purchases and ESI jobs older than the project are neither linked nor offered for linking (a manual purchase is still possible).
- **Sales fees.** "Broker Fee %" and "Sales Tax %" are read at creation from the skills and standings of your main character. If they were unknown at that moment, they stay unknown, even if you sync afterwards: enter them with the "Edit" button in the header. Details: [market fees](/help?page=calculations/market-fees).

## 3. Read the project page

From top to bottom:

- the header, with the status, the "Publish" and "Start" buttons (in draft) and the "Edit" and "Delete" icons;
- the phase rail: "Prepare", "Supply", "Produce", "Sell", "Close", each with a counter;
- "Next action": the one thing to do now, with its button;
- the tabs. Alone, you see "Sourcing & jobs" (open by default), "Finances", "Sales", "Production", "Planning", "Journal" and "Invention". "Contributions" and "Team" are hidden as long as you are the only participant.

When the plan suits you, click "Start" (the project goes "In Progress"). Ignore "Publish", meant for projects other people have to join.

## 4. Buy the materials

"Sourcing & jobs" tab. "Materials to Source" lists the materials to buy (the BOM only holds these leaves; components and blueprints are under "Jobs to Launch"), with "Required", "Fulfilled", "Remaining", "Progress" and "Est. Cost".

- **"Est. Cost" is not the analysis price.** It is the 5th percentile Jita price read when the project was created, and it is never refreshed. The analysis uses the weighted price of the quantity. A material without a price shows "---".
- **"Where to buy the materials"** compares, for the remaining quantity, "Jita + import" and "Structure" (your preferred market structure), and says "Buy at". With no preferred structure, or if its market is not synced, everything goes to Jita; with no favorite system for the activity, the import is unknown. The "Copy Jita (N)" and "Copy <structure> (N)" buttons copy the **remaining** quantities, one "name, tab, quantity" line per material.
- The "Copy multibuy" button at the top of "Materials to Source" copies the **required** quantity of every line, not the remaining one. "Evaluate in Appraisal" sends the same list to the appraisal.

After buying in game, the purchase has to get into the project:

- **Automatic link.** The scheduler runs every 5 minutes, but a character is only read again when its read is due: the "Wallet read at … · next read around …" chip in the "Purchases" window tells you where it stands. A new purchase is linked on its own when exactly one remaining line of your open projects (material or component) has its type and its quantity fits in the line's remaining quantity.
- **Manual link.** Under "Fulfilled", the "Purchases" button (owner only) opens "Purchases of …": "Wallet transactions" with "Link", or "Manual purchase" ("Quantity", "Unit price", "Add purchase"). On a component line, "Purchases" is under the line's name.
- A linked purchase moves "Fulfilled" forward ("N purchased") and becomes a contribution valued at the **price you actually paid**.
- **"I supplied it"** is for declaring stock you already had. It is approved immediately, but valued at the Jita price (5th percentile) of the day you declare it, not at what you paid, and with no value to enter. Do not use it on a line whose purchase you also link: both count (duplicate contribution), and the "Contributions" tab, where you would see them, is hidden when you are alone.

When all the materials are covered, the sourcing section (including "Where to buy the materials") folds; "Show sourcing" reopens it.

## 5. Launch the jobs

Same tab, "Jobs to Launch", in three groups: "Blueprints" (T1 copy and invention), "Components" and "Final Products". The "Production" tab shows the order to follow: a job is "Blocked" until a component is "Done", and "Missing materials" when its sub-components are done but its materials are not. Launch the jobs in game, then:

- **Manufacturing and reactions.** The ESI jobs of your characters are linked at every jobs sync (every 30 minutes), or when you click "Match jobs" (owner only). A job is kept if it uses the line's blueprint, started after the project was created, has an output (neither cancelled nor reverted) and its runs fit in the rest of the line (plus, if needed, one single bigger job). The "Link a job" button fixes it by hand. The install cost read from ESI becomes a "Job Install" contribution; if ESI gives no cost, it is unknown, never 0.
- **Invention and T1 copy.** No ESI job links to them. The plan's "Attempts" is the number of runs to invent divided by (runs of the invented BPC × base probability), rounded up, with no skills and no decryptor: the analysis, which takes them into account, can give another figure. For these lines to count and for their cost to enter the project, click "I supplied it" on the line: the "BPC" type is preselected, the quantity is prefilled, and you enter an "Estimated Value" above 0, meaning what the datacores and the invention cost you. If you already own T2 BPCs, open "Owned T2 BPCs" instead ("Declare my T2 blueprint copies"): the declared runs leave the plan. That panel only shows up if the plan finds T2 BPCs in your synced blueprints.
- **"Mark".** In the "Production" tab, "Job tree" view, the owner can mark a line "Launched" or "Done" for a number of runs. The mark changes the line's status and its remaining runs, but creates no cost. Pitfall: it does not move the "Produce" counter of the phases. A line only counts there as delivered if its linked jobs are delivered or if its contributions cover its quantity. After a "Done" mark on an invention, "Next action" can therefore stay on "Launch a job". The "Move to Selling" button in the header stays available.

In the "Production" tab, "Buy or build" compares each built intermediate with buying it at Jita plus import, and "Switch to buy" takes it out of production.

## 6. Sell

When the jobs are done, click "Move to Selling" ("Next action" offers "Move to selling", which does the same). Sales can be recorded as soon as they happen, as long as the project is not completed.

"Sales" tab. "My sell orders" shows your open orders on the project's products (read every 5 minutes) and your position ("1st" or "undercut for …"). It is read-only: EVE Tools changes no order. To record a sale:

- **"Transactions to link"**: sales from your wallet on the project's products, since its creation, not yet linked. "Record as sale" takes the transaction; "Quantity to record" lets you record part of it; "Possible duplicate" flags an already entered sale with the same quantity and the same unit price.
- **"Link a contract"**: finished item exchange contracts of your characters. Quantity, price and date are frozen from the contract.
- **Manual entry**: the "Record Sale" button, then "Item", "Quantity", "Unit Price (ISK)", "Channel" ("Market order" or "Contract"), "Venue", "Seller" and "Save".

The channel matters. A "Contract" sale pays neither the broker fee nor the sales tax of the project; its contract fees are read from the wallet journal, and unknown if they are not there. Under the table, one line per product gives "N / M sold" (units sold out of units produced).

## 7. Read the real margin and complete

"Finances" tab. "Financial Summary" gives the "Total Revenue", the "Broker Fee" and the "Sales Tax" (with their percentage), the "Net Revenue", the "Total Project Cost" broken down into "Materials", "Job Install + Line Rental" and "BPC", then the "Profit" and "Margin:". Before the first sale, the total revenue reads "No sale" and the net revenue, the profit and the margin read "Awaiting sales". With partial sales, the profit becomes "Provisional profit" and, lower down, "Provisional margin" lists the "Unsold:" products and the "Margin projected at the last sale price".

- **Margin = profit ÷ total project cost.** Example (round values): a net revenue of 120 million for a cost of 100 million gives a profit of 20 million and a margin of 20%, not 16.7%. The help text under the analysis table says "Revenue = unit price x runs" and "Margin = profit / revenue": both are inaccurate. The revenue is the unit price multiplied by the units produced (not by the runs), and the calculation is profit ÷ cost, in the analysis and in the project alike. Details: [margin](/help?page=calculations/margin).
- **The cost is the sum of the approved contributions, nothing else.** Material from your hangar that you never declared, an invention you never entered: they are not in it, and the margin then looks better than it is. If a single contribution has an unknown value, the cost, the profit and the margin are unknown (never partial). Details: [group contributions](/help?page=calculations/group-contributions).
- **Unknown fees: no margin.** If the project's "Broker Fee %" or "Sales Tax %" is empty and a sale goes through the market, the net revenue, the profit and the margin are unknown. A contract sale does not need them.
- "Break-even price per unit" (in "From revenue to profit") is unknown until all the final jobs are delivered, or while a contribution or the fees are unknown.

To close, click "Move to Completed". The "Complete Project" window lists "Before completing": contributions awaiting review, unsold products and "Final margin: …%" (or "Final margin unknown: a cost or a fee is missing"). Completing freezes the accounts: no more sales, contributions or fee changes, and the project can no longer be deleted. This action cannot be undone.

## What you read, and where it comes from

| Figure | Where | Good to know | Page |
|---|---|---|---|
| "Total Cost", "Job Install Cost" | Analysis | Estimate from the ESI cost indices and adjusted prices | [job cost](/help?page=calculations/job-cost) |
| Material prices | Analysis | Weighted price of the quantity | [weighted price](/help?page=calculations/weighted-price-appraisal) |
| "Est. Cost" | Project, "Materials to Source" | 5th percentile, frozen at creation | [weighted price](/help?page=calculations/weighted-price-appraisal) |
| "Broker Fee", "Sales Tax" | Analysis and "Finances" | Copied when the project is created, never recomputed | [market fees](/help?page=calculations/market-fees) |
| "Margin" | Analysis and "Finances" | Profit ÷ cost; estimated on one side, real on the other | [margin](/help?page=calculations/margin) |
| "Total Project Cost" | "Finances" | Sum of the approved contributions | [group contributions](/help?page=calculations/group-contributions) |

## What next

- A project with several people: [owner](/help?page=playbooks/group-project-owner) or [participant](/help?page=playbooks/group-project-member).
- Follow your sell orders: [market orders](/help?page=playbooks/market-orders).
- A figure stays "unknown": [known limits](/help?page=limits/known-limits).
