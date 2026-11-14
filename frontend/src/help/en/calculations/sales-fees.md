# Sales fees: broker fee and sales tax

EVE Tools deducts two fees from a market sale: the **broker fee** and the **sales tax**. Together they are the **seller fees** of the glossary. They depend on the skills and standings of the seller's main character. When any of that data is missing, the fees are **unknown**, never 0 and never a fallback rate.

The margins and profits in [Profit and margin](/help?page=calculations/profit-and-margin) use these fees: an unknown fee makes the margin unknown.

## The formula

The two rates are computed separately, from four inputs:

| Input | Role |
|---|---|
| **Accounting** level (0 to 5) | Lowers the sales tax |
| **Broker Relations** level (0 to 5) | Lowers the broker fee |
| **Standing** towards the **Caldari State** faction (-10 to 10) | Lowers the broker fee |
| **Standing** towards the **Caldari Navy** corporation (-10 to 10) | Lowers the broker fee |

Caldari State and Caldari Navy are the faction and the corporation that own Jita 4-4: they are the only standings read.

```
sales tax  = 7.5% × (1 − 11% × Accounting level)

broker fee = 3%
             − 0.3%  × Broker Relations level
             − 0.03% × standing towards Caldari State
             − 0.02% × standing towards Caldari Navy
```

- A skill level outside 0 to 5 is rejected.
- Standings are taken as ESI returns them, with no further calculation.
- The formulas are linear and the code does not clamp the result: a negative standing raises the broker fee.
- The coefficients (7.5%, 11%, 3%, 0.3%, 0.03%, 0.02%) are constants in the code, not data read from ESI or the SDE. If CCP changes them in game, EVE Tools has to be updated.

## Where the data comes from

| Term | Source | Freshness |
|---|---|---|
| Accounting level (skill 16622) | ESI `GET /characters/{id}/skills/`, active level, scope `esi-skills.read_skills.v1` | On demand |
| Broker Relations level (skill 3446) | Same call | On demand |
| Standing towards Caldari State (faction 500001) | ESI `GET /characters/{id}/standings/`, scope `esi-characters.read_standings.v1` | On demand |
| Standing towards Caldari Navy (corporation 1000035) | Same call | On demand |
| Coefficients | Constants in the code | Change with an EVE Tools release |

**On demand**: no scheduled sync reads skills or standings. They are read again only when you click **Sync all**: **Industry** › gear icon (**Configuration**) › **Skills** section. One click syncs the skills and the standings of all your characters. The **Last sync** column shows the age of the last read.

- Only the **main character** counts. The skills and standings of your alts do not change the fees.
- Between two syncs, EVE Tools uses the cached values: a change in game (a finished skill, a changed standing) only shows after the next sync.
- If a sync fails (missing scope, ESI error), the values of the last successful sync stay in use. The failure is reported at the end of the sync, character by character.
- After a successful sync, a skill the character has not trained is level 0 and a standing missing from the ESI list is 0.0 (neutral). These are known values, not unknown ones.

## Which fees for which sale

| Sale | Fees deducted |
|---|---|
| Sell order in Jita or in a structure | Broker fee + sales tax |
| Sale to an existing buy order (immediate sale, or the structure buy order in the margin comparison) | Sales tax only |
| Contract | Neither broker fee nor sales tax |

- In the margin comparison, a contract sale counts 0 fees. In a project, the real contract fees are read from the seller's wallet journal; with no journal line they are unknown.
- For a structure, EVE Tools applies the same rates as for Jita. The own fees a structure owner can set are not read.
- The calculation covers neither order modification fees nor relisting fees.

## Worked example

Calculation example, not a real character: Accounting 4, Broker Relations 3, standing 2.0 towards Caldari State, standing 1.0 towards Caldari Navy. A sell order brings in 100,000,000 ISK gross for a production cost of 80,000,000 ISK.

| Step | Calculation | Result |
|---|---|---|
| Sales tax | 7.5% × (1 − 0.11 × 4) = 7.5% × 0.56 | 4.2% |
| Broker fee | 3% − 0.9% − 0.06% − 0.02% | 2.02% |
| Seller fees | 4.2% + 2.02% | 6.22%, that is 6,220,000 ISK |
| Net revenue | 100,000,000 − 6,220,000 | 93,780,000 ISK |
| Profit | 93,780,000 − 80,000,000 | 13,780,000 ISK |
| Margin | 13,780,000 ÷ 80,000,000 | about 17.2% |

The margin is a percentage of the **cost**, not of the revenue. The same order sold to an existing buy order would only pay the 4.2% sales tax, that is 4,200,000 ISK.

## The fees of a group project

A project keeps its own fees, as percentages:

- at creation, the project copies the owner's fees, computed as above at that moment;
- a sync done after creation does not update the project: the copied fees stay those of the creation;
- the owner can enter or correct them (**Broker Fee %** and **Sales Tax %** fields of the **Edit Project** form); each field goes from 0 to 100 and their sum cannot exceed 100;
- a completed project no longer changes its fees.

The net revenue, the margin, the payouts and the break-even of a project use the project's two percentages, not the skills of the user looking at it.

## When the fees are unknown

The seller fees are unknown in three cases:

| Missing data | Case |
|---|---|
| Main character | The account has none |
| Skills | Accounting or Broker Relations is missing from the cache: no skills sync has succeeded for the main character |
| Standings | No standings sync has succeeded for the main character (scope `esi-characters.read_standings.v1` missing, for example) |

To get out of unknown: check that the main character has granted both scopes, then click **Sync all**.

What the screen shows:

- **What to produce**: the message "Seller fees unknown: sync the skills and standings of your main character" is shown; a product whose profit is unknown is left out of the ranking and counted in "{n} products left out: data missing".
- **Margins tab** (the sell venue comparison of a product): the comparison header shows "Unknown fees", the fees, profit and margin columns show "—" (tooltip "Unknown value") and a banner explains that skills and standings must be synced. The **contract** row is still computed, at 0 fees.
- **Group project**: the financial summary shows "Fees unknown: the project owner has no synced skills and standings, so the net revenue, the profit and the margin cannot be computed."; the break-even shows "The sales fees are unknown.". A project sold only by contract has no market fees, so nothing is unknown on that side.
- **Edit Project form**: a note says that, left empty, the fees stay unknown; entering them by hand makes them known without a sync.

The fees are also unknown when no sync has ever been made: an account that never clicked **Sync all** sees "unknown" wherever a margin depends on the fees.
