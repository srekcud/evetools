# Build a T2 item from A to Z: from the analysis to the real margin

This guide follows a T2 item from choosing the product to reading the margin in the completed project. Screen and button names are the ones of the English app (a few labels are always in English, even in the French app).

At the end you will have chosen a product on its numbers, created the project, brought your materials at the real price, started the jobs in game, recorded the sales and read the real margin.

Structures and the favorite system (step 4 of [First steps](/help?page=playbooks/first-steps), guide [Set up your industry structures](/help?page=playbooks/industry-structures)) must be filled in beforehand: without a structure, the plan is calculated without any bonus.

## Step 1: choose the product

Menu **Production** › **Industry**, tab **Analysis**. Four sub-tabs: **Margins**, **Batch Scan**, **Buy vs Build**, **Pivot**.

- **Margins**: type the product in "Search a product (e.g., Nomad, Raven...)", set **Runs**, **ME**, **TE** and **Solar System**, then click **Analyze**. For a T2 item, ME and TE are greyed out: the screen says "T2 item detected. ME/TE are set by invention decryptor" and starts at ME 2 / TE 4.
- **Batch Scan**: the **Profit Scanner** ranks every manufacturable item; clicking a row opens the item in **Margins**.

What you read in **Margins**:

- **Total Cost**: materials, job installation and, for a T2, the invention.
- **Best Sell Price**, **Profit** and **Margin**, then the **Sell Price Comparison** table by venue (columns Venue, Unit Price, Revenue, Fees, Profit, Margin).
- **Where to sell**: price, volume and days to sell everything, per market.
- For a T2, the **Invention** panel compares the decryptors (attempts, cost per unit, delta against "without decryptor"); pick one in the list.

The **Margin** is always calculated the same way: `profit ÷ total cost`, with `profit = revenue − fees − total cost`. The note under the "Sell Price Comparison" table says "Margin = profit / revenue": that label is outdated, the calculation does divide by the cost.

A missing figure (price, cost index, inventor skill, selling fees) is shown as "unknown", never as 0. See [Numbers that look wrong](/help?page=faq/numbers-that-look-wrong). Selling fees come from the skills and standings of your main character: [Industry FAQ](/help?page=faq/industry).

## Step 2: create the project

**Create the project before buying or starting anything.** The wallet transactions offered for purchases and sales, and the jobs matched to the project, only show up when they date from after the project was created.

1. In **Margins**, after the analysis, click **Create project**. You land on **Projects** with the **New Group Project** window already filled in (product, runs, ME, TE). Without an analysis: tab **Projects**, button **New Project**, then **Add Item**, **Bulk Paste** or **From a template**.
2. Check the runs and, for a T2, leave **Exclude the invention** ticked or not: ticked, the plan has no invention, no T1 copy and no datacores, and the T2 BPCs are to be provided.
3. Useful options: **Name** (optional), **Personal use (not sold)** (the finances then show a cost per unit instead of the profit), **Private project**, **Blacklist** (the ticked items are purchased, not manufactured).
4. If you completed a project of this single product less than a month ago, a **Reference** line gives its unit cost and its margin. An unknown figure stays "unknown".
5. Click **Create Project**: you land on the project, in the **Draft** status.

A project you run alone is a solo project: the **Contributions** and **Team** tabs are hidden, and your contributions are approved as soon as you submit them.

## Step 3: read the project and start it

The phase rail (**Prepare**, **Supply**, **Produce**, **Sell**, **Close**) and the **Next action** card tell you where the project stands. The useful tabs: **Sourcing & jobs**, **Production**, **Invention**, **Sales**, **Finances**.

In the header, click **Start** (or **Publish** to open the project to others). The project moves to **In Progress**. There is no other condition: you can start before everything is bought.

## Step 4: bring the materials at the real price

Tab **Sourcing & jobs**. The **Materials to Source** table gives, per material, **Required**, **Fulfilled**, **Remaining** and **Est. Cost**. The **Where to buy the materials** block compares **Jita + import** and **Structure** and tells you where to **Buy at**. Buy in game.

For each material, two ways to record it:

- **Purchases** (owner only): the "Purchases of *material*" window lists the **Wallet transactions** of your characters; **Link** attaches a transaction. When no transaction is visible yet, the **Manual purchase** section (quantity, unit price, **Add purchase**) records it right away. A line of chips tells the state of the wallet reading ("Wallet read at … · next read around …", "Wallet never read", "Wallet scope missing", "No token"). Value of the contribution: the **paid price**.
- **I supplied it**: for a material you already hold in stock. The **Add Contribution** window values the quantity at the **Jita price of the deposit day (5th percentile)**, frozen when you submit. Labels in this window are in English: **Material**, **Job Install**, **BPC**, **Line Rental**.

A transaction already linked to another project is not offered. An unknown price gives a contribution of unknown value, never 0: the totals that include it are unknown too. Details: [Group projects FAQ](/help?page=faq/group-projects).

For a T2, the **Invention** tab compares the decryptors read-only: nothing is saved and the project plan does not change. In the **Sourcing & jobs** tab, the **Owned T2 BPCs** card (button **Declare my T2 blueprint copies**) is used to declare the T2 BPCs you already own, and **Use my stock** to declare the intermediates you hold in stock (owner, project not completed).

## Step 5: start the jobs in game

Jobs are started in the game, not in EVE Tools. The app matches them to the project lines:

- **automatically** after each jobs synchronization, for the uncompleted projects where you are an accepted participant. Only jobs started after the project was created count;
- on demand: in the **Jobs to Launch** table, the **Match jobs** button (owner) first re-reads the ESI jobs of your characters, then matches them;
- by hand: **Link a job** on a line (**Unlink** to undo).

The value of a job is its **Job cost** as given by ESI ("real cost (ESI)"), one contribution per participant who started the job. A cost that ESI does not give stays unknown. Synchronization frequency: [Sync frequencies](/help?page=data/sync-frequencies); a job that has just finished can take a while to show up (see [Numbers that look wrong](/help?page=faq/numbers-that-look-wrong)).

Tab **Production**: the chain by level with the status of each job (**To do**, **In progress**, **Done**) and the **Real cost** column. The owner can also put a **Mark** (**Launched** or **Done**, with a number of runs) on a step, and remove it (**Remove the mark**).

When everything is produced, the **Next action** card offers **Move to selling**.

## Step 6: sell and record the sales

Move the project to selling: **Move to Selling** button in the header (or **Move to selling** on the card). Sell in game, then tab **Sales**:

- **My sell orders**: your orders on the project's products, with your price, the best price and your position (EVE Tools never edits an order: the button only copies a price);
- **Transactions to link**: the sales of your wallet, made since the project was created, on the project's products and never linked; **Record as sale** creates the sale. A **Possible duplicate** badge flags a sale already typed by hand with the same quantity and the same price;
- **Link a contract**: a finished item exchange contract; quantity, price and date are frozen from the contract;
- by hand (owner or admin): **Record Sale** button, then **Item**, **Quantity**, **Unit Price (ISK)**, **Channel** (**Market order** or **Contract**), **Venue**, **Seller**, **Save**.

A sale can be recorded as long as the project is not completed. The fees come from the project: the pencil icon in the header (tooltip **Edit**) opens **Edit Project**, which shows **Broker Fee %** and **Sales Tax %**. Left unknown (skills and standings of the owner not synced), the net revenue, the profit and the margin stay unknown.

## Step 7: read the real margin

Tab **Finances**:

- **From revenue to profit**: revenue, broker fee, sales tax, materials, job install, BPC, line rental, courier, then **Profit** (or **Loss**);
- **Financial Summary**: **Profit** (or **Provisional profit** while not everything is sold) and **Margin**;
- **Break-even**: the price per unit below which you lose (calculated only for a project with a single final product, when every cost and fee is known);
- **Sell forecast** for what is left to sell.

With no sale at all, the "No sale recorded yet" banner reminds you that revenue, profit and margin will be known at the first sale. With partial sales, the margin is provisional: unsold products are valued at the price of the most recent sale.

The real **Margin** is `(net revenue − total project cost) ÷ total project cost`, the total cost being the sum of the approved contributions (purchases at the paid price, jobs at the ESI cost, stock at the Jita price of the deposit day). It is the same definition as in **Margins**: compare the two to see the gap between the analysis and reality.

## Step 8: complete the project

When everything is sold, click **Move to Completed** (or **Close the project** on the card). The **Complete Project** window lists **Before completing**: unsold products ("Everything is sold" when none is left) and the final margin ("Final margin: …%", or "Final margin unknown: a cost or a fee is missing"). Confirm with **Complete**.

Completing freezes the accounts: no more contribution, sale or fee change, and the project can no longer be deleted. This cannot be undone. If it holds only this product, it will serve as the **Reference** when you create a project for the same product, for one month.
