<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MoonChunkValue } from '@/composables/useCorporationMiningExtractions'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'

const ROW_PARTICIPANT_COUNT = 6

const CELL_CLASSES = { td: 'px-3 py-2 text-sm max-w-[22rem]', div: 'mt-1 text-xs' }

const props = withDefaults(defineProps<{
  value: MoonChunkValue | null
  expanded: boolean
  as?: 'td' | 'div'
}>(), { as: 'td' })

defineEmits<{ toggle: [] }>()

const { t, locale } = useI18n()

const rowShare = computed(() => props.value?.perParticipant?.find(candidate => candidate.participants === ROW_PARTICIPANT_COUNT) ?? null)
const summary = computed(() => {
  const value = props.value
  if (value === null) return null
  if (value.status === 'unknown') return t(`ledger.mining.moonChunks.value.unknown.${value.reason}`)
  if (value.totalValue === null || rowShare.value === null) return null
  return t('ledger.mining.moonChunks.value.rowKnown', {
    total: formatWhole(value.totalValue, locale.value),
    share: formatWhole(rowShare.value.value, locale.value),
    count: ROW_PARTICIPANT_COUNT,
  })
})
</script>

<template>
  <component
    :is="as"
    v-if="value !== null && summary !== null"
    data-cell="value"
    :data-value-status="value.status"
    :data-unknown-reason="value.reason ?? undefined"
    :class="CELL_CLASSES[as]"
  >
    <button
      type="button"
      data-toggle-detail
      :aria-expanded="expanded"
      :title="expanded ? t('ledger.mining.moonChunks.value.hideDetail') : t('ledger.mining.moonChunks.value.showDetail')"
      :class="['flex items-start gap-1.5 text-left break-words', value.status === 'known' ? 'text-white' : 'text-amber-300']"
      @click="$emit('toggle')"
    >
      <span aria-hidden="true">{{ expanded ? '▾' : '▸' }}{{ value.status === 'known' ? '' : ' ⚠' }}</span>
      <span>{{ summary }}</span>
    </button>
  </component>
  <td v-else data-cell="value" class="px-3 py-2 text-sm text-slate-500" :title="t('ledger.mining.moonChunks.value.notEstimated')">—</td>
</template>
