# Keep your PI colonies running

This playbook shows you how to read the state of your planetary interaction (PI) colonies and extractors, get alerted before an extractor stops, and know what the app cannot know. EVE Tools reads your colonies, it never changes one: restarting an extractor is done in game.

Labels in quotation marks are the ones on screen. They are quoted as they appear in the app.

## 1. Allow reading your colonies

Each character needs the permission to read its colonies (the planetary ESI scope). A character without that scope, whose authorization is no longer valid, or whose account has not logged in through EVE SSO for more than 7 days, is skipped by the sync: its colonies are no longer read and no alert concerns them. To fix it, see [Getting started](/help?page=playbooks/getting-started), step 4, "Reauthorize" button.

## 2. Open "Planetary" and start a sync

Menu "Planetary". The screen is titled "Planetary Interaction". Top right, "Last sync:" gives the age of the most recent read, or "never".

- Colonies are read again **every 30 minutes** for active accounts. The "Sync" button starts the same sync on demand. It reads "Syncing..." while it works, then the screen reloads.
- A sync reads every colony of every character, pausing half a second between two colonies: the more colonies you have, the longer it takes.
- A colony deleted in game disappears from the screen at the next sync.
- With no colony, the screen shows "No PI colonies". Click "Sync" or check the scope (step 1).

Trap: the data read from ESI reflects your last interaction **in game** (the button tooltip says so). If you just restarted an extractor, EVE Tools still sees the old expiry until the sync that reads the colony again. The age of each piece of data: [data freshness and privacy](/help?page=data/freshness-privacy).

## 3. Read the four cards at the top

| Card | What it counts |
|---|---|
| "Colonies" | Your colonies, "across N characters". |
| "Active extractors" | Extractors that have not expired, **including** the ones that are "expiring soon". Below: "N extractors total". A red "expired" word is added as soon as at least one extractor has expired, without the number. |
| "Expiring soon" | Extractors that have not expired and whose expiry falls within the threshold, "within next 24h" by default. |
| "Theoretical production/day" | A total in ISK, see step 7. |

An extractor with no known expiry is only counted in "N extractors total". If the statistics fail to load, the extractor cards show a dash instead of the number (tooltip "Unknown value"), never 0.

Under the cards, a banner appears when a colony is concerned:

- red: "N extractors expired", followed by "system (planet type)" for each colony;
- amber: "N expiring within 24h".

Trap: the banner counts **colonies**, not extractors, despite its wording. A colony with one expired extractor and another expiring soon is only counted in the red one. The cards, on the other hand, count extractors.

Another trap: the status of colonies (banner, card borders, top cards) is computed when the data is loaded. The timers tick every second. An extractor can therefore show a red "EXPIRED" while the banner has not counted it yet: reload the page or click "Sync".

## 4. Read a colony

Colonies are grouped by character. A card shows the planet and its type, the system and its security, "CC Lv" (command center level), the number of installations ("inst."), the timers, and "MAJ" followed by the age of that colony's last read.

- Closed card: a single timer, the nearest expiry of **all the colony's extractors, expired ones included**. A colony with an expired extractor therefore shows a red timer even if the others are running. Click the card: the header switches to one timer per extractor.
- Colors: green beyond the threshold, amber below it, red at expiry.
- The "STALE" badge flags a colony read more than an hour ago. With a sync every 30 minutes, a read has been missed (see step 1).
- A colony with no extractor but with factories (a "factory colony") runs on an imported stock. Its timer is how long that stock will last, with "N in stock, M left to make" of the final product. It starts from the last read.
- With no extractor and no recognized factory: "No extractors".

Open card:

- "Extractors": "Cycle", "Qty/cycle", "Extr. heads" and "Initial rate", given as "max ~N / hour".
- "Factories": schema, inputs, output, cycle.
- The colony's "Theoretical production / day", see step 7.
- "Storage flows": per storage, "Incoming" and "Outgoing" per cycle and per day, "Net flow" and "fill ~N days". That delay divides the capacity by the net incoming flow, with a per-unit volume fixed per tier in the app. It ignores what the storage already holds: read it as an order of magnitude for an empty storage.

At the bottom of the page, the legend shows "> 24h", "< 24h" and "Expired". The first two labels are fixed: the colors follow **your** threshold (step 5).

## 5. Set the threshold and notifications

Two paths lead to the same form: "Account", "Settings", "Notifications" section, or the header bell and then the "Preferences" button of the panel. "Planetary" row, columns "Enabled", "Browser Push" and "Threshold (min)", then "Save".

- **Threshold (min)**: how long before expiry an extractor counts as "expiring soon". 1440 minutes (24 h) by default, between 15 and 10,080 (7 days). Outside those bounds the save is refused ("Failed to save"). An empty field goes back to 1440.
- It drives the cards, the banner, the timer colors, the "PI extractors" line on Home and the notifications. Screens apply it on reload, notifications at the next sync.
- **Enabled**: unchecked, the category stops producing notifications, and the threshold field is greyed out.
- **Browser Push**: off by default. Turning it on asks your browser for permission. Without it, or if the browser has no push support, the screen shows "Push notifications were denied by the browser" or "Push not supported by this browser".

## 6. How the alert reaches you

The alert is evaluated **at the end of each sync**, not continuously. For each extractor of a colony, at most one notification per stage and per expiry:

| Stage | Condition | Notification title |
|---|---|---|
| Warning | the expiry falls within the threshold and 30 minutes or more remain | "Extractor expiring soon" |
| Critical | less than 30 minutes remain (fixed value, independent of the threshold) | "Extractor expiring soon" |
| Expired | the expiry has passed | "Extractor expired" |

The message gives the product, the planet, the character and, before the expiry, the minutes left. The notification is in the bell, "Planetary" category: a click opens "Planetary". If push is on, the same text goes to your browser. **These texts are always in English**, whatever language you chose.

A stage does not repeat: the next one (Critical, then Expired) can follow. When you restart the extractor, the new expiry starts over as soon as a sync reads it.

Traps:

- With a sync every 30 minutes, a short threshold (under 30 minutes) can let the expiry pass without a prior alert. An expiry is only notified at the next sync anyway, up to 30 minutes later.
- Category disabled: the alert is still marked as sent. Turning it back on does not replay it for that expiry.
- Only extractors with an expiry are followed. A factory colony about to run out of stock, a full storage or a stopped factory trigger no notification.
- A character skipped by the sync (step 1) triggers nothing. The "characters to reconnect" line on Home is then your only signal.

## 7. Read the production

The "Theoretical production/day" card and the "Global production by tier" table give the same total, in ISK per day. Columns: "Tier" ("Raw", "Processed", "Refined", "Specialized", "Advanced" for P0 to P4), "Products", "Volume / day", "ISK / day", "% total". Click a tier to see each product: inputs, output, ISK/d and "Supply" (the worst gap between what your colonies produce and what your factories consume of that input).

What the calculation does:

- **Theoretical**: for an extractor, the quantity per cycle and the cycle time read from ESI (the "Initial rate"), with no decay. An expired extractor no longer counts. A factory runs according to what its colony supplies (extractor, upstream factory, or stock).
- **Sellable part only**: what your own factories consume is removed ("N/d used in-house, M/d sellable"). A factory with no input is "Stopped: no input available".
- **Price**: the cheapest sell order at Jita 4-4 (not the weighted price), taken from the Jita prices shared by all players, synced every hour. The screen says "updated every 2h": that is the cache lifetime, not the pace. See [weighted price and appraisal](/help?page=calculations/weighted-price-appraisal) for the other valuations.

What the total does not tell you:

- **A product with no Jita price counts as 0 ISK in the total.** It shows "-", like a fully consumed product, and nothing says the price is missing. Read the total as a minimum.
- An extractor for which ESI gives no cycle or quantity, or a factory whose schematic is unknown to the SDE, is skipped without a message.
- The "Theoretical production / day" panel **of one colony** only counts its factories, at full speed, without removing what they consume, and shows "-" when the price is missing. The sum of the colonies therefore does not match the global total.

## 8. Find the alert on Home

In "To do":

- "N PI extractors stopped" (severity "Critical"): the expired extractors;
- "N PI extractors expire within 24 h" (severity "Warning"): the ones expiring soon.

Both lines open "Planetary". "Acknowledge" or "Acknowledge all" hides them: an acknowledged line only comes back if its number exceeds the acknowledged one, and the acknowledgement clears when the number drops back to 0. If the statistics fail to load: "PI extractors: unknown (loading failed)".

Trap: "24 h" is hard-written in that line, but the number follows **your** threshold. With a 6 h threshold, the line says "within 24 h" and counts the extractors under 6 h. The "In progress" card, "PI extractors", shows the real threshold ("N expiring within …") with "A / T active" and "N stopped".

## Final check

- Every character that has colonies is read: no "characters to reconnect" line on Home, no "STALE" badge on the screen.
- "Last sync:" is less than 30 minutes old.
- The "Planetary" row is "Enabled" with the threshold you want, and push is on if you want to be warned outside the app.
- The production total is a minimum: a product with no price counts as 0 in it.

For other unknown figures: [known limits](/help?page=limits/known-limits) and the [FAQ](/help?page=faq/faq).
