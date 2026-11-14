# 881 - Volume and volume sort of the By type view (remainder)

Delivered before: By type view, search, name/quantity sort, absent in corporation view (#926); packaged volume catalogue (#890, #964), `summarizeVolume`, `AssetVolumeBadge`.

## Rules

1. Each type row shows the packaged volume of the type: unit volume x total quantity of the row, in m³, with `AssetVolumeBadge` (`data-testid="type-volume"`).
2. The total quantity of a row follows the active filters (system, location), so the volume follows too.
3. A type absent from the catalogue shows 0 m³ with the "type without volume" marker (never a silent 0).
4. A type in `excludedTypeIds` shows no volume badge and counts as no volume for sorting.
5. A "Volume" sort button joins Name and Quantity, default direction descending, a second click reverses it. Types without volume (unknown or excluded) always sort last, then by name.
6. Without a loaded catalogue, no volume badge and no volume sort button; name and quantity sorts are unchanged.

## Wiring

- `utils/assetsByType.ts`: `typeGroupVolume(group, catalog)` returns `VolumeSummary | null` (null when excluded); `sortTypeGroups(groups, key, direction, catalog)` accepts the `volume` key.
- `AssetTypeView.vue`: optional prop `packagedVolumes`.
- `Assets.vue`: passes `packagedVolumeCatalog`.
- i18n `assets.byType.sortVolume` (en, fr).

## Out of scope

Per-location volume in the expansion; any change to the By location view.
