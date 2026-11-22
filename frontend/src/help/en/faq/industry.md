# FAQ: industry

## How are the structure and its bonuses chosen for a project?

For each step of the project, the calculation picks a structure from your list, in this order:

1. the structure attached to the step, when an ESI job linked to the step ran in an **imported** structure (you do not pick it by hand);
2. otherwise, the best of your structures located in the favorite system of the activity (manufacturing or reaction);
3. with no favorite system, or no structure in that system, the best of all your structures for the activity.

"Best" means: the best material bonus for the product's category. With no structure configured, the project is calculated with no bonus.

The details, the special cases and the installation tax are in the guide [Configuring your industry structures](/help?page=playbooks/industry-structures).

## ME/TE: where do the default values come from?

- **Project product**: you enter the ME and TE of each product at creation. A product added by hand starts at ME 2 and TE 4. A product opened from an analysis arrives with the analysis values; failing that, it starts at ME 0 and TE 0 (TE 4 for a T2 product).
- **Blueprint you own**: if a visible blueprint produces the product, the project plan uses it instead of the entered values. EVE Tools keeps the one with the highest ME, then the highest TE, then a BPO before a BPC. The blueprints of all your characters and those of the corporation (depending on the visibility set by the director) enter this choice.
- **Manufactured components** (intermediates): ME 10 and TE 20.
- **Reactions**: ME 0 and TE 0, because a reaction formula cannot be researched.

## Buy or build: how is the decision computed?

For each intermediate of a project plan that has a job, EVE Tools compares the options for the requested quantity, each delivered to the station that uses it:

- **build**: the manufacturing cost, with all runs rounded up to whole runs (the surplus produced is included in the cost);
- **buy at Jita**: the weighted Jita price, plus the **import** cost;
- **buy on the capital market**: the market price of the capital, when it is read.

Import goes through your **courier hub**, the system where the corporation's couriers always deliver the purchases of Jita (the capital, C-J6MT by default). It is the volume in m³ × the quantity × the Jita to courier hub rate (800 ISK per m³ by default), then, when the workshop is not in the hub system, × your **jump bridge** rate (0 by default). A workshop in Jita pays no import. The courier hub is set under "Favorite systems", the two rates ("Export cost" and "Jump bridge rate") in the Industry configuration. The import is unknown with no volume, no workshop or no courier hub, never 0.

The recommended choice is the cheapest option, with build first, then Jita, then the capital market on a tie. It is **unknown** as soon as a cost it needs is unknown. Nothing is stored: the calculation is redone on every read.
