# Getting started

This playbook goes from your first EVE SSO login to a usable account: your characters attached, your skills read, your production structures declared, and a clear idea of what updates on its own. Follow the steps in order, each one assumes the previous one.

Labels between quotes "" are the ones on screen in the English interface.

## 1. Log in with EVE SSO

On the login screen, click the official "Log in with EVE Online" image. You go through EVE SSO: pick the character and authorize the application. All the scopes are requested in one go (see the [FAQ](/help?page=faq/faq)).

On the way back, EVE Tools opens your session and takes you to Home. On your first login, that character becomes your main character. An asset sync for your characters starts at every login.

- The session lasts 7 days. After that, log in again: without a recent login, the scheduled syncs stop for your account (see the table at the end of the page).
- If an "EVE Online servers are under maintenance" banner shows up, ESI is degraded or unreachable. Try again later. That banner and the login error messages stay in English.

## 2. Read Home

You land on "Home", from top to bottom:

- "To do": one line per action (character to reconnect, PI extractor stopped or about to expire, price alert triggered). With nothing to handle: "All caught up". A source that could not be loaded gives a line "…: unknown", never a counter at 0.
- "In progress": mining operations, jobs finishing soon, next chunks, PI extractors. A new account shows "No job running" and the like until the first syncs have run.
- "Balance of the last 30 days": PVE, Mining, Industry, Total, with the "Balance" link.
- The "Characters" and "Wallet" cards ("total ISK"), then "Your characters" and the "Activity" column.

Two figures to read carefully right now.

**"total ISK"** is the sum of your characters' balances, read from ESI when you open the screen. An unreadable balance is not counted and the screen adds "Partial total: N unknown balance(s)": read the total as a minimum. Pitfall: if no balance is readable, the total shows "0" with that mention, which is not a real zero.

**The "Balance of the last 30 days"**:

- "PVE": the PVE income recorded over the period. With no entry, 0 means "nothing recorded", not "measured at zero". Where it comes from: [FAQ](/help?page=faq/faq).
- "Mining": the value of your mining over the period. If a mined type has no price, it is "unknown" and "Total" is too. Calculation: [ore value and moon tax](/help?page=calculations/ore-value-moon-tax).
- "Industry": your profit share of the projects completed over the period. A completed project whose profit is unknown is not counted, and Home does not flag it: the Balance page gives their number. Calculation: [group contributions](/help?page=calculations/group-contributions) and [margin](/help?page=calculations/margin).
- "Total": PVE, Mining and Industry added up, without subtracting expenses.

## 3. Add your other characters

"Account" menu, "Characters" tab. Click "Add character" (the same button exists on Home, above "Your characters"). Pick the character to add in EVE SSO. You come back to the Characters page, and an asset sync for it starts.

The card shows up under "Same corporation (N)" or "Other corporations (N)", depending on the corporation of your main character.

- A character already attached to another EVE Tools account is refused (the message is in English).
- A character already attached to your account gets its token replaced, it is not duplicated.
- To change your main character: the star "Set as main" on a card, then confirm. This choice sets your reference corporation and the seller fees of your projects, details in the [FAQ](/help?page=faq/faq).
- To remove one: "Remove character". The main character cannot be removed.

## 4. Check the state of each card

Each card shows:

- an authorization badge. On the main character card: "Valid token", "Token expired" or "Missing scopes". On the other cards: "Valid", "Expired" or "Missing scopes";
- the character's wallet, or "Unknown" if it could not be read;
- under "Training", the current skill and "N in queue", or "No skill training". Pitfall: that last message also shows when the queue could not be read.

If the badge is not green, use "Reauthorize" (button on the main character card; icon on the other cards, whose tooltip reads "Missing scopes" when scopes are missing). You go through EVE SSO again with the same character, and its token is replaced.

Pitfall: a green badge does not prove that the authorization was not revoked at CCP. Home, the "character to reconnect" line in "To do", is more reliable. See the [FAQ](/help?page=faq/faq).

## 5. Sync the skills

Skills are never synced automatically. Without them, the seller fees (broker fee and sales tax, computed from the Accounting and Broker Relations skills and the standings of your main character) stay unknown, and the slot limits can be wrong. See [market fees](/help?page=calculations/market-fees).

1. "Industry" menu, then the gear icon (tooltip "Configuration"). Other path: "Account", "Settings", "Industry" section.
2. Expand the "Skills" section and click "Sync all".
3. Check the table: one row per character, with Industry, Adv. Industry, Reactions, the "Source" column ("ESI" after a sync) and "Last sync". Characters that could not be read are listed under "Skills not synced for N character(s):", with the reason.

The same button also reads the character's standings.

- Before the first sync, the table shows levels at 0 with the source "Manual": that is a missing value, not measured levels.
- A level you set by hand stays "Manual" and is not overwritten by the sync.
- In Industry, "Production" tab, "Slots", a warning "Slot limits may be inaccurate…" with the "Sync Skills" button opens that same section.

## 6. Declare your production structures

A production structure carries the structure and rig bonuses used by the [job cost](/help?page=calculations/job-cost). Without a declared structure, a project plan applies no structure or rig bonus.

1. Open "Configuration" as in step 5, "Structures" section (or "Settings", "Industry").
2. "Favorite systems" block: pick a system for "Manufacturing" and one for "Reactions".
3. "Production structures" block: click "Add". The "New structure" form is filled in three ways: "Corporation shared structures", "Or search by name (ESI)" (at least 3 characters) or typing the name by hand.
4. Fill in "Name", "Structure type", "Security", up to 3 rigs and "Facility tax (%)" (from 0 to 50). The "Calculated bonus (base structure + rigs x security)" box gives a preview. Confirm with "Add".

Pitfalls:

- "Security" is pre-selected on "Null-Sec". An ESI search fills in the name and the type but not the security: check it before saving.
- A facility tax left empty shows "not set", and the install cost of jobs in that structure is unknown, not computed with 0 %. Enter 0 if the structure really charges nothing. For an "NPC Station (no bonus)", the tax is fixed by the game at 0.25 %.
- Prefer importing (corporation list or ESI search) to typing by hand: the details, including sharing with the corporation, are in [Configuring your industry structures](/help?page=playbooks/industry-structures).

## 7. Pick a market structure (optional)

Do not confuse it with a production structure. It is used to compare prices with Jita. "Account", "Settings", "Market" section, "Market Structure" block: search a structure (at least 3 characters), the choice is saved right away ("Structure saved"). How prices are read: [weighted price](/help?page=calculations/weighted-price-appraisal).

## 8. Fill in the inventory

"Wealth" menu, "Inventory" tab. Assets arrive with the sync started at login or when the character was added. Until they are there, the screen shows "No assets".

To trigger it again: the "Refresh" button at the top right, or the "Refresh from EVE" link when the list is empty (the screen then shows "Sync in progress" until it ends). A character with a valid authorization is required. Corporation assets ("Corporation assets") have a delay between two refreshes ("Refresh available at …") and need a connected Director.

## What updates on its own, and what needs a click

Conditions common to the per-player syncs: an EVE SSO login less than 7 days old and, for the per-character syncs, a valid authorization. A character to reconnect is skipped, the others carry on.

| Data | Pace | Notes |
|---|---|---|
| Industry jobs | every 30 minutes | |
| Blueprints | every hour | Corporation ones need a connected Director. |
| Characters' sell orders | every 5 minutes | |
| Wallet transactions | 5-minute tick | Only re-reads the characters whose ESI `Expires` has passed. |
| Planetary colonies | every 30 minutes | |
| PVE, mining | every hour | |
| Ansiblex | every 12 hours | |
| Jita prices | every hour | Shared by all players. |
| Cost index | every 2 hours | Shared. |
| Adjusted prices | every 24 hours | Shared. |
| Public contracts | every 30 minutes | Shared. |

Only updated on action or when opened:

- **assets**: login, adding or reauthorizing a character, then "Refresh" (step 8);
- **skills and standings**: "Sync all" (step 5), never scheduled;
- **wallet balances and skill queue**: read from ESI when you open Home and the Characters page;
- **structures, favorite systems, market structure**: what you entered, nothing changes them on its own.

The age of each shared data set, and what each scope feeds: [data freshness and privacy](/help?page=data/freshness-privacy).

## Final check

- Home has no "character to reconnect" line.
- Every card on the Characters page shows a green badge, and the "Skills" table says "ESI".
- At least one production structure is declared, with its facility tax.
- The Inventory shows your assets.

If a figure stays "unknown" despite all this: [known limits](/help?page=limits/known-limits) and the [FAQ](/help?page=faq/faq).

## What next

- A first T2 project: [T2 project](/help?page=playbooks/t2-project).
- A project with others: [owner](/help?page=playbooks/group-project-owner) or [participant](/help?page=playbooks/group-project-member).
- Follow your orders: [market orders](/help?page=playbooks/market-orders).
- PI colonies: [planetary colonies](/help?page=playbooks/planetary-colonies).
- Moon mining: [mining and moon tax](/help?page=playbooks/mining-moon-tax).
