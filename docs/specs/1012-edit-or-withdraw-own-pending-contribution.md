# #1012 Edit or withdraw one's own pending contribution

Circuit m. Part of the issue only: the value source choice (wallet purchase or Jita price) depends on #1009, absent from `origin/main`, and is out of this lot.

## Rules

1. **Withdraw** (`DELETE /industry/projects/{projectId}/contributions/{id}`, provider `ProjectContributionItemProvider`): the contributor removes their own contribution while it is `pending`. The line reservation of the member is untouched.
2. **Edit** (`PATCH /industry/projects/{projectId}/contributions/{id}/details`, `application/merge-patch+json`, input `EditContributionInput`): the contributor changes `quantity`, `note` and, for a non-material type, `estimatedValue`. A field left `null` is unchanged; an empty `note` clears it. At least one field is required.
3. Both actions are refused (400) when the contribution is not `pending`, when it `isAutoDetected`, or when the project is completed. A contribution of another participant is refused (403, `AccessDeniedHttpException`). A non-accepted member is refused (403).
4. Edit re-applies the submission rules: `quantity` must not exceed `BomLine::getRemainingQuantity()`; a material contribution takes no entered value (`resolveMaterialValue()` revalues it, wallet purchases then Jita price); `job_install` and `bpc` need a positive value; `line_rental` needs a value; `courier` is not editable. The duplicate rule does not apply (the contribution is its own predecessor).
5. Pending stays pending after an edit; `reviewedBy`, `reviewedAt` untouched. The BOM fulfilment is not changed (pending contributions do not count).
6. Mercure, topic of the group project: `contribution_withdrawn` and `contribution_updated`, payload `{contributionId, characterName, bomItemName, quantity}`.
7. The resource exposes `editableByViewer` (own, pending, not auto-detected, project not completed), set by the collection provider, the item provider and the three processors. The front shows Edit and Withdraw only when it is true.

## Examples

| Case | Result |
|---|---|
| pending material 100 on a line with 300 remaining, edit quantity 250 | quantity 250, value revalued |
| same, edit quantity 301 | 400, contribution unchanged |
| approved or rejected contribution, edit or withdraw | 400 |
| other participant's pending contribution, edit or withdraw | 403 |
| owner withdraws a participant's contribution | 403 (admins keep approve, reject, reopen) |
| material contribution with `estimatedValue` in the edit | 400 |
| withdraw then submit again on the same line | accepted (the anti-duplicate rule no longer blocks) |

## Out of scope

Value source choice and wallet-awaiting state (#1009). Editing or withdrawing another participant's contribution. Recording the two actions in the activity feed.

## Front

Contributions tab: Edit (quantity, note, value for non-material) and Withdraw (with confirmation) on rows where `editableByViewer`. The companion lists the viewer's pending contributions with Withdraw, fed by the same store.
