import { computed, ref } from 'vue'
import { useCorporationMiningExtractions, type CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { bridgeCardStatus, type BridgeCardStatus } from '@/composables/bridgeCardStatus'

export const BRIDGE_NEXT_CHUNKS_SHOWN = 3

export function useBridgeNextChunks() {
  const extractions = useCorporationMiningExtractions()
  const isSettled = ref(false)

  const upcoming = computed<CorporationMiningExtraction[]>(() => {
    const now = Date.now()
    return extractions.corporations.value
      .flatMap(corporation => corporation.extractions)
      .filter(extraction => new Date(extraction.chunkArrivalTime).getTime() > now)
      .sort((first, second) => new Date(first.chunkArrivalTime).getTime() - new Date(second.chunkArrivalTime).getTime())
  })

  const shown = computed(() => upcoming.value.slice(0, BRIDGE_NEXT_CHUNKS_SHOWN))

  const status = computed<BridgeCardStatus>(() => bridgeCardStatus({
    isSettled: isSettled.value,
    hasFailed: extractions.hasError.value,
    itemCount: upcoming.value.length,
  }))

  async function load(): Promise<void> {
    await extractions.load()
    isSettled.value = true
  }

  void load()

  return { status, shown }
}
