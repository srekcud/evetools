# Run a group project as the owner

This playbook follows a group project from the side of the person who created it: publish, invite, accept requests, approve contributions, record sales, read the distribution, make the transfers, complete. To create the project, buy the materials and start the jobs, see [Build a T2 product](/help?page=playbooks/t2-project): a project on your own is a project like any other. For the invited side: [group project participant](/help?page=playbooks/group-project-member).

Labels in quotation marks are the ones on screen. Several are in English in the application whatever the language, or worded oddly, and are quoted as they appear.

An admin sees the same screens as you and can review contributions, handle requests, record sales and change the status. Two things stay with the owner: appointing an admin, and matching your ESI jobs to the project lines ("Match jobs" in "Sourcing & jobs").

## Before you start

- The plan suits you. The blacklist categories and the invention exclusion can only be changed while the project is a "Draft".
- The project's selling fees ("Broker Fee %", "Sales Tax %") are copied from your seller fees when the project is created. When they are empty, the net revenue of market sales, the profit and the transfers are unknown. See [market fees](/help?page=calculations/market-fees) and the [T2 playbook](/help?page=playbooks/t2-project), step 2.

## 1. Publish the project

Menu "Industry", tab "Projects", "My Projects": open the project (right after creating it, you land on it). While it is a "Draft", the header offers two buttons:

- "Publish": the project becomes "Published".
- "Start": the project becomes "In Progress", skipping "Published".

From "Published", the button becomes "Move to In Progress".

**Trap: a project is private by default, and "Publish" changes nothing about that.** A private project does not show in "Available", and its link answers like an unknown link. To open the project: "Edit" icon in the header, untick "Private project", then "Save". The "Private" badge next to the status goes away.

What is open, and to whom:

| Way in | Condition |
|---|---|
| Invite link | Non-private project, "Published" or "In Progress" |
| "Available" tab | Non-private project, "Published" only, and from the same corporation as you (that of your main character) |
| From "Selling" on | Nobody can join anymore |

To close access again, tick "Private project" back: participants already accepted stay.

## 2. Invite

Click "Invite" in the header. The button is called "Share Link" as soon as there is another participant, a request or a contribution to review. It copies the link to the clipboard (the confirmation is "Link copied!"). The same link is in the "Team" tab: a "Copy Join Link" button and an "Invite Link" card with "Copy".

- The link looks like `<EVE Tools address>/industry/join/<code>`. The invitation code belongs to the project and never changes. There is no screen to type a code in: the invited person opens the link.
- Someone who is not logged in lands on the login screen, then on Home: the link is not replayed. Tell them to open the link again once logged in.
- **Same corporation as you** (that of both main characters): the invited person is accepted at once and opens the project. In "Available", the button is "Join Project".
- **Another corporation, or an unknown corporation**: the invited person sees "Request Sent" and waits for your answer. The screen promises a notification on acceptance, but EVE Tools sends none: tell them yourself.

## 3. Accept requests

No notification warns you of a request. It shows in the "Team" tab (and in "Journal", "Member joined"). That tab is hidden as long as you are the only participant with no request and no contribution to review, and it appears as soon as a request arrives. See [Who can join a project, and who approves?](/help?page=faq/faq).

Under "Pending Requests", each row gives the character, its corporation, the date and "Via" ("Short Link"), with two buttons:

- "Accept": the person becomes a participant with the member role.
- "Reject": the request disappears. The person can ask again with the same link.

In the participants table, "Promote", "Demote" and "Kick" change a role or remove someone. Only the owner promotes an admin.

**Appointing an admin changes who reviews your own contributions** (step 4).

## 4. Approve contributions

They arrive in the "Contributions" tab, whose counter gives the number of contributions. While one is waiting, the "Next action" card shows "Contributions to review" (button "Review contributions"), ahead of any other action. It also shows in the "Workshop".

The table gives, per row: the type, the quantity, the "Valuation", the "Method" and the "Status". For a "Pending" contribution, two buttons: "Approve" and "Reject".

- **You approve a quantity, not a price.** The value of a declared material is the Jita price of the deposit day, read when the contribution is submitted and kept as is at review. The other types carry an entered value, or the real ESI cost. Details: [group contributions](/help?page=calculations/group-contributions).
- **Some contributions have nothing to review.** A purchase you linked, or a linked job, arrives already "Approved" with the "Auto-detected" and "ESI ✓" badges.
- **Duplicate.** If a declared, approved contribution doubles one detected by EVE Tools, the row carries "Duplicate of an ESI contribution" and "Reject" stays available, even once approved. This is the only exception.
- **Approved = locked.** An approved contribution can no longer be rejected: it enters the real cost and the payouts, and the project can no longer be deleted. See [Why can't I touch an approved contribution anymore?](/help?page=faq/faq).
- **Your own contributions.** While you are the only reviewer (no other accepted admin), they are approved when submitted. As soon as you appoint an admin, the following ones stay "Pending" and only that admin can review them: nobody reviews their own contribution.
- **Unknown value.** A contribution with no known price makes the totals that include it unknown, never partial. The table footer ("Total Engaged Costs", "Approved Value", "Pending Value") says so.

When every material is covered and every job delivered, the card offers "Move to selling". The "Move to Selling" button in the header is available in every case: EVE Tools does not check that production is finished.

## 5. Record sales

"Sales" tab. Recording is reserved for the owner and the admins, and is done from your own characters. Three ways:

1. **"Record Sale"**: the form asks for "Item" ("Choose an item", with what is left to sell), "Quantity", "Unit Price (ISK)", "Channel" ("Market order" or "Contract"), "Venue" and "Seller", then "Save".
2. **"Transactions to link"**: the wallet sales of your characters, for the project's products, since the project was created. "Record as sale" takes the quantity and the price. "Possible duplicate" flags a sale already entered with the same quantity and the same unit price.
3. **"Link a contract"**: the finished item exchange contracts of your characters that sold a product of the project. Quantity, price and date are frozen from the contract.

A sale can be corrected ("Edit") and deleted ("Delete") as long as the project is not completed. Under the table, each product shows "N / M sold": units sold out of units produced. M is replaced by a long dash when production is unknown.

**Trap: the "Seller" can only be one of your characters.** Left on "Unknown", the sale is attributed to whoever recorded it. The transfers assume that everyone keeps the net revenue of their own sales: if another participant sold for the project, EVE Tools cannot attribute the sale to them, and you are counted as holding that money. There is no other setting.

The net revenue subtracts the project's "Broker Fee %" and "Sales Tax %" on market sales. See [market fees](/help?page=calculations/market-fees).

## 6. Read the distribution

"Finances" tab. From top to bottom: "From revenue to profit", "Financial Summary", "Sell forecast", the "Profit Distribution" table, "Transfers between members", and "Discord recap" once the project is completed.

The "Profit Distribution" table gives, per participant: "Costs Engaged", "Share %", "Profit Part" and "Payout Total". Clicking a row ("Show breakdown") details the cost per contribution type. The formulas are in [group contributions](/help?page=calculations/group-contributions) and [margin](/help?page=calculations/margin).

What the screen can say instead of a number:

- "Awaiting sales": no sale recorded. Revenue, profit, margin and payouts are unknown, not zero.
- "Provisional margin": some products are unsold ("Unsold:"). Payouts are computed on the revenue to date, to be read as provisional. "Margin projected at the last sale price" estimates the outcome.
- "Undistributable distribution": no approved contribution has a cost.
- "An approved contribution has no known price": the cost, the profit and the payouts stay unknown until it has one.

## 7. Make the transfers

The "Transfers between members" section offers the plan of in-game transfers that gives everyone their payout. It is only computed once every product is sold, the fees are known and every cost is known. Otherwise it says why (for example "Awaiting sales" or "Some products are still unsold").

1. Copy the "Payment reference" ("Copy"). It looks like `IP-` followed by six hexadecimal characters.
2. Make each transfer in game with that reference in the reason. EVE Tools moves no ISK.
3. The recipient ticks "Received". You cannot tick for them ("Only the recipient can tick this transfer"). The box ticks itself, with "Verified in the wallet journal", when the recipient's wallet journal shows the transfer with the reference and the full amount.

The plan is recomputed when read. A tick only counts while the transfer amount is unchanged: if you correct a sale, check them again. A "Rounding gap absorbed by the largest payout" may be shown.

## 8. Complete

When everything is sold, the card offers "Close the project"; the header offers "Move to Completed". The "Complete Project" window shows "Before completing": pending contributions, unsold products, "Final margin: …%" (or "Final margin unknown: a cost or a fee is missing") and "Transfers ticked as received: x / y".

- **These checks block nothing.** "Complete" stays clickable, even with pending contributions or unsold products.
- **Irreversible.** No more contributions, reviews, sales or fee changes, and the project can no longer be deleted.
- Recipients can still tick "Received" after the project is completed.
- Once completed, "Copy for Discord" (in "Discord recap") copies a text ready to paste. Nothing is posted for you.

## What you read, and where it comes from

| Figure | Where | Good to know | Page |
|---|---|---|---|
| Value of a contribution | "Contributions" | Deposit-day price, paid price, ESI cost or entered value | [group contributions](/help?page=calculations/group-contributions) |
| Net revenue, fees | "Sales", "Finances" | Project fees, copied at creation | [market fees](/help?page=calculations/market-fees) |
| Margin, "Payout Total" | "Finances" | Computed on approved contributions | [group contributions](/help?page=calculations/group-contributions), [margin](/help?page=calculations/margin) |
| Amount of a transfer | "Finances" | Revenue held by each seller minus their payout | [group contributions](/help?page=calculations/group-contributions) |

## What next

- What an invited person sees: [group project participant](/help?page=playbooks/group-project-member).
- Known limits: [known limits](/help?page=limits/known-limits).
