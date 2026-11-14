# Frequently asked questions

Short answers to the questions that keep coming back. Each one points to the playbook or the calculation page that goes deeper. One rule holds across EVE Tools: when a piece of data is missing, the screen says "unknown". It never puts 0 in its place.

## Account and characters

### Why does EVE Tools ask for so many ESI scopes?

All the scopes are requested in one go, each time a character is connected. A character authorized before a new scope was added shows "Missing scopes" until you reauthorize it.

Some scopes serve no feature for now: the code reads neither EVE notifications, nor fleet, nor location, nor online status, nor killmails. The `esi-planets.manage_planets.v1` scope has a misleading name: EVE Tools only reads your colonies.

The scope-by-scope table, with what each one feeds, is in the page about [data freshness and privacy](/help?page=data/freshness-privacy).

### How do I add an alt character?

On the Characters page, "Add character". You go through EVE SSO, pick the character, it is attached to your account and an asset sync starts. A character already attached to another EVE Tools account is refused.

To remove one, use the "Remove character" icon on its card. Its cached data is deleted. The main character cannot be removed: set another one as main first.

Step by step in [Getting started](/help?page=playbooks/getting-started).

### How do I change my main character?

On the card of an alt, the star (tooltip "Set as main"), then confirm. The choice matters more than it looks, because the main character sets:

- your corporation for EVE Tools: group project discovery and automatic acceptance (see below);
- the only name other project participants see, never the name of your alts;
- the sale fees copied into your new projects, computed with its Accounting and Broker Relations skills and its standings.

If the new main is in another corporation, the confirmation window warns you that your reference corporation changes.

### My character is "to reconnect": what do I do?

The access token renews itself. What breaks is the authorization: EVE SSO refuses the renewal when you revoked the application or the token expired. The character then drops out of the syncs, the others carry on, and Home shows a "character to reconnect" line under "To do" that leads to the Characters page.

To fix it, use "Reauthorize" when the button is shown. Otherwise, "Add character" and pick the same character in EVE SSO: its token is replaced, not duplicated.

Trap: the "Valid token" badge on the Characters page only looks at the scopes and at whether the token can be decrypted. It does not detect a revoked authorization. Trust Home.

### Who sees my data?

Your assets, wallet, skills, jobs and PI colonies are read for your account and shown to your account. What is shared:

- **with the participants of a group project**: your main character (name, corporation, role), your contributions and their value, the jobs linked to the project that you started (status, runs, cost, facility) under the name of your main, the sales you record;
- **with your corporation**: your published non-private projects, the project templates you choose to share, the configuration (type, security, rigs, facility tax) of a structure owned by your corporation that you imported;
- **corporation assets and blueprints**: they are read with the token of a connected Director character, and only a Director sets which divisions are visible (all members, a list of players, or none).

The exhaustive list, module by module, is in [the data privacy page](/help?page=data/freshness-privacy). For group projects: [participant](/help?page=playbooks/group-project-member).

### How do I delete my account?

Characters page, "Danger zone", "Delete my account". To confirm, you type the exact name of your main character. Deletion is immediate and cannot be undone.

- **Deleted**: all your characters, your EVE SSO tokens, your personal data (assets, skills, jobs, transactions, PI, alerts, notifications, settings), your project templates, and the projects you own when no other accepted participant can take them over.
- **Transferred**: a project you own, with other accepted participants, goes to its oldest admin, otherwise to its oldest accepted participant. Its linked jobs and purchases are emptied and its courier contract unlinked.
- **Kept, anonymized as "Deleted pilot"**: your approved contributions and the sales you recorded in other people's projects.

Tokens are revoked with CCP on a best-effort basis: if the revocation fails, the account is deleted anyway and the failure is only logged on the server.

## Figures that look wrong

### Why "unknown" instead of a price or a margin?

Because a plausible but wrong figure is worse than a gap. As soon as a piece of data is missing, the result that depends on it is "unknown", and a sum that contains an unknown is unknown, never partial.

Frequent causes:

- no Jita price for a material;
- ESI adjusted price or system cost index missing;
- facility tax of a player structure not entered (an entered 0 stays a real 0 %);
- invention probability unknown, skill not synced;
- seller fees unknown: skills or standings of the main character not synced yet;
- volume of an item missing from the static data.

Most screens name the missing data. Details in [known limits](/help?page=limits/known-limits), [margin](/help?page=calculations/margin) and [job cost](/help?page=calculations/job-cost).

### Why is my inventory out of date?

Assets are not scheduled. They sync when you log in, when you add a character, and with the "Refresh" button of the Inventory, which needs a character whose authorization is valid.

Corporation assets can also be refreshed on demand, at most once an hour: the screen shows "Refresh available at …" while you wait. They need a connected Director.

See [data freshness](/help?page=data/freshness-privacy).

### Why has nothing updated for several days?

The scheduled syncs (jobs, wallet, blueprints, sell orders, PI colonies, PVE, mining, Ansiblex) only run for an account that logged in through EVE SSO less than 7 days ago and has a character with a valid authorization. Those 7 days are also the length of a session. Log in again: logging in resets the counter.

### Why doesn't a finished job show up right away?

Jobs are re-read every 30 minutes, not on demand. Corporation jobs are read once per corporation and kept until the ESI `Expires`, so they can arrive even later. A character to reconnect is skipped.

A job finished in game is first to deliver (ESI status `ready`), then delivered (`delivered`) once you collect it. In the Slots tab, "Stale data" appears when the character's authorization is invalid or when the most recent running job is more than an hour old.

### Why does a project's cost differ from what I paid in game?

Two figures coexist.

- **The estimate.** When the BOM is computed, each material is valued at the Jita 5th percentile price, that is the average of the cheapest 5 % of the volume for sale. "Cost of what is left to produce" uses those prices, without the job install costs or what is already committed.
- **The real cost**, the sum of approved contributions: price paid for a linked purchase, ESI cost for a linked job, Jita price of the deposit day for a declared material, entered value for the rest.

Anything not linked or not approved is not in the real cost: an unlinked purchase, a contribution waiting for review, an unlinked job. A purchase only counts for the line of the same type. A material already in your hangar only has a cost if you declare it as a contribution.

See [group contributions](/help?page=calculations/group-contributions) and [job cost](/help?page=calculations/job-cost).

## Industry

### How are the structure and its bonuses chosen for a project?

A project's plan uses the owner's structures, favorite system and blueprints. Structures are declared in Industry, Configuration button, "Structures" section.

For manufacturing or a reaction:

1. the best **suitable** structure (one of its rigs targets the product's category) of the favorite system, meaning the one with the smallest material multiplier;
2. with no suitable structure in the favorite system, the best of all those that can run the activity, with a warning.

For copying and invention, it is the structure with the lowest install rate.

In the project's "Production" tab, the "Structures" card compares the total cost: materials, installation, transport. The "Allow for this project" button lets the plan leave the favorite system when the saving reaches the project's threshold (500,000 ISK by default).

Bonuses come from the structure type and the rigs you declared (3 at most). The facility tax of a player structure must be entered, otherwise the install cost is unknown. In an NPC station it is fixed at 0.25 %.

See [job cost](/help?page=calculations/job-cost).

### Which ME and TE are used?

When you create a project, you enter ME and TE for each product. A line added by hand starts at ME 2 and TE 4. A creation prefilled from another screen takes the values passed along, otherwise 0 and 0, with TE 4 for a T2 product.

But if the owner has a blueprint for the product, that blueprint replaces the entered values. Counted are the blueprints of all their characters and those of their main's corporation that are visible to them. The best one wins: highest ME, then highest TE, then BPO over BPC. Manufactured components are at ME 10 and TE 20 unless a blueprint is owned. A reaction has neither ME nor TE.

See [T2 project](/help?page=playbooks/t2-project).

### Buy or build?

The "Buy or build" card of the "Production" tab compares each intermediate of the plan:

- **build**: the total cost of its jobs, direct materials bought included;
- **buy**: the weighted Jita price of the requested quantity, plus the import to the workshop: volume in m³ × jumps from Jita to the favorite system × 150 ISK per m³ and per jump.

The choice is "build" on a tie, "unknown" as soon as one of the two costs is. The surplus of whole runs is not credited; the card shows it separately ("with the surplus reused").

"Switch to buy" and "Switch to build" change the project's blacklist and recompute the BOM. Once the project is out of draft, the switch is refused if it would remove a line that already has work on it (linked job, purchase or contribution).

See [weighted price](/help?page=calculations/weighted-price-appraisal).

## Group projects

### Who can join a project, and who approves?

A project is private by default. Private, it does not appear in the "Available" tab and its invite link answers like an unknown link. To be joinable, it must be non-private and Published or In Progress.

- **Same corporation as the owner** (that of the main character): accepted automatically. The "Available" tab only lists the Published projects of your corporation.
- **Another corporation**: the request goes into "Pending Requests". The owner or an admin handles it ("Accept" or "Reject").
- **Admins**: only the owner appoints them.
- **Removed or departed member**: comes back through the link, but as a request, with the member role.

See [group project owner](/help?page=playbooks/group-project-owner) and [participant](/help?page=playbooks/group-project-member).

### Why can't I touch an approved contribution anymore?

A contribution goes from pending to approved or rejected, reviewed by the owner or an admin. You do not review your own contribution, except the owner when nobody else can review: in a solo project, your contributions are approved when you submit them.

Once approved, it can no longer be rejected. One exception: a declared contribution that duplicates one EVE Tools detected ("Duplicate of an ESI contribution"), which reviewers can reject.

An approved contribution enters the real cost and the payouts, and it also locks the project: it can no longer be deleted, and a BOM line that carries one is never removed by a recompute. A Completed project freezes everything: no more contributions, reviews, sales or fee changes.

### How is a contribution valued?

By its type.

- **Declared material**: Jita price of the deposit day, 5th percentile of the sell orders, read at submission and frozen. You enter no value. With no Jita price that day, the contribution is refused.
- **Linked purchase** (owner): the price paid, read from wallet transactions. Approved automatically if you are the only reviewer of the project; otherwise it is a contribution proposal, waiting for its delivery confirmation (by the project container, or by hand by another owner or administrator).
- **Linked job**: the job's ESI cost, in the name of its installer. With no cost from the ESI, the value is unknown.
- **Job install, BPC, line rental**: entered value, strictly positive for job install and BPC. Line rental offers a suggestion drawn from your rate schedule.
- **Courier**: the reward of the linked contract, once delivered.

A contribution with no known price makes the totals that include it unknown, never partial. See [group contributions](/help?page=calculations/group-contributions).

### When is my payout exact?

Payout = costs you contributed (approved contributions) × (1 + project margin). The margin is (net revenue − total cost) ÷ total cost. Net revenue is the sum of recorded sales, minus the project's broker fee and sales tax, minus contract fees.

*Example, round values: a project costs 100 M ISK in approved contributions and brings in 130 M ISK net. The margin is 30 %. You contributed 40 M ISK: your payout is 40 × 1.3 = 52 M ISK.*

It is exact when:

- every product is sold: with no sale at all, the screen shows "Awaiting sales" and payouts are unknown; with partial sales, they are computed on the revenue so far, to be read as provisional ("Provisional margin" estimates the result if the unsold units went at the last price);
- the broker fee and sales tax are known (copied at creation from your seller fees, editable);
- every approved contribution has a value, and the total cost is not zero (otherwise "Undistributable distribution");
- pending contributions have been reviewed: they do not count before approval.

At that point, "Transfers between members" computes a transfer plan, the smallest possible up to 12 participants to settle and a simpler greedy one beyond, with the payment reference to put in the reason. See [group contributions](/help?page=calculations/group-contributions).

## Market

### Why does the EVE Tools price differ from Jita in game?

Jita prices come from an order book synced every hour and kept for 2 hours. It keeps the 20 best orders on each side, at the Jita 4-4 station, or across the whole The Forge region when the station has no order on that side.

Depending on the screen, the price is not the same:

- **weighted price** (Margins, Batch Scan, Buy vs Build, Pivot, Stockpile, project sourcing): the average of the orders consumed from best to worst until your quantity is covered;
- **5th percentile price** (a project's BOM, material contributions): the average of the cheapest 5 % of the volume for sale.

If your quantity exceeds the 20 orders kept, the price is **partial**: computed on what the book covers, shown with its coverage, never hidden. See [weighted price](/help?page=calculations/weighted-price-appraisal).

### Why is my order "undercut"?

A project's "My sell orders" card lists the sell orders of your characters for its products, re-read every 5 minutes. "Undercut" means at least one order at the same place is at a strictly lower price than yours. At an equal price, you are 1st. The rank is the number of cheaper orders, plus one.

"Copy" gives the best price minus 100 ISK, to paste when you edit the order in game. Nothing is modified from EVE Tools. "Market not followed" or "No market data" means unknown position, never "1st" by default. The "Log in again with …" message means the permission to read the character's orders is missing or the character's authorization is invalid.

See [market orders](/help?page=playbooks/market-orders).

## Planetary (PI)

### When is an extractor "expiring soon"?

By default, within 1440 minutes, that is 24 h. The threshold is set in "Notification Preferences", category Planetary, column "Threshold (min)". It drives the KPIs, the banner, the timers and the notifications. Colonies are re-read every 30 minutes for active accounts.

See [planetary colonies](/help?page=playbooks/planetary-colonies).

### Why is the PI production value lower than what my colonies produce?

Production is theoretical: chained cycles and schematics, valued at the Jita sell price. Only the sellable part counts. What your own factories consume is subtracted ("used in-house" in the table by tier), and a factory with no input is "Stopped".

Careful: today, a product with no Jita price counts for 0 ISK in the total. It shows "-", like a fully consumed product, and nothing flags the missing price. This goes against the "unknown, never 0" rule.

## Mining

### Why is the value of my mining "unknown"?

The value is the mined quantity × the unit price of the mining valuation profile. As soon as a mined type with a positive quantity has no price, the total is unknown, never a partial sum, and the types concerned are listed. A zero quantity is worth 0. The ledger is re-read every hour.

See [ore value and moon tax](/help?page=calculations/ore-value-moon-tax).

### How is the moon tax computed, and how do I pay it?

Per miner, refinery and period (week or month, in EVE time): mined quantity × unit price × the rate for the ore's rarity. Rates are set by the refinery owner. The price is the Jita buy price of the equivalent compressed ore. An ore with no price or no known rarity makes the tax unknown.

The statement is frozen when the period closes and carries a `TAX-…` reference. Payment is an ISK donation to the corporation wallet. It is matched to the statement by corporation, donor character (or the character a manager attributed the donation to) and date. The donation reason is not read. An unpaid statement turns "Late" 7 days after the end of its period. Your share is in the "My tax" view.

See [mining and moon tax](/help?page=playbooks/mining-moon-tax).

## PVE

### Where does my PVE income come from?

The PVE sync reads the last 30 days of each character's wallet journal: bounties, ESS and agent mission rewards.

Loot sales are only read from your wallet transactions for the types you configured ("Loot types"). The loot contracts imported are finished item exchange contracts you issued, at a price above 0 ISK: a 0 ISK contract is not imported. With no loot type configured, only loot contracts and corporation projects are read.
