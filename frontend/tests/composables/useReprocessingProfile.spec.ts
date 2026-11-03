import { apiRequest } from '@/services/api'
import { useReprocessingProfile } from '@/composables/useReprocessingProfile'
import type { ReprocessingProfileInput } from '@/composables/useReprocessingProfile'
import {
  COHERENT_ORE_PROCESSING,
  ESI_ERROR_PROFILE,
  NPC_STATION_PROFILE,
  PILOT_TWO_ID,
  TATARA_PROFILE,
  UNCONFIGURED_PROFILE,
} from '../components/ledger/reprocessingProfileHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TATARA_INPUT: ReprocessingProfileInput = {
  characterId: PILOT_TWO_ID,
  oreProcessingSkillTypeId: COHERENT_ORE_PROCESSING,
  location: 'tatara',
  rigTier: 't2',
  security: 'nullsec',
  implantPercent: 1,
}

function requests(): Array<{ endpoint: string; method: string; body: unknown }> {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => ({
    endpoint,
    method: options?.method?.toUpperCase() ?? 'GET',
    body: typeof options?.body === 'string' ? JSON.parse(options.body) : null,
  }))
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('useReprocessingProfile loading', () => {
  it('loads the profile of the user and exposes its known reprocess yield', async () => {
    vi.mocked(apiRequest).mockResolvedValue(NPC_STATION_PROFILE)
    const { profile, reprocessYield, hasError, load } = useReprocessingProfile()

    await load()

    expect(requests()).toEqual([{ endpoint: '/mining/reprocessing-profile', method: 'GET', body: null }])
    expect(profile.value).toEqual(NPC_STATION_PROFILE)
    expect(reprocessYield.value).toBe(0.72358)
    expect(hasError.value).toBe(false)
  })

  it('exposes no reprocess yield when the user has no profile', async () => {
    vi.mocked(apiRequest).mockResolvedValue(UNCONFIGURED_PROFILE)
    const { profile, reprocessYield, load } = useReprocessingProfile()

    await load()

    expect(profile.value).toEqual(UNCONFIGURED_PROFILE)
    expect(reprocessYield.value).toBeNull()
  })

  it('exposes no reprocess yield when the skills of the saved profile were unreadable', async () => {
    vi.mocked(apiRequest).mockResolvedValue(ESI_ERROR_PROFILE)
    const { profile, reprocessYield, load } = useReprocessingProfile()

    await load()

    expect(profile.value?.failure).toBe('esi_error')
    expect(reprocessYield.value).toBeNull()
  })

  it('reports an error and keeps the yield unknown when the profile cannot be loaded', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('HTTP 500'))
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { profile, reprocessYield, hasError, load } = useReprocessingProfile()

    await load()

    expect(hasError.value).toBe(true)
    expect(profile.value).toBeNull()
    expect(reprocessYield.value).toBeNull()
  })
})

describe('useReprocessingProfile saving and refreshing', () => {
  it('saves the settings with a PUT and replaces the profile with the saved one', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(NPC_STATION_PROFILE).mockResolvedValueOnce(TATARA_PROFILE)
    const { profile, reprocessYield, load, save } = useReprocessingProfile()
    await load()

    await save(TATARA_INPUT)

    expect(requests()[1]).toEqual({ endpoint: '/mining/reprocessing-profile', method: 'PUT', body: TATARA_INPUT })
    expect(profile.value).toEqual(TATARA_PROFILE)
    expect(reprocessYield.value).toBe(0.7809530995302404)
  })

  it('recomputes the yield with a POST to refresh and replaces the profile with the recomputed one', async () => {
    const refreshedProfile = { ...ESI_ERROR_PROFILE, reprocessingLevel: 5, reprocessingEfficiencyLevel: 4, oreProcessingLevel: 3, reprocessYield: 0.6912, failure: null }
    vi.mocked(apiRequest).mockResolvedValueOnce(ESI_ERROR_PROFILE).mockResolvedValueOnce(refreshedProfile)
    const { profile, reprocessYield, load, refresh } = useReprocessingProfile()
    await load()

    await refresh()

    expect(requests()[1]).toMatchObject({ endpoint: '/mining/reprocessing-profile/refresh', method: 'POST' })
    expect(profile.value).toEqual(refreshedProfile)
    expect(reprocessYield.value).toBe(0.6912)
  })

  it('keeps the previous profile and reports an error when the save fails', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(NPC_STATION_PROFILE).mockRejectedValueOnce(new Error('HTTP 400'))
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { profile, reprocessYield, hasError, load, save } = useReprocessingProfile()
    await load()

    await save(TATARA_INPUT)

    expect(hasError.value).toBe(true)
    expect(profile.value).toEqual(NPC_STATION_PROFILE)
    expect(reprocessYield.value).toBe(0.72358)
  })

  it('clears the error once a later request succeeds', async () => {
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('HTTP 502')).mockResolvedValueOnce(TATARA_PROFILE)
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { profile, hasError, load, save } = useReprocessingProfile()
    await load()

    await save(TATARA_INPUT)

    expect(hasError.value).toBe(false)
    expect(profile.value).toEqual(TATARA_PROFILE)
  })
})
