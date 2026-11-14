# Take part in a group project as a member

This playbook follows a group project from the side of someone who does not own it: join through the invite link, contribute materials or work, wait for the review, read your share, receive your transfers. For the owner's side (publish, invite, review, sell, complete), see [Run a group project as the owner](/help?page=playbooks/group-project-owner).

Labels in quotation marks are the ones on screen. They are quoted as they appear, including the ones that are not translated (for example "Add Contribution", "Material", "Job Install", "Pending", "Approved", "Profit Distribution", which are English in every language).

## 1. Join

The owner sends you a link shaped like `<EVE Tools address>/industry/join/<code>`. The shortcut `<EVE Tools address>/join/<code>` leads to the same screen. There is no screen to type a code in: open the link.

- **Not logged in yet.** You land on the login screen, then on Home: the link is not replayed. Open it again once logged in.
- **Same corporation as the owner.** That is the corporation of your main character, compared with the one of the owner's main character. After "Joining Project...", you open the project directly.
- **Another corporation, or an unknown corporation.** You see "Request Sent" and the "Back to Projects" button. The screen promises a notification on acceptance, but EVE Tools sends none: tell the owner, or come back and look. Until someone answers, nothing changes: the project shows neither in "My Projects" nor in "Available", and opening the link again takes you back to the project list without any message. Once the request is accepted, the project shows in "My Projects".
- **"Unable to Join".** The message comes from the server, in English. "Project not found": the code is unknown, or the project is private (the two cases cannot be told apart). "This project is no longer accepting new members": the project is no longer "Published" or "In Progress".

Another way in, if you are in the owner's corporation: menu "Industry", tab "Projects", tab "Available". It lists the "Published", non-private projects of your corporation, with "Join Project" and an immediate acceptance.

**Trap: a participant who was removed or who left always goes back through a request, even inside the owner's corporation.** The project is no longer listed in "Available": open the link again, you get "Request Sent" and the member role. See [Who can join a project, and who approves?](/help?page=faq/faq).

## 2. Read the project screen

Open the project from "My Projects". Under the header you find the phase bar ("Prepare", "Supply", "Produce", "Sell", "Close") and the "Next action" card. For you, it can show:

- "Source a material", button "Contribute": it opens the contribution form on the line where the largest quantity is missing.
- "Launch a job", button "See the jobs".
- "Nothing to do for you" ("The project is moving along without you for now"): what is left belongs to the owner and the admins (review, change the status, record sales).

The tabs that matter to you: "Sourcing & jobs", "Contributions", "Finances", "Team" and "Journal". The "Companion" button in the header (absent once the project is "Completed") opens a detached window with the "To assign" lines and "I'll handle it".

## 3. Choose what you bring

"Sourcing & jobs" tab. "Materials to Source" gives, per line, "Required", "Fulfilled", "Remaining" and "Progress"; "Jobs to Launch" does the same for the work.

- "Fulfilled" counts **approved** contributions. If the project names a container whose stock can be read, it is the larger of that number and the container content, capped at the required quantity. When the container cannot be read, the figure carries "≥" and "Cannot be checked".
- A pending contribution does not change "Fulfilled": a line stays to be covered until someone approves.
- The "Reserved by" column shows who takes care of what. "I'll handle it" (with a quantity to type) announces what you take; "Release" removes it. It informs without blocking: another participant can contribute on the same line. Your reservation goes down when one of your contributions is approved.
- On a line, the button is "Contribute". "I supplied it" is the owner's label while the owner is alone: you will not see it.
- **What stays with the owner**: "Purchases", "Link a job", "Unlink" and "Match jobs". Your wallet purchases cannot be linked on your behalf, and they do not count until you declare them.

## 4. Declare a contribution

Three ways to the same form: "Contribute" on a line (type, item and quantity are prefilled, the quantity being your reservation, otherwise the remaining quantity), "Add Contribution" in the "Contributions" tab (absent once the project is "Completed"), or "Contribute" in the "Next action" card.

The "Add Contribution" window asks for a "Contribution Type", the "Item" ("BOM Step" for a job), the "Quantity", optional "Notes", then "Submit Contribution". It reminds you: "Your contribution will be reviewed by a project admin."

| Type | For what | Value |
|---|---|---|
| "Material" | A material from "Materials to Source" | Nothing to enter: Jita price of the deposit day (5th percentile), read when you submit |
| "Job Install" | The installation cost of a job started for the project | "Estimated Value" to enter, strictly positive |
| "BPC" | A copy or invention line | "Estimated Value" to enter, strictly positive |
| "Line Rental" | The use of your slot, per your rate schedule | "Estimated Value" required; a suggestion may show ("Suggested from your rate schedule"), otherwise "No suggestion" and you enter it by hand |

Valuation in detail: [group contributions](/help?page=calculations/group-contributions).

Refusals to know (the message comes from the server, in English, shown in the window):

- **One pending or approved contribution per line and per type.** "A contribution of this type is already pending or approved for this item". So bring the whole quantity at once. *Example: a line asks for 200, you hold 120. Submit 120; once it is pending or approved, you cannot add another "Material" on that line.* A rejected contribution does not block: you can submit another one.
- **Quantity above the remaining quantity**: "The contribution exceeds the remaining quantity of the line".
- **No Jita price for a material that day**: "No market price for type N: no Jita price for the deposit day." The contribution is refused, not valued at 0.
- **You cannot withdraw a pending contribution.** There is no button and no cancellation: ask a reviewer to reject it, then submit it again.

**A manufacturing or reaction job is often detected without you.** The jobs of your characters are read every 30 minutes. If a job started since the project was created matches a manufacturing or reaction line, EVE Tools links it and creates a "Job Install" contribution in your name, with the "Auto-detected" and "ESI ✓" badges, already "Approved", at the cost the ESI gives (unknown if the ESI gives none). Your declared contribution of the same type on that line, if it was still pending, is then rejected automatically. Do not declare a job that is already detected: a declared, approved contribution that doubles a detected one is flagged "Duplicate of an ESI contribution" to the reviewers. Copies and inventions are not linked this way: declare them as "BPC".

## 5. Wait for the review

Your contribution shows in the "Contributions" tab as "Pending", next to the other contributions of the project (those of every participant, under the name of their main character). Only the owner and the admins have the "Approve" and "Reject" buttons.

- **No notification** warns you of an approval or a rejection. An open page updates by itself. Otherwise, the "Journal" keeps "Contribution approved" or "Contribution rejected".
- A rejection carries **no reason**: ask the reviewer.
- **Approved = locked.** An approved contribution can no longer be rejected (except the duplicate above), enters the real cost and the payouts. See [Why can't I touch an approved contribution anymore?](/help?page=faq/faq).
- An admin does not review their own contribution: only another authorised person does.
- The table footer ("Total Engaged Costs", "Approved Value", "Pending Value") says when a value is unknown: any total that includes it is then unknown, never partial.

## 6. Follow your share

"Finances" tab. The "Profit Distribution" table lists every participant, you included, by main character name ("Unknown" without a main character, "Deleted pilot" for a deleted account). Nothing highlights your row: look for your name. Its columns: "Costs Engaged", "Share %", "Profit Part", "Payout Total". "Show breakdown" details your costs by type.

- Only your **approved** contributions count. A "Pending" contribution is in none of these figures.
- As long as no sale is recorded, the screen says "Awaiting sales": the payout is unknown, not zero. With unsold products, "Provisional margin": read the payouts as provisional.
- You see the same screens and the same figures as the owner. The possible messages ("Undistributable distribution", "An approved contribution has no known price") are detailed in the [owner playbook](/help?page=playbooks/group-project-owner), step 6.
- Formulas: [group contributions](/help?page=calculations/group-contributions) and [margin](/help?page=calculations/margin).

## 7. Receive your transfers

Recording sales is reserved for the owner and the admins: you cannot enter any. They are the ones who hold the net revenue, and the "Transfers between members" plan ("Finances" tab) gives everyone their payout. It is only computed once every product is sold, the fees are known and every cost is known; otherwise it says why.

1. The payer makes the in-game transfer with the "Payment reference" in the reason. EVE Tools moves no ISK.
2. You tick "Received" on the transfer you are the recipient of. You cannot tick someone else's ("Only the recipient can tick this transfer"). The box ticks itself, with "Verified in the wallet journal", once your wallet journal shows the transfer with the reference and the full amount.
3. A tick only counts while the transfer amount does not change: if a sale is corrected, check again.

You can still tick "Received" after the project is completed.

## 8. Invite or leave

- **Invite.** The "Share Link" button in the header copies the invite link (confirmation "Link copied!"); the "Team" tab gives the same link. Someone you invite is accepted at once only if they are in the **owner's** corporation, not yours.
- **Leave.** "Team" tab, "Leave" on your row, with a confirmation. The button only shows while you have no approved contribution, and the owner cannot leave. Your reservations are released. To come back: the invite link, as a request (step 1).

## What you read, and where it comes from

| Figure | Where | Good to know | Page |
|---|---|---|---|
| "Est. Cost" | "Sourcing & jobs" | BOM estimate, Jita 5th percentile, not your real cost | [FAQ](/help?page=faq/faq) |
| "Valuation" of a contribution | "Contributions" | Deposit-day price, ESI cost or entered value | [group contributions](/help?page=calculations/group-contributions) |
| "Costs Engaged", "Share %", "Payout Total" | "Finances" | Computed on approved contributions | [group contributions](/help?page=calculations/group-contributions), [margin](/help?page=calculations/margin) |
| Amount of a transfer | "Finances" | Revenue held by each seller minus their payout | [group contributions](/help?page=calculations/group-contributions) |

## What next

- What the owner does on their side: [group project owner](/help?page=playbooks/group-project-owner).
- Known limits: [known limits](/help?page=limits/known-limits).
