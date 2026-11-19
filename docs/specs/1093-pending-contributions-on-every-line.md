# Spec #1093 — Pending contributions on every BOM line

Status: validated in night mode (design validated by Sylvain in the issue body). Level of technicity: 2 (Industry code, displayed quantity).

## Problem

The BOM and the companion show "Awaiting approval" only for partly filled lines that carry a reservation. A line filled in the container, or a contribution made without a reservation, shows nothing, while the "N to review" counter (`pendingContributionCountReviewableBy`) counts them. The reservation chip also prints `×0` when the whole reservation is covered by pending contributions.

## Behaviour

### API (`GET /industry/projects/{projectId}/bom`)

- Every `ProjectBomLineResource` exposes `pendingContributions: list<{participantId, characterName, quantity, isMine}>`.
- Source: `IndustryProjectContributionRepository::sumPendingQuantityByLineAndParticipant` (pending contributions, material and others with a BOM line), for every line, fulfilled or not, with or without a reservation.
- `characterName` is `PilotName::of` of the participant's user (main character name), `isMine` is true when the participant's user is the viewer.
- Entries are sorted by `characterName`, then `participantId`, so chips do not move between refreshes.
- `pendingQuantity` leaves the reservation entries. Reservations stay hidden on fulfilled lines.
- A line without pending contribution has `pendingContributions: []`.
- `GET /industry/projects/{projectId}/bom` access rules are unchanged (accepted member or owner).

### BOM (`LineReservation.vue`)

- One amber chip per entry of `pendingContributions`: `<name> · Awaiting approval ×N`, plus `(you)` when `isMine`. Shown on fulfilled lines, with or without reservation, hidden on solo projects (as reservations are).
- The name is truncated on one line with a `title` tooltip carrying the full name.
- The cyan reservation chip shows `×(reservation.quantity - pending quantity of that participant)` and is shown only when that value is above zero.
- The "free" hint keeps its condition on `item.reservations.length > 0`.

### Companion (`buildCompanionTasks`)

- `awaitingApproval`: one entry per line with a `pendingContributions` entry where `isMine`, quantity of that entry, without the `isFulfilled` and reservation filters.
- `mine` (lines to deliver): unchanged rule (not fulfilled, my reservation), quantity `reservation.quantity - my pending quantity`, kept when above zero.

### Contribute modal

- The prefilled quantity of a reserved line keeps its value: `reservation.quantity - my pending quantity`, read from `pendingContributions`.

## Out of scope

- The "N to review" counter, the i18n key `groupIndustry.companion.deliver`, the reservation rules and the `reservations` payload apart from dropping `pendingQuantity`.
- No migration.

## Tests

- PHPUnit `ProjectBomProviderTest`: fulfilled line with pending contributions, pending contribution without reservation, `isMine`, sorting, empty list, reservation payload without `pendingQuantity`.
- Vitest `companionTasks.spec.ts`, `lineReservation.spec.ts`, `contributeModalPendingReservation.spec.ts`.
