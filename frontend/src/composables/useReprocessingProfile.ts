import { computed, ref } from 'vue'
import { apiRequest } from '@/services/api'

export type ReprocessingLocation = 'npc_station' | 'athanor' | 'tatara'

export type ReprocessingRigTier = 'none' | 't1' | 't2'

export type ReprocessingSecurity = 'highsec' | 'lowsec' | 'nullsec'

export type ReprocessingFailure = 'missing_token' | 'missing_scope' | 'esi_error'

export interface OreProcessingSkill {
  typeId: number
  typeName: string
}

export interface ReprocessingProfile {
  configured: boolean
  characterId: number | null
  oreProcessingSkillTypeId: number | null
  location: ReprocessingLocation | null
  rigTier: ReprocessingRigTier | null
  security: ReprocessingSecurity | null
  implantPercent: number | null
  reprocessingLevel: number | null
  reprocessingEfficiencyLevel: number | null
  oreProcessingLevel: number | null
  reprocessYield: number | null
  failure: ReprocessingFailure | null
  computedAt: string | null
  oreProcessingSkills: OreProcessingSkill[]
}

export interface ReprocessingProfileInput {
  characterId: number
  oreProcessingSkillTypeId: number
  location: ReprocessingLocation
  rigTier: ReprocessingRigTier | null
  security: ReprocessingSecurity | null
  implantPercent: number
}

const PROFILE_ENDPOINT = '/mining/reprocessing-profile'

export function useReprocessingProfile() {
  const profile = ref<ReprocessingProfile | null>(null)
  const isLoading = ref(false)
  const hasError = ref(false)
  const reprocessYield = computed<number | null>(() => profile.value?.reprocessYield ?? null)

  async function request(endpoint: string, options?: RequestInit): Promise<void> {
    isLoading.value = true
    try {
      profile.value = await apiRequest<ReprocessingProfile>(endpoint, options)
      hasError.value = false
    } catch (e) {
      console.error('Failed to reach the reprocessing profile:', e)
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  async function load(): Promise<void> {
    await request(PROFILE_ENDPOINT)
  }

  async function save(input: ReprocessingProfileInput): Promise<void> {
    await request(PROFILE_ENDPOINT, { method: 'PUT', body: JSON.stringify(input) })
  }

  async function refresh(): Promise<void> {
    await request(`${PROFILE_ENDPOINT}/refresh`, { method: 'POST', body: JSON.stringify({}) })
  }

  return { profile, isLoading, hasError, reprocessYield, load, save, refresh }
}
