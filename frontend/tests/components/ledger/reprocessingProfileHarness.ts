import type { Character } from '@/types'
import type { OreProcessingSkill, ReprocessingProfile } from '@/composables/useReprocessingProfile'

export const PILOT_ONE_ID = 2112269001
export const PILOT_TWO_ID = 2112269002
export const SIMPLE_ORE_PROCESSING = 60377
export const COHERENT_ORE_PROCESSING = 60378
export const ICE_PROCESSING = 18025
export const LONG_NAME_ORE_PROCESSING = 99269001

export const ORE_PROCESSING_SKILLS: OreProcessingSkill[] = [
  { typeId: COHERENT_ORE_PROCESSING, typeName: 'Coherent Ore Processing' },
  { typeId: LONG_NAME_ORE_PROCESSING, typeName: 'Exceptionally Lustrous Hypothetical Moon Ore Processing With An Overly Long Name' },
  { typeId: ICE_PROCESSING, typeName: 'Ice Processing' },
  { typeId: SIMPLE_ORE_PROCESSING, typeName: 'Simple Ore Processing' },
]

export function character(eveCharacterId: number, name: string): Character {
  return {
    id: `character-${eveCharacterId}`,
    eveCharacterId,
    name,
    corporationId: 98000001,
    corporationName: 'Deep Core Mining Inc.',
    allianceId: null,
    allianceName: null,
    isMain: eveCharacterId === PILOT_ONE_ID,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

export const PILOT_ONE = character(PILOT_ONE_ID, 'Ilyra Castell')
export const PILOT_TWO = character(PILOT_TWO_ID, 'Tovan Reske')

export function reprocessingProfile(overrides: Partial<ReprocessingProfile>): ReprocessingProfile {
  return {
    configured: true,
    characterId: PILOT_ONE_ID,
    oreProcessingSkillTypeId: SIMPLE_ORE_PROCESSING,
    location: 'npc_station',
    rigTier: null,
    security: null,
    implantPercent: 4,
    reprocessingLevel: 5,
    reprocessingEfficiencyLevel: 5,
    oreProcessingLevel: 5,
    reprocessYield: 0.72358,
    failure: null,
    computedAt: '2026-11-03T21:00:00+00:00',
    oreProcessingSkills: ORE_PROCESSING_SKILLS,
    ...overrides,
  }
}

export const NPC_STATION_PROFILE = reprocessingProfile({})

export const UNCONFIGURED_PROFILE = reprocessingProfile({
  configured: false,
  characterId: null,
  oreProcessingSkillTypeId: null,
  location: null,
  implantPercent: null,
  reprocessingLevel: null,
  reprocessingEfficiencyLevel: null,
  oreProcessingLevel: null,
  reprocessYield: null,
  computedAt: null,
})

export const TATARA_PROFILE = reprocessingProfile({
  characterId: PILOT_TWO_ID,
  oreProcessingSkillTypeId: COHERENT_ORE_PROCESSING,
  location: 'tatara',
  rigTier: 't2',
  security: 'nullsec',
  implantPercent: 1,
  reprocessingLevel: 4,
  reprocessingEfficiencyLevel: 3,
  oreProcessingLevel: 2,
  reprocessYield: 0.7809530995302404,
})

export const ESI_ERROR_PROFILE = reprocessingProfile({
  characterId: PILOT_TWO_ID,
  oreProcessingSkillTypeId: ICE_PROCESSING,
  location: 'athanor',
  rigTier: 't1',
  security: 'lowsec',
  implantPercent: 2,
  reprocessingLevel: null,
  reprocessingEfficiencyLevel: null,
  oreProcessingLevel: null,
  reprocessYield: null,
  failure: 'esi_error',
})
