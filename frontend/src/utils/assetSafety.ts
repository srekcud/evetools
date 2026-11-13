import { SHIP_CATEGORY_ID } from '@/constants/assetsConstants'
import type { Asset } from '@/types'

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
