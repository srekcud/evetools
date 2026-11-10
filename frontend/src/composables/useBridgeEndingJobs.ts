import { computed, ref } from 'vue'
import { useSlotsStore } from '@/stores/industry/slots'
import type { TimelineJob } from '@/stores/industry/types'
import { bridgeCardStatus, type BridgeCardStatus } from '@/composables/bridgeCardStatus'

export const BRIDGE_ENDING_JOBS_SHOWN = 4

export function useBridgeEndingJobs() {
  const store = useSlotsStore()
  const isSettled = ref(false)
  const hasFailed = ref(false)

  const timeline = computed<TimelineJob[]>(() => {
    const loadedTimeline = store.data?.timeline
    return hasFailed.value || !Array.isArray(loadedTimeline) ? [] : loadedTimeline
  })

  const shown = computed(() => [...timeline.value]
    .sort((first, second) => new Date(first.endDate).getTime() - new Date(second.endDate).getTime())
    .slice(0, BRIDGE_ENDING_JOBS_SHOWN))

  const status = computed<BridgeCardStatus>(() => bridgeCardStatus({
    isSettled: isSettled.value,
    hasFailed: hasFailed.value,
    itemCount: timeline.value.length,
  }))

  async function load(): Promise<void> {
    await store.fetchSlots()
    hasFailed.value = store.error !== null || !Array.isArray(store.data?.timeline)
    isSettled.value = true
  }

  void load()

  return { status, shown }
}
