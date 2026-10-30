import { computed, type Ref } from 'vue'
import type { IndustryProject } from '@/stores/industry/types'

/**
 * Format a duration in seconds to human-readable format.
 */
export function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds <= 0) return '-'

  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)

  if (days > 0) {
    if (hours > 0) {
      return `${days}j ${hours}h`
    }
    return `${days}j`
  } else if (hours > 0) {
    return `${hours}h ${minutes}m`
  } else {
    return `${minutes}m`
  }
}

/**
 * Compute estimated total project time.
 * Strategy: sum the longest step duration at each depth level
 * (steps at same depth can run in parallel, but different depths are sequential).
 */
export function useProjectTime(project: Ref<IndustryProject | null>) {
  const stepsToBuild = computed(() => (project.value?.steps ?? []).filter(step => !step.purchased && !step.inStock))

  const isEstimatedProjectTimeUnknown = computed(() => stepsToBuild.value.some(step => step.timePerRun === null))

  const estimatedProjectTime = computed(() => {
    if (isEstimatedProjectTimeUnknown.value) return null

    const maxByDepth = new Map<number, number>()

    for (const step of stepsToBuild.value) {
      if (step.timePerRun === null) continue

      const duration = step.timePerRun * step.runs
      const current = maxByDepth.get(step.depth) ?? 0
      if (duration > current) {
        maxByDepth.set(step.depth, duration)
      }
    }

    let total = 0
    for (const duration of maxByDepth.values()) {
      total += duration
    }

    return total > 0 ? total : null
  })

  return { estimatedProjectTime, isEstimatedProjectTimeUnknown, formatDuration }
}
