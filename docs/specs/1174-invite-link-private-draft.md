# #1174 — Invite link of a private or draft project

## Problem
A new project is private and a draft, yet the header always offers "Invite" / "Share link", which copies a link the guest cannot use (private: 404 "Project not found"; public draft: "no longer accepting new members", shown raw in English).

## Rules
1. Header share button (`GroupIndustryDetail.vue`, `data-testid="share-link"`) is disabled while the project is private or its status is `draft`. A private project that is also a draft gets the private reason.
2. A disabled button copies nothing and shows no "Link copied!" toast.
3. A one-line reason (`data-testid="share-link-reason"`) sits under the header actions while the button is disabled, FR and EN:
   - private: "A private project cannot be joined with a link. Make it public to invite people."
   - draft (public): "A draft project is not open yet. Publish it to invite people."
4. Shortcut (`data-testid="share-link-shortcut"`), owner or admin only, next to the reason:
   - private: "Make public", opens the edit modal (the existing private checkbox);
   - public draft: "Publish", requests the `published` status (same path as the header Publish button).
   Other members see the reason without a shortcut.
5. A public project that is published or in progress keeps the enabled button (copy plus toast) unchanged.
6. Backend: joining a public project in `draft` answers 400 "This project is not open to new members yet". Other non-open statuses keep "This project is no longer accepting new members". Private still answers 404 "Project not found".
7. Join page (`GroupIndustryJoin.vue`) translates the refusals instead of printing the API text:
   - HTTP 404 -> `groupIndustry.join.notAvailable`
   - 400 "not open to new members yet" -> `groupIndustry.join.notOpenYet` ("This project is not open yet. Ask its owner to publish it, then try again.")
   - 400 "no longer accepting new members" -> `groupIndustry.join.closed`
   - any other error keeps the raw message (current behaviour, "already" redirect included).

## Cases
| Project | Header button | Reason | Shortcut (owner) |
|---|---|---|---|
| private, draft | disabled | private | Make public |
| private, published | disabled | private | Make public |
| public, draft | disabled | draft | Publish |
| public, published / in_progress | enabled | none | none |

## Out of scope
- `ShareLinkCard` on the Members tab (#1229), Publish button visibility (#1189), join notifications (#1219).
- Completed or cancelled public projects keep an enabled button (not part of the issue).

## Open decisions
None.
