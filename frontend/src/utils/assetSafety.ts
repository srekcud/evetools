import { SHIP_CATEGORY_ID } from '@/constants/assetsConstants'
import type { Asset, AssetLocationSummaryRow } from '@/types'
import { summarizeVolume, type PackagedVolumeCatalog, type VolumeSummary } from '@/utils/assetVolume'

export interface AssetSafetyLocation {
  locationName: string
  solarSystemName: string
  itemCount: number
  shipCount: number
}

const ASSET_SAFETY_LOCATION_PATTERN = /(?:^| — )(Asset Safety \((.+)\))$/

export function summarizeAssetSafety(assets: Asset[]): AssetSafetyLocation[] {
  const byLocation = new Map<string, AssetSafetyLocation>()

  for (const asset of assets) {
    const match = ASSET_SAFETY_LOCATION_PATTERN.exec(asset.locationName)
    if (match === null) {
      continue
    }
    const [, locationName, solarSystemName] = match
    const summary = byLocation.get(locationName) ?? { locationName, solarSystemName, itemCount: 0, shipCount: 0 }
    summary.itemCount += asset.quantity
    if (asset.categoryId === SHIP_CATEGORY_ID && asset.isSingleton === true) {
      summary.shipCount += 1
    }
    byLocation.set(locationName, summary)
  }

  return Array.from(byLocation.values()).sort((a, b) => a.locationName.localeCompare(b.locationName))
}

export function summarizeAssetSafetyLocations(locations: readonly AssetLocationSummaryRow[]): AssetSafetyLocation[] {
  const byLocation = new Map<string, AssetSafetyLocation>()

  for (const location of locations) {
    const match = ASSET_SAFETY_LOCATION_PATTERN.exec(location.locationName)
    if (match === null) {
      continue
    }
    const [, locationName, solarSystemName] = match
    const summary = byLocation.get(locationName) ?? { locationName, solarSystemName, itemCount: 0, shipCount: 0 }
    summary.itemCount += location.itemCount
    summary.shipCount += location.shipCount
    byLocation.set(locationName, summary)
  }

  return Array.from(byLocation.values()).sort((a, b) => a.locationName.localeCompare(b.locationName))
}

export function summarizeAssetSafetyVolumes(
  locations: readonly AssetLocationSummaryRow[],
  assetsOf: (locationId: number) => readonly Asset[],
  contentsOf: (itemId: number) => readonly Asset[],
  catalog: PackagedVolumeCatalog,
): Map<string, VolumeSummary> {
  const assetsByLocationName = new Map<string, Asset[]>()

  for (const location of locations) {
    const match = ASSET_SAFETY_LOCATION_PATTERN.exec(location.locationName)
    if (match === null) {
      continue
    }
    const locationName = match[1]
    assetsByLocationName.set(locationName, [...(assetsByLocationName.get(locationName) ?? []), ...assetsOf(location.locationId)])
  }

  const volumes = new Map<string, VolumeSummary>()
  for (const [locationName, assets] of assetsByLocationName) {
    volumes.set(locationName, summarizeVolume(assets, contentsOf, catalog))
  }

  return volumes
}
