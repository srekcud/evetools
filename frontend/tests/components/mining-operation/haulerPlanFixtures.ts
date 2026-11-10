import type { MiningHaulerPlan } from '@/stores/miningOperation'

export const HAULER_OPERATION_ID = '0198c1f2-6a5e-7d11-9c44-5b2f3a0e9d71'
export const ZEOLITES_TYPE_ID = 45_490
export const HAULER_TYPE_NAMES: Record<number, string> = { [ZEOLITES_TYPE_ID]: 'Zeolites' }
export const EMPTIED_AT = '2026-11-10T20:56:00+00:00'
export const SITE_ETA_AT = '2026-11-10T21:10:00+00:00'

export function knownHaulerPlan(overrides: Partial<MiningHaulerPlan> = {}): MiningHaulerPlan {
  return {
    operationId: HAULER_OPERATION_ID,
    state: 'known',
    unknownReason: null,
    boostShipName: 'Orca',
    haulerName: 'Brann Holt',
    emptiedAt: null,
    originIsStart: true,
    capacityM3: 40_000,
    holdCapacityM3: 150_000,
    share: 0.5,
    inflowM3PerMinute: 5_000,
    fillM3: 20_000,
    fillRatio: 0.5,
    fullInMinutes: 4,
    isFull: false,
    transportM3PerMinute: 5_000,
    toMoveM3: 320_000,
    trips: 3,
    siteEtaAt: SITE_ETA_AT,
    rawTypeIds: [ZEOLITES_TYPE_ID],
    contributorNames: ['Aria Solberg', 'Cyra Lune'],
    ...overrides,
  }
}

export function unknownHaulerPlan(unknownReason: NonNullable<MiningHaulerPlan['unknownReason']>): MiningHaulerPlan {
  return knownHaulerPlan({
    state: 'unknown',
    unknownReason,
    capacityM3: null,
    holdCapacityM3: null,
    share: null,
    inflowM3PerMinute: null,
    fillM3: null,
    fillRatio: null,
    fullInMinutes: null,
    isFull: null,
    transportM3PerMinute: null,
    toMoveM3: null,
    trips: null,
    siteEtaAt: null,
    rawTypeIds: [],
    contributorNames: [],
  })
}

type MountedElement = { element: Element }

export function haulerElement(wrapper: MountedElement, testId: string): HTMLElement | null {
  return wrapper.element.matches(`[data-testid="${testId}"]`) ? (wrapper.element as HTMLElement) : wrapper.element.querySelector<HTMLElement>(`[data-testid="${testId}"]`)
}

export function haulerText(wrapper: MountedElement, testId: string): string | null {
  const element = haulerElement(wrapper, testId)
  return element === null ? null : (element.textContent ?? '').replace(/\s+/g, ' ').trim()
}

export function haulerAttribute(wrapper: MountedElement, testId: string, attribute: string): string | null {
  return haulerElement(wrapper, testId)?.getAttribute(attribute) ?? null
}
