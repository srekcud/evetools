<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatChunkTime, type CorporationMiningExtraction, type MoonChunkValue } from '@/composables/useCorporationMiningExtractions'
import { formatPercent, formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import MoonChunkName from './MoonChunkName.vue'
import MoonCompositionPasteZone from './MoonCompositionPasteZone.vue'

const EXTRACTION_RATE_M3_PER_HOUR = 25_000
const PERCENT = 100
const HOURS_FRACTION_DIGITS = 2

const props = defineProps<{
  corporationId: number
  extraction: CorporationMiningExtraction
  value: MoonChunkValue
  timeZone: string
}>()

const emit = defineEmits<{ recorded: [] }>()

const { t, locale } = useI18n()

const isRepasting = ref(false)

const needsScan = computed(() => props.value.reason === 'no_scan')
const showsPasteZone = computed(() => needsScan.value || isRepasting.value)
const hours = computed(() => new Intl.NumberFormat(locale.value, { maximumFractionDigits: HOURS_FRACTION_DIGITS }).format(props.value.volumeM3 / EXTRACTION_RATE_M3_PER_HOUR))
const volume = computed(() => formatWhole(props.value.volumeM3, locale.value))
const rate = computed(() => formatWhole(EXTRACTION_RATE_M3_PER_HOUR, locale.value))
const unknownExplanationKey = computed(() => `ledger.mining.moonChunks.value.explanation.${props.value.reason}`)

function sharePercent(share: number): string {
  return formatPercent(share * PERCENT, locale.value)
}

function onRecorded(): void {
  isRepasting.value = false
  emit('recorded')
}
</script>

<template>
  <div class="space-y-4 rounded-sm border border-slate-800 bg-slate-900/60 p-4">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
      <div data-detail-moon class="text-sm font-semibold text-white break-words">
        <MoonChunkName :name="extraction.moonName" :id="extraction.moonId" />
      </div>
      <p data-formula class="text-xs text-slate-400">
        {{ t('ledger.mining.moonChunks.value.formula', { hours, rate, volume }) }}
      </p>
    </div>

    <template v-if="value.status === 'known' && value.totalValue !== null && value.perParticipant !== null && value.lines !== null">
      <div class="grid gap-3 sm:grid-cols-4">
        <div class="rounded-sm border border-cyan-500/40 bg-cyan-500/5 p-3 sm:col-span-1">
          <div class="text-xs uppercase tracking-wider text-slate-400">{{ t('ledger.mining.moonChunks.value.estimatedValue') }}</div>
          <div data-detail-total class="text-lg font-semibold text-cyan-300">≈ {{ formatWhole(value.totalValue, locale) }} ISK</div>
          <div class="text-xs text-slate-500">{{ t('ledger.mining.moonChunks.value.basis') }}</div>
        </div>
        <div
          v-for="part in value.perParticipant"
          :key="part.participants"
          :data-share="part.participants"
          class="rounded-sm border border-slate-700 bg-slate-900 p-3"
        >
          <div class="text-xs uppercase tracking-wider text-slate-400">{{ t('ledger.mining.moonChunks.value.sharePerCount', { count: part.participants }) }}</div>
          <div class="text-base font-semibold text-white">{{ formatWhole(part.value, locale) }} ISK</div>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr>
              <th
                v-for="column in ['ore', 'share', 'units', 'value']"
                :key="column"
                scope="col"
                :class="['px-3 py-2 text-xs font-medium uppercase tracking-wider text-slate-400', column === 'ore' ? 'text-left' : 'text-right']"
              >
                {{ t(`ledger.mining.moonChunks.value.columns.${column}`) }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr v-for="line in value.lines" :key="line.typeId" :data-line-type-id="line.typeId">
              <td class="px-3 py-1.5 text-sm text-white">{{ line.typeName }}</td>
              <td class="px-3 py-1.5 text-right text-sm text-slate-300">{{ sharePercent(line.share) }} %</td>
              <td class="px-3 py-1.5 text-right text-sm text-slate-300">{{ formatWhole(line.units, locale) }}</td>
              <td class="px-3 py-1.5 text-right text-sm text-white">{{ formatWhole(line.value, locale) }} ISK</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <p
      v-else-if="!needsScan"
      data-unknown-explanation
      role="status"
      class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"
    >
      <span aria-hidden="true">⚠ </span>{{ t(unknownExplanationKey) }}
    </p>

    <p v-if="value.scanRecordedAt !== null" class="text-xs text-slate-400">
      {{ t('ledger.mining.moonChunks.value.scanRecordedAt') }}
      <time data-scan-recorded-at :datetime="value.scanRecordedAt">{{ formatChunkTime(value.scanRecordedAt, timeZone) }}</time>
    </p>

    <button
      v-if="!needsScan && !isRepasting"
      type="button"
      data-repaste
      class="px-3 py-1 rounded-sm border border-slate-600 text-xs font-medium uppercase tracking-wider text-slate-300 transition-colors hover:border-cyan-500/60 hover:text-cyan-300"
      @click="isRepasting = true"
    >
      {{ t('ledger.mining.moonChunks.value.repaste') }}
    </button>

    <MoonCompositionPasteZone
      v-if="showsPasteZone"
      :corporation-id="corporationId"
      :moon-id="extraction.moonId"
      @recorded="onRecorded"
    />
  </div>
</template>
