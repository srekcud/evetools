<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupBuyOrBuildStore } from '@/stores/group-industry/buyOrBuild'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BuyOrBuildChoice, BuyOrBuildRow } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import UnknownContributionValue from './UnknownContributionValue.vue'

const CHOICE_CHIP_CLASSES: Record<BuyOrBuildChoice, string> = {
  build: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  buy: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
  unknown: 'border-dashed border-violet-400 text-violet-300',
}

const props = withDefaults(defineProps<{
  projectId: string
  canSwitch?: boolean
  blacklistTypeIds?: number[]
}>(), {
  canSwitch: false,
  blacklistTypeIds: () => [],
})

const emit = defineEmits<{
  switched: []
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const buyOrBuildStore = useGroupBuyOrBuildStore()
const projectStore = useGroupProjectStore()

const isSwitching = ref(false)
const switchError = ref<string | null>(null)

const COLUMNS = ['intermediate', 'quantity', 'build', 'jita', 'import', 'buy', 'choice'] as const

function typeLabel(row: BuyOrBuildRow): string {
  return row.typeName ?? `#${row.typeId}`
}

function jitaCoveragePercent(row: BuyOrBuildRow): number | null {
  return row.warnings.find(warning => warning.typeId === row.typeId)?.coveragePercent ?? null
}

async function switchRow(row: BuyOrBuildRow): Promise<void> {
  const blacklistTypeIds = row.blacklisted
    ? props.blacklistTypeIds.filter(typeId => typeId !== row.typeId)
    : [...props.blacklistTypeIds, row.typeId]
  isSwitching.value = true
  switchError.value = null
  try {
    await projectStore.updateProject(props.projectId, { blacklistTypeIds })
    await buyOrBuildStore.fetchBuyOrBuild(props.projectId)
    emit('switched')
  } catch (e) {
    switchError.value = e instanceof Error ? e.message : t('groupIndustry.buyOrBuild.switch.error')
  } finally {
    isSwitching.value = false
  }
}

function columnAlignment(column: (typeof COLUMNS)[number]): string {
  return column === 'intermediate' || column === 'choice' ? 'text-left' : 'text-right'
}

watch(() => props.projectId, projectId => buyOrBuildStore.fetchBuyOrBuild(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    buyOrBuildStore.fetchBuyOrBuild(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    buyOrBuildStore.fetchBuyOrBuild(props.projectId)
  },
)
</script>

<template>
  <section data-testid="buy-or-build" class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.buyOrBuild.title') }}
      </h2>
      <span
        v-if="buyOrBuildStore.importIskPerCubicMeterJump !== null"
        data-testid="buy-or-build-rate"
        class="px-2 py-1 rounded border border-slate-700 text-xs text-slate-300"
      >{{ t('groupIndustry.buyOrBuild.importRate', { rate: formatNumber(buyOrBuildStore.importIskPerCubicMeterJump) }) }}</span>
    </header>

    <p
      v-if="buyOrBuildStore.loading && buyOrBuildStore.rows.length === 0"
      data-testid="buy-or-build-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.buyOrBuild.loading') }}</p>

    <div
      v-else-if="buyOrBuildStore.error !== null"
      data-testid="buy-or-build-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.buyOrBuild.loadError') }}</p>
      <p class="mt-1 break-words">{{ buyOrBuildStore.error }}</p>
    </div>

    <p
      v-else-if="buyOrBuildStore.rows.length === 0"
      data-testid="buy-or-build-empty"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.buyOrBuild.empty') }}</p>

    <template v-else>
      <div
        v-if="switchError !== null"
        data-testid="buy-or-build-switch-error"
        role="alert"
        class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.buyOrBuild.switch.error') }}</p>
        <p class="mt-1 break-words">{{ switchError }}</p>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[56rem] text-sm">
          <thead>
            <tr class="border-b border-slate-800 text-xs uppercase tracking-wider text-slate-500">
              <th
                v-for="column in COLUMNS"
                :key="column"
                scope="col"
                class="px-4 py-2 font-medium"
                :class="columnAlignment(column)"
              >{{ t(`groupIndustry.buyOrBuild.columns.${column}`) }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/60">
            <tr
              v-for="row in buyOrBuildStore.rows"
              :key="row.typeId"
              :data-testid="`buy-or-build-row-${row.typeId}`"
              class="align-top hover:bg-slate-800/30"
            >
              <td class="px-4 py-3 max-w-[16rem]">
                <div class="flex items-center gap-2 min-w-0">
                  <img
                    :src="getTypeIconUrl(row.typeId, 32)"
                    alt=""
                    class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
                    @error="onImageError"
                  />
                  <span class="truncate font-medium text-slate-100" :title="typeLabel(row)">{{ typeLabel(row) }}</span>
                </div>
              </td>

              <td :data-testid="`buy-or-build-row-${row.typeId}-quantity`" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                {{ formatNumber(row.quantity) }}
              </td>

              <td class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                <div :data-testid="`buy-or-build-row-${row.typeId}-build`">
                  <UnknownContributionValue v-if="row.buildPerUnit === null" />
                  <template v-else>{{ formatIsk(row.buildPerUnit) }}</template>
                </div>
                <div
                  v-if="row.buildPerUnitIfSurplusReused !== null"
                  :data-testid="`buy-or-build-row-${row.typeId}-build-reused`"
                  class="mt-0.5 text-xs font-sans text-slate-500"
                >{{ t('groupIndustry.buyOrBuild.ifSurplusReused', { amount: formatIsk(row.buildPerUnitIfSurplusReused) }) }}</div>
              </td>

              <td :data-testid="`buy-or-build-row-${row.typeId}-jita`" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="row.jitaPerUnit === null" />
                <template v-else>{{ formatIsk(row.jitaPerUnit) }}<PartialPriceMarker :percent="jitaCoveragePercent(row)" /></template>
              </td>

              <td :data-testid="`buy-or-build-row-${row.typeId}-import`" class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">
                <UnknownContributionValue v-if="row.importPerUnit === null" />
                <template v-else>
                  +{{ formatIsk(row.importPerUnit) }}
                  <div
                    v-if="row.volumePerUnit !== null && row.jumps !== null"
                    class="mt-0.5 text-xs font-sans text-slate-500"
                  >{{ t('groupIndustry.buyOrBuild.importDetail', { volume: formatNumber(row.volumePerUnit), jumps: row.jumps }) }}</div>
                </template>
              </td>

              <td :data-testid="`buy-or-build-row-${row.typeId}-buy`" class="px-4 py-3 text-right font-mono tabular-nums font-semibold text-slate-100">
                <UnknownContributionValue v-if="row.buyPerUnit === null" />
                <template v-else>{{ formatIsk(row.buyPerUnit) }}</template>
              </td>

              <td class="px-4 py-3">
                <span
                  :data-testid="`buy-or-build-row-${row.typeId}-choice`"
                  class="inline-block px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
                  :class="CHOICE_CHIP_CLASSES[row.choice]"
                >{{ t(`groupIndustry.buyOrBuild.choice.${row.choice}`) }}</span>
                <div
                  v-if="row.saving !== null"
                  :data-testid="`buy-or-build-row-${row.typeId}-saving`"
                  class="mt-1 text-xs text-slate-400"
                >{{ t('groupIndustry.buyOrBuild.saving', { amount: formatIsk(row.saving) }) }}</div>
                <div
                  v-if="row.savingIfSurplusReused !== null"
                  :data-testid="`buy-or-build-row-${row.typeId}-saving-reused`"
                  class="mt-1 text-xs text-slate-400"
                >{{ t('groupIndustry.buyOrBuild.savingIfSurplusReused', { amount: formatIsk(row.savingIfSurplusReused) }) }}</div>
                <div
                  v-if="row.choice !== row.choiceWithoutImport"
                  :data-testid="`buy-or-build-row-${row.typeId}-without-import`"
                  class="mt-1 text-xs text-amber-400"
                >{{ t('groupIndustry.buyOrBuild.withoutImport') }}</div>
                <span
                  v-if="row.blacklisted"
                  :data-testid="`buy-or-build-row-${row.typeId}-blacklisted`"
                  class="mt-1 inline-block px-2 py-0.5 rounded border border-amber-500/40 bg-amber-500/10 text-xs text-amber-300"
                >{{ t('groupIndustry.buyOrBuild.switch.blacklisted') }}</span>
                <button
                  v-if="canSwitch"
                  type="button"
                  :data-testid="`buy-or-build-row-${row.typeId}-switch`"
                  :disabled="isSwitching"
                  class="mt-2 block px-2 py-1 rounded border border-cyan-500/40 text-xs text-cyan-300 hover:bg-cyan-500/10 disabled:opacity-50 disabled:cursor-not-allowed"
                  @click="switchRow(row)"
                >{{ t(row.blacklisted ? 'groupIndustry.buyOrBuild.switch.toBuild' : 'groupIndustry.buyOrBuild.switch.toBuy') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500">{{ t('groupIndustry.buyOrBuild.hint') }}</p>
    </template>
  </section>
</template>
