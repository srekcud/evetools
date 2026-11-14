# First steps: login, alts, structures, first sync

This guide goes from the EVE SSO login to a usable dashboard. Menu and button names are the ones of the English app.

At the end you will have your account and alts connected, your production structures and skills filled in, and you will know what updates on its own and what needs a click.

## Step 1: log in with EVE SSO

1. On the login page, click the **Log in with EVE Online** button (CCP's official image).
2. You land on CCP's site: pick the character and approve the requested permissions (the ESI scopes). EVE Tools asks for the 31 scopes of its list in a single authorization. The details are in the [ESI scopes](/help?page=data/esi-scopes) page and the [FAQ on account and characters](/help?page=faq/account-and-characters).
3. You come back to the app, on the **Home** screen.

The first character you connect becomes your **Main character**. It matters in several places (structure search, sales fees, visibility in group projects).

As soon as you log in, an asset sync starts in the background for all your characters.

When ESI is in maintenance or degraded, the login page shows a banner. In that case, try again later.

## Step 2: read the Home screen

The **Home** screen summarises your account:

- **Characters** (with « linked accounts »): how many characters are connected.
- **Wallet** (« total ISK »): the sum of the balances. When a character's balance could not be read, the total shows « Partial total: 1 unknown balance » (or « N unknown balances »). An unknown balance is never counted as 0.
- **Your characters**: a **Valid token** or **Invalid token** badge per character.
- **To do**: pending actions, for example « 1 character to reconnect ». If loading fails, the line reads « Characters to reconnect: unknown ».
- **Activity**: real-time events. The dot next to the title reads **Mercure connected** or **Disconnected**.

## Step 3: add your alts

1. Menu **Account**, tab **Characters** (or, from your portrait at the top right, **Characters**).
2. Click **Add character**. The button of the same name on **Home** does the same.
3. You go through EVE SSO for the new character and come back to **Characters**. An asset sync for it starts in the background.

Under the **Main character** card, the page groups your alts under **Same corporation** and **Other corporations** (the number of characters is shown next to each). Without alts it reads « No alt characters ».

Every card carries a token badge:

| Badge | Meaning | Action |
|---|---|---|
| **Valid token** (main card), **Valid** (alts) | The token covers the required scopes. | None. |
| **Token expired** (main card), **Expired** (alts) | EVE SSO refused the token renewal. The character drops out of the syncs. | **Reauthorize** on the card. |
| **Missing scopes** | The token is valid but does not cover every scope. | **Reauthorize**. |

On an alt's card, icons (tooltips) let you **Reauthorize** (only visible when the token is expired or incomplete), **Set as main** and **Remove character**. The **Wallet** of a character whose balance cannot be read shows « Unknown ».

## Step 4: fill in your production structures

Without a configured structure, Industry projects are computed with no structure or rig bonus at all.

1. Menu **Industry**, gear icon (tooltip **Configuration**) under the module tabs; or menu **Account** › **Settings** › **Industry** section. Both open the same panel.
2. Expand the **Structures** section and click **Add**.

The form, the import from the corporation or by ESI search, the security class, the rigs and the installation tax are detailed in [Configuring your industry structures](/help?page=playbooks/industry-structures). That guide also explains how a job's installation cost and a structure's bonus are calculated.

The same panel holds the **Fees** (export cost in ISK/m³), **Skills** (step 5) and **Blacklist** sections, with **Max job duration** at the top.

Do not confuse it with **Market Structure**, under **Account** › **Settings** › **Market** section, which only compares prices against Jita. It plays no part in production bonuses.

## Step 5: sync skills and standings

Skills drive production times. The broker fee and the sales tax of a sale are computed from your main character's skills and standings. As long as that data is missing, the seller's fees are shown as unknown: the app does not replace them with 0.

This sync never runs by itself:

1. **Account** › **Settings** › **Industry** section, or the Industry module gear (step 4).
2. Expand the **Skills** section and click **Sync all**.

It reads the skills, attributes and standings of each of your characters. The **Source** column shows **ESI** for a value read from CCP and **Manual** for a value set by hand. When a character fails (missing token, missing scope, revoked authorization, ESI unavailable), the reason is shown with the **Reconnect characters** link.

## Step 6: understand what updates on its own

| Data | How it arrives |
|---|---|
| Assets | A sync starts at login and each time you add an alt. After that it is no longer scheduled: menu **Wealth** › **Inventory**, **Refresh** button. |
| Industry jobs | Every 30 minutes, for characters with a valid token. |
| Wallet transactions | A pass every 5 minutes that only reads characters whose ESI cache expiry has passed. |
| Planetary colonies | Every 30 minutes. The **Sync** button on the **Planetary** page forces a read. |
| Blueprints, PVE data, mining ledger | Every hour. |
| Skills and standings | On demand only (step 5). |

The full table, generated from the code, is on the [Sync frequencies](/help?page=data/sync-frequencies) page.

What you see during and after a sync:

- **Inventory**: a progress bar appears while it reads, the button turns to **Syncing...**, then a line shows the age (« Synced » followed by the elapsed time). Until a character's assets have arrived, the page reads « Sync in progress ».
- **Inventory, corporation view**: after a refresh, the button is locked until the time shown (« Refresh available at »). This limit does not apply to a character's view.
- **Industry** › **Workshop**: the « Next job completions » block shows « ESI sync » followed by the age, or « ESI sync unknown » when no read has happened.

A job finished in game therefore does not necessarily show up right away: it arrives at the next 30-minute tick, and the ESI cache can add a delay.

## If something does not show

- **Empty inventory**: the first sync has not arrived. Wait for « Sync in progress » to end, or click **Refresh from EVE**.
- **Character to reconnect in To do**: **Reauthorize** on its card, in **Characters**.
- **Seller's fees unknown**: do step 5 with a main character whose token is valid.
- **No bonus in a project**: add your structures (step 4).
- **Other questions**: the [FAQ on numbers that look wrong](/help?page=faq/numbers-that-look-wrong).

The calculation pages for each number read here (sales fees, job cost) will be added to the « How it is calculated » section of the help center. Until then, the installation cost calculation is described in the structures guide of step 4.
