# Watch your sell orders and get back to the top

This guide follows your sell orders of an Industry project against the best market price: where to read your position, since when an order has been undercut, how to copy the price to paste in game, and how to be warned without opening the project. Each step names the screen and the button as they appear.

EVE Tools never modifies an order: it reads your orders, compares them with the market book and gives you a price to paste. The change itself is made in game.

To know whether a price is still profitable before lowering it, see [Profit and margin: how EVE Tools calculates them](/help?page=calculations/profit-and-margin).

## What is followed

The table does not show all your orders, only those that meet all these conditions:

- they are **sell orders** (buy orders are ignored);
- they belong to one of **your characters** (not only the main one);
- they are for a **final product** of the project, that is an item whose BOM has a job in the "final" group;
- the project is neither **Completed** nor in personal use: in both cases the table stays empty.

Orders are read with the ESI permission to read your market orders (`esi-markets.read_character_orders.v1`). A character that does not have it, or whose login is no longer valid, is not read (see step 6).

## 1. Open the Sales tab of the project

1. Side menu: **Industry**, **Projects** tab.
2. In **My Projects**, open the project.
3. Click the **Sales** tab.

The **My sell orders** table is the first block of the tab, above the recorded sales.

## 2. Read the table

| Column | Content |
|---|---|
| **Order** | The item, the remaining quantity (`×quantity`) and the name of the character that holds the order. |
| **Market** | The location of the order: **Jita 4-4**, or the structure name. If the structure name is unknown, the app shows `#` followed by the location id. |
| **My price** | The price of your order. |
| **Best price** | The lowest sell price in the book of the same location and the same item, your own order included. Shows **Unknown value** when the position is unknown. |
| **Position** | **1st**, **#N · undercut for …** or the reason why it is unknown. |

Rows are sorted by urgency: undercut orders first (the oldest first), then unknown positions, then orders in first position.

### What the position means

- **1st** (green pill): no order in the book is strictly cheaper than yours. **At an equal price you stay 1st.**
- **#N · undercut for …** (orange pill): at least one order is cheaper. N is the number of strictly cheaper orders, plus one. In that case **Best price** turns orange.
- An unknown position (dashed violet pill) has two causes:

| Message | Cause |
|---|---|
| **Market not followed** | The location of the order is neither Jita 4-4 nor a structure whose book EVE Tools keeps. |
| **No market data** | The book of the location is empty for this item. |

An unknown position is never counted as "1st" or as "undercut": **Best price** shows **Unknown value** and no price to copy is offered.

### Followed markets

- **Jita 4-4**: the book is read again at every sync of your orders, for the items you sell there.
- **Structures**: EVE Tools keeps the book of the application's default market structure and of every structure chosen as **Market Structure** in the settings (click your character card at the bottom of the menu), provided a character has the permission to read its market; otherwise the structure is ignored. These books are refreshed every hour.
- **Any other location** (another station, a structure nobody chose): **Market not followed**.

## 3. Know since when an order has been undercut

"Undercut for" is shown in minutes (`min`), hours (`h`) or days (`d`), rounded down. The counter advances on its own on the page, without reloading.

It is the time elapsed since the **first sync that saw the order undercut**, not since the real moment a competitor passed you:

- the duration restarts from zero as soon as the order is no longer undercut (it is **1st** again, or its position becomes unknown). An order that gets undercut again restarts from zero;
- an order already undercut the first time the app reads it counts from that reading;
- the app does not know the exact time of the overtaking: it only has its readings.

## 4. Get back to the top: copy the price

On an undercut order, the **Copy {price}** button appears at the right of the row. The suggested price is the **best price minus 100 ISK**. There is no button when the position is **1st** or unknown, nor when the best price minus 100 ISK would not be positive.

1. Click **Copy {price}**. The app copies the raw number to the clipboard, without thousands separators. It shows no confirmation.
2. In game, open your market orders, use **Modify Order** on the order concerned and paste the price.
3. Come back to EVE Tools: the new position appears at the next sync (up to 5 minutes).

The suggested price is only a calculation. The table does not take your fees, the remaining quantity or profitability into account: see "Before lowering the price" below.

### Example (fictional)

Your order is at 5,200,000 ISK. The Jita 4-4 book contains 5,100,000, 5,150,000 and 5,200,000 ISK.

- Two orders are strictly cheaper (5,100,000 and 5,150,000): position **#3 · undercut for …**.
- **Best price**: 5,100,000 ISK.
- Suggested price: 5,100,000 − 100 = 5,099,900 ISK.

With a single competitor at 5,200,000 ISK (the same price as yours), you stay **1st**: a tie does not count as an overtaking.

## 5. Be warned without opening the Sales tab

Every undercut order of a project where you are accepted (except a **Completed** project or one in personal use) shows up in three places:

- **Workshop** (**Industry**, **Workshop** tab), in the **Action queue**: a line "Undercut order: {item} ×{quantity}, best price {best price} against your {your price}.", level **Warning**, with the **See my orders** button, which opens the **Sales** tab of the project. Alerts about a price below break-even and alerts about undercut orders come before the other actions in the queue;
- the **badge** at the right of **Industry** in the menu: it counts the actions of the queue, undercut orders included;
- the project **Companion** (**Companion** button in the project header): an alert "Undercut: {item} ×{quantity} at {market}, best price {best price} against your {your price}". It describes only the first undercut order of the list, and the Companion reads it again when a sync of the orders ends.

The **Action queue** and the badge are read again when the application and the **Workshop** are opened, not at the end of a read of the orders: reload the page to update them. The application sends no push notification and no message for an undercut order.

## 6. When nothing shows or a value is unknown

| What you see | Cause | What to do |
|---|---|---|
| "No sell order for the products of this project." | None of your characters' sell orders is for a final product of the project, or the project is **Completed** or in personal use, or the orders have not been read yet. | Check the product and the project status; wait for the next sync. |
| "Log in again with {characters} to read their sell orders (new ESI permission)." | A character does not have the permission to read orders, or its login is no longer valid. Its orders are not read. | Log that character in again with EVE SSO, accepting the requested permission. |
| **Market not followed** | Location outside Jita 4-4 and outside the followed structures. | No position is calculated. Choose that structure as **Market Structure** in the settings. |
| **No market data** | Empty book for this item at this location. | No position is calculated; wait for the next market read. |
| An error message instead of the table | Loading the table failed. | Reload the page. |

## Before lowering the price

Getting back to the top is not always profitable. Before copying a price, compare it with the **break-even price** of the project, explained in [Profit and margin](/help?page=calculations/profit-and-margin). When the Jita price falls below that threshold, the **Action queue** of the **Workshop** also shows "Jita ({price}) is below the break-even price of {threshold}: {gap} lost per unit sold on the market.", with the **Where to sell** button.

## Data freshness

- Your orders are read **every 5 minutes** for each user who has an active character with a valid login. No button forces the read from the **Sales** tab; when it ends, the table reloads on its own.
- The **Jita 4-4** book is read again at every read of your orders. If that refresh fails, the user's sync fails and the table keeps the state of the last successful read.
- **Structure** books are refreshed every hour: the best price of a structure can be up to about an hour late.
- The table does not show the time of the last read.

## Known pitfalls

- **Your own orders count**: the book does not distinguish other players' orders from yours. Another order of yours that is cheaper for the same item at the same location puts you **undercut**.
- **Only the best price counts**: the position ignores quantities. A competitor 1 ISK below with 1 unit undercuts you as much as a large order.
- **Only Jita 4-4 is followed**: an order placed in another Jita station or elsewhere is **Market not followed**.
- **The location can show as `#id`** when the app does not know the structure name.
- **The button confirms nothing**: after clicking **Copy {price}**, paste in game to check that the clipboard holds the right price.
- **The login text mentions a new permission** even when the cause is a login that is no longer valid.
- **No guided tour** exists for this module: this guide is the only step-by-step path.
