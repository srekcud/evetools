<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { WalletReading } from '@/stores/group-industry/types'

const props = defineProps<{
  readings: WalletReading[]
  emptyHint?: boolean
}>()

const { t } = useI18n()
const { formatTime } = useFormatters()

function statusSentence(reading: WalletReading): string {
  switch (reading.status) {
    case 'no_token':
      return t('groupIndustry.walletReading.noToken')
    case 'missing_scope':
      return t('groupIndustry.walletReading.missingScope')
    case 'never_read':
      return t('groupIndustry.walletReading.neverRead')
    case 'read':
      if (reading.readAt === null) return t('groupIndustry.walletReading.neverRead')
      if (reading.nextReadAt === null) return t('groupIndustry.walletReading.readWithoutNext', { readAt: formatTime(reading.readAt) })
      return t('groupIndustry.walletReading.read', { readAt: formatTime(reading.readAt), nextReadAt: formatTime(reading.nextReadAt) })
  }
}

const latestNextReadAt = computed<string | null>(() => {
  const nextReads = props.readings
    .flatMap(reading => (reading.status === 'read' && reading.nextReadAt !== null ? [reading.nextReadAt] : []))
  if (nextReads.length === 0) return null
  return nextReads.reduce((latest, candidate) => (new Date(candidate) > new Date(latest) ? candidate : latest))
})

const emptyHintSentence = computed(() =>
  latestNextReadAt.value === null
    ? t('groupIndustry.walletReading.emptyHintUnbounded')
    : t('groupIndustry.walletReading.emptyHintBounded', { time: formatTime(latestNextReadAt.value) }),
)
</script>

<template>
  <div v-if="readings.length > 0 || emptyHint" class="space-y-2">
    <ul v-if="readings.length > 0" class="flex flex-wrap gap-2">
      <li
        v-for="reading in readings"
        :key="reading.characterName"
        data-testid="wallet-reading"
        :data-emphasized="reading.status === 'missing_scope' ? 'true' : undefined"
        class="px-2.5 py-1 rounded-sm text-xs border"
        :class="reading.status === 'missing_scope'
          ? 'bg-amber-500/10 border-amber-500/40 text-amber-300'
          : 'bg-slate-800/60 border-slate-700/60 text-slate-400'"
      >
        <span class="font-medium" :class="reading.status === 'missing_scope' ? 'text-amber-200' : 'text-slate-200'">{{ reading.characterName }}</span>
        <span class="mx-1 text-slate-600" aria-hidden="true">&middot;</span>
        <span>{{ statusSentence(reading) }}</span>
      </li>
    </ul>
    <p v-if="emptyHint" data-testid="wallet-reading-empty-hint" class="text-xs text-slate-500">{{ emptyHintSentence }}</p>
  </div>
</template>
