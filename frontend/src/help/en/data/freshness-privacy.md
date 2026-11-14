# Data freshness and privacy

This page says where EVE Tools figures come from, how old they can be, what the application keeps about you, who else can see it, what is purged and how to delete everything. One rule holds everywhere: when a piece of data is missing, the screen says "unknown". It never puts 0 in its place.

## Where the data comes from

For its data, EVE Tools only queries three services: ESI (the EVE Online API), EVE SSO (the login) and CCP's developer portal for the SDE. There is no third-party price site.

| Source | What it provides | Read with |
|---|---|---|
| ESI, in your name | assets, wallet, skills, jobs, market orders, PI colonies, blueprints, mining of your characters | the token of each of your characters |
| ESI, for a corporation | assets, blueprints, jobs, moon extractions, refinery ledger, wallet journal | the token of a corporation character who holds the required role, not necessarily yours |
| Public ESI | Jita (The Forge) order books, adjusted prices, cost indices, market histories, public contracts | no token |
| ESI, a player structure's order book | the structure's orders | the token of a single player who follows that structure and has the structure markets scope |
| SDE (CCP's static data) | types, blueprints and recipes, systems, stations, PI schematics | manual import, by command |

The SDE does not update itself. A daily check compares the latest version published by CCP with the imported one and warns the administrators, without importing anything.

### Why "unknown" rather than 0

A plausible but wrong figure is worse than a gap. If a price, a cost index, a skill or a balance is missing, every result that depends on it is "unknown", and a sum that contains an unknown is unknown, never partial. A displayed 0 means the data is known and equals zero, or, for PVE income, that no entry is recorded.

The known exceptions, where the screen shows a 0 that is not one, are in [known limits](/help?page=limits/known-limits). The usual causes of an "unknown" are in the [FAQ](/help?page=faq/faq).

## Scopes

A scope is a permission you grant to EVE Tools in EVE SSO, per character.

- **All scopes are requested together**, at each login and each "Add character". There is no scope-by-scope choice.
- **A requested scope is not necessarily read.** Some serve no feature for now. The table below says, scope by scope, what each one feeds.
- **"Missing scopes"** appears on the card of a character whose token does not have every scope requested today, for example because it was authorized before a scope was added. The "Reauthorize" button fixes it.
- **A missing scope keeps the character out of the feature concerned.** The wallet, market order, blueprint, PI colony and job syncs each check their own scope and do not read the data of a character that lacks it.
- **For a corporation, the scope is not enough.** The character must also hold the in-game role (Director, Station_Manager, Accountant depending on the data). Without a connected character that holds it, the corporation data is not read.

**Scope-by-scope table: generated table, #935.** One line per scope, with the feature it feeds. It will replace this sentence.

## Sync frequency

### Scheduled and on-demand

- A **scheduled sync** starts by itself, at a fixed interval, without any action from you.
- An **on-demand sync** starts from an action: login, adding a character, a "Refresh" button or equivalent, opening a screen.

**Table of scheduled syncs and their frequencies: generated table, #935.** It will replace this sentence.

### When a scheduled sync skips your account

The scheduled syncs for jobs, wallet, blueprints, sell orders, PI colonies, PVE, mining and Ansiblex only run when both conditions hold:

1. your last **EVE SSO login** is less than 7 days old (those 7 days are also the length of a session);
2. the **authorization is valid**: the character's own for per-character syncs (jobs, wallet, blueprints, sell orders, PI colonies), that of at least one of your characters for per-account syncs (PVE, mining, Ansiblex).

Adding a character does not count as a login. A character whose authorization is revoked drops out of the syncs on its own: the others keep going. It comes back when you reconnect it.

Some other scheduled syncs do not apply this login condition. They depend on a character that has the right scope and role: a structure's order book (read with the most recently logged-in player who follows it and has the scope), and a corporation's moon extractions and moon tax.

### What is never scheduled

- **Assets**: at login, when a character is added or reauthorized, and with "Refresh". Corporation assets refresh on demand, at most once an hour, with a connected Director.
- **Skills and standings**: on demand only, through the skills sync.
- **Wallet balances**: read from ESI when you open Home and the Characters page, never stored.
- **A price missing from the cache**: read straight from ESI and kept for 5 minutes.

Several screens also have a button to re-run a scheduled sync right away (blueprints, PI colonies, Ansiblex, moon extractions, moon tax).

### ESI cache delays

Each ESI response carries an expiry date (`Expires`) and often a fingerprint (`ETag`). EVE Tools honors them in three cases, and nowhere else:

- **Wallet**: a character is only read again once the `Expires` of its last reading has passed. Without an `Expires`, the next reading waits 1 hour. After a failure, it waits 15 minutes.
- **Corporation jobs**: read once per corporation and kept until the `Expires`, for all its members.
- **Public profiles** (character, corporation, alliance, moon): kept until the `Expires`, or 5 minutes when ESI gives none. After that, EVE Tools asks again with the `ETag`: an "unchanged" answer reuses the copy. The copy is kept for 7 days. If ESI is unreachable, that copy is served, even when it is several days old.

When ESI answers 420 or 429 (error budget exhausted or rate limit), the client waits for the indicated delay then replays the request once. If it fails again, the reading fails.

ESI data is as old as the last in-game event. Example: a PI colony reflects your last in-game interaction, see [planetary colonies](/help?page=playbooks/planetary-colonies).

## What is stored

| Type of data | What is kept | Attached to |
|---|---|---|
| Account | creation date, last login date, main character, settings (industry blacklist, followed market structures, line rental rates) | your account |
| Characters | EVE ID, name, corporation, alliance, authorization state, last sync date | your account |
| EVE SSO tokens | access token, refresh token, expiry date, list of granted scopes | each character |
| Data read from ESI | assets, skills, attributes, standings, jobs, wallet transactions and journal, sell orders, PI colonies (pins, routes), blueprints, mining entries | each character or your account |
| What you enter | projects and project templates, production structures, stockpile, favorites, price alerts, PVE income and expenses, escalations, mining operations, notifications, browser push subscription | your account |
| Corporation data | corporation assets and blueprints, moon extractions, refinery observer records, corporation wallet donations, moon tax statements, moon compositions | the corporation, not an account |
| Common data | SDE, market prices and histories, structure order books, adjusted prices, cost indices, public contracts | no account |

Your wallet balance is not stored.

**Tokens.** The refresh token is encrypted before it is written to the database. The access token, short-lived, is kept as is. No other data is encrypted by the application. The temporary copies of ESI responses held in cache are tagged per character and invalidated when the account is deleted.

Administrators have a support sheet per user: characters, token state, missing scopes, creation and last login dates, date of the last data received per sync type. It holds no assets, no wallet and no token.

## What is shared

By default, your character data (assets, wallet, skills, jobs, PI colonies, orders, PVE, mining) is only visible to your account. What leaves your account:

| What | Who sees it | How it is set |
|---|---|---|
| Your main character in a **group project**: name, ID, corporation, role, status, total and number of contributions, join date | the project participants | the main's name only, never a secondary character |
| In a group project: your contributions and their value, their notes, the linked jobs you started, the sales entered, the activity journal | the project participants | a job's installer is shown under your main's name, and under the character's real name to you alone |
| A **non-private group project** | members of the owner's corporation while it is Published ("Available" tab), and any logged-in user who has the invite link while it is Published or In Progress | a project is private by default; private, it answers like an unknown link |
| Your **project templates** | your main's corporation | shared only if you choose to; not shared by default |
| The configuration of a **production structure** (type, security, rigs, facility tax) that your corporation owns | the other corporation members, who each keep their own copy | automatic when the structure is imported |
| **Corporation blueprints** | corporation members, as soon as a Director is connected | all blueprints of all divisions are visible by default; a Director can untick "Show corporation blueprints from hidden divisions" |
| **Corporation assets** | corporation members, division by division | nothing is visible until a Director sets the visibility (all members, a list of players or hidden) |
| A **mining operation** | its participants: main's name, corporation, role, presence ("Online", "AFK", "Left"), and the characters you enroll in it (name and ID); members of your corporation also see in the list the ones set to "My corporation" | "Private (link)" by default: people join through the link |
| An **escalation** | you alone, your corporation, your alliance, or everyone without an account | "Visible only to me" by default; as "Public", the character name, system, price and notes can be read without logging in while it is for sale and not expired |
| A **shared appraisal** | anyone with the `/s/…` link, no account needed | see below |
| A **structure's order book** | everyone who follows it | read with the token of a single player, common result |

For moon extractions and moon tax, who sees what is detailed in [mining and moon tax](/help?page=playbooks/mining-moon-tax). For group projects: [owner](/help?page=playbooks/group-project-owner) and [participant](/help?page=playbooks/group-project-member).

**A shared appraisal is public through its link.** It holds a frozen copy of the appraisal: lines, totals, transport cost, comparison structure. No character name appears in it, but the name of the comparison structure, if there is one, does. The link is short and random, and the preview page it opens is served with the `X-Robots-Tag: index, follow` header: a search engine that finds the link may index it. Do not share an appraisal whose content is confidential.

## What is purged and when

Daily tasks delete:

| Data | Purged when |
|---|---|
| Notifications | more than 7 days after their creation, read or not |
| Sync runs (technical history of the syncs) | more than 30 days |
| Shared appraisals | once expired, one week after their creation |
| Market price history | more than 365 days |
| Structure order book snapshots and traded volumes | more than 90 days |

Other data is replaced at each successful reading or stays as long as the account exists. The code does not purge an inactive account: after 7 days without a login, only its scheduled syncs stop. The data attached to a character removed with "Remove character" (assets, jobs, skills, wallet, PI colonies, orders, blueprints) is deleted with it.

Trap: "Remove character" deletes the token from EVE Tools but does not revoke it with CCP. The authorization stays valid on the EVE Online side until you revoke it yourself, in the authorized applications page of your EVE Online account.

## Deleting your account and your data

"Account" menu, "Characters" tab, "Danger zone" section, "Delete my account" button. The window lists what will be deleted and what is kept, then asks you to type the **exact name of your main character**. The "Delete permanently" button stays inactive until the name matches, and the server also refuses a confirmation that does not match. Deletion is immediate and cannot be undone.

**Deleted**:

- your account, all your characters and their tokens;
- your personal data: assets, skills, jobs, transactions, PI colonies, orders, alerts, favorites, notifications, settings, PVE, mining, escalations, project templates, production structures, shared appraisals you created;
- your industry projects: if other accepted participants exist, the project is transferred (see below), otherwise it is deleted with everything in it;
- your pending join requests and your unapproved contributions (pending or rejected) in other people's projects;
- your participation in other people's mining operations, with the quantities and corrections of your characters.

**Transferred**:

- a group project you own goes to the oldest accepted admin, otherwise to the oldest accepted participant. Its linked jobs and purchases are cleared and its courier contract is unlinked;
- a mining operation already started passes the same way to its successor, and is deleted if there is none. An operation still "Planned" is deleted with you.

**Kept, with no link to you**:

- your approved contributions and the sales you entered in other people's projects, shown as "Deleted pilot", with the contribution note erased;
- in those projects, the participant line stays, without an account.

**Kept and possibly still carrying a name** (the code does not erase it):

- a project's **activity journal**: events already recorded keep the main character's name as it was;
- the name of the installer character of a job linked to a project you do not own;
- the names already written in the payments of a mining operation transferred to another participant, and the author name of a quantity correction;
- **corporation** data tied to an EVE ID rather than to an account: refinery observer records, moon tax statements, corporation wallet donations;
- corporation assets and blueprints read with your token: they depend on no account and stay until a Director reads them again, so without limit if none is connected.

**After the deletion**: your refresh tokens are revoked with CCP on a best-effort basis. If the revocation fails, the account is deleted anyway and the failure is only logged on the server: revoke the application yourself on the EVE Online side. Your session is put on a blocklist, and the cached ESI copies of your characters are invalidated.

For the full policy, see the "Privacy Policy" (in English) in the footer of the login screen.

## See also

- [FAQ](/help?page=faq/faq): scopes, "to reconnect", "nothing has updated", who sees my data.
- [Known limits](/help?page=limits/known-limits): the figures that can surprise you.
- [Getting started](/help?page=playbooks/getting-started): login and adding characters.
