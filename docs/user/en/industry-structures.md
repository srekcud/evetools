# Configuring your industry structures

Production structures carry the structure and rig bonuses: fewer materials and shorter jobs. Without any configured structure, Industry projects are calculated with no bonus at all.

Do not confuse them with the **Market Structure** in the settings (click your character card at the bottom of the menu). That one is only used to compare prices with Jita.

> The Industry calculation engine is being reworked. The rules described here may change.

## Where to find the configuration

1. In the side menu: **Production** › **Industry**.
2. On the right of the tab bar, click the gear icon (tooltip **Configuration**). The icon is hidden while a project is open: go back to the list first.
3. The **Configuration** panel opens on the **Fees** section. Expand the **Structures** section.

The section has two blocks: **Favorite systems** at the top, then the **Production structures** list.

## Adding a structure

Click **Add**: the **New structure** form opens. There are three ways to fill it.

- **Corporation shared structures**: this list offers the structures that other members of your corporation have already imported and that are not in your list yet. The "Rigs configured" mention means a configuration exists. Picking a structure fills in the name, type, security and rigs.
- **Or search by name (ESI)**: type at least 3 characters. The search runs through your main character and returns at most 10 results. Structures already in your list are not offered, and those whose details your character cannot read are skipped. A star ★ marks a structure of your corporation. The name and type are filled in (Raitaru, Azbel, Sotiyo, Athanor, Tatara), **but not the security**: pick it yourself.
- **Manual entry**: fill in the name only. Such a structure is not shared and is not recognised when an ESI job ran in it (see below). Prefer importing whenever possible.

Form fields:

| Field | Content |
|---|---|
| **Name** | Required. |
| **Structure type** | **NPC Station (no bonus)**, Raitaru, Azbel, Sotiyo (Engineering Complexes, for manufacturing), Athanor, Tatara (refineries, for reactions). |
| **Security** | **High-Sec (x1.0)**, **Low-Sec (x1.9)**, **Null-Sec (x2.1)**. The multiplier applies to the rig bonus. The values shown are the manufacturing ones; for reaction rigs, project calculations apply ×1.0, ×1.0 and ×1.1. Default: Null-Sec. |
| **Rigs (material bonus)** | Up to 3 rigs. Type at least 2 characters. The list only offers rigs matching the structure size: manufacturing rigs for an Engineering Complex, reaction rigs for a refinery. Each rig shows its ME bonus (and TE if it has one) and the product categories it targets. |

The **Calculated bonus (base structure + rigs x security)** box gives a preview before saving. Confirm with **Add**.

An imported structure can only be added once. If it is already in your list, saving is refused with the message "This structure is already imported (*name*). Edit it from the list instead.": edit the existing entry. In the corporation list and in the ESI search, a structure already imported shows the "already imported" mention.

The icons on the right of each structure let you edit or delete it.

## Corporation shared structures

A structure is shared with your corporation when it was imported (corporation list or ESI search) and belongs to your corporation. It then shows the **CORPO** badge.

- Its configuration (type, security, rigs) is offered to other members when they add that structure. The most recently created configuration is the one offered.
- Each member keeps **their own copy**. If you edit yours, the copies other members already imported do not change. A confirmation (**Edit corp structure**) is requested if you change the rigs, type or security.
- Deleting a corp structure removes it from your list, but its configuration stays available to others. If you import it again, you get your previous entry back, updated with the values you entered.
- The message "No shared structures. This list shows the corporation structures configured by other members." means that no other member has shared a structure you do not already have.

## Favorite systems

The **Favorite systems** block offers one system for **Manufacturing** and one for **Reactions**. Type at least 2 characters and pick the system; the cross removes it.

These systems are used for:

- **step job install costs**: the cost index used is the one of the favorite system for the activity. Without a favorite system, Jita's is used;
- **the analysis tabs** **Margins**, **Batch Scan**, **Buy vs Build** and **Pivot**: the favorite manufacturing system is the default system. Without one, Jita is used.

They are also used to choose the structure of a step (see below). Only imported structures know their system: a structure entered by hand is never picked as the favorite system's structure.

## What these settings are used for

For each step of a project, the calculation picks a structure:

1. **The structure attached to the step**, if any. Attachment is automatic: when an ESI job linked to the step ran in an **imported** structure from your list, the step takes that structure. The interface does not let you choose it by hand.
2. **Otherwise, your best structure located in the favorite system** for the activity (manufacturing or reaction): the one giving the best material bonus for the product category. It takes precedence over a better structure located elsewhere.
3. **Without a favorite system, or without any structure in that system, your best structure overall**: among Engineering Complexes for manufacturing, or refineries for reactions, the one giving the best material bonus for the product category.

The material bonus combines the structure base bonus (1% for an Engineering Complex in manufacturing) with the rig bonus multiplied by security. The time bonus combines the base bonus (Raitaru 15%, Azbel 20%, Sotiyo 30% in manufacturing; 25% in reactions for refineries) with the time rigs. A rig only applies to the product categories it targets.

In a project tree:

- **Unconfigured**: a job linked to the step ran in a structure that is not in your list, or that was entered by hand. Import it so that its bonuses are taken into account.
- **Suboptimal — Better : …**: another of your structures gives a better material bonus for this product.

## Known pitfalls

- **Structure type in the favorite system**: in the favorite system, the structure type is not checked. A refinery alone in your favorite manufacturing system can be picked for a manufacturing step, with no bonus, instead of your best Engineering Complex located elsewhere (and the other way round for reactions). Known.
- **Duplicates created before the fix**: refusing a second import does not remove duplicates that already exist. Delete the extra entry.
- **Athanor and Thukker rigs**: the Athanor wrongly gets a 25% time bonus in reactions, and the Thukker rig bonus is mis-evaluated (#71). Known, being fixed.
- **Displayed reaction material bonus**: the "ME Reactions" value in the list and in the preview uses the manufacturing security multiplier. Project calculations are not affected, only the display is wrong (#72). Known, being fixed.
- **"ME Manuf" bonus in the list**: it is the sum of all manufacturing rigs, across all categories, without the 1% base bonus. It therefore matches no specific product. The bonus actually applied is shown in the project.
- **Security after an ESI search**: it is not filled in automatically. Check it before saving.
