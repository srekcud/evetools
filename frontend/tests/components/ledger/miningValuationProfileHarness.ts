import type { MiningValuationProfile, ValuationSource } from '@/stores/miningValuationProfile'

export const VALUATION_PROFILE_PATH = '/mining/valuation-profile'
export const PERIMETER_STRUCTURE_ID = 1_035_466_617_946
export const PERIMETER_STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower'
export const BROWSER_STRUCTURE_ID = 1_035_000_000_007
export const BROWSER_STRUCTURE_NAME = 'Jita 4-4 - Caldari Navy Assembly Plant'
export const BROWSER_EXPORT_TAX = '800'
export const LEGACY_STRUCTURE_KEY = 'miningSelectedStructure'
export const LEGACY_EXPORT_TAX_KEY = 'miningExportTax'

export const JITA_BUY_COMPRESSED: ValuationSource = { market: 'jita', side: 'buy', form: 'compressed', structureName: null }

export function miningValuationProfile(overrides: Partial<MiningValuationProfile> = {}): MiningValuationProfile {
  return {
    market: 'jita',
    side: 'buy',
    form: 'compressed',
    structureId: null,
    structureName: null,
    exportTax: 0,
    persisted: true,
    ...overrides,
  }
}

export const DEFAULT_PROFILE = miningValuationProfile()

export const PERIMETER_PROFILE = miningValuationProfile({
  structureId: PERIMETER_STRUCTURE_ID,
  structureName: PERIMETER_STRUCTURE_NAME,
  exportTax: 650,
})

type Answer = (endpoint: string, options?: RequestInit) => Promise<unknown>

export function valuationProfileServer(initial: MiningValuationProfile = DEFAULT_PROFILE) {
  const state = { profile: initial, patches: [] as Record<string, unknown>[] }

  function answer(endpoint: string, options?: RequestInit): Promise<unknown> | undefined {
    if (endpoint !== VALUATION_PROFILE_PATH) return undefined
    if (options?.method === 'PATCH') {
      const patch = JSON.parse(String(options.body)) as Record<string, unknown>
      state.patches.push(patch)
      const { clearStructure, ...fields } = patch
      const cleared = clearStructure === true ? { structureId: null, structureName: null } : {}
      state.profile = { ...state.profile, ...fields, ...cleared, persisted: true }
    }
    return Promise.resolve(state.profile)
  }

  function respondingWith(other: Answer): Answer {
    return (endpoint, options) => {
      const profileAnswer = answer(endpoint, options)
      return profileAnswer === undefined ? other(endpoint, options) : profileAnswer
    }
  }

  return { state, respondingWith }
}
