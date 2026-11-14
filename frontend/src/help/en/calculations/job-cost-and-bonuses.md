# Job cost, structure and rig bonuses, ME and TE

This page explains how EVE Tools calculates what a job costs to install, how many materials it consumes and how long it lasts. For each term: the formula, where the data comes from, how fresh it is, and what happens when it is missing.

It applies to **manufacturing** and **reaction** jobs. Copying, invention and research follow the same logic with a different base (see below).

## Where these calculations are used

- The **Margins**, **Batch Scan**, **Buy vs Build** and **Pivot** analyses, as well as the buy-or-build comparison of a project, **estimate** the install cost with the formula below.
- In a project, once an ESI job is linked to a line, the install cost counted in the distribution is the **real cost read from the ESI job**, not this estimate. If the job has no cost, the amount is unknown.
- Material quantities and durations in a project follow the ME, TE and bonus rules in the "Materials: ME, structure and rigs" and "Duration" sections.

## Install cost

The install cost is the sum of three terms computed on the same base:

```
install cost = base × cost index × cost role bonus
             + base × facility tax
             + base × SCC surcharge (4%)
```

| Term | What it is | Where the data comes from |
|---|---|---|
| **Base** | The job's **EIV** (estimated item value): `runs × Σ (base quantity × adjusted price)`. Quantities are the SDE ones, **before ME and before any** structure or rig bonus. | Quantities: SDE. Adjusted price: ESI `GET /markets/prices/`. |
| **Cost index** | Index of the solar system and activity. It rises with the industrial activity of the system. | ESI `GET /industry/systems/`. |
| **Cost role bonus** | Multiplier of the structure (1 without a structure). It only applies to the cost index term. | SDE, depending on the structure type. |
| **Facility tax** | Percentage taken by the structure owner, from 0 to 50%. | Entered by you on the structure; 0.25% in an NPC station, set by the game. |
| **SCC surcharge** | Fixed tax of 4% of the base in manufacturing and reactions. | Constant in the code. |

Points to remember:

- The cost role bonus multiplies **only** the cost index term. It never reduces the facility tax or the SCC.
- The tax is an extra term: it does not multiply the cost index.
- With no structure able to run the job, the calculation assumes an **NPC station**: 0.25% tax, no role bonus.
- The **system** whose cost index is read is the structure's system when the structure was imported (only imported structures know their system). Otherwise it is the favorite system of the activity, and in the analyses the selected system (Jita by default). With no system at all, the cost index is unknown.
- A role bonus missing from the SDE counts as 1, meaning no bonus.
- EVE Tools does not count the Alpha clone tax.
- How a job's structure is chosen is described in the guide [Configuring your industry structures](/help?page=playbooks/industry-structures).

### Other activities

- **Copying and invention**: the base is not the EIV but **2% of the manufacturing EIV** of the blueprint concerned (copied or invented), multiplied by the runs. The SCC is 4%. Laboratory rigs add a cost rig bonus on the cost index term.
- **ME and TE research**: base = 2% of the manufacturing EIV × a multiplier depending on the target level, SCC of 2%. This model still has to be confirmed in game.

## Materials: ME, structure and rigs

```
quantity = runs × base quantity × (1 − ME / 100) × mod_structure × Π mod_rig
```

- **ME**: research level of the blueprint, from 0 to 10. Each level removes 1% of the materials.
- **mod_structure**: role bonus of the structure on materials, read from the SDE. It only applies to manufacturing.
- **mod_rig**: for each rig whose category targets the category of the job's **product** (never the material's), `1 − bonus % × security modifier / 100`. The rig bonus and the security modifier come from the SDE (the security chosen on your structure: High-Sec, Low-Sec or Null-Sec).
- **Rounding**: once per job, never per run. The result is rounded to 2 decimals, then up, and never goes below **1 unit per run**.
- **Reactions**: always ME 0, the reaction formula has no ME, and there is no role bonus on materials.

### Where a project's ME comes from

- For a blueprint you own (characters and corporation), the plan takes the **best** one: highest ME, then highest TE, then the BPO before the BPC. It replaces the ME entered.
- Otherwise, the ME entered on the project product. At creation, a product added by hand starts at ME 2 / TE 4; a product preloaded from an analysis starts at ME 0 / TE 0 (TE 4 for a T2). These values can be changed.
- Components manufactured within the project count at ME 10 and TE 20.

## Duration

```
duration = runs × base time × (1 − TE / 100) × mod_skills × mod_structure × Π mod_rig
```

- **Base time**: SDE, per run, before TE, structure and skills.
- **TE**: research level of the blueprint, from 0 to 20 in steps of 2. Each level removes 1% of the time. A reaction has no TE.
- **mod_skills** (manufacturing): `(1 − 0.04 × Industry) × (1 − 0.03 × Advanced Industry)`, then `1 − 0.01 × level` for each science of the blueprint. Reaction: `1 − 0.04 × Reactions`. A skill never trained counts as level 0.
- **mod_structure**: time bonus of the structure, read from the SDE (a different attribute for reactions).
- **mod_rig**: as for materials, only the time rigs that target the product's category, scaled by security.
- **Rounding**: once on the whole job.

The duration is calculated character by character with **their** skills. A character whose skills have not been synchronized has no duration: it stays unknown rather than assumed.

## Worked example

> **Made-up example, not game values.** The figures below are chosen for the calculation. Real bonuses come from the SDE and the rigs of your structure.

**Install cost** of a manufacturing job, base (EIV) of 100,000,000 ISK, cost index of 5% (0.05), cost role bonus of 0.97, facility tax of 2% entered on the structure:

| Term | Calculation | Amount |
|---|---|---|
| Cost index | 100,000,000 × 0.05 × 0.97 | 4,850,000 ISK |
| Facility tax | 100,000,000 × 0.02 | 2,000,000 ISK |
| SCC surcharge | 100,000,000 × 0.04 | 4,000,000 ISK |
| **Total** | | **10,850,000 ISK** |

The same job in an NPC station: 100,000,000 × 0.05 = 5,000,000, plus 0.25% = 250,000, plus the 4,000,000 SCC, which is **9,250,000 ISK**.

**Materials**: 10 runs, 1,000 base units per run, ME 10, role bonus of 0.99 and one rig at 2% in High-Sec (security modifier 1.0, so mod_rig 0.98):

`10 × 1,000 × 0.90 × 0.99 × 0.98 = 8,731.8`, rounded up: **8,732 units** (10,000 without ME or bonuses).

**Duration**: 10 runs, 6,000 s base per run, TE 20, Industry 5 and Advanced Industry 5, structure time bonus of 0.85:

`60,000 × 0.80 × (0.80 × 0.85) × 0.85 = 27,744 s`, which is **7 h 42 min 24 s** (60,000 s without TE or bonuses).

## When the result is unknown

An install cost is **unknown**, never 0, as soon as a piece of data is missing. A sum that contains an unknown amount stays unknown.

| Missing data | Message shown | Usual cause |
|---|---|---|
| Cost index | "System cost index unknown" | No cost index for this system and activity in the cache, or no known system (structure entered by hand, no favorite system). |
| Adjusted price | "ESI adjusted price unknown" | ESI gives no adjusted price for a material of the job, or a zero price. A single material without a price is enough. |
| Facility tax | "Facility tax not set on the structure: install cost unknown." | The structure has no tax entered. Enter the rate shown in game, or 0 if the structure takes nothing: an entered 0 is a real 0%. |

A duration is unknown when the base time, the TE or the character's skills are missing.

## Data freshness

| Data | Synchronization | Kept in cache | After expiry |
|---|---|---|---|
| System cost indices | Every 2 hours | 6 hours (3 synchronizations) | Unknown until the next successful synchronization |
| Adjusted prices | Every 24 hours | 72 hours (3 synchronizations) | Unknown until the next successful synchronization |
| Facility tax, type, security, rigs | What you entered | Until you change it | Not applicable |
| Role and rig bonuses, base times | Imported SDE | Until the next SDE import | Not applicable |
| Character skills | Character synchronization (ESI) | Until the next synchronization | The duration stays unknown until the skills are synchronized |
| ME and TE of owned blueprints | Blueprint synchronization, every hour | Until the next synchronization | The ME and TE entered on the product apply |

The first two rows are public ESI data, shared by all users.
