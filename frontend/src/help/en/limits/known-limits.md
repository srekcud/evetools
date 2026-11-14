# Known limits

Last reviewed: 2026-11-14. The page is reviewed at each `V0.x` release.

This page lists what EVE Tools does not do, or does badly, today. A limit is listed only if it exists in the code or in an open issue. The page promises no fix.

Each line says what is limited, why, what you can do, and the linked open issue. A dash in the "Issue" column means no open issue describes that limit at the review date.

One rule holds across EVE Tools: when a piece of data is missing, the screen says "unknown". It never puts 0 in its place. The lines marked "a 0 that is not one" below are the known exceptions.

## Account and syncs

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **No scheduled sync at all after 7 days without a login.** | The scheduled syncs of jobs, wallet, blueprints, sell orders, PI colonies, PVE, mining and Ansiblex only pick accounts that logged in through EVE SSO less than 7 days ago and that have a character with a valid authorization. "Add character" does not count as a login. | Log in again. | - |
| **Skills and standings never sync on their own.** | No scheduled task reads them. Until the button has been used, the seller fees are unknown and a character's levels show as 0 with the source "Manual": that is missing data, not measured levels. | Industry, "Configuration" icon, "Skills" section, "Sync all" button. | - |
| **Five requested scopes are not used.** | Location, online status, fleet, EVE notifications and killmails are requested at every login, but no code reads them. All scopes are requested together, with no scope-by-scope choice. | None. Scope-by-scope table: [data freshness and privacy](/help?page=data/freshness-privacy). | - |
| **The "Valid token" badge does not detect a revoked authorization.** | It only checks that the scopes are present and that the token can be decrypted. | Home flags "character to reconnect". See the [FAQ](/help?page=faq/faq). | - |
| **A 0 that is not one: Home "total ISK".** | The total adds the readable balances. If no balance is readable it is 0, shown with "Partial total: N unknown balances". | Read the "Partial total" mention, and the Characters page for the per-character detail. | - |
| **"Remove character" does not revoke the authorization at CCP.** | EVE Tools deletes its token without calling EVE SSO to revoke it. | Revoke the application in the authorized applications of your EVE Online account. | - |
| **One very long sync delays all the others.** | Production runs a single worker, which consumes the asynchronous queue and then the scheduled tasks, and every scheduled task goes through that queue. The issue reports scheduled syncs running hours late. | None on the player side. | #838 |
| **The ESI response cache may not be written in production.** | The production Redis runs with an eviction policy that the ESI tag-aware cache adapter does not support. According to the issue, no response is then cached: each sync rereads everything and spends ESI request budget, and the cache delays described in [data freshness and privacy](/help?page=data/freshness-privacy) do not apply. | None on the player side. | #840 |

## Inventory

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **Assets do not update on their own.** | No scheduled task reads them. They are read when you log in, when you add a character, and with the "Refresh" button, which needs a character whose authorization is valid. | The "Refresh" button of the Inventory. | - |

## Industry

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **Default ME and TE differ from screen to screen.** | "Margins" starts from ME 10 and TE 20 for T1, and takes ME 2 and TE 4 for T2, with the fields greyed out (the chosen decryptor modifies them). "Buy vs Build" starts from ME 2, with no TE field. "Batch Scan" assumes ME 10 and TE 20 for T1, a single run, and ignores the material bonuses of the structure and its rigs. Creating a project starts from ME 2 and TE 4. | Check the ME and TE of each screen before comparing two figures. Details: [job cost](/help?page=calculations/job-cost). | - |
| **A project's fees are copied at creation and never recomputed.** | Broker fee and sales tax come from your seller fees at the moment of creation. If skills were not synced, they stay empty, so unknown, even after a later sync. | "Edit Project", fields "Broker Fee %" and "Sales Tax %". See [market fees](/help?page=calculations/market-fees). | - |
| **A 0 that is not one: the "Balance of the last 30 days" card on Home.** | The "Industry" and "Total" lines only add completed projects whose profit is known. A project with unknown profit is not counted, and the line is 0 if all of them are. | The "Industry" tab of the Balance page says "of which N with unknown profit, not counted". | - |

## Group projects

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **A sale made by another participant is counted for whoever records it.** | Only the owner and admins record a sale, and the "Seller" can only be one of their own characters. The transfer plan assumes that everyone keeps the net revenue of their own sales: another participant's share is counted for the person who recorded it. | No setting. Correct the transfer concerned among yourselves. See [group contributions](/help?page=calculations/group-contributions). | - |
| **Broker fee and sales tax apply to every market sale of a project.** | A project applies both rates to all market revenue, including a sale to an existing buy order, whereas "Margins" only counts the tax for an "instant sell". The margin of a project is therefore lower than the analysis margin for the same sale. | None. See [market fees](/help?page=calculations/market-fees). | - |
| **No notification of acceptance, approval or rejection.** | The "Request Sent" screen says "You will be notified once your request is accepted", but EVE Tools sends no notification when a request is accepted, nor when a contribution is approved or rejected. An open page refreshes by itself. | Tell the person, or come back and look. The project "Journal" keeps "Contribution approved" and "Contribution rejected". | - |
| **A pending join request is invisible to the requester.** | The project only shows in "My Projects" once the request is accepted. Reopening the link takes you back to the project list, with no message. | Ask the owner. | - |
| **A pending contribution cannot be withdrawn, and a rejection carries no reason.** | There is no delete or cancel for the participant, and the review only passes on "approved" or "rejected". | Ask a reviewer to reject it, then submit it again. Give the reason outside EVE Tools. | - |

## Market

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **The order book keeps only 20 orders per side.** | Jita and structures both keep only the 20 best orders on each side. The rank of one of your sell orders is capped at 21: "#21" reads "21 or more". | None. See [market orders](/help?page=playbooks/market-orders). | - |
| **The Jita order book is read from the first page of ESI results.** | For a product with more than one page of orders in The Forge, the client does not read the following pages: the best price shown can miss an order. | Check in game before a large purchase or sale. | - |
| **Registering a market structure starts no read.** | The order book arrives at the next sync, every hour, and a player who follows it needs the structure market scope. Until then, your orders in that structure show "Market not followed". | Wait for the next sync, up to an hour. | - |
| **A structure's "Weighted Sell" is not weighted.** | In the Appraisal, the structure price is the price of the cheapest sell order, multiplied by the quantity. | See [weighted price and appraisal](/help?page=calculations/weighted-price-appraisal). | - |
| **A 0 that is not one: the Appraisal totals.** | "Sell Value", "Buy Value" and the structure column only add the lines that have a price, without saying so. If reading the prices fails, a message shows and the cards are 0. | Check that no line shows "-" before relying on a total. | - |

## PI colonies

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **A 0 that is not one: the PI production total.** | A product with no Jita price counts as 0 ISK in the total. It shows "-", like a fully consumed product. | Read the total as a minimum. See [planetary colonies](/help?page=playbooks/planetary-colonies). | - |
| **A colony's "Theoretical production / day" panel does not subtract consumption.** | It counts the colony's factories at full speed, without subtracting what they consume, whereas the global total only counts the surplus. The sum of the colonies therefore does not match the total. | Rely on the global total for the sellable part. | - |

## Mining

| Limit | Why | Workaround | Issue |
|---|---|---|---|
| **A donation pays a moon tax statement by corporation, payer and date only.** | The donation reason is never read, although "How to pay" asks for the statement reference as the reason. A donation pays a statement of the same corporation, from the same payer (the character a manager attributed it to, otherwise the donor), from the start of the period. | Pay from the character that mined, or ask the manager to attribute the donation. See [ore value and moon tax](/help?page=calculations/ore-value-moon-tax). | - |
| **"Open to the alliance" does not filter by alliance.** | It is the switch that makes the refinery be read. Every miner of its ledger is taken, and "Outside alliance" is only a display flag with no effect on the amount. | None. See [mining and moon tax](/help?page=playbooks/mining-moon-tax). | - |
| **Changing the period of a refinery that already has statements can create a duplicate.** | The code does not compare against the statements of another split: the same mining days can produce a second statement. | Choose the period before the first sync. | - |

## Going further

- [FAQ](/help?page=faq/faq): scopes, "to reconnect", "nothing updates anymore".
- [Data freshness and privacy](/help?page=data/freshness-privacy): what is synced, when, and what is kept.
- [Job cost](/help?page=calculations/job-cost), [market fees](/help?page=calculations/market-fees), [margin](/help?page=calculations/margin): the Industry figures.
- [Group contributions](/help?page=calculations/group-contributions): value of contributions and transfers.
