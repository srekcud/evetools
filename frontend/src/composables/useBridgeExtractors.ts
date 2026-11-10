import { computed, type Ref } from 'vue'
import type { SourceState } from '@/composables/useTodoItems'
import type { PlanetaryStats } from '@/stores/planetary'
import { bridgeCardStatus, type BridgeCardStatus } from '@/composables/bridgeCardStatus'

export interface BridgeExtractors {
  active: number
  total: number
  expired: number
  expiring: number
  expiryThresholdMinutes: number | null
}

function isValidStats(stats: PlanetaryStats): boolean {
  return [stats.totalExtractors, stats.activeExtractors, stats.expiredExtractors, stats.expiringExtractors].every(Number.isFinite)
}

export function useBridgeExtractors(source: Ref<SourceState<PlanetaryStats>>) {
  const stats = computed<PlanetaryStats | null>(() => source.value.status === 'loaded' && isValidStats(source.value.value) ? source.value.value : null)

  const status = computed<BridgeCardStatus>(() => bridgeCardStatus({
    isSettled: source.value.status !== 'loading',
    hasFailed: source.value.status === 'failed' || (source.value.status === 'loaded' && stats.value === null),
    itemCount: stats.value?.totalExtractors ?? 0,
  }))

  const extractors = computed<BridgeExtractors | null>(() => {
    const current = stats.value
    if (current === null) return null
    return {
      active: current.activeExtractors,
      total: current.totalExtractors,
      expired: current.expiredExtractors,
      expiring: current.expiringExtractors,
      expiryThresholdMinutes: current.expiryThresholdMinutes ?? null,
    }
  })

  return { status, extractors }
}
