<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { effectiveChoice, sourcingSummary, type SourcingPicks } from './projectSetup'
import type { SetupSourcingChoice, SourcingDecisions, SourcingOption, SourcingRow } from '@/stores/group-industry/types'

type SectionKey = 'components' | 'reactions' | 'base'

const OPTIONS: SetupSourcingChoice[] = ['build', 'jita', 'structure']
const SECTION_KEYS: SectionKey[] = ['components', 'reactions', 'base']
const STAGES_OF_SECTION: Record<SectionKey, SourcingRow['stage'][]> = {
  components: ['component', 'hull'],
  reactions: ['reaction'],
  base: ['base'],
}

const props = defineProps<{ decisions: SourcingDecisions; picks: SourcingPicks; baselineTotal: number | null; stationNames: Record<string, string> }>()
const emit = defineEmits<{ 'update:picks': [picks: SourcingPicks]; back: []; validate: [] }>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const baseShown = ref(false)

const summary = computed(() => sourcingSummary(props.decisions, props.picks, props.baselineTotal))

const sections = computed(() => SECTION_KEYS
  .map(key => ({ key, rows: props.decisions.rows.filter(row => STAGES_OF_SECTION[key].includes(row.stage)) }))
  .filter(section => section.rows.length > 0))

function amountOf(amount: number | null): string {
  return amount === null ? t('groupIndustry.setup.sourcing.unknown') : formatIsk(amount)
}

function perUnitOf(option: SourcingOption): string {
  return option.perUnit === null ? t('groupIndustry.setup.sourcing.unknown') : t('groupIndustry.setup.sourcing.perUnit', { amount: formatNumber(option.perUnit, 2) })
}

function isChosen(row: SourcingRow, option: SetupSourcingChoice): boolean {
  return effectiveChoice(row, props.picks) === option
}

function choose(row: SourcingRow, option: SetupSourcingChoice): void {
  emit('update:picks', { ...props.picks, [row.typeId]: option })
}

function stationsOf(row: SourcingRow): string {
  return row.consumerStructureIds
    .map(id => props.stationNames[id])
    .filter((name): name is string => name !== undefined)
    .join(', ')
}

function countsOf(rows: SourcingRow[]): string {
  const counts = { build: 0, jita: 0, structure: 0 }
  for (const row of rows) {
    const choice = effectiveChoice(row, props.picks)
    if (choice !== null) counts[choice]++
  }
  return t('groupIndustry.setup.sourcing.sectionCounts', counts)
}

function offRecommendedOf(rows: SourcingRow[]): number {
  return rows.filter(row => effectiveChoice(row, props.picks) !== row.recommended).length
}

function buildDetailOf(row: SourcingRow, option: SourcingOption): string {
  return t('groupIndustry.setup.sourcing.buildDetail', {
    total: amountOf(option.total),
    inputs: amountOf(option.price),
    installation: amountOf(option.installation),
    transport: amountOf(option.transport),
    runs: row.runs === null ? t('groupIndustry.setup.sourcing.unknown') : formatNumber(row.runs, 0),
  })
}

function marketDetailOf(option: SourcingOption, kind: 'jita' | 'structure'): string {
  if (kind === 'structure') return amountOf(option.total)
  return t('groupIndustry.setup.sourcing.jitaDetail', { total: amountOf(option.total), transport: amountOf(option.transport) })
}

function volumeLabelOf(row: SourcingRow): string | null {
  return row.volumePerUnit === null ? null : t('groupIndustry.setup.sourcing.volumePerUnit', { volume: formatNumber(row.volumePerUnit, 2) })
}

function stockLabelOf(option: SourcingOption): string {
  return option.availableQuantity === null
    ? t('groupIndustry.setup.sourcing.stockUnknown')
    : t('groupIndustry.setup.sourcing.onSaleOnly', { quantity: formatNumber(option.availableQuantity, 0) })
}

const gapLabel = computed(() => {
  const gap = summary.value.gapToBaseline
  if (gap === null) return t('groupIndustry.setup.sourcing.unknown')
  if (gap === 0) return t('groupIndustry.setup.sourcing.allRecommended')
  return t('groupIndustry.setup.sourcing.againstFirst', { amount: `${gap > 0 ? '+ ' : ''}${formatIsk(gap)}` })
})
</script>

<template>
  <section data-testid="sourcing-step">
    <header class="mb-4">
      <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.setup.sourcing.title') }}</h2>
      <p class="text-sm text-slate-400">{{ t('groupIndustry.setup.sourcing.intro') }}</p>
    </header>

    <p v-if="decisions.rows.length === 0" class="mb-4 text-sm text-slate-400">{{ t('groupIndustry.setup.sourcing.empty') }}</p>

    <div v-else class="overflow-x-auto rounded-xl border border-slate-700 bg-slate-900/70">
      <table class="w-full min-w-[56rem] table-fixed border-collapse text-sm">
        <thead>
          <tr class="border-b border-slate-700 text-left text-xs uppercase tracking-wider text-slate-400">
            <th class="w-56 p-2">{{ t('groupIndustry.setup.sourcing.neededColumn') }}</th>
            <th class="p-2">{{ t('groupIndustry.setup.sourcing.build') }}</th>
            <th class="p-2">{{ t('groupIndustry.setup.sourcing.jita') }}</th>
            <th class="p-2">{{ t('groupIndustry.setup.sourcing.structure') }}</th>
          </tr>
        </thead>
        <tbody v-for="section in sections" :key="section.key">
          <tr class="bg-slate-800/60">
            <td colspan="4" class="p-2 text-slate-300">
              <b>{{ t(`groupIndustry.setup.sourcing.sections.${section.key}`) }}</b>
              <span class="ml-2 text-xs text-slate-400">{{ countsOf(section.rows) }}</span>
              <span v-if="offRecommendedOf(section.rows) > 0" class="ml-2 text-xs text-amber-300">{{ t('groupIndustry.setup.sourcing.offRecommended', { count: offRecommendedOf(section.rows) }) }}</span>
              <button
                v-if="section.key === 'base'"
                type="button"
                data-testid="sourcing-base-toggle"
                class="ml-3 rounded border border-slate-600 px-2 py-0.5 text-xs text-slate-300 hover:border-cyan-500/50"
                @click="baseShown = !baseShown"
              >
                {{ baseShown ? t('groupIndustry.setup.sourcing.hideBase') : t('groupIndustry.setup.sourcing.showBase', { count: section.rows.length }) }}
              </button>
            </td>
          </tr>
          <template v-if="section.key !== 'base' || baseShown">
            <tr
              v-for="row in section.rows"
              :key="row.typeId"
              :data-testid="`sourcing-row-${row.typeId}`"
              class="border-t border-slate-800 align-top"
            >
              <td class="p-2">
                <div class="flex items-center gap-2">
                  <img :src="getTypeIconUrl(row.typeId, 32)" alt="" class="h-6 w-6 rounded" @error="onImageError">
                  <span class="min-w-0 truncate text-slate-100" :title="row.typeName ?? undefined">{{ row.typeName }}</span>
                </div>
                <p class="mt-0.5 truncate text-xs text-slate-400">
                  {{ formatNumber(row.quantity, 0) }}<template v-if="stationsOf(row) !== ''"> · {{ t('groupIndustry.setup.sourcing.forStation', { station: stationsOf(row) }) }}</template>
                </p>
                <p
                  v-if="row.choiceChangesWithoutImport"
                  :data-testid="`sourcing-without-import-${row.typeId}`"
                  class="mt-0.5 text-xs text-amber-300"
                >{{ t('groupIndustry.setup.sourcing.withoutImport') }}</p>
              </td>
              <template v-for="option in OPTIONS" :key="option">
                <td
                  v-if="row.options[option] !== undefined"
                  :data-testid="`sourcing-cell-${row.typeId}-${option}`"
                  :data-selected="isChosen(row, option) ? 'true' : undefined"
                  :data-ineligible="row.options[option]!.eligible ? undefined : 'true'"
                  class="cursor-pointer p-2 transition-colors hover:bg-slate-800/60"
                  :class="[
                    isChosen(row, option) ? 'bg-cyan-500/10 ring-1 ring-inset ring-cyan-500/60' : '',
                    row.options[option]!.eligible ? '' : 'bg-red-500/10 text-red-300',
                  ]"
                  @click="choose(row, option)"
                >
                  <div class="flex items-center gap-2">
                    <input
                      type="radio"
                      :name="`sourcing-${row.typeId}`"
                      :data-testid="`sourcing-radio-${row.typeId}-${option}`"
                      :checked="isChosen(row, option)"
                      :aria-label="t('groupIndustry.setup.sourcing.optionLabel', { type: row.typeName ?? row.typeId, option: t(`groupIndustry.setup.sourcing.${option}`) })"
                      class="accent-cyan-500"
                      tabindex="-1"
                      @click.stop="choose(row, option)"
                    >
                    <b>{{ perUnitOf(row.options[option]!) }}</b><PartialPriceMarker v-if="option === 'jita'" :percent="row.options[option]!.coveragePercent" />
                    <span v-if="row.recommended === option" class="rounded bg-emerald-500/15 px-1.5 py-0.5 text-xs text-emerald-300">{{ t('groupIndustry.setup.sourcing.recommended') }}</span>
                  </div>
                  <p class="mt-0.5 break-words text-xs text-slate-400">
                    {{ option === 'build' ? buildDetailOf(row, row.options[option]!) : marketDetailOf(row.options[option]!, option) }}
                  </p>
                  <p
                    v-if="option === 'jita' && volumeLabelOf(row) !== null"
                    :data-testid="`sourcing-volume-${row.typeId}`"
                    class="mt-0.5 text-xs text-slate-400"
                  >{{ volumeLabelOf(row) }}</p>
                  <template v-if="option === 'build'">
                    <p
                      v-if="row.options[option]!.perUnitIfSurplusReused !== null"
                      :data-testid="`sourcing-reused-${row.typeId}`"
                      class="mt-0.5 text-xs text-slate-400"
                    >{{ t('groupIndustry.setup.sourcing.reusedPerUnit', { amount: formatNumber(row.options[option]!.perUnitIfSurplusReused!, 2) }) }}</p>
                    <p
                      v-if="row.savingIfSurplusReused !== null"
                      :data-testid="`sourcing-reused-saving-${row.typeId}`"
                      class="mt-0.5 text-xs text-slate-400"
                    >{{ t('groupIndustry.setup.sourcing.reusedSaving', { amount: formatIsk(row.savingIfSurplusReused) }) }}</p>
                  </template>
                  <p v-if="!row.options[option]!.eligible" class="mt-0.5 text-xs text-red-300">{{ stockLabelOf(row.options[option]!) }}</p>
                </td>
                <td v-else class="p-2 text-slate-600">—</td>
              </template>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <section class="mt-4 grid gap-3 rounded-xl border border-slate-700 bg-slate-900/70 p-4 md:grid-cols-4">
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.sourcing.summaryDelivered') }}</p>
        <p data-testid="sourcing-total" class="text-xl font-bold text-slate-100">{{ amountOf(summary.total) }}</p>
        <p data-testid="sourcing-gap" class="text-xs" :class="summary.gapToBaseline !== null && summary.gapToBaseline > 0 ? 'text-amber-300' : 'text-emerald-300'">{{ gapLabel }}</p>
      </div>
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.sourcing.purchasesJita') }}</p>
        <p data-testid="sourcing-jita" class="text-xl font-bold text-slate-100">{{ amountOf(summary.jita.amount) }}</p>
        <p class="text-xs text-slate-400">{{ t('groupIndustry.setup.sourcing.purchasesJitaDetail', { types: summary.jita.types, amount: amountOf(summary.jita.importCost) }) }}</p>
      </div>
      <div>
        <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('groupIndustry.setup.sourcing.purchasesStructure') }}</p>
        <p data-testid="sourcing-structure" class="text-xl font-bold text-slate-100">{{ amountOf(summary.structure.amount) }}</p>
        <p class="text-xs text-slate-400">{{ t('groupIndustry.setup.sourcing.purchasesStructureDetail', { types: summary.structure.types }) }}</p>
      </div>
      <div class="flex flex-col justify-center gap-2">
        <button
          type="button"
          data-testid="sourcing-back"
          class="rounded-lg border border-slate-600 px-3 py-2 text-sm text-slate-200 hover:border-cyan-500/50"
          @click="emit('back')"
        >
          {{ t('groupIndustry.setup.sourcing.back') }}
        </button>
        <button
          type="button"
          data-testid="sourcing-all-recommended"
          class="rounded-lg border border-slate-600 px-3 py-2 text-sm text-slate-200 hover:border-cyan-500/50"
          @click="emit('update:picks', {})"
        >
          {{ t('groupIndustry.setup.sourcing.allToRecommended') }}
        </button>
        <button
          type="button"
          data-testid="sourcing-validate"
          class="rounded-lg bg-cyan-600 px-3 py-2 text-sm font-medium text-white hover:bg-cyan-500"
          @click="emit('validate')"
        >
          {{ t('groupIndustry.setup.sourcing.validate') }}
        </button>
      </div>
    </section>

    <p class="mt-3 text-xs text-slate-500">{{ t('groupIndustry.setup.sourcing.footnote') }}</p>
  </section>
</template>
