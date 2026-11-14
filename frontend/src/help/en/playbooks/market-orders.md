# Watch your sell orders and get back in front

This playbook follows your open sell orders against the best price on the market: where to read your position, since when an order is undercut, which price to copy to get back in front. EVE Tools reads and compares, it never changes an order: you change the price in game.

Scope, to know before you start: only the sell orders of your characters on the **final products** of an industry project are followed, and only for projects where you are an accepted member that are neither "Personal use (not sold)" nor "Completed". An order on any other item shows up nowhere. Buy orders are ignored.

Labels in quotation marks are the ones on screen. They are quoted as they appear in the app.

## 1. Allow reading your orders

Each character needs the permission to read its orders (the character orders ESI scope). Characters without it, or whose authorization is no longer valid, are skipped. The "My sell orders" card tells you: "Log in again with <characters> to read their sell orders (new ESI permission)."

To fix it: "Account" menu, "Characters" tab, "Reauthorize" on the character card. See [Getting started](/help?page=playbooks/getting-started), step 4.

Condition for the automatic reading: an EVE SSO login less than 7 days old. Beyond that, none of your orders is read again and the positions shown stay those of the last reading.

## 2. Choose the markets to follow

An order only has a position on a **followed market**:

- **Jita 4-4**: always followed. The "Market" column shows "Jita IV - Moon 4".
- **A structure**: "Account", "Settings", "Market" section, "Market Structure" block. Search for the structure (at least 3 characters) and pick it in the list: it is saved right away ("Structure saved"). "Delete" on the structure line removes it.

Trap: saving does not start any read of the structure's order book. It arrives with the next hourly sync, so up to an hour later, and a player who follows the structure needs the structure market scope. Until then, your orders in that structure stay "Market not followed", unless another player already follows it.

An order placed elsewhere (an NPC station other than Jita 4-4, a structure that is not followed) has an unknown position, and its location can show as a number ("#...") instead of a name.

## 3. Open "My sell orders"

"Industry" menu, "Projects" tab, "My Projects", open the project, "Sales" tab. The "My sell orders" card is at the top of the tab, above the recorded sales. It lists the orders of **all your characters** on the project's products.

Orders are read every 5 minutes. The card reloads by itself when a reading ends. It does not show the time of the last reading.

With no order, the card says: "No sell order for the products of this project." This is also what you see just after placing an order, until the next reading.

## 4. Read a row

Columns: "Order" (product, remaining quantity, character), "Market", "My price", "Best price", "Position".

Rows are sorted by urgency: undercut orders first, oldest first, then unknown positions, then orders in front.

| "Position" | Meaning |
|---|---|
| "1st" | No order of the same location is at a strictly lower price than yours. At an equal price, you are 1st. |
| "#N · undercut for ..." | At least one order of the same location is cheaper. N is the number of cheaper orders, plus one. The duration is written in "min", "h" or "d". |
| "Market not followed" | The location is not a followed market (step 2), or the structure's order book has expired. Position and best price are unknown ("Unknown value"). |
| "No market data" | The market is followed but its order book has no sell order for this product. Position unknown. |

An unknown position is never "1st" by default. For the general reading of "unknown", see the [FAQ](/help?page=faq/faq).

What the comparison does, and does not do:

- It only looks at the **price**. A competing order of a single unit undercuts you as much as a large one.
- It does not tell your orders from others: **your own cheaper orders count**. Two orders for the same product in the same market, at two prices: the more expensive one shows as undercut by the other.
- It ignores fees. The "Best price" is an order book price, not a net amount. See [market fees](/help?page=calculations/market-fees).
- For an order in front, "Best price" equals "My price".
- The order book only keeps the 20 cheapest sell orders per product and per location. A rank of "#21" therefore reads "21 or more".

"Undercut for" starts at the first reading that sees the order undercut, not at the moment the competitor placed their order. It restarts from zero as soon as a reading no longer sees the order undercut, including when the position becomes unknown.

## 5. Get back in front

On an undercut row, the "Copy <price>" button copies the best price minus 100 ISK to the clipboard. Paste it when you edit the order in game. Nothing is changed from EVE Tools.

- The price shown on the button is abbreviated ("K", "M", "B", two decimals). The clipboard receives the full number. *Example: best price at 4,900,000, the button shows "Copy 4.90 M" and copies 4899900.*
- The step is fixed: 100 ISK, whatever the price. If the best price is 100 ISK or less, there is no button.
- The copied price starts from the best price of the last reading. If a competitor has moved since, the row is out of date.

After your edit, the position updates at the next reading (every 5 minutes). For a structure, the comparison order book is read again every hour: the position can stay "undercut" up to an hour longer, even if you are already back in front.

## 6. Get warned without opening the project

EVE Tools sends no notification for an undercut order. Two places flag it:

- **"Workshop"** ("Industry" menu, "Workshop" tab), "Action queue": one row per undercut order, "Undercut order: <product> ×<quantity>, best price <price> against your <your price>.", severity "Warning". The "See my orders" button opens the project's "Sales" tab. These rows come after the price alerts and before the usual project actions. The queue is loaded when the page opens: reload it to see a recent reading.
- **"Companion"** (button in the project header): an alert "Undercut: <product> ×<quantity> at <market>, best price <price> against your <your price>", which shows the most urgent order. It updates at the end of each reading.

The price alerts in the "Alerts" tab of the "Market Browser" are something else: a threshold on an item's price, unrelated to your orders.

## Check

- No "Log in again..." message on the card.
- Every row has a position "1st" or "#N", except those whose market is not followed.
- After pasting the new price in game, the row goes back to "1st" at the next reading (an hour at most for a structure).

## Traps

- **Positions can be old without the screen saying so.** If a character's reading fails, its rows keep their last state. If the Jita order book cannot be refreshed, no row is updated.
- **Jita order book.** It is read from the first page of ESI results. For a heavily traded product, the best price can be wrong: see [known limits](/help?page=limits/known-limits).
- An order that ESI no longer returns (sold, expired, cancelled) disappears from the card at the next reading.
