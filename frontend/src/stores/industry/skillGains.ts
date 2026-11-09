import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'

export type SkillEffect =
  | 'manufacturing_time'
  | 'reaction_time'
  | 'invention_chance'
  | 'manufacturing_slot'
  | 'reaction_slot'
  | 'science_slot'

export type SkillGainUnknownReason = 'skills_not_synchronized' | 'project_jobs_not_cached' | 'isk_per_hour_unknown'

export type SkillTrainingUnknownReason =
  | 'attributes_not_synchronized'
  | 'skill_not_in_sde'
  | 'skill_points_unknown'
  | 'active_level_below_trained_level'
  | 'max_level_reached'

export interface SkillGainLine {
  skillId: number
  skillName: string | null
  currentLevel: number
  targetLevel: number
  effect: SkillEffect
  hoursSavedPerMonth: number | null
  monthlyGain: number | null
  gainUnknownReason: SkillGainUnknownReason | null
  trainingSeconds: number | null
  trainingUnknownReason: SkillTrainingUnknownReason | null
  gainPerTrainingDay: number | null
}

export interface SkillGains {
  characterId: string
  period: { from: string; to: string; days: number }
  iskPerHour: number | null
  lines: SkillGainLine[]
}

export const useSkillGainsStore = defineStore('industry-skill-gains', () => {
  const skillGains = ref<SkillGains | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  let latestRequest = 0

  async function fetchSkillGains(characterId: string): Promise<void> {
    const request = ++latestRequest
    skillGains.value = null
    isLoading.value = true
    error.value = null

    try {
      const gains = await apiRequest<SkillGains>(`/me/characters/${characterId}/skill-gains`)
      if (request === latestRequest) {
        skillGains.value = gains
      }
    } catch (e) {
      if (request === latestRequest) {
        error.value = e instanceof Error ? e.message : 'Failed to fetch skill gains'
      }
    } finally {
      if (request === latestRequest) {
        isLoading.value = false
      }
    }
  }

  return { skillGains, isLoading, error, fetchSkillGains }
})
