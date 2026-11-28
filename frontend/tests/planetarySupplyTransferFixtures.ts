import type { SupplyHolderCharacter, SupplyHolders, SupplyLaunchpad } from '@/utils/planetarySupply'
import { COOLANT, MECHANICAL_PARTS, feedOf, launchpadOf } from './planetarySupplyFixtures'

export const X = COOLANT
export const Y = MECHANICAL_PARTS
export const TRANSFER_VOLUME_PER_UNIT = 0.75
export const TRANSFER_DAYS = 4

export const ALPHA = { characterId: 2_119_100_001, characterName: 'Alpha' }
export const BRAVO = { characterId: 2_119_100_002, characterName: 'Bravo' }
export const CARL = { characterId: 2_119_100_003, characterName: 'Carl' }
export const HOLD = { characterId: 2_119_100_004, characterName: 'Hold' }

export const HOME_LOCATION_ID = 60_003_760
export const HOME_LOCATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
export const ASSETS_READ_AT = '2026-11-28T07:00:00+00:00'

export function transferFeed(typeId: number, dailyRate: number) {
  return feedOf(typeId, { dailyRate, volumePerUnit: TRANSFER_VOLUME_PER_UNIT })
}

export function alphaFirstLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({
    ...ALPHA,
    colonyId: 'colony-alpha',
    planetName: 'Alpha I',
    pinId: 1_101,
    feeds: [transferFeed(X, 250), transferFeed(Y, 125)],
    ...overrides,
  })
}

export function alphaSecondLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({
    ...ALPHA,
    colonyId: 'colony-alpha-2',
    planetName: 'Alpha II',
    pinId: 1_102,
    feeds: [transferFeed(X, 50)],
    ...overrides,
  })
}

export function bravoLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({
    ...BRAVO,
    colonyId: 'colony-bravo',
    planetName: 'Bravo I',
    pinId: 1_103,
    feeds: [transferFeed(X, 100)],
    ...overrides,
  })
}

export function transferLaunchpads(): SupplyLaunchpad[] {
  return [alphaFirstLaunchpad(), alphaSecondLaunchpad(), bravoLaunchpad()]
}

export const MIXED_VOLUME_X_PER_UNIT = 0.75
export const MIXED_VOLUME_Y_PER_UNIT = 0.5

export function mixedVolumeLaunchpad(overrides: Partial<SupplyLaunchpad> = {}): SupplyLaunchpad {
  return launchpadOf({
    ...ALPHA,
    colonyId: 'colony-alpha',
    planetName: 'Alpha I',
    pinId: 1_101,
    feeds: [
      feedOf(X, { dailyRate: 250, volumePerUnit: MIXED_VOLUME_X_PER_UNIT }),
      feedOf(Y, { dailyRate: 125, volumePerUnit: MIXED_VOLUME_Y_PER_UNIT }),
    ],
    ...overrides,
  })
}

export function knownHolder(
  character: { characterId: number; characterName: string },
  quantities: { typeId: number; quantity: number }[],
  overrides: Partial<SupplyHolderCharacter> = {},
): SupplyHolderCharacter {
  return {
    ...character,
    status: 'known',
    unknownReason: null,
    assetsReadAt: ASSETS_READ_AT,
    stale: false,
    quantities,
    ...overrides,
  }
}

export function unknownHolder(
  character: { characterId: number; characterName: string },
  unknownReason: 'missing_assets_scope' | 'assets_never_read',
): SupplyHolderCharacter {
  return {
    ...character,
    status: 'unknown',
    unknownReason,
    assetsReadAt: null,
    stale: false,
    quantities: [],
  }
}

export function holdersOf(characters: SupplyHolderCharacter[], homeLocationId: number | null = HOME_LOCATION_ID): SupplyHolders {
  return {
    homeLocationId,
    homeLocationName: homeLocationId === null ? null : HOME_LOCATION_NAME,
    characters,
  }
}

export function referenceHolders(): SupplyHolders {
  return holdersOf([
    knownHolder(ALPHA, [{ typeId: X, quantity: 300 }]),
    knownHolder(BRAVO, []),
    knownHolder(HOLD, [{ typeId: X, quantity: 3000 }, { typeId: Y, quantity: 100 }]),
  ])
}
