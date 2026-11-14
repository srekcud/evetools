# FAQ: account and characters

## Why does EVE Tools ask for 31 ESI scopes?

A scope is a permission that CCP asks you to grant. EVE Tools requests the 31 scopes of its list in a single EVE SSO authorization, at login and every time you add a character. Most of them are reads (assets, wallet, jobs, blueprints, skills, market). Two act in the game client: opening a window and setting the destination.

Six scopes are requested in advance: no feature reads the skill queue, the location, the online status, the fleet, the in-game notifications or the killmails yet.

The full table, scope by scope, is on the [ESI scopes](/help?page=data/esi-scopes) page. It is generated from the code.

## How do I add an alt?

Menu **Characters**, button **Add character**. You go through EVE SSO for the new character, which is attached to your account. A sync of its assets starts in the background.

## How do I change my main character?

On an alt's card, in **Characters**, click **Set as main**. The former main stays in your account as an alt.

The main matters in several places:

- it is the only one of your characters that the other participants of a group project see;
- the structure search and the reading of your skills and standings for the seller fees go through it;
- it cannot be removed: set another character as main before removing the old one.

## What happens when a token expires?

The access token is renewed on its own with the refresh token: you have nothing to do.

When EVE SSO refuses the renewal (you revoked the application on the CCP side, or the refresh token expired), the character shows **Token expired**. It drops out of the syncs by itself and the other characters of your account are not affected. Click **Reauthorize** on its card to reconnect it through EVE SSO: it becomes valid again.

The **Missing scopes** badge flags another case: the token is valid but does not cover every required scope. Reauthorizing fixes that case too.

## Is my data visible to my corporation? To the members of a group project?

Your inventory, wallet, skills, jobs and colonies are attached to your account. These are the places where something is shared.

- **Production structures**: a structure of your corporation that you import becomes available to the other members of the corporation, with its configuration (type, security, rigs). Each member keeps their own copy.
- **Corporation inventory**: a director sets, division by division, what is visible (all members, a list of players, or hidden). The corporation's blueprints can stay shared even for a hidden division: that is a director setting, ticked by default.
- **Project templates**: a template can be shared with the corporation of your main character.
- **Group projects**: participants see the members, the contributions, the sales and the activity feed of the project. They see your main character, never your alts.

## How do I delete my account and my data?

In **Characters**, **Danger zone** section, button **Delete my account**. You confirm by typing the exact name of your main character. The deletion is immediate and cannot be undone.

Deleted: all your characters, your EVE SSO tokens (revoked with CCP), your personal data (assets, skills, jobs, transactions, PI, alerts, notifications, settings) and your personal industry projects.

Kept and shown as "Deleted pilot": your approved contributions to other people's group projects and the sales you recorded in them. Your contributions that were not approved are deleted.

A group project you own is transferred to its oldest admin, otherwise to its oldest member, otherwise deleted.
