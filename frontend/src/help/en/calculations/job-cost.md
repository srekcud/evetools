# Industry job cost

This page explains how EVE Tools computes the install cost of a job (manufacturing, reaction, copying, invention), where each term comes from, and what the structure, its rigs, and the blueprint ME and TE change. The margin built on it is described in [margin](/help?page=calculations/margin). To declare your structures: [set up your industry structures](/help?page=playbooks/industry-structures).

## Where the figure appears

- **"Industry" menu, "Analysis" tab, "Margins" sub-tab.** The "Job Install Cost" table gives one row per job ("Install Cost" column) and the "Total Job Install Cost". The "Job Install" box of the "Cost Summary" repeats that total.
- **"Buy vs Build" sub-tab.** Same calculation as "Margins".
- **"Batch Scan" sub-tab.** The install cost of a single run goes into each row's margin. It is computed separately, with the assumptions described below.
- **Project, "Production" tab, "Structures" card.** Each structure's total includes the installation (cost index, role bonus, tax).

The cost of a job already started, read from the ESI and recorded as a "Job Install" contribution of a project, does not go through this calculation: it is the amount the game charged.

## The formula

### 1. Estimated item value (EIV)

EIV = runs × sum over materials of (base quantity per run × adjusted price of the material)

Quantities are the blueprint's in the SDE, **before ME and before any structure or rig bonus**. The adjusted price is the one the ESI publishes; it is not a market price.

### 2. The job base

- Manufacturing and reaction: base = EIV.
- Copying and invention: base = 2% of the manufacturing EIV of the blueprint concerned (copied or invented).

### 3. The install cost

install cost = base × cost index × structure cost multiplier
\+ base × facility tax
\+ base × 4% (SCC surcharge)

- The **cost index** is the one of the job's system and activity.
- The **structure cost multiplier** combines the cost role bonus of the structure type and, for copying, invention and research, the cost bonus of the science rigs for that activity. It only applies to the cost index term, never to the tax or the SCC. Without a structure it is 1.
- The **facility tax** is the one you entered for the structure. With no player structure able to run the job, the job is counted in an NPC station at 0.25%.
- The **SCC surcharge** is 4% of the base, whatever the structure.
- No rounding is applied in the calculation. The "Margins" screen shows each row without decimals.
- The alpha clone tax (0.25% of the base) exists in the calculation but is never switched on: EVE Tools always counts an omega clone.

### Which system, which structure

- **Structure.** For manufacturing and reactions, EVE Tools picks the structure that cuts the product's materials the most, preferring your favorite system. For copying and invention, it is the structure with the lowest install rate (cost index × cost multiplier + tax). A structure declared as "NPC Station" is not part of this choice: with no suitable player structure, the calculation uses the default NPC station described above.
- **System of the cost index.** The structure's own system if it was imported from the game (its system is then known). Otherwise, on "Margins", "Batch Scan" and "Pivot", the system picked in "Solar System"; with "Default", your favorite manufacturing system (or reaction system for a reaction), and Jita if you have none. A system picked by hand is used for both manufacturing and reactions.

## What ME, TE and material and time bonuses change

ME, TE, material bonuses and time bonuses **do not change the install cost**, because the EIV starts from base quantities. They change the material quantities to buy and the duration.

Quantity of a material for a manufacturing job:

quantity = max(runs, round up [round to 2 decimals (runs × base quantity × (1 − ME / 100) × material multiplier)])

- The game rounds once per job, never per run, and never below one unit per run.
- The **material multiplier** = material role bonus of the structure type (manufacturing only) × the multiplier of each rig that targets the category of the job's **product**.
- Rig multiplier = 1 − (rig bonus in % × security modifier) / 100. The bonus and the security modifiers (high, low, null) come from the SDE.
- A reaction ignores ME: it always counts ME 0.
- TE and time bonuses (time role bonus, time rigs) cut the job duration, not its cost.

## Where each term comes from

| Term | Source | Freshness |
|---|---|---|
| Cost index | ESI `GET /industry/systems/`, per system and activity | Synced every 2 h, kept 6 h in cache |
| Adjusted price | ESI `GET /markets/prices/` | Synced every 24 h, kept 72 h in cache |
| Base material quantity | SDE, materials of the blueprint activity | On each SDE import |
| Role bonuses (material, cost, time) | SDE, attributes of the structure type (Raitaru, Azbel, Sotiyo, Athanor, Tatara) | On each SDE import |
| Rig bonus and security modifiers | SDE, attributes of the rig | On each SDE import |
| Type, security, rigs, facility tax | Setting: "Configuration" panel of the Industry page, "Structures" section | Your input |
| Favorite systems | Setting: "Favorite systems" ("Manufacturing", "Reactions") | Your input |
| ME, TE, runs | Screen input (defaults below) | Your input |

The "Cost indices" and "Adjusted prices" labels under the analysis sub-tabs give the age of the last sync (see [data freshness](/help?page=data/freshness-privacy)).

## Example

Example values, round, not taken from the game.

A manufacturing job of 10 runs. Per run, the blueprint needs 1,000 units of material A (adjusted price 500 ISK) and 100 units of material B (adjusted price 5,000 ISK).

- EIV = 10 × (1,000 × 500 + 100 × 5,000) = 10 × 1,000,000 = **10,000,000 ISK**.
- System cost index: 0.05 (5%).

In an NPC station (cost multiplier 1, tax 0.25%):

| Term | Calculation | ISK |
|---|---|---|
| Cost index | 10,000,000 × 0.05 × 1 | 500,000 |
| Facility tax | 10,000,000 × 0.0025 | 25,000 |
| SCC surcharge | 10,000,000 × 0.04 | 400,000 |
| **Install cost** | | **925,000** |

In a player structure with a cost multiplier of 0.9 and an entered tax of 1%:

| Term | Calculation | ISK |
|---|---|---|
| Cost index | 10,000,000 × 0.05 × 0.9 | 450,000 |
| Facility tax | 10,000,000 × 0.01 | 100,000 |
| SCC surcharge | 10,000,000 × 0.04 | 400,000 |
| **Install cost** | | **950,000** |

Here the structure costs more to install: its tax outweighs the saving on the cost index. It can still be the better choice if its rigs save more on materials.

An invention of the same product would have a base of 2% × 10,000,000 = 200,000 ISK (EIV of one manufacturing run of the T2 product, assumed here to equal the example's).

Materials, same example: 100 base units per run, 10 runs, ME 10, material role bonus 0.99 and one 2% rig in low security with a modifier of 1.9 (multiplier 1 − 2 × 1.9 / 100 = 0.962):
10 × 100 × 0.9 × 0.99 × 0.962 = 857.14, rounded up **858 units**. The install cost does not move.

## When the cost is unknown

The install cost is **unknown**, never 0, when one of these is missing:

| Missing data | Message on screen |
|---|---|
| The system cost index for the activity | "System cost index unknown" |
| The adjusted price of at least one material | "ESI adjusted price unknown" |
| The facility tax of the chosen structure (field left empty) | "Facility tax not set on the structure: install cost unknown." |

- On "Margins", the job row and the "Total Job Install Cost" show "Unknown". The total cost and the margins are then unknown, and the sell comparison table is replaced by the cause or causes.
- On "Batch Scan", the row shows a single cause, the first one found.
- A tax entered as 0 stays a real 0%: enter 0 if the structure charges nothing.
- A cost index or an adjusted price leaves the cache when it has not been synced again for three intervals (6 h for the cost index, 72 h for adjusted prices): the cost becomes unknown until the next sync.

## Default ME and TE per screen

Screens do not start from the same values. Check ME and TE before comparing two screens.

| Screen | T1 product | T2 product | Reaction |
|---|---|---|---|
| "Margins" | ME 10, TE 20 | ME 2, TE 4, then those of the chosen decryptor | ME 0 in the calculation |
| "Buy vs Build" | ME 2 (no TE field) | ME 2 | ME 0 in the calculation |
| "Batch Scan" (fixed) | ME 10, TE 20 | ME 2, TE 4 | ME 0, TE 0 |
| Project creation | ME 2, TE 4 | ME 2, TE 4 | ME 0 in the calculation |

- For a T2 on "Margins", the product ME and TE are the invention's (2 and 4 without a decryptor, changed by the decryptor), whatever you type.
- Components built along the way always count ME 10 and TE 20, and reactions ME 0.

## Pitfalls

- **ME does not lower the install cost.** The EIV starts from base quantities, before ME and before bonuses.
- **An empty tax makes the cost unknown.** It does not count as 0%.
- **"Batch Scan" simplifies.** One run, fixed ME, and material quantities without structure or rig bonus. The cost index is the analysis system's, even for a structure imported in another system. For a T2, the calculation assumes no decryptor, while the screen's help text announces an Attainment decryptor.
- **A project's "Structures" card.** A structure created by hand (not imported) has no known system: its installation is unknown there, while "Margins" uses your favorite system for the same structure.
- **The "Calculated bonus" preview of the structure configuration** is an overall indication. The calculation applies each rig only to products of the category it targets, with the SDE values.
- **The note under the "Job Install Cost" table** says actual costs may vary with facility bonuses and structure rigs. The calculation already includes them (cost role bonus, science rigs); the remaining gap comes from the cost index and adjusted prices, which change between the sync and the moment you start the job.

## Technical note

Cost: `InstallCost::forJob()` (`src/Industry/Domain/InstallCost.php`), EIV: `EstimatedItemValue::of()`, called by `ProductionCostCalculator::installCost()` for "Margins", "Buy vs Build" and a project's buy or build card, by `StructureTotalCostComparator::breakdownOf()` for a project's "Structures" card, and by `JobInstallCostEstimator::estimate()` for "Batch Scan" and "Pivot". Quantities: `MaterialQuantity::forManufacturingJob()`. Multipliers: `ProductionStructure::installCostMultiplierFor()` and `materialMultiplierFor()`.
