import { computed, ref } from 'vue'

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

export function useReprocessingProfile() {
  const profile = ref<ReprocessingProfile | null>(null)
  const isLoading = ref(false)
  const hasError = ref(false)
  const reprocessYield = computed<number | null>(() => null)

  async function load(): Promise<void> {}

  async function save(_input: ReprocessingProfileInput): Promise<void> {}

  async function refresh(): Promise<void> {}

  return { profile, isLoading, hasError, reprocessYield, load, save, refresh }
}
