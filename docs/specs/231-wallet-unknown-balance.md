# #231 — Wallet: unknown balances must reach the frontend in every format

## Context

The fix for #37 flags the wallet total as partial when a balance is unknown (`incomplete: true`, entry `balance: null`). The frontend calls the API without an `Accept` header, so it receives JSON-LD, and API Platform omits `null` properties in that format. The entry then has no `balance` key, the frontend's `=== null` checks never match, and the Dashboard shows the lower bound as a complete total.

## Decision (Sylvain, 2026-10-23)

Backend: `GET /api/me/wallets` always serializes `balance: null` for an unknown balance, in JSON-LD and in JSON. The frontend contract (`balance === null` means unknown) stays as is.

## Rules

1. An entry whose balance could not be read carries the key `balance` with value `null`, whatever the requested format.
2. A known balance is serialized as a number, including `0`.
3. `totalBalance` stays the sum of known balances; `incomplete` is `true` as soon as one balance is unknown.
4. The frontend shows the partial-total line on the Dashboard and the `characters.walletUnknown` label on the Characters page when it receives `balance: null`.

## Examples

| Request | Characters | Expected response / display |
|---|---|---|
| `GET /api/me/wallets`, `Accept: application/ld+json` | A known 1 500 000.5 ISK, B unknown | `wallets[B].balance === null` (key present), `wallets[A].balance === 1500000.5`, `totalBalance: 1500000.5`, `incomplete: true` |
| same, `Accept: application/json` | same | same entry shapes |
| same, ld+json | A known 0 ISK | `wallets[A].balance === 0`, `incomplete: false` |
| Dashboard | response with `incomplete: true`, 1 entry `balance: null` | partial-total line shown with 1 unknown balance |
| Characters | entry `balance: null` for a character | card shows `characters.walletUnknown`, not `--- ISK` |

## Out of scope

- Changing `authFetch` headers or the null-skipping behaviour of other resources (same trap may exist elsewhere: separate issue if found).
