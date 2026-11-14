# FAQ: numbers that look wrong

## Why "unknown" instead of a price or a margin?

EVE Tools never makes up a number. When a piece of data is missing, the result that depends on it is shown as unknown, never as 0: a 0 would look like a real result and make you decide on a wrong value. A sum that contains an unknown term is itself unknown.

The most common causes:

- a missing market price, adjusted price or cost index (the sync concerned has not run yet or has failed);
- an unknown invention probability, because a skill of the inventor character is not cached;
- unknown seller fees: sync the skills and standings of your main character;
- a contribution whose price is unknown, or a job for which the ESI gives no cost.

In a group project, the margin and each member's share also stay unknown while no sale is recorded ("awaiting sales" state) and when the total cost of the approved contributions is 0 (undistributable distribution). The engaged costs are still calculated in both cases.

## Why is my inventory not up to date?

Assets are not synced at a regular interval: the sync is on demand. It starts at login and when you add a character, and when you start a refresh from the application. The corporation's assets are also refreshed on demand.

A change made in game therefore shows up only after the next refresh. The other data (jobs, wallet, market, colonies) have their own frequency: see [Sync frequencies](/help?page=data/sync-frequencies).

## Why does a project cost differ from what I paid in game?

The cost of a project is the sum of the value of its contributions, and the value of a contribution depends on its source:

- a purchase linked to the project: the **price paid**;
- a job linked to the project: the **ESI cost** of the job;
- a material you declare as contributed, with no linked purchase: the **Jita price of the contribution day** (5th percentile), frozen when you declare it.

A gap with what you paid in game usually comes from a purchase that you did not link to the project: the material is then valued at the Jita price of the day. An unknown price gives a contribution of unknown value, never 0.

## Why does a finished job not show up immediately?

Industry jobs are synced every 30 minutes, from what the ESI returns. A job that has just finished in game can therefore take a while to show as finished in a project.
