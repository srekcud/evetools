# Mining operation and moon tax: from plan to settlement

This guide follows a mining operation in the order you run it: plan, invite, start, end, read the shares, settle. It then covers your personal ledger, and the moon tax of a refinery, from the manager's side and the miner's side. Each step names the screen and the button as they appear.

Everything happens in the **Mining** module of the side menu, which has five tabs: **Operations**, **Moons**, **Sites**, **Ore value**, **My mining**. Times in the application are in EVE time (UTC).

## The rule that runs through this guide: unknown is never 0

When a quantity or a price is missing, the value shown is **unknown**, never 0 and never a partial sum. A single unknown quantity makes the total value and every share unknown, and the operation cannot be frozen until the case is resolved. The causes are listed on screen.

## Part 1. Running a mining operation

An operation goes through statuses, tracked in the step bar at the top of the operation (**Each for themselves** ends at **Completed**, the prorata mode ends at **Settled**):

| Status | What happens |
|---|---|
| **Planned** | Participants sign up, rules are set. |
| **In progress** | Each character's start snapshot is taken. |
| **Closing** | The end snapshot is read, the quantities and the shares preview appear. |
| **Shares** | Shares are frozen, payments are tracked. |
| **Settled** | Every payment is marked paid, the operation is archived, read only. |
| **Completed** | End of an operation in **Each for themselves** mode (nothing to settle), archived. |

The owner (**Owner**) and the **Admin** members run the operation. A plain participant does not start or end the operation: they join, leave while the operation is **Planned**, leave early, and read.

### 1. Create the operation

1. **Mining** menu, **Operations** tab, **New operation** button.
2. In **New mining operation**, fill in:
   - **Label** (required) and **Start (EVE time)**;
   - **Visibility**: **Private (link)** (default) or **My corporation**. **My corporation** is greyed out if your corporation is unknown;
   - **Split**: **Value prorata**, **Each for themselves** (everyone keeps their own ore, no share, no payment), or **Weighted time**, marked **soon** and not selectable;
   - **Recurrence**: **Once**, **Every week** (same day and time in EVE time as the start) or **At each chunk of a refinery** (one operation per extraction, at the chunk arrival);
   - **Boost ship and compressors**, optional (**Not declared** by default, editable afterwards in the **Valuation** block);
   - **Your characters**: tick the ones that mine (**Select all ready** and **Clear** help).
3. Click **Create operation**. The **Operation created** screen offers **Open operation**.

A character can only be in one open operation. A character with an invalid token is marked **reconnect needed**: its quantity would be unknown, so reconnect it before ticking it.

**From a moon chunk**: **Moons** tab, **Chunks** view, **Plan the operation** button on the chunk's row. The chunk's system is reused and set when the operation starts (message **The system is set when the operation starts.**). A chunk that has exploded is no longer offered.

### 2. Invite participants

On the operation page, the **Invitation link** block gives the link to copy (**Copied!**). **Renew link** invalidates the previous one.

- A member of your corporation is accepted right away.
- A member of another corporation sees **Request sent**: the owner or an admin has to accept it. In the **Participants** block, the line shows **pending request**, with **Accept** and **Refuse**.
- A participant can **Leave operation** while it is **Planned**. The owner can **Delete operation** in that status only.

A **My corporation** operation also shows up in the **Available** tab of the list, with a **Join** button. Your operations are in **My operations**.

### 3. Set the valuation and the shares

The **Valuation** block can be edited while the operation is **Planned**, **In progress** or **Closing**. **Save settings** button.

| Setting | Choices (default first) |
|---|---|
| **Valuation basis** | **Compressed**, **Raw**, **Reprocessed**. A compressed unit is worth a raw unit. |
| **Market** | **Jita** or **Tracked structure** |
| **Side** | **Buy** or **Sell** |
| **Reprocessing yield** | Shown with the **Reprocessed** basis, prefilled from your reprocessing profile |
| **Booster cut** and **Corporation cut** | 0 % by default |
| **Booster** | **None**, or a participant |

The rule displayed under the form sums up the calculation: **G** is the sum of the quantities multiplied by the unit price of the basis. The booster receives G × booster cut, the corporation G × corporation cut, and each participant their mined value × the rest. The calculation is done in whole ISK: the rounding remainder goes to the largest fractions, and the check line shows it (**rounding remainder**).

In **Each for themselves**, there is no share, no booster and no payment: each person's mined value is only computed.

The **Fleet storage** block (pick a recipient, each participant declares whether they deliver their ore) creates a debt of the recipient towards each depositor: their share minus what they kept. A **Corporation container to check (optional)** (exact name) compares what was delivered with the corporation assets; the finding does not prevent freezing the shares.

Before the start, the **How many miners?** block estimates the number of miners from your own sites scanned until depletion. It shows nothing while the history is short (at least three sites of that type with a known crew), never an invented duration.

### 4. Start

**Start operation** button (owner or admin). The **Start "…"** form offers **Solar system (optional)**:

- with a system, only the ledger lines of that system count (**Counted in … only**);
- without a system (**All systems**), mining done outside the operation during the window is included (**Counted in every system**).

**Start** button. Each character's ESI ledger start snapshot is taken at that moment. A late participant gets theirs when accepted. Under **Participants**, each character shows its state: **✓ start n/m**, or **✗ start n/m**. An unreadable snapshot is relaunched with **Retry**. A character that needs reconnecting gives **start snapshot not read, quantity unknown**.

### 5. During the operation

- Presence (**Online**, **AFK · n min**, **Left**, **Not seen yet**) comes from the last sign of life in EVE Tools, not from the in-game state, which the ESI does not give.
- **I'm leaving** (early departure) takes your end snapshot right away and cannot be undone. It bounds your mining.
- The owner or an admin can click **End operation** at any time.

### 6. End and wait for the end ledger

**End operation** button, then **End at …** in the confirmation. Anything mined afterwards does not count.

The ESI caches the mining ledger for 10 minutes. The **Waiting for the end ledger** screen shows the read time (**read in**) and the progress (**n / m characters**). Nothing is estimated while waiting. The status is **Closing**.

### 7. Read the quantities

The **Mined quantities** block gives, per character and per ore, the difference between the end ledger and the start ledger. A quantity is **unknown** (never 0) when:

- the character's token is invalid (**✗ invalid token · quantity unknown**) or the read failed (**✗ read failed**);
- the end snapshot is missing (**✗ no end snapshot · quantity unknown**);
- the operation is too old for the ledger, which covers 30 days (**✗ beyond the 30-day ledger**);
- an end line is lower than the start line, which makes the ledger inconsistent.

A quantity still being read shows **measuring**. The owner or an admin can **Correct a quantity** (forgotten alt, mining outside a site); the correction keeps a trace of who, when and the originally measured value.

### 8. Read the shares preview, then freeze it

In **Closing**, the **Value prorata split preview** shows, per participant, **Quantity (u)**, **Mined value**, **% of G**, **Share**, **ISK/h**, and the cards **Total value G**, **Booster cut**, **To share** and **Operation ISK/h**. Prices are read now and recomputed on every read (**Re-read prices**).

**Freeze shares** button, then confirmation. The current prices are copied into the operation, and quantities, prices and shares no longer change. The button is refused, with the reason on screen, if:

- the operation is not **Closing**;
- a quantity is unknown or still being measured (the names are listed);
- an ore has no price on the chosen basis;
- a booster cut is set without an accepted **Booster** in the operation;
- a participant declares delivering ore without an accepted fleet storage recipient, or delivers more than they mined.

### 9. Pay, then settle

**Shares** status. The **Payments** block lists who owes what to whom (**… owes … ISK to …**):

- Everyone keeps their ore and gives the booster the difference between their mined value and their share. The booster passes the corporation cut on. Without a booster, the difference goes to the corporation.
- The owner or an admin ticks the line by hand once the ISK is given in game: the application does not detect the donation.

Once every payment is marked paid, **Mark settled** button, then confirmation: the operation moves to the archive, read only. In **Each for themselves**, the button is **Mark completed**: prices and mined values are frozen, nothing to settle.

The **Archive** tab of the list shows your settled or completed operations and those of your corporation open to the corporation. The **Summary to share** block copies a text or an image to paste into Discord (**Copy the text**, **Copy the image**). No webhook is used and the text contains no participant name.

## Part 2. Following your personal ledger

**My mining** tab, **Ledger** view. The ESI ledger of your characters is synced every hour; the **Last sync:** line gives the time, and **Refresh** triggers a read. The period buttons (and **YTD**) filter what you read.

The value shown (**Total value**, **ISK / active day**) comes from your settings in the **Ore value** tab (structure, export tax). An ore without a price makes the value unknown and it is named (**Ores without a price: …**), never counted as 0. The **Season** view gives, for your corporation, the operations completed over the period (**30 days**, **90 days**, **All**): mined hours per player and ISK/h per ship. An operation whose shares are not frozen counts its hours but not its ISK (**Season value unknown**).

## Part 3. The moon tax of a refinery

**Moons** tab, **Moon tax** view. Two displays: **Manager** and **My tax**. If none of your corporations is manageable by you, the application opens **My tax** directly.

### How the tax is calculated

For each character, each refinery and each period, from the refinery's ledger:

tax = sum, over each ore type, of **quantity × unit price × rarity rate ÷ 100**, rounded to 2 decimals per line.

- The **unit price** is the **Jita buy** price of the ore in compressed equivalent, with no export tax and no reprocessing yield.
- The **rarity** (R4, R8, R16, R32, R64) comes from the moon ore's group. Each rarity has its own rate, set by the manager.
- A type with no price, or outside the moon ore groups, makes the tax **unknown**: the statement shows **Unknown**, **Due** and **Remaining** show **unknown** and the list **no price: …**, never 0.
- The period is **Weekly** (from Monday 00:00 UTC to the following Monday) or **Monthly** (from the 1st 00:00 UTC to the 1st of the next month), chosen per refinery. A ledger line belongs to the period that contains its date.

### Provisional statement and frozen statement

- While the period is running, the line is **Provisional**, computed at current prices, with **period in progress** in place of the reference.
- A finished period is frozen as soon as the refinery's ledger has been read at least 1 hour after its end. The **statement** then carries its frozen lines, prices and rates: it no longer changes. It gets a **reference** of the form `TAX-<year>-W<week>-XXXX` (weekly) or `TAX-<year>-M<month>-XXXX` (monthly), to copy with **Copy**.

### Manager side: open a refinery

You need the corporation role **Accountant** (or **Director**) on one of your characters, with the scope that reads roles. Without it, the corporation shows **Manager access denied**, **Corporation roles unreadable**, or **None of your corporations is manageable by you**, with the detail of the missing role or the character to reconnect. Roles take up to about 1 h to show up on the ESI side.

1. **Moons** tab, **Moon tax** view, **Manager** display. One panel per corporation, with a card per refinery. The list comes from the extractions: **No known refinery, sync the extractions.** if it is empty.
2. Fill in the card: **Open to the alliance** (checkbox), **Period** (**Weekly** or **Monthly**), **Wallet division** (from 1 to 7), **R4 rate (%)**, **R8**, **R16**, **R32**, **R64** (from 0 to 100 each). All fields are required.
3. **Save**: the message **Settings saved** confirms.

**The Open to the alliance checkbox is what opens the refinery**: only ticked refineries are read by the sync, counted in the statements and visible to miners. Unticked, the refinery disappears.

The sync runs every hour for each corporation that has an open refinery; the **Sync** button launches it right away. It reads two sources, shown under **Mining ledger** and **Payments** when they have a problem (**not synced yet**, **access denied**, **to reconnect**, **ESI error**, with **last read on**):

- the observers' ledger, with the **Accountant** role and the scope that reads corporation mining;
- the wallet journal of the chosen **Wallet division**, with the **Accountant** or **Junior_Accountant** role and the scope that reads corporation wallets.

### Manager side: follow the statements

The table lists **Character**, **Corporation**, **Alliance**, **Refinery**, **Period**, **Reference**, **Due**, **Paid**, **Remaining**, **Status**. The **All** / **Late** filter and **Export CSV** are above, the totals **Due**, **Paid**, **Remaining**, **Outside alliance** below (a partial total shows **unknown**, **of which … known**).

| Status | When |
|---|---|
| **Provisional** | Period in progress |
| **Due** | Frozen statement, nothing received |
| **Partial** | Part of the amount is covered by donations |
| **Late** | Remaining to pay, more than 7 days after the end of the period |
| **Paid** | Received donations are enough, or **Mark paid** was clicked |
| **Cancelled** | **Cancel** then **Confirm the cancellation** (**Keep** to back out) |
| **Unknown** | Unknown tax (missing price or rarity) |

**Mark paid** is offered for **Due**, **Partial** and **Late**. A cancelled statement can no longer be marked paid, and a statement marked paid can no longer be cancelled. **Cancel** is not offered on a provisional or already cancelled statement. The **Outside alliance** mention flags a statement whose recorded corporation is not in the alliance of the refinery's owner (different alliances, or one of the two corporations without an alliance); it stays empty when the ESI did not answer.

### Manager side: attribute donations

The **Donations received** panel lists 90 days of donations made to the corporation (filter **To attribute** or **All**). The application applies a character's donations to their statements in period order, oldest first. **A donation pays the statements of the character who made it, received from the start of the period.** If the miner paid from another character, choose **Choose a miner**, then **Attribute**: the donation then counts for that miner.

### Miner side: read and pay

**My tax** display: your statements, all characters combined, grouped by corporation, with **Period**, **Refinery**, **Character**, **Due**, **Paid**, **Remaining**, **Status**, **Reference**. The **How to pay** block says what to do: a donation in game of the remaining amount to the corporation, with the statement's reference as the reason. The payment is recognised after the next wallet journal sync, which can take up to one hour. If you pay from another character, the manager matches the payment by hand. **No moon tax for your characters** shows if nothing is due.

## Known traps

- **Unticked refinery**: without **Open to the alliance**, no statement, no sync, no line for miners.
- **The donation reason is not read**: the application matches a donation to a statement by the donating character and the date, not by the `TAX-…` reference. The reference helps the manager check by hand.
- **Roles**: both syncs require the **Accountant** role (or **Junior_Accountant** for the wallet). A character without the role, or with an invalid token, leaves the corporation without data.
- **Unknown quantity of an alt**: an alt that needs reconnecting or fell outside the ledger window blocks **Freeze shares** for the whole operation, until it is reconnected or you **Correct a quantity**.
- **Operation without a system**: mining done outside the operation during the window is counted.
- **Weighted time**: announced, not available.
