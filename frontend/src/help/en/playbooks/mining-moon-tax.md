# Run a mining operation and follow the moon tax

This playbook covers two linked topics: the mining operation (several players, ledger snapshots, shares) and the moon tax of a refinery (statements, payments). EVE Tools reads ESI and computes, it never moves ISK: donations and payments are made in game.

Who does what:

- the organizer (role "Owner") and the "Admin" members run the operation: steps 1 to 11;
- participants join it: step 2;
- the refinery manager sets up the tax: steps 12 to 16, with the Accountant (or Director) role;
- each miner reads and pays their own tax: step 17.

Labels in quotation marks are the ones on screen. Times are in EVE time (UTC).

## 0. Before you start

- Each character that will mine needs the permission to read its mining ledger (the character mining ESI scope). Without it, or if the authorization is no longer valid, it shows "reconnect needed" and cannot be enrolled. See [Getting started](/help?page=playbooks/getting-started), step 4.
- A character can only be in one open operation, meaning "Planned", "In progress" or "Closing". It shows "already in "..."" until the other one has reached "Shares", "Settled" or "Completed".
- The "My corporation" visibility needs a known corporation. Otherwise the screen says "Unknown corporation: private visibility only.".

## 1. Create the operation

"Mining" menu, "Operations" tab, "New operation" button. To start from a moon chunk, see step 13.

The "New mining operation" form asks for:

- "Label" (required, 100 characters at most).
- "Start (EVE time)": the next full hour by default. It is the planned date and the reference for recurrences. The real start is the moment you click "Start operation" (step 4), not this time.
- "Visibility": "Private (link)" or "My corporation" (the default when your corporation is known). A "My corporation" operation appears in the "Available" tab of the corporation members.
- "Split": "Value prorata" or "Each for themselves" (everyone keeps their ore: no share, no payment). "Weighted time" is greyed out and tagged "soon".
- "Boost ship and compressors": optional, to declare when a booster compresses on site.
- "Recurrence": see step 11.
- "Your characters": your main is ticked when it is ready. "Select all ready" and "Clear" help with ticking. At least one is required.

The "Create operation" button shows "Operation created", "The operation is planned.", the invitation link and "Open operation".

## 2. Invite and accept participants

On the operation, the "Invitation link" card gives the address to share ("Copy" button). Every accepted participant sees it.

- The recipient opens the link, picks their characters ("ready", "reconnect needed" or "already in "..."") then "Join with N characters". Other way in: "Available" tab then "Join".
- Joining is only possible while the operation is "Planned" or "In progress".
- A member of the organizer's corporation is accepted right away, even if the operation is "Private (link)". Others get a "pending request": "Accept" or "Refuse" (Owner and Admin, who get a notification in the "Mining Operations" category).
- "Renew link" invalidates the old one (Owner and Admin, operation "Planned" or "In progress").
- In "Participants", the Owner can "Make admin" / "Remove admin", and remove a participant. A participant can "Leave operation" while it is "Planned". The Owner can "Delete operation" while it is "Planned".

## 3. Set the valuation (Owner and Admin)

"Valuation" card, editable while the operation is "Planned", "In progress" or "Closing":

- "Valuation basis": "Raw", "Compressed" (default) or "Reprocessed" (with a "Reprocessing yield").
- "Market": "Jita" (default) or "Tracked structure". "Side": "Buy" (default) or "Sell".
- "Booster cut" and "Corporation cut": 0 to 100 %, 0 by default. "Booster" names the participant who receives the booster cut.
- "Save settings".

The sharing rule is shown under the card: G is the sum of quantities times the unit price of the basis, the booster takes G × its cut, the corporation G × its cut, each participant their value × the rest. Unit price and value: [ore value and moon tax](/help?page=calculations/ore-value-moon-tax).

## 4. Start

"Start operation" button (Owner or Admin, operation "Planned"). The window offers "Solar system (optional)":

- with a system, only the quantity mined in that system counts ("Counted in ... only");
- with "All systems", everything the character mined between the two snapshots counts, including outside the operation ("mining outside the operation during the window is included").

"Start" moves the operation to "In progress" and takes, for each enrolled character, the start snapshot of its ledger. In "Participants", "✓ start 2/3" shows the snapshots read. A failure shows as "✗" ("invalid token · quantity unknown", "read failed"): "Retry" tries the read again (Owner, Admin; the participant themselves outside "Closing"). A character that needs reconnecting has to be reconnected first.

## 5. During the operation

- A participant accepted after the start gets their start snapshot when accepted: what they mined before is not counted.
- "Presence": "Online", "AFK · N min", "Left", "Not seen yet". It comes from the operation screen being open in EVE Tools, not from the in-game state.
- "I'm leaving" takes the end snapshot of your characters right away, with no way back.
- Shares are computed only from the start snapshot and the end snapshot. No quantity read during the operation enters the shares.

## 6. End

"End operation" (Owner or Admin), then "End at <time>". The operation moves to "Closing". What is mined afterwards does not count.

The end snapshot is read when the ESI cache of the ledger expires, about 10 minutes after the end: the "Waiting for the end ledger" page gives the reading time. Progress is shown ("N / M characters"). When all snapshots are settled, every participant gets the "Mining ledger ready" notification.

Only characters whose start snapshot was read get an end snapshot. An end reading 30 days or more after the start day is refused: "✗ beyond the 30-day ledger".

## 7. Check the quantities

The "Mined quantities" card has one line per participant:

- "unknown": missing snapshot, invalid token, read failed, beyond the 30 days, or inconsistent ledger (end quantity lower than the start one). It is never counted as 0;
- "measuring": a snapshot has not been read yet.

"Correct a quantity" (Owner or Admin, only in "Closing"): pick the character, the type and the quantity. The value you enter replaces the measured value for that type and the correction is tracked (who, when, original value). To make an unknown quantity known, you have to enter every mined type: only the corrected types count then.

## 8. Freeze the shares, or complete

During "Closing", the "Value prorata split preview" re-reads the prices on every reading ("Re-read prices"). A missing price or quantity gives "Total value and shares unknown. Nothing is counted as 0.".

- "Value prorata": "Freeze shares" (Owner or Admin), then confirm. Current prices are copied into the operation, the operation moves to "Shares" and the settings no longer change. The button is refused when: a quantity is unknown or measuring; an ore has no price on the basis; the booster cut is above 0 with no booster chosen; participants deliver ore to the fleet storage with no recipient chosen, or more than they mined.
- "Each for themselves": "Mark completed". Same refusals for quantities and prices. Nothing to settle, the operation moves to "Completed" in the archive.

## 9. Pay and settle

In the "Shares" state, the "Payments" card lists who owes how much to whom ("X owes N ISK to Y"). Everyone keeps their ore and pays the difference between their mined value and their share: to the booster, who passes the corporation cut on, or directly to the corporation when the booster cut is 0. An extra rule is shown when ore goes through the fleet storage.

Once the donation is made in game, tick the line ("paid"). The screen lets the Owner, the Admin members and the payer of the line tick it. EVE Tools does not read the wallet for these payments: only the checkbox counts. When every line is paid, "Mark settled" then confirm: the operation moves to "Settled", in the archive, read only. The "Archive" tab lists "Settled" and "Completed" operations.

## 10. Notifications of an operation

"Mining Operations" category (titles are in English): "Mining operation join request" for a join request (Owner and Admin), "Mining ledger ready", "Mining shares computed" at "Freeze shares", "Payment received" when a payment is ticked for its beneficiary.

## 11. Recurring operations

In the step 1 form, "Recurrence" offers "Once", "Every week" and "At each chunk of a refinery" (if your corporation has a known refinery). The choice is absent when the operation starts from a chunk.

- "Every week": same day and same EVE time as the start. The next occurrence is created 7 days before its start at the earliest (checked every 30 minutes). It is linked to the chunk of the corporation arriving that day, if there is only one.
- "At each chunk of a refinery": one operation per extraction of the chosen refinery, at the chunk arrival, for the extractions started after the recurrence was created.
- An occurrence takes over the label, visibility, split and valuation (basis, market, side, structure, yield, cuts, booster if it was you) of the most recent one. The boost ship and the compressors are not taken over: declare them again. It enrols your characters that are in no other open operation: those of the previous occurrence are missing while it is still "Planned", "In progress" or "Closing".
- Occurrences carry the ↻ icon. The "Recurrences" panel of "My operations" gives the rule and "Next:", with "Stop" then "Stop the recurrence": existing operations are kept. A recurrence stops by itself if the organizer's corporation has changed.

## 12. Roles and reads for the moon tax

Three different reads, all made with the token of a character of the corporation registered in EVE Tools, not necessarily yours:

| Data | Role of the reader | Frequency |
|---|---|---|
| Chunks (extractions) | Station_Manager or Director | 30 minutes, or "Refresh" |
| Refinery ledger | Accountant or Director | 1 hour, or "Sync" |
| Wallet journal (donations) | Accountant, Junior_Accountant or Director | 1 hour, or "Sync" |

Any user with a character in a player corporation sees the chunks of that corporation, with or without a role. To **manage** the tax ("Manager" view), one of your own characters of the corporation must have Accountant or Director. Otherwise the screen says "Manager access denied" or "None of your corporations is manageable by you", and only the "My tax" view remains (step 17). Scopes and freshness: [data freshness and privacy](/help?page=data/freshness-privacy).

## 13. Read the chunks

"Mining" menu, "Moons" tab, "Chunks" sub-tab. Views "List", "Week", "Month"; "EVE time" or "Local time". Chunks stay visible until 14 days after their natural decay.

- "Status": "Extracting" before the chunk arrives, "Ready" from the arrival to the "Natural decay", "Exploded" afterwards.
- "Refineries": "Follow" to be notified ("Moon Chunks" category, set in "Account", "Settings", "Notifications" section) one hour before the arrival and at the arrival. Each alert is sent once, never for an event earlier than the follow, with a check every 5 minutes.
- "Value": estimate of the chunk before booster and tax, from the composition of the moon. Without a scan: "value unknown · paste the moon scan". Open the row, paste the Moon Survey Probe result in "Moon scan" then "Save the composition". A chunk with an ore without a price stays unvalued rather than partially valued. The first paste is open to any member; replacing it ("Paste a new scan") requires being the author, Director or Station_Manager. Figures: [ore value and moon tax](/help?page=calculations/ore-value-moon-tax).
- "Plan the operation" (chunk not "Exploded") opens the step 1 form with the moon name as label and the chunk arrival as start. The chunk's system is set at start. When an operation is linked, "Linked operation →" replaces the button, and the operation carries the "Linked chunk" card.
- "Miners" (chunk not "Extracting"): "Miners of this chunk, tax estimated at the current price". Reserved for accountants ("Reserved for the accountants of the corporation"). The refinery ledger is dated by day: mining goes to the chunk whose interval, from the arrival day to the decay day, contains that day (the most recent one if two overlap). The statement shown covers its whole period, not only this chunk.

## 14. Open a refinery to the tax (manager)

"Moon tax" sub-tab, "Manager" view. One card per refinery known from the chunks (otherwise "No known refinery, sync the extractions.").

- "Open to the alliance": this is the only switch that makes the refinery be read. Unticked, no ledger is read, no statement is created, no "Provisional" line appears. Despite its name, it does not filter by alliance: every miner of the refinery ledger is taken.
- "Period": "Weekly" (Monday 00:00 UTC) or "Monthly" (the 1st at 00:00 UTC).
- "Wallet division": 1 to 7, the division where donations arrive. It is used to read the journal.
- "R4 rate (%)" to "R64 rate (%)": 0 to 100, two decimals. The five rates and the division are required to "Save" ("Settings saved").

The tax of a type is its quantity × the Jita buy price of the compressed ore × the rate of its rarity. A type without a price, or outside the moon ore groups, makes the tax unknown. Detail: [ore value and moon tax](/help?page=calculations/ore-value-moon-tax).

## 15. Sync and read the statements

The "Sync" button starts the read without waiting for the hour ("Sync N %"). The "Mining ledger: ..." and "Payments: ..." banners tell the state of each read ("not synced yet", "access denied", "to reconnect", "ESI error", "last read on ..."). The two reads are independent.

**When a statement exists.** The period is over and the ledger was read successfully at least one hour after its end, at the end of a successful ledger sync. The statement then freezes the lines, the prices and the rates, and gets a reference `TAX-<year>-W<week>-xxxx` (weekly) or `TAX-<year>-M<month>-xxxx` (monthly). A change of rates or prices, or a late ledger line, no longer modifies it. The current period has no statement: it is "Provisional" ("period in progress"), computed on every reading with the current prices.

| "Status" | Meaning |
|---|---|
| "Provisional" | Current period, no reference. |
| "Unknown" | At least one type without a price or outside the moon groups: "unknown" and "no price: " followed by the type numbers. Never 0, and no donation is applied. |
| "Due" | Nothing paid, less than 7 days after the end of the period (or exactly 7 days). |
| "Partial" | Part of it is paid, within the same delay. |
| "Late" | Still owing, more than 7 days after the end of the period. |
| "Paid" | Nothing remaining, by donations or by "Mark paid". |
| "Cancelled" | Cancelled by a manager, left out of the totals. |

Columns: "Character", "Corporation", "Alliance" (those of the last line of the ledger for the period), "Refinery", "Period", "Reference", "Due", "Paid", "Remaining", "Status", "Alliance" ("Outside alliance" if the miner's corporation is not in the refinery's alliance; a plain flag, with no effect on the amount) and "Actions". Above: "Due", "Paid", "Remaining" ("unknown" with "of which ... known" if one line is unknown) and the "Outside alliance" count. "Provisional" statements count in these totals. "All" / "Late" filter, "Export CSV" with the same filter.

Actions: "Mark paid" (statuses "Due", "Late", "Partial"), "Cancel" then "Confirm the cancellation" or "Keep" (except "Cancelled" and "Provisional"). A statement marked paid by hand can no longer be cancelled, and the other way round, and "Mark paid" cannot be undone.

EVE Tools sends no notification about statements.

## 16. Match the donations (manager)

The "Donations received" section lists the ISK donations received by the corporation wallet over 90 days ("To attribute" by default, or "All"), with "Date", "Donor", "Amount", "Reason", "Attribution" and "Attribute to".

A donation pays a statement when all three conditions hold: same corporation, same payer (the character a manager attributed it to, otherwise the donor), and a donation date on or after the start of the statement's period. A donation pays the oldest statement first; the surplus goes to the next one. Statements marked "Paid" by hand, "Cancelled" or "Unknown" absorb nothing. **The "Reason" is never read**: putting the reference in the reason helps you read the list, it does not change the matching.

"To attribute" shows the donations whose payer has no statement in the corporation ("Provisional" included). For a donation made from another character: "Choose a miner" then "Attribute". The miner list comes from the statements shown: with the "Late" filter, it is reduced to the late miners. A new donation appears at the next journal sync.

## 17. Miner side: "My tax"

"My tax" view (no role required): your statements grouped by corporation, with "Period", "Refinery", "Character", "Due", "Paid", "Remaining", "Status", "Reference" and "Copy". Without a statement: "No moon tax for your characters".

To pay, make an in-game donation of the "Remaining" to the corporation, from the character that mined. The "How to pay" box also asks for the reference as the reason: it helps the manager, but the matching does not read it. Paid from another character, the payment must be attributed by the manager (step 16). Recognition waits for the journal sync: one hour at most.

## Check

- Operation: each character shows "✓ start" before you begin; in "Closing", no "unknown" quantity; "Freeze shares" lists no refusal.
- Tax: "Mining ledger" and "Payments" show no access banner; each followed refinery carries "Settings saved"; the "Provisional" line of the current period exists for each miner.

## Traps

- **Changing the "Period" of a refinery that already has statements.** The code does not compare with the statements of another split: at the next sync, the same mining days can produce a second statement (monthly after weekly ones, for example). Choose the period before the first sync.
- **Everything ESI returns is recorded,** including days before the refinery was opened: periods already over can produce statements right away, at the rates you entered.
- **The amount of a "Provisional" moves,** since it follows current prices. Only the frozen statement is stable.
- **A ledger read failure delays the statements.** If the ledger could not be read, no statement is created at that sync. Without a character holding the required role and a valid scope, the read stays "access denied" or "to reconnect" until someone logs in again.
- **A recurring occurrence can be created without some of your characters** if the previous occurrence is still open (step 11).
- **Ending too late.** An end snapshot read 30 days or more after the start day is lost: the quantity becomes unknown, to correct by hand.
