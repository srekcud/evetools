<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useSyncStore } from '@/stores/sync'
import type { WalletReading } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  readings: WalletReading[]
}>()

const { t } = useI18n()
const { formatTime } = useFormatters()
const projectStore = useGroupProjectStore()
const syncStore = useSyncStore()

const isRefreshing = ref(false)
const hasNothingToLaunch = ref(false)
const refreshError = ref<string | null>(null)

const isWalletBeingRead = computed(() => syncStore.walletTransactionsProgress?.status === 'started')

const earliestNextReadAt = computed<string | null>(() => {
  const nextReads = props.readings
    .flatMap(reading => (reading.status === 'read' && reading.nextReadAt !== null ? [reading.nextReadAt] : []))
  if (nextReads.length === 0) return null
  return nextReads.reduce((earliest, candidate) => (new Date(candidate) < new Date(earliest) ? candidate : earliest))
})

const nothingToLaunchSentence = computed(() =>
  earliestNextReadAt.value === null
    ? t('groupIndustry.walletReading.noneToLaunchUnbounded')
    : t('groupIndustry.walletReading.noneToLaunch', { time: formatTime(earliestNextReadAt.value) }),
)

async function refresh(): Promise<void> {
  isRefreshing.value = true
  hasNothingToLaunch.value = false
  refreshError.value = null
  try {
    const result = await projectStore.refreshWallet(props.projectId)
    hasNothingToLaunch.value = result.queuedCharacters === 0
  } catch (e) {
    refreshError.value = e instanceof Error ? e.message : t('groupIndustry.walletReading.refreshError')
  } finally {
    isRefreshing.value = false
  }
}
</script>

<template>
  <div class="space-y-1">
    <button
      data-testid="wallet-refresh"
      class="px-2.5 py-1 rounded-sm text-xs font-medium bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 hover:bg-cyan-500/20 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="isRefreshing || isWalletBeingRead"
      @click="refresh"
    >{{ isRefreshing ? t('groupIndustry.walletReading.refreshing') : t('groupIndustry.walletReading.refresh') }}</button>
    <p v-if="hasNothingToLaunch" data-testid="wallet-refresh-none" class="text-xs text-slate-500">{{ nothingToLaunchSentence }}</p>
    <p v-if="refreshError !== null" data-testid="wallet-refresh-error" role="alert" class="text-xs text-red-400">{{ refreshError }}</p>
  </div>
</template>
